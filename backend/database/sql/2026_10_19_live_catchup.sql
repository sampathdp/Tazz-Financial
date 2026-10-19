-- Live DB catch-up for: loan document charge, FD loan settlement, FD auto-renew / post-maturity interest,
-- borrowing interest accrual, FD pre-maturity (full early) closure.
-- MySQL 8.0.16+. TAKE A BACKUP FIRST. Run statement by statement; "Duplicate column name" = already applied, skip it.
-- Equivalent to `php artisan migrate --force` for these migrations - use that instead if you can.

-- 1. 2026_10_13_000004 - FD settled against a loan
ALTER TABLE `fd_withdrawals`
  ADD COLUMN `settled_credit_facility_id` BIGINT UNSIGNED NULL AFTER `closed_at`,
  ADD CONSTRAINT `fd_withdrawals_settled_credit_facility_id_foreign` FOREIGN KEY (`settled_credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE SET NULL;

-- 2. 2026_10_17_000003 - document charge on loans
ALTER TABLE `loan_facility_details` ADD COLUMN `document_charge_amount` DECIMAL(15,2) NULL AFTER `down_payment`;

-- 3. 2026_10_18_000001 - FD auto-renew + post-maturity interest
ALTER TABLE `funding_products` ADD COLUMN `post_maturity_rate_percent` DECIMAL(6,3) NULL AFTER `premature_withdrawal_rate_percent`;
ALTER TABLE `fixed_deposits`
  ADD COLUMN `auto_renew` TINYINT(1) NOT NULL DEFAULT 0 AFTER `lien_amount`,
  ADD COLUMN `auto_renew_mode` ENUM('principal_only','principal_and_interest') NULL AFTER `auto_renew`,
  ADD COLUMN `auto_renew_term_months` SMALLINT UNSIGNED NULL AFTER `auto_renew_mode`;
ALTER TABLE `fd_withdrawals`
  ADD COLUMN `post_maturity_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `adjusted_amount`,
  ADD COLUMN `post_maturity_rate_percent` DECIMAL(6,3) NULL AFTER `post_maturity_days`,
  ADD COLUMN `post_maturity_interest` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0 AFTER `post_maturity_rate_percent`;
ALTER TABLE `fd_renewals` DROP CHECK `chk_fdr_new_principal`;
ALTER TABLE `fd_renewals`
  ADD COLUMN `interest_amount` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0 AFTER `new_principal`,
  ADD COLUMN `capitalised_interest` DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0 AFTER `interest_amount`,
  ADD COLUMN `interest_payment_method` VARCHAR(16) NULL AFTER `capitalised_interest`,
  ADD COLUMN `is_auto` TINYINT(1) NOT NULL DEFAULT 0 AFTER `interest_payment_method`;
ALTER TABLE `fd_renewals` ADD CONSTRAINT `chk_fdr_new_principal`
  CHECK (`new_principal` = `old_principal` + `capitalised_interest` + `additional_deposit_amount`);

-- cashbook_entries.source_type must allow 'FdRenewal' (only if cashbook_entries is a table, not a view).
SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cashbook_entries' AND COLUMN_NAME = 'source_type');
SET @is_table := (SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cashbook_entries');
SET @sql := IF(@is_table = 'BASE TABLE' AND @t NOT LIKE '%''FdRenewal''%',
  CONCAT('ALTER TABLE `cashbook_entries` MODIFY `source_type` ', LEFT(@t, CHAR_LENGTH(@t) - 1), ',''FdRenewal'') DEFAULT NULL'),
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. 2026_10_18_000002 - borrowing interest accrual
ALTER TABLE `borrowings`
  ADD COLUMN `interest_accrued_through` DATE NULL AFTER `maturity_date`,
  ADD COLUMN `accrued_interest_unpaid` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `interest_accrued_through`;
ALTER TABLE `borrowing_transactions`
  ADD COLUMN `accrued_interest_cleared` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `outstanding_before`;

-- accrual of existing active, non-overdraft borrowings starts today (earlier interest is not back-booked)
UPDATE `borrowings` SET `interest_accrued_through` = CURDATE()
 WHERE `status` = 'Active' AND `type` <> 'Overdraft' AND `interest_accrued_through` IS NULL;

INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '2220', 'Accrued Interest Payable - Borrowings', 'Liability', 'Credit', NULL, 1, 'Active', NOW(), NOW()
 WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '2220');

SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_entries' AND COLUMN_NAME = 'source_type');
SET @sql := IF(@t NOT LIKE '%''BorrowingAccrual''%',
  CONCAT('ALTER TABLE `journal_entries` MODIFY `source_type` ', LEFT(@t, CHAR_LENGTH(@t) - 1), ',''BorrowingAccrual'') DEFAULT NULL'),
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. 2026_10_19_000001 - FD pre-maturity (full early) closure
ALTER TABLE `fd_withdrawals`
  ADD COLUMN `is_premature` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `premature_rate_percent` DECIMAL(6,3) NULL,
  ADD COLUMN `premature_held_days` INT UNSIGNED NULL,
  ADD COLUMN `premature_clawback` DECIMAL(15,2) NOT NULL DEFAULT 0;

-- 6. Tell Laravel these migrations are done, so a later `php artisan migrate` does not try them again.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.name, (SELECT COALESCE(MAX(batch), 0) + 1 FROM (SELECT batch FROM `migrations`) b)
FROM (
  SELECT '2026_10_17_000003_add_document_charge_amount_to_loan_facility_details' AS name
  UNION ALL SELECT '2026_10_18_000001_add_fd_auto_renew_and_post_maturity_interest'
  UNION ALL SELECT '2026_10_18_000002_add_borrowing_interest_accrual'
  UNION ALL SELECT '2026_10_19_000001_add_premature_closure_to_fd_withdrawals'
) m
WHERE NOT EXISTS (SELECT 1 FROM `migrations` x WHERE x.`migration` = m.name);
