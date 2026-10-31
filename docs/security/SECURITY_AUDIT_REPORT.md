# Security Audit Report

Date: 2026-11-01. Scope: `backend/` (Laravel 8.83.29, Sanctum 2.15.1, MySQL), `frontend/` (React 18 + Vite 5), `.github/workflows/main.yml`, repository contents and git history (188 commits).

## Method and limits

This audit is **static inspection plus two dependency scanners** (`npm audit`, `composer audit --locked`). I did **not**: run the PHPUnit suite, start the application, send live requests, read the production server, or review every one of the 94 controllers line by line. Anything not directly read is labelled **Needs verification**. The application is not claimed to be secure; see "Residual risk".

Status labels: **Confirmed** (seen in code/repo), **Potential** (plausible from code, not proven), **Needs verification**.

Secret values are never reproduced here. Where a value was seen, only its location is given.

## Summary

| ID | Severity | Status | Finding |
|----|----------|--------|---------|
| F-01 | Critical | Confirmed | Production-style SQL dumps containing users, customers, API tokens and document records are tracked in git (and therefore in history) |
| F-02 | High | Confirmed | A non-empty `OPS_TOKEN` value is committed in `backend/.env.production.example` |
| F-03 | High | Confirmed | Backend framework stack is end-of-life with 37 known advisories (1 critical in `laravel/framework`); CI deploys with `--no-audit` after deleting `composer.lock` |
| F-04 | High | Confirmed | Bearer token kept in `localStorage`; the SPA ships no Content-Security-Policy |
| F-05 | Medium | Confirmed | Temporary hard-purge endpoint and bulk transaction wipe exist; purge runs with `FOREIGN_KEY_CHECKS=0` |
| F-06 | Medium | Confirmed | Login lockout is per email+IP only; no per-account lockout, no MFA |
| F-07 | Medium | Confirmed | Rate limiting is one global bucket (300/min) for all authenticated routes; no tighter limits on exports, searches, uploads, payments |
| F-08 | Medium | Confirmed | Four controllers return raw `$e->getMessage()` to the client |
| F-09 | Medium | Needs verification | Branch / record-level access: only 17 of 94 controllers reference `CurrentBranch`; the rest may rely on model scopes |
| F-10 | Medium | Needs verification | ~25 approve/reject routes have no route-level `perm:`/`admin` middleware and depend on in-controller checks |
| F-11 | Medium | Confirmed | Binary/unreviewable artifacts tracked: `composer.phar`, `vendor.zip`, `scratch_borrowing_fixup.php` |
| F-12 | Low | Confirmed | Password hashing is bcrypt (acceptable; Argon2id preferred) |
| F-13 | Low | Confirmed | Customer form drafts (PII) cached in `localStorage` |
| F-14 | Low | Confirmed | Deprecated `X-XSS-Protection` header; `X-Content-Type-Options` set as a `<meta>` tag has no effect |
| F-15 | Low | Needs verification | Uploaded files: legacy `public` disk may still hold customer documents |
| F-16 | Info | Confirmed | No password-reset or self-registration endpoint exists |
| F-17 | Info | Confirmed | Frontend dependencies: `vite` (high), `follow-redirects` (high, via axios), `esbuild` (moderate) |

## Detailed findings

### F-01 Critical, Confirmed: database dumps in git
- **Module:** repository / data protection. **Files:** `zemimycf_tazz-investment(6).sql` (≈1.2 MB, repo root), `backend/storage/backups/tazz-before-staff-2026-10-23.sql`, `backend/storage/backups/pre-till-fix-2026-10-25.sql`, `backend/tazz-investment.sql`, `LIVE_DB_CHECK.sql` (154 KB, contents not read).
- **Evidence:** `git ls-files` lists them. The root dump and the 2026-10-23 backup each contain `INSERT INTO` statements for `users`, `customers`, `personal_access_tokens` and `customer_documents`; the root dump contains nine bcrypt (`$2y$10$`) hashes. Added in commit `fa47e62`.
- **Impact:** anyone with read access to the repository (or any clone/fork/CI cache) holds customer PII, staff password hashes and possibly still-valid API token hashes. Removing the file from HEAD does not remove it from history.
- **Fix:** (1) establish whether the repo has ever been pushed or shared; (2) rotate all staff passwords and truncate `personal_access_tokens` on the live DB; (3) `git rm` the dumps and add `*.sql`/`storage/backups/` to `.gitignore`; (4) rewrite history (`git filter-repo`) and force-push only after you approve, then invalidate clones. Note `backend/database/sql/*.sql` and `schema.sql` are migration scripts and need to stay; they were not shown to contain customer rows (not exhaustively checked).
- **Test:** `git log --all -S` on identifying values returns nothing after cleanup; `git ls-files | grep -E '\.sql$'` shows only migration files.

### F-02 High, Confirmed: committed ops token
- **File:** `backend/.env.production.example:28`. It gates `backend/public/opcache-reset.php`, which compares `X-Ops-Token` with `hash_equals`.
- **Evidence:** every other secret line in that example is empty; `OPS_TOKEN` is populated (first characters masked here).
- **Impact:** if this value equals the live one, anyone who can read the repo can trigger the endpoint. Impact of the endpoint itself (opcache reset) is limited, but it signals the template was copied from a real file.
- **Fix:** blank the value in the example, rotate `OPS_TOKEN` on every environment, check history (`git log -S` for the value) for earlier copies.
- **Test:** request without header and with old token returns 403.

### F-03 High, Confirmed: end-of-life stack and unpinned deploys
- **Evidence:** `composer audit --locked` reports 37 advisories across 8 packages: `laravel/framework` (1 critical, 1 high, 2 medium, 1 low), `league/commonmark` (9 high, 3 medium), `guzzlehttp/guzzle` (1 high, 8 medium), `symfony/http-foundation` (1 high), `dompdf/dompdf`, `guzzlehttp/psr7`, `league/flysystem`, `symfony/mime`. Locked Laravel is 8.83.29 (EOL). CI (`.github/workflows/main.yml`) installs PHP 7.4 (EOL), runs `rm -f composer.lock` then `composer update --no-audit`, so production code runs versions that were never tested or scanned.
- **Impact:** known-exploitable framework flaws; deployed dependency set differs from the one CI/tests used.
- **Fix:** stop deleting the lockfile; run `composer audit` in CI and fail on high/critical; move to a supported PHP and Laravel (stepwise 8→9→10→11). Plan in the roadmap. Intermediate: `composer update` within constraints for patch-level fixes (commonmark, guzzle, http-foundation) on PHP version the server actually runs (**Needs verification: production PHP version**).
- **Risk:** framework upgrades change behaviour; requires regression runs of the 60+ feature tests and a staging deploy.

### F-04 High, Confirmed: token in localStorage, no SPA CSP
- **Files:** `frontend/src/api.ts:41,2957-2960`; `frontend/index.html`.
- **Evidence:** token key `mms-token` is written to `localStorage`; `index.html` has only `nosniff` and `referrer` meta tags. No CSP is defined in the repo for the SPA host (the SPA host `.htaccess` is not in this repo: **Needs verification**).
- **Positive:** no `dangerouslySetInnerHTML`/`innerHTML`/`eval` in `frontend/src`; the one `document.write` (`screens/FdCommissions.tsx:92`) passes cell values through `esc()` but interpolates `head` strings unescaped (they are static literals today).
- **Impact:** any XSS (including a future dependency flaw) can steal a 12-hour bearer token.
- **Fix:** add a CSP via the SPA host's `.htaccess` (`default-src 'self'; script-src 'self'; connect-src 'self' <api origin>; img-src 'self' data: blob:; frame-ancestors 'none'; object-src 'none'; base-uri 'self'`), roll out in Report-Only first because inline styles are widely used. Moving to httpOnly cookies is a larger auth change; not recommended before CSP.
- **Test:** browser console shows no CSP violations on core screens; header present in `curl -I`.

### F-05 Medium, Confirmed: destructive maintenance endpoints
- `routes/api.php:273` `DELETE loans/{loan}/purge` (admin, commented "TEMPORARY"); `LoanController.php:457` disables FK checks inside a transaction and deletes ledger rows. `routes/api.php:767` `POST system/clean-transactions` (admin) truncates all transactional tables; guarded by admin, `APP_ENV=production` block unless `DB_CLEAN_ENABLED`, password re-entry and typed phrase.
- **Impact:** a compromised or malicious admin can erase financial history, contrary to requirement B. Guards on `clean-transactions` are good.
- **Fix:** remove `purge` once the cleanup it was added for is done (needs your confirmation); keep `clean-transactions` disabled in production (verify `DB_CLEAN_ENABLED` is unset there).

### F-06 Medium, Confirmed: authentication hardening gaps
- `AuthController::login`: 5 failures per 60 s keyed on email+IP; HTTP throttle 30/min per IP. An attacker rotating IPs against one email is never locked out. No MFA. Tokens last 12 h (`SANCTUM_EXPIRATION_MINUTES=720`); user edits that change password/status/role/branch revoke tokens (`UserController.php:99`).
- **Positives:** `Hash::check`, same error for unknown user and wrong password, inactive accounts rejected, failures logged to the `security` channel, `StrongPassword` rule on create/update, tokens revoked on logout.
- **Fix:** add a per-email counter independent of IP (e.g. 10 failures/15 min), keep the message generic; evaluate MFA for admin roles.

### F-07 Medium, Confirmed: coarse rate limiting
- `config/ratelimit.php`: login 30/min, API 300/min per user/IP. No dedicated limits for customer search, report exports, uploads, payment posting, approvals.
- **Fix:** named limiters applied to those route groups. Idempotency middleware already protects replays where applied.

### F-08 Medium, Confirmed: exception text returned to clients
- `FdCommissionController.php:191`, `FinanceTransactionController.php:131`, `FixedAssetController.php:222`, `SalesExecutiveCommissionController.php:143` return `$e->getMessage()` with HTTP 422.
- **Impact:** depends on exception type; business-rule exceptions are intended, but a `QueryException` caught by the same block would leak SQL.
- **Fix:** catch domain exceptions only; log others and return a generic message. `APP_DEBUG` defaults to false in `config/app.php` and is `false` in the production example; **live value needs verification**.

### F-09 Medium, Needs verification: record-level / branch access
- 17 of 94 API controllers reference `CurrentBranch::applyScope`. Tests named `BranchIsolationExtendedTest`/`BranchIsolationProbeTest` exist. Whether model global scopes cover the remainder was not traced.
- **Action:** enumerate every route-bound model and confirm scoping; add negative tests (user in branch A requests branch B ids) for loans, FDs, customers, payments, finance transactions.

### F-10 Medium, Needs verification: routes without route-level permission
- Examples: `loans/{loan}/approve|reject` (243-244), `speed-drafts/{id}/approve|reject`, `finance-requests/{id}/approve|reject`, `expenses/{id}/approve|reject`, void/allocation request rejects, `profile/*`, `users/active`, `users/marketing-executives`, `staff/options`. Profile routes are scoped to the caller by design. Approve/reject routes likely use `ApprovalType::isAuthorized` inside the controller (`AuthController` exposes `approvalTypes`). Not each read.
- **Action:** read each handler and confirm the authorization call; add tests that a user without the approval type gets 403.

### F-11 Medium, Confirmed: tracked binaries and scratch code
- `backend/composer.phar`, `backend/vendor.zip`, `backend/scratch_borrowing_fixup.php`, `.phpunit.result.cache` in the tree. `vendor.zip` cannot be reviewed and may contain outdated or altered packages.
- **Fix:** remove from git after confirming nothing deploys from them (the workflow uses `composer`, not `vendor.zip`).

### F-12..F-17 (Low / Info)
- **F-12** `config/hashing.php` driver `bcrypt`; acceptable. Argon2id needs PHP built with it and would rehash on next login. Bcrypt cost not verified.
- **F-13** `frontend/src/lib/draft.tsx` persists new-record form values (can include NIC, phone, address) in `localStorage` with a TTL; cleared on logout via a helper at line 24 (call site not verified).
- **F-14** `SecurityHeaders` sends `X-XSS-Protection: 1; mode=block` (deprecated; set `0` or drop). Meta-tag `X-Content-Type-Options` is ignored by browsers.
- **F-15** `CompanyProfileController` uses the `public` disk for the logo (intended, public branding). `DocStore` stores customer/loan documents on a private disk and has a legacy-migration path from `public`; whether old files still sit under `storage/app/public` and `public/storage` is exposed was not checked.
- **F-16** There is no `forgot-password`/`reset-password`/`register` route; password changes are admin-initiated. Reduces attack surface; no reset-token flow to audit.
- **F-17** `npm audit`: vite (high, direct, dev-server issue only, not shipped), follow-redirects (high, fix available), esbuild (moderate, dev-only). `xlsx` is pinned to SheetJS 0.20.3 from the vendor CDN tarball (current for that package; supply-chain dependence on an external URL).

## Controls verified as present
- Sanctum bearer auth on all non-public routes (`routes/api.php:119`); public routes are `login`, `ping`, `company-profile/branding|logo`.
- Route-level `perm:Module,Action` on the large majority of CRUD routes; `admin` on roles, approval types, waivers, commissions pay, maintenance.
- `SecurityHeaders` (nosniff, frame DENY, referrer, permissions-policy, HSTS on HTTPS, no-store on `/api`), CORS allowlist from env with credentials off by default and explicit allowed headers.
- Request audit (`AuditWrites`), security log channel for login/permission denials, `FinanceAuditEvent` model, idempotency middleware on sensitive posts, deadlock-retry and concurrency tests present.
- File upload validation (`mimes`, `max:5120`) on all seven upload endpoints; uploads stored via `store()` (random names).
- No string-concatenated user input found in raw SQL (`groupExpr` in `CollectionReportController` is built from a `match`; `LoanController` raw updates cast to float; schema commands use identifiers from `information_schema`).
- `.env` ignored by `backend/.gitignore`; no `.env` with secrets in history for the backend (`git log --diff-filter=A` returned none). `sourcemap: false` in `vite.config.ts`.

## Residual risk
Unverified areas: live server configuration (PHP version, headers, `.htaccess`, `APP_DEBUG`, directory exposure of `/storage`, DB account privileges), business-logic race conditions beyond the existing tests, per-controller authorization, and dependency behaviour after upgrade. Passing automated tests would not change this.
