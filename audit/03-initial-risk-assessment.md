# 03 Initial risk assessment

Ranked before testing, from code reading (details in `docs/audit/` and the audit artifact):

1. Branch isolation gaps, read and write (security, P1).
2. Accounting reports wrong: cash flow blank, balance sheet provision sign, P&L waterfall dropping accounts (reporting integrity, P1).
3. Commission payment bypasses the money writer, amount uncapped (P1).
4. Silent skip of ledger posting when no till exists (P1).
5. Bank/cheque receipts parked in 1030 with no clearing step (P2).
6. No period lock, no inter-branch accounting (design gaps).
7. E2E harness drift: UI tests could not run (P3, but blocks UI regression).
