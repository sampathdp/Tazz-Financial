# Pawning — test plan

Code under test: `PawningController`, `PawningAuctionController`, `app/Support/PawningInterestCalculator.php`,
`PawningLedgerPoster.php`. Control accounts `1120` (capital), `4025` (interest), `2110` (surplus payable),
`1190` (shortfall/provision), `4900` (forfeiture income), `5310`/`4905` (n/a here).

Run [`ledger-invariants.md`](ledger-invariants.md) after every case.

Fixtures: P-TYPE-1 (`normal=3.0%`, `special=2.0%` within `10` days, `cycle_days=30`, `ltv=80%`), P-1, P-2ART — see [`README.md`](README.md).

---

### PWN-01 | Advance calculation — weight × rate × LTV, rounding at each step
**Type:** unit · **Priority:** P0
**Setup:** article `net_weight_grams=7.315`, `rate_per_gram=14,997.50`, `ltv_percent=80`.
**Hand computation:**
- `appraisedValue = round(7.315 × 14,997.50, 2)`. `7.315 × 14,997.50 = 14,997.50×7 + 14,997.50×0.315 = 104,982.50 + 4,724.2125 = 109,706.7125` → `round = 109,706.71`
- `maxLoanValue = round(109,706.71 × 80/100, 2) = round(87,765.368, 2) = 87,765.37`
**Expected balances after:** `facility_collateral_pawn_details.appraised_value = 109,706.71`, `.max_loan_value = 87,765.37` exactly — both rounded independently (not one derived by dividing the other).
**Risk it covers:** compounding rounding error across weight→value→LTV chain; test with a second article to confirm sums (`Σ appraisedValue`, `Σ maxLoanValue`) also land on the cent.

---

### PWN-02 | Article intake — no GL until disbursement
**Type:** integration · **Priority:** P0
**Setup:** P-1 store request.
**Steps:** `POST /pawning {...}`.
**Expected ledger entries:** the intake write (`facility_collateral` + `facility_collateral_pawn_details`, `item_status=Pledged`) and the disbursement entry both happen inside **one** `store()` transaction — but conceptually distinct:
```
Dr 1120   200,000.00
   Cr 1010-1         200,000.00
```
**Expected balances after:** `TILL-HQ -= 200,000`; `1010-1` leaf `= −200,000` movement; `1120 = 200,000.00`; `pawning.outstanding_balance_cache = 200,000.00`; article `item_status=Pledged`.
**Risk it covers:** regression on `PawningLedgerPoster::postDisbursement`; article valuation persisted even though no separate "valuation" transaction exists.

---

### PWN-03 | Full redemption at normal rate (day 15 of a 30-day cycle)
**Type:** integration · **Priority:** P0
**Setup:** P-1, disbursed `2026-03-01`, `repay_due_date = 2026-03-31` (cycleStart `2026-03-01`). Redeem on `2026-03-16` (`daysElapsed = 15`).
**Hand computation** (`PawningInterestCalculator::quote`): `daysElapsed=15 > special_rate_days=10` → rate `= 3.0%` (normal). `interest = round(200,000 × 3/100, 2) = 6,000.00`. `total = 206,000.00`.
**Steps:** `POST /pawning/{id}/redeem {redemption_type: Full, payment_method: Cash, paid_at: 2026-03-16}`.
**Expected ledger entries:**
```
Dr 1010-1   206,000.00
   Cr 4025            6,000.00
   Cr 1120          200,000.00
```
**Expected balances after:** `TILL-HQ += 206,000`; `1120` for ticket `= 0.00`; `pawning.status=Closed`, `outstanding_balance_cache=0`; article `item_status=Released`.
**Risk it covers:** rate selection at the boundary of the special window; capital+interest both clear exactly. **README top-25 #19.**

---

### PWN-04 | Special-rate window — day 10 (inclusive) vs day 11
**Type:** unit + integration · **Priority:** P0
**Setup:** P-1, cycleStart `2026-03-01`.
**Hand computation:**
- `asOf = 2026-03-11` → `daysElapsed = 10` → `10 <= special_rate_days(10)` → **special** `2.0%` → `interest = round(200,000×0.02,2) = 4,000.00`, total `204,000.00`
- `asOf = 2026-03-12` → `daysElapsed = 11` → **normal** `3.0%` → `interest = 6,000.00`, total `206,000.00`
**Expected balances after:** the two redemptions differ by exactly `2,000.00` of interest for a one-day difference — assert both entries independently.
**Risk it covers:** off-by-one at `daysElapsed <= special_rate_days`.

---

### PWN-05 | Partial redemption — proportional capital split, one article released
**Type:** integration · **Priority:** P0
**Setup:** P-2ART: Article A `net 20g@15,000` → appraised `300,000`, `maxLoanValue 240,000`; Article B `net 10g@15,000` → appraised `150,000`, `maxLoanValue 120,000`. `Σ maxLoanValue = 360,000`. `outstanding_balance_cache = 200,000`. Redeem article B only, on day 15 (normal rate).
**Hand computation** (`PawningInterestCalculator::quoteForArticles`):
- `releaseMaxLoanValue = 120,000`; `capital = round(200,000 × 120,000/360,000, 2) = round(66,666.666…, 2) = 66,666.67`
- `interest = round(66,666.67 × 3/100, 2) = round(2,000.0001, 2) = 2,000.00`
- `total = 68,666.67`
**Expected ledger entries:**
```
Dr 1010-1   68,666.67
   Cr 4025            2,000.00
   Cr 1120           66,666.67
```
**Expected balances after:** `newOutstanding = max(0, round(200,000 − 66,666.67, 2)) = 133,333.33`; article B `Released`; article A stays `Pledged`; `pawning.status` stays `Active`. `1120` for the ticket `= 133,333.33`.
**Risk it covers:** proportional-split arithmetic; ticket must NOT close while an article remains pledged.

---

### PWN-06 | Renewal / rollover — interest settled, principal not double-counted
**Type:** integration · **Priority:** P0
**Setup:** P-1, `outstanding_balance_cache=200,000`, `repay_due_date=2026-03-31`. Renew on `2026-03-20` (`daysElapsed=19`, normal rate) with a partial capital payment of `50,000`.
**Hand computation:** `interest = round(200,000×3/100,2) = 6,000.00`. `amount = interest + capitalPaid = 6,000 + 50,000 = 56,000.00`.
**Steps:** `POST /pawning/{id}/renew {capital_payment: 50000, payment_method: Cash, paid_at: 2026-03-20}`.
**Expected ledger entries:**
```
Dr 1010-1   56,000.00
   Cr 4025            6,000.00
   Cr 1120           50,000.00
```
**Expected balances after:** `newOutstanding = round(200,000 − 50,000, 2) = 150,000.00`; `1120` for ticket `= 150,000.00` (**not** `200,000` — the `50,000` principal reduction must be reflected, not just the interest); `repay_due_date` rolled to `2026-03-20 + 30d = 2026-04-19`; `pawning_renewals` row logs `capital_before=200,000`, `capital_after=150,000`.
**Risk it covers:** principal counted once on renewal (not re-added from a stale snapshot); due-date roll uses `asOf`, not `now()`, when backdated.

---

### PWN-07 | Multiple tickets per customer do not cross-contaminate
**Type:** integration · **Priority:** P1
**Setup:** same customer, two independent tickets: P-1 (`200,000`) and a second `P-2` (`80,000`, different type/rate).
**Steps:** fully redeem P-1 only.
**Expected ledger entries:** only P-1's redemption entry exists; P-2 untouched.
**Expected balances after:** `1120` reduced by exactly P-1's `200,000`; P-2's `outstanding_balance_cache`, articles, and due date are byte-for-byte unchanged; `customer.total_pawning_exposure` (if computed) reflects only P-2 remaining.
**Risk it covers:** a shared-customer query in `PawningInterestCalculator`/`quoteForArticles` accidentally summing across tickets instead of scoping to `credit_facility_id`.

---

### PWN-08 | Bank/Cheque redemption and renewal post NO ledger entry
**Type:** integration · **Priority:** P0 · **Expected result: FAIL (Risk 1)**
**Setup:** P-1, `outstanding_balance_cache=200,000`.
**Steps:**
1. `redeem {redemption_type: Full, payment_method: Bank}`.
2. On a fresh copy, `renew {capital_payment: 0, payment_method: Cheque}`.
**Expected (current):** both succeed operationally — redemption releases the article and closes the ticket (`PawningController.php:388`); renewal rolls `repay_due_date`. **`PawningLedgerPoster::postCashReceipt` returns immediately for non-Cash** (`:80`) — **zero GL entries, zero cash movement** in both cases.
**Expected balances after (current):** `1120` still shows `200,000` for a ticket that is now `Closed` and has released its gold — permanent asset overstatement; INV-04 fails.
**Target (xfail):** a GL entry equivalent to PWN-03/PWN-06 posted against the bank leaf.
**Risk it covers:** Risk 1. **README top-25 #7.**

---

### PWN-09 | Overdue ticket redeemed after default — no extra-cycle interest charged
**Type:** integration · **Priority:** P1
**Setup:** P-1, cycleStart `2026-03-01`, `repay_due_date=2026-03-31` (never renewed). Redeem `2026-06-15` — `daysElapsed = cycleStart.diffInDays(2026-06-15) = 106` (over 3 cycles late).
**Hand computation:** `quote()` applies the **same flat single-cycle rate** regardless of `daysElapsed` beyond the special window — `rate = 3.0%` (normal, since `106 > 10`), `interest = round(200,000×0.03,2) = 6,000.00`. There is **no compounding / no multiple-cycle charge** — `daysElapsed=106` and `daysElapsed=31` price identically.
**Expected ledger entries:** `Dr 1010-1 206,000.00 / Cr 4025 6,000.00 / Cr 1120 200,000.00` — identical to PWN-03 despite being ~3 cycles overdue.
**Expected balances after:** as PWN-03.
**Risk it covers:** documents that a badly-overdue ticket collects the **same** interest as a 15-day-overdue one — a real-world lender would expect additional cycles of interest or a default/auction path; this is a business-rule gap, not a posting bug, but it under-collects income at scale. Flag for product sign-off; test pins current (single-rate) behaviour so a future fix is visible as an intentional change, not silent drift.

---

### PWN-10 | Auction batch listing — snapshot only, no GL
**Type:** integration · **Priority:** P1
**Setup:** an overdue, un-renewed ticket, `outstanding_balance_cache=100,000`, current live quote `interest=9,000` (already past the cycle).
**Steps:** `POST /pawning-auctions/{id}/items {facility_collateral_ids:[...]}`.
**Expected ledger entries:** none.
**Expected balances after:** `pawning_auction_items` row created with `outstanding_capital=100,000.00`, `outstanding_interest=9,000.00` **frozen at listing time** — a later change to the ticket's live quote (e.g. a rate-table edit) must **not** move these snapshot values.
**Risk it covers:** snapshot vs live-quote drift between listing and sale.

---

### PWN-11 | Auction sale — shortfall (hammer < capital+interest)
**Type:** integration · **Priority:** P0 · **partial FAIL (Risk 3)**
**Setup:** auction item: `outstanding_capital=100,000`, `outstanding_interest=9,000` (owed `109,000`). `hammer_price=90,000`.
**Hand computation** (`PawningLedgerPoster::postAuctionSale`):
- `capitalRecovered = min(100,000, 90,000) = 90,000.00`
- `capitalShortfall = round(100,000 − 90,000, 2) = 10,000.00`
- `interestRecovered = min(9,000, max(0, round(90,000 − 90,000, 2))) = min(9,000, 0) = 0.00` (omitted)
- `surplus = max(0, round(90,000 − 100,000 − 9,000, 2)) = 0.00` (omitted)
**Expected ledger entries:**
```
Dr 1010-1 (leaf)   90,000.00
Dr 1190            10,000.00
   Cr 1120                    100,000.00
```
check: `90,000 + 10,000 = 100,000` ✓
**Expected balances after (current — the bug):** `1120` for ticket `= 0.00` (correct), **but `TILL-HQ.current_balance` is unchanged** — `postAuctionSale` calls `LedgerPoster::post` directly, never `FinancePostingService` (`PawningLedgerPoster.php:177`). INV-04 **fails**: GL till-leaf balance is `90,000` higher than `cash_accounts.current_balance`. No `cashbook_entries` row.
**Target (xfail):** the same GL entry **plus** `TILL-HQ.current_balance += 90,000.00` and a cashbook mirror.
**Risk it covers:** Risk 3. **README top-25 #5.**

---

### PWN-12 | Auction sale — surplus (hammer > capital+interest)
**Type:** integration · **Priority:** P0 · **partial FAIL (Risk 3)**
**Setup:** same item, `hammer_price=130,000`.
**Hand computation:**
- `capitalRecovered = min(100,000, 130,000) = 100,000.00`; shortfall `0.00` (omitted)
- `interestRecovered = min(9,000, max(0, round(130,000−100,000,2))) = min(9,000, 30,000) = 9,000.00`
- `surplus = max(0, round(130,000 − 100,000 − 9,000, 2)) = 21,000.00`
**Expected ledger entries:**
```
Dr 1010-1 (leaf)   130,000.00
   Cr 1120                     100,000.00
   Cr 4025                       9,000.00
   Cr 2110                      21,000.00
```
check: `130,000 = 100,000+9,000+21,000` ✓
**Expected balances after (current):** `TILL-HQ.current_balance` unchanged (same bug as PWN-11) — GL till-leaf overstates cash by `130,000`.
**Risk it covers:** Risk 3.

---

### PWN-13 | Surplus refund to the pledger — till not moved either
**Type:** integration · **Priority:** P1 · **partial FAIL (Risk 3)**
**Setup:** PWN-12's item, `surplus_amount=21,000`, `settlement_status=Pending`.
**Steps:** `POST /pawning-auctions/items/{id}/settle-surplus`.
**Expected ledger entries:**
```
Dr 2110   21,000.00
   Cr 1010-1 (leaf)      21,000.00
```
**Expected balances after (current):** `2110` for the item nets to `0.00` (correct); `TILL-HQ.current_balance` **unchanged** — the cash physically paid out to the pledger is invisible to the till (same `LedgerPoster`-direct bug). INV-04 fails.
**Risk it covers:** Risk 3.

---

### PWN-14 | Surplus forfeiture — unclaimed becomes company income
**Type:** integration · **Priority:** P1
**Setup:** an item, `surplus_amount=5,500`, `settlement_status=Pending`, past the branch's claim window.
**Steps:** `POST /pawning-auctions/items/{id}/forfeit-surplus`.
**Expected ledger entries:**
```
Dr 2110   5,500.00
   Cr 4900          5,500.00
```
No cash movement at all (correct — forfeiture is not a cash event).
**Expected balances after:** `2110` for the item `= 0.00`; `4900 += 5,500.00`.
**Risk it covers:** forfeited surplus left sitting in the liability account forever, or wrongly refunded twice.

---

### PWN-15 | Payment void restores the till and reverses the split
**Type:** integration · **Priority:** P1
**Setup:** PWN-03's redemption (`206,000` Cash).
**Steps:** void the payment.
**Expected ledger entries:** `PawningLedgerPoster::reverseAllFor` → `FinancePostingService::reverse`: `Dr 4025 6,000.00 / Dr 1120 200,000.00 / Cr 1010-1 206,000.00`.
**Expected balances after:** `TILL-HQ` reduced back by `206,000`; ticket reopens (`status=Active`, article back to `Pledged`, `outstanding_balance_cache=200,000`); original redemption entry `Reversed`, not deleted.
**Risk it covers:** void leaving the ticket "half closed" (gold not re-pledged, or capital not restored).

---

### PWN-16 | Revaluation of a pledged article — not supported
**Type:** integration · **Priority:** P2
**Setup:** P-1, article `Pledged`, `appraised_value=300,000`.
**Steps:** search for any endpoint that updates `facility_collateral_pawn_details.appraised_value`/`max_loan_value` for an already-pledged article; none exists (`PawningGoldRateController` only records daily rate quotes for **new** tickets).
**Expected:** no such endpoint; a gold-price change never touches an existing ticket's `max_loan_value`, `outstanding_balance_cache`, or the GL.
**Risk it covers:** documents the gap so a future revaluation feature is designed with a GL entry from day one (a write-up would need `Dr 1120 / Cr <a valuation-gain account>`, currently nonexistent in the chart of accounts).

---

### PWN-17 | Auction proceeds cover neither capital nor interest at all
**Type:** unit · **Priority:** P2
**Setup:** item `outstanding_capital=100,000`, `outstanding_interest=9,000`. `hammer_price=0.01` (nominal, near-zero sale).
**Hand computation:** `capitalRecovered=0.01`, `capitalShortfall=round(100,000−0.01,2)=99,999.99`, `interestRecovered=min(9,000,max(0,round(0.01−0.01,2)))=0`, `surplus=0`.
**Expected ledger entries:** `Dr 1010-1(leaf) 0.01 / Dr 1190 99,999.99 / Cr 1120 100,000.00`. Balance check: `0.01+99,999.99=100,000.00` ✓.
**Expected balances after:** `1190` absorbs almost the entire capital as loss.
**Risk it covers:** rounding/edge at near-zero proceeds; ensures no negative/zero-amount line is emitted.

---

### PWN-18 | Full redemption on the exact cycle-end day
**Type:** unit · **Priority:** P2
**Setup:** P-1, cycleStart `2026-03-01`, `repay_due_date=2026-03-31`. Redeem exactly on `2026-03-31` (`daysElapsed=30`).
**Hand computation:** `30 > special_rate_days(10)` → normal `3.0%` → `interest=6,000.00`, unchanged from PWN-03 (the calculator does not add a second cycle merely for reaching the due date — that only happens once `renew()`'s own cycle boundary is crossed, see PWN-09).
**Expected balances after:** identical entry shape to PWN-03.
**Risk it covers:** off-by-one at `repay_due_date` itself.

---

### PWN-19 | Article intake with multiple articles — sums, not per-article GL lines
**Type:** unit · **Priority:** P1
**Setup:** three articles, appraised `50,000.10`, `74,999.95`, `120,000.00` (sum `244,999.5+0.05... = 245,000.05`... use exact: `50,000.10+74,999.95+120,000.00 = 245,000.05`), `principal_amount = 196,000.04` (≤ 80% LTV of the sum, `196,000.04`).
**Steps:** disburse.
**Expected ledger entries:** a **single** entry `Dr 1120 196,000.04 / Cr 1010-1 196,000.04` — never one line per article.
**Expected balances after:** `pawning_detail.total_appraised_value = 245,000.05` (informational only — not what's posted); `1120 = 196,000.04`.
**Risk it covers:** a future refactor accidentally posting per-article, breaking the "at least 2, exactly balanced" invariant on a 3+-article ticket.

---

### PWN-20 | Default with no auction — ticket left open
**Type:** integration · **Priority:** P2
**Setup:** P-1 past `repay_due_date`, never renewed, never auctioned.
**Steps:** none — just let time pass; run a report as-of a date 6 months later.
**Expected:** `pawning.status` stays `Active` indefinitely (no automatic default/write-off job exists for pawning — confirmed absent from `app/Console/Commands` and `Kernel.php`'s schedule); `1120` stays at `200,000` and `4025` never accrues further income until someone manually redeems, renews, or auctions it.
**Risk it covers:** documents that pawning has **no scheduled default sweep** (unlike loans' daily `loans:recompute-provisioning`) — a defaulted ticket can sit unflagged indefinitely with no NPL-style provisioning against it.
