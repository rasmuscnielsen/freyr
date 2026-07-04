# Security Policy

Freyr handles bank data, so security reports are taken seriously.

## Reporting a vulnerability

Please report vulnerabilities privately via
[GitHub's private vulnerability reporting](https://github.com/rasmuscnielsen/freyr/security/advisories/new)
on this repository. Do **not** open a public issue for security problems.

You can expect an initial response within a week. Please include reproduction
steps and the affected version or commit.

## Supported versions

Freyr is pre-1.0; only the latest release receives security fixes.

## Notes for self-hosters

- Set `ADMIN_EMAILS` so only you can access the Horizon dashboard.
- Set `REGISTRATION_ENABLED=false` if your instance should be private.
- Keep your Enable Banking private key (`storage/*.pem`) out of version
  control and readable only by the application user.
