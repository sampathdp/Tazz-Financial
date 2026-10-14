-- =====================================================================
-- Deploy script: 3 migrations dated 2026_10_14_*. Apply to LIVE database.
-- Equivalent to: php artisan migrate --force
-- Run 2026_10_13_loan_against_fd.sql first if not yet applied.
-- BACK UP THE DATABASE FIRST. Run once, top to bottom. Not re-runnable
-- (ADD COLUMN errors on 2nd run = already applied).
-- =====================================================================

-- 2026_10_14_000001: Document charge category -> 4910 Documentation Income (idempotent)
UPDATE `charge_categories`
SET `chart_of_account_id` = (SELECT `id` FROM `chart_of_accounts` WHERE `code` = '4910' AND `account_type` = 'Income' LIMIT 1)
WHERE `name` = 'Document'
  AND `chart_of_account_id` IS NULL
  AND EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '4910' AND `account_type` = 'Income');

-- 2026_10_14_000002: cash_accounts.purpose
ALTER TABLE `cash_accounts`
  ADD COLUMN `purpose` varchar(24) NULL AFTER `account_type`,
  ADD INDEX `cash_accounts_branch_id_purpose_index` (`branch_id`, `purpose`);

-- 2026_10_14_000003: bank_account_id on FD / savings movements
ALTER TABLE `fd_deposits`
  ADD COLUMN `bank_account_id` bigint unsigned NULL,
  ADD CONSTRAINT `fd_deposits_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL;
ALTER TABLE `fd_interest_payments`
  ADD COLUMN `bank_account_id` bigint unsigned NULL,
  ADD CONSTRAINT `fd_interest_payments_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL;
ALTER TABLE `fd_withdrawals`
  ADD COLUMN `bank_account_id` bigint unsigned NULL,
  ADD CONSTRAINT `fd_withdrawals_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL;
ALTER TABLE `fd_part_payments`
  ADD COLUMN `bank_account_id` bigint unsigned NULL,
  ADD CONSTRAINT `fd_part_payments_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL;
ALTER TABLE `savings_transactions`
  ADD COLUMN `bank_account_id` bigint unsigned NULL,
  ADD CONSTRAINT `savings_transactions_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL;

-- Record migrations so a later `php artisan migrate` skips them
INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.`migration`, (SELECT MAX(`batch`) FROM (SELECT `batch` FROM `migrations`) b) + 1
FROM (
  SELECT '2026_10_14_000001_map_document_charge_to_documentation_income' AS `migration`
  UNION ALL SELECT '2026_10_14_000002_add_purpose_to_cash_accounts'
  UNION ALL SELECT '2026_10_14_000003_add_bank_account_to_fd_and_savings_movements'
) m;

-- Verify
SHOW COLUMNS FROM `cash_accounts` LIKE 'purpose';
SHOW COLUMNS FROM `savings_transactions` LIKE 'bank_account_id';
