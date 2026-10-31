# Security Testing Report

Date 2026-11-01. **No application tests were run and no live requests were sent in this pass.** The only executed checks are the two dependency scanners and static code searches. Nothing here claims a fix, because no fix has been applied.

## Checklist

| Area | Result | Evidence / note |
|---|---|---|
| Dependency scan: Composer | **Failed** (37 advisories) | `composer audit --locked` |
| Dependency scan: npm | **Failed** (2 high, 1 moderate) | `npm audit` |
| Secrets in current tree | **Failed** | `OPS_TOKEN` value in `.env.production.example`; dumps with credentials/PII tracked |
| Secrets in history | **Failed** for dumps; backend `.env` never added | `git log --diff-filter=A`; dump commit `fa47e62` |
| Route authentication coverage | Passed (static) | `route:list`: only 4 public routes, all intended |
| Route permission coverage | Requires manual verification | 35 auth-only routes; 19 approve/reject handlers pattern-matched, 1 fully read |
| SQL injection (static) | Passed for reviewed sites | Raw SQL sites enumerated; `orderBy(request)` not traced |
| XSS (static) | Passed with caveats | No `innerHTML`/`dangerouslySetInnerHTML`/`eval`; `document.write` print template escapes data cells |
| CSP / headers: API | Passed (static) | `SecurityHeaders` middleware; **not verified on live** |
| CSP / headers: SPA | **Failed** | No CSP in repo |
| CORS | Passed (static) | env allowlist, credentials off; production value not verified |
| CSRF | Not applicable | Bearer-token API; `web` group only serves `/` |
| Authentication tests (lockout, expiry, revocation) | Not tested | `AuthSessionProbeTest` exists; not run |
| Authorization / role tests | Not tested | needs generated matrix |
| IDOR / branch isolation | Not tested | `BranchIsolation*Test` exist; not run |
| Rate limits | Not tested | |
| File upload tests | Not tested | validation rules read only |
| Financial rollback / duplicate / concurrent | Not tested | `ConcurrencyTest`, `DeadlockRetryTest`, `AllTablesTransactionalTest` exist; not run |
| Sensitive data exposure (logs/errors) | Requires manual verification | |
| Live server exposure (`.env`, `/storage`, dumps) | Requires manual verification | no access |

## Next step to produce evidence
1. Run the existing suite against a cloned test DB (`php artisan db:clone-for-tests`, then `php artisan test`) and record the baseline before any change.
2. Add regression tests listed in `AUTHENTICATION_AUTHORIZATION_AUDIT.md` as each fix lands.
3. Live checks from outside: `curl -I` on API and SPA for headers; request `/.env`, `/storage/...`, `/api/.env`, backup paths; verify 401/403 without token.

## Update: Phase 0.4 / Phase 1 (2026-11-01)

| Change | Verification | Result |
|---|---|---|
| Dumps/binaries untracked, `OPS_TOKEN` example blanked, `.gitignore` extended | `git ls-files` | Done locally, **not pushed**; history still contains the dumps until rewritten |
| `FinanceTransactionController`: no SQL text returned (rethrow `PDOException`) | `php -l` only | Syntax OK; behaviour **not tested** |
| Login: per-email lockout (10 failures / 15 min regardless of IP) | `php -l`; new test `LoginAccountLockoutTest` | **Test written, not run** |
| `X-XSS-Protection` set to `0` | `php -l` | Syntax OK; header **not checked live** |
| `FdCommissions.tsx` escapes print headers | `npm run build` | Build passes |
| `npm audit fix` (follow-redirects) | `npm audit` | 3 → 2 findings; `vite` (high) and `esbuild` (moderate) remain, need a major upgrade |

**Test suite blocked:** the local `tazz` database has `users.id` without `AUTO_INCREMENT` (confirmed via `information_schema`), so `db:clone-for-tests` copies a schema where inserts fail with `1364 Field 'id' doesn't have a default value`. Every DB-backed test errors, so no baseline exists. `LIVE_SCHEMA_REPAIR.sql` in the repo root may address this. If the same defect exists on the live server, it is an integrity problem in itself (**requires manual verification**).
