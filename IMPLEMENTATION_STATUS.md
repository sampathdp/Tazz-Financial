# Implementation Status (2026-10-31)

Nothing is committed. Verified: `php -l` on every edited file; full PHPUnit run on `tazz_test` = 302 tests, 11 failures, all caused by schema drift in the cloned dev database (`tazz`: e.g. `fd_renewals.interest_amount` missing, tables without AUTO_INCREMENT `id`) - the same 11 failed before the void/lock changes. New file `tests/Feature/FinancialHistoryVoidTest.php` (5 tests) passes.

## Deploy order on the live server
1. Backup. 2. `php artisan down`. 3. Run `LIVE_SERVER_UPDATE.sql` (or `php artisan migrate --force`). 4. Deploy code. 5. `php artisan up`. 6. Run STEP 0 diagnostics output past me if anything is returned - especially 0.3/0.4: the dev database was found to be behind its migrations, and live may be too.

## Changes by finding
| Finding | Change |
|---|---|
| F-01 | InnoDB config + conversion migration + SQL step 1 (already in tree) |
| F-02a-d | FD: `lockFd()` in renew/auto-renew (`performRenewal`), withdraw, premature, part payment (stale-principal check), void withdrawal, void part payment (must be Active + latest) |
| F-02e,f | Pawning `lockTicket()` in renew/redeem/void; redeem re-checks articles pledged; void re-checks latest payment |
| F-02g,h | Savings withdraw re-check under lock; interest post/void and collection post lock the run/sheet; cancel is a conditional update |
| F-02i | Bank clear: row lock + status re-check + idempotency key |
| F-02j,k | `ApprovalRequest::lockPending()` in loan approve/reject/cancel/write-off and payment settlement/void/allocation approvals; cancel re-checks payments under loan lock; cheque realize/bounce lock; speed-draft payment void lock; pawning auction lot lock |
| F-02l | Finance reversal approval is one transaction; `reverse()` re-checks under lock |
| F-03 | Idempotency middleware added to 30 more routes (71 total); abandoned in-flight claims expire after 3 min |
| F-04 | Voided rows (`voided_at/by/reason`) instead of delete: FD withdrawal, FD part payment, pawning renewal/redemption, borrowing transaction, loan waiver; global scope hides them; "one live row" keys via generated columns |
| F-05 | `LoanController::purge` always refused in production (flag no longer overrides). System wipe unchanged (password + phrase + `DB_CLEAN_ENABLED`) |
| F-06 | Waiver stores `journal_entry_id` + `posted_amount`; removal reverses its own entry and voids the row |
| F-07 | Charges: `paid_amount` input ignored, none on Closed/Cancelled/Rejected loans, edits/deletes under loan lock |
| F-08,09 | User+staff creation, cash/bank account + chart-account updates in one transaction |
| F-10 | Undeposited-funds deposit locks account 1030 |
| F-13 | Scheduled commands `withoutOverlapping()` |
| F-14 | `audit_events` table written by `AuditWrites` (secrets stripped) |

## Not done / needs a decision
- F-12 journal-number sequence serialisation: unchanged (changing numbering is a business decision).
- F-15 float arithmetic, F-18 time zone: need your rounding/time-zone rules.
- Two-user race tests (true concurrency) need a multi-process harness; the new tests cover the guards single-process.
- SCAN-only modules from the audit (petty cash, expenses, manual journals, fixed assets, commissions, floats) were not line-audited; no change made there.
- Frontend: voided rows now return 404 on a second void; no UI change was needed but screens that list withdrawals/part payments only ever saw live rows.

## Phase 3 addendum - second pass over the modules that were only scanned (no schema change, no new live SQL)
Same pattern everywhere: lock the row inside the transaction, re-check the status/balance the earlier read relied on.
| Module | Change |
|---|---|
| Petty cash | Voucher spend re-checks balance/active on the locked fund (float could go negative); voucher void and top-up void re-check "already voided" under lock; top-up re-checks the float limit; fund creation number drawn inside a transaction |
| Commissions | Sales-executive and FD commission pay/void lock the commission row (double pay would also have started two next cycles) |
| Expenses | approve/reject lock the approval request and the expense; expense number drawn inside the transaction |
| Manual journals | approve locks request + entry (journal posted once); reject can no longer overwrite an approved, already-posted entry |
| Fixed assets | dispose and delete lock the asset and re-check status/depreciation |
| Finance requests | `lockStatus()` in submit, approve, reject, cancel, execute, transfer, release, receive, recall (cancel racing execute) |
| Speed drafts | approve/reject lock request + draft; disburse and renew re-check status under lock |
| Loans | disburse re-checks Approved under lock; reschedule refuses if a payment landed after it was quoted; skip-installment locks loan and installment |
| Branch funding | reject is a conditional claim (cannot overwrite an approved, paid-out agreement) |

Test run after this phase: 302 tests, the same 11 schema-drift failures as the baseline, no new failures.
Still not line-audited: cash floats (`CashFloatService`), cash/bank reconciliation controllers, depreciation run internals, PDC controller, report controllers, frontend screens.

## Phase 4 addendum - remaining scanned modules (no schema change, no live SQL)
| Module | Change |
|---|---|
| Post-dated cheques | Realize locks loan + cheque and re-checks (two clicks created two payments); bounce re-checks it is still Realized; other status changes can no longer relabel a cheque realized a moment earlier |
| Cash floats | `settle` locks the approval + settlement (cash moved once); `requestSettlement` re-checks Open |
| Depreciation run | Each month is posted under asset lock and skipped if already depreciated/disposed (two runs posted the same month twice) |
| Cash reconciliation | verify and delete are conditional claims (verified rows cannot be deleted by a racing request) |
Suite unchanged: 302 tests, same 11 schema-drift failures. Bank reconciliation reviewed: no ledger effect, left as is.
Remaining un-read: report controllers, frontend screens, LoanPaymentAllocator/interest calculator correctness.

## Phase 5 addendum - shared deadlock retry (F-12 mitigation, no schema change, no live SQL)
`App\Support\DeadlockRetryingMySqlConnection` (registered in `AppServiceProvider::register`) makes every `DB::transaction()` in the app retry up to 3 times when MySQL kills it as a deadlock victim (error 1213). Laravel retries only the outermost transaction and only for deadlock/lock errors, after a full rollback. `tests/Feature/DeadlockRetryTest.php` (3 tests): retried then commits, gives up after 3 and leaves no open transaction, non-deadlock errors run once. Suite: 305 tests, same 11 schema-drift failures.
Deploy note: closures run again on a retry, so they must not have side effects outside the database; none of the money paths do (checked: no file/HTTP/mail calls inside the transaction blocks read so far).

## Phase 6 addendum - complete audit pass: schema drift, test truth, real concurrency (read this one first)

### New findings (all verified) and what was done
| ID | Finding | Evidence | Fix |
|---|---|---|---|
| N-1 **High** | The dev database is NOT the schema the migrations define. Compared with a database built purely by running every migration: 35 CHECK constraints, 57 foreign keys, a primary key + AUTO_INCREMENT + unique code on `branch_funding_agreements`, enum values, and the 4 `fd_renewals` columns were missing; `migrations` table said they were applied (the test-DB helper `db:clone-for-tests --adopt-pending` marks migrations done without running them, which hid this). Live may be the same. | `db:schema-diff` against `tazz` (read-only) | New `php artisan db:schema-diff` (baseline file `database/schema/expected-schema.json`): reports every difference and `--print-sql` / `--fix` creates the missing columns, keys, unique indexes, FKs, CHECKs, AUTO_INCREMENT. Never drops or shrinks anything. Run on the dev copy: 291 differences, 0 failed; 12 harmless type notes remain (varchar(255) vs 191, longtext vs json). |
| N-2 **High** | `php artisan migrate` could not run on a database built from the schema dump: migration `2026_10_18_000001` did `DROP CHECK chk_fdr_new_principal` unconditionally and that check is absent there (error 3821). A failed run also leaves half-applied DDL. | reproduced on a scratch DB | Migration made conditional. Full chain from the dump now runs clean (0 pending, no errors). |
| N-3 **High** | `FdCommission` and `Staff` (NIC, phone) were not branch-scoped: any branch user could list them, and pay/void/edit another branch's FD commission by id. | `BranchIsolationExtendedTest` failure + controller read | `BelongsToBranch` added (Staff also `sharedWhenNoBranch` for head-office staff). |
| N-4 **Medium** | `data:clean` / Maintenance wipe deleted `asset_categories`, `fd_loan_policies`, `staff`, `user_achievements`, `subscriptions`, `customer_bank_details` (setup/people data) while keeping users and customers. | cleaner KEEP list + fixed-asset tests erroring after a clean | KEEP list corrected. |
| N-5 **Medium** | `db:verify-schema` expected the old unique key on `fd_withdrawals.fixed_deposit_id`, which the void-instead-of-delete migration replaced. | read | now checks `live_fd_id`. |
| N-6 Low | Approval of finance requests used a stale approval row. | read | `lockPending()` added. |
| N-7 info | Test hygiene: tests assume an empty ledger, so they must run on a cleaned copy with no period lock; one fixed-asset test failed only when run on the 31st (Carbon month overflow) - test fixed with `subMonthsNoOverflow`. | investigated | documented below |

### Test truth
- Previously reported "11 failures = drift". Now diagnosed individually: 3 = missing CHECK constraints, 3 = missing columns/AUTO_INCREMENT (N-1), 1 = branch isolation (N-3), 4 = data/date effects of running on a non-empty copy or on the 31st. After `db:schema-diff --fix` + `data:clean` + clearing the period lock on the **test copy**: **305 tests, 0 failures** (14 skipped by the suite itself).
- Rebuild recipe for the test database: `php artisan db:clone-for-tests --adopt-pending`; `DB_DATABASE=tazz_test php artisan migrate`; `DB_DATABASE=tazz_test php artisan db:schema-diff --fix`; `DB_DATABASE=tazz_test php artisan data:clean --force`; delete the `ledger_locked_through` row in `finance_settings`.
- **Real two-process concurrency tests** (`tests/Feature/ConcurrencyTest.php`, `tests/Support/concurrency_worker.php`, group `concurrency`, excluded from the normal run, run with `DB_DATABASE_TEST=<scratch>_test php vendor/bin/phpunit --group concurrency`): two separate PHP processes with their own DB connections fire the same request at the same instant with different idempotency keys. Six scenarios: FD part payment, FD renewal, FD withdrawal, savings overdraw, bank clear, petty-cash overspend. **All six fail against the original (pre-fix) controllers and all six pass now** - verified by restoring the originals temporarily.
- A bug in my own first version of the harness (workers' stderr filled a pipe and hung it) was found and fixed; the harness now writes to files.

### Deploy-order changes for live
1. Backup. 2. Run `LIVE_SERVER_UPDATE.sql` or `php artisan migrate --force` (now safe on a dump-built DB). 3. **Run `php artisan db:schema-diff` on live (read-only report), send me the output, then `php artisan db:schema-diff --print-sql` to get the exact repair statements for live, review, and run them (or `--fix`).** Adding foreign keys/CHECKs fails if existing rows already violate them - the command reports each failure instead of stopping, and those rows are real data problems to clean first. 4. Deploy code.

### Still not covered by this audit (honest list)
- Frontend screens (61): only the API client was read; no per-form trace.
- Remaining read-only report controllers were checked for SQL injection (none) and payment-status filtering (consistent), not figure-by-figure.
- Business-rule questions still open: allocation order per installment vs per bucket (docblock says per bucket, code interleaves per installment), rounding mode, business time zone, journal numbering, bank receipts parked in 1030.
- Live database was never reachable from here; everything about live is inferred from dev + migrations.

## Phase 7 addendum - remaining non-decision items
- Frontend: all 40+ screens with write calls were checked for an in-flight guard (busy/saving state + disabled button). Only `FdCommissions.tsx` Void had none; fixed. `api.ts` also re-sends the identical Idempotency-Key for an identical in-flight request, so a double click is deduplicated centrally. `tsc --noEmit` and `vite build` pass.
- Error display: `getErrorText` already renders the backend's 422 messages, including the new lock/re-check messages.
- `LoanPaymentAllocator::applyAllocation` docblock corrected to match the real (tested) behaviour: per installment, oldest first, interest -> OD -> other charges -> principal. No behaviour change.
- Rounding: every money path uses PHP `round(x, 2)` (half away from zero) and the ledger uses bcmath scale 2; that is the rule in force today and it is now documented rather than changed.
- Full suite after this: 305 tests, 0 failures.

### Needs YOUR decision (changing these alters stored data or business results, so I did not)
1. Time zone: `config/app.php` is UTC; Sri Lanka is UTC+05:30, so a receipt made 00:00-05:30 local is dated the previous day. Switching to `Asia/Colombo` reinterprets existing timestamps and needs a data migration plan.
2. Journal numbering: the single company-wide `JV` sequence serialises postings (mitigated by the deadlock retry, not removed).
3. Allocation order (per installment vs per bucket across installments): code is tested and consistent; say if the business wants the other order.
4. Bank-method loan receipts park in 1030 until a manual Bank Deposit.
5. Float arithmetic in domain code: safe today because every step is rounded to 2 dp; converting to bcmath is possible but is a rewrite of the interest/allocation maths.

## Phase 8 addendum - the real live database (uploaded dump) was checked
Source: `zemimycf_tazz-investment(6).sql`, generated 2026-10-31 from **MariaDB 11.4.13** (not MySQL). Loaded into a scratch local database and checked with `LIVE_DB_CHECK.sql`.

### Findings on live (verified)
| # | Finding | Effect |
|---|---|---|
| L-1 | **`php artisan migrate` must NOT be run on live.** The `migrations` table lists 224 names; 34 migration files in the repo are not listed although their changes are already in the database (restored from a dump / patched by hand). `migrate` would re-run them and stop half way ("already exists"). | Deploy with the SQL files only; Step 3 of `LIVE_SERVER_UPDATE.sql` records them as done. |
| L-2 | 17 tables are still **MyISAM** (no transactions): asset_categories, branch_funding_agreements, branch_funding_transactions, company_profile, credit_facilities, facility_payments, fd_commissions, fd_joint_holders, fd_loan_policies, finance_transactions, fixed_asset_depreciations, fixed_asset_disposals, password_resets, pawning_article_types, pawning_auctions, pawning_auction_items, pawning_renewals. **Includes `credit_facilities`, `facility_payments`, `finance_transactions`** - the core money tables - so on live a failed save really can leave half-saved rows. | Converted by step 1b. |
| L-3 | 15 tables are **latin1** while the rest are utf8mb4 (mixed-collation joins fail). | Converted by step 1c. |
| L-4 | Structure drift identical to dev: 57 foreign keys, 35 CHECK constraints missing. Also `credit_facilities.rate_period` lacks `'daily'` (daily-rate Speed Drafts cannot be saved) and `cashbook_entries.source_type` lacks `'FdRenewal'`. `fd_renewals` lacks 4 columns (renewing an FD that pays interest fails). | `LIVE_SCHEMA_REPAIR.sql` + step 2. |
| L-5 | The import error `#1071 Specified key was too long; max key length is 1000 bytes` (on `branch_funding_agreements ... USING HASH`) is caused by this MyISAM table with a `varchar(255)` unique key; MariaDB hides it behind a hash index, MySQL cannot. | Production fix: step 1a (InnoDB + `varchar(191)` + normal unique key). Local fix: `php artisan db:import-dump`. |
| L-6 | Data checks on the live data: no orphan pending payments, no double-renewed FDs, no savings balance mismatches, no negative cash, no unbalanced journal entries, no bad journal lines. Period lock is set to 2026-10-20. | Good news: constraints can be added without cleaning data first. |

### What was built and tested (on a live-like copy, MyISAM + latin1 kept)
- `php artisan db:import-dump "<file>.sql" <local_db> --force` : loads a live dump into local MySQL (removes MariaDB `USING HASH`, MyISAM -> InnoDB, reports every error). Loaded 492 statements, 0 errors. Refuses to overwrite the configured app database unless `--force`.
- `LIVE_SERVER_UPDATE.sql` (rewritten): guard, step 1a-1c, step 2 (new columns, one-live-row keys, audit table), step 3 (record 34 migrations). Run twice: 0 errors both times.
- `LIVE_SCHEMA_REPAIR.sql` (new): 94 statements (57 FK, 35 CHECK, 2 enum widenings), each tried separately with a result grid. First run applied 94; second run 2 applied (idempotent enums) + 92 already present.
- After both files, `LIVE_DB_CHECK.sql` on the copy returns only INFO/RAN rows: **zero MISSING, zero PROBLEM**.
- The five migrations that used `DROP CHECK` now use `DROP CONSTRAINT` (works on MariaDB and MySQL 8). Full migration chain from the dump re-run: 0 pending, schema equals baseline. Full test suite: 305 tests, 0 failures.

### Live deploy order (final)
1. Full backup (`mysqldump --single-transaction`). 2. Maintenance mode. 3. `LIVE_SERVER_UPDATE.sql` (database selected in the left tree). 4. `LIVE_SCHEMA_REPAIR.sql`, read the final grid (expect `applied | 94`). 5. `LIVE_DB_CHECK.sql` (expect only INFO/RAN rows). 6. Deploy the new code. 7. Do NOT run `artisan migrate`.
