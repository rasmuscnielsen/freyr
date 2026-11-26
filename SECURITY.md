# Security Policy

Freyr handles bank data, so security reports are taken seriously.

## Reporting a vulnerability

Please report vulnerabilities privately via
[GitHub's private vulnerability reporting](https://github.com/rasmuscnielsen/freyr/security/advisories/new)
on this repository. Do **not** open a public issue for security problems.

You can expect an initial response within a week. Please include reproduction
steps and the affected version or commit.

## Supported versions

Freyr is in beta and has no versioned releases. Security fixes land on the
`main` branch; update with the steps in the README.

## Notes for self-hosters

- Run with `APP_ENV=production` and `APP_DEBUG=false`, as in `.env.example`.
  Local mode opens the Horizon dashboard to everyone and enables a
  passwordless login shortcut; debug mode shows error details to anyone.
- Install with `composer install --no-dev`, so development tools are not on
  your server.
- Registration is off by default. Turn it on under **Settings → Platform**
  only if other people should create accounts.
- Keep `.env` and the files in `storage/` (API token keys and any Enable
  Banking private key) out of version control and readable only by the
  application user.
