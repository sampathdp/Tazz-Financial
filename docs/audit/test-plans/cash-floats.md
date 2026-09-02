# Cash floats, tills & sessions — test plan

Code under test: `app/Support/CashFloatService.php`, `CashSessionService.php`,
`FinanceRequestService.php` (transfer/release/receive), `BankTransactionController.php`
(till-side legs), `LoanLedgerPoster`/`PawningLedgerPoster` (`postCashReceipt`).

Run [`ledger-invariants.md`](ledger-invariants.md) after every case.

Fixtures: TILL-HQ (`1010-1`, opening `500,000`), a second branch till TILL-B (Branch B, `1010-2`),
a Vault till (`1010-3`), BANK-1 (`1020-1`).

---

### CF-01 | Float issue — both ledger sides
**Type:** integration · **Priority:** P0
**Setup:** TILL-HQ `= 500,000`. Cashier "Alice", no existing float.
**Steps:** `CashFloatService::issue(homeCashAccountId=1, custodianId=Alice, amount=100,000)` → Finance approves + executes.
**Expected ledger entries** (`FLOAT_ISSUE`, via `executeTransfer`):
```
Dr 1010-{float}   100,000.00
   Cr 1010-1                100,000.00
```
**Expected balances after:** a new `cash_accounts` row (`account_type=CashierFloat`, `parent_cash_account_id=TILL-HQ`) `current_balance=100,000.00 = 1010-{float}` leaf; `TILL-HQ = 400,000.00`; a `cash_float_settlements` row `status=Open`.
**Risk it covers:** float leaf creation (`ChartOfAccount::forCashTill`); wrong source till debited.

---

### CF-02 | Cash received from a loan/pawning payment lands in the correct branch till, not a generic account
**Type:** integration · **Priority:** P0
**Setup:** two branches, HQ (till `1010-1`) and Branch-B (till `1010-2`, its own primary). A loan at Branch-B, `outstanding=12,000`; a pawning ticket at HQ, `outstanding capital=200,000`.
**Steps:** pay the Branch-B loan's instalment `12,000` Cash; separately redeem the HQ pawning ticket.
**Expected ledger entries:**
- Loan payment: `Dr 1010-2 12,000.00 / Cr 1100 10,000.00 / Cr 4010 2,000.00` (Branch-B's leaf, not HQ's).
- Pawning redemption: `Dr 1010-1 ... / Cr 4025 ... / Cr 1120 ...` (HQ's leaf).
**Expected balances after:** `TILL-HQ` untouched by the loan payment; `TILL-B` untouched by the pawning redemption. `CashAccount::branchPrimary($payment->branch_id)` resolved per-payment, not a single hardcoded till.
**Risk it covers:** a cross-branch mix-up would silently move cash into the wrong branch's till while the customer's facility still shows paid — undetectable except by till reconciliation. **README top-25 #10.**

---

### CF-03 | Float settlement — shortage posted, remainder returned
**Type:** integration · **Priority:** P0
**Setup:** CF-01's float, `current_balance=100,000.00`. Cashier counts `99,500.00` at settlement.
**Hand computation:**
- `expected = 100,000.00`, `counted = 99,500.00`, `variance = −500.00` → `shortage = 500.00`, `excess = 0.00`
- after shortage entry, `float.current_balance = 100,000 − 500 = 99,500.00`
- `returned = 99,500.00`
**Steps:** `requestSettlement()` → second approver `settle(countedAmount=99,500)`.
**Expected ledger entries** (two entries):
```
(1) ADJUSTMENT — shortage
Dr 5310            500.00
   Cr 1010-{float}          500.00

(2) FLOAT_RETURN
Dr 1010-1        99,500.00
   Cr 1010-{float}          99,500.00
```
**Expected balances after:** `float.current_balance = 0.00`, `status=Inactive`; `TILL-HQ = 400,000 + 99,500 = 499,500.00`; `5310 += 500.00`. Net: HQ started at `500,000`, ends at `499,500` — the `500` shortage is now visible in `5310`, not silently absorbed.
**Risk it covers:** shortage/excess routed to the wrong account; float not fully drained on settlement; double-counting the return.

---

### CF-04 | Till-to-till transfer (one step)
**Type:** integration · **Priority:** P0
**Setup:** TILL-HQ `= 400,000` (post CF-01). Transfer `50,000` to Vault till.
**Expected ledger entries:**
```
Dr 1010-3 (Vault)   50,000.00
   Cr 1010-1                    50,000.00
```
**Expected balances after:** `TILL-HQ = 350,000.00`; `Vault = 50,000.00`.
**Risk it covers:** `executeTransfer` endpoint resolution (`FinanceRequestService.php:337`); same-account source=dest guard (`:348`).

---

### CF-05 | Cross-branch transfer — release, receive, recall
**Type:** integration · **Priority:** P0
**Setup:** TILL-HQ (Branch A) `= 350,000`. Transfer `80,000` to TILL-B (Branch B).
**Steps & expected ledger entries:**
1. `release()`: `Dr <Branch-A in-transit leaf> 80,000.00 / Cr 1010-1 80,000.00`. `finance_requests.status=InTransit`.
2. `receive()`: `Dr 1010-2 80,000.00 / Cr <Branch-A in-transit leaf> 80,000.00`. `status=Completed`.
**Expected balances after step 2:** `TILL-HQ = 270,000.00`; `TILL-B += 80,000.00`; in-transit leaf nets to `0.00`.
**Recall variant (before receive):** `recall()` → `FinancePostingService::reverse` on the release txn: `Dr 1010-1 80,000.00 / Cr <in-transit leaf> 80,000.00`. `TILL-HQ` restored to `350,000.00`; request `Cancelled`.
**Risk it covers:** cash "in flight" between branches losing its GL trail; recall not fully unwinding the release.

---

### CF-06 | Cash session shortage and excess — posted to `5310`/`4905`, till trued to the count
**Type:** integration · **Priority:** P0
**Setup:** TILL-HQ `current_balance=400,000` at day-end. Session opened this morning, closed with two variants.
**Hand computation:**
- Shortage: `counted=399,800.00` → `variance=−200.00` → `shortage=200.00`
- Excess: `counted=400,150.00` → `variance=+150.00` → `excess=150.00`
**Steps:** `close()` then `verify()` by a **different** user than who closed.
**Expected ledger entries (shortage):**
```
Dr 5310   200.00
   Cr 1010-1        200.00
```
**Expected ledger entries (excess):**
```
Dr 1010-1   150.00
   Cr 4905           150.00
```
**Expected balances after:** shortage case: `TILL-HQ = 399,800.00` (trued to the physical count); excess case: `TILL-HQ = 400,150.00`. `cash_accounts.last_reconciled_at` updated (shortage/excess path only, `CashSessionService.php:121`).
**Risk it covers:** the till cache must equal the counted cash after verification, not the pre-count expected figure. **README top-25 #22.**

---

### CF-07 | Day-end count matches exactly — no GL posted
**Type:** integration · **Priority:** P1
**Setup:** TILL-HQ `current_balance=250,000`. Count `= 250,000.00` exactly.
**Steps:** `close()` then `verify(postVariance=true)`.
**Expected ledger entries:** **none** — `verify()`'s posting branch is skipped when `shortage==0 AND excess==0` (`CashSessionService.php:93`).
**Expected balances after:** `TILL-HQ` unchanged; session `status=Verified`.
**Risk it covers:** a false-positive shortage/excess entry on a perfectly matched count.

---

### CF-08 | Opening twice on the same day is blocked; closing twice is blocked
**Type:** unit · **Priority:** P1
**Setup:** TILL-HQ, no session today.
**Steps:**
1. `open()` → succeeds.
2. `open()` again (same till, same day) → HTTP/exception "already has an open session" (`CashSessionService.php:27`) — even from a **different** operator.
3. `close()` → succeeds.
4. `close()` again → "Only an open session can be closed" (`:52`).
**Expected ledger entries:** none at any step (open/close never post).
**Expected balances after:** exactly one `cash_sessions` row for the day.
**Risk it covers:** two operators opening the same till concurrently (see INV-09 for the till-money version); a double-close corrupting `counted_closing`.

---

### CF-09 | Negative till balance is impossible
**Type:** unit · **Priority:** P0
**Setup:** TILL-HQ `current_balance=1,000.00`, `allow_negative=false`.
**Steps:** attempt an expense payment of `5,000.00` Cash from TILL-HQ.
**Expected:** `InsufficientFundsException` — "does not have enough cash for this transaction (balance would be -4,000.00)" (`FinancePostingService.php:286`). The whole `DB::transaction` rolls back.
**Expected ledger entries:** none.
**Expected balances after:** `TILL-HQ` unchanged at `1,000.00`; no `journal_entries`, no `finance_transactions` row (not even a `Draft`).
**Contrast sub-case:** a `CashAccount` with `allow_negative=true` (e.g. a designated clearing account) — the same `5,000` payment **succeeds**, balance goes to `-4,000.00`, confirming the flag is load-bearing both ways.
**Risk it covers:** the one hard money-conservation guard in the system. **README top-25 #16.**

---

### CF-10 | Bank deposit from till
**Type:** integration · **Priority:** P0
**Setup:** TILL-HQ `= 400,000`. Deposit `150,000` to BANK-1.
**Expected ledger entries:**
```
Dr 1020-1   150,000.00
   Cr 1010-1           150,000.00
```
**Expected balances after:** `TILL-HQ = 250,000.00`; `BANK-1 = 150,000.00`.
**Risk it covers:** `BankTransactionController` Deposit + `contra_kind=Cash` wiring; both legs move atomically (`FinancePostingService`).

---

### CF-11 | Bank withdrawal to till
**Type:** integration · **Priority:** P0
**Setup:** BANK-1 `= 150,000`. Withdraw `60,000` to TILL-HQ.
**Expected ledger entries:**
```
Dr 1010-1   60,000.00
   Cr 1020-1           60,000.00
```
**Expected balances after:** `BANK-1 = 90,000.00`; `TILL-HQ += 60,000.00`.
**Risk it covers:** withdrawal direction (debit/credit sides) not swapped relative to deposit.

---

### CF-12 | A `Pending` bank transaction still moves the balance immediately
**Type:** integration · **Priority:** P1 · **partial FAIL (Risk 10)**
**Setup:** BANK-1 `= 90,000`.
**Steps:** `POST /bank-transactions {..., transaction_type: Deposit, amount: 500,000, status: Pending, contra_kind: Cash, contra_cash_account_id: TILL-HQ}`.
**Expected (current):** `Dr 1020-1 500,000 / Cr 1010-1 500,000` posts immediately; `BANK-1.current_balance = 590,000.00` **before the deposit has cleared.**
**Expected balances after (current):** `bank_transactions.status='Pending'` but `balance_after` already reflects it — an "available balance" report reading `current_balance` overstates spendable funds by `500,000` until someone manually reverses a bounced/uncleared item.
**Risk it covers:** Risk 10.

---

### CF-13 | Cash movement across a date boundary
**Type:** integration · **Priority:** P1
**Setup:** a cash session open for TILL-HQ, `business_date=2026-03-15`, spanning a graveyard shift.
**Steps:** record a loan payment `paid_at=2026-03-15 23:58:00`; record another `paid_at=2026-03-16 00:02:00`.
**Expected ledger entries:** first entry `entry_date=2026-03-15`; second `entry_date=2026-03-16` — `entry_date` follows the payment's calendar date, **not** the still-open session's `business_date`.
**Expected balances after:** the second payment lands in a day whose cash session (business_date `2026-03-15`) hasn't been closed yet — a same-day report for `2026-03-16` will show till activity before that day's session was ever opened.
**Risk it covers:** no reconciliation between `entry_date`/`paid_at` and the operational `business_date` — a graveyard-shift business would see cash split across two ledger days that don't match its one physical shift.

---

### CF-14 | Second float issue to an already-open float tops up the same account
**Type:** integration · **Priority:** P1
**Setup:** CF-01's float, `current_balance=100,000`, settlement `status=Open`.
**Steps:** `CashFloatService::issue()` again for the same custodian, `amount=20,000`, before settling.
**Expected ledger entries:** a **second** `FLOAT_ISSUE` transfer: `Dr 1010-{same float} 20,000.00 / Cr 1010-1 20,000.00` — `firstOrCreate` reuses the existing `CashAccount` and the existing `Open` `CashFloatSettlement` row (`CashFloatService.php:41-70`).
**Expected balances after:** `float.current_balance = 120,000.00`; the **same** settlement row's `issued_total` should reflect both issues (via `RecordFloatFundingOnSettled`) — assert it equals `120,000.00`, not just the second `20,000`.
**Risk it covers:** a top-up creating a second, orphaned settlement row that never gets closed, leaving `120,000` split across two open settlements neither of which reconciles.

---

### CF-15 | Segregation of duties — self-verification / self-approval blocked
**Type:** unit · **Priority:** P1
**Setup:** a closed cash session, `closed_by=UserX`; a float settlement `requested_by=UserX`, `custodian_id=UserX`.
**Steps:**
1. `UserX` attempts `verify()` on the session they closed → "The person who closed a session cannot verify it" (`CashSessionService.php:85`).
2. `UserX` attempts `settle()` on their own float → "The float holder / requester cannot verify their own settlement" (`CashFloatService.php:133`).
**Expected ledger entries:** none.
**Risk it covers:** a single operator manufacturing their own shortage/excess sign-off.

---

### CF-16 | `require_cash_session` setting gates Cash collection
**Type:** integration · **Priority:** P2
**Setup:** `FinanceSetting['require_cash_session']=true`; TILL-HQ has no open session today; a loan due `12,000`.
**Steps:** `POST /loans/{id}/payments {amount: 12000, payment_method: Cash}`.
**Expected:** HTTP **422** "Open today's cash session for TILL-HQ before recording cash payments" (`LoanPaymentController.php:91`). No entry.
**Contrast:** setting `false` (the default) — same payment succeeds with no session open at all.
**Risk it covers:** confirms the gate only fires when explicitly enabled — documents that by default, **cash can be collected with no session open**, so CF-06's reconciliation is opt-in, not enforced.

---

### CF-17 | Multiple tills at the same branch don't share balances
**Type:** unit · **Priority:** P2
**Setup:** Branch HQ has TILL-HQ (`primary`) and Vault (`secondary`, not primary).
**Steps:** a Cash loan payment at HQ; a manual cashbook adjustment against Vault.
**Expected ledger entries:** the loan payment always resolves `CashAccount::branchPrimary()` → TILL-HQ only; the Vault entry only moves Vault's leaf.
**Expected balances after:** Vault's balance/leaf completely unaffected by the loan payment, and vice versa.
**Risk it covers:** `branchPrimary()` accidentally matching more than one till, or a report summing "the branch's cash" by branch_id instead of by account.
