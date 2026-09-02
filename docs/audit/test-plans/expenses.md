# Expenses & petty cash — test plan

Code under test: `ExpenseController`, `app/Support/ExpensePaymentExecutor.php`,
`PaymentVoucherController`, `PettyCashVoucherController`, `PettyCashReplenishmentController`.

Run [`ledger-invariants.md`](ledger-invariants.md) after every case.

Fixtures: EXP-CAT-1 (→ `5010`), PCF-1 (`float_limit=50,000`, `current_balance=50,000`, leaf `1015-1`).

---

### EXP-01 | Direct expense payment — Cash
**Type:** integration · **Priority:** P0
**Setup:** an expense `amount=50,000`, `category=EXP-CAT-1`, `status=Approved`. `TILL-HQ=1,000,000`.
**Steps:** `POST /expenses/{id}/pay {payment_method: Cash, cash_account_id: 1}`.
**Expected ledger entries:**
```
Dr 5010   50,000.00
   Cr 1010-1        50,000.00
```
`finance_transactions`: `txn_type=EXPENSE_PAYMENT`, `idempotency_key='expense-payment-{id}'`.
**Expected balances after:** `TILL-HQ = 950,000.00 = 1010-1` leaf; `payment_vouchers` row `status=Issued`; `expense.status=Paid`.
**Risk it covers:** regression on `ExpensePaymentExecutor::pay`; category→account mapping.

---

### EXP-02 | Direct expense payment — Bank
**Type:** integration · **Priority:** P0
**Setup:** as EXP-01, `payment_method=Bank`, `bank_account_id=BANK-1`, `BANK-1=1,000,000`.
**Expected ledger entries:**
```
Dr 5010   50,000.00
   Cr 1020-1        50,000.00
```
**Expected balances after:** `BANK-1 = 950,000.00 = 1020-1` leaf. (Unlike loans/pawning, expense Bank payments **do** post — `ExpensePaymentExecutor` has no Cash-only guard.)
**Risk it covers:** confirms expenses are NOT subject to Risk 1 — regression sentinel: if this ever starts skipping the GL like the credit-facility Bank paths, it's a regression, not a known gap.

---

### EXP-03 | Request path — money moves before the voucher exists
**Type:** integration · **Priority:** P1
**Setup:** an Approved expense; officer `POST /expenses/{id}/request-payment`; Finance approves + executes the resulting `finance_requests` row (`offset_account_id = 5010` from `meta`).
**Steps:** inject a fault into `CompleteExpensePaymentOnSettled` (voucher-writing listener) after the money moves.
**Expected ledger entries:** the `Dr 5010 X / Cr <source leaf> X` entry is posted and committed regardless of the listener fault (same shape as `LOAN-06`).
**Expected balances after:** source account moved; `5010` debited; **no `payment_vouchers` row**; `expense.status` still `Approved` (not `Paid`) — a paid expense with no voucher and no "Paid" flag.
**Risk it covers:** money/GL correct but the operational record left inconsistent — same class of gap as `LOAN-06`.

---

### EXP-04 | Petty cash spend
**Type:** integration · **Priority:** P0
**Setup:** PCF-1 `current_balance=50,000`, leaf `1015-1`.
**Steps:** `POST /petty-cash-vouchers {petty_cash_fund_id: 1, expense_category_id: EXP-CAT-1, amount: 3,500}`.
**Expected ledger entries:**
```
Dr 5010   3,500.00
   Cr 1015-1        3,500.00
```
**Expected balances after:** `petty_cash_funds.current_balance = 46,500.00`; `1015-1` leaf `= 46,500.00` (INV-04 — **not otherwise checked anywhere in production**, Risk 5).
**Risk it covers:** fund cache vs GL leaf agreement.

---

### EXP-05 | Petty cash spend beyond the fund balance is rejected
**Type:** unit · **Priority:** P0
**Setup:** PCF-1 `current_balance=3,000`.
**Steps:** `POST /petty-cash-vouchers {..., amount: 3,500}`.
**Expected:** HTTP **422** "Insufficient petty cash balance" (`PettyCashVoucherController.php:52`) — a **manual** check, not `FinancePostingService`'s guard (this path bypasses FPS entirely).
**Expected ledger entries:** none.
**Expected balances after:** unchanged.
**Risk it covers:** the manual `bcsub` at `:65` has no built-in negative floor — this test is the only thing stopping a negative fund balance; assert the check fires **before** any DB write.

---

### EXP-06 | Payable created, then paid later — no `2300 Accrued Expenses` posting
**Type:** integration · **Priority:** P0
**Setup:** expense created and Approved on `2026-03-01`; paid on `2026-03-20`.
**Steps:** create → approve → wait → pay.
**Expected ledger entries:** **zero** entries between creation and payment. On payment: the single entry from EXP-01. `2300 Accrued Expenses` is never touched anywhere in the codebase (confirmed — no `LedgerPoster::post` call references it).
**Expected balances after:** as-of `2026-03-10` (between approval and payment), the expense is a real liability the business owes but the Balance Sheet shows **nothing** for it — `2300` stays `0.00` even though an approved, unpaid expense exists.
**Risk it covers:** documents that this system is cash-basis for expenses too — an accrual-basis Balance Sheet run mid-period understates payables by every Approved-but-unpaid expense. P0 because it can silently misstate the Balance Sheet by an unbounded amount at period end.

---

### EXP-07 | Tax / withholding split — does not exist
**Type:** integration · **Priority:** P0 · **Expected result: FAIL (documents a gap)**
**Setup:** an expense `amount=100,000` that should carry a `10%` WHT deduction (vendor invoice `100,000` gross, `10,000` withheld, `90,000` paid out).
**Steps:** `POST /expenses/{id}/pay {...}` — inspect the request/response schema and `ExpensePaymentExecutor::pay` for any WHT/VAT field.
**Expected (current):** no such field exists; the full `100,000` is debited to `5010` and the full `100,000` leaves the till/bank. **No `2300`/withholding-payable line, no split.**
**Target the test asserts (xfail):**
```
Dr 5010   100,000.00
   Cr <WHT payable>          10,000.00
   Cr 1010-1                 90,000.00
```
**Risk it covers:** every taxed expense either (a) pays the vendor the full gross amount (over-payment, WHT never remitted) or (b) is manually adjusted outside the system with no enforced link back to the expense — either way the ledger cannot be trusted for tax compliance reporting.

---

### EXP-08 | Void of a paid expense — reverses the payment, restores the account
**Type:** integration · **Priority:** P1
**Setup:** EXP-01's paid voucher.
**Steps:** `DELETE /payment-vouchers/{id}` (void).
**Expected ledger entries:** `FinancePostingService::reverse` on the `EXPENSE_PAYMENT` txn: `Dr 1010-1 50,000.00 / Cr 5010 50,000.00`. Original entry `Reversed`; a `REVERSAL` `finance_transactions` row.
**Expected balances after:** `TILL-HQ = 1,000,000.00` restored; `voucher.status=Voided`. `expense.status` — check whether it reverts to `Approved` (re-payable) or stays `Paid` (must not be both `Paid` and have a `Voided` voucher with no live payment).
**Risk it covers:** double-payment after a void if `expense.status` isn't reset; till left short after void.

---

### EXP-09 | Void an expense with no voucher (Pending/Approved-not-yet-paid) is rejected
**Type:** unit · **Priority:** P1
**Setup:** an `Approved`, unpaid expense.
**Steps:** attempt to void it via `PaymentVoucherController` (no voucher exists) — instead use `reject()`.
**Expected:** `reject()` only works while `status=Pending` (`ExpenseController.php:112`); an Approved-but-unpaid expense has **no reject/cancel endpoint** — confirm this and document it as a gap (an approved expense can only be paid or left to rot, never explicitly cancelled).
**Expected ledger entries:** none in either path.
**Risk it covers:** stuck Approved expenses with no clean way to close them out.

---

### EXP-10 | Backdated expense payment — no closed-period block
**Type:** integration · **Priority:** P0
**Setup:** an Approved expense; CLOCK `2026-06-01`.
**Steps:** `POST /expenses/{id}/pay {..., paid_at: 2025-01-15}`.
**Expected ledger entries:** posts successfully with `entry_date=2025-01-15` — **no block, no warning.**
**Expected balances after:** a Trial Balance as-of `2025-01-31` (long "closed" for a real business) now includes this `50,000` expense.
**Risk it covers:** Risk 7, expense-specific instance. **P0** because expenses are the highest-frequency backdatable postings in the system.

---

### EXP-11 | Pay a Pending (not-yet-Approved) expense is rejected
**Type:** unit · **Priority:** P1
**Setup:** an expense `status=Pending`.
**Steps:** `POST /expenses/{id}/pay {...}`.
**Expected:** HTTP **422** "Only an approved expense can be paid" (`ExpenseController.php:146`). No entry.
**Risk it covers:** maker-checker bypass — paying before approval.

---

### EXP-12 | Idempotency — retrying `pay()` after it already succeeded
**Type:** integration · **Priority:** P1
**Setup:** EXP-01, already paid (`expense.status=Paid`).
**Steps:** `POST /expenses/{id}/pay {...}` again (simulating a client retry).
**Expected:** HTTP **422** "Only an approved expense can be paid" (status is now `Paid`, not `Approved`) — **this path IS guarded**, unlike FD deposits / bank transactions / commission payments.
**Expected ledger entries:** none on the retry.
**Risk it covers:** regression sentinel — confirms expenses are not exposed to Risk 6 the way EXP-CAT-1's siblings are; if this guard is ever refactored away, this test catches it.

---

### EXP-13 | Petty cash top-up (replenishment)
**Type:** integration · **Priority:** P1
**Setup:** PCF-1 `current_balance=20,000`, `float_limit=50,000`. `TILL-HQ=1,000,000`.
**Steps:** `POST /petty-cash-replenishments {petty_cash_fund_id:1, amount: 25,000, funding_method: Cash, cash_account_id: 1}`.
**Expected ledger entries:**
```
Dr 1015-1   25,000.00
   Cr 1010-1           25,000.00
```
**Expected balances after:** `PCF-1.current_balance = 45,000.00`; `TILL-HQ = 975,000.00`.
**Risk it covers:** source/fund balance moved by hand (no `FinancePostingService`) — regression on the manual `bcsub`/`bcadd` pair.

---

### EXP-14 | Top-up that would exceed `float_limit` is rejected; top-up void after partial spend is rejected
**Type:** unit · **Priority:** P1
**Setup:** PCF-1 `current_balance=45,000`, `float_limit=50,000`.
**Steps:**
1. `POST /petty-cash-replenishments {..., amount: 10,000}` → `45,000+10,000=55,000 > 50,000` → HTTP **422** (`PettyCashReplenishmentController.php:53`).
2. A prior `20,000` top-up now has `40,000` already spent from the fund since (`fund.current_balance=45,000 < 20,000`? — actually check must be `< replenishment.amount`) → attempt `destroy()` on it → HTTP **422** "already been spent" (`:120-121`).
**Expected ledger entries:** none for either rejection.
**Risk it covers:** float ceiling breach; voiding a top-up that's already been drawn down (would drive the fund negative).

---

### EXP-15 | Reimbursement — no dedicated flow
**Type:** integration · **Priority:** P2
**Setup:** an employee reimbursement of `4,200`.
**Steps:** search the codebase for a reimbursement-specific model/controller — none exists; it must be entered as an ordinary `Expense` (category, e.g., "Staff Reimbursement").
**Expected:** identical entries to EXP-01 with whatever category account is chosen; no employee-specific ledger tracking (no sub-ledger by employee) beyond the expense's own `vendor_id`/description.
**Risk it covers:** documents that "reimbursement" has no distinct audit trail from any other expense — a reimbursement misclassified under the wrong category is indistinguishable from a vendor payment in reports.
