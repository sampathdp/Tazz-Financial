# Implementation Roadmap (awaiting approval — nothing implemented)

Principles: smallest change that closes each finding; reuse the existing pattern from `LoanPaymentController::store` (lock → re-validate → write → post, all in one `DB::transaction`); no rewrites; keep every existing route and response shape; no deletion of historical rows; one migration per schema change, additive where possible. Finding IDs refer to `SYSTEM_AUDIT_REPORT.md`.

Each phase lists: **Scope/changes · DB · Depends on · Risks · Validation · Tests · Acceptance**.

## Phase 1 — Critical database integrity (F-01)
- **Changes:** commit `config/database.php` engine `InnoDB`; commit migration `2026_10_31_000001_convert_myisam_tables_to_innodb.php` and `AllTablesTransactionalTest`; run the engine query on every environment; fix `database/schema/mysql-schema.dump` generation so a fresh database is InnoDB; add a boot-time/deploy check (`VerifySchema` command already exists — extend it to assert InnoDB).
- **DB:** `ALTER TABLE … ENGINE=InnoDB` on any MyISAM table (locks the table while it runs; schedule a window; take a backup first).
- **Depends on:** you confirming the target database and giving me a working connection.
- **Risks:** conversion fails if a table has FK-violating orphans (see Integrity report §8); long lock on large tables.
- **Validation:** `information_schema` shows zero non-InnoDB tables; orphan queries return zero or are reviewed.
- **Tests:** `AllTablesTransactionalTest`.
- **Acceptance:** all tables InnoDB in dev/staging/prod; test green.

## Phase 2 — Shared backend architecture
- **Changes (small, shared):**
  1. `Support/Concurrency` helper: `lockedFresh(Model $m)` = `Model::whereKey($id)->lockForUpdate()->firstOrFail()`; used to re-read status/balance **inside** each transaction.
  2. Deadlock-safe transaction wrapper: `DB::transaction($fn, 3)` on money paths (Laravel retries deadlocks) — apply through the helper, not per call site.
  3. Document a fixed lock order (parent facility row → account row → number sequence) and keep `NumberGenerator::next` as the last lock taken where possible (F-12). Evaluate making the `journal` sequence non-serialising (e.g. DB-generated id with formatted code) — **needs your decision**, it changes numbering behaviour.
  4. Extend `Idempotency` middleware to the uncovered state-changing routes listed in the audit (F-03) and review crash behaviour (in-flight key stuck).
  5. Add a DB audit table (`audit_events`: user, route, request id, entity, before/after JSON) written inside the same transaction for money-changing actions (F-14); keep the file log.
- **DB:** one new table (`audit_events`); no changes to existing tables.
- **Depends on:** Phase 1. **Risks:** retry wrapper must not wrap non-idempotent external calls (none found).
- **Validation/Tests:** unit tests for the helper; deadlock simulation (TESTING_STRATEGY G).
- **Acceptance:** helper used by Phases 3-6; no behaviour change when uncontended.

## Phase 3 — Loan module
- F-02j: lock the loan row in `approveCancelRequest`, `approveWriteOffRequest`, `close`, `clearCheque` (realize), `reschedule`, `skipInstallment`; re-check status/collection history inside the transaction.
- F-02k: in every approve/reject/void method, move the status checks inside the transaction after locking the `approval_requests` row (and payment row); make `recordApproval` run under that lock.
- F-02k `voidRequest`/`cancelRequest`: lock subject, re-check "no pending request".
- F-06: waiver void instead of delete; reverse the waiver's own entry (store `journal_entry_id` on the waiver); store the capped amount actually posted.
- F-07: forbid manual `paid_amount` edits (or post it as a payment); block charges on Closed/Cancelled loans (**CLARIFY #3**); wrap charge create/update in a transaction with loan lock.
- F-05: remove `purge` route and method in production builds (or require a separate maintenance flag plus audit row).
- F-11: guard `LoanDisbursementCompleter::complete` with a locked status check so duplicates return 422 instead of a 500.
- **DB:** add `facility_waivers.journal_entry_id`, `voided_at`, `voided_by`, `posted_amount` (nullable, additive).
- **Tests:** B, E, F, J (below). **Acceptance:** concurrent-approval and cancel-vs-payment tests green; existing `LoanLedgerTest`, `LoanLifecycleScenariosTest`, `ChequeBounceReversalTest` still pass.

## Phase 4 — Fixed Deposit module
- F-02a/b/d: lock the `fixed_deposits` row (`lockForUpdate`) at the start of `partPayment`, `renew`/`performRenewal` (also used by `fd:auto-renew`), `withdraw`, `prematureWithdraw`, `voidPartPayment`, `voidWithdrawal`; re-read principal/status inside; reject if `status != Active` or `renewed_to_id` set; `voidPartPayment` additionally requires FD `Active`.
- F-04: add `status`/`voided_at`/`voided_by` to `fd_withdrawals` and `fd_part_payments`; stop `->delete()`; adjust unique constraint on `fd_withdrawals.fixed_deposit_id` to allow a voided row (e.g. unique on `(fixed_deposit_id, active_flag)` or generated column) — **needs your decision on approach**.
- F-19: lock the interest-payment row in `pay`/`void`.
- DB: add unique `fd_renewals.old_fixed_deposit_id` only after confirming no historical duplicates.
- **Tests:** C, E, F-fd, I. **Acceptance:** two concurrent part payments → second rejected or applied on the updated principal; one renewal only.

## Phase 5 — Pawning module
- F-02e/f: lock the pawning ticket row in `renew`, `redeem`, `voidPayment`; read `outstanding_balance_cache`, status and pledged items inside the transaction.
- F-04: keep `pawning_renewals`/`pawning_redemptions` as voided rows (add `voided_at`), restore due date/items from them.
- F-02k: one shared ledger key or a status-state check so refund and forfeit of a surplus are mutually exclusive; lock the auction item in `recordSale`, `settleSurplus`, `forfeitSurplus`, `markUnsold`.
- **Tests:** D, E, F-pawn. **Acceptance:** double redemption blocked; `PawningLedgerTest` passes.

## Phase 6 — Remaining financial modules
- Savings: F-02g enforce `new balance >= min_balance` inside `SavingsAccountService::record` for `Out` movements (single enforcement point), lock sheet/run rows and re-check status in `post`/`cancel`/`voidRun`.
- Bank: F-02i lock the `bank_transactions` row and add `idempotency_key = "bank-txn-clear-{id}"` in `clear`; lock the same way in void paths.
- Finance reversal: F-02l wrap `approveReverse` in one transaction and lock the request; re-check status inside `FinancePostingService::reverse`.
- Undeposited funds: F-10 lock account 1030 (or the bank account row) before checking the cap.
- Speed drafts: lock the draft in `SpeedDraftPaymentController::destroy`, `renew`, `disburse` completion.
- Borrowings: lock in `voidTransaction`; F-04 keep row as voided.
- Commissions: F-17 lock commission row; record each payment attempt as a row.
- Fixed assets, petty cash, expenses, manual journals, cash floats: second-pass audit first (they were scanned, not read) — then apply the same pattern.
- **Tests:** E/F per module. **Acceptance:** each route in `CRUD_TRANSACTION_INVENTORY` with "LOCK N" and a state guard either fixed or documented as safe.

## Phase 7 — Customers, staff, users, admin
- F-08 wrap `UserController::store` (and `StaffController::createLogin`, `Permissions::saveFor*`) in a transaction; F-09 wrap account + COA updates.
- Documents/photos: define order (DB row after file saved, delete file after commit) — **CLARIFY** desired behaviour.
- Verify delete guards per master table (FK RESTRICT) with a test per table.
- **Acceptance:** failure injection leaves no login-without-staff and no half-updated account.

## Phase 8 — Reports, dashboards, reconciliation
- Add reconciliation commands (read-only) for loan/pawning/speed-draft `outstanding_balance_cache`, FD principal, savings balance vs rows; schedule them alongside `finance:check-drift`; record drift in `finance_audit_events`.
- Decide rounding rule (CLARIFY) and replace float comparisons in money paths with `bcmath` helpers (F-15) — incremental, per module, covered by existing ledger tests.
- Decide business time zone (CLARIFY; F-18).
- Remaining report controllers: first-time audit (not inspected).

## Phase 9 — Concurrency, idempotency, failure recovery
- Apply `withoutOverlapping()->onOneServer()` to scheduled commands (F-13); make `fd:auto-renew`, `fd-loans:auto-adjust`, `borrowings:auto-renew` lock per record and skip already processed.
- Idempotency middleware crash recovery: expire `locked_at` claims after a short timeout.
- Retry policy on deadlock (Phase 2 wrapper) verified under load.

## Phase 10 — Full regression and integrity testing
Run all 59 existing feature tests + new tests in `TESTING_STRATEGY.md` against InnoDB MySQL (not SQLite), plus Playwright smoke in `e2e/`. Run the integrity SQL from the Integrity report on a copy of production data. Release gate: zero failures, zero drift.

## Ordering and dependencies
1 → 2 → {3, 4, 5, 6 in parallel by module} → 7 → 8 → 9 → 10. Phases 3-6 each ship independently behind their own tests. Inside each, do locks first (no schema change), then voided-row migrations.

## Decisions I need from you before implementing
1. Confirm target database(s) and credentials for the engine check.
2. Journal numbering: keep the single serialising `JV` sequence, or allow a different scheme (F-12)?
3. FD/pawning/borrowing voids: confirm "keep as voided row" (recommended).
4. Remove `purge`/system-wipe from production builds?
5. The six CLARIFY questions in the Audit Report §5, plus rounding mode and business time zone.
6. Allow a second audit pass over the items marked SCAN/not-inspected before Phases 6-8?
