# Financial System Security Audit

Date 2026-11-01. This pass did not recompute balances, replay transactions or run the financial test suite. It reviews controls visible in code and cross-references earlier project documents (`CRUD_TRANSACTION_INVENTORY.md`, `DATABASE_INTEGRITY_REPORT.md`, `SYSTEM_AUDIT_REPORT.md`) **without having re-verified their claims**.

## A. Transaction protection

| Control | Evidence | Status |
|---|---|---|
| Atomic multi-step writes | 243 `DB::transaction` / lock call sites; services (`FinanceRequestService`, posting services) wrap approval + posting in one transaction | Sampled, not exhaustive |
| Concurrency control | `lockForUpdate` in 37 files; `FinanceRequestService::approve` locks status and approval row before posting; `ConcurrencyTest`, `DeadlockRetryTest` exist | Sampled |
| Duplicate submission | `Idempotency` middleware (key + method + path + body fingerprint, 24 h) applied to 71 of 261 write routes; only active when client sends `Idempotency-Key` | Confirmed; **optional**, so a client that omits the header is unprotected |
| Client-calculated balances | Not exhaustively checked. `current_balance` is mutated by raw `+`/`-` updates inside transactions in `LoanController` purge path | Needs verification per payment endpoint |
| Maker-checker | Approval registry; self-approval blocked in `FinanceRequestService::approve` | Verified for finance requests; others pattern-matched |

Gap: 190 write routes have no idempotency middleware, and I have not classified which of them post money. Action: list write routes that post money (loan payments, FD deposits/withdrawals/interest, pawning redemption, bank/cash transactions, expenses, vouchers) and either require the `Idempotency-Key` header or confirm a DB unique constraint prevents double posting.

## B. Record protection and reversals
- Reversals exist as a governed flow (`finance-transaction-reverse-requests`, loan payment void requests, cheque bounce reversal tests), consistent with preserving history.
- **Conflicting controls:** `DELETE loans/{loan}` (`perm:Loan Applications,Delete`), `DELETE loans/{loan}/purge` (admin, hard delete with FK checks off), `POST system/clean-transactions` (admin, truncation) allow destroying posted history. Whether `LoanController::destroy` refuses loans that have payments/ledger entries was **not read**.
- `LoanCharge`, `LoanPostDatedCheque`, document and bank-detail deletes are permission-gated deletes; whether they soft-delete or keep an audit row was not checked.

## C. Customer data protection
- Customer documents and photos use `DocStore` private disk with authenticated download (`Storage::disk(...)->response`); route-level `perm:Customer Management,*`. Legacy public-disk files may remain (F-15).
- Exports are done client-side from API data (`xlsx`, print windows); there is no server export route to restrict, so export control = `View` permission on the data. A user with `View` can export everything they can load. Accepted design; flag for management.
- Logs: request log middleware (`LogRequests`) and `AuditWrites` strip `password`, `token`, `confirm_phrase` keys. Other PII keys (NIC, phone, account numbers) are **not** redacted in the audit payload (`AuditWrites.php:49`): **Potential** PII-in-logs. Verify what is stored.

## D. Audit logging
- Security channel (auth events), `AuditWrites` (all successful writes, user, path), `FinanceAuditEvent` model with `finance-audit` route (`perm:Finance Audit,View`).
- Log retention configured via `LOG_*_DAYS`. Whether audit rows are in a table that application roles can edit/delete was **not checked**; no route found that edits finance audit events by name.
- Gap: no evidence of alerting on repeated `permission_denied` or `login_failed`.

## Calculations
No financial calculation logic was inspected or modified. None of the proposed security changes touch interest, penalty or balance computation.
