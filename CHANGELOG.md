# Changelog

All notable changes to Freyr are documented in this file. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Removed

- The `php artisan app:spiir:import` command. Spiir exports are imported
  through the browser (setup wizard or **Platform → Settings**), which only
  runs while no postings exist.

## [0.1.0] - 2026-07-04

First public release.

### Added

- Bank connections via Enable Banking (PSD2 open banking) with authorization
  renewal and per-account linking.
- Automatic transaction import with deduplication, scheduled daily syncs, and
  manual sync from the UI.
- Sync audit trail: every sync run records new / updated / duplicate posting
  counts, time range, and timing, plus balance reconciliation warnings.
- Dashboard with monthly income/expense flow, per-category breakdown, and
  period selection.
- Manual categorization with a category tree (income / expense / saving /
  transfer types) and a seeded default tree for new installations.
- Spiir export importer (`php artisan app:spiir:import <path>`) as a migration
  path for ex-Spiir users; it replaces the seeded default categories with the
  export's tree (after a confirmation prompt).
- `php artisan app:install` (migrations + default categories; `--demo` resets
  a local database with sample data) and `php artisan app:deployed`
  (migrations + cache optimization and Horizon restart in production).
- Linking a bank connection to an account that already has postings sets an
  import cutoff (`ignore_postings_before`) so the first Enable Banking sync
  does not duplicate imported history.
- Read-only REST API secured by Sanctum personal access tokens.
- Auth via Fortify: registration (can be disabled with
  `REGISTRATION_ENABLED=false`), email verification, password reset, 2FA, and
  passkeys.
- Horizon queue dashboard gated by `ADMIN_EMAILS`.
- Dark mode.

### Known limitations

- Categories are shared per installation and there is no management UI yet;
  the seeded defaults or a Spiir import define the tree.
- The dashboard aggregates across accounts without currency conversion; totals
  are displayed in your most common account currency.
