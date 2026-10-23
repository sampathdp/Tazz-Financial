-- FD interest payout voucher number (mirrors migration 2026_10_23_000001_add_voucher_no_to_fd_interest_payments).
-- Run once on live. Safe to run before deploying the new code.

ALTER TABLE `fd_interest_payments`
  ADD COLUMN `voucher_no` VARCHAR(32) NULL AFTER `installment_no`,
  ADD UNIQUE KEY `fd_interest_payments_voucher_no_unique` (`voucher_no`);

ALTER TABLE `number_sequences` MODIFY `module` ENUM('loan','customer','payment','fd_deposit','fixed_deposit','fd_withdrawal','fd_part_payment','journal','finance_transaction','finance_request','manual_journal','expense','payment_voucher','petty_cash_fund','petty_cash_replenishment','petty_cash_voucher','speed_draft','speed_draft_payment','pawning','pawning_auction','borrowing','borrowing_txn','savings_account','savings_txn','savings_collection','savings_interest','fd_interest') NOT NULL;

ALTER TABLE `document_number_log` MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher','Borrowing','BorrowingTransaction','ManualJournalEntry','FinanceTransaction','FinanceRequest','SavingsAccount','SavingsTransaction','SavingsCollectionSheet','SavingsInterestRun','FdInterestPayment') DEFAULT NULL;

-- Mark the migration as applied so a later `php artisan migrate` does not re-run it.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_23_000001_add_voucher_no_to_fd_interest_payments', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
