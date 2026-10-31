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
