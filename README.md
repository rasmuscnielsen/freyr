# Freyr

Freyr is a self-hosted personal budgeting application. It connects to your bank,
imports your transactions automatically, and gives you a clear overview of where
your money goes.

It is an open-source project built in the spirit of [Spiir](https://spiir.dk) —
the popular Danish budgeting app that has been discontinued — for people who want
to keep an automatic, bank-connected budgeting overview while owning their own
data on their own server.

> **Status: MVP.** Freyr is functional but early. Expect rough edges, breaking
> changes, and missing polish. Contributions and feedback are welcome.

## Features

- **Bank connections** via [Enable Banking](https://enablebanking.com) (PSD2 open
  banking), so you can link real bank accounts through a licensed aggregator.
- **Automatic transaction import** with deduplication, so re-syncing the same
  period does not create duplicate postings.
- **Sync audit trail** — every sync run is recorded (new / updated / duplicate
  posting counts, time range, timing) so you can see exactly what each sync did.
- **Manual categorization** with a full category tree (categories and
  subcategories, income / expense / saving / transfer types).
- **Dashboard** with an income and expense overview.
- **Read-only REST API** protected by Laravel Sanctum personal access tokens.
- **Horizon queue dashboard** for monitoring background jobs.
- **Dark mode** (follows your system preference, with a manual toggle).

## Requirements

- **PHP 8.4**
- **PostgreSQL** (recommended). The app runs on other drivers, but the postings
  table stores an optional AI description embedding using the `pgvector`
  extension on PostgreSQL (it falls back to a plain JSON column on other
  drivers), and uses a `jsonb` column for posting metadata. PostgreSQL is the
  tested and recommended database.
- **Node.js + [pnpm](https://pnpm.io)** for the frontend build.
- **Redis** — required for [Laravel Horizon](https://laravel.com/docs/horizon),
  which drives the background queue.
- A [Composer](https://getcomposer.org) installation.

## Getting started

```bash
# 1. Install dependencies
composer install
pnpm install

# 2. Create your environment file
cp .env.example .env

# 3. Generate the application key
php artisan key:generate

# 4. Configure your database in .env (see .env.example), then run migrations.
#    Add --seed in local/dev to load demo data (see "Demo data" below).
php artisan migrate --seed

# 5. Build the frontend assets...
pnpm run build
```

For local development you can run the full stack (PHP server, queue worker, log
tailer, and Vite dev server) with a single command:

```bash
composer run dev
```

## Enable Banking configuration

Freyr uses [Enable Banking](https://enablebanking.com) to connect to banks. You
need an Enable Banking account and an application registered in their control
panel.

1. Sign up at [enablebanking.com](https://enablebanking.com) and create an
   application in their control panel.
2. Note your **Application ID** and download the **private key** (`.pem`) that
   Enable Banking issues for the application.
3. Configure the following environment variables (see `config/services.php`
   under `enable_banking`):

   | Variable                          | Description                                                                                   | Default                          |
   | --------------------------------- | --------------------------------------------------------------------------------------------- | -------------------------------- |
   | `ENABLE_BANKING_APP_ID`           | Your Enable Banking application ID.                                                           | —                                |
   | `ENABLE_BANKING_PRIVATE_KEY`      | Path to the private key `.pem` file. Defaults to `storage/{APP_ID}.pem` if left unset.        | `storage_path("{APP_ID}.pem")`   |
   | `ENABLE_BANKING_BASE_URL`         | Enable Banking API base URL.                                                                  | `https://api.enablebanking.com`  |
   | `ENABLE_BANKING_ACCESS_DAYS`      | How many days a bank authorization stays valid before it must be renewed.                     | `90`                             |
   | `ENABLE_BANKING_TRANSACTION_DAYS` | How far back (in days) to fetch transactions on a sync.                                        | `30`                             |
   | `ENABLE_BANKING_ASPSP_COUNTRY`    | Default bank country code.                                                                    | `DK`                             |
   | `ENABLE_BANKING_PSU_TYPE`         | Payment service user type.                                                                    | `personal`                       |
   | `ENABLE_BANKING_LANGUAGE`         | Language for the bank authorization flow.                                                     | `en`                             |

Once configured, connect a bank from the **Bank connections** page in the app.
The account balances and transactions are then imported by Freyr.

## Background workers

Freyr uses a queue (via Horizon) and a scheduler.

Run the queue worker with Horizon:

```bash
php artisan horizon
```

The Horizon dashboard is available at `/horizon`.

Run the scheduler:

```bash
php artisan schedule:work
```

**Bank syncs run via the scheduler.** The `app:bank-connections:sync`
command is scheduled to run daily (see `routes/console.php`), which is what keeps
your linked accounts up to date. You can also trigger a sync manually from the
app, and run the command directly:

```bash
php artisan app:bank-connections:sync
```

In production, make sure both a Horizon process and the scheduler are running
(e.g. a `cron` entry running `php artisan schedule:run` every minute, plus a
supervised `php artisan horizon` process).

## Demo data

In a local environment, seeding creates a demo user (`john@example.com`) along
with sample accounts, a bank connection, a full category tree, roughly a year of
postings, and sync-run history:

```bash
php artisan migrate --seed
# or, on an existing database:
php artisan db:seed
```

The seeder only runs in the `local` environment.

## Migrating from Spiir

If you have a Spiir data export, Freyr can import it directly — a migration path
for ex-Spiir users. Place your export directory (containing `Categories.json`,
`User.csv`, `Account.csv`, and `Posting.csv`) somewhere under the project and
run:

```bash
php artisan app:spiir:import path/to/spiir-export
```

The path defaults to `project/data`. The importer creates the user, accounts,
categories, and postings from the export.

## API

Freyr exposes a **read-only** REST API secured by Sanctum personal access tokens.

**Create a token:** in the app, go to **Settings → Security** and create a
personal access token. The token is shown once; copy it immediately. Tokens are
granted read-only (`read`) ability.

**Authenticate** by sending the token as a Bearer token:

```bash
curl https://your-freyr-host/api/postings \
  -H "Authorization: Bearer <your-token>" \
  -H "Accept: application/json"
```

**Endpoints** (all require authentication):

| Method | Endpoint                  | Description                              |
| ------ | ------------------------- | ---------------------------------------- |
| `GET`  | `/api/bank-accounts`      | List your bank accounts.                 |
| `GET`  | `/api/bank-accounts/{id}` | Show a single bank account.              |
| `GET`  | `/api/postings`           | List your postings (transactions).       |
| `GET`  | `/api/postings/{id}`      | Show a single posting.                   |
| `GET`  | `/api/categories`         | List categories and their subcategories. |
| `GET`  | `/api/categories/{id}`    | Show a single category.                  |

## Tech stack

Laravel 13 · Inertia.js v3 · React 19 · Tailwind CSS v4 · PostgreSQL · Redis ·
Laravel Horizon · Laravel Sanctum · Laravel Fortify · Enable Banking.

## License

See the repository for licensing information.
