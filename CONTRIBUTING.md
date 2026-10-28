# Contributing

Read `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, and `docs/FEATURE_MATRIX.md` before changing code. Keep pull requests focused, preserve the public-only document-root boundary, and do not add frontend controls without the real backend, authorization, and tests required to make them truthful.

Before opening a change, run PHP lint, PHPUnit, and `npm test`. Database integration tests must use only the disposable fixture environment and must retain the explicit reset gate. Never include credentials, production dumps, personal row data, or compatibility claims that CI has not verified.

Use clear commit messages and explain the user-visible behavior, security impact, and verification performed in the pull request. New database mutations require server-side authorization, CSRF validation, validated identifiers, parameterized values where applicable, meaningful errors, and automated integration coverage.

