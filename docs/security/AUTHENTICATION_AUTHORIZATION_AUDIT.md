# Authentication and Authorization Audit

Date 2026-11-01. Static review; no live login or token testing performed.

## Authentication

| Area | Finding | Status |
|---|---|---|
| Mechanism | Laravel Sanctum personal access tokens, `Authorization: Bearer`. Token created per login (`createToken('mms-web')`); no session cookie auth for the SPA | Confirmed |
| Password hashing | `Hash::make` / `Hash::check`, driver `bcrypt` (`config/hashing.php`). Stored hashes in dumps start `$2y$10$` (cost 10). Argon2id preferred; bcrypt acceptable | Confirmed |
| Password policy | `App\Rules\StrongPassword` on user create/update (`UserController.php:128`), receives email and name to block reuse. Rule body **not read** | Needs verification |
| Login validation | `email|max:190`, `password|min:6|max:200` | Confirmed |
| Brute force | 5 failures / 60 s per email+IP (`RateLimiter`), cleared on success; HTTP throttle 30/min per IP | Confirmed; weak against distributed attempts (F-06) |
| Enumeration | Same message for unknown user and wrong password; inactive accounts get a distinct message only after a correct password | Good |
| Session expiry | `SANCTUM_EXPIRATION_MINUTES` default 720 (12 h), set to 720 in production example. No idle timeout | Confirmed |
| Token revocation | Logout deletes current token. Changing a user's password, status, role or branch revokes tokens (`UserController.php:99-101`) | Confirmed |
| Session fixation | Not applicable: new random token per login, no pre-auth session | Confirmed |
| Token storage (client) | `localStorage` key `mms-token` (F-04) | Confirmed |
| Password reset | No route exists; admins set passwords | Confirmed (no attack surface) |
| Registration | No public route; accounts created by users with `User Management,Create` or `Staff,Edit` (`createLogin`) | Confirmed |
| MFA | None | Confirmed |
| Default credentials | Not searched in seeders beyond the file listing | **Needs verification**: grep `database/seeders` and SQL dumps for known default accounts, and check that no `admin@…` demo account survives on live |
| Audit | `security` log channel: login_failed, login_throttled, login_inactive, login_success, logout, permission_denied, admin_denied | Confirmed. Logs record email and IP; no passwords |

## Authorization model

- Roles table with `slug`, `can_view_all_branches`; per-role permission grid (`Module` × `Action`: View/Create/Edit/Delete) plus per-user overrides (`UserPermission` routes). Evaluated by `App\Support\Permissions::userCan` via `CheckPermission` middleware. `EnsureAdmin` uses `$user->isAdmin()`.
- Approval authority is a separate registry (`approval_types`, `ApprovalType::isAuthorized`) with maker-checker rules (self-approval blocked, verified in `FinanceRequestService::approve`).
- Role and role-permission management: role CRUD is `admin`; `PUT roles/{role}/permissions` is `perm:Role Management,Edit`. **Potential privilege escalation:** a non-admin role granted `Role Management,Edit` can grant itself any permission. Needs verification of whether `PermissionController::updateRole` refuses to grant permissions the caller lacks or to edit the caller's own role. Same question for `users/{user}` update (can a `User Management,Edit` user assign the admin role to themselves?) and per-user permission overrides.
- Role matrix requested (Administrator, Manager, Cashier, Employee, Read-only): role slugs are data-driven, not code. The actual matrix lives in the `roles` / permissions tables; it was **not exported** in this pass. Required action: dump the matrix from the live database (read-only query) and review against least privilege.

## Verified positives
- All non-public routes sit under `auth:sanctum` (route list shows 4 unauthenticated routes only).
- Authorization is enforced in route middleware (server side); frontend route guards are not relied upon in the API.
- Destructive/structural actions (roles, approval types, waivers, commission payout, purge, wipe) are `admin`.

## Required follow-up tests (not yet written)
1. User without a grant gets 403 on one write route per module (generate from the route table).
2. Non-admin with `Role Management,Edit` cannot raise own privileges.
3. User A (branch 1) cannot read or modify branch 2 loan / FD / customer by id (extends `BranchIsolationExtendedTest`).
4. Disabled user's existing token returns 401/403 immediately.
5. Same email from rotating IPs is locked after N failures (after F-06 is implemented).
