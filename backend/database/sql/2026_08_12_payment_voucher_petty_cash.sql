-- Payment Voucher void fields + Petty Cash module
-- Run in order. Matches migrations 2026_08_12_000001 .. 000007.

-- 1) payment_vouchers: void audit columns
ALTER TABLE `payment_vouchers`
  ADD COLUMN `void_reason` VARCHAR(255) NULL AFTER `status`,
  ADD COLUMN `voided_by` BIGINT UNSIGNED NULL AFTER `void_reason`,
  ADD COLUMN `voided_at` DATETIME NULL AFTER `voided_by`,
  ADD CONSTRAINT `payment_vouchers_voided_by_foreign` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 2) chart_of_accounts: petty cash control account
INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '1015', 'Petty Cash Floats', 'Asset', 'Debit', NULL, 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '1015');

-- 3) petty_cash_funds
CREATE TABLE `petty_cash_funds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(24) NOT NULL,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `custodian_id` BIGINT UNSIGNED DEFAULT NULL,
  `chart_of_account_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(128) NOT NULL,
  `float_limit` DECIMAL(15,2) UNSIGNED NOT NULL,
  `current_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `petty_cash_funds_code_unique` (`code`),
  UNIQUE KEY `petty_cash_funds_chart_of_account_id_unique` (`chart_of_account_id`),
  KEY `petty_cash_funds_custodian_id_foreign` (`custodian_id`),
  KEY `petty_cash_funds_branch_id_status_index` (`branch_id`,`status`),
  CONSTRAINT `petty_cash_funds_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `petty_cash_funds_chart_of_account_id_foreign` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `petty_cash_funds_custodian_id_foreign` FOREIGN KEY (`custodian_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_pcf_float_limit` CHECK (`float_limit` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) petty_cash_replenishments
CREATE TABLE `petty_cash_replenishments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(24) NOT NULL,
  `petty_cash_fund_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) UNSIGNED NOT NULL,
  `funding_method` ENUM('Cash','Bank') NOT NULL DEFAULT 'Cash',
  `cash_account_id` BIGINT UNSIGNED DEFAULT NULL,
  `bank_account_id` BIGINT UNSIGNED DEFAULT NULL,
  `paid_at` DATETIME NOT NULL,
  `paid_by` BIGINT UNSIGNED DEFAULT NULL,
  `status` ENUM('Issued','Voided') NOT NULL DEFAULT 'Issued',
  `void_reason` VARCHAR(255) DEFAULT NULL,
  `voided_by` BIGINT UNSIGNED DEFAULT NULL,
  `voided_at` DATETIME DEFAULT NULL,
  `journal_entry_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `petty_cash_replenishments_code_unique` (`code`),
  KEY `petty_cash_replenishments_cash_account_id_foreign` (`cash_account_id`),
  KEY `petty_cash_replenishments_bank_account_id_foreign` (`bank_account_id`),
  KEY `petty_cash_replenishments_paid_by_foreign` (`paid_by`),
  KEY `petty_cash_replenishments_voided_by_foreign` (`voided_by`),
  KEY `petty_cash_replenishments_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `petty_cash_replenishments_petty_cash_fund_id_paid_at_index` (`petty_cash_fund_id`,`paid_at`),
  KEY `petty_cash_replenishments_status_index` (`status`),
  CONSTRAINT `petty_cash_replenishments_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `petty_cash_replenishments_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `petty_cash_replenishments_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_replenishments_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_replenishments_petty_cash_fund_id_foreign` FOREIGN KEY (`petty_cash_fund_id`) REFERENCES `petty_cash_funds` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `petty_cash_replenishments_voided_by_foreign` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_pcr_amount` CHECK (`amount` > 0),
  CONSTRAINT `chk_pcr_funding_source` CHECK (
    (`funding_method` = 'Cash' AND `cash_account_id` IS NOT NULL) OR
    (`funding_method` = 'Bank' AND `bank_account_id` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) petty_cash_vouchers
CREATE TABLE `petty_cash_vouchers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(24) NOT NULL,
  `petty_cash_fund_id` BIGINT UNSIGNED NOT NULL,
  `expense_category_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(15,2) UNSIGNED NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `spent_at` DATE NOT NULL,
  `paid_by` BIGINT UNSIGNED DEFAULT NULL,
  `status` ENUM('Issued','Voided') NOT NULL DEFAULT 'Issued',
  `void_reason` VARCHAR(255) DEFAULT NULL,
  `voided_by` BIGINT UNSIGNED DEFAULT NULL,
  `voided_at` DATETIME DEFAULT NULL,
  `journal_entry_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `petty_cash_vouchers_code_unique` (`code`),
  KEY `petty_cash_vouchers_expense_category_id_foreign` (`expense_category_id`),
  KEY `petty_cash_vouchers_vendor_id_foreign` (`vendor_id`),
  KEY `petty_cash_vouchers_paid_by_foreign` (`paid_by`),
  KEY `petty_cash_vouchers_voided_by_foreign` (`voided_by`),
  KEY `petty_cash_vouchers_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `petty_cash_vouchers_petty_cash_fund_id_spent_at_index` (`petty_cash_fund_id`,`spent_at`),
  KEY `petty_cash_vouchers_status_index` (`status`),
  CONSTRAINT `petty_cash_vouchers_expense_category_id_foreign` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `petty_cash_vouchers_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_vouchers_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_vouchers_petty_cash_fund_id_foreign` FOREIGN KEY (`petty_cash_fund_id`) REFERENCES `petty_cash_funds` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `petty_cash_vouchers_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_vouchers_voided_by_foreign` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_pcv_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) document_number_log.source_type: widen enum
ALTER TABLE `document_number_log`
  MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','FdDeposit','FixedDeposit','FdWithdrawal','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher') DEFAULT NULL;

-- 7) journal_entries.source_type: widen enum
ALTER TABLE `journal_entries`
  MODIFY `source_type` ENUM(
    'Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment',
    'FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal',
    'CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher',
    'ManualAdjustment','OpeningBalance'
  ) DEFAULT NULL;

-- 8) mark these 7 migrations as run, so `php artisan migrate` skips them
INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.migration, (SELECT COALESCE(MAX(batch),0)+1 FROM `migrations`)
FROM (
  SELECT '2026_08_12_000001_add_void_fields_to_payment_vouchers_table' AS migration
  UNION ALL SELECT '2026_08_12_000002_add_petty_cash_control_account'
  UNION ALL SELECT '2026_08_12_000003_create_petty_cash_funds_table'
  UNION ALL SELECT '2026_08_12_000004_create_petty_cash_replenishments_table'
  UNION ALL SELECT '2026_08_12_000005_create_petty_cash_vouchers_table'
  UNION ALL SELECT '2026_08_12_000006_update_document_number_log_source_type_for_petty_cash'
  UNION ALL SELECT '2026_08_12_000007_add_petty_cash_to_journal_entries_source_type'
) m
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = m.migration);
