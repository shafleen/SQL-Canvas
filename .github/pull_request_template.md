## What changed

Describe the user-visible behavior and why it is needed.

## Safety and compatibility

- [ ] No credentials, production data, or private hostnames are included.
- [ ] Database mutations remain server-authorized, CSRF-protected, and explicitly confirmed where destructive.
- [ ] MySQL/MariaDB differences are handled or documented.
- [ ] Public hosting still exposes only the `public/` directory.

## Verification

- [ ] PHP lint passes.
- [ ] PHPUnit passes.
- [ ] Frontend tests pass.
- [ ] Relevant disposable-database integration tests pass.
- [ ] Documentation and feature boundaries are updated.
