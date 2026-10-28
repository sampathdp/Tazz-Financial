-- Fixed Assets module (matches migration 2026_10_29_000001_create_fixed_assets).
-- Safe to run once on the live DB. BACK UP FIRST. Run the whole file in one go.

-- 1. Chart of accounts ------------------------------------------------------
INSERT INTO chart_of_accounts (code, name, account_type, normal_balance, parent_id, is_system, is_postable, status, created_at, updated_at)
SELECT v.code, v.name, v.account_type, v.normal_balance,
       (SELECT p.id FROM chart_of_accounts p WHERE p.code = v.parent_code LIMIT 1),
       1, 1, 'Active', NOW(), NOW()
FROM (
  SELECT '1310' AS code, 'Land & Buildings' AS name, 'Asset' AS account_type, 'Debit' AS normal_balance, '1300' AS parent_code
  UNION ALL SELECT '1320', 'Furniture & Fittings', 'Asset', 'Debit', '1300'
  UNION ALL SELECT '1330', 'Office Equipment', 'Asset', 'Debit', '1300'
  UNION ALL SELECT '1340', 'Computers & IT Equipment', 'Asset', 'Debit', '1300'
  UNION ALL SELECT '1350', 'Motor Vehicles', 'Asset', 'Debit', '1300'
  UNION ALL SELECT '1390', 'Accumulated Depreciation', 'Asset', 'Credit', NULL
  UNION ALL SELECT '4940', 'Gain on Disposal of Fixed Assets', 'Income', 'Credit', '4900'
  UNION ALL SELECT '5450', 'Depreciation Expense', 'Expense', 'Debit', NULL
  UNION ALL SELECT '5460', 'Loss on Disposal of Fixed Assets', 'Expense', 'Debit', NULL
) v
WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = v.code);

-- 1300 now only groups the per-category cost accounts.
UPDATE chart_of_accounts SET is_postable = 0 WHERE code = '1300';

-- 2. Widen enums (appends the new values to whatever the live list is) --------
SET @t = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_entries' AND COLUMN_NAME = 'source_type');
SET @sql = IF(@t LIKE '%''FixedAsset''%', 'SELECT 1',
  CONCAT('ALTER TABLE `journal_entries` MODIFY `source_type` ', LEFT(@t, CHAR_LENGTH(@t) - 1), ',''FixedAsset'',''FixedAssetDepreciation'',''FixedAssetDisposal'') DEFAULT NULL'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @t = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_number_log' AND COLUMN_NAME = 'source_type');
SET @sql = IF(@t LIKE '%''FixedAsset''%', 'SELECT 1',
  CONCAT('ALTER TABLE `document_number_log` MODIFY `source_type` ', LEFT(@t, CHAR_LENGTH(@t) - 1), ',''FixedAsset'',''FixedAssetDepreciation'',''FixedAssetDisposal'') DEFAULT NULL'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @t = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'number_sequences' AND COLUMN_NAME = 'module');
SET @null = (SELECT IF(IS_NULLABLE = 'YES', 'DEFAULT NULL', 'NOT NULL') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'number_sequences' AND COLUMN_NAME = 'module');
SET @sql = IF(@t LIKE '%''fixed_asset''%', 'SELECT 1',
  CONCAT('ALTER TABLE `number_sequences` MODIFY `module` ', LEFT(@t, CHAR_LENGTH(@t) - 1), ',''fixed_asset'') ', @null));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Tables --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asset_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `asset_account_id` bigint unsigned NOT NULL,
  `accumulated_depreciation_account_id` bigint unsigned NOT NULL,
  `expense_account_id` bigint unsigned NOT NULL,
  `default_useful_life_months` smallint unsigned NOT NULL DEFAULT 60,
  `default_residual_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_categories_name_unique` (`name`),
  CONSTRAINT `asset_categories_asset_account_id_foreign` FOREIGN KEY (`asset_account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `asset_categories_accum_account_id_foreign` FOREIGN KEY (`accumulated_depreciation_account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `asset_categories_expense_account_id_foreign` FOREIGN KEY (`expense_account_id`) REFERENCES `chart_of_accounts` (`id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fixed_assets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `asset_category_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `serial_no` varchar(100) DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `custodian_staff_id` bigint unsigned DEFAULT NULL,
  `vendor_id` bigint unsigned DEFAULT NULL,
  `invoice_no` varchar(64) DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `in_service_date` date NOT NULL,
  `cost` decimal(14,2) NOT NULL,
  `residual_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `useful_life_months` smallint unsigned NOT NULL,
  `depreciation_method` enum('straight_line') NOT NULL DEFAULT 'straight_line',
  `accumulated_depreciation` decimal(14,2) NOT NULL DEFAULT 0.00,
  `depreciated_through` date DEFAULT NULL,
  `status` enum('Active','FullyDepreciated','Disposed') NOT NULL DEFAULT 'Active',
  `acquisition_type` enum('Purchase','Opening') NOT NULL DEFAULT 'Purchase',
  `payment_method` varchar(16) DEFAULT NULL,
  `cash_account_id` bigint unsigned DEFAULT NULL,
  `bank_account_id` bigint unsigned DEFAULT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `finance_transaction_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fixed_assets_code_unique` (`code`),
  KEY `fixed_assets_status_branch_id_index` (`status`,`branch_id`),
  CONSTRAINT `fixed_assets_asset_category_id_foreign` FOREIGN KEY (`asset_category_id`) REFERENCES `asset_categories` (`id`),
  CONSTRAINT `fixed_assets_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_assets_custodian_staff_id_foreign` FOREIGN KEY (`custodian_staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_assets_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_assets_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_assets_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_assets_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_assets_finance_transaction_id_foreign` FOREIGN KEY (`finance_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_assets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fixed_asset_depreciations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fixed_asset_id` bigint unsigned NOT NULL,
  `period` char(7) NOT NULL,
  `period_end` date NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `posted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fixed_asset_depreciations_fixed_asset_id_period_unique` (`fixed_asset_id`,`period`),
  KEY `fixed_asset_depreciations_period_index` (`period`),
  CONSTRAINT `fixed_asset_depreciations_fixed_asset_id_foreign` FOREIGN KEY (`fixed_asset_id`) REFERENCES `fixed_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fixed_asset_depreciations_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_asset_depreciations_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fixed_asset_disposals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fixed_asset_id` bigint unsigned NOT NULL,
  `disposal_date` date NOT NULL,
  `proceeds` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(16) DEFAULT NULL,
  `cash_account_id` bigint unsigned DEFAULT NULL,
  `bank_account_id` bigint unsigned DEFAULT NULL,
  `book_value` decimal(14,2) NOT NULL,
  `gain_loss` decimal(14,2) NOT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `finance_transaction_id` bigint unsigned DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `disposed_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fixed_asset_disposals_fixed_asset_id_unique` (`fixed_asset_id`),
  CONSTRAINT `fixed_asset_disposals_fixed_asset_id_foreign` FOREIGN KEY (`fixed_asset_id`) REFERENCES `fixed_assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fixed_asset_disposals_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_asset_disposals_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_asset_disposals_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_asset_disposals_finance_transaction_id_foreign` FOREIGN KEY (`finance_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fixed_asset_disposals_disposed_by_foreign` FOREIGN KEY (`disposed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Default categories ---------------------------------------------------------
INSERT INTO asset_categories (name, asset_account_id, accumulated_depreciation_account_id, expense_account_id, default_useful_life_months, default_residual_percent, status, created_at, updated_at)
SELECT v.name,
       (SELECT id FROM chart_of_accounts WHERE code = v.code),
       (SELECT id FROM chart_of_accounts WHERE code = '1390'),
       (SELECT id FROM chart_of_accounts WHERE code = '5450'),
       v.life, v.residual, 'Active', NOW(), NOW()
FROM (
  SELECT 'Land & Buildings' AS name, '1310' AS code, 480 AS life, 0 AS residual
  UNION ALL SELECT 'Furniture & Fittings', '1320', 120, 0
  UNION ALL SELECT 'Office Equipment', '1330', 60, 0
  UNION ALL SELECT 'Computers & IT Equipment', '1340', 48, 0
  UNION ALL SELECT 'Motor Vehicles', '1350', 60, 10
) v
WHERE NOT EXISTS (SELECT 1 FROM asset_categories c WHERE c.name = v.name);

-- 5. Tell Laravel the migration already ran, so `php artisan migrate` will not repeat it.
INSERT INTO migrations (migration, batch)
SELECT '2026_10_29_000001_create_fixed_assets', (SELECT COALESCE(MAX(batch), 0) + 1 FROM (SELECT batch FROM migrations) m)
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_29_000001_create_fixed_assets');
