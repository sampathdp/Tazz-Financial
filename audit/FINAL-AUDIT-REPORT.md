# Final audit report

## 1. Executive summary
- **Application:** ABSL Financial (Laravel 8 API + React/Vite), commit 3190ad4 with uncommitted fixes.
- **Environment:** Windows, PHP 8.5, MySQL, Chromium via Playwright, isolated database `tazz_test`. The working database was not touched.
- **Scope:** accounting reports, multi-branch isolation, the existing automated suites, and the UI paths in the e2e suite. This was **not** a full audit of every screen.
- **Result:** twenty-three defects found and fixed across four rounds (branch security, accounting reports, ledger guards, period lock, commission payment, silent skipped postings, branch reporting). The UI suite was repaired and now passes 31/31; the backend suite passes 247/247.
- **Release recommendation: FAIL** (see section 9).

## 2. Application coverage
Inspected: ledger posting code for every money flow, branch scope layer, accounting report controller, the chart of accounts and configuration in the local database.
UI routes exercised: login, dashboard, customers, administration (branches, roles, users, permissions, company), expenses (as a branch manager), cash flow, balance sheet, profit and loss.
Roles exercised in the UI: Administrator, Branch Manager (Horana).
Not exercised: loans, pawning, speed drafts, fixed deposits, savings, finance requests, cashbook, bank, petty cash, commissions, other reports, mobile layouts, accessibility.

## 3. Test statistics
| Measure | Count |
|---|---|
| Playwright UI tests executed | 31 (30 tests + 1 auth setup), 31 passed, 0 failed |
| UI tests that failed first and were then fixed or repaired | 14 harness failures (BUG-001), 4 product failures (SEC-001, ACC-001..003) |
| PHPUnit tests executed | 247 passed, 0 failed (206 existing, 41 new) |
| Time-based scenarios executed | 0 |
| Dedicated security checks executed | branch read isolation, branch write isolation, `npm audit` |
| Blocked tests | 0 |
| Not run | all areas listed in `04-test-case-matrix.md` (Not run) |
Percentages are not given for coverage because no complete test inventory of the application was built.

## 4. Bug resolution summary
Full detail in `05-bug-register.md`. BUG-001 (harness, P3), BUG-002 and BUG-003 (branch isolation, P1), BUG-004 (cash flow blank, P1), BUG-005 (balance sheet contra sign, P1), BUG-006 (P&L waterfall drops accounts, P1). Each has a failing-first test, a fix, and a passing regression test; BUG-002 to BUG-006 were also verified in the real UI with before and after screenshots in `evidence/`.

## 5. Real-world scenario results
Executed: a Horana branch manager logging in through the login form and being unable to see or create a Head Office expense; accounting reports showing seeded September 2026 postings (opening balance, loan interest, till to bank transfer, provision, pawning interest, cash overage) with figures checked by hand (cash flow closing 6,450.00; balance sheet balanced at 6,150.00 with the provision shown as -300.00).
Not executed: time-based scenarios, concurrent users, retries after timeouts.

## 6. Security and data integrity
- **Confirmed vulnerabilities (fixed):** cross-branch read and write.
- **Confirmed defects (open):** commission payment amount uncapped and outside the money writer; ledger posting silently skipped when a branch has no till; bank and cheque receipts accumulate in 1030 with no clearing step.
- **Potential risks (not tested):** CSRF/CORS, uploads, XSS, rate limiting under load.
- **Could not be completed:** production data review; period-lock and inter-branch accounting do not exist in the system.

## 7. Performance
Not measured. No claims are made.

## 8. Remaining issues
See `05-bug-register.md` (Open): bank account branch confirmation (B-10), collect-at-another-branch beyond loans, automatic funding-cost allocation, and two missing screens (clearing 1030, account mapping). Configuration items need business input: till purposes, charge and expense category account mappings.

## 9. Release recommendation: **FAIL**
Reasons: the standard-setup command has not been applied to the real database and bank-account branches are unconfirmed; collect-at-another-branch covers loans only; coverage of loans, pawning, deposits and cash modules through the UI was not performed; time-based behaviour was not tested. The evidence supports the six fixes above, not release approval of the whole application. The system is not in production, so this is a planning gate, not an incident.

## 10. Evidence
`audit/evidence/`: `BUG-002-before/after-manager-expenses.png`, `BUG-004/005/006-before-ui-failure.png` and `-after-*.png`, `regression-phpunit-output.txt`, `regression-playwright-output.txt`, `regression-frontend-build.txt`. Playwright traces are in `e2e/test-results/` (gitignored).
