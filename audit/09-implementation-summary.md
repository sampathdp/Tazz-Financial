# 09 Implementation summary

## Changed
Backend: `app/Models/Concerns/BelongsToBranch.php` (write isolation); `Borrowing`, `CashReconciliation`, `Expense`, `FdDeposit`, `FixedDeposit`, `PawningAuction`, `SavingsAccount` (scope added); `JournalEntryController`, `ManualJournalEntryController`, `PaymentVoucherController` (branch scope); `AccountingReportController` (cash flow child accounts, contra assets, waterfall catch-all).
Frontend: `src/api.ts`, `src/screens/Accounting.tsx` (Miscellaneous Income row).
Tests added: `backend/tests/Feature/BranchIsolationExtendedTest.php`, `AccountingReportsAuditTest.php`; `e2e/tests/10-branch-isolation.spec.ts`, `11-accounting-reports.spec.ts`, `e2e/seed/audit-ledger.php`. Repaired: e2e `helpers.ts`, `08-admin.spec.ts`, `09-customers.spec.ts`.

Nothing was committed, deployed or migrated. No schema change. The working database `tazz` was not modified. The isolated `tazz_test` received a test password for the Horana manager and seeded journal entries (descriptions starting `E2E-RPT`).

## Run commands
```
# backend tests (auto-uses tazz_test)
cd backend && php vendor/bin/phpunit --no-coverage

# app for UI tests, against the isolated DB (two terminals)
cd backend && DB_DATABASE=tazz_test LOGIN_RATE_LIMIT=100000 API_RATE_LIMIT=100000 php -S 127.0.0.1:8000 -t public server.php
cd frontend && npx vite --host 127.0.0.1

# test DB only: set the Horana manager password used by 10-branch-isolation
#   user yapa@tazz.local -> Zq7-mV3-kLp9-x   (or set E2E_MANAGER_PASSWORD)

cd e2e && npm ci && DB_DATABASE=tazz_test npx playwright test
```

Behaviour change to know about: a branch-confined user who posts a record with another branch's id now gets HTTP 403, and no longer sees other branches' deposits, borrowings, expenses, journals, vouchers, reconciliations and auctions.

## Outstanding
See the open list in `05-bug-register.md`. Recommended: the rebuild plan in the audit artifact (posting rules, branch on every ledger line, period lock).

## Second round of fixes (BUG-007 to BUG-019)
New migrations (run `php artisan migrate`; already applied to the isolated `tazz_test`, NOT to `tazz`): `2026_10_15_000001_add_is_postable_and_unique_till_codes`, `2026_10_15_000002_add_branch_id_to_money_child_tables`.
New endpoints: `GET /api/undeposited-funds`, `POST /api/undeposited-funds/deposit`. New setting key: `ledger_locked_through` (Finance Settings).
New tests: `CommissionPaymentTest`, `NoTillPostingTest`, `ChartOfAccountGuardsTest`, `PeriodLockTest`, `UndepositedFundsTest`, `BranchReportsTest`; `e2e/tests/12-ledger-controls.spec.ts`.
Behaviour changes to know about: posting to a header or inactive account now throws; entries dated on or before the lock date are refused; cash movements with no till return 422 instead of silently skipping; commission payments above the earned amount are refused; branch managers now see branch-limited accounting reports.

## Third round (BUG-020 to BUG-022)
New migrations (run on your database; applied only to `tazz_test` so far): `2026_10_15_000003_add_inter_branch_accounts` (accounts 1400 and 2400, `journal_entries.counterparty_branch_id` and `related_entry_id`), `2026_10_15_000004_add_collected_branch_id_to_facility_payments`.
New command: `php artisan accounts:apply-standard-setup` (dry run) then `php artisan accounts:apply-standard-setup --apply`. The dry run against your real `tazz` database is saved in `evidence/standard-setup-dry-run-tazz.txt`; nothing was changed there.
New screen: Accounting > Inter-Branch Balances. New field: "Collected at branch" on the loan payment form (all-branch roles).
New tests: `InterBranchAccountingTest`, `CollectedAtBranchTest`, `StandardAccountSetupCommandTest`.
Behaviour changes to know about: an entry whose lines move another branch's till or bank account is now stored as one entry per branch; company-wide reports are unchanged, a single-branch view includes its inter-branch lines.

## Fourth round (BUG-023): default accounts for every new branch
Creating a branch now also creates its main till (General purpose) and ledger account, in one transaction. The setup command (`php artisan accounts:apply-standard-setup --apply`) adds the same till to existing branches that lack one. Deleting an untouched branch removes its default till; a branch whose till has transactions cannot be deleted (deactivate it instead). No new migration. New tests: `BranchDefaultAccountsTest`, `e2e/tests/13-branch-defaults.spec.ts`.
