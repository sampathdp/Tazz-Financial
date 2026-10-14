# 08 Final regression results

Run on commit 3190ad4 plus the working-tree changes listed in `09-implementation-summary.md`, against the isolated `tazz_test` database.

| Suite | Result | Evidence |
|---|---|---|
| PHPUnit (backend) | OK, 247 tests, 1258 assertions | `evidence/regression-phpunit-output.txt` |
| Playwright (UI) | 31 passed, 0 failed (includes 1 auth setup) | `evidence/regression-playwright-output.txt` |
| Frontend `tsc -b` and `vite build` | success | `evidence/regression-frontend-build.txt` |
| `npm audit --omit=dev` | 0 vulnerabilities | run in session |

During regression one new test (cash flow cumulative balance) failed because the UI seed data lives in the same test database. The test was changed to compare against a baseline and the full suite was re-run (results above).

Not covered: see `04-test-case-matrix.md` (Not run) and `06-time-based-scenarios.md`.
