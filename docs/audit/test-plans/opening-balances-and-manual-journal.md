# Opening balances & manual journal entries — test plan

Code under test: `app/Support/OpeningBalanceLedgerPoster.php`, `app/Console/Commands/PostOpeningBalances.php`,
`ManualJournalEntryController`. Control account `3015` (Opening Balance Equity).

Run [`ledger-invariants.md`](ledger-invariants.md) after every case.

---

### OB-01 | New till/bank account opening balance
**Type:** integration · **Priority:** P0
**Setup:** create a `cash_accounts` row with `opening_balance=75,000.00`.
**Expected ledger entries:**
```
Dr 1010-{new}   75,000.00
   Cr 3015                 75,000.00
```
**Expected balances after:** `current_balance = 75,000.00 = 1010-{new}` leaf; `3015` credited `75,000.00`.
**Risk it covers:** regression on `OpeningBalanceLedgerPoster::postForCashAccount`.

---

### OB-02 | Subledger true-up — posts the delta once, idempotent on rerun
**Type:** integration · **Priority:** P0
**Setup:** a data cutover state: `SUM(loans.outstanding_balance_cache) = 900,000.00` but GL `1100`'s current balance (from whatever was actually posted) `= 850,000.00` — a `50,000.00` gap from loans disbursed before the system went live.
**Hand computation:** `deltaNormal = target − current = 900,000 − 850,000 = 50,000.00` (positive, `1100` is Debit-normal) → `debit=50,000.00` on `1100`; `offsetDebit = 0 − 50,000 = −50,000.00` → equity line `credit=50,000.00`.
**Steps:** `php artisan ledger:opening-balances --apply`.
**Expected ledger entries:**
```
Dr 1100   50,000.00
   Cr 3015           50,000.00
```
`source_type='OpeningBalance'`.
**Expected balances after:** GL `1100 = 900,000.00 = Σ outstanding_balance_cache` (INV-04 now holds). **Rerun immediately:** `deltaNormal = 900,000 − 900,000 = 0.00` → command reports "Nothing to do", **posts no entry** — confirmed by the `bccomp(...) === 0` skip (`PostOpeningBalances.php:87`).
**Risk it covers:** double-posting the true-up on a second run. **README top-25 #25.**

---

### OB-03 | True-up can launder a write-off bug into opening equity
**Type:** integration · **Priority:** P0
**Setup:** run `LOAN-18` (the write-off that over-credits `1100` by `22,000.00`) first. After it, `SUM(outstanding_balance_cache)` across all loans correctly excludes the written-off loan, but GL `1100`'s real balance is `22,000.00` **lower** than it should be for the loans still on the books (the write-off took `92,000` out when only `70,000` was actually owed).
**Steps:** `php artisan ledger:opening-balances --apply`.
**Hand computation:** `deltaNormal = target − current = +22,000.00` (current is too low) → the command posts `Dr 1100 22,000.00 / Cr 3015 22,000.00`.
**Expected ledger entries (current behaviour):** the entry above — `3015 Opening Balance Equity` absorbs the write-off's over-credit as if it were an ordinary cutover adjustment.
**Expected balances after:** `1100` now matches the cache (technically INV-04 passes again), but the `22,000` overstatement from `LOAN-18` is now sitting in equity with **no trace back to the write-off** — the `5300`/`1190` figures from `LOAN-18` are untouched and still wrong.
**Risk it covers:** running this command masks Risk 2/Risk 9 bugs instead of surfacing them — anyone auditing `3015` after a routine true-up run has no way to tell a legitimate cutover entry from a laundered posting bug. Recommend this command flag (not silently post) any single-run delta above a threshold, or require it to be run only immediately after go-live, never as routine maintenance.

---

### OB-04 | Manual journal entry — unbalanced input rejected at two layers
**Type:** unit · **Priority:** P0
**Setup:** —
**Steps:**
1. `POST /manual-journal-entries {lines: [{account: 5010, debit: 1000, credit: 0}, {account: 1010-1, debit: 0, credit: 999.99}]}`.
2. A line with both `debit>0` and `credit>0`.
3. A line with `debit=0` and `credit=0`.
**Expected:** all three rejected with a `ValidationException` **before** any DB write (`ManualJournalEntryController.php:53-66`) — "does not balance", "either a debit or a credit, not both", "needs a debit or a credit amount" respectively.
**Expected ledger entries:** none. `manual_journal_entries` staging row not created.
**Risk it covers:** the controller-level check is a UX nicety; `LedgerPoster::post`'s own check (`LedgerPoster.php:39`) is the real backstop — assert **both** independently (bypass the controller and call `LedgerPoster::post` directly with unbalanced lines to prove the backstop alone still holds).

---

### OB-05 | Maker-checker — not posted until required approvals met; self-approval blocked
**Type:** integration · **Priority:** P0
**Setup:** a balanced MJE, `lines=[{5010, debit:5000}, {1010-1, credit:5000}]`, `required_approvals=2`.
**Steps:**
1. Submit (creator = UserA) → `status=PendingApproval`, **no GL entry**.
2. UserA attempts to approve their own entry → rejected ("You cannot approve your own journal entry", `:120`).
3. UserB approves (1st of 2) → still `PendingApproval`, **no GL entry** (`req->fresh()->approvalsCount() < required_approvals` → early return, `:132`).
4. UserC approves (2nd) → **now** posts.
**Expected ledger entries (only after step 4):**
```
Dr 5010   5,000.00
   Cr 1010-1         5,000.00
```
`source_type='ManualAdjustment'`.
**Expected balances after:** `manual_journal_entries.status='Approved'`, `journal_entry_id` set; `1010-1` moved only once, at step 4.
**Risk it covers:** a single approver posting an adjustment alone; the entry appearing before the second sign-off.

---

### OB-06 | Rejection posts nothing
**Type:** unit · **Priority:** P1
**Setup:** a pending MJE.
**Steps:** an authorised approver rejects it.
**Expected ledger entries:** none, ever — `manual_journal_entries.status='Rejected'`, `approval_requests.status='Rejected'`.
**Risk it covers:** a rejected entry somehow still reaching `LedgerPoster`.

---

### OB-07 | Backdated / future-dated manual journal entry — no lock date
**Type:** integration · **Priority:** P1
**Setup:** CLOCK `2026-06-01`.
**Steps:** submit + fully approve an MJE with `entry_date=2024-01-01` and, separately, one with `entry_date=2030-01-01`.
**Expected ledger entries:** both post successfully; `journal_entries.entry_date` = the operator's chosen date in both cases.
**Expected balances after:** a Trial Balance as-of `2024-12-31` includes the first; as-of `2026-06-01` includes neither the second.
**Risk it covers:** duplicate of `INV-14`, MJE-specific — the highest-privilege posting path in the system has the least restriction on when it can date its entries.

---

### OB-08 | Negative opening balance credits the leaf, debits equity
**Type:** unit · **Priority:** P1
**Setup:** a `cash_accounts` row created with `opening_balance = −10,000.00` (a till carried over already overdrawn, `allow_negative=true`).
**Hand computation:** `positive = false`; `abs = bcsub(0, −10,000, 2) = 10,000.00`. Lines: leaf `debit=0, credit=10,000.00`; equity `debit=10,000.00, credit=0`.
**Expected ledger entries:**
```
Dr 3015   10,000.00
   Cr 1010-{new}      10,000.00
```
**Expected balances after:** `current_balance = −10,000.00 = 1010-{new}` leaf balance (a Debit-normal account showing a credit balance — confirm the report layer flags this as abnormal rather than hiding it).
**Risk it covers:** sign error on the negative-opening-balance branch (`OpeningBalanceLedgerPoster.php:45-50`) crediting the wrong side.

---

### OB-09 | Zero opening balance — no-op
**Type:** unit · **Priority:** P2
**Setup:** `opening_balance = 0.00`.
**Expected ledger entries:** none (`OpeningBalanceLedgerPoster.php:41` — `bccomp(amount,'0.00',2)===0` returns immediately).
**Risk it covers:** a zero-amount line reaching `LedgerPoster` and tripping the CHECK constraint.

---

### OB-10 | Opening-balance true-up with `--include-cash` covers every till/bank in one entry
**Type:** integration · **Priority:** P2
**Setup:** three tills whose `current_balance` disagrees with their GL leaves by `+200`, `−150`, `0` respectively (simulating drift the daily `finance:check-drift` job would also catch).
**Steps:** `php artisan ledger:opening-balances --include-cash --apply`.
**Expected ledger entries:** **one** entry with a line per drifted account (the `0` one omitted) plus the `3015` offset — not three separate entries.
**Expected balances after:** every till's GL leaf now equals its `current_balance` exactly; the `3015` offset equals the net of `+200` and `−150` = `+50.00`.
**Risk it covers:** this command is presented as the *fix* for drift `finance:check-drift` reports — assert it actually closes the gap it's meant to close, on the accounts it's told to include.
