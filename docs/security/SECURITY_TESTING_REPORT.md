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

## Phase 2 results (access-control verification)

New tests, all in `backend/tests/Feature/`:

- `PermissionEscalationTest` (9 tests): a non-admin with Role Management,Edit or User Permission,Edit can no longer grant a permission they do not hold; admin role and admin users cannot be edited by non-admins; a user cannot move themselves to a role with more permissions; `profile/achievements/{achievement}` is owner-only; a non-approver cannot reject a pending settlement.
- `RouteAccessMatrixTest` (4 tests), generated from the route table: every API route outside the 4 public ones returns 401 without a token; every `perm:Module,Action` route refuses a user holding every permission except that one and admits a user holding only that one; every `admin` route refuses non-admins; the 35 routes gated only by login are pinned (new ones fail until reviewed).
- `BranchChildIsolationTest` (3 tests): child records without their own `branch_id` are hidden from a branch user when their parent belongs to another branch.

Defects found and fixed:

| Id | Severity | Finding | Fix |
|----|----------|---------|-----|
| P2-1 | High | Role Management,Edit / User Permission,Edit holders could grant any permission, including to their own role (privilege escalation). | `PermissionController::guardGrant`: non-admins may only switch on permissions they hold; 403 on admin role/users. |
| P2-2 | Medium | `PUT users/{user}` let a non-admin move themselves into a role holding permissions they lack. | `UserController::guardSelfRoleChange`. |
| P2-3 | High | `LoanPaymentController::rejectSettlement` skipped the approver check when a pending settlement had no open approval request, so any signed-in user could void it and reverse its ledger postings. | Approver check is now unconditional. |
| P2-4 | High | Bare-id routes `loan-diary-notes/{note}`, `speed-draft-collateral/{collateral}` (+ documents), `bank-reconciliations/{id}`, `cash-float-settlements/{id}/settle`, `borrowing-transactions/{id}/void`, `petty-cash-vouchers/{id}`, `petty-cash-replenishments/{id}` and FD interest/part-payment models had no branch check (cross-branch read/modify by id). | New `ScopedThroughParent` model trait: the child is visible only if its parent is visible to the caller's branch. |

| P2-5 | High | A user with User Management,Create/Edit/Delete (live: the `branch_manager` role, 1 active user) could reset an administrator's password, delete administrators, edit or delete users of other branches, and assign any non-admin role, including all-branches roles and roles with more permissions than their own. | `UserController::guardManageable`, `guardRoleAssignable`, `guardBranchAssignable`: a non-admin manages only ordinary users of their own branch and centers, assigns only roles whose permissions they all hold (and no all-branches role unless they have one), and places users only in their own branch. Covers `Staff#createLogin` too (it calls `UserController::store`). |

### Live role/permission matrix review (queries run on live, read-only)

- Roles: admin (4 active users), branch_manager (1), cashier (1), marketing_executive (3); staff, finance_officer, finance_manager, finance_admin have no users.
- No non-admin role holds Role Management,Edit or User Permission,Edit, so P2-1 was latent on live. Only `branch_manager` holds User Management Create/Edit (not Delete), which made P2-5 reachable.
- User 12 "test" is an active **admin with no branch**: a leftover test account with full access. Disable or delete it.
- User 2 (cashier, Poornika Hansani) has about 680 per-user override rows: effectively every permission on every module except a few (for example Active Loans,Delete is explicitly denied). A cashier with near-admin rights through overrides defeats the role model; confirm it is intended, otherwise clear the overrides.
- `approval_tiers` is empty, so every approval type uses its flat `required_approvals`. Most types need 1 approver. The two-approver types are the legacy non-system `odi_waiveoff` and `write_off`; the in-use `loan_write_off` needs only 1. Decide whether write-offs and manual journal entries should need two.
- The admin role has no `role_permissions` rows by design (implicit full access).

Test database note: the local `tazz` database is a restore without primary keys or AUTO_INCREMENT, so `db:clone-for-tests` produces an unusable `tazz_test`. `tazz_test` was rebuilt from migrations (MySQL client on PATH required for the schema dump) plus the chart-of-accounts seed migration and `db:seed`. The full suite on it has 63 failing/erroring tests unrelated to these changes (missing control accounts such as 1015/3015 and reference rows); the access-control suites above pass.
