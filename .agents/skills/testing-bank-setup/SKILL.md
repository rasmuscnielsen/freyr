---
name: testing-bank-setup
description: Browser testing of bank account setup and manage flows without real Enable Banking OAuth.
---

# Local runtime

- Build frontend assets before serving Laravel; if `npm` is absent from PATH, initialize the installed Node version manager.
- The existing `.env` may target PostgreSQL despite a local SQLite file. Use the same explicit `DB_CONNECTION=sqlite` and absolute `DB_DATABASE` override for migrations, fixtures, and `php artisan serve`.
- Use `QUEUE_CONNECTION=database` without a worker when using invented provider accounts, so linking does not attempt real provider calls.
- Seed demo data if needed with `php artisan migrate --seed`. Demo login is `john@example.com` / `password`.

# Fixture strategy

- Use an active user-owned `BankConnection` and multiple `BankConnectionAccount` factory rows with status `unlinked`.
- Remote account factory rows can use `->for($connection)`; set `user_id` explicitly rather than `->for($user)` because there is no `user()` relationship on this model.
- Create a manual local account with a known posting and a closed local account. Save IDs and names for UI assertions.
- Navigate to `/bank-accounts?setup={connectionId}` to exercise the callback landing UI. This does not verify OAuth or the callback itself.
- Exercise create/link/ignore together; select the same local account from two rows to verify the duplicate option is disabled.
- Blank create names are blocked client-side. A name longer than 255 characters exercises server-side inline validation.
- In manage mode, stop syncing the local account with a known posting, cancel once, then confirm; verify the local account becomes manual and retains its posting and balance.
- Change one ignored row while leaving another empty to verify partial manage submission.
- For preserved alert UI, adjust the fixture connection's `access_valid_until` to a few days ahead and then into the past. Reload the linked account show page each time.
- Keep real OAuth, queued sync execution, and fixture-only UI checks clearly separated in reporting.

## Devin Secrets Needed

None for seeded local browser tests. Real Enable Banking testing requires separately authorized provider credentials and consent; do not treat seeded accounts as provider integration proof.
