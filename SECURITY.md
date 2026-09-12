# Security Policy
Codex is a self-hosted personal library server, designed to be run by a single administrator on their own infrastructure (a home server, a NAS, a small VPS) rather than as a multi-tenant public service.  
Its threat model reflects that: the admin account is fully trusted, and the goal is to protect the instance from external/unauthenticated attackers and from reader accounts overstepping their access, not from a malicious admin.  

## Supported Versions
Codex does not currently follow a formal release/version numbering scheme.  
Security fixes are applied to the `main` branch; if you are running a fork or an older checkout, please pull the latest `main` before assuming an issue is unpatched.

## Reporting a Vulnerability
If you find a security issue, please **do not open a public GitHub issue** for it.

Instead, use GitHub's private vulnerability reporting:
1. Go to the repository's **Security** tab.
2. Click **Report a vulnerability**.
  
This opens a private conversation with the maintainer, visible only to the two of you, so the issue can be discussed and fixed before any public disclosure.
  
Please include:
- What you found and why it's a security issue (not just "this seems wrong").
- Steps to reproduce, or a proof of concept if you have one.
- The affected file(s)/endpoint(s), if you know them.

There's no bug bounty — this is a personal project — but reports are genuinely appreciated, and you'll be credited (if you want to be) once a fix ships.

## What's already in place
For anyone evaluating whether to self-host this, here's a summary of the security-relevant design as it stands today:

**Authentication**
- Passwords hashed with bcrypt (PHP's `password_hash`/`password_verify`).
- Optional TOTP-based two-factor authentication, which an admin can make mandatory per account.
- Failed logins are rate-limited: 5 attempts, then a 15-minute lockout, keyed on the request's real client IP (`X-Forwarded-For` when behind a trusted reverse proxy, `REMOTE_ADDR` otherwise).
- "Remember me" issues a random token; only its SHA-256 hash is stored server-side, never the token itself.

**Sessions**
- Session and remember-me cookies are `HttpOnly`, `SameSite=Strict`, and `Secure` when served over HTTPS.
- Session ID is regenerated on every successful login (mitigates session fixation).

**Access control**
- Two roles: admin (full access, including user/library management) and reader (browses only the libraries explicitly granted to them).
- A reader requesting an item outside their allowed libraries gets a 404, not a 403 — the item's existence isn't revealed to accounts  without access to it.

**Data handling**
- All database queries are parameterized; the few places that build SQL with a table/column name inline only ever pull that name from a fixed internal list, never from request input.
- User-supplied text is escaped before being rendered as HTML, consistently, including in indirectly-assembled strings.
- Library and item file paths are only ever read from the database (populated by the library scanner walking the actual filesystem), never taken directly from a request parameter.

**Infrastructure**
- `src/` and `data/` live outside the web server's document root and are never served directly, even by misconfiguration.
- `display_errors` is off; errors are logged, never shown to a visitor.
- A `Content-Security-Policy` and standard hardening headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`) are set by default.  
  `'unsafe-inline'` is currently allowed for scripts and styles — the admin UI still relies on inline event handlers and inline `style=""` attributes in a number of places, and removing that would need a broader front-end refactor first.

**The .cbz editor**
- Rewriting a comic archive (reordering/deleting pages, editing metadata) always builds a brand-new archive file first, verifies it, and only then atomically replaces the original. A failure at any step leaves the original file untouched.
- This reduces the risk of a rewrite corrupting a file, but it is not a substitute for backups. **Back up your library before using this feature.** Codex does not take a backup on your behalf before an archive rewrite — that responsibility stays with the administrator, same as for any other destructive action.

**Backups**
- Scheduled backups (triggered by an external cron job against `/api/backup`) use a dedicated token, separate from the library-sync token, specifically because a backup download contains password hashes and user email addresses — a leaked sync token should never also grant that.

## Known trade-offs
- **`'unsafe-inline'` in the CSP** — see above. A meaningful chunk of inline JS/CSS would need to move to external files/classes before this could be tightened.
- **Reverse-proxy IP trust** — the login rate-limiter trusts `X-Forwarded-For` for the client IP. This is only safe if the container's port is *never* reachable except through your trusted reverse proxy.  
  If you expose the container's port directly (in addition to, or instead of, going through a proxy), a client could spoof this header to dodge rate-limiting or frame another IP — don't do that, or revert this to `REMOTE_ADDR` if you do.
