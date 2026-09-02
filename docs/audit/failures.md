# Ledger audit — test failures (findings)

Findings discovered while **implementing and running** the test plans in
[`test-plans/`](test-plans/). Each is a real defect confirmed by an executed test (or by a
direct DB probe), not a code-review hypothesis. Application code was **not** modified to make any
test pass — the failing test stays failing and is recorded here.

Environment: MySQL 8.4.7, database `tazz` (a persistent, already-populated dev database — **not**
migrated fresh per test; tests isolate via `DatabaseTransactions` rollback, so every balance
assertion is written as *baseline → delta*, never an absolute figure on a shared seeded account).

---

## F-1 — Reversing a journal entry does **not** net to zero in any report; it swings the account to the full opposite of the original transaction

> **STATUS: FIXED** (commit — see git log). `ChartOfAccount::balancesAsOf()` and the three
> `AccountingReportController` aggregate queries (`generalLedger`, `incomeByLoanProduct`,
> `cashFlow`) no longer filter `journal_entries.status = 'Posted'` — they count Posted **and**
> Reversed rows, so a reversed original and its mirror reversal net to zero. `INV-07` now passes.
> Historical data that already drifted still needs a one-off correction (see fix note below).

**Severity:** P0 — systemic. Affects every void / reversal ever performed in the system's history
(loan-payment void, expense void, disbursement cancel, bank-transaction delete, pawning-payment
void, commission void, petty-cash void, finance-transaction reversal, manual-JE reversal).

**Found by:** `tests/Feature/LedgerInvariantsCoreTest.php::test_inv07_reversal_is_a_contra_entry_original_lines_are_never_mutated` (plan INV-07), corroborated by a standalone probe and by `php artisan finance:check-drift` already reporting live drift.

**Code:**
- `app/Models/ChartOfAccount.php:150` — `balancesAsOf()` filters `->where('journal_entries.status', 'Posted')`.
- `app/Support/LedgerPoster.php:98` — `reverse()` does `$entry->update(['status' => 'Reversed'])` on the **original** entry, then posts a **new** `Posted` entry (`:90`) whose lines are the original's with debit/credit swapped (`:83-88`).
- Blast radius (every one filters `status='Posted'` or calls `balancesAsOf`): Trial Balance `app/Http/Controllers/Api/AccountingReportController.php:67`; P&L `:181`, `:735`; Balance Sheet `:540`; Cash Flow `:646-647`; account ledger detail `:123`, `:271`, `:620`; Ledger Drift report `:...`; `finance:check-drift` command `app/Console/Commands/CheckLedgerDrift.php` (via `balancesAsOf`).

**Repro (exact, isolated — from the standalone probe):**
```
fresh till leaf 1010-x, GL balance BEFORE           =  0.00
post  Dr 5010 4,200.00 / Cr 1010-x 4,200.00
GL balance AFTER POST (correct)                     = -4,200.00
LedgerPoster::reverse() the entry
GL balance AFTER REVERSE                            = +4,200.00     ← WRONG
```
The two journal_lines on the till leaf afterward:
```
status=Reversed   debit=0.00      credit=4,200.00     ← EXCLUDED by the status='Posted' filter
status=Posted     debit=4,200.00  credit=0.00         ← counted, stands alone
```

**Wrong ledger output:** after a post-then-reverse of a `-4,200.00` movement, every report shows
the account at **`+4,200.00`** (an `8,400.00` error vs truth: `4,200` of the original wrongly
dropped, `4,200` of the reversal wrongly left uncancelled and in the opposite direction).

**Correct ledger output:** the account returns to **`0.00`**. The reversal's mirrored lines are
designed to cancel the original's lines when **both** are summed (standard "reversing entry"
practice, and exactly what `LedgerPoster::reverse` builds). Summing all lines regardless of
`status` **does** net to zero — verified in the same probe:
```
SUM over ALL statuses:  debit 4,200.00  credit 4,200.00  → balance 0.00   ✓
```
So the reversal entries themselves are correct; the defect is purely that `balancesAsOf()` (and
the report/ledger queries) exclude the `Reversed` original while including its `Posted` reversal.
`Reversed` is an audit-trail label ("this entry was superseded, here's the correction"), not
"this entry no longer has economic effect."

**Fix direction (not applied):** `balancesAsOf()` and the report/ledger queries must count
`Reversed` entries too (drop the `status='Posted'` filter, or change it to exclude only a future
`Draft`/`Void` state that genuinely means "never happened"). The `current_balance` cash/bank cache
is unaffected (it is separate arithmetic in `FinancePostingService::applyMovement`/`reverse` and
does restore correctly) — which is why `finance:check-drift` **already flags drift after every
reversal**: cache says `0.00`, GL says `+4,200.00`.

**Money impact:** unbounded and cumulative. Every reversed transaction misstates the GL — and
therefore the Balance Sheet, P&L, Trial Balance, and every account ledger — by up to `2×` the
transaction amount, in the wrong direction. A branch that voids and re-keys a LKR 500,000 loan
disbursement (a routine correction) leaves its cash/receivable accounts `500,000`–`1,000,000` off
in the official reports until a manual journal entry is authored to paper over it. Historical
reports for any prior period containing a reversal are retroactively wrong.

**Live corroboration:** `php artisan finance:check-drift` on the `tazz` DB right now reports
2 of 4 cash accounts drifted (net `-1,000.00`) — consistent with reversals (and the other
`LedgerPoster`-direct / manual-balance-move paths flagged as F-in-progress below) having occurred.

---

## F-2 — `chk_journal_lines_one_side` CHECK constraint is absent from the live database

> **STATUS: FIXED.** Migration `2026_09_02_000001_restore_journal_lines_one_side_check`
> re-adds the constraint (verified 0 violating rows first; the migration is idempotent and a
> no-op if the constraint is already present). `LedgerPoster::post()` now also rejects any
> per-line zero / two-sided / negative amount before insert, so the guard holds even if the DB
> constraint goes missing again. `INV-06a` / `INV-06b` now pass.

**Severity:** P0 — removes the only database-level backstop against malformed GL lines.

**Found by:** `tests/Feature/LedgerInvariantsCoreTest.php::test_inv06a_journal_lines_check_constraint_rejects_zero_and_double_sided_rows` and `::test_inv06b...` (plan INV-06), plus a direct `information_schema.CHECK_CONSTRAINTS` probe.

**Code / schema:**
- Migration `database/migrations/2026_08_01_000007_create_journal_entries_and_journal_lines_tables.php:68-72` defines
  `ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_one_side CHECK ((debit=0 AND credit>0) OR (debit>0 AND credit=0))`.
- Live DB probe: `information_schema` shows **no** check constraints on `journal_lines`
  (only `finance_transactions.chk_ftx_amount` `(amount > 0)` survives). Almost certainly lost when
  the schema was rebuilt from a `mysqldump` (see the "schema.sql regenerated" history) taken before
  or without this raw `ALTER TABLE`.

**Wrong behaviour (exact repro):**
```
INSERT INTO journal_lines (journal_entry_id, account_id, entry_date, debit, credit)
VALUES (<entry>, 1, CURDATE(), 0, 0);
→ INSERTED WITHOUT ERROR   ← the constraint should reject a zero/zero line
```
A `debit=10, credit=5` line also inserts without error.

**Correct behaviour:** the insert is rejected by the DB with a check-constraint violation
(`QueryException`), as `ledger-matrix.md` §0 documents it to be.

**Secondary gap:** `LedgerPoster::post()` (`app/Support/LedgerPoster.php:33-41`) only checks that
**total** debits equal **total** credits — it does **not** validate that each individual line is
one-sided and non-zero. So even the application-level writer would happily persist a
`debit=0,credit=0` junk line inside an otherwise-balanced multi-line entry. With the DB constraint
gone, there is now **no** guard at any layer against zero-amount or double-sided journal lines
written outside `LedgerPoster` (raw inserts, the `purge()` admin tool, a future bug, direct model
`create()`).

**Money impact:** on its own, latent — nothing in the current code deliberately writes such a
line. But it means the "structurally impossible" claim behind the whole ledger design is not true
in this database: a single stray `DB::table('journal_lines')->insert(...)` (the `purge()` tool
already does raw journal-line manipulation) or a bad migration can silently create an unbalanced
or nonsensical entry that no guard will catch, corrupting the Trial Balance by an arbitrary amount.

**Fix direction (not applied):** re-add the check constraint to the live schema (and to whatever
canonical `schema.sql` the DB is rebuilt from); optionally also have `LedgerPoster::post()` reject
per-line zero/double-sided rows before insert.

---

## F-3 — Per-account opening-balance entries are posted with `source_type = NULL`, not `'OpeningBalance'`

> **STATUS: FIXED.** `OpeningBalanceLedgerPoster::post()` now passes
> `'source_type' => 'OpeningBalance'` to `LedgerPoster::post()`. `INV-05` opening-balance test
> now passes. Existing NULL-tagged rows in live data are cosmetic — re-tagging them is optional.

**Severity:** P2 — audit-trail / traceability defect; no direct money misstatement.

**Found by:** `tests/Feature/LedgerInvariantsCoreTest.php::test_inv05_manual_and_opening_balance_entries_are_exempt...` (plan INV-05).

**Code:** `app/Support/OpeningBalanceLedgerPoster.php:48-54` — the `LedgerPoster::post(...)` call
passes meta `['description' => ..., 'branch_id' => ...]` with **no `source_type` key**, so
`LedgerPoster::post` stores `source_type = NULL` (`app/Support/LedgerPoster.php:48`).

**Wrong output:** the journal entry created when a cash till or bank account is first given an
`opening_balance` (via `CashAccountController::store` / `BankAccountController::store`) has
`source_type = NULL`. Contrast the **bulk** cutover command `PostOpeningBalances.php:127`, which
*does* set `'source_type' => 'OpeningBalance'`.

**Correct output:** `source_type = 'OpeningBalance'` (the value exists in the `journal_entries`
enum precisely for this, and the class's own docblock says it posts "the one-time entry that
establishes … opening_balance").

**Money impact:** none directly. But any audit query, report, or reconciliation that isolates
opening-balance postings by `WHERE source_type = 'OpeningBalance'` (a natural thing to do when
verifying go-live balances or explaining an equity figure) will **silently miss every individual
till/bank account's opening entry**, seeing only the bulk-command entries. It also makes INV-05's
"entries without an operational source row are exactly the ManualAdjustment + OpeningBalance ones"
invariant unverifiable by `source_type` alone.

**Fix direction (not applied):** add `'source_type' => 'OpeningBalance'` to the meta array in
`OpeningBalanceLedgerPoster::post()`.

---

## F-4 — No request-level idempotency: a retried FD deposit (and, by the same mechanism, bank transaction / manual cashbook / commission payment) posts twice

**Severity:** P1 — confirms `ledger-matrix.md` Risk 6 empirically for the FD path.

**Found by:** `tests/Feature/LedgerInvariantsConcurrencyIdempotencyTest.php::test_inv11b_a_replayed_fd_deposit_should_post_once` (plan INV-11).

**Code:** `app/Support/FdLedgerPoster.php:63` — `idempotency_key => "fd-deposit-{$deposit->id}"`. The
key is derived from the `fd_deposits` row id, which is freshly created on every request
(`FdDepositController::store` `app/Http/Controllers/Api/FdDepositController.php:58` has no dedupe on
the request body). A client retry after a timeout creates a second `fd_deposits` row, so the key
differs (`fd-deposit-41` vs `fd-deposit-42`) and `FinancePostingService`'s idempotency check never
matches.

**Repro (exact):**
```
till before                              = X
FdLedgerPoster::postDepositReceived( deposit#1, amount 500,000.00, Cash )
FdLedgerPoster::postDepositReceived( deposit#2, amount 500,000.00, Cash )   ← the "retry"
finance_transactions rows (source_module=FdDeposit)  = 2      ← should be 1
till after                                            = X + 1,000,000.00   ← should be X + 500,000.00
```

**Wrong ledger output:** two `Dr 1010-x 500,000.00 / Cr 2010 500,000.00` entries; the till and the
`2010` customer-FD-payable are each overstated by `500,000.00`.

**Correct ledger output:** one entry; till and `2010` move by `500,000.00` once.

**Money impact:** every double-submitted FD deposit inflates recorded cash and the FD payable
liability by the deposit amount. Unlike loan payments (which have an `outstanding`-balance guard
that 422s a duplicate), FD deposits, `BankTransactionController::store`
(`app/Http/Controllers/Api/BankTransactionController.php:88` — `execute()` called with **no**
`idempotency_key`), `CashbookEntryController::store`, and `SalesExecutiveCommissionController::pay`
have **no** such guard, so a retry silently doubles the entry.

**Fix direction (not applied):** accept a client-supplied idempotency token on these create
endpoints and thread it into `FinancePostingService`'s `idempotency_key`, or dedupe on a natural
key (customer + amount + date + reference) within a short window.

---

## F-5 — Pawning auction shortfall is debited to the `1190` contra-asset (never funded for this ticket), so the loss never reaches the P&L

**Severity:** P1 — same family as `ledger-matrix.md` Risk 2 (loss recognition), pawning path.

**Found by:** `tests/Feature/PawningLedgerTest.php::test_pwn11_auction_shortfall_loss_is_not_recognised_in_the_pnl` (plan PWN-11).

**Code:** `app/Support/PawningLedgerPoster.php:164-165` — `if ($capitalShortfall > 0) { $lines[] = ['account_id' => ChartOfAccount::system('1190')->id, 'debit' => $capitalShortfall, ...]; }`. Posted via `LedgerPoster::post` at `:177`.

**Repro (exact):** ticket advance `100,000` (→ `Dr 1120 100,000` at disbursement, no provision ever built). Auctioned for `90,000`:
```
capitalRecovered = min(100,000, 90,000) = 90,000.00
capitalShortfall = 100,000 − 90,000       = 10,000.00
posting: Dr <till leaf> 90,000.00 / Dr 1190 10,000.00 / Cr 1120 100,000.00
```
`1190` (Credit-normal contra-asset) balance delta for this ticket = **−10,000.00**; `5300` (P&L
provision expense) delta = **0.00**.

**Wrong ledger output:** the `10,000` real economic loss reduces the `1190` provision buffer —
which was never credited for this ticket (pawning has no provision build-up, unlike loans). `1190`
goes to an abnormal debit balance, the loss is invisible on the P&L, and net assets are overstated
by the shortfall.

**Correct ledger output:** `Dr <a P&L bad-debt/loss expense> 10,000.00 / Cr 1120 …` — the loss
recognised in the income statement in the period the lot was sold.

**Money impact:** every auction sold below the outstanding capital understates period losses (and
overstates equity) by the shortfall. Across a book of defaulted tickets this is a material,
recurring understatement of losses that the P&L will never show.

**Fix direction (not applied):** debit a P&L expense account for the shortfall (add one to the
chart of accounts if none fits — `5300` is loan-specific), not `1190`.

---

## F-6 — Pawning renewal / redemption by Bank or Cheque posts nothing (confirms Risk 1)

**Severity:** P0 — `ledger-matrix.md` Risk 1, pawning path, now empirically confirmed.

**Found by:** `tests/Feature/PawningLedgerTest.php::test_pwn08_bank_method_redemption_posts_no_ledger_entry` (plan PWN-08).

**Code:** `app/Support/PawningLedgerPoster.php:80` — `postCashReceipt()` starts `if ($payment->payment_method !== 'Cash') { return; }`. `PawningController::redeem` / `::renew` accept `Bank` and `Cheque` (`app/Http/Controllers/Api/PawningController.php:309`, `:205`) and proceed to release the gold, close the ticket, and roll the due date regardless.

**Repro (exact):** advance `200,000` (→ `Dr 1120 200,000`). `POST /api/pawnings/{id}/redeem {redemption_type: Full, payment_method: Bank, paid_at: 2026-03-16}` → HTTP 200. Ticket `status=Closed`, `outstanding_balance_cache=0`, article `Released`. `journal_entries` where `source_type='PawningPayment' AND source_id=<payment>` → **0 rows**. `1120` delta → **0.00** (still carries the `200,000`).

**Wrong ledger output:** none — the GL never records the redemption. `1120` permanently overstated
by the advance of every bank/cheque-settled ticket; `4025` interest income never recognised; no
bank receipt anywhere.

**Correct ledger output:** `Dr <bank leaf> (capital + interest) / Cr 4025 <interest> / Cr 1120 <capital>`.

**Money impact:** unbounded, growing. Every pawning ticket redeemed or renewed by bank transfer
leaves its advance stranded in `1120` forever and its interest income unrecorded.

---

## F-7 — Pawning auction sale, surplus refund and forfeiture bypass FinancePostingService — the till GL leaf moves but `cash_accounts.current_balance` does not (confirms Risk 3)

**Severity:** P0 — `ledger-matrix.md` Risk 3, now empirically confirmed.

**Found by:** `tests/Feature/PawningLedgerTest.php::test_pwn11_auction_sale_does_not_move_physical_cash`, `::test_pwn12_auction_sale_surplus_gl_only_till_not_moved`, `::test_pwn13_surplus_refund_gl_only_till_not_moved` (plan PWN-11/12/13).

**Code:** `app/Support/PawningLedgerPoster.php:177` (`postAuctionSale`), `:202` (`postSurplusRefund`),
`:226` (`postSurplusForfeiture`) — all call `LedgerPoster::post` directly, never
`FinancePostingService::execute`, so nothing touches `cash_accounts.current_balance` and no
`cashbook_entries` mirror row is written.

**Repro (exact):** till `current_balance` before an auction sale = `X`. Sale for hammer `90,000`
posts `Dr <till leaf> 90,000.00 …` in the GL. After: GL till-leaf balance = `X_leaf + 90,000.00`;
`cash_accounts.current_balance` = **`X` (unchanged)**. Surplus refund of `21,000`: `Dr 2110
21,000.00 / Cr <till leaf> 21,000.00` — GL leaf `−21,000`, `current_balance` **unchanged**.

**Wrong ledger output:** the till's GL leaf and its `current_balance` diverge by the hammer price
of every auction (and by every surplus paid out). `finance:check-drift` flags it the next morning
but nothing reconciles it; the cashier has no record of the cash received or paid.

**Correct ledger output:** route through `FinancePostingService::execute` so
`current_balance` moves with the GL and a `cashbook_entries` mirror is written — same as every
other Cash movement in the system.

**Money impact:** every auction permanently drifts the branch till cache below the GL by the
hammer price; every surplus refund drifts it above. Reconciliation, the Cashbook report, and the
teller's drawer are all wrong by the cumulative amount.

---

## F-8 — Loan (and Speed Draft) instalment payment by Bank or Cheque posts nothing (confirms Risk 1)

**Severity:** P0 — `ledger-matrix.md` Risk 1, loan path.

**Found by:** `tests/Feature/LoanLedgerTest.php::test_loan07_bank_method_payment_posts_no_ledger_entry` (plan LOAN-07).

**Code:** `app/Support/LoanLedgerPoster.php:299` — `postCashReceipt()` starts `if ($payment->payment_method !== 'Cash') { return; }`. `LoanPaymentController::store` (`:189-191`) still runs `LoanPaymentAllocator::applyAllocation` for a Bank payment, marking the instalment `Paid`. Cheque payments land in `1030` only on realization (`postChequeRealized`, `LoanLedgerPoster.php:387`) and `1030` is never cleared to a real bank leaf.

**Repro (exact):** loan disbursed (`Dr 1100 100,000`). `POST /api/loans/{id}/payments {amount: 12000, payment_method: Bank, paid_at: 2026-04-01}` → HTTP 201. Instalment 1 `status=Paid`, `paid_amount=12,000.00`. `journal_entries` where `source_type='LoanPayment' AND source_id=<payment>` → **0 rows**. `1100` delta → **0.00**.

**Wrong ledger output:** the GL never records the collection — `1100` stays at the disbursed
principal, `4010` interest income is never recognised, no bank receipt.

**Correct ledger output:** `Dr <bank leaf> 12,000 / Cr 1100 10,000 / Cr 4010 2,000`.

**Money impact:** unbounded, growing. Every loan/speed-draft instalment collected by bank transfer
overstates the loan receivable and understates interest income by the amount collected.

---

## F-9 — Loan write-off credits `1100` for the full all-in outstanding, over-stating bad-debt expense (confirms Risk 2)

**Severity:** P0 — `ledger-matrix.md` Risk 2, empirically confirmed.

**Found by:** `tests/Feature/LoanLedgerTest.php::test_loan18_writeoff_over_credits_1100` (plan LOAN-18).

**Code:** `app/Support/LoanLedgerPoster.php:131-155` (`postWriteOff`) — credits `1100` for the entire `$amount`; `$amount = (float) $loan->outstanding_balance_cache` (`app/Http/Controllers/Api/LoanController.php:938`), which is principal **+ unpaid interest + live penalty + unpaid charges** (`LoanPaymentAllocator::outstandingBalance` `:60-74`).

**Repro (exact):** loan financed `100,000`; instalments 1–3 paid (principal `30,000` collected → this loan holds `70,000` in `1100`). Set `outstanding_balance_cache = 92,000` (70k principal + 14k interest + 8k penalty), `provision_posted_amount = 45,000`, and post that provision (`Dr 5300 45,000 / Cr 1190 45,000`). Then `LoanLedgerPoster::postWriteOff($loan, 92000, …)`:
```
fromProvision = min(45,000, 92,000) = 45,000.00
direct        = 92,000 − 45,000       = 47,000.00
posting: Dr 1190 45,000.00 / Dr 5300 47,000.00 / Cr 1100 92,000.00
```
This loan's `1100` contribution: `70,000 − 92,000 = −22,000.00` (abnormal credit). `5300` charged
for this write-off: `47,000.00`.

**Wrong ledger output:** `1100` driven `22,000` negative for the loan; bad-debt expense (`5300`)
over-stated by `22,000` — the interest and penalty that were never booked as income are now
written off as if they had been.

**Correct ledger output:**
```
Dr 1190 45,000.00                       (relieve the provision already expensed)
Dr 5300 25,000.00                       (70,000 principal − 45,000 already provided)
   Cr 1100        70,000.00             (only the principal actually on the books)
```

**Money impact:** every NPL write-off over-states period bad-debt expense (and drives `1100`
abnormally negative — the exact Trial-Balance complaint in
`trial_balance_negative_balances_diagnosis`) by the non-principal portion of the outstanding
balance.

---

## F-10 — Loan principal waiver posts nothing to the GL; `1100` stays overstated, no loss in P&L (confirms Risk 8)

**Severity:** P1 — `ledger-matrix.md` Risk 8, empirically confirmed.

**Found by:** `tests/Feature/LoanLedgerTest.php::test_loan27_principal_waiver_never_hits_the_gl` (plan LOAN-27).

**Code:** `app/Http/Controllers/Api/LoanWaiverController.php:20-38` (`store`) — creates a
`facility_waivers` row and returns; no `LedgerPoster` call. `approved_by` is set to the creator
(`:33`) — no maker-checker. `amount` is unbounded (`:23`). `destroy()` (`:41`) hard-deletes.

**Repro (exact):** loan financed `100,000`; instalments 1–3 paid (→ `1100` holds `70,000`).
`POST /api/loans/{id}/waivers {waiver_type: Principal, amount: 70000}` → HTTP 201.
`journal_entries` count **unchanged**; `1100` delta **0.00**; `5300` delta **0.00**. The loan's
`outstandingBalance()` drops (the waiver is netted in `LoanPaymentAllocator::waivedByRow`) and it
can close.

**Wrong ledger output:** none — the forgiven principal never leaves `1100`, and the loss never
reaches the P&L. Deleting the waiver silently restores the debt with no audit trail.

**Correct ledger output:** `Dr 5300 (or a dedicated waiver-expense) 70,000.00 / Cr 1100 70,000.00`
at the moment the waiver is created, gated by the same maker-checker as a write-off.

**Money impact:** every principal waiver overstates the loan book and understates losses by the
waived amount; a closed loan can silently owe again if the waiver row is deleted.

---

## F-11 — A `Pending` (uncleared) bank transaction moves `current_balance` immediately (confirms Risk 10)

**Severity:** P1 — `ledger-matrix.md` Risk 10.

**Found by:** `tests/Feature/CashFloatsLedgerTest.php::test_cf12_pending_bank_transaction_moves_the_balance_immediately` (plan CF-12).

**Code:** `app/Http/Controllers/Api/BankTransactionController.php:87-107` — `store()` calls
`FinancePostingService::execute` (which moves `current_balance`) unconditionally, then writes the
`bank_transactions` row with `status => $data['status'] ?? 'Cleared'`. The `Pending` status is
recorded but never gates the balance movement or the GL posting.

**Repro (exact):** `BANK-1.current_balance = 90,000`. `POST /api/bank-transactions
{transaction_type: Deposit, amount: 500000, status: Pending, contra_kind: Cash,
contra_cash_account_id: <till>}` → HTTP 201, `status=Pending`. `BANK-1.current_balance` →
**`590,000.00`**; the contra till → `−500,000.00`. A `Dr <bank leaf> 500,000 / Cr <till leaf>
500,000` GL entry is posted, dated now.

**Wrong ledger output:** an uncleared deposit is shown as spendable bank balance and posted to the
GL as though it had cleared. If the cheque bounces, the only remedy is a manual `destroy()`
(reversal), which — per F-1 — then mis-states the GL in the opposite direction.

**Correct ledger output:** a `Pending` bank transaction records the instrument but does not move
`current_balance` or post to the GL until it is marked `Cleared`; only then does the movement
happen.

**Money impact:** available-balance reports and overdraft checks over-state usable bank funds by
the total of all uncleared deposits; every bounced pending deposit needs a manual reversal.

---

## F-12 — An approved-but-unpaid expense is a real liability, but nothing ever posts to `2300 Accrued Expenses`

**Severity:** P1 — the system is cash-basis for expenses; an accrual-basis Balance Sheet run
before period end understates payables.

**Found by:** `tests/Feature/ExpenseLedgerTest.php::test_exp06_approved_unpaid_expense_never_hits_2300` (plan EXP-06).

**Code:** `app/Http/Controllers/Api/ExpenseController.php` — `store()` and `approve()` write only
`expenses` / `approval_requests` rows; the first (and only) GL posting is at `pay()` time
(`ExpensePaymentExecutor::pay`). `grep` confirms **no** `LedgerPoster::post` call anywhere
references account code `2300` (it is seeded but never used).

**Repro (exact):** create + approve an expense for `50,000`. Between approval and payment:
`journal_entries` count **unchanged**; `2300` balance delta **0.00**. The business owes `50,000`
and the ledger shows nothing.

**Correct ledger output:** on approval, `Dr <category expense account> 50,000 / Cr 2300 50,000`;
on payment, `Dr 2300 50,000 / Cr <cash/bank> 50,000`.

**Money impact:** at any month-end with approved-but-unpaid expenses, the Balance Sheet understates
liabilities and the P&L understates expenses by the total unpaid-approved amount.

---

## F-13 — No tax / withholding split on expense payments

**Severity:** P1 — every taxed vendor payment either over-pays the vendor or is adjusted outside the system.

**Found by:** `tests/Feature/ExpenseLedgerTest.php::test_exp07_no_withholding_tax_split` (plan EXP-07).

**Code:** `app/Support/ExpensePaymentExecutor.php:55-58` — the GL entry is exactly two lines:
`Dr <category account> <amount> / Cr <source leaf> <amount>`. `pay()`'s validated input
(`ExpenseController::PAY_RULES`) has no WHT / VAT field.

**Repro (exact):** a `100,000` invoice that should carry `10%` WHT. `POST /api/expenses/{id}/pay
{payment_method: Cash, …}` → the entry has **2** journal lines; the full `100,000` is debited to
the expense account and `100,000` leaves the till.

**Correct ledger output:** `Dr 5010 100,000 / Cr <WHT payable> 10,000 / Cr <cash> 90,000`.

**Money impact:** the vendor is paid the full gross (`10,000` over-payment per this example, never
recovered), or staff post a manual journal entry with no enforced link to the expense — either way
the WHT-payable / VAT-payable control accounts cannot be trusted for tax filing.

---

## Observations (not formal findings — no direct money misstatement)

**O-1 — `LoanProvisioning::RELATIONS` omits `schedules.allocations.payment`.**
`app/Support/LoanProvisioning.php:27` eager-loads `schedules.allocations` but not `.payment`,
which `LoanSummary::activePenaltyPaid` (`app/Support/LoanSummary.php:329`) accesses. In production
`Model::preventLazyLoading` is off (`app/Providers/AppServiceProvider.php:25`) so this is a silent
N+1 on every loan payment; in dev / staging / any non-production environment it throws
`LazyLoadingViolationException` → **HTTP 500 on recording a loan payment**. `LoanLedgerTest`
disables lazy-loading prevention in `setUp` to match production and keep the ledger assertions
meaningful.

---

## Deferred plan cases (ledger-invariants.md)

| Case | Why deferred | What is covered instead |
|---|---|---|
| INV-10 (loan-payment lost update) | Genuinely requires two overlapping DB transactions reading stale schedule state. This repo's harness (persistent DB + `DatabaseTransactions` rollback) makes fixtures invisible to a second connection, and app code cannot be modified to add a pause point. Faithful reproduction needs committed fixtures + real OS-level parallelism (subprocesses). | Static fact confirmed by code review in `ledger-matrix.md` Risk 6: `LoanPaymentController::store`'s transaction (`:153`) and `LoanPaymentAllocator::applyAllocation` take **no** `lockForUpdate` on the loan or its `facility_schedules` rows, unlike `FinancePostingService` on cash accounts. Recommend a dedicated subprocess-based harness (Symfony Process ×2) in a follow-up, with manual DB teardown. |
| INV-12 (full-suite determinism) | Requires every other plan file's suite to exist and run in a pinned order on a genuinely fresh (migrated-empty) DB. This DB is persistent and populated; `journal_entries.code` / `finance_transactions.code` sequences are not reset. | Will be implemented once pawning/loans/cash-floats/expenses/reconciliation suites land, as a CI-only job that migrates a scratch schema, runs the ordered suite twice, and diffs the `(code, entry_date, account_id, debit, credit)` projection. |

## Status by plan file

| Plan file | Implemented | Pass | Fail (findings) | Notes |
|---|---|---|---|---|
| ledger-invariants.md | INV-01…09, 11, 13, 14, 15 | 15 | 5 (F-1, F-2×2, F-3, F-4) | INV-10, INV-12 deferred (see above). 3 test files: `LedgerInvariantsCoreTest`, `LedgerInvariantsRoundingDriftTest`, `LedgerInvariantsConcurrencyIdempotencyTest` |
| pawning.md | PWN-01…09, 11…15, 18…20 | 14 | 5 (F-5, F-6, F-7×3) | `tests/Feature/PawningLedgerTest.php`. PWN-10/16/17 folded into other cases or n/a (no revaluation feature). |
| loans.md | LOAN-01…05, 07…09, 12, 13, 15, 18, 21, 22, 23, 27 | 13 | 3 (F-8, F-9, F-10) | `tests/Feature/LoanLedgerTest.php`. LOAN-06/10/11/14/16/17/19/20/24/25/26 not yet implemented (P1/P2). O-1 observation. |
| cash-floats.md | CF-02, 06(excess), 07, 09, 12, 13, 16, 17 | 7 | 1 (F-11) | `tests/Feature/CashFloatsLedgerTest.php`. CF-01/03/04/05/06(shortage)/08/09(bank)/10/11/15 already covered by the pre-existing suite (CashFloatFlowTest, FinanceTransferFlowTest, CashSessionFlowTest, BankTransactionFinancePostingTest). CF-14 not implemented. |
| expenses.md | EXP-01, 02, 04, 05, 06, 07, 10, 11, 12, 13, 14 | 9 | 2 (F-12, F-13) | `tests/Feature/ExpenseLedgerTest.php`. EXP-03/08/09/15 covered by pre-existing ExpensePaymentRequestFlowTest or n/a (reimbursement has no dedicated flow). |
| reconciliation.md | RECON-01, 03, 08, 10 | 4 | 0 | `tests/Feature/ReconciliationLedgerTest.php`. No new findings — the report layer ties correctly to the ledger; the defects are all in the posting layer. RECON-02 (PDF/export parity) needs render-level testing — deferred; RECON-04…07/09 folded into RECON-01/03. |
