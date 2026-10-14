# Full System Audit

Date: 2026-10-14. Scope: backend (Laravel API), frontend (React/TypeScript), configuration, test setup.
Companion document: `cash-flow-ledger-map.md` (every cash in/out and ledger posting).
Method: code reading plus probe tests in `backend/tests/Feature/` (`LoanLifecycleScenariosTest`, `CashLedgerAuditProbeTest`, `BranchIsolationProbeTest`, `AuthSessionProbeTest`, `HardeningTest`, `BankMethodSavingsFdPostingTest`).
Suite: 206 tests, 0 failures. Frontend: `tsc` clean, production build OK, `npm audit --omit=dev` reports 0 vulnerabilities.

For each risk the notes below say how the problem is normally avoided, then what was changed.

---

## 1. Root cause behind the "record already exists" error: database drift

The error you hit while recording a payment is a duplicate-key error. The chain of causes:

1. The working databases have **MyISAM tables** (`facility_payments`, `credit_facilities`, `company_profile`, `pawning_*`, ...), some in `latin1`. MyISAM has **no transactions**: when a save fails part-way, the payment row stays but the transaction that also advanced the number counter rolls back.
2. The next save reuses the same number (`HB-CA-26-10-00002`) and collides with the orphaned row.
3. The restored databases also **lack integrity constraints** that the migrations define (`chk_journal_lines_one_side`, `chk_ftx_amount`), and a column (`fd_withdrawals.settled_credit_facility_id`), while their `migrations` table says everything ran. So the app keeps working while quietly losing its safety net.

Normal practice: use InnoDB everywhere, run migrations on every environment, and have a check that proves the schema matches what the code expects.

Changes:
- `php artisan db:verify-schema` reports (and with `--fix` repairs) non-InnoDB tables, missing check constraints and missing columns.
- `NumberGenerator` skips numbers that already exist, so a stale counter can no longer cause this error.
- Payments now take a row lock (see F-12).

**Action for you (I was not allowed to change your working database):**
```
cd backend
php artisan db:verify-schema          # shows what is missing
php artisan db:verify-schema --fix    # converts MyISAM -> InnoDB, adds the constraints and the column
php artisan migrate                   # applies the new migrations (purpose tills, bank accounts, document-charge account)
php artisan files:privatize           # moves existing documents off the public disk
```
Run the same on the other MySQL server (the one behind phpMyAdmin). Back up first; converting tables rewrites them.

Note: 27 migration files are not recorded in the `tazz` `migrations` table although their effects are present (the database was restored from a dump). If `php artisan migrate` stops on a "column already exists" error, record those migrations as applied rather than running them (`db:clone-for-tests --adopt-pending` does this for the test copy).

---

## 2. Fixed

| ID | Risk | What was wrong | How it is normally handled | What changed |
|----|------|----------------|---------------------------|--------------|
| F-01 | High | Charge created already paid (client sent `paid_amount`). | Server derives amounts; clients never set balances. | `paid_amount` ignored on create; Document charge must be > 0. |
| F-02 | High | A 1.00 document charge unlocked disbursing 100,000. | Fee computed server-side from the contract. | Disburse checks the charge covers the fee on the disbursed amount. |
| F-03 | High | Disbursed amount unbounded; partial disbursement mismatched ledger and schedule. | Payout cannot exceed the approved amount; schedule built on what was paid out. | Amount > 0 and <= approved (loans and speed drafts); schedule uses the disbursed amount. |
| F-04/05 | Medium | First installment before disbursement; payments dated in the future or before the loan. | Date sanity rules on financial documents. | Rejected for loans and speed drafts. |
| F-06/07 | High/Med | Disbursed loan with a fee could never be cancelled; approved loan with a paid fee could be cancelled with cash kept. | Cancel is allowed unless real collections exist; money received must be voided first. | `hasCollectionHistory()` ignores the charge-only receipt; cancel blocked until the receipt is voided. |
| F-08 | High | Number counter behind its data caused duplicate-key errors. | Generators verify uniqueness. | `NumberGenerator` skips used numbers. |
| F-09 | High | Maintenance wipe missed many tables. | Clean by keep-list, so new tables are cleaned by default. | `TransactionalDataCleaner` (button and `data:clean`). |
| F-10 | High | Petty-cash top-up could overdraw a till. | One money writer enforces funds. | Top-up now goes through `FinancePostingService`: funds check, finance transaction, cashbook row, session gate. Void uses its reversal. |
| F-11 | Medium | Stale session accepted. | A session belongs to a business day. | Gate needs a session dated today. |
| F-12 | High | Two simultaneous payments on one loan/draft both allocated to the same amount due. | Lock the row, re-check inside the transaction. | Row lock plus re-check (loans, speed drafts). Not covered by an automated test (needs real concurrent requests). |
| F-13 | High | Deactivated or re-passworded user kept working for 12 h. | Revoke tokens on credential or role change. | Tokens revoked on status, password, role, branch change and delete. |
| F-14 | Medium | Local `.env` had production values and an empty `APP_KEY`. | Separate env per environment. | Local values set; production copy kept in ignored `.env.production`. |
| S1 | High | Branch users could read (and write into) other branches' cash accounts, bank accounts, cashbook, petty cash. | One global branch scope on every branch-owned model. | `BelongsToBranch` added to those four models (bank accounts with no branch stay shared). Other branch's records now 404. |
| S2 | Medium | Passwords of 6 characters accepted. | Length over composition rules (NIST 800-63B); block common passwords. | `StrongPassword`: 10+ characters, not common, not a sequence, not the email or name. Login keeps the old check so existing users can sign in. |
| S3 | Medium | Expense / manual-journal reject skipped the approver check when no pending request existed. | Authorise unconditionally. | Check always runs. |
| S4 | Medium | `loans/{id}/purge` hard-delete available everywhere. | Destructive admin tools are off in production. | Blocked in production unless `LOAN_PURGE_ENABLED=true`. |
| S5 | Medium | KYC and loan documents on the web-reachable `public` disk. | Private storage, served only through an authenticated route. | New `documents` disk and `DocStore`; reads fall back to the old location until `files:privatize` runs. |
| S6 | Medium | `xlsx` library with 3 high advisories. | Upgrade to the patched release. | Moved to SheetJS 0.20.3 (the vendor's patched build); `axios`/`form-data` patched via `npm audit fix`. |
| C1 | High | One till per branch for all cash. | A till has a purpose; modules ask for the till for their purpose. | `cash_accounts.purpose`; `branchPrimary($branch, $purpose)`. Doc charges, collections, disbursements, FD, savings and pawning each resolve their own till; a "General" till is used when none is dedicated and a dedicated till is never borrowed by another purpose. Form field added in Cashbook > accounts. |
| C2 | High | FD / Savings Bank or Cheque movements posted nothing. | Every money movement posts through the one writer. | `bank_account_id` captured on FD deposit, interest, withdrawal, part payment and savings deposit/withdraw/close. Posts to that bank account; a receipt with no account is parked in 1030; a payout with no account is refused. Pickers added to the forms. |
| C3 | Medium | Session could open with a count different from the books, unexplained. | Differences must be explained at the point of discovery. | A note is required when the count differs; the form shows the difference. |
| C4 | Medium | Cash-session requirement only checked loan collections. | Enforce in one place. | Enforced in `FinancePostingService` for collections, cash in/out, disbursements and expense payments, so every module obeys it. |
| C5 | Medium | Every charge posted to 4020. | Charge categories map to income accounts. | Migration maps Document to 4910 Documentation Income when it exists (other categories editable in Masters). |
| C6 | Medium | Voids forced negative balances. | Reversal obeys the same overdraw rule. | Void refused with an explanation until the account holds the money. |
| C7 | Medium | Manual cashbook / bank-transaction delete skipped the approval setting. | Maker-checker applies to every reversal. | Deletes refused when approval is required; use Cash Transactions > Request reversal. |
| C8 | Low | Direct loan disbursement could not choose the till. | Operator picks the paying till. | Optional till selector on the disburse form. |
| C9 | Low | Petty-cash top-ups invisible in Cash Transactions. | Same writer as every payout. | Fixed with F-10. |
| D1 | Medium | Huge amounts caused a 500; sub-cent amounts rounded silently. | Validate range and precision at the edge. | `Money` rule (max 13 digits, 2 decimals) on every amount field. |
| D2 | Medium | Tests ran on the working database; auto-committing statements and failures left rows behind. | Tests use their own database. | Under `APP_ENV=testing` the app points at `<DB>_test`; tests refuse to run elsewhere; `php artisan db:clone-for-tests` builds the copy. |
| D4 | Low | Photo and collateral writes without a transaction. | Save the new file before deleting the old; transactions for multi-row writes. | Fixed. |
| D5 | Low | Wipe skipped some upload folders. | Same helper for every disk read/delete. | Uses `DocStore`. |

---

## 3. Not changed, with reasons

| ID | Risk | Reason |
|----|------|--------|
| D3 | Low | Allocators use PHP floats rounded to 2 dp at each step. Normal practice is integer cents or bcmath. Rewriting the allocation engine is a large change with high regression risk; the existing ledger-drift check (`finance:check-drift`) is the safety net. Recommend a dedicated change with property tests. |
| S7 | Low | The permission model is coarse (module + action). Tightening it is a product decision about roles, not a code defect. |
| - | Info | Loan, speed-draft and pawning **bank receipts** still land in 1030 Undeposited Funds until a Bank Deposit moves them; this is the existing convention, now also used by FD and savings receipts with no account named. |

## 4. Checked and found sound

Login throttling and lockout; per-route permission middleware and inline approver checks with self-approval blocks; atomic, idempotent finance writes with funds checks; FD double-withdraw unique index; savings row locks; no SQL injection or unguarded mass assignment found; upload type and size validation; request-log masking; frontend type-check clean.

## 5. Not covered

Report formula correctness (trial balance, P&L, PDFs); interest calculations for FD, savings and pawning; pawning auction rules; payroll and commission payouts; role-based UI hiding; performance; backups and deployment; the production database itself.

## 6. Known test-environment notes

- `tests/Feature/BankTransactionFinancePostingTest.php` was removed from disk during this work (Windows reported the file as held open). Its updated content is saved as `BankTransactionFinancePostingTest.php.restore` next to it: rename that file back to `.php` (the only change is that the delete step now expects the approval rule).
- The suite still depends on seed data in the cloned database (chart of accounts, approval types, roles, an admin user); `db:clone-for-tests` provides it. A fully self-contained suite would need a test seeder.
