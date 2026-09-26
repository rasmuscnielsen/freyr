<?php

namespace App\Finance;

use App\Enums\CategoryType;
use App\Enums\ExpenseType;
use App\Models\BankAccount;
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

class SpiirExportImporter
{
    public function __construct(private AccountBalanceRecalculator $balanceRecalculator) {}

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

            $categoryIds = Category::query()->pluck('id', 'legacy_id');
            $subcategoryCount = 0;

            foreach ($payload['subcategories'] ?? [] as $subcategory) {
                $categoryId = $categoryIds[(string) $subcategory['categoryId']] ?? null;

                if ($categoryId === null) {
                    continue;
                }

                Subcategory::query()->updateOrCreate(
                    ['legacy_id' => (string) $subcategory['id']],
                    [
                        'category_id' => $categoryId,
                        'name' => $subcategory['name'],
                        'hyphenated_name' => $subcategory['hyphenatedName'] ?? null,
                        'expense_type' => $this->expenseType($subcategory['expenseType'] ?? null)?->value,
                        'hints' => $subcategory['hints'] ?? null,
                    ],
                );

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
        Category::query()
            ->where('legacy_id', 'like', DefaultCategoriesSeeder::CATEGORY_LEGACY_ID_PREFIX.'%')
            ->delete();
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

        return DB::transaction(function () use ($path, $user): array {
            $user ??= $this->resolveUser($path);

            $accounts = $this->importAccounts($path, $user);
            $postings = $this->importPostings($path, $user, $accounts);
            $counterEntries = $this->resolveCounterEntryPostings();
            $this->recalculateImportedBalances($accounts);

            return [
                'user_id' => $user->id,
                'accounts' => $accounts->count(),
                'postings' => $postings,
                'counter_entries' => $counterEntries,
            ];
        });
    }

    /**
     * Imported accounts have no bank connection yet, so their balances are
     * derived: seed each account's opening balance from the imported chain
     * while the imported per-posting balances are still authoritative, then
     * rewrite the chain so it is consistent by construction.
     *
     * @param  Collection<string, int>  $accountIds
     */
    private function recalculateImportedBalances(Collection $accountIds): void
    {
        BankAccount::query()
            ->whereIn('id', $accountIds->values())
            ->get()
            ->each(function (BankAccount $account): void {
                $this->balanceRecalculator->seedOpeningBalanceFromImportedChain($account);
                $this->balanceRecalculator->recalculate($account);
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
     * @return Collection<string, int>
     */
    private function importAccounts(string $path, User $user): Collection
    {
        $accountIds = collect();

        foreach ($this->readCsv($this->resolveFile($path, 'Account.csv')) as $row) {
            $legacyId = $this->nullableString($row['Id'] ?? null);

            if ($legacyId === null) {
                continue;
            }

            $account = BankAccount::query()->updateOrCreate(
                ['legacy_id' => $legacyId],
                [
                    'user_id' => $user->id,
                    'name' => (string) ($row['Name'] ?: 'Unnamed account'),
                    'bank_key' => $this->nullableString($row['BankId'] ?? null),
                    'bank_name' => $this->bankName($this->nullableString($row['BankId'] ?? null)),
                    'currency' => (string) ($row['Currency'] ?: 'DKK'),
                    'current_balance' => $this->decimal($row['LastBookedBalance'] ?? null),
                    'account_type' => $this->nullableString($row['AccountType'] ?? null),
                    'ignore_postings_before' => $this->date($row['IgnorePostingsBefore'] ?? null),
                    'last_updated_at' => $this->dateTime($row['LastUpdated'] ?? null),
                    'last_known_posting_date' => $this->date($row['LastKnownPostingDate'] ?? null),
                    'is_active' => ! $this->boolean($row['InActive'] ?? null),
                    'account_number' => $this->nullableString($row['AccountNumber'] ?? null),
                    'is_favorite' => $this->boolean($row['IsFavorite'] ?? null),
                ],
            );

            $accountIds->put($legacyId, $account->id);
        }

        return $accountIds;
    }

    /**
     * Bulk-upserts postings keyed on their legacy id, so re-running an import
     * stays idempotent without paying a select-then-write per row.
     *
     * @param  Collection<string, int>  $accountIds
     */
    private function importPostings(string $path, User $user, Collection $accountIds): int
    {
        $subcategoryIds = Subcategory::query()->pluck('id', 'legacy_id');
        $now = now();

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        foreach ($this->readCsv($this->resolveFile($path, 'Posting.csv')) as $row) {
            $legacyId = $this->nullableString($row['Id'] ?? null);
            $accountLegacyId = $this->nullableString($row['AccountId'] ?? null);
            $bankAccountId = $accountLegacyId === null ? null : $accountIds->get($accountLegacyId);

            if ($legacyId === null || $bankAccountId === null) {
                continue;
            }

            $subcategoryLegacyId = $this->nullableString($row['SubcategoryId'] ?? null);
            $categorizedSubcategoryLegacyId = $this->nullableString($row['CategorizedSubcategoryId'] ?? null);
            $effectiveCategorizedSubcategoryLegacyId = $categorizedSubcategoryLegacyId ?? $subcategoryLegacyId;
            $extraData = $this->json($row['ExtraData'] ?? null);

            $rows[$legacyId] = [
                'legacy_id' => $legacyId,
                'user_id' => $user->id,
                'bank_account_id' => $bankAccountId,
                'subcategory_id' => $subcategoryLegacyId === null ? null : $subcategoryIds->get($subcategoryLegacyId),
                'categorized_subcategory_id' => $effectiveCategorizedSubcategoryLegacyId === null ? null : $subcategoryIds->get($effectiveCategorizedSubcategoryLegacyId),
                'counter_entry_legacy_id' => $this->nullableString($row['CounterEntryPostingId'] ?? null),
                'split_group_legacy_id' => $this->nullableString($row['SplitGroupId'] ?? null),
                'date' => $this->date($row['Date'] ?? null) ?? $now->toDateString(),
                'custom_date' => $this->date($row['CustomDate'] ?? null),
                'description' => (string) ($row['Description'] ?: $row['OriginalDescription'] ?: 'Posting'),
                'description_embedding' => null,
                'description_embedded_at' => null,
                'original_description' => $this->nullableString($row['OriginalDescription'] ?? null),
                'comment' => $this->nullableString($row['Comment'] ?? null),
                'amount' => $this->decimal($row['Amount'] ?? null) ?? '0.00',
                'original_amount' => $this->decimal($row['OriginalAmount'] ?? null),
                'balance' => $this->decimal($row['Balance'] ?? null),
                'upload_batch_id' => $this->nullableString($row['UploadBatchId'] ?? null),
                'external_posting_id' => $this->nullableString($row['ExternalPostingId'] ?? null),
                'document_legacy_id' => $this->nullableString($row['DocumentId'] ?? null),
                'is_extraordinary' => $this->boolean($row['IsExtraordinary'] ?? null),
                'is_account_group_duplicate' => $this->boolean($row['IsAccountGroupDuplicate'] ?? null),
                'prevent_marking_as_counter_entry' => $this->boolean($row['PreventMarkingAsCounterEntry'] ?? null),
                'description_changed' => $this->boolean($row['DescriptionChanged'] ?? null),
                'extra_data' => $extraData === null ? null : json_encode($extraData),
                'posting_type' => $this->integer($row['PostingType'] ?? null),
                'posting_sub_type' => $this->integer($row['PostingSubType'] ?? null),
                'currency' => (string) ($row['Currency'] ?: 'DKK'),
                'original_currency' => $this->nullableString($row['OriginalCurrency'] ?? null),
                'merchant_legacy_id' => $this->nullableString($row['MerchantId'] ?? null),
                'country_code' => $this->nullableString($row['CountryCode'] ?? null),
                'state' => $this->nullableString($row['State'] ?? null),
                'carbon_emission_in_grams' => $this->integer($row['CarbonEmissionInGrams'] ?? null),
                'categorized_by' => $this->nullableString($row['CategorizedBy'] ?? null),
                'legacy_created_at' => $this->dateTime($row['Created'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $updateColumns = array_values(array_diff(array_keys(reset($rows) ?: []), ['legacy_id', 'created_at']));

        foreach (array_chunk($rows, 500) as $chunk) {
            Posting::query()->upsert($chunk, ['legacy_id'], $updateColumns);
        }

        return count($rows);
    }

    private function resolveCounterEntryPostings(): int
    {
        return DB::update(<<<'SQL'
            UPDATE postings
            SET counter_entry_posting_id = counter_entry.id
            FROM postings AS counter_entry
            WHERE postings.counter_entry_posting_id IS NULL
              AND postings.counter_entry_legacy_id = counter_entry.legacy_id
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

    private function expenseType(mixed $value): ?ExpenseType
    {
        return ExpenseType::tryFrom((string) $value);
    }
}
