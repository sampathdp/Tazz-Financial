-- =====================================================================
-- Deploy script for the 24 migrations dated 2026_08_20_* (uncommitted,
-- local-only as of 2026-08-21). Apply to the LIVE server database.
-- Equivalent to running: php artisan migrate --force
-- but as plain SQL for environments without CLI/artisan access.
--
-- Generated 2026-08-21 from the actual `up()` bodies of:
--   2026_08_20_000001 .. 2026_08_20_000022 (24 files, two dates share
--   000018 and 000019 — order below matches Laravel's execution order).
--
-- Run as one script inside a transaction-safe client. Statements are
-- idempotent where the source migration was idempotent (chart_of_accounts
-- seeds, CREATE TABLE IF NOT EXISTS). Safe to re-run if it fails partway,
-- EXCEPT the enum MODIFY statements and ADD COLUMN statements, which will
-- error on a second run if already applied (expected — means it worked).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 2026_08_20_000001: P&L waterfall chart-of-accounts leaves
-- ---------------------------------------------------------------------
INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '4040', 'Inter Branch / Zonal Interest Income (Exp)', 'Income', 'Credit', NULL, 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '4040');

INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '4910', 'Documentation Income (Other Income)', 'Income', 'Credit', (SELECT id FROM `chart_of_accounts` WHERE `code` = '4900'), 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '4910');

INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '4920', 'Real Estate Profit', 'Income', 'Credit', (SELECT id FROM `chart_of_accounts` WHERE `code` = '4900'), 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '4920');

INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '4930', 'Refunds on Write-offs from General Treasury', 'Income', 'Credit', (SELECT id FROM `chart_of_accounts` WHERE `code` = '4900'), 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '4930');

INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '5210', 'Salary / EPF / ETF', 'Expense', 'Debit', (SELECT id FROM `chart_of_accounts` WHERE `code` = '5200'), 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '5210');

INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '5220', 'Staff Allowances', 'Expense', 'Debit', (SELECT id FROM `chart_of_accounts` WHERE `code` = '5200'), 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '5220');

INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '5400', 'HO Expense Absorption', 'Expense', 'Debit', NULL, 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '5400');

INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '5500', 'VAT & NBT on Financial Services', 'Expense', 'Debit', NULL, 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '5500');

INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '5600', 'Income Tax', 'Expense', 'Debit', NULL, 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '5600');

-- ---------------------------------------------------------------------
-- 2026_08_20_000002: widen credit_facilities.facility_type for Pawning
-- ---------------------------------------------------------------------
ALTER TABLE `credit_facilities`
    MODIFY `facility_type` ENUM('Loan', 'SpeedDraft', 'Pawning') NOT NULL;

-- ---------------------------------------------------------------------
-- 2026_08_20_000003: pawning_types
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pawning_types` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` varchar(128) NOT NULL,
    `code` varchar(24) NOT NULL,
    `normal_rate_percent` decimal(6,3) unsigned NOT NULL,
    `special_rate_percent` decimal(6,3) unsigned NULL,
    `special_rate_days` smallint unsigned NULL,
    `cycle_days` smallint unsigned NOT NULL,
    `ltv_percent` decimal(5,2) unsigned NOT NULL,
    `max_renewal_count` smallint unsigned NULL,
    `grace_period_days` smallint unsigned NOT NULL DEFAULT 5,
    `auction_eligible_days` smallint unsigned NOT NULL,
    `auction_notice_days` smallint unsigned NOT NULL,
    `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
    `sort_order` smallint unsigned NOT NULL DEFAULT 0,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    UNIQUE KEY `pawning_types_code_unique` (`code`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `pawning_types` ADD CONSTRAINT `chk_pt_special_rate`
    CHECK (`special_rate_percent` IS NULL OR `special_rate_percent` <= `normal_rate_percent`);
ALTER TABLE `pawning_types` ADD CONSTRAINT `chk_pt_cycle_days` CHECK (`cycle_days` > 0);
ALTER TABLE `pawning_types` ADD CONSTRAINT `chk_pt_ltv`
    CHECK (`ltv_percent` > 0 AND `ltv_percent` <= 100);

-- ---------------------------------------------------------------------
-- 2026_08_20_000004: pawning_article_types (+ seed)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pawning_article_types` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` varchar(64) NOT NULL,
    `code` varchar(16) NOT NULL,
    `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
    `sort_order` smallint unsigned NOT NULL DEFAULT 0,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    UNIQUE KEY `pawning_article_types_name_unique` (`name`),
    UNIQUE KEY `pawning_article_types_code_unique` (`code`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `pawning_article_types` (`name`, `code`, `sort_order`, `created_at`, `updated_at`) VALUES
    ('Chain', 'CHAIN', 1, NOW(), NOW()),
    ('Necklace', 'NECK', 2, NOW(), NOW()),
    ('Ring', 'RING', 3, NOW(), NOW()),
    ('Bangle', 'BANGLE', 4, NOW(), NOW()),
    ('Bracelet', 'BRACE', 5, NOW(), NOW()),
    ('Ear Stud', 'ESTUD', 6, NOW(), NOW()),
    ('Pendant', 'PEND', 7, NOW(), NOW()),
    ('Coin', 'COIN', 8, NOW(), NOW()),
    ('Bar', 'BAR', 9, NOW(), NOW()),
    ('Anklet', 'ANKLET', 10, NOW(), NOW()),
    ('Other', 'OTHER', 11, NOW(), NOW());

-- ---------------------------------------------------------------------
-- 2026_08_20_000005: pawning_gold_rates
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pawning_gold_rates` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `effective_date` date NOT NULL,
    `karat` decimal(4,2) unsigned NOT NULL,
    `rate_per_gram` decimal(10,2) unsigned NOT NULL,
    `branch_id` bigint unsigned NULL,
    `created_by` bigint unsigned NULL,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    UNIQUE KEY `uq_pgr_date_karat_branch` (`effective_date`, `karat`, `branch_id`),
    CONSTRAINT `pawning_gold_rates_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
    CONSTRAINT `pawning_gold_rates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `pawning_gold_rates` ADD CONSTRAINT `chk_pgr_rate` CHECK (`rate_per_gram` > 0);

-- ---------------------------------------------------------------------
-- 2026_08_20_000006: pawning_facility_details
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pawning_facility_details` (
    `credit_facility_id` bigint unsigned NOT NULL,
    `pawning_type_id` bigint unsigned NOT NULL,
    `applied_rate_percent` decimal(6,3) unsigned NOT NULL,
    `is_special_rate` tinyint(1) NOT NULL DEFAULT 0,
    `total_gross_weight_grams` decimal(10,3) unsigned NOT NULL DEFAULT 0,
    `total_net_weight_grams` decimal(10,3) unsigned NOT NULL DEFAULT 0,
    `total_appraised_value` decimal(15,2) unsigned NOT NULL DEFAULT 0,
    `repay_due_date` date NOT NULL,
    `renewal_count` smallint unsigned NOT NULL DEFAULT 0,
    `previous_facility_id` bigint unsigned NULL,
    `purpose` varchar(128) NULL,
    PRIMARY KEY `pawning_facility_details_credit_facility_id_primary` (`credit_facility_id`),
    KEY `pawning_facility_details_previous_facility_id_index` (`previous_facility_id`),
    CONSTRAINT `pawning_facility_details_credit_facility_id_foreign` FOREIGN KEY (`credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE CASCADE,
    CONSTRAINT `pawning_facility_details_pawning_type_id_foreign` FOREIGN KEY (`pawning_type_id`) REFERENCES `pawning_types` (`id`),
    CONSTRAINT `pawning_facility_details_previous_facility_id_foreign` FOREIGN KEY (`previous_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `pawning_facility_details` ADD CONSTRAINT `chk_pfd_weight`
    CHECK (`total_net_weight_grams` <= `total_gross_weight_grams`);

-- ---------------------------------------------------------------------
-- 2026_08_20_000007: widen facility_collateral.collateral_type
-- ---------------------------------------------------------------------
ALTER TABLE `facility_collateral`
    MODIFY `collateral_type` ENUM('Vehicle', 'Jewelry', 'PawnedJewelry', 'Property', 'Other') NOT NULL;

-- ---------------------------------------------------------------------
-- 2026_08_20_000008: facility_collateral_pawn_details
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `facility_collateral_pawn_details` (
    `facility_collateral_id` bigint unsigned NOT NULL,
    `item_no` smallint unsigned NOT NULL,
    `article_type_id` bigint unsigned NOT NULL,
    `declared_karat` decimal(4,2) unsigned NULL,
    `tested_karat` decimal(4,2) unsigned NOT NULL,
    `gross_weight_grams` decimal(10,3) unsigned NOT NULL,
    `net_weight_grams` decimal(10,3) unsigned NOT NULL,
    `rate_per_gram` decimal(10,2) unsigned NOT NULL,
    `appraised_value` decimal(15,2) unsigned NOT NULL,
    `max_loan_value` decimal(15,2) unsigned NOT NULL,
    `item_status` enum('Pledged','Released','Auctioned','Forfeited') NOT NULL DEFAULT 'Pledged',
    `appraised_by` bigint unsigned NULL,
    `remark` varchar(255) NULL,
    PRIMARY KEY `pk_fcpd` (`facility_collateral_id`),
    KEY `facility_collateral_pawn_details_article_type_id_index` (`article_type_id`),
    KEY `facility_collateral_pawn_details_item_status_index` (`item_status`),
    CONSTRAINT `fk_fcpd_collateral` FOREIGN KEY (`facility_collateral_id`) REFERENCES `facility_collateral` (`id`) ON DELETE CASCADE,
    CONSTRAINT `facility_collateral_pawn_details_article_type_id_foreign` FOREIGN KEY (`article_type_id`) REFERENCES `pawning_article_types` (`id`),
    CONSTRAINT `facility_collateral_pawn_details_appraised_by_foreign` FOREIGN KEY (`appraised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `facility_collateral_pawn_details` ADD CONSTRAINT `chk_fcpd_weight`
    CHECK (`net_weight_grams` <= `gross_weight_grams`);

-- ---------------------------------------------------------------------
-- 2026_08_20_000009: pawning_renewals
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pawning_renewals` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `credit_facility_id` bigint unsigned NOT NULL,
    `renewal_no` smallint unsigned NOT NULL,
    `old_due_date` date NOT NULL,
    `new_due_date` date NOT NULL,
    `capital_before` decimal(15,2) unsigned NOT NULL,
    `interest_settled` decimal(15,2) unsigned NOT NULL,
    `capital_after` decimal(15,2) unsigned NOT NULL,
    `applied_rate_percent` decimal(6,3) unsigned NOT NULL,
    `facility_payment_id` bigint unsigned NULL,
    `renewed_by` bigint unsigned NULL,
    `renewed_at` datetime NOT NULL,
    `remark` varchar(255) NULL,
    UNIQUE KEY `pawning_renewals_credit_facility_id_renewal_no_unique` (`credit_facility_id`, `renewal_no`),
    CONSTRAINT `pawning_renewals_credit_facility_id_foreign` FOREIGN KEY (`credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE CASCADE,
    CONSTRAINT `pawning_renewals_facility_payment_id_foreign` FOREIGN KEY (`facility_payment_id`) REFERENCES `facility_payments` (`id`) ON DELETE SET NULL,
    CONSTRAINT `pawning_renewals_renewed_by_foreign` FOREIGN KEY (`renewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2026_08_20_000010: pawning_redemptions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pawning_redemptions` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `credit_facility_id` bigint unsigned NOT NULL,
    `redemption_type` enum('Full','Partial') NOT NULL,
    `capital_settled` decimal(15,2) unsigned NOT NULL,
    `interest_settled` decimal(15,2) unsigned NOT NULL,
    `total_settled` decimal(15,2) unsigned NOT NULL,
    `receipt_no` varchar(24) NULL,
    `facility_payment_id` bigint unsigned NULL,
    `settled_by` bigint unsigned NULL,
    `settled_at` datetime NOT NULL,
    KEY `pawning_redemptions_credit_facility_id_settled_at_index` (`credit_facility_id`, `settled_at`),
    UNIQUE KEY `pawning_redemptions_receipt_no_unique` (`receipt_no`),
    CONSTRAINT `pawning_redemptions_credit_facility_id_foreign` FOREIGN KEY (`credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE CASCADE,
    CONSTRAINT `pawning_redemptions_facility_payment_id_foreign` FOREIGN KEY (`facility_payment_id`) REFERENCES `facility_payments` (`id`) ON DELETE SET NULL,
    CONSTRAINT `pawning_redemptions_settled_by_foreign` FOREIGN KEY (`settled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `pawning_redemptions` ADD CONSTRAINT `chk_pr_total`
    CHECK (`total_settled` = `capital_settled` + `interest_settled`);

-- ---------------------------------------------------------------------
-- 2026_08_20_000011: pawning_redemption_items
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pawning_redemption_items` (
    `pawning_redemption_id` bigint unsigned NOT NULL,
    `facility_collateral_id` bigint unsigned NOT NULL,
    PRIMARY KEY `pk_pri` (`pawning_redemption_id`, `facility_collateral_id`),
    KEY `pawning_redemption_items_facility_collateral_id_index` (`facility_collateral_id`),
    CONSTRAINT `pawning_redemption_items_pawning_redemption_id_foreign` FOREIGN KEY (`pawning_redemption_id`) REFERENCES `pawning_redemptions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `pawning_redemption_items_facility_collateral_id_foreign` FOREIGN KEY (`facility_collateral_id`) REFERENCES `facility_collateral` (`id`) ON DELETE RESTRICT
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2026_08_20_000012: pawning_auctions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pawning_auctions` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `branch_id` bigint unsigned NOT NULL,
    `auction_no` varchar(24) NOT NULL,
    `scheduled_date` date NOT NULL,
    `notice_published_date` date NULL,
    `auctioneer_name` varchar(128) NULL,
    `venue` varchar(255) NULL,
    `status` enum('Scheduled','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
    `created_by` bigint unsigned NULL,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    KEY `pawning_auctions_branch_id_scheduled_date_index` (`branch_id`, `scheduled_date`),
    UNIQUE KEY `pawning_auctions_auction_no_unique` (`auction_no`),
    CONSTRAINT `pawning_auctions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `pawning_auctions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2026_08_20_000013: pawning_auction_items
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pawning_auction_items` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `pawning_auction_id` bigint unsigned NOT NULL,
    `facility_collateral_id` bigint unsigned NOT NULL,
    `credit_facility_id` bigint unsigned NOT NULL,
    `outstanding_capital` decimal(15,2) unsigned NOT NULL,
    `outstanding_interest` decimal(15,2) unsigned NOT NULL,
    `reserve_price` decimal(15,2) unsigned NULL,
    `hammer_price` decimal(15,2) unsigned NULL,
    `buyer_name` varchar(128) NULL,
    `buyer_nic` varchar(15) NULL,
    `surplus_amount` decimal(15,2) unsigned NOT NULL DEFAULT 0,
    `settlement_status` enum('Pending','Refunded','Forfeited') NOT NULL DEFAULT 'Pending',
    `status` enum('Listed','Sold','Unsold','Cancelled') NOT NULL DEFAULT 'Listed',
    `sold_at` datetime NULL,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    UNIQUE KEY `uq_pai_auction_collateral` (`pawning_auction_id`, `facility_collateral_id`),
    KEY `pawning_auction_items_credit_facility_id_index` (`credit_facility_id`),
    CONSTRAINT `pawning_auction_items_pawning_auction_id_foreign` FOREIGN KEY (`pawning_auction_id`) REFERENCES `pawning_auctions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `pawning_auction_items_facility_collateral_id_foreign` FOREIGN KEY (`facility_collateral_id`) REFERENCES `facility_collateral` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `pawning_auction_items_credit_facility_id_foreign` FOREIGN KEY (`credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE RESTRICT
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `pawning_auction_items` ADD CONSTRAINT `chk_pai_outstanding` CHECK (`outstanding_capital` + `outstanding_interest` > 0);

-- ---------------------------------------------------------------------
-- 2026_08_20_000014: widen document_number_log.source_type for Pawning
-- ---------------------------------------------------------------------
ALTER TABLE `document_number_log`
    MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','FdDeposit','FixedDeposit','FdWithdrawal','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher') DEFAULT NULL;

-- ---------------------------------------------------------------------
-- 2026_08_20_000015: Pawning capital/income chart-of-accounts leaves
-- ---------------------------------------------------------------------
INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '1120', 'Pawning Capital Outstanding', 'Asset', 'Debit', NULL, 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '1120');

INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '4025', 'Interest Income - Pawning', 'Income', 'Credit', NULL, 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '4025');

-- ---------------------------------------------------------------------
-- 2026_08_20_000016: widen journal_entries.source_type for Pawning
-- ---------------------------------------------------------------------
ALTER TABLE `journal_entries`
    MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment','Pawning','PawningPayment','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','ManualAdjustment','OpeningBalance') DEFAULT NULL;

-- ---------------------------------------------------------------------
-- 2026_08_20_000017: widen document_number_log.source_type for PawningAuction
-- ---------------------------------------------------------------------
ALTER TABLE `document_number_log`
    MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher') DEFAULT NULL;

-- ---------------------------------------------------------------------
-- 2026_08_20_000018 (add_pawning_auction_accounting): surplus-payable
-- account + widen journal_entries.source_type for PawningAuctionItem
-- ---------------------------------------------------------------------
INSERT INTO `chart_of_accounts` (`code`, `name`, `account_type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`)
SELECT '2110', 'Pawning Auction Surplus Payable', 'Liability', 'Credit', NULL, 1, 'Active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `chart_of_accounts` WHERE `code` = '2110');

ALTER TABLE `journal_entries`
    MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','ManualAdjustment','OpeningBalance') DEFAULT NULL;

-- ---------------------------------------------------------------------
-- 2026_08_20_000018 (add_report_performance_indexes)
-- ---------------------------------------------------------------------
ALTER TABLE `cashbook_entries` ADD INDEX `cashbook_entries_branch_id_entry_date_index` (`branch_id`, `entry_date`);
ALTER TABLE `credit_facilities` ADD INDEX `credit_facilities_disbursed_at_index` (`disbursed_at`);
ALTER TABLE `credit_facilities` ADD INDEX `credit_facilities_status_closed_at_index` (`status`, `closed_at`);
ALTER TABLE `facility_schedules` ADD INDEX `facility_schedules_due_date_index` (`due_date`);

-- ---------------------------------------------------------------------
-- 2026_08_20_000019 (add_more_report_performance_indexes)
-- ---------------------------------------------------------------------
ALTER TABLE `journal_entries` ADD INDEX `journal_entries_branch_id_status_entry_date_index` (`branch_id`, `status`, `entry_date`);
ALTER TABLE `expenses` ADD INDEX `expenses_status_index` (`status`);
ALTER TABLE `sales_executive_commissions` ADD INDEX `sales_executive_commissions_loan_type_id_index` (`loan_type_id`);

-- ---------------------------------------------------------------------
-- 2026_08_20_000019 (create_company_profile_table)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `company_profile` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` varchar(191) NOT NULL DEFAULT 'Tazz Investment & Credit Pvt Ltd',
    `tagline` varchar(191) NULL,
    `address` varchar(255) NULL,
    `phone` varchar(32) NULL,
    `email` varchar(191) NULL,
    `tax_no` varchar(64) NULL,
    `currency` varchar(32) NOT NULL DEFAULT 'LKR — Sri Lankan Rupee',
    `logo_path` varchar(255) NULL,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2026_08_20_000020: loan_facility_details.disbursement_method
-- ---------------------------------------------------------------------
ALTER TABLE `loan_facility_details`
    ADD `disbursement_method` ENUM('Cash', 'Bank', 'Cheque') NULL AFTER `down_payment`;

-- ---------------------------------------------------------------------
-- 2026_08_20_000021: write-off columns on credit_facilities
-- ---------------------------------------------------------------------
ALTER TABLE `credit_facilities`
    ADD `written_off_reason` VARCHAR(255) NULL AFTER `cancelled_at`,
    ADD `written_off_by` BIGINT UNSIGNED NULL AFTER `written_off_reason`;

ALTER TABLE `credit_facilities`
    ADD CONSTRAINT `credit_facilities_written_off_by_foreign` FOREIGN KEY (`written_off_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 2026_08_20_000022: void support on cash_transfers
-- ---------------------------------------------------------------------
ALTER TABLE `cash_transfers`
    MODIFY `status` ENUM('Pending', 'Completed', 'Rejected', 'Voided') DEFAULT 'Pending';

ALTER TABLE `cash_transfers`
    ADD `void_reason` VARCHAR(255) NULL AFTER `approved_at`,
    ADD `voided_by` BIGINT UNSIGNED NULL AFTER `void_reason`,
    ADD `voided_at` DATETIME NULL AFTER `voided_by`;

ALTER TABLE `cash_transfers`
    ADD CONSTRAINT `cash_transfers_voided_by_foreign` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- Record all 24 as applied in Laravel's migrations table so a later
-- `php artisan migrate` on the live server does not try to re-run them.
-- All 24 rows share one batch number, computed once up front so every
-- row (including the first) lands on the same value.
-- ---------------------------------------------------------------------
SET @deploy_batch := (SELECT MAX(batch) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`) VALUES
    ('2026_08_20_000001_add_pl_waterfall_accounts_to_chart_of_accounts', @deploy_batch),
    ('2026_08_20_000002_add_pawning_to_credit_facilities_facility_type', @deploy_batch),
    ('2026_08_20_000003_create_pawning_types_table', @deploy_batch),
    ('2026_08_20_000004_create_pawning_article_types_table', @deploy_batch),
    ('2026_08_20_000005_create_pawning_gold_rates_table', @deploy_batch),
    ('2026_08_20_000006_create_pawning_facility_details_table', @deploy_batch),
    ('2026_08_20_000007_add_pawned_jewelry_to_facility_collateral_type', @deploy_batch),
    ('2026_08_20_000008_create_facility_collateral_pawn_details_table', @deploy_batch),
    ('2026_08_20_000009_create_pawning_renewals_table', @deploy_batch),
    ('2026_08_20_000010_create_pawning_redemptions_table', @deploy_batch),
    ('2026_08_20_000011_create_pawning_redemption_items_table', @deploy_batch),
    ('2026_08_20_000012_create_pawning_auctions_table', @deploy_batch),
    ('2026_08_20_000013_create_pawning_auction_items_table', @deploy_batch),
    ('2026_08_20_000014_add_pawning_to_document_number_log_source_type', @deploy_batch),
    ('2026_08_20_000015_add_pawning_accounts_to_chart_of_accounts', @deploy_batch),
    ('2026_08_20_000016_add_pawning_to_journal_entries_source_type', @deploy_batch),
    ('2026_08_20_000017_add_pawning_auction_to_document_number_log_source_type', @deploy_batch),
    ('2026_08_20_000018_add_pawning_auction_accounting', @deploy_batch),
    ('2026_08_20_000018_add_report_performance_indexes', @deploy_batch),
    ('2026_08_20_000019_add_more_report_performance_indexes', @deploy_batch),
    ('2026_08_20_000019_create_company_profile_table', @deploy_batch),
    ('2026_08_20_000020_add_disbursement_method_to_loan_facility_details', @deploy_batch),
    ('2026_08_20_000021_add_write_off_columns_to_credit_facilities', @deploy_batch),
    ('2026_08_20_000022_add_void_to_cash_transfers', @deploy_batch);
