# Ledger invariants

These assertions run **after every scenario in every other plan file** (implemented as a shared
"assert ledger sane" helper the other suites call in `tearDown`/after each case), plus the
standalone cases below. They do not test one code path — they test that the whole ledger is
internally consistent no matter what produced it.

Shared fixtures: see [`README.md`](README.md).

---

### INV-01 | System-wide and per-entry debit = credit
**Type:** integration · **Priority:** P0
**Setup:** any populated DB (run after each scenario in every file; also run standalone against the reconciliation full-day DB, RECON-01).
**Steps:**
1. `SELECT SUM(debit) AS d, SUM(credit) AS c FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id` (all statuses).
2. For every `journal_entry_id`: `SUM(debit)` vs `SUM(credit)` for its lines.
**Expected ledger entries:** n/a — this inspects them.
**Expected balances after:**
- `d == c` exactly (compare as fixed-point strings, not floats).
- Every entry: `SUM(debit) == SUM(credit)` exactly. Zero entries fail.
- Include `Reversed` entries — a reversal is itself balanced, and the original is untouched, so the global sum still nets.
**Risk it covers:** Risk 1, 2, 3, 8 (missing/one-sided legs), regression on `LedgerPoster.php:39`.

---

### INV-02 | Trial balance nets to zero
**Type:** integration · **Priority:** P0
**Setup:** any populated DB.
**Steps:**
1. For every `chart_of_accounts` row, compute `bal = normal_balance=='Debit' ? Σdebit−Σcredit : Σcredit−Σdebit` from **Posted** lines only (mirror of `ChartOfAccount::balancesAsOf()` but recomputed independently in the test with a raw `GROUP BY`).
2. Sum, across all accounts, of `(Σdebit − Σcredit)` (signed, not normal-balance-oriented).
**Expected balances after:**
- Step 2 total `== 0.00` exactly.
- Independently: `Σ(debit-normal account balances) == Σ(credit-normal account balances)`.
**Risk it covers:** master consistency; catches any account whose lines don't have a mirror elsewhere.

---

### INV-03 | Every account balance equals the sum of its own ledger entries
**Type:** integration · **Priority:** P0
**Setup:** any populated DB.
**Steps:**
1. For each account, recompute balance from `journal_lines` with a hand-written aggregate query (do **not** call `ChartOfAccount::balancesAsOf()` — that is a function under test elsewhere).
2. Compare to `ChartOfAccount::balancesAsOf()` output for the same as-of date.
**Expected balances after:** identical to the cent for every account, for `asOf = null`, `asOf = today`, and `asOf = today-1yr`.
**Risk it covers:** regression on the one query every report trusts (`ChartOfAccount.php:135`); memoization bug (`$balancesCache`).

---

### INV-04 | Every operational record equals its ledger counterpart
**Type:** integration · **Priority:** P0
**Setup:** run after each scenario; also standalone against RECON-01 DB.
**Steps & expected balances (each an independent assertion):**

| Operational figure | Query | Must equal | Caveat encoded in the test |
|---|---|---|---|
| Total loan principal outstanding | `SUM(credit_facilities.outstanding_balance_cache WHERE facility_type='Loan' AND status IN (Active,Approved))` **minus** unpaid interest/penalty/charges component | GL `1100` balance | `outstanding_balance_cache` is all-in (`LoanPaymentAllocator.php:74`); the test must subtract the schedule's unpaid-interest + live-penalty + unpaid-charge totals to isolate principal, OR the fixture must be built so those are zero (all cases here settle interest before asserting) |
| Total speed-draft capital | analogous | GL `1110` | as above |
| Total pawning capital | `SUM(outstanding_balance_cache WHERE facility_type='Pawning' AND status='Active')` | GL `1120` | pawning cache is principal-only (no schedule) — direct equality expected |
| Each till | `cash_accounts.current_balance` | its `1010-{id}` leaf balance | exact |
| Each bank | `bank_accounts.current_balance` | its `1020-{id}` leaf balance | exact |
| Each petty cash fund | `petty_cash_funds.current_balance` | its `1015-{id}` leaf balance | exact — **currently unchecked anywhere; Risk 5** |
| FD payable | `SUM(fixed_deposits principal outstanding)` for Active FDs | GL `2010` | exact |
| Pawning surplus payable | `SUM(pawning_auction_items.surplus_amount WHERE settlement_status='Pending')` | GL `2110` | exact |
| Borrowing liability | `SUM(borrowings.outstanding_amount)` by type | GL `2200`+`2210`+`2130` | exact |

**Risk it covers:** Risks 1, 3, 5, 8, 9 — the single most valuable invariant.

---

### INV-05 | No orphan entries; no source doc without entries; no entry without a source doc
**Type:** integration · **Priority:** P0
**Setup:** run after each scenario.
**Steps:**
1. Every `journal_entries` row (except `source_type='ManualAdjustment'` and `'OpeningBalance'`) must have a resolvable source: a row exists in the table implied by `source_type` with id `source_id`.
2. Every `finance_transactions` row has a non-null `journal_entry_id` that resolves, and its `amount` equals the sum of debits on that entry's till/bank/leaf line for a single-movement txn.
3. Every `payment_vouchers`, `petty_cash_vouchers`, `petty_cash_replenishments`, `bank_transactions` row with a non-null `journal_entry_id` resolves to a Posted-or-Reversed entry.
4. Reverse direction: every non-voided `facility_payments` (Cash method), every `expenses` with `status=Paid`, every `fd_deposits` (Cash), every completed `finance_requests` (non-transfer, non-zero) has **at least one** `journal_entries` row keyed to it.
**Expected balances after:** all four checks return zero rows.
**Risk it covers:** Risk 1 (paid operational record, no GL), dangling FK from `purge()` (`LoanController.php:398`).

---

### INV-06 | No null / zero-sum / impossible-negative / unbalanced amounts
**Type:** unit + integration · **Priority:** P0
**Setup:** any populated DB.
**Steps:**
1. `journal_lines`: no row with `debit IS NULL OR credit IS NULL`; no row with `debit=0 AND credit=0`; no row with `debit<0 OR credit<0` (DB CHECK `chk_journal_lines_one_side` should already forbid all of these — the test asserts the CHECK is present and active by attempting a raw insert of `(debit=0,credit=0)` and expecting a driver exception).
2. `finance_transactions`: no `amount <= 0` (CHECK `chk_ftx_amount`); assert by raw insert attempt.
3. `LedgerPoster::post([...],...)` with two lines summing to different totals throws `RuntimeException` (`LedgerPoster.php:39`) — unit test with literal lines `[{acct:1,debit:100},{acct:2,credit:99.99}]`.
4. `LedgerPoster::post([...])` with a single line throws `InvalidArgumentException` (`LedgerPoster.php:29`).
**Expected balances after:** unchanged (all attempts rejected).
**Risk it covers:** regression on both structural guards.

---

### INV-07 | Reversals write contra entries; originals are never mutated or deleted
**Type:** integration · **Priority:** P0
**Setup:** post any entry E via a real flow (e.g. EXP-04 expense payment). Snapshot E's rows: `journal_entries` row + all `journal_lines` (id, account_id, debit, credit, description).
**Steps:**
1. Trigger the reversal path for E (void the voucher).
2. Re-read E's `journal_entries` row and lines.
3. Read the new reversal entry R.
**Expected ledger entries:**
- E's lines: **byte-for-byte identical** to the snapshot (same ids, same amounts).
- E's `journal_entries.status` changed `Posted → Reversed`; `reversal_of_id` still null on E.
- R has the same account set as E with `debit`/`credit` swapped per line (`LedgerPoster.php:83`), `R.reversal_of_id == E.id`, `R.status='Posted'`.
- `E.lines.count == R.lines.count`; for each account: `E.debit_a == R.credit_a` and `E.credit_a == R.debit_a`.
- No `DELETE` occurred on `journal_entries`/`journal_lines` (assert row count only grew).
**Expected balances after:** every account touched by E is back to its pre-E balance (E + R net to zero per account).
**Risk it covers:** "posted entry edited/deleted instead of reversed"; regression on `LedgerPoster::reverse`.

---

### INV-08 | Rounding-drift harness — 1,000 randomized transactions net to exactly zero
**Type:** integration · **Priority:** P0
**Setup:** fresh DB. Seed BR-HQ, TILL-HQ, BANK-1, COA, LT-FLAT, LT-RB, P-TYPE-1, one FD product, EXP-CAT-1, PCF-1. Seed TILL-HQ with a large opening balance (say 100,000,000 via OB-01) so nothing hits `InsufficientFundsException`.
**Steps:**
1. Deterministic PRNG, fixed seed. Generate 1,000 operations drawn from: loan disbursement (Cash), loan Cash payment, pawning disbursement, pawning Cash renewal, pawning Cash redemption, Cash expense payment, petty-cash spend + top-up, cash session shortage/excess, till→bank deposit, bank→till withdrawal, one-step transfer.
2. **Constrain the mix so the ledger can net to zero deterministically:** no Bank/Cheque collections (Risk 1 would leave a real hole), no principal waivers (Risk 8), no write-offs, no auctions (Risk 3). If those must be included, the test asserts the *known* residual (Σ of those amounts) instead of zero.
3. Awkward inputs: principals like `133,337.77`, rates like `1.83%`, `2.66%`, weights like `7.315g`, `@ 14,997/g`, terms `7`, `11`, `13` months, payment amounts that are odd fractions of the instalment.
4. After all 1,000: run INV-01, INV-02, INV-03, INV-04.
**Expected balances after:**
- `Σ all debits − Σ all credits == 0.00` exactly.
- `Σ(till current_balance) + Σ(bank current_balance) + Σ(petty cash current_balance)` == `Σ(GL leaf balances 1010-*, 1020-*, 1015-*)` exactly.
- `Σ(loan outstanding principal) == 1100`, `Σ(pawning outstanding) == 1120` (both after netting interest per INV-04).
- Per-account: independent recompute == `balancesAsOf()`.
**Risk it covers:** float vs bcmath precision mixing (`LoanAmortizer`/`LoanPaymentAllocator` use float `round`; `FinancePostingonService` uses bcmath); "last row absorbs drift" logic in `LoanAmortizer.php:114`, `InterestAccrualSchedule.php:54`.

---

### INV-09 | Concurrency — two simultaneous float movements on the same till
**Type:** integration · **Priority:** P0
**Setup:** TILL-HQ `current_balance = 500000`. Two approved `finance_requests` transfers, each `amount=300000`, source TILL-HQ, different destinations.
**Steps:**
1. Execute both `FinanceRequestService::execute()` concurrently (two DB connections / threads).
**Expected ledger entries:** two `TRANSFER` entries **or** one entry + one `InsufficientFundsException` — never two entries that overdraw.
**Expected balances after:**
- If both succeed: impossible here (500k − 600k < 0) → exactly one succeeds, `TILL-HQ = 200000`, its GL leaf `= −200000` credit... wait: `500000 − 300000 = 200000`; the second must fail with `InsufficientFundsException` (`FinancePostingService.php:285`).
- `cash_accounts.current_balance` for TILL-HQ `== 200000`; its `1010-1` leaf balance `== 200000`.
- Exactly one `finance_transactions` row for the pair that touched TILL-HQ as source; the failed request stays `ReadyForPayment` (its `DB::transaction` rolled back).
- No lost update: the successful destination's leaf `== 300000`.
**Risk it covers:** lost update / double spend on a till; verifies `lockForUpdate()` at `FinancePostingService.php:280`,`:294` actually serializes.

---

### INV-10 | Concurrency — two simultaneous payments on the same loan / pawning ticket
**Type:** integration · **Priority:** P0
**Setup:** L-FLAT-1 disbursed and Active; instalment 1 due `12,000` (principal 10,000 + interest 2,000), nothing paid. TILL-HQ balance large.
**Steps:**
1. Fire two identical `POST /loans/{id}/payments` `{amount: 12000, payment_method: Cash}` concurrently.
**Expected ledger entries:** exactly **one** COLLECTION set:
```
Dr 1010-1 (TILL-HQ)   12,000.00
   Cr 1100                       10,000.00   (principal_applied)
   Cr 4010                        2,000.00   (interest_applied)
```
**Expected balances after:**
- Instalment 1 `status=Paid`, `paid_amount = 12,000.00` (**not** 24,000, not 12,000-with-two-allocation-rows).
- Exactly one `facility_payments` row **or**, if two rows exist, the second must have been rejected before allocation and posted nothing (its `LoanPaymentAllocator::applyAllocation` must not have run — currently there is **no row/loan lock**, so this case is expected to FAIL and document the bug: two allocation rows, `1100` credited 20,000, schedule `paid_amount` only 12,000 from a last-write-wins update).
- `1100` balance == loan principal outstanding (INV-04).
- `TILL-HQ` moved by exactly 12,000.
**Risk it covers:** lost update on `facility_schedules.paid_amount`; double GL posting; `LoanPaymentController.php:153` transaction does not `lockForUpdate` the loan or its schedules.

---

### INV-11 | Idempotency — replay the same payment request and the settled-event twice
**Type:** integration · **Priority:** P0
**Setup:** FD-M product; a customer; TILL-HQ large. Also L-FLAT-1 Active.
**Steps:**
1. **HTTP replay:** submit `POST /fd-deposits {amount: 500000, payment_method: Cash, ...}` twice with an identical body (simulating a client retry).
2. **Event replay:** for a request-path loan disbursement, dispatch `FinanceRequestSettled($request,'paid')` twice into `CompleteDisbursementOnSettled`.
3. **Regular loan payment replay:** submit `POST /loans/{L-FLAT-1}/payments {amount: 12000, Cash}` twice.
**Expected ledger entries:**
- FD deposit: **currently FAILS** — two `fd_deposits` rows, two `Dr 1010-1 500,000 / Cr 2010 500,000` entries, TILL overstated 500,000. Test asserts the *desired* behaviour (one entry) and is marked `xfail` against Risk 6 until an HTTP idempotency key exists.
- Event replay: **one** disbursement completion — second call is a no-op because `loan.status !== 'Approved'` (`CompleteDisbursementOnSettled.php:56`). Assert schedule row count unchanged, one GL disbursement entry, `finance_requests.status='Completed'` (not re-processed). This one should PASS.
- Regular loan payment: second call returns HTTP 422 "Amount exceeds the outstanding balance" (`LoanPaymentController.php:127`) because instalment 1 is now paid — one COLLECTION entry only. PASSES for the happy fixture; add a variant where `amount` is interest-only and outstanding stays high → second call succeeds → documents the gap.
**Expected balances after:** where the guard holds — one set of entries, balances moved once.
**Risk it covers:** Risk 6. Note: **no webhooks exist in this system** — the nearest external trigger is `FinanceRequestSettled`; there is no payment-gateway callback to replay.

---

### INV-12 | Full scenario suite is deterministic — rerun twice on a fresh DB, identical ledger
**Type:** integration · **Priority:** P0
**Setup:** the complete case list from every plan file, executed in a fixed order, under CLOCK (frozen time). `number_sequences` empty (fresh DB) so codes restart at 1.
**Steps:**
1. Run the full suite on fresh DB #1. Export `(je.code, je.entry_date, je.source_type, je.source_id, je.status, jl.account_id, jl.debit, jl.credit)` ordered deterministically.
2. Drop, migrate fresh DB #2. Run the identical suite.
3. Diff the two exports.
**Expected balances after:** exports are identical row-for-row. `journal_entries.code` values match (`JV-26-000001…`), `finance_transactions.code` match (`FTX-26-000001…`).
- `created_at`/`updated_at`/`posted_at` are excluded from the diff (wall-clock).
**Risk it covers:** hidden non-determinism (unpinned `now()`, map ordering, PRNG leakage), sequence-reset assumptions.

---

### INV-13 | Reversal of a reversal is rejected
**Type:** unit · **Priority:** P1
**Setup:** an entry E, reversed to R (INV-07).
**Steps:** call `LedgerPoster::reverse(R)` and `FinancePostingService::reverse(R_ftx)`.
**Expected:** `RuntimeException` "Only a posted journal entry can be reversed" (`LedgerPoster.php:79` — R... wait R is Posted). Precisely: `LedgerPoster::reverse` only checks `status==='Posted'`, so it would *allow* reversing R. `FinancePostingService::reverse` checks `reversal_of_id` and throws (`FinancePostingService.php:208`). Assert FPS blocks it; document that `LedgerPoster::reverse` does **not** block re-reversal of a reversal entry (P1 gap).
**Expected balances after:** unchanged.
**Risk it covers:** double-reversal inflating the ledger.

---

### INV-14 | Backdated / future-dated postings land on the entry_date given, and nothing stops them
**Type:** integration · **Priority:** P1
**Setup:** L-FLAT-1 Active. CLOCK = 2026-03-01.
**Steps:**
1. Record a Cash payment with `paid_at = 2025-11-15` (prior year).
2. Record a manual journal entry with `entry_date = 2030-01-01`.
3. Run Trial Balance as-of `2025-12-31` and as-of `2026-03-01`.
**Expected ledger entries:** the payment's `journal_entries.entry_date = 2025-11-15`; the MJE's `= 2030-01-01`. Both post successfully.
**Expected balances after:**
- As-of `2025-12-31` TB **includes** the backdated payment (it moves a prior-period figure).
- As-of `2026-03-01` TB **excludes** the future MJE.
- No error, no warning, no block anywhere.
**Risk it covers:** Risk 7 — documents that there is no period lock; this test is the regression anchor if one is added.

---

### INV-15 | Journal `source_type` enum tolerance
**Type:** unit · **Priority:** P2
**Setup:** —
**Steps:** attempt `LedgerPoster::post(lines, date, ['source_type' => 'NotAThing'])`.
**Expected:** under MySQL `STRICT_TRANS_TABLES` → driver exception (good). Without strict mode → silently stores `''`. Assert the app's DB connection runs in strict mode (`config('database.connections.mysql.strict') === true`).
**Risk it covers:** silent loss of source linkage on an unlisted `source_type`.
