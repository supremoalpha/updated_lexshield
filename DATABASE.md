# Database

The app connects to MySQL/MariaDB through `config/db.php` (PDO, prepared
statements only) using credentials from `.env` (see `.env.example`).

On XAMPP, copy the project to `htdocs/lexshield` and open
`http://localhost/lexshield/` or `http://localhost/lexshield/lexshield.php`.
All in-app links are prefixed with `/lexshield` automatically (see `DEPLOY.md`).

Production uses a dedicated database and user (`updated_lexshield` by
default), not the MySQL `root` account. Create them with
`php scripts/setup_database.php` (see `DEPLOY.md`).

## Setup

```bash
cp .env.example .env   # fill in real DB credentials (and APP_KEY for production)
php scripts/migrate.php
```

`scripts/migrate.php` runs every `*.sql` file in `sql/migrations/` that
hasn't been applied yet (tracked in a `schema_migrations` table) and is
safe to re-run at any time.

As a safety net, `config/bootstrap.php` also calls `lex_core_tables_ensure()`
and the various `*_table_ensure()` functions on every request, which
re-create any of this app's tables with `CREATE TABLE IF NOT EXISTS` if
they're somehow missing. Running the migration explicitly is still the
recommended path (it's what tracks versions and keeps a clear history);
the self-heal exists purely so the app never hard-crashes with a blank
database.

## Schema layout

| Migration | Tables |
| --- | --- |
| `2026_08_29_000001_create_core_schema.sql` | `users`, `clients`, `lawyers`, `cases`, `appointments`, `lawyer_reviews`, `manual_payments`, `phishing_scans`, `quick_inquiries`, `site_settings`, `email_otps`, `password_resets`, `audit_logs`, `notifications`, `risk_assessments` |
| `2026_08_29_000002_create_messaging_casefiles_blockchain_schema.sql` | `messages`, `message_deletions`, `case_files`, `case_file_folders`, `case_file_documents`, `blockchain_ledger`, `blockchain_ledger_lock`, `data_sharing_requests`, `case_file_shares` |
| `2026_08_30_000003` … `000006` | message encryption columns, Messages PIN, `email_otps.attempts` |
| self-created at runtime | `rate_limits` (`security/rate_limiter.php`), `video_call_sessions` / `video_call_signals` (`config/video_call/helpers.php`) |

## Database security

- **SQL injection**: every single query in this codebase goes through PDO
  prepared statements with bound parameters
  (`PDO::ATTR_EMULATE_PREPARES => false` in `config/db.php`, so MySQL
  itself parses the query before any value is substituted). Nothing ever
  concatenates raw request input into SQL. Where a *dynamic identifier*
  is unavoidable (e.g. `?sort=` picking a column, `?dir=` picking
  ASC/DESC), it is validated against an explicit whitelist first via
  `lex_safe_identifier()` / `lex_safe_direction()` in
  `security/db_security.php` - parameters can only ever be used for
  *values*, never column/table names, so this whitelist step is what
  actually prevents that class of injection.
- **LIKE-wildcard injection**: search inputs used inside `LIKE '%...%'`
  are escaped with `lex_like_value()` so a search term containing `%` or
  `_` is treated literally instead of acting as a wildcard.
- **Least privilege**: use a dedicated MySQL user for the app (see
  `.env.example`) with grants limited to the one application database,
  not `root`.
- **Secrets**: DB credentials and the app's encryption key live in `.env`
  (git-ignored) or real environment variables - never hard-coded.
- **Defense in depth beyond SQL**: CSRF tokens on every state-changing
  form (`security/csrf.php`), hardened sessions (`security/session_guard.php`),
  rate limiting on auth/contact endpoints (`security/rate_limiter.php`),
  input sanitization (`security/input_sanitizer.php`), output escaping via
  `lex_e()` everywhere, and strict security headers (CSP, X-Frame-Options,
  etc.) set in `config/bootstrap.php`.
- **File uploads**: every upload is validated by real MIME sniffing
  (`finfo`/`getimagesize`), renamed to a random filename, and size-capped.
  Sensitive uploads (payment proofs, case vault documents, message
  attachments) are stored under `storage/` - outside anything the web
  server serves directly (`storage/.htaccess` denies all access on
  Apache) - and can only be retrieved through an authenticated,
  permission-checked PHP script.
- **Case file vault documents** are encrypted at rest with AES-256-GCM
  (`config/case_files/core.php`) using a per-install key
  (`APP_KEY` env var, or an auto-generated key persisted to
  `storage/app/encryption.key`, mode 0600).
- **Chat messages and chat attachments** are also encrypted at rest with
  AES-256-GCM (`config/messages/core.php`, same key). The `messages.body`
  column stores base64 ciphertext plus IV/tag columns; attachment files
  on disk are ciphertext. Older rows with empty algorithm columns stay
  readable as plaintext. Authorized sender/receiver see the decrypted
  text in the UI; a raw database dump does not.
- **Virus scan (CVD)**: every upload (avatars, payment proofs, chat
  attachments, case-file documents) is scanned with ClamAV `clamscan`
  using `storage/clamav_db/*.cvd` before it is stored. Infected files
  are rejected. See `security/virus_scan.php`.
- **Messages PIN (lawyer and admin)**: each lawyer or admin has one 4–8
  digit PIN (bcrypt hash on `users.messages_pin_hash`) that unlocks all
  of their conversations. After logout the session flag is gone. Forgot
  PIN resets it with the account password; Change PIN requires the
  current PIN then a new one. Clients never see a PIN. Wrong guesses
  are rate-limited.

See `BLOCKCHAIN.md` for the tamper-evident audit ledger used by the
lawyer-to-lawyer data sharing feature.
