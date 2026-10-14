# 01 Project understanding

- **Backend:** Laravel 8 API (PHP 8.5 locally; composer allows ^7.3|^8.0), Sanctum token auth, MySQL (InnoDB). 370 route definitions in `backend/routes/api.php`, permission middleware `perm:Module,Action`.
- **Frontend:** React 18 + TypeScript + Vite 5, react-router 7 (URL routes `/<route id>`), axios. Dev server :5173 proxies `/api` to :8000.
- **Database:** MySQL `tazz` (working), `tazz_test` (isolated clone used by PHPUnit and by this audit's UI runs). PHPUnit auto-switches to `<DB>_test` under `APP_ENV=testing`.
- **Tests:** PHPUnit 9 (`backend/tests`, 213 tests after this audit), Playwright (`e2e`, 25 tests after this audit).
- **Deploy:** GitHub Actions on push to master: frontend build + FTP, backend deploy (see `.github/workflows/main.yml`). Not touched.
- **Core domain:** lending (loans, speed drafts, pawning), fixed deposits, daily savings, borrowings, cashbook/bank/finance requests, expenses and petty cash, double-entry general ledger (`LedgerPoster`, `FinancePostingService`), approvals (maker-checker), multi-branch isolation (`BelongsToBranch`).
- **Roles (8):** Admin, Branch Manager, Staff, Cashier, Marketing Executive, Finance Officer, Finance Manager, Finance Administrator. Admin, Finance Manager and Finance Administrator see all branches.
- **Scheduled work:** `finance:check-drift` (daily), `loans:recompute-provisioning`, FD/savings jobs (see `app/Console/Kernel.php`).
- **Secrets:** `.env` files exist locally; none are copied into this audit.
- Prior audit documents exist in `docs/audit/` and were used as input, then re-verified against code.
