# Loan Calculations Reference

Source files:
- `app/Support/LoanAmortizer.php` — schedule generation (Flat / Reducing Balance)
- `app/Support/LoanSummary.php` — OD (default charge/penalty), arrears, contract status
- `app/Support/LoanPaymentAllocator.php` — payment waterfall, outstanding balance, early settlement
- `app/Support/InterestAccrualSchedule.php` — day-count accrual/proration
- `app/Support/AerCalculator.php` — Flat → Effective Rate back-solve
- `app/Models/LoanType.php` — per-product rates (`interest_rate_percent`, `default_charge_rate_percent`, `grace_period_days`, `processing_fee_percent`)

---

## 1. Interest calculation methods

Set per loan via `loanDetail.interest_method`: `flat` or `reducing_balance`.

Config:
- `principal` = `principal_amount - down_payment` (only financed amount is amortized)
- `monthlyRate` = `interest_rate_percent` if `rate_period = monthly`, else `interest_rate_percent / 12`
- `n` = number of installments = `term_months * periodsPerMonth` (periodsPerMonth: daily=30 or 20 if weekdays-only, weekly=4, monthly=1)
- `periodRate` = `(monthlyRate / 100) / periodsPerMonth`

### 1.1 Flat Rate

Equal principal per installment. Total interest = simple interest on full original principal for the whole term, split evenly.

```
totalInterest = principal * (monthlyRate / 100) * term_months
principalPerInstallment = principal / n
interestPerInstallment  = totalInterest / n
```

**Example:** principal = 100,000, monthlyRate = 2%, term = 12 months, monthly installments (n=12)
```
totalInterest = 100,000 * 0.02 * 12 = 24,000
principalPerInstallment = 100,000 / 12 = 8,333.33
interestPerInstallment  = 24,000 / 12 = 2,000.00
Installment total = 10,333.33 (same every month)
```
Last installment absorbs rounding drift so totals reconcile exactly.

### 1.2 Reducing Balance

Standard amortizing annuity — equal total installment, interest computed on the declining balance each period.

```
installment = principal * periodRate * (1+periodRate)^n / ((1+periodRate)^n - 1)
```
Per period:
```
interest[i]   = balance * periodRate
principal[i]  = installment - interest[i]   (last row = remaining balance, absorbs rounding)
balance       = balance - principal[i]
```

**Example:** principal = 100,000, monthlyRate = 2%, n = 12, periodRate = 0.02
```
installment = 100,000 * 0.02 * 1.02^12 / (1.02^12 - 1) ≈ 9,455.96

Period 1: interest = 100,000*0.02 = 2,000.00 ; principal = 9,455.96-2,000.00 = 7,455.96 ; balance = 92,544.04
Period 2: interest = 92,544.04*0.02 = 1,850.88 ; principal = 7,605.08 ; balance = 84,938.96
... interest shrinks, principal portion grows each period, installment stays ~9,455.96
```

### 1.3 AER (Annual Effective Rate) — informational only

For Flat-rate loans, back-solves the reducing-balance rate `r` that would produce the same EMI, via bisection (no closed form). Never used for pricing — display only, per [[aer_calc_exact_not_approx]].

```
solve for r:  principal * r * (1+r)^n / ((1+r)^n - 1) = periodicRental
monthlyRate = (1+r)^periodsPerMonth - 1
annualRate  = (1+monthlyRate)^12 - 1
```
Client's own "Approx" table will diverge ~0.1–0.3pp from this — expected, this calc is exact.

---

## 2. Default Charges / OD (Overdue Penalty)

Config per loan type: `grace_period_days` (default 5), `default_charge_rate_percent` (a MONTHLY rate).

**Not persisted** — recalculated live every view and every real payment, so display and collection can never disagree.

Rules:
- Base = that installment's own unpaid balance (`principal_due + interest_due - paid_amount`), NOT the whole loan's outstanding principal.
- Charge starts accruing `grace_period_days` after `due_date` (i.e. `due_date + grace_period_days`).
- Monthly rate is prorated to a daily rate using the ACTUAL days in each calendar month spanned (not flat 30-day months), applied simple (non-compounding) per day elapsed.

```
overdueBalance = principal_due + interest_due - paid_amount   (floor 0)
chargeStart    = due_date + grace_period_days
if today <= chargeStart or overdueBalance <= 0 or rate <= 0: penalty = 0

dailyBase = overdueBalance * (rate / 100)     # rate applied once per "month equivalent"
penalty = sum over each calendar month segment spanned:
    (dailyBase / daysInThatMonth) * daysInSegment
```

**Example:** installment overdueBalance = 10,000, `default_charge_rate_percent` = 3% (monthly), grace = 5 days, due_date = 2026-06-01, today = 2026-08-22.

```
chargeStart = 2026-06-06
dailyBase = 10,000 * 0.03 = 300   (this is the "per full month" charge)

Segment 1: 2026-06-06 -> 2026-07-01 (25 days, June has 30 days)
  300/30 * 25 = 250.00
Segment 2: 2026-07-01 -> 2026-08-01 (31 days, July has 31 days)
  300/31 * 31 = 300.00
Segment 3: 2026-08-01 -> 2026-08-22 (21 days, August has 31 days)
  300/31 * 21 = 203.23

Total penalty = 250.00 + 300.00 + 203.23 = 753.23
```

Penalty due is netted against penalty already collected (`penalty_applied` on active, non-voided allocations) to get remaining/outstanding OD. Waivers of type `Penalty` reduce it further.

---

## 3. Payment waterfall (standard allocation order)

`LoanPaymentAllocator::applyAllocation()` — for each unpaid installment, oldest first, a payment is applied in this fixed order until the payment amount is exhausted:

```
1. Interest (interest_due of the installment)
2. OD / Default Charge (live penalty as of payment date)
3. Other charges (Document / Legal / Valuation / Other — oldest charge first, across whole loan)
4. Principal
```
Then moves to the next oldest installment and repeats. `total_due`/`status` on the schedule row only reflects principal+interest (penalty is priced/collected separately, never part of `total_due`).

**Example:** Installment due = interest 2,000 + principal 8,333.33 = 10,333.33; OD accrued = 753.23; payment received = 5,000.

```
Step 1 - Interest: due 2,000, pay 2,000 → remaining 3,000
Step 2 - OD: due 753.23, pay 753.23 → remaining 2,246.77
Step 3 - Other charges: none due → remaining 2,246.77
Step 4 - Principal: due 8,333.33, pay 2,246.77 → remaining 0
Result: installment status = Partial (principal still owes 6,086.56); OD fully cleared.
```

### 3.1 Manual allocation
`applyManualAllocation()` lets a cashier split payment into explicit buckets (`interest`, `od_charge`, `capital`, `other_charges`) instead of the waterfall — each bucket independently applied oldest-first, skipping buckets not chosen even if due.

---

## 4. Outstanding balance

```
outstanding = unpaid(principal+interest across schedules)
            + unpaid OD (live penalty as of date, per installment)
            + unpaid other charges
            - total waivers
            (floored at 0)
```

## 5. Early settlement (accrued interest only)

Client rule: on early full settlement, only collect interest ACCRUED to date on each unpaid future installment; forgive the rest (auto-waived), rather than collecting full scheduled interest.

Accrual within an installment's period `[periodStart, dueDate)` is elapsed-day proportional:
```
accruedThrough(asOf) = interestDue * (daysElapsed(periodStart, asOf) / daysElapsed(periodStart, dueDate))
```
capped so it never waives interest already collected (`floor = max(accrued, alreadyPaid)`), and floored so nothing is waived below what's actually accrued.

**Example:** installment interest_due = 2,000, period = 2026-06-01 → 2026-07-01 (30 days), settlement date = 2026-06-16 (15 days elapsed).
```
accrued = 2,000 * (15/30) = 1,000.00
waived  = 2,000 - 1,000 = 1,000.00   → written to waived_interest, interest_due reduced to 1,000
```
OD/penalty and other charges are still charged in full up to the settlement date; only future-interest is discounted this way.

---

## 6. Charges (Document / Legal / Valuation / Other, dynamic via ChargeCategory)

- `processing_fee_percent` on the loan type, if > 0, requires a "Document" category charge to exist and be fully paid before disbursement (`LoanController::disburse()`).
- Charges are independent of the schedule; each has its own `due_amount`/`paid_amount`, paid oldest-`charged_at`-first in the waterfall's step 3.
- Waivers of type `Charge` are not attributable to a specific charge (no FK link) — only netted into the aggregate arrears figure, not the per-category breakdown.

---

## 7. Arrears / Contract Status roll-up (`LoanSummary::build`)

```
rentalArrears      = overdue unpaid principal + overdue unpaid interest (net of Principal/Interest waivers)
otherChargeArrears = unpaid charges (net of Charge waivers)
defaultCharges     = overdue unpaid OD (net of Penalty waivers)
totalArrears       = rentalArrears + otherChargeArrears + defaultCharges
overPayment        = max(0, totalPaid - totalDueToDate)
balancePrePayment  = max(0, totalArrears - overPayment)
amountToBeCollected= balancePrePayment + futureCapital + futureInterest
```
`daysOverdue` = days since the oldest unpaid installment's due_date. `overdueInstallments` is fractional (a 60%-paid installment counts as 0.4, not 1).

Same overdue definition (`due_date < today AND status != 'Paid'`) is shared by the Loan Detail page and the Overdue Loans report, so the two screens never disagree.
