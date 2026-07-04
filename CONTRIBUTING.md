# Contributing to Freyr

Thanks for your interest in contributing! Freyr is an early-stage MVP, so
expect rough edges — and please open an issue to discuss bigger changes before
building them.

## Getting set up

Follow the "Getting started" section in the [README](README.md). For local
development, `composer run dev` runs the PHP server, queue worker, log tailer,
and Vite in one command.

## Guidelines

- **Open an issue first** for new features or behavior changes, so we can
  align on the approach before you invest time.
- **Write tests.** Every change should be covered by a Pest test. Run the
  suite with `php artisan test --compact`.
- **Format your code.** Run `vendor/bin/pint --dirty` for PHP and
  `pnpm run lint && pnpm run format` for TypeScript/React before committing.
- **Type-check the frontend** with `pnpm run types:check`.
- **Keep pull requests focused** — one topic per PR.

## Security issues

Please do not open public issues for security vulnerabilities — see
[SECURITY.md](SECURITY.md).
