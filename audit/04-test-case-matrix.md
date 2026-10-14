# 04 Test case matrix

Scope note: the application has about 50 screens and 370 routes. This audit executed the automated suites below plus the new tests added for each fixed bug. It did **not** build or execute a case for every screen. Cases not executed are listed as Not run with the reason; none is counted as passed.

## Executed through the real UI (Playwright, Chromium, desktop viewport, isolated `tazz_test`)
| ID | Scenario | Result | Evidence |
|---|---|---|---|
| AUTH-001 | Invalid credentials rejected | PASS | regression-playwright-output.txt |
| AUTH-002 | Valid login reaches Dashboard | PASS | same |
| AUTH-003 | Session persists across reload | PASS | same |
| AUTH-004 | Logout returns to login | PASS (after BUG-001 fix) | same |
| NAV-001..007 | 7 screens open without error | PASS (after BUG-001) | same |
| NAV-008 | Sidebar navigation | PASS | same |
| ADM-001 | Branch create, verify, delete | PASS (after BUG-001) | same |
| ADM-002 | Role create and permission matrix | PASS | same |
| ADM-003 | User create, verify, delete (strong-password rule) | PASS (after BUG-001) | same |
| ADM-004 | Company profile update | PASS | same |
| ADM-005 | User permission matrix loads | PASS | same |
| CUS-001 | Create customer, auto code, delete | PASS (after BUG-001) | same |
| CUS-002 | Required fields enforced | PASS (new) | same |
| CUS-003 | Occupation toggles employer/business fields | PASS (after BUG-001) | same |
| SEC-001 | Branch manager cannot read or create other-branch expense | FAIL then FIXED (BUG-002, BUG-003) | BUG-002-before/after png |
| ACC-001 | Cash Flow shows till and bank cash | FAIL then FIXED (BUG-004) | BUG-004-before-ui-failure.png, BUG-004-after-cash-flow.png |
| ACC-002 | Balance Sheet deducts provision and balances | FAIL then FIXED (BUG-005) | BUG-005-* |
| ACC-003 | P&L waterfall shows miscellaneous income | FAIL then FIXED (BUG-006) | BUG-006-* |

## Executed as backend automated tests (PHPUnit, 213 tests)
206 pre-existing (all passed at baseline) plus 7 new: `BranchIsolationExtendedTest` (4), `AccountingReportsAuditTest` (3). Output: `evidence/regression-phpunit-output.txt`.

## Not run
| Area | Reason |
|---|---|
| UI flows for loans, pawning, FD, savings, finance requests, cashbook, bank, expenses, petty cash | Not executed through the UI in this session; covered only by existing PHPUnit tests |
| Mobile and tablet layouts, keyboard navigation, double-click submit | Not executed |
| Password recovery, registration | Not applicable: the app has no such flows |
| Concurrent edits from two browser sessions | Not executed (PHPUnit has ledger concurrency invariants) |
| Time-based scenarios | See `06-time-based-scenarios.md` |
