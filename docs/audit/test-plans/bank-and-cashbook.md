# Manual bank transactions & manual cashbook — test plan

Code under test: `BankTransactionController`, `CashbookEntryController`. Both are unapproved,
operator-chosen-account posting paths layered directly on `FinancePostingService` — see
`ledger-matrix.md` Risk 7. (Till-side deposit/withdrawal happy paths are covered in
[`cash-floats.md`](cash-floats.md) CF-10/CF-11; this file covers the accounts-payable-to-any-leaf
and no-approval angles specifically.)

Run [`ledger-invariants.md`](ledger-invariants.md) after every case.

---

### BANK-01 | `Ledger` contra can post to any account — equity, income, anything — with no approval
**Type:** integration · **Priority:** P0 · **Expected result: FAIL (documents Risk 7)**
**Setup:** BANK-1 `= 500,000`. CLOCK `2026-06-01`.
**Steps:** `POST /bank-transactions {bank_account_id: 1, transaction_type: Deposit, amount: 75000, txn_date: 2025-01-01, contra_kind: Ledger, contra_chart_of_account_id: <3020 Retained Earnings>}` — submitted by a single ordinary Finance-role user, no second approver anywhere in the flow.
**Expected (current):** succeeds, no approval gate exists on this controller at all.
```
Dr 1020-1   75,000.00
   Cr 3020            75,000.00
```
`entry_date = 2025-01-01` (a prior, presumably closed, financial year).
**Expected balances after (current):** `BANK-1 = 575,000.00`; `3020 Retained Earnings` silently restated for a year already reported on, by one person, with no second sign-off and no lock-date check.
**Target the test asserts (xfail):** either (a) `contra_chart_of_account_id` is restricted to non-equity/non-income control accounts, or (b) this endpoint requires the same maker-checker `ManualJournalEntryController` uses, or (c) `txn_date` is rejected once earlier than a configured lock date. None of the three exist today.
**Risk it covers:** Risk 7. **README top-25 #20.**

---

### BANK-02 | Bank-to-bank transfer (`TransferOut` / `TransferIn`)
**Type:** integration · **Priority:** P0
**Setup:** BANK-1 `= 500,000`, BANK-2 `= 0`.
**Steps:** `POST /bank-transactions {bank_account_id: 1, transaction_type: TransferOut, amount: 200000, contra_kind: Bank, contra_bank_account_id: 2}`.
**Expected ledger entries:**
```
Dr 1020-2   200,000.00
   Cr 1020-1           200,000.00
```
**Expected balances after:** `BANK-1 = 300,000.00`; `BANK-2 = 200,000.00`; a single `BankTransaction` row on BANK-1's side only (assert whether the mirror row on BANK-2 is auto-created or must be entered separately — if not, BANK-2's own `bank_transactions` history is incomplete even though its balance is correct).
**Risk it covers:** debit/credit sides swapped for the "out" direction relative to "in" (`BankTransactionController.php:72`,`:79`); `different:bank_account_id` guard (`:62`) — same account on both sides rejected.

---

### BANK-03 | Manual cashbook adjustment — arbitrary offset, no approval
**Type:** integration · **Priority:** P0
**Setup:** TILL-HQ `= 400,000`.
**Steps:** `POST /cashbook-entries {cash_account_id: 1, direction: In, category: Other, amount: 12345.67, offset_account_id: <4900 Other Income>, entry_date: 2026-05-01}`.
**Expected ledger entries:**
```
Dr 1010-1   12,345.67
   Cr 4900             12,345.67
```
**Expected balances after:** `TILL-HQ = 412,345.67`; a `cashbook_entries` mirror row `direction=In`, `category=Other`. No approval step exists anywhere in this controller.
**Risk it covers:** an operator manufacturing income (or, with `direction=Out`, manufacturing an expense against any account) with a single click and no review — same class of hole as BANK-01, on the cash side.

---

### BANK-04 | Cashbook void — Phase-9 reversal vs legacy manual walk-back
**Type:** integration · **Priority:** P1
**Setup:** BANK-03's entry (has `source_type='FinanceTransaction'`); a second, pre-migration-style row seeded directly into `cashbook_entries` with `source_type=null` (simulating legacy data).
**Steps:** void both.
**Expected ledger entries:**
- Phase-9 row: `FinancePostingService::reverse` → `Dr 4900 12,345.67 / Cr 1010-1 12,345.67`; original entry `Reversed`.
- Legacy row: **no GL entry at all** — `CashbookEntryController::destroy` legacy branch (`:101`) only walks the `cash_accounts.current_balance` back and hard-**deletes** the `cashbook_entries` row; it never had a `journal_entries` row to begin with (predates the GL migration).
**Expected balances after:** `TILL-HQ` restored correctly in both cases, but the legacy void leaves **no audit trail whatsoever** — the original entry, the void, and the reason are all gone.
**Risk it covers:** "posted entry deleted instead of reversed" — the legacy path is a live example still reachable through the same controller.

---

### BANK-05 | No idempotency on bank txn / cashbook entry creation
**Type:** integration · **Priority:** P1 · **Expected result: FAIL (Risk 6)**
**Setup:** BANK-1 `= 500,000`.
**Steps:** submit the identical `POST /bank-transactions {...}` (`amount=100000`, Deposit, Cash contra) twice.
**Expected (current):** two entries, two movements — `BANK-1` and `TILL-HQ` both moved twice for one physical deposit. No `idempotency_key` is ever sent from this controller (`BankTransactionController.php:88` — `execute()` called without an `idempotency_key` key in the spec array at all).
**Risk it covers:** Risk 6.

---

### BANK-06 | Every cash leg gets a cashbook mirror — regression sentinel
**Type:** integration · **Priority:** P1
**Setup:** run one of each: a loan Cash collection, an expense Cash payment, a float issue, a cash-session shortage adjustment, a bank Deposit with Cash contra.
**Steps:** for each, inspect `cashbook_entries` for a row with `source_type='FinanceTransaction'`, `source_id = <the finance_transactions.id>`.
**Expected balances after:** exactly one mirror row per cash-account leg touched, `amount = leg['delta']` (unsigned), `balance_after` equal to the account's `current_balance` immediately after that transaction — assert against a running total, not just the final balance.
**Risk it covers:** `FinancePostingService::mirrorToCashbook` (`FinancePostingService.php:170`) silently skipped for a bank-only movement (correct — only `type==='cash'` legs mirror) vs a cash leg that's supposed to mirror but doesn't.

---

### BANK-07 | Bank reconciliation and cash reconciliation are read-only
**Type:** integration · **Priority:** P2
**Setup:** BANK-1 with several `bank_transactions`; a bank statement import/entry with a slightly different balance.
**Steps:** run `BankReconciliationController`/`CashReconciliationController` matching flow.
**Expected ledger entries:** none — these controllers only compare `current_balance` to a statement figure and mark items matched/unmatched (`BankReconciliationController.php:37`).
**Expected balances after:** `bank_accounts.current_balance` and every GL leaf completely unchanged by running a reconciliation, regardless of how large the discrepancy found is.
**Risk it covers:** confirms reconciliation cannot itself introduce a posting bug — a false assumption to guard against explicitly, since every other "compare and true up" flow in this system (float settle, cash session verify) **does** post.

---

### BANK-08 | Zero-amount / negative-amount bank transaction rejected before posting
**Type:** unit · **Priority:** P2
**Steps:** `POST /bank-transactions {..., amount: 0}` and `{..., amount: -500}`.
**Expected:** validation `min:0.01` rejects both (`BankTransactionController.php:58`) before `FinancePostingService::execute` is ever called.
**Risk it covers:** a `0`/negative amount reaching `LedgerPoster` and tripping the DB CHECK as an ugly 500 instead of a clean 422.
