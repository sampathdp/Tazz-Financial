# Database Security Report

Date 2026-11-01. Based on `backend/config/database.php`, `database/schema.sql`, code search. The live database was **not** accessed.

## Connection and credentials
- MySQL via env (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`); no credentials in source. Real `.env` is git-ignored and absent from history. Deployed env file lives outside the web root (`<account home>/envs/tazz-investment.env`, per `public/opcache-reset.php` and `bootstrap/app.php`), which is good practice.
- TLS to the database: `config/database.php` supports `MYSQL_ATTR_SSL_CA` but it is optional and unset in the examples. On shared hosting with `DB_HOST=127.0.0.1` (production example) the connection is local; acceptable. **Needs verification** that the DB is not reachable from the internet (hosting panel "Remote MySQL" allowlist).
- **Least privilege: not verifiable from the repo.** The app runs migrations and `ALTER TABLE` through artisan commands (`VerifySchema`, `SchemaBaseline`), so the live account may hold DDL rights. Recommended: run the web app as a DML-only user (SELECT/INSERT/UPDATE/DELETE/LOCK TABLES) and keep a separate migration user used only during deploy. Requires coordination with the deploy process; not changed.

## Injection
- Eloquent / query builder with bindings is used throughout. 37 files use `lockForUpdate`, 243 `DB::transaction`/lock call sites.
- Raw SQL reviewed (all hits of `DB::raw|selectRaw|whereRaw|orderByRaw|DB::statement|DB::select`): no user input is concatenated. `CollectionReportController.php:308-326` builds `$groupExpr` from a fixed `match`; `LoanController.php:466-508` casts to float before string concatenation; `whereRaw` calls use `?` bindings; schema tools interpolate identifiers read from `information_schema`. **Confirmed no SQLi found in these sites**; `orderBy` columns taken from request parameters were not exhaustively traced (**Needs verification**: grep `orderBy(\$request`).

## Integrity
- `database/schema.sql` declares 205 FOREIGN KEY / CONSTRAINT clauses; `VerifySchema` forces InnoDB and utf8mb4 and adds CHECK constraints. Migration folder has 218 files plus ad-hoc SQL scripts in `database/sql` and repo root (`LIVE_*.sql`). Those scripts are hand-applied to live; their order and idempotence are a change-control risk, not a direct vulnerability.
- Hard-delete paths with `FOREIGN_KEY_CHECKS=0`: `LoanController::purge` (F-05). Console commands that disable FK checks (`ImportDump`, `CloneDatabaseForTests`, `VerifySchema`) are CLI-only.

## Data exposure
- Dumps in git: F-01 (critical). Backup directory `backend/storage/backups/` is tracked. `storage/app/.gitignore` is `*` (uploads not tracked) but `storage/backups` is not covered.
- `personal_access_tokens` stores SHA-256 token hashes (Sanctum default); with the dump in git, tokens issued before the dump should be treated as exposed until revoked.
- Encryption at rest of PII columns (NIC, phone, bank details): not implemented in application code (no `encrypted` casts found by name; **Needs verification**). Rely on host disk encryption; document as accepted risk or add cast-level encryption later (requires data migration and key management; not recommended without approval).

## Backups
- Stored in-repo and in the web-adjacent `storage/backups`. Moving them outside the repository and web root, encrypting them, and setting retention is required. Live backup procedure on the host is unknown.

## Recommendations (priority order)
1. Remove dumps from git and rotate affected credentials (F-01).
2. Confirm DB is not remotely reachable; create DML-only runtime user.
3. Move backups out of repo; encrypt; restrict permissions.
4. Review `orderBy` with request-controlled column names for allowlists.
5. Decide on encryption for highest-risk PII fields.
