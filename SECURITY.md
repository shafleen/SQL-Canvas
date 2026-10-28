# Security policy

SQL Canvas is a database administration application. Please do not disclose a suspected vulnerability in a public issue before a fix is available.

## Reporting a vulnerability

Use GitHub's private vulnerability reporting feature on this repository. Include:

- the affected version or commit;
- a concise description of the impact;
- the minimum steps needed to reproduce it;
- relevant requests, responses, or server configuration with secrets removed;
- a suggested mitigation, if known.

Do not include real database credentials, session cookies, access tokens, production data, or personally identifiable information.

## Deployment responsibility

SQL Canvas should be hosted on localhost, a VPN, a private administration network, or behind strong perimeter authentication. Serve only the `public/` directory, use HTTPS, configure an explicit database destination allowlist, and use least-privilege database accounts.

Operational hardening guidance is maintained in [`docs/SECURITY.md`](docs/SECURITY.md).
