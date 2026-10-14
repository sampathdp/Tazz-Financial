# 06 Time-based scenarios

Time-sensitive features exist: cash-session business-day gate, FD maturity and interest due dates, loan instalment due dates, grace days and penalties, pawning cycle dates, token and session expiry, scheduled drift and provisioning jobs, auction surplus forfeiture window.

**Status: not executed in this session.** No deterministic-clock scenarios (before, at and after a deadline, midnight, month and year end, leap day, timezones, late or duplicate scheduled jobs) were run, so none is claimed as passed.

Existing automated coverage found, not re-verified for boundaries: stale cash session rejected (`CashLedgerAuditProbeTest`), session expiry (`AuthSessionProbeTest`), loan lifecycle scenarios (`LoanLifecycleScenariosTest`).

Recommended next: `Carbon::setTestNow` tests for the instalment grace-day boundary, FD maturity day and the session gate at midnight, and Playwright `page.clock` for the session-expiry UI.
