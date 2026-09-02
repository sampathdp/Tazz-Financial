# Borrowings (company-side) — test plan

Code under test: `BorrowingController`, `app/Support/BorrowingLedgerPoster.php`. Liability accounts:
`2200` (Party/Institutional), `2210` (Bank Overdraft), `2130` (Re-Pledged Pawning). Expense `5110`
(Interest Expense – Borrowings). Cash-method only — Bank legs never post (Risk 1).

Run [`ledger-invariants.md`](ledger-invariants.md) after every case.

Fixture **BRW-1**: `type=Party`, `principal_amount=1,000,000`, `receipt_method=Cash`, `borrowing_date=2026-03-01`.

---

### BRW-01 | Drawdown — Cash, Party type
**Type:** integration · **Priority:** P0
**Setup:** TILL-HQ `= 500,000`.
**Steps:** `POST /borrowings {type: Party, principal_amount: 1000000, receipt_method: Cash, lender_name: "ABC Finance"}`.
**Expected ledger entries:**
```
Dr 1010-1     1,000,000.00
   Cr 2200                1,000,000.00
```
**Expected balances after:** `TILL-HQ = 1,500,000.00`; `2200 = 1,000,000.00`; `borrowing.outstanding_amount = 1,000,000.00`; `idempotency_key='borrowing-drawdown-{id}'`.
**Risk it covers:** regression on `BorrowingLedgerPoster::postDrawdown`; correct liability account chosen by `type`.

---

### BRW-02 | Drawdown — RePledgedPawning type uses `2130`, not `2200`
**Type:** integration · **Priority:** P0
**Setup:** `type=RePledgedPawning`, `principal_amount=400,000`, Cash, with `repledged_items` rows attached.
**Expected ledger entries:**
```
Dr 1010-1   400,000.00
   Cr 2130            400,000.00
```
**Expected balances after:** `2130 = 400,000.00`; `2200` untouched.
**Risk it covers:** `BorrowingLedgerPoster::liabilityAccount()` match-arm (`BorrowingLedgerPoster.php:36`) — a Party-type entry accidentally landing on `2130` would misclassify the liability on the Balance Sheet.

---

### BRW-03 | Overdraft registration posts nothing; draw-downs are ordinary bank withdrawals
**Type:** integration · **Priority:** P0
**Setup:** BANK-1 `current_balance=0`, `overdraft_limit=0`.
**Steps:**
1. `POST /borrowings {type: Overdraft, principal_amount: 500000, bank_account_id: BANK-1, set_overdraft_limit: true}`.
2. Withdraw `300,000` from BANK-1 via an ordinary `BankTransactionController` Withdrawal (paying an expense, say).
**Expected ledger entries (step 1):** **none** (`BorrowingLedgerPoster.php:55` requires `receipt_method==='Cash'`; an Overdraft borrowing has `receipt_method=null` — `BorrowingController.php:150`). Only `BANK-1.overdraft_limit` is set to `500,000` via a raw `update()` (`:161`), not through `FinancePostingService`.
**Expected balances after step 1:** `BANK-1.current_balance = 0.00` still; `2210` still `0.00`.
**Expected ledger entries (step 2):**
```
Dr <expense/contra>   300,000.00
   Cr 1020-1                       300,000.00
```
**Expected balances after step 2:** `BANK-1.current_balance = −300,000.00` — **allowed**, because `floor = −overdraft_limit = −500,000.00` and `−300,000 > −500,000` (`FinancePostingService.php:299`).
**Expected balances after a further `250,000` withdrawal:** `−550,000.00 < −500,000.00` → `InsufficientFundsException` — the draw is rejected.
**Risk it covers:** overdraft facility never appears in `2210` at all (it's tracked only as a bank-balance floor, not a GL liability) — a Balance Sheet reader sees a negative bank asset, never a `2210` liability, for the same economic fact. Document as a **classification gap**, distinct from Risk 1 (which is about missing entries, not misclassified ones).

---

### BRW-04 | Interest payment
**Type:** integration · **Priority:** P0
**Setup:** BRW-1 drawn down; pay `18,500.00` interest Cash.
**Expected ledger entries:**
```
Dr 5110   18,500.00
   Cr 1010-1        18,500.00
```
**Expected balances after:** `TILL-HQ -= 18,500`; `2200` unchanged (interest doesn't reduce principal).
**Risk it covers:** interest miscoded to the liability account instead of `5110`.

---

### BRW-05 | Principal repayment reduces the liability
**Type:** integration · **Priority:** P0
**Setup:** BRW-1, `outstanding_amount=1,000,000`. Repay `250,000` Cash.
**Expected ledger entries:**
```
Dr 2200   250,000.00
   Cr 1010-1          250,000.00
```
**Expected balances after:** `2200 = 750,000.00`; `borrowing.outstanding_amount = 750,000.00`; `TILL-HQ -= 250,000`.
**Risk it covers:** repayment posted as if it were interest (wrong account) or not reducing `outstanding_amount`.

---

### BRW-06 | Bank-method drawdown and repayment post nothing
**Type:** integration · **Priority:** P1 · **partial FAIL (Risk 1)**
**Setup:** BRW-1 with `receipt_method=Bank`.
**Expected (current):** `borrowing.outstanding_amount` set, but **zero** GL entries and zero bank-balance movement (`BorrowingLedgerPoster.php:55` early-returns).
**Expected balances after (current):** `2200` never shows the liability; INV-04 fails.
**Risk it covers:** Risk 1, borrowings instance.

---

### BRW-07 | Void a drawdown / void a transaction
**Type:** integration · **Priority:** P1
**Setup:** BRW-01's drawdown and BRW-05's repayment.
**Steps:** void each.
**Expected ledger entries:** `reverseDrawdown`/`reverseTransaction` → `FinancePostingService::reverse`:
- drawdown void: `Dr 2200 1,000,000.00 / Cr 1010-1 1,000,000.00`
- repayment void: `Dr 1010-1 250,000.00 / Cr 2200 250,000.00`
**Expected balances after:** `TILL-HQ` and `2200` both restored to pre-transaction values; originals `Reversed`, not deleted.
**Risk it covers:** void ordering — voiding the drawdown while a later repayment still references it would leave `2200` negative; assert the system either blocks out-of-order voids or nets correctly regardless of order.

---

### BRW-08 | Renewal — no cash, dates only
**Type:** unit · **Priority:** P2
**Setup:** BRW-1 at maturity; renew for another 12 months.
**Expected ledger entries:** none (`BorrowingController.php:209` docblock: "No cash moves here").
**Expected balances after:** `maturity_date`/`term_months`/`interest_rate_percent` updated in place; a `borrowing_renewals` audit row logs the prior values; `2200` unchanged.
**Risk it covers:** a renewal accidentally re-posting the principal (double-counting the liability).
