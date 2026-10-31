# Testing Strategy

Existing base (read from `backend/tests`): 59 files in `tests/Feature` using Laravel `TestCase`; ledger/idempotency/concurrency invariants in `LedgerInvariantsConcurrencyIdempotencyTest` (6 tests: overdraw refusal, replayed key, FD deposit key, bank key, disbursement listener no-op), plus module suites (Loan, Pawning, FD, Savings, Borrowing, Finance, PeriodLock, BranchIsolation). Playwright UI suite in `e2e/` (31 tests per `audit/FINAL-AUDIT-REPORT.md`). This audit did **not** run any of them.

## 1. Environment rules
- Run on MySQL InnoDB, same version as production, isolated schema (e.g. `tazz_test`, as the earlier audit used). SQLite hides lock and engine problems. `AllTablesTransactionalTest` must pass first.
- Real concurrency needs two database connections. Use PHPUnit with `pcntl_fork`/`proc_open` helper that runs two artisan test workers against the same schema, or a small PHP script driven by the test using `Http::pool`/guzzle against `php artisan serve`. On Windows (current dev machine) use `proc_open` with a barrier table: each worker waits until a row `barrier.go=1`.
- Use `DatabaseTransactions` only for single-connection tests; concurrency tests must commit and clean up (`CleanTransactionalData` or per-test truncate).

## 2. Failure injection (rollback) pattern
For every multi-step operation: take a **snapshot** (row counts and checksums of all tables in the operation's table list from `CRUD_TRANSACTION_INVENTORY.md`, plus cash/bank/savings balances and caches), inject a failure at step N, assert the HTTP response is an error and the snapshot is identical.
Injection techniques (no production code change needed for most):
1. `DB::listen` / model event: throw on the Nth `INSERT` into a named table (`Event::listen('eloquent.creating: App\Models\JournalLine', fn() => throw ...)`).
2. Make a dependency fail legitimately: deactivate the chart account the poster needs (`LedgerPoster` throws), remove the branch till (`FinancePostingException`), set `ledger_locked_through` after the entry date, drop cash below zero (`InsufficientFundsException`).
3. For true crash simulation (Test G): run the request in a child process and `kill -9`/`taskkill` after the first insert, via a test-only env flag read in a `DB::listen` hook; then verify no committed partial state.
Each test is a data-provider row (operation × failure step) so all steps are covered mechanically.

## 3. Test catalogue
Letters A-G follow your brief; H-L are added from the findings.

| ID | Scenario | Setup | Assertion |
|---|---|---|---|
| A | Create loan, force ledger/number/approval step to fail | `LoanController::store` with failure at each insert | no `credit_facilities`, details, guarantors, collateral, approval_requests, `number_sequences` increment (sequence rolled back too) |
| B | Loan repayment, fail at receipt number, allocation, approval request, `postCashReceipt`, finance transaction, cashbook mirror | `LoanPaymentController::store` | no payment, allocations, charge/schedule `paid_amount` change, balance cache, journal, till balance change |
| C | FD part payment / renew / withdraw / premature withdraw, fail after each step | FixedDepositController | FD row, interest schedule, renewals, deposits, new FD, ledger, cash all unchanged |
| D | Pawning renew / redeem / void, fail after each step | PawningController | payment, renewal/redemption, item_status, outstanding cache, due date, ledger unchanged |
| E | Same request twice | same body, (E1) same Idempotency-Key, (E2) **different** keys, (E3) no key | E1: replay with `Idempotency-Replayed`; E2/E3: exactly one financial effect for every route in the inventory; today E2 fails for F-02a/b/e/g/h/i |
| F | Two simultaneous requests on one record | barrier-synchronised workers: repayment+repayment (same loan), part payment+part payment (FD principal), redeem+redeem, withdraw+withdraw (savings, min balance), bank clear+clear, interest post+post | final cache == recomputed from rows; cash == ledger; exactly one success or both applied sequentially with correct totals |
| G | Crash / connection loss mid-transaction | child process killed or connection dropped after k statements; InnoDB only | no committed partial rows; after restart the same request with the same key succeeds once |
| H | Approval races | two approvers approve simultaneously; approver + rejecter; approver + payment (cancel vs payment, F-02j) | one terminal state; no orphan reversal; payment either rejected or loan not cancelled |
| I | Void safety | void part payment after FD closed/renewed; void twice concurrently; void then re-void | rejected with 422; one reversal entry per original |
| J | Reversal fidelity | waiver delete with two waivers; charge edit with paid amount; bounced cheque after realize | reversal entry matches the intended source; no history row deleted (after Phase 4/5) |
| K | Reconciliation | after each scenario run `finance:check-drift`, `borrowings:check-ledger`, and new cache-vs-rows checks | zero drift |
| L | Deadlock | two requests locking loan→journal sequence in opposite order | retried, both succeed or one clean 409/422; never a half state |

## 4. Regression of successful flows
Keep all 59 existing feature tests green after each phase. Add golden-path tests for every route in the inventory that lacks one (reports-only controllers excluded until audited): create/update/delete happy path asserting rows + ledger + balances. Run Playwright suite (`e2e/`) after Phases 3-7. Frontend: confirm `api.ts` still sends `Idempotency-Key` and screens disable submit while in flight (134 `disabled={… saving|busy|loading}` occurrences found; coverage per form not checked).

## 5. Pass criteria per phase
1. All new failure-injection rows pass (snapshot identical). 2. Concurrency tests pass 50 consecutive runs. 3. No new MySQL deadlock in log. 4. Drift commands report zero. 5. Existing tests green.

## 6. Not yet testable / assumptions
Production data behaviour; MySQL isolation level (assumed default REPEATABLE READ); true multi-process concurrency on Windows needs the `proc_open` harness above (to be written in Phase 2); external integrations: none found in `app/` (no payment gateway, SMS or e-mail client code was seen in the files read — to confirm in the second pass).
