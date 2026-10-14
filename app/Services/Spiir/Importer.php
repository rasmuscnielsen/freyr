<?php

namespace App\Services\Spiir;

use App\Actions\RecalculateAccountBalance;
use App\Enums\CategoryType;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DefaultCategoriesSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

final class Importer
{
    public function __construct(private RecalculateAccountBalance $balanceRecalculator) {}

    /**
     * @return array{categories:int, subcategories:int}
     */
    public function importCategories(string $path): array
    {
        $file = $this->resolveFile($path, 'Categories.json');
        $payload = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($payload): array {
            $this->removeDefaultCategories();

            $categoryCount = 0;

            foreach ($payload['categories'] ?? [] as $category) {
                Category::query()->updateOrCreate(
                    ['legacy_id' => (string) $category['id']],
                    [
                        'name' => $category['name'],
                        'type' => $this->categoryType($category['categoryType'] ?? null)->value,
                        'hyphenated_name' => $category['hyphenatedName'] ?? null,
                    ],
                );

                $categoryCount++;
            }

            $categories = Category::query()->get()->keyBy('legacy_id');
            $subcategoryCount = 0;

            foreach ($payload['subcategories'] ?? [] as $subcategory) {
                $parent = $categories[(string) $subcategory['categoryId']] ?? null;

                if ($parent === null) {
                    continue;
                }

                $record = Subcategory::query()->firstOrNew(['legacy_id' => (string) $subcategory['id']]);
                $group = $parent->type === CategoryType::Expense
                    ? ($record->expense_group ?? ExpenseGroupMapping::forSubcategory(
                        (string) $subcategory['id'], $subcategory['name'], $parent->legacy_id,
                    ))
                    : null;

                $record->fill([
                    'category_id' => $parent->id,
                    'name' => $subcategory['name'],
                    'hyphenated_name' => $subcategory['hyphenatedName'] ?? null,
                    'expense_group' => $group,
                    'hints' => $subcategory['hints'] ?? null,
                ])->save();

                $subcategoryCount++;
            }

            return [
                'categories' => $categoryCount,
                'subcategories' => $subcategoryCount,
            ];
        });
    }

    public function hasDefaultCategories(): bool
    {
        return Category::query()
            ->where('legacy_id', 'like', DefaultCategoriesSeeder::CATEGORY_LEGACY_ID_PREFIX.'%')
            ->exists();
    }

    /**
     * A Spiir export carries its own category tree, so the seeded defaults
     * are removed in favor of it. Subcategories cascade with their category
     * and postings referencing them are nulled at the database level.
     */
    private function removeDefaultCategories(): void
    {
        // Deleted one by one so the model events bump the `updated_at` of
        // postings that lose their category (see Category::booted()).
        Category::query()
            ->where('legacy_id', 'like', DefaultCategoriesSeeder::CATEGORY_LEGACY_ID_PREFIX.'%')
            ->get()
            ->each->delete();
    }

    public function canImport(): bool
    {
        return ! Posting::query()->exists();
    }

    /**
     * @return array{user_id:int, accounts:int, postings:int, counter_entries:int}
     */
    public function importExport(string $path, ?User $user = null): array
    {
        $this->importCategories($path);

        $reportingCurrency = $this->exportReportingCurrency($path);

        return DB::transaction(function () use ($path, $user, $reportingCurrency): array {
            $user ??= $this->resolveUser($path);

            // The export's reporting currency replaces the user's initial
            // choice before any history is written — the only way the fixed
            // currency may still move. Rolls back with the import on failure.
            $this->applyReportingCurrency($user, $reportingCurrency);

            $accounts = $this->importAccounts($path, $user);
            $postings = $this->importPostings($path, $user, $accounts, $reportingCurrency);
            $counterEntries = $this->resolveCounterEntryPostings();
            $this->recalculateImportedManualTotals($accounts);

            return [
                'user_id' => $user->id,
                'accounts' => $accounts->count(),
                'postings' => $postings,
                'counter_entries' => $counterEntries,
            ];
        });
    }

    /**
     * The export's single reporting currency, read from every usable
     * Amount/Currency pair in Posting.csv — never from account currencies or
     * a single arbitrary row.
     */
    private function exportReportingCurrency(string $path): string
    {
        $currencies = [];
        $unidentifiable = false;

        foreach ($this->readCsv($this->resolveFile($path, 'Posting.csv')) as $row) {
            if ($this->nullableString($row['Amount'] ?? null) === null) {
                continue;
            }

            $currency = $this->nullableString($row['Currency'] ?? null);

            if ($currency === null) {
                $unidentifiable = true;

                continue;
            }

            $currencies[$currency] = true;
        }

        if ($unidentifiable || $currencies === []) {
            throw new RuntimeException('The Spiir export does not identify a reporting currency.');
        }

        if (count($currencies) > 1) {
            throw new RuntimeException('The Spiir export mixes reporting currencies: '.implode(', ', array_keys($currencies)));
        }

        return (string) array_key_first($currencies);
    }

    /**
     * Move the importing user's reporting currency to the export's currency.
     * Only allowed while the user has no monetary history — the currency must
     * never change underneath postings, known balances, or active sync work.
     */
    private function applyReportingCurrency(User $user, string $currency): void
    {
        if ($user->reporting_currency === $currency) {
            return;
        }

        $hasHistory = $user->postings()->exists()
            || $user->bankAccounts()
                ->where(fn ($query) => $query->whereNotNull('opening_balance')->orWhereNotNull('current_balance'))
                ->exists()
            || $user->bankConnections()
                ->where('status', '!=', BankConnection::STATUS_ARCHIVED)
                ->exists();

        if ($hasHistory) {
            throw new RuntimeException(sprintf(
                'The export reports in %s but %s already has monetary history in %s.',
                $currency,
                $user->email,
                $user->reporting_currency ?? 'an unset currency',
            ));
        }

        $user->reporting_currency = $currency;
        $user->save();
    }

    /**
     * Re-imports may change movements after the user explicitly established a
     * manual opening balance. Imported snapshots with unknown openings stay intact.
     *
     * @param  Collection<string, BankAccount>  $accounts
     */
    private function recalculateImportedManualTotals(Collection $accounts): void
    {
        $accounts->each(function (BankAccount $account): void {
            if ($account->opening_balance !== null && ! $account->hasBankConnection()) {
                $this->balanceRecalculator->handle($account);
            }
        });
    }

    private function resolveUser(string $path): User
    {
        $existingUser = User::query()->first();

        if ($existingUser instanceof User) {
            return $existingUser;
        }

        $row = $this->readCsv($this->resolveFile($path, 'User.csv'))->first();
        $email = (string) ($row['Email'] ?? 'user@example.com');
        $name = filled($row['Name'] ?? null) ? (string) $row['Name'] : Str::before($email, '@');

        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make(Str::password(32)),
        ]);
    }

    /**
     * @return Collection<string, BankAccount>
     */
    private function importAccounts(string $path, User $user): Collection
    {
        $accounts = collect();

        foreach ($this->readCsv($this->resolveFile($path, 'Account.csv')) as $row) {
            $legacyId = $this->nullableString($row['Id'] ?? null);

            if ($legacyId === null) {
                continue;
            }

            $account = BankAccount::query()->firstOrNew(['legacy_id' => $legacyId]);
            $isNew = ! $account->exists;
            $account->fill([
                'user_id' => $user->id,
                'name' => (string) ($row['Name'] ?: 'Unnamed account'),
                'bank_key' => $this->nullableString($row['BankId'] ?? null),
                'bank_name' => $this->bankName($this->nullableString($row['BankId'] ?? null)),
                'currency' => (string) ($row['Currency'] ?: 'DKK'),
                'account_type' => $this->nullableString($row['AccountType'] ?? null),
                'ignore_postings_before' => $this->date($row['IgnorePostingsBefore'] ?? null),
                'is_active' => ! $this->boolean($row['InActive'] ?? null),
                'account_number' => $this->nullableString($row['AccountNumber'] ?? null),
                'is_favorite' => $this->boolean($row['IsFavorite'] ?? null),
            ]);

            if ($isNew) {
                $account->current_balance = $this->decimal($row['LastBookedBalance'] ?? null);
                $account->opening_balance = null;
                $account->last_updated_at = $this->dateTime($row['LastUpdated'] ?? null);
                $account->last_known_posting_date = $this->date($row['LastKnownPostingDate'] ?? null);
            }

            $account->save();

            $accounts->put($legacyId, $account);
        }

        return $accounts;
    }

    /**
     * Bulk-upserts postings keyed on their legacy id, so re-running an import
     * stays idempotent without paying a select-then-write per row.
     *
     * The export's `Amount`/`Currency` pair is the reporting amount in the
     * user's reporting currency. `OriginalAmount`/`OriginalCurrency` is the
     * account movement — but only when its currency matches the account's;
     * otherwise it is a purchase currency and the account movement stays
     * unknown. Both source pairs and the source balance are preserved in
     * `extra_data` before the fields are repurposed.
     *
     * @param  Collection<string, BankAccount>  $accounts
     */
    private function importPostings(string $path, User $user, Collection $accounts, string $reportingCurrency): int
    {
        $subcategoryIds = Subcategory::query()->pluck('id', 'legacy_id');
        $now = now();

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        foreach ($this->readCsv($this->resolveFile($path, 'Posting.csv')) as $row) {
            $legacyId = $this->nullableString($row['Id'] ?? null);
            $accountLegacyId = $this->nullableString($row['AccountId'] ?? null);
            $account = $accountLegacyId === null ? null : $accounts->get($accountLegacyId);

            if ($legacyId === null || ! $account instanceof BankAccount || $this->boolean($row['IsAccountGroupDuplicate'] ?? null)) {
                continue;
            }

            $subcategoryLegacyId = $this->nullableString($row['SubcategoryId'] ?? null);
            $categorizedSubcategoryLegacyId = $this->nullableString($row['CategorizedSubcategoryId'] ?? null);
            $effectiveCategorizedSubcategoryLegacyId = $categorizedSubcategoryLegacyId ?? $subcategoryLegacyId;
            $extraData = $this->json($row['ExtraData'] ?? null) ?? [];
            $extraData['spiir_source'] = [
                'amount' => $this->nullableString($row['Amount'] ?? null),
                'currency' => $this->nullableString($row['Currency'] ?? null),
                'original_amount' => $this->nullableString($row['OriginalAmount'] ?? null),
                'original_currency' => $this->nullableString($row['OriginalCurrency'] ?? null),
                'balance' => $this->nullableString($row['Balance'] ?? null),
            ];

            $amount = $this->decimal($row['Amount'] ?? null);
            $originalAmount = $this->decimal($row['OriginalAmount'] ?? null);
            $originalCurrency = $this->nullableString($row['OriginalCurrency'] ?? null);

            // The original pair only represents the account movement when its
            // currency is the account currency. When it is missing but the
            // reporting amount is already in the account currency, that same
            // figure is the account movement.
            if ($originalCurrency !== $account->currency) {
                if ($row['Currency'] === $account->currency && $amount !== null) {
                    $originalAmount = $amount;
                    $originalCurrency = $account->currency;
                } else {
                    $originalAmount = null;
                    $originalCurrency = null;
                }
            }

            // The exported Balance column is denominated in the reporting
            // currency — it only anchors the account chain when the account
            // currency is the reporting currency.
            $balance = $row['Currency'] === $account->currency
                ? $this->decimal($row['Balance'] ?? null)
                : null;

            $rows[$legacyId] = [
                'legacy_id' => $legacyId,
                'user_id' => $user->id,
                'bank_account_id' => $account->id,
                'subcategory_id' => $subcategoryLegacyId === null ? null : $subcategoryIds->get($subcategoryLegacyId),
                'categorized_subcategory_id' => $effectiveCategorizedSubcategoryLegacyId === null ? null : $subcategoryIds->get($effectiveCategorizedSubcategoryLegacyId),
                'counter_entry_legacy_id' => $this->nullableString($row['CounterEntryPostingId'] ?? null),
                'split_group_legacy_id' => $this->nullableString($row['SplitGroupId'] ?? null),
                'source' => 'spiir',
                'date' => $this->date($row['Date'] ?? null) ?? $now->toDateString(),
                'custom_date' => $this->date($row['CustomDate'] ?? null),
                'description' => (string) ($row['Description'] ?: $row['OriginalDescription'] ?: 'Posting'),
                'description_embedding' => null,
                'description_embedded_at' => null,
                'original_description' => $this->nullableString($row['OriginalDescription'] ?? null),
                'comment' => $this->nullableString($row['Comment'] ?? null),
                'amount' => $amount,
                'original_amount' => $originalAmount,
                'balance' => $balance,
                'upload_batch_id' => $this->nullableString($row['UploadBatchId'] ?? null),
                'external_posting_id' => $this->nullableString($row['ExternalPostingId'] ?? null) === '*** hidden ***'
                    ? null : $this->nullableString($row['ExternalPostingId'] ?? null),
                'document_legacy_id' => $this->nullableString($row['DocumentId'] ?? null),
                'is_extraordinary' => $this->boolean($row['IsExtraordinary'] ?? null),
                'is_account_group_duplicate' => $this->boolean($row['IsAccountGroupDuplicate'] ?? null),
                'prevent_marking_as_counter_entry' => $this->boolean($row['PreventMarkingAsCounterEntry'] ?? null),
                'description_changed' => $this->boolean($row['DescriptionChanged'] ?? null),
                'extra_data' => json_encode($extraData),
                'posting_type' => $this->integer($row['PostingType'] ?? null),
                'posting_sub_type' => $this->integer($row['PostingSubType'] ?? null),
                'currency' => $reportingCurrency,
                'original_currency' => $originalCurrency,
                'merchant_legacy_id' => $this->nullableString($row['MerchantId'] ?? null),
                'country_code' => $this->nullableString($row['CountryCode'] ?? null),
                'state' => $this->nullableString($row['State'] ?? null),
                'carbon_emission_in_grams' => $this->integer($row['CarbonEmissionInGrams'] ?? null),
                'categorized_by' => $this->nullableString($row['CategorizedBy'] ?? null),
                'legacy_created_at' => $this->dateTime($row['Created'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
                // A re-import revives a trashed posting in place, keeping its
                // id so API sync clients see an update rather than a new row.
                'deleted_at' => null,
            ];
        }

        $splitGroups = collect($rows)
            ->filter(fn (array $row): bool => $row['split_group_legacy_id'] !== null)
            ->groupBy(fn (array $row): string => $row['bank_account_id'].':'.$row['split_group_legacy_id']);

        foreach ($splitGroups as $group) {
            $parents = $group->where('posting_type', 2);
            $children = $group->where('posting_type', 0);

            if ($parents->count() !== 1 || $children->isEmpty() || $parents->count() + $children->count() !== $group->count()) {
                continue;
            }

            $parent = $parents->first();

            // A matching reporting sum alone does not prove the parent is
            // redundant — the account amounts must also match when known.
            if (! $this->splitSumsMatch($parent, $children, 'amount')
                || ! $this->splitSumsMatch($parent, $children, 'original_amount')) {
                continue;
            }

            unset($rows[$parent['legacy_id']]);
            // Soft, so sync clients that already have the parent learn it is
            // gone; it is out of $rows, so its legacy id is never upserted.
            // Per model, so the softDeleted hook unlinks counter entries.
            Posting::query()->where('legacy_id', $parent['legacy_id'])->get()->each->delete();
        }

        $updateColumns = array_values(array_diff(array_keys(reset($rows) ?: []), ['legacy_id', 'created_at']));

        foreach (array_chunk($rows, 500) as $chunk) {
            Posting::query()->upsert($chunk, ['legacy_id'], $updateColumns);
        }

        return count($rows);
    }

    /**
     * Whether a split parent's amount column equals the sum of its children.
     * Unknown amounts (null) defer to the other pair's check.
     *
     * @param  array<string, mixed>  $parent
     * @param  Collection<int, array<string, mixed>>  $children
     */
    private function splitSumsMatch(array $parent, Collection $children, string $column): bool
    {
        $parentAmount = $parent[$column];

        if ($parentAmount === null || $children->contains(fn (array $child): bool => $child[$column] === null)) {
            return true;
        }

        $childrenAmount = $children->reduce(
            fn (string $total, array $child): string => bcadd($total, $child[$column], 2),
            '0.00',
        );

        return bccomp($parentAmount, $childrenAmount, 2) === 0;
    }

    private function resolveCounterEntryPostings(): int
    {
        return DB::update(<<<'SQL'
            UPDATE postings
            SET counter_entry_posting_id = counter_entry.id
            FROM postings AS counter_entry
            WHERE postings.counter_entry_posting_id IS NULL
              AND postings.counter_entry_legacy_id = counter_entry.legacy_id
              AND counter_entry.deleted_at IS NULL
        SQL);
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    private function readCsv(string $file): Collection
    {
        $csv = new SplFileObject($file);
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $csv->setCsvControl(';', '"', '\\');

        $headers = null;
        $rows = collect();

        foreach ($csv as $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(
                    fn (?string $header): string => preg_replace('/^\xEF\xBB\xBF/', '', (string) $header) ?? '',
                    $row,
                );

                continue;
            }

            if (count($row) !== count($headers)) {
                continue;
            }

            $rows->push(array_combine($headers, $row));
        }

        return $rows;
    }

    private function resolveFile(string $path, string $file): string
    {
        $candidate = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;

        if (! is_file($candidate)) {
            throw new RuntimeException("Missing Spiir export file: {$candidate}");
        }

        return $candidate;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function integer(mixed $value): ?int
    {
        $value = $this->nullableString($value);

        return $value === null ? null : (int) $value;
    }

    private function decimal(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function date(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : CarbonImmutable::parse($value)->toDateString();
    }

    private function dateTime(mixed $value): ?CarbonImmutable
    {
        $value = $this->nullableString($value);

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(mixed $value): ?array
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : ['raw' => $value];
    }

    private function bankName(?string $bankKey): ?string
    {
        return match ($bankKey) {
            'DK_Wise' => 'Wise',
            'OtherBank' => 'Other bank',
            null => null,
            default => Str::headline($bankKey),
        };
    }

    /**
     * Maps a Spiir category type onto the app's {@see CategoryType}. Spiir's
     * legacy 'Transfer' type, and anything the app no longer models, collapses
     * to {@see CategoryType::Exclude}.
     */
    private function categoryType(mixed $value): CategoryType
    {
        return CategoryType::tryFrom((string) $value) ?? CategoryType::Exclude;
    }
}
