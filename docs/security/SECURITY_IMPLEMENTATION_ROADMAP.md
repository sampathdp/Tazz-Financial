# Security Implementation Roadmap

Nothing below has been implemented. Items marked **APPROVAL** touch production data, secrets, git history, authentication compatibility or external behaviour and need an explicit go-ahead first.

## Phase 0: containment (this week)
| # | Action | Finding | Approval | Reversible |
|---|---|---|---|---|
| 0.1 | Find out whether the repo was ever pushed/shared/cloned (GitHub remote, collaborators, CI caches) | F-01 | no | n/a |
| 0.2 | On live: force password reset for all staff, `TRUNCATE personal_access_tokens` (logs everyone out) | F-01 | **APPROVAL** (logs out all users) | partly |
| 0.3 | Rotate `OPS_TOKEN` on every environment; blank the value in `.env.production.example` | F-02 | **APPROVAL** (live env change) | yes |
| 0.4 | `git rm --cached` the dumps/backups; add `*.sql` exceptions for `database/**` only, `storage/backups/`, `*.zip`, `composer.phar` to `.gitignore` | F-01, F-11 | no (history untouched) | yes |
| 0.5 | Rewrite history to purge the dumps (`git filter-repo`) and force-push; all clones must be re-cloned | F-01 | **APPROVAL** (destructive, outward-facing) | no |

## Phase 1: low-risk code and config fixes (one PR, with tests)
1. Replace `$e->getMessage()` with domain-exception handling in four controllers (F-08).
2. Per-email login limiter independent of IP (F-06).
3. Named rate limiters for exports/search, uploads, payment posting, approvals (F-07).
4. Drop `X-XSS-Protection`; add `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` (F-14).
5. Escape `head` cells in `FdCommissions.tsx` print template (F-04 hardening).
6. Add PII keys (`nic`, `phone`, account numbers) to the `AuditWrites` redaction list after confirming audit consumers (F-financial C).
7. `npm audit fix` for follow-redirects; vite patch upgrade; run `npm run build`.

## Phase 2: access-control verification and tests
1. Generate one 403 test per module from the route table (auth/permission matrix).
2. Prove or fix privilege-escalation paths in `PermissionController::updateRole`, `UserController::update`, user permission overrides.
3. Branch-isolation tests for every route-bound model (F-09).
4. Ownership tests on `profile/achievements/{achievement}`.
5. Dump live role/permission matrix (read-only) and review.

## Phase 3: frontend hardening
1. CSP in Report-Only on the SPA host, then enforce (F-04). Requires editing the SPA `.htaccess` on hosting: **APPROVAL**.
2. Clear draft and notice caches on logout (F-13).

## Phase 4: dependency and platform (largest risk to behaviour)
1. Stop deleting `composer.lock` in CI; add `composer audit --locked` and `npm audit --audit-level=high` gates (F-03).
2. Patch-level Composer updates within constraints; PHPUnit + staging smoke.
3. Confirm production PHP version, then upgrade PHP and Laravel 8→9→10 in separate releases. **APPROVAL** per step.
4. Remove `composer.phar`, `vendor.zip`, scratch file from git (F-11).

## Phase 5: financial controls (needs business sign-off)
1. Decide on retiring `loans/{loan}/purge` (F-05) and keeping `DB_CLEAN_ENABLED` unset in production.
2. Classify money-posting write routes; require `Idempotency-Key` on them (client change needed).
3. Confirm `LoanController::destroy` blocks loans with posted payments.
4. Confirm audit tables cannot be edited by the app DB user's normal paths; consider DML-only runtime DB user.

## Deployment checklist (when Phases 0-1 ship)
- Env: new `OPS_TOKEN`; confirm `APP_ENV=production`, `APP_DEBUG=false`, `CORS_ALLOWED_ORIGINS` = exact SPA origin, `CORS_SUPPORTS_CREDENTIALS=false`, `DB_CLEAN_ENABLED` unset, `SANCTUM_EXPIRATION_MINUTES` per policy.
- Secrets to rotate: staff passwords, API tokens, `OPS_TOKEN`, and (if the repo was exposed) DB password, `APP_KEY` consequences considered (rotating `APP_KEY` invalidates encrypted cookies/values; none used by the bearer flow, **verify** no encrypted columns first), FTP password.
- Migrations: none required by Phases 0-2.
- Rollback: each phase is a separate commit; Phase 0.5 is the only non-reversible step.

## Status update (Phase 2 done, Phase 3 prepared)
- Phase 2 items 1-4 done and tested (see SECURITY_TESTING_REPORT.md, "Phase 2 results"); item 5 needs `LIVE_ROLE_MATRIX_QUERIES.sql` run on live.
- Phase 3.1: `frontend/public/.htaccess` now carries a Content-Security-Policy-Report-Only header plus nosniff/Referrer-Policy/X-Frame-Options; the ineffective `X-Content-Type-Options` meta tag was removed from `index.html`. **Not deployed**: rebuild, upload `dist/.htaccess`, browse every screen with DevTools open, then rename the header to enforce. APPROVAL step remains with the hosting owner.
- Phase 3.2: `auth.logout()` now also clears the `mms-notices` cache (drafts were already cleared). Drafts are intentionally kept after a 401 so a mid-form session expiry loses no work.

## Status update (Phases 4 and 5)
- Phase 4.1 done in `.github/workflows/main.yml`: the backend job now runs `composer install --no-dev` from `composer.lock` (it no longer deletes the lock and re-resolves), then `composer audit --locked`; the frontend job runs `npm audit --audit-level=high`. Both audits are `continue-on-error` for now (37 composer advisories, 1 high npm advisory remain); make them blocking after the Laravel upgrade. `composer install --no-dev --dry-run` against the lock succeeds under `config.platform.php = 7.4.33`. First deploy after this change will install the lock's versions, which may differ from what production runs today: smoke-test after deploy.
- Phase 4.2 (patch-level Composer updates) and 4.3 (PHP and Laravel 8->9->10) not started: they change production behaviour and need staging plus approval per step.
- Phase 4.4: `composer.phar`, `vendor.zip` and the DB dumps are already untracked (commit 949f750). The remaining tracked `.sql` files (`LIVE_*.sql`, `backend/database/*.sql`) hold DDL/maintenance statements only; no row data or credentials found.
- Phase 5.1: `LoanController::purge` already returns 403 when `APP_ENV=production` (it does not depend on `LOAN_PURGE_ENABLED`), so the route is inert in production. Removing the route and method entirely is still recommended: business decision.
- Phase 5.3 verified by code: `LoanController::destroy` only withdraws `Pending` applications (status change, no delete), so it cannot touch a loan with posted payments.
- Phase 5.2 (require `Idempotency-Key` on money-posting writes) and 5.4 (DML-only runtime DB user, audit-table protection) need business sign-off and a client change; not started.

## Status update (approved follow-ups)
- **5.1 done**: `DELETE loans/{loan}/purge`, `LoanController::purge()`, the "Delete Completely" button, `loansApi.purge`, the `loan_purge_enabled` config key and its test are removed.
- **4.2 done**: `composer update` within the existing constraints (31 packages, e.g. guzzle, symfony 5.4.x, monolog, league/commonmark, phpunit 9.6.38). `composer audit` fell from 37 advisories / 8 packages to 12 / 3 (laravel/framework 5, dompdf 6, flysystem 1). Full suite: same failures as the pre-update baseline, none new. `composer.lock` is changed and needs a staging smoke test before deploy.
- **5.2 done, off by default**: 51 more money-posting routes (branch funding, cash floats, fixed deposits, FD loans, loan approve/close/provision/reschedule/charges/skip, pawnings, savings runs/sheets, speed-draft approve, expense approve, cheque clearing, voids and deletes of ledger rows, admin edits) now use `idempotency:required`, joining the 71 that already had it. With `REQUIRE_IDEMPOTENCY_KEY=true` a write without an `Idempotency-Key` header gets 428. The frontend already sends the header on every write. Default is `false` so nothing changes until you turn it on after a smoke test; other API clients (scripts, e2e) must send the header first.
- **5.4 prepared, not applied**: `docs/security/DB_RUNTIME_USER.md` has the grants for a DML-only runtime account plus a separate migrate account. `public/opcache-reset.php` uses `DB_MIGRATE_USERNAME`/`DB_MIGRATE_PASSWORD` when set, because migrations run through that web endpoint.
- **4.3 not done**: Laravel 8 has no fix for its 5 remaining advisories (one critical: TokenGuard bypass). Laravel 9 needs PHP 8.0+ and 10 needs PHP 8.1+, but the production PHP version is unconfirmed and the CI/composer platform is pinned to 7.4.33. Order of work once the host PHP is known: raise host PHP, then L9 (replace `fruitcake/laravel-cors` with the built-in middleware, `swiftmailer` with Symfony Mailer, `facade/ignition` with `spatie/laravel-ignition`), then L10, each as its own release with the suite and a staging smoke test.
