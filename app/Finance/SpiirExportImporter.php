<?php

namespace App\Finance;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

class SpiirExportImporter
{
    /**
     * @return array{categories:int, subcategories:int}
     */
    public function importCategories(string $path): array
    {
        $file = $this->resolveFile($path, 'Categories.json');
        $payload = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $ignoredSubcategoryIds = collect($payload['subcategoryIdsToIgnore'] ?? [])
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return DB::transaction(function () use ($payload, $ignoredSubcategoryIds): array {
            $categoryCount = 0;

            foreach ($payload['categories'] ?? [] as $category) {
                Category::query()->updateOrCreate(
                    ['legacy_id' => (string) $category['id']],
                    [
                        'name' => $category['name'],
                        'type' => $category['categoryType'],
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
                        'expense_type' => $subcategory['expenseType'] ?? null,
                        'type' => $subcategory['categoryType'],
                        'hints' => $subcategory['hints'] ?? null,
                        'is_ignored' => in_array((string) $subcategory['id'], $ignoredSubcategoryIds, true),
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

    /**
     * @return array{user_id:int, accounts:int, postings:int, counter_entries:int}
     */
    public function importExport(string $path): array
    {
        $this->importCategories($path);

        return DB::transaction(function () use ($path): array {
            $user = $this->resolveUser($path);
            $accounts = $this->importAccounts($path, $user);
            $postings = $this->importPostings($path, $user, $accounts);
            $counterEntries = $this->resolveCounterEntryPostings();

            return [
                'user_id' => $user->id,
                'accounts' => $accounts->count(),
                'postings' => $postings,
                'counter_entries' => $counterEntries,
            ];
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
                    'last_booked_balance' => $this->decimal($row['LastBookedBalance'] ?? null),
                    'last_available_balance' => $this->decimal($row['LastAvailableBalance'] ?? null),
                    'account_type' => $this->nullableString($row['AccountType'] ?? null),
                    'account_subcategory_key' => $this->nullableString($row['AccountSubcategoryId'] ?? null),
                    'account_group_legacy_id' => $this->nullableString($row['AccountGroupId'] ?? null),
                    'ignore_postings_before' => $this->date($row['IgnorePostingsBefore'] ?? null),
                    'ignore_postings_after' => $this->date($row['IgnorePostingsAfter'] ?? null),
                    'last_updated_at' => $this->dateTime($row['LastUpdated'] ?? null),
                    'last_known_posting_date' => $this->date($row['LastKnownPostingDate'] ?? null),
                    'is_active' => ! $this->boolean($row['InActive'] ?? null),
                    'inactivated_by' => $this->nullableString($row['InActivatedBy'] ?? null),
                    'account_number' => $this->nullableString($row['AccountNumber'] ?? null),
                    'owner_name' => $this->nullableString($row['OwnerName'] ?? null),
                    'supports_payment_from' => $this->boolean($row['SupportsPaymentFrom'] ?? null),
                    'supports_payment_to' => $this->boolean($row['SupportsPaymentTo'] ?? null),
                    'is_favorite' => $this->boolean($row['IsFavorite'] ?? null),
                    'connection_type' => $this->nullableString($row['ConnectionType'] ?? null),
                    'donation_topup_count' => (int) ($row['DonationTopupCount'] ?: 0),
                    'legacy_created_at' => $this->dateTime($row['Created'] ?? null),
                ],
            );

            $accountIds->put($legacyId, $account->id);
        }

        return $accountIds;
    }

    /**
     * @param  Collection<string, int>  $accountIds
     */
    private function importPostings(string $path, User $user, Collection $accountIds): int
    {
        $subcategoryIds = Subcategory::query()->pluck('id', 'legacy_id');
        $count = 0;

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

            Posting::query()->updateOrCreate(
                ['legacy_id' => $legacyId],
                [
                    'user_id' => $user->id,
                    'bank_account_id' => $bankAccountId,
                    'subcategory_id' => $subcategoryLegacyId === null ? null : $subcategoryIds->get($subcategoryLegacyId),
                    'categorized_subcategory_id' => $effectiveCategorizedSubcategoryLegacyId === null ? null : $subcategoryIds->get($effectiveCategorizedSubcategoryLegacyId),
                    'counter_entry_legacy_id' => $this->nullableString($row['CounterEntryPostingId'] ?? null),
                    'split_group_legacy_id' => $this->nullableString($row['SplitGroupId'] ?? null),
                    'date' => $this->date($row['Date'] ?? null) ?? now()->toDateString(),
                    'custom_date' => $this->date($row['CustomDate'] ?? null),
                    'description' => (string) ($row['Description'] ?: $row['OriginalDescription'] ?: 'Posting'),
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
                    'extra_data' => $this->json($row['ExtraData'] ?? null),
                    'category_type_code' => $this->integer($row['CategoryType'] ?? null),
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
                ],
            );

            $count++;
        }

        return $count;
    }

    private function resolveCounterEntryPostings(): int
    {
        $postings = Posting::query()
            ->whereNotNull('counter_entry_legacy_id')
            ->whereNull('counter_entry_posting_id')
            ->get(['id', 'counter_entry_legacy_id']);
        $legacyIds = Posting::query()->pluck('id', 'legacy_id');
        $count = 0;

        foreach ($postings as $posting) {
            $counterEntryPostingId = $legacyIds[$posting->counter_entry_legacy_id] ?? null;

            if ($counterEntryPostingId === null) {
                continue;
            }

            $posting->forceFill(['counter_entry_posting_id' => $counterEntryPostingId])->save();
            $count++;
        }

        return $count;
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
}
