# Regional preferences and multi-currency reporting

Date: 2026-09-27

Status: Implementation plan. This document replaces the earlier multi-currency plan.

## 1. Purpose and agreed scope

Give the existing posting amount pairs fixed meanings. Show all eligible postings in each user's reporting currency. Calculate account balances in the account currency.

Use short sentences and explicit instructions. This document follows Simplified Technical English principles. It does not claim formal ASD-STE100 certification.

The agreed design is:

- Each user selects a country, timezone, and reporting currency.
- The interface stays English. Number formatting follows the user's country.
- Reporting currency is fixed after initial setup. A Spiir import can replace the initial choice before it writes postings.
- Spiir reporting amounts must match the importing user's reporting currency.
- Enable Banking writes account amounts to the existing original amount pair.
- The existing main amount pair holds the reporting amount.
- Same-currency amounts are copied immediately. Foreign amounts are converted after sync.
- Missing reporting amounts stay null. Reports show incomplete coverage.
- Frankfurter supplies ECB reference rates for all users. No API key, registration, or FX configuration screen is required.
- Persistent rates and a shared cooldown prevent repeated requests after a rate limit.
- Both queued and synchronous queue drivers must work.
- The owner will perform a clean wipe and reimport. Do not build a legacy-data repair system.

This plan does not authorize an automatic wipe. Do not reset the owner's data while implementing it.

## 2. Setting ownership

Use the user model for personal preferences. Keep existing Enable Banking credentials in platform settings. Frankfurter needs no credentials. Do not put personal preferences in `PlatformSettings`.

| Setting or state | Scope | Behavior |
| --- | --- | --- |
| Country | User | Sets number-format defaults and the default country in bank selection. Can change later. |
| Timezone | User | Controls timestamp display. Can change later. Store an IANA timezone identifier. |
| Reporting currency | User | Selected during initial setup. No ordinary edit endpoint after setup. Spiir has the limited override described below. |
| Region setup completion | User | Controls the personal setup step for every user, including later registrations. |
| Number-format locale | Derived per user | Derive from country. For Denmark, use Danish decimal and thousands separators. No language selector is needed. |
| FX integration | Platform | Frankfurter v2 with the ECB source. Fixed application behavior, not a user preference or credential setting. |
| Rate cache | Platform | Share non-personal rate data across users. Key by provider, base currency, quote currency, and effective date. |
| Provider cooldown and request coordination | Platform | Shared across all accounts, users, workers, and synchronous requests in the installation. |
| Enable Banking application credentials | Platform | Keep the current ownership and encrypted storage. |
| Bank-selection country | Connection or request | Default from the user. Allow a different country for a foreign bank. Do not overwrite existing connection metadata when user country changes. |
| Posting conversions and incomplete counts | User | Always query through the posting owner. Never expose another user's financial data. |
| Platform onboarding completion | Platform | Keep separate from personal region setup. Finishing platform setup must not skip region setup for later users. |

Country is not a substitute for timezone or reporting currency. A user can live in Denmark, use another timezone, and report in EUR.

### 2.1 Onboarding and subsequent edits

1. Collect region preferences for the first administrator and every subsequent user.
2. Require completed region preferences before that user creates accounts, imports postings, or starts bank sync.
3. Validate country and supported currency codes. Validate the timezone against supported IANA identifiers.
4. Show the selected reporting currency and explain that it cannot be changed later.
5. After setup, allow country and timezone edits only. These edits must not change reporting currency.
6. Keep English interface text. Apply country-based number formatting to money in the dashboard, tables, tooltips, and account views.
7. Keep booking dates as dates. Do not shift a date-only posting into another day when displaying a timezone.
8. Convert timestamps, such as last sync time, for display. Keep stored timestamps in the application's standard timezone.

Use the existing registration flow for later users. Do not send them through administrator-only platform onboarding.

### 2.2 Spiir currency override

Before writing import data:

1. Determine the export's reporting currency from all usable reporting amount pairs. Do not use account currencies or the first arbitrary row.
2. Require one consistent reporting currency. Reject conflicting or unidentifiable reporting currencies with a clear import error.
3. Require that the importing user has no existing monetary history. Do not change a currency underneath postings, opening balances, or active sync work.
4. Set that user's reporting currency to the export currency inside the same transaction as the import.
5. Keep country and timezone unchanged.
6. If the import fails, roll back the currency change and imported data together.
7. After import, keep reporting currency fixed.

An exact repeat of the same import can retain existing idempotent behavior if it does not change the currency or overwrite unrelated history. Do not treat this as permission for a general currency-switching feature.

The current importer has installation-wide eligibility and category operations. Do not turn it into a general per-user import endpoint merely by changing `canImport()`. If multi-user import is exposed, scope every query, legacy identifier, category operation, and upsert to its intended owner first. Otherwise retain the existing restricted import entry point. This plan requires per-user region preferences; it does not require opening Spiir import to every hosted user.

## 3. Monetary contract

Reuse the existing posting fields.

| Fields | Required meaning |
| --- | --- |
| `original_amount`, `original_currency` | Signed amount booked against the account, in the account currency. |
| `amount`, `currency` | Reporting amount in the owning user's fixed reporting currency. `amount` can be null. |
| `balance` | Running balance in the account currency. Null when no valid balance can be established. |
| Account `opening_balance`, `current_balance` | Amounts in the account currency. Unknown balances must remain distinguishable from zero. |

For pending conversion, set `currency` to the user's reporting currency and leave `amount` null. Null is not zero.

The word `original` is a legacy field name. It does not mean the merchant's purchase currency. A USD purchase charged to an EUR account needs the EUR debit in `original_amount`. Retain any distinct purchase amount in source evidence.

Use account amounts for:

- Running balances and reconciliation.
- Manual posting input and edits.
- Sign-based classification when reporting conversion is pending.
- Account transaction history and account-specific amount sorting.

Use reporting amounts for:

- Dashboard income, spending, and result totals.
- Monthly charts, category breakdowns, and their tooltips.
- Report-specific amount sorting and ranking.

Do not hide this change behind a model accessor. SQL sums, API output, and manual edits must follow the same explicit contract.

### 3.1 Minimal schema changes

The current posting schema requires `amount`. Add a migration to make it nullable. Keep `currency` populated with the target reporting currency.

Add country, timezone, reporting currency, and a region-completion marker to users. Prefer a completion timestamp to a separate currency-lock system. Enforce the limited Spiir exception at the import boundary.

Do not add FX credential keys, API-key storage, or provider setup routes. Existing Enable Banking credentials remain unchanged.

Add one persistent daily-rate table. Store provider, base, quote, rate, and effective date with a unique key. Give rates enough decimal precision for conversion.

Use the existing shared cache for request locks and cooldown if its production backend is shared across workers. If it is not, store these controls in the database. Do not rely on process memory or queue-driver-specific behavior.

Do not add new canonical amount pairs, a generic money table, or a per-posting conversion ledger. Preserve source monetary evidence in the existing payload storage. Copy the needed Spiir values there before repurposing fields. Do not overwrite unrelated payload keys.

Verify balance nullability. A manual account can default to an explicit zero opening balance. An imported account with no account-currency anchor must not inherit that zero as if it were known. Add the smallest required nullability change if the current schema cannot express this distinction.

Keep the existing supported monetary precision for this change. Do not advertise arbitrary currencies with unsupported minor-unit scales. Validate preference options against supported precision and ECB coverage. Check historical coverage separately for each required date.

## 4. Import and edit behavior

### 4.1 Spiir

- Preserve the source amount pairs and source balance with their known currencies in import evidence.
- Populate the main amount pair from the verified export reporting amount.
- Populate the original pair from the verified account movement.
- In the observed EUR example, use the EUR original pair for the account movement and the DKK pair for reporting.
- Do not assume that every export's original pair is an account movement. Validate source meaning and account currency.
- Keep a foreign-currency source balance out of `balance`.
- Preserve split handling. A reporting sum that matches does not prove that account amounts also match.
- Do not count a split parent and its children twice.
- If evidence does not establish an account movement, retain a null original amount and expose incomplete account history. Do not copy the reporting amount into it.
- Set the source to Spiir explicitly on newly imported postings.

A source reporting amount can remain usable when account evidence is incomplete. Reporting coverage and reconciliation coverage are separate.

### 4.2 Enable Banking

1. Verify the provider's amount and balance semantics before implementation. Use provider documentation and sanitized payloads.
2. Store the verified booked account amount and currency in the original pair.
3. If account currency equals reporting currency, fill the main amount pair immediately.
4. Otherwise leave the reporting amount null until conversion succeeds.
5. Accept a bank running balance only when it is in the account currency.
6. Use a compatible booked balance for reconciliation. Do not silently substitute an available balance when no booked balance exists.
7. Keep the existing transaction identity, duplicate checks, and imported-history cutoff.
8. On unchanged re-sync, preserve completed reporting conversions. Do not set them back to null on every upsert.
9. If account amount or booking date changes, recalculate same-currency reporting values immediately. Clear foreign reporting amounts for conversion again.
10. Do not clear conversions after category or comment edits.

Validate remote and local account currencies before linking. Treat a mismatch as a link error, not as a conversion task.

### 4.3 Manual posting edits

Manual input is in the account currency. Update the original pair first. Copy or invalidate the reporting pair using the same rule as bank import.

Apply this rule to UI controllers and MCP edits. Audit API mutation paths if any are enabled. Recalculate account balances from original amounts after an insert, amount edit, date edit, or deletion.

Do not allow an account currency change to relabel existing money. Reject it once account monetary history exists.

## 5. Balance calculations

Update the existing balance classes. Do not build a parallel implementation.

- `AccountBalanceRecalculator`: use original account amounts for SQL sums and opening-balance calculations.
- `SyncedBalanceChainWriter`: use original account amounts and only account-currency anchors.
- `BalanceChainInspector`: compare account values. Use original amounts for duplicate and gap diagnostics.
- Connection unlink and disconnect paths: keep the same contract when the account becomes manual.

For a freshly imported EUR account, a Spiir DKK balance cannot anchor EUR history. Leave derived balances unknown until valid EUR evidence exists. Do not convert the source balance with a posting's implied rate.

A later valid checkpoint can start a new calculable segment. An unresolved account amount breaks propagation until another valid anchor exists. Never skip the missing movement silently.

Keep the existing same-day order handling and break-display limit behavior.

If the current booked balance is used to derive an opening balance, the resulting match is true by construction. Return an explicit comparison status or reason so the UI does not call it independent verification. Add only the minimal persisted anchor-origin marker needed to retain this distinction across later syncs. Do not infer provenance from a non-null balance.

Use null for unknown delta and a clear status for not-comparable history. Preserve real mismatch warnings when an independent comparison is possible.

No legacy balance repair, source classification command, or historical sync-run cleanup is required. The owner will reimport clean data through the corrected implementation.

## 6. FX policy and service

### 6.1 Selected provider: Frankfurter v2, ECB source

Use [Frankfurter v2](https://frankfurter.dev/) with its explicit ECB provider route. Do not use the default blended-source feed.

The current documentation states that the public API needs no API key and has no daily or monthly quota. Anti-abuse rate limits still apply. It supports historical dates, date ranges, and configurable base and quote currencies. Commercial API use is allowed subject to the underlying providers' data terms.

Use these documented routes:

```text
GET https://api.frankfurter.dev/v2/providers/ecb/rates?base=EUR&quotes=DKK,USD&from=2024-01-01&to=2024-12-31
GET https://api.frankfurter.dev/v2/providers/ecb/rates?base=EUR&quotes=DKK,USD
```

The first route supplies history. The second supplies the latest available observations. These examples follow the documented API shape; they are not a recorded live integration test. Verify response shape, date semantics, and error handling when implementing the client.

Store the source identity as `frankfurter:ecb` in the rate cache. Store each response's actual observation date. Do not replace it with the requested date or today's date. No user identifiers, balances, posting descriptions, or transaction amounts need to leave Freyr.

[ECB reference rates](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html) cover DKK and other supported currencies. They are normally published around 16:00 CET on working days, excluding TARGET closing days. They are reference values for reporting, not evidence of the bank's execution rate.

Frankfurter's overall currency coverage is broader than ECB coverage. Do not advertise that full list when using the ECB route. Check the selected source's currency and date coverage. If a pair or date is unsupported, retain the account movement and show missing reporting coverage. Do not silently switch sources.

Use the existing HTTP client. Add one small Frankfurter client, not a multi-provider abstraction or a new dependency. Self-hosting is available upstream, but is outside this implementation.

### 6.2 Rate rules

- Prefer an existing source reporting amount in the fixed reporting currency. Do not revalue Spiir history.
- For new conversions, use the daily rate for the booking date.
- For a non-publication date, use the latest preceding valid published rate.
- Never use a future rate.
- Use a maximum fallback age of seven calendar days for ECB reference rates. An older observation leaves the conversion pending. Do not carry suspended or discontinued rates forward indefinitely.
- Define rate direction: one base-currency unit equals `rate` quote-currency units.
- Calculate with decimal arithmetic. Do not use binary float arithmetic for stored amounts.
- Round once at the reporting currency's supported scale. Use one rule, such as half away from zero, for debits and credits.
- Keep completed posting amounts stable on later rate refreshes.
- Retain the rate's effective date. Multiple weekend booking dates can share one effective rate.

### 6.3 Conversion job

Use one job with the posting owner as its scope. Scan that user's unresolved postings, including older imports and disconnected accounts. Do not restrict retries to only the latest transaction window.

Dispatch after the bank sync transaction commits. With `QUEUE_CONNECTION=sync`, the same job runs inline after commit. With a worker, it runs asynchronously. Do not require a queue worker for correctness.

Run this sequence:

1. Copy any remaining same-currency values.
2. Apply cached rates to all eligible unresolved postings in bounded database chunks.
3. Group missing needs by base currency, quote currency, and date.
4. Check whether Frankfurter is in the installation-wide cooldown. No credential check is needed.
5. Acquire a shared provider request lock. Recheck the cooldown and cache inside the lock before requesting.
6. Fetch missing ECB rates from Frankfurter in bounded date ranges. Include the preceding seven calendar days when needed to resolve non-publication dates.
7. Store reusable rates and fill reporting amounts.
8. Stop when the request or time budget is reached. Leave the rest for another run.

Start with at most three HTTP requests and a 20-second wall-clock budget per run. Give each request a five-second timeout. Request no more than one year per range and only the needed quote currencies. Check the budget between requests and database chunks. These are application limits, not Frankfurter quotas. Do not add user-facing tuning settings.

Make each write conditional on the posting still having the original amount and booking date used for conversion. A concurrent edit must not receive a stale converted amount. Avoid long database transactions around network requests.

### 6.4 Failure and retry rules

- Rate limit: record a provider-wide retry time, respect `Retry-After` when supplied, and stop network requests for this run.
- If a rate-limit response has no usable retry time, use a shared 15-minute cooldown. Do not retry immediately. Frankfurter has no published daily or monthly quota, so no quota-reset or credential-repair flow is needed.
- Unexpected access-denied responses: stop requests, apply the cooldown, and record a service error. Do not prompt the user for a nonexistent API key.
- Timeout or service failure: stop repeated requests for this run and apply a shared five-minute cooldown.
- Unsupported pair or unavailable historical date: do not block unrelated pairs. Cache the missing result for a bounded period to avoid repeating it on every posting.
- Continue to use cached rates during cooldown. A provider failure does not invalidate stored rates or completed amounts.
- Do not sleep or recursively dispatch immediate retries, especially with the synchronous driver.
- Catch conversion-service failures so a committed bank sync is not marked failed solely because FX conversion is unavailable.

Later bank syncs retry missing reporting amounts. No administrator setup is needed. For a large backlog, provide a user-scoped retry action and reuse the normal scheduled sync cadence. Do not depend on an immediate self-dispatch loop. A bounded run must explain that some history remains pending.

After initial history is filled, cache each needed pair and effective date once. Share these rates across accounts and users. A Frankfurter request can return multiple dates and quote currencies, so do not issue one request per posting or pair when one range request can supply them.

## 7. User interface and API behavior

### 7.1 Rate availability notice

Foreign-currency reporting works without administrator configuration. Remove the planned API-key banner and FX settings screen.

When conversion is incomplete, show the affected user a concise reason: pending conversion, temporary rate-service failure, or unavailable currency/date coverage. Offer the user-scoped retry action when useful. The action must respect the same shared cooldown and request budget.

Do not expose other users' accounts, provider response bodies, or installation-wide financial details. Use in-app notices only. Do not add email or external notifications in this change.

### 7.2 Incomplete reporting

Keep unresolved postings visible in account history and posting lists. Display the original account amount and currency while reporting conversion is pending.

Aggregate only non-null reporting amounts whose currency matches the owning user's reporting currency. Return coverage for the selected report scope: eligible posting count, converted count, and missing count.

Do not include excluded postings in missing counts for a report they do not contribute to. Use original amounts for sign-based uncategorized classification so a pending expense is not treated as zero income.

Label known subtotals as partial. Distinguish an empty period from a period where all amounts are unresolved. Apply the same rule to dashboard summaries, charts, deferred breakdowns, and tooltips.

Update resource types so null remains null. Do not cast a missing amount to `0.0` in `PostingResource`, controllers, or MCP output.

### 7.3 Current account balances

Account pages show balances in each account's currency.

For a consolidated current balance, use the latest acceptable rates as of the valuation time. Do not sum historical converted postings to obtain current value. Reuse the same rate cache and request safeguards. Do not make provider calls while rendering the page.

Return valuation date and missing-account coverage. A missing current balance or rate makes the valuation partial. Historical spending amounts remain unchanged when the current valuation rate changes.

## 8. Implementation sequence and file guidance

### Step 1: Add preferences and ownership rules

- Extend `app/Models/User.php` and user migrations.
- Integrate personal setup with first-user onboarding and subsequent registration.
- Keep `app/Http/Middleware/RedirectToOnboarding.php` platform behavior separate from personal preference completion.
- Use current country as the default in bank selection. Preserve the connection's selected country.
- Add country-based number formatting and timezone display through existing frontend helpers.

### Step 2: Establish the monetary contract

- Make posting `amount` nullable and add any required unknown-balance support.
- Update `app/Models/Posting.php`, factories, and seeders.
- Update `app/Finance/SpiirExportImporter.php` and `app/Finance/EnableBanking/EnableBankingTransactionMapper.php`.
- Update sync upserts in `app/Finance/EnableBanking/EnableBankingConnectionManager.php`.
- Update manual posting controllers, requests, and `app/Mcp/Tools/EditPosting.php`.
- Update API resources and frontend types for null reporting amounts.

### Step 3: Correct balance consumers

- Update `AccountBalanceRecalculator`, `SyncedBalanceChainWriter`, and `BalanceChainInspector` together.
- Update account-link currency validation and disconnect paths.
- Update account and connection warnings to distinguish mismatch from incomplete or derived history.
- Confirm no balance calculation still sums reporting `amount`.

### Step 4: Add the Frankfurter integration

- Add no platform credential settings or configuration UI.
- Add the daily-rate table, one Frankfurter client using the fixed ECB source, and the conversion job.
- Implement shared cache lookup, cooldown, request lock, and bounded execution.
- Dispatch after successful sync commit, after Spiir import if unresolved values remain, and from the user-scoped retry action.

### Step 5: Switch reporting and formatting

- Update `app/Http/Controllers/DashboardController.php` and `resources/js/pages/dashboard.tsx` together.
- Read the user's fixed reporting currency. Remove most-common-account-currency selection.
- Use reporting amounts consistently in summaries and breakdowns.
- Add partial coverage and current-balance valuation.
- Update account pages, posting lists, API resources, and MCP responses.

### Step 6: Verify and reimport

- Run focused tests and formatting.
- Smoke-test both queue drivers with disposable synthetic records.
- Ask the owner to run the complete suite before merge.
- Coordinate the clean wipe and reimport separately. Do not execute it as part of schema migration or deployment automatically.
- No legacy-data repair command or old-schema compatibility mode is part of this change.

Before implementation, re-read applicable repository rules and skills. Confirm installed package versions and use the Boost documentation tool for framework-dependent changes. Inspect the actual schema before writing migrations.

## 9. Specific test coverage

Use existing Pest feature tests where they fit. Use synthetic users, banks, merchants, identifiers, amounts, and rates. Freeze time. Fake provider HTTP responses and use the existing bank client fakes. Keep real database queries for application behavior.

### 9.1 Preferences and multiple users

Extend `tests/Feature/OnboardingTest.php` and profile settings tests. Existing platform credential behavior does not change.

| Case | Expected result |
| --- | --- |
| First administrator completes region setup. | Personal preferences are stored on that user, not as platform defaults. |
| A later user registers after platform onboarding is complete. | Personal region setup is still required. Platform setup pages stay administrator-only. |
| Users A and B select DKK and EUR. | Each dashboard and conversion job uses its own owner's currency. |
| User A changes country or timezone. | User B and both reporting currencies stay unchanged. |
| User attempts to change reporting currency after setup through a crafted request. | Reject the mutation even if the field is hidden in the UI. |
| Spiir currency differs from the importing user's initial selection and no monetary history exists. | Import changes that user's currency atomically. Other users stay unchanged. |
| Export has mixed or missing reporting currencies, or the user already has incompatible history. | Reject without partial import or currency changes. |
| Import fails after its currency update. | Both imported data and currency update roll back. |
| User country is DK, but user connects to a bank in another country. | Default selection uses DK; explicit selection is allowed and stored on the connection. |
| Danish formatting displays 1234.56. | Money uses `1.234,56`; interface labels remain English. |
| Timestamp crosses midnight in the selected timezone. | Timestamp display changes correctly. A date-only booking date does not move. |
| A non-administrator connects a supported foreign-currency account on a clean installation. | Conversion works without platform FX setup. No API-key prompt appears. |
| User A invokes the retry action with User B's identifiers. | Reject access. No financial records or conversion status cross user boundaries. |

### 9.2 Core import-to-sync regression

Use the real importer and sync flow in `tests/Feature/BankConnectionTest.php` or a focused new feature test.

1. Create a synthetic EUR account with an independently known EUR 100.00 opening balance.
2. Import a Spiir row with account movement EUR -10.00, reporting amount DKK -75.00, and source balance DKK 675.00.
3. Sync a later Enable Banking movement of EUR -5.00 and a booked balance of EUR 85.00.
4. Supply a fake daily rate of 7.40 DKK per EUR for the later movement.

Assert:

- Original amounts are EUR -10.00 and EUR -5.00.
- Reporting amounts are DKK -75.00 and DKK -37.00.
- Account balances are EUR 90.00 and EUR 85.00.
- Consolidated spending is DKK 112.00.
- The DKK 675.00 balance remains source evidence and never becomes an EUR anchor.
- Independent reconciliation succeeds without the false large delta.
- Repeated sync creates no duplicates and does not clear completed reporting amounts.

Repeat without the independent EUR opening anchor. Assert that the DKK balance is not used. If the booked EUR snapshot supplies a derived opening, the result must not claim independent verification.

### 9.3 Monetary normalization and balance behavior

Extend `tests/Feature/FinanceImportTest.php`, `tests/Feature/BalanceChainInspectorTest.php`, `tests/Feature/AccountBalanceRecalculatorTest.php`, and `tests/Feature/ManualPostingTest.php`.

| Case | Expected result |
| --- | --- |
| Same-currency bank posting. | Both amount pairs are filled immediately without FX HTTP requests. |
| Foreign posting without a rate. | Original amount is visible; reporting amount stays null in storage and serialized output. |
| Original purchase currency differs from booked account currency. | Use the account debit for balances. Preserve distinct purchase evidence. |
| Spiir preserves a reporting amount that differs from a provider calculation. | Keep the Spiir amount unchanged. |
| Split parent and children exist. | Count the movement once; check account and reporting sums separately. |
| No source pair establishes the account movement. | Account history is incomplete. Do not use reporting money as account money. |
| Unresolved movement between valid checkpoints. | Do not propagate through the unknown amount. A later checkpoint can restart calculation. |
| No independent account-currency anchor. | Show unknown or derived status, not a false independent match. |
| Same-day rows are stored out of order, or breaks exceed the display cap. | Preserve correct closing calculation and bounded displayed details. |
| Real duplicate or missing movement exists. | Keep genuine account-currency diagnostics. |
| Booked target is missing or has the wrong currency. | No numeric reconciliation delta against an incompatible target. |
| Unchanged bank transaction is reimported. | Preserve existing conversion and evidence. |
| Amount or booking date changes. | Recalculate same-currency values or clear foreign reporting amount for retry. |
| Only category or comment changes. | Keep the existing conversion. |
| Manual insert, edit, deletion, and disconnect. | Balance calculations use original amounts and reporting follows the same conversion rules. |
| Link currencies differ, or an account with history is relabelled. | Reject before monetary data changes. |

### 9.4 Frankfurter rates, throttling, and queue drivers

Add focused FX conversion feature tests. Use exact fake HTTP responses and assert request counts.

| Case | Expected result |
| --- | --- |
| Historical import needs many dates. | Request the fixed ECB route in bounded ranges with only needed currencies. Do not use the blended feed or one request per posting. |
| A requested date has an earlier effective observation date. | Preserve the actual date and enforce the seven-day age limit. |
| Currency exists in Frankfurter but not in its ECB source. | Keep conversion unresolved. Do not fall back silently to another source. |
| 200 postings need one pair and one date. | One missing-rate lookup, not 200. All eligible amounts are filled. |
| Two users need the same rate. | Share one cached rate while writing only each user's own postings. |
| Same base currency, different users' target currencies. | Use distinct quote-currency keys. No cross-currency cache collision. |
| Weekend has no published rate. | Use the preceding permitted rate, record its date, and reuse it. |
| Only future or excessively old rate exists. | Leave reporting amount null. Do not apply an invalid fallback. |
| Provider returns zero, negative, malformed, or wrong-pair data. | Reject the rate. Do not fill reporting amounts. |
| First missing request receives HTTP 429 with retry instructions. | Stop further provider requests, persist shared cooldown, and leave unresolved amounts null. |
| Another account, user, or worker runs during cooldown. | Make zero provider requests. Continue applying valid cached rates. |
| Cooldown expires. | Resume bounded requests and fill unresolved amounts. |
| HTTP 429 has no usable retry time. | Apply the shared 15-minute fallback cooldown and issue no more requests during it. |
| Unexpected access-denied response occurs. | Show temporary service unavailability. Do not prompt for credentials. |
| Provider times out or returns a service error. | Stop repeated network attempts. Committed bank sync remains successful. |
| One historical date or pair is unavailable. | Remember the miss briefly and allow unrelated conversions. |
| Two conversions run concurrently. | Shared request coordination prevents duplicate lookups and respects cooldown set by the first request. |
| A posting is edited while a rate request is in flight. | Do not write an amount calculated from stale input. |
| Initial history exceeds the run budget. | Stop cleanly, keep completed amounts, and resume on a later attempt without recursive dispatch. |
| Sync driver is selected. | Conversion runs after commit with bounded work and no worker dependency. |
| Async driver is selected. | Job is queued after commit, scopes to its user, and remains idempotent on retry. |
| Import transaction rolls back. | No conversion runs against uncommitted or rolled-back postings. |
| User invokes retry after a cooldown expires. | Resume unresolved work without credentials, currency changes, or reimport. |
| Debit and refund hit a rounding boundary. | Apply the specified decimal rounding symmetrically. Known zero stays distinct from null. |

### 9.5 Reporting and current valuation

Extend `tests/Feature/DashboardFinanceTest.php` and API/MCP tests.

| Case | Expected result |
| --- | --- |
| User has EUR and DKK accounts with DKK reporting. | Summary, chart, breakdown, and tooltips all use DKK reporting amounts. |
| Some eligible amounts are null. | Known totals are labelled partial with correct scope-specific counts. |
| All eligible amounts are null. | Show unavailable or incomplete totals, not a complete zero. |
| No eligible postings exist. | Show a complete empty period. |
| Null reporting amount on an uncategorized expense. | Original negative amount determines the expense bucket and missing coverage. |
| Excluded category, transfer, refund, or extraordinary posting. | Preserve existing eligibility and sign rules. Missing counts use the same rules. |
| Deferred breakdown response is loaded. | It matches the user's currency, formatting, and coverage contract. |
| API/MCP reads a pending conversion. | Preserve null and expose the original amount and both currencies clearly. |
| Current EUR 100.00 balance, historical rate 7.40, current rate 7.50. | Current DKK valuation is 750.00. Historical reporting amounts do not change. |
| Current balance or valuation rate is missing for an active account. | Show missing-account coverage and a labelled known subtotal. |
| Page loads during provider outage. | Render stored results without any provider HTTP requests. |

## 10. Verification and delivery

Run the narrowest affected tests first. Examples:

```sh
php artisan test --compact tests/Feature/OnboardingTest.php
php artisan test --compact tests/Feature/FinanceImportTest.php
php artisan test --compact tests/Feature/BalanceChainInspectorTest.php
php artisan test --compact tests/Feature/AccountBalanceRecalculatorTest.php
php artisan test --compact tests/Feature/BankConnectionTest.php
php artisan test --compact tests/Feature/DashboardFinanceTest.php
```

Run new conversion tests by their actual file paths. Use the project's isolated test database. Never reset development or production data to make tests pass.

After PHP changes, run:

```sh
vendor/bin/pint --dirty --format agent
```

Run the installed frontend checks after frontend changes. Verify English labels, Danish number formatting, timezone display, and pending amounts through the local application. Use synthetic data and clean it up.

After focused checks pass, ask the owner to run:

```sh
php artisan test --compact
```

Before the clean reimport, confirm that all writers and consumers use the new contracts. Do not run old workers with the new amount meanings.

## 11. Completion criteria

- Every user has independent region preferences, including users registered after initial platform setup.
- Reporting currency cannot change through ordinary settings or account-count changes.
- A valid Spiir import sets only its owner's reporting currency before writing history.
- Original amounts always represent verified account movements or remain explicitly unknown.
- Reporting amounts are either valid target-currency values or null.
- Account calculations never use reporting amounts.
- FX limits stop network requests across users and workers, not just within one posting loop.
- Cached rates still work during cooldown.
- Synchronous and asynchronous queue modes both complete bounded work safely.
- Partial reporting is visible and never presented as complete.
- Frankfurter works without keys or setup. Requests contain only currency codes and dates, never user or transaction details.
- No legacy repair system, currency-switching system, or duplicate monetary schema has been added.

## 12. Explicit exclusions

- Automatic wiping of existing data.
- Repair or migration of the current mixed-currency dataset.
- Changing reporting currency after monetary history exists.
- Translating the interface or adding a language selector.
- A multi-provider FX framework, FX credential settings, or automatic fallback to blended or non-ECB sources.
- A full conversion audit ledger or new canonical posting amount columns.
- Automatic revaluation of historical reporting amounts.
- A general multi-user Spiir import redesign unless separately required.
- A double-entry ledger, foreign-exchange trading, or account redenomination.
