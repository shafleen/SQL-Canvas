# Security and deployment checklist

- Serve only `public/`; verify `/src`, `/config`, `/storage`, `/tests`, and `/docs` are unreachable over HTTP.
- Put SQL Canvas behind HTTPS plus a VPN, private administration network, or strong perimeter authentication.
- Copy `config/config.example.php` to `config/config.php`; explicitly allow only required database hosts and ports. Remote destinations require verified TLS and a readable CA file.
- Keep PHP's session directory private to the service account. Credentials are session-only and are removed on disconnect/expiry.
- Leave `security.read_only` enabled for audit/browse deployments. The policy is enforced for CRUD, DDL, imports, arbitrary SQL, account changes, and process termination.
- Use a least-privilege database account. SQL Canvas never grants itself privileges and never treats UI visibility as authorization.
- Every mutation uses same-origin CSRF protection. Structured mutations quote metadata-validated identifiers, bind values, preview SQL, and require explicit target confirmation where destructive.
- Every POST outcome is appended to a protected project-local JSON-lines audit file with time, route, outcome, actor, and hashed client address. Request bodies, SQL text, passwords, row values, and CSRF tokens are never logged.
- Rows without a primary key are read-only. Updates compare the original row and primary-key identity; conflicts do not silently overwrite another writer.
- Query execution is deliberate, bounded, timed, and requires `EXECUTE` confirmation for statements outside the read allowlist. A classifier is a warning mechanism, not a general SQL safety proof.
- Imports have request/file/statement limits. Upload bytes are parsed as CSV or SQL text and are never written into the public tree or executed as PHP.
- Connection failures and database statement errors are normalized and scrubbed; stack traces, credentials, DSNs, SQLSTATE wrappers, and session IDs are not returned.
- Response headers include CSP, frame denial, MIME sniffing denial, no-referrer, no-store, and a restrictive permissions policy.

Process termination, DROP/TRUNCATE, SQL import, account removal, and privilege changes can cause permanent damage. Verify backups and target identity before enabling writes against an operational server.
