# Database Integrity Report

Evidence: `backend/database/migrations` (217 files), `backend/database/schema/mysql-schema.dump` (79 tables), model and controller code. **The live database was not reachable** (`Plugin 'mysql_native_password' is not loaded`), so engine, row counts, orphan checks and actual column types were not checked. Everything below is from files.

## 1. Engine and transaction capability
- Repo history shows tables created on MyISAM: migrations `2026_08_01_000008_convert_legacy_myisam_tables_to_innodb` (12 tables, whole Cash and Funding/FD modules and `number_sequences`) and `2026_08_27_000001_convert_remaining_myisam_tables_to_innodb`; the docblock states `lockForUpdate()` was a silent no-op and FKs were missing on those tables.
- `config/database.php` committed value was `'engine' => null` (server default). Uncommitted diff sets `'InnoDB'`.
- Untracked `2026_10_31_000001_convert_myisam_tables_to_innodb.php` converts every non-InnoDB table, and its docblock describes an orphaned `facility_payments` row without its `approval_requests` row — i.e. a partial save was already observed.
- `mysql-schema.dump` still has 16 `ENGINE=MyISAM` strings; a database built from the dump starts partly non-transactional until the conversion migrations run.
- **Action before anything else:** run `SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB'` on dev, staging and production; apply the migration; keep `AllTablesTransactionalTest`.
- Isolation level, `innodb_lock_wait_timeout`, deadlock retry: not configured in `config/database.php` (default REPEATABLE READ assumed, not confirmed). No transaction in `app/` passes a retry count.

## 2. Constraints present (from the schema dump and cited migrations)
85 foreign keys, 19 CHECK constraints (e.g. `chk_journal_lines_one_side`, `chk_fdr_new_principal`), unique keys including:
`fd_withdrawals.fixed_deposit_id`, `fd_withdrawals.voucher_no`, `fd_renewals.new_fixed_deposit_id`, `facility_schedules(credit_facility_id, installment_no)`, `finance_transactions.idempotency_key`, `finance_requests.idempotency_key`, `idempotency_keys(idempotency_key, method, path)`.

## 3. Constraint gaps (derived from the findings)
| Gap | Evidence | Consequence |
|---|---|---|
| `fd_renewals.old_fixed_deposit_id` not unique | migration `2026_08_04_000004` | One FD can be renewed twice (F-02b) |
| No unique "one live payment per approval subject" | `approval_requests` pending rows created by check-then-insert in `voidRequest`, `cancelRequest`, `reverseRequest` | Duplicate pending requests |
| Savings balance has no DB floor | `SavingsAccountService::record` has no check; column constraint not verified | Overdraft under concurrency (F-02g) |
| `bank_transactions` has no idempotency key | `BankTransactionController::clear` spec | Double clear (F-02i) |
| Surplus refund and forfeit use different ledger keys | `PawningLedgerPoster` L245 vs forfeiture | Both can post (F-02k) |
| Non-negative checks on `cash_accounts.current_balance`, `bank_accounts.current_balance`, `credit_facilities.outstanding_balance_cache`, `fixed_deposits.principal_amount` | not found in cited migrations | Enforced in PHP only |
| Workflow state columns are free strings or enums changed by later migrations (`add_*_to_source_type_enums`) | several migrations alter enums | Easy to drift; each new source type needs a migration |

## 4. Financial consistency model
Source of truth is the double-entry ledger (`journal_entries`/`journal_lines`); cash and bank balances are caches maintained under row lock by `FinancePostingService` and checked daily by `finance:check-drift` and `borrowings:check-ledger`. Other balances are caches with no scheduled reconciliation found in `Console/Commands`:
- `credit_facilities.outstanding_balance_cache` (loan, speed draft, pawning) vs `facility_schedules`/`facility_payment_allocations`/`facility_charges`.
- `fixed_deposits.principal_amount`, `lien_amount` vs deposits, part payments, liens.
- `savings_accounts.current_balance` vs last Posted `savings_transactions.running_balance`.
Each payment path writes both in one transaction when the transaction is real; the risk is concurrent writers computing the new value from a stale read (F-02a, e, g, k).

## 5. Rounding and types
Ledger code uses `bcadd/bcsub/bccomp` with scale 2 (good). Domain code mixes `(float)` and `round(…, 2)` (FD part payment, savings withdraw, pawning renew/redeem, loan allocator caps). Money validated by `App\Rules\Money`. Column precision of `decimal(15,2)` seen on `fd_renewals`; others not enumerated. Rule for rounding mode (half-up vs banker's) is not written anywhere found — CLARIFY.

## 6. Dates and time zones
`config/app.php` timezone `UTC`; business "today" computed with `now()`/`Carbon::today()` in UTC. Period lock compares `substr(entryDate,0,10) <= ledger_locked_through` (string date). CLARIFY the business timezone (Sri Lanka, UTC+05:30).

## 7. Delete / reversal classification
| Operation | Current behaviour | Class | Recommended |
|---|---|---|---|
| Loan/speed-draft payment void | status=Voided + ledger reversal | Financial reversal | keep |
| FD withdrawal void | ledger reversal + **row deleted** | Hard delete | mark voided, keep row |
| FD part-payment void | same | Hard delete | same |
| Pawning renewal/redemption void | rows deleted | Hard delete | same |
| Borrowing transaction void | row deleted | Hard delete | same |
| Loan waiver delete | row deleted, wrong entry reversed | Hard delete | void + reverse own entry |
| FD commission void | payment columns nulled | Overwrite | keep history row |
| Loan cancel (Active) | schedule + commissions deleted, ledger reversed | Restricted (approval) | keep schedule rows as cancelled |
| Loan purge, system wipe | physical delete of everything | Restricted (env flag) | remove from production builds |
| Customer delete | soft delete with FK guards | Soft delete | keep |
| Loan application withdraw | status=Rejected | Status | keep |
| Cash/Bank account delete | guarded by history; bank also deletes COA leaf | Restricted | keep |
| Master types/categories | physical delete (FK RESTRICT) | Safe physical | keep, verify RESTRICT |
| Uploaded documents | row + file removal, not atomic | Unclear | CLARIFY |

## 8. Integrity checks to run on real data (read-only SQL, to be agreed)
1. Non-InnoDB tables. 2. Orphans: `facility_payments` without `approval_requests` where type is Settlement/ManualAllocation; payments without a `finance_transactions` row where method=Cash. 3. FDs with `renewed_to_id` null but a row in `fd_renewals.old_fixed_deposit_id`, and old FDs with 2+ renewals. 4. Savings accounts where `current_balance <> last running_balance` or `< min_balance`. 5. `outstanding_balance_cache` vs recomputed per facility. 6. Bank transactions Cleared with 2+ finance transactions. 7. Duplicate `source_id` in `journal_entries` for single-shot sources. 8. Cash/bank cache vs ledger (`finance:check-drift` output).
