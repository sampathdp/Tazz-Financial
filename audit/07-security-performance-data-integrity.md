# 07 Security, performance and data integrity

## Confirmed and fixed
- Cross-branch read and write (BUG-002, BUG-003).
- Accounting reports wrong (BUG-004 to BUG-006).

## Checked and sound (code reading, existing tests, one UI observation)
Login throttling, per-route permission middleware, strong-password rule (a 422 for `password123` was observed in the UI run), token revocation on credential or branch change (existing `AuthSessionProbeTest`), `npm audit --omit=dev`: 0 vulnerabilities (run in this session).

## Confirmed defects not fixed
Commission payment: uncapped amount, hand-moved till balance (C-11). Silent skipped postings (C-8). See the artifact.

## Potential risks (not tested)
CSRF and CORS configuration, file upload paths, SQL injection beyond existing tests, XSS in customer text fields, rate limiting under load, report query cost on large journal tables (balances are recomputed by scanning journal_lines on every request).

## Performance
Not measured. The only observation: the Playwright run took 1.9 minutes for 25 tests on a local machine. No endpoint timings were collected.

## Data integrity
Ledger invariants (balanced entries, reversal netting, idempotency) are covered by existing PHPUnit tests, all passing (213/213). No production data was examined.
