-- Funding (Fixed Deposit) Management module — schema changes for the live server.
-- Generated from migrations 2026_07_28_102001 .. 2026_07_28_102007.
-- Run this whole script in one transaction against the production database,
-- THEN insert the matching rows into `migrations` (see bottom) so
-- `php artisan migrate` on that server doesn't try to re-run these files.

START TRANSACTION;

-- 2026_07_28_102001_create_funding_products_table
CREATE TABLE `funding_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(128) NOT NULL,
  `code` varchar(32) NOT NULL,
  `interest_method` varchar(16) NOT NULL,
  `annual_rate_percent` decimal(6,3) NOT NULL,
  `monthly_rate_percent` decimal(6,3) NULL,
  `rate_is_manual` tinyint(1) NOT NULL DEFAULT '0',
  `allowed_terms_months` json NOT NULL,
  `allow_custom_term` tinyint(1) NOT NULL DEFAULT '0',
  `min_deposit_amount` decimal(15,2) NOT NULL,
  `max_deposit_amount` decimal(15,2) NULL,
  `reference_format` varchar(191) NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `funding_products` ADD UNIQUE `funding_products_code_unique`(`code`);

-- 2026_07_28_102002_create_fixed_deposits_table
CREATE TABLE `fixed_deposits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `fd_number` varchar(64) NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `funding_product_id` bigint unsigned NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `interest_rate_percent` decimal(6,3) NOT NULL,
  `interest_payment_method` varchar(16) NOT NULL,
  `term_months` smallint unsigned NOT NULL,
  `start_date` date NOT NULL,
  `maturity_date` date NOT NULL,
  `monthly_interest_amount` decimal(15,2) NULL,
  `total_interest_amount` decimal(15,2) NOT NULL,
  `maturity_amount` decimal(15,2) NOT NULL,
  `next_interest_date` date NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `renewed_from_id` bigint unsigned NULL,
  `renewed_to_id` bigint unsigned NULL,
  `closed_at` date NULL,
  `created_by` bigint unsigned NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `fixed_deposits` ADD CONSTRAINT `fixed_deposits_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT;
ALTER TABLE `fixed_deposits` ADD CONSTRAINT `fixed_deposits_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT;
ALTER TABLE `fixed_deposits` ADD CONSTRAINT `fixed_deposits_funding_product_id_foreign` FOREIGN KEY (`funding_product_id`) REFERENCES `funding_products` (`id`) ON DELETE RESTRICT;
ALTER TABLE `fixed_deposits` ADD CONSTRAINT `fixed_deposits_renewed_from_id_foreign` FOREIGN KEY (`renewed_from_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE SET NULL;
ALTER TABLE `fixed_deposits` ADD CONSTRAINT `fixed_deposits_renewed_to_id_foreign` FOREIGN KEY (`renewed_to_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE SET NULL;
ALTER TABLE `fixed_deposits` ADD CONSTRAINT `fixed_deposits_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `fixed_deposits` ADD INDEX `fixed_deposits_status_index`(`status`);
ALTER TABLE `fixed_deposits` ADD INDEX `fixed_deposits_maturity_date_index`(`maturity_date`);
ALTER TABLE `fixed_deposits` ADD UNIQUE `fixed_deposits_fd_number_unique`(`fd_number`);

-- 2026_07_28_102003_create_fd_deposits_table
CREATE TABLE `fd_deposits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `deposit_number` varchar(64) NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `deposit_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` varchar(16) NOT NULL,
  `reference_no` varchar(64) NULL,
  `bank_name` varchar(128) NULL,
  `notes` text NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Available',
  `fixed_deposit_id` bigint unsigned NULL,
  `created_by` bigint unsigned NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `fd_deposits` ADD CONSTRAINT `fd_deposits_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT;
ALTER TABLE `fd_deposits` ADD CONSTRAINT `fd_deposits_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT;
ALTER TABLE `fd_deposits` ADD CONSTRAINT `fd_deposits_fixed_deposit_id_foreign` FOREIGN KEY (`fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE SET NULL;
ALTER TABLE `fd_deposits` ADD CONSTRAINT `fd_deposits_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `fd_deposits` ADD INDEX `fd_deposits_status_index`(`status`);
ALTER TABLE `fd_deposits` ADD UNIQUE `fd_deposits_deposit_number_unique`(`deposit_number`);

-- 2026_07_28_102004_create_fd_interest_payments_table
CREATE TABLE `fd_interest_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `fixed_deposit_id` bigint unsigned NOT NULL,
  `installment_no` smallint unsigned NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Pending',
  `paid_amount` decimal(15,2) NOT NULL DEFAULT '0',
  `paid_at` date NULL,
  `payment_method` varchar(16) NULL,
  `reference_no` varchar(64) NULL,
  `paid_by` bigint unsigned NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `fd_interest_payments` ADD CONSTRAINT `fd_interest_payments_fixed_deposit_id_foreign` FOREIGN KEY (`fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE CASCADE;
ALTER TABLE `fd_interest_payments` ADD CONSTRAINT `fd_interest_payments_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `fd_interest_payments` ADD INDEX `fd_interest_payments_fixed_deposit_id_status_index`(`fixed_deposit_id`, `status`);
ALTER TABLE `fd_interest_payments` ADD INDEX `fd_interest_payments_due_date_index`(`due_date`);

-- 2026_07_28_102005_create_fd_renewals_table
CREATE TABLE `fd_renewals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `old_fixed_deposit_id` bigint unsigned NOT NULL,
  `new_fixed_deposit_id` bigint unsigned NOT NULL,
  `old_principal` decimal(15,2) NOT NULL,
  `additional_deposit_amount` decimal(15,2) NOT NULL DEFAULT '0',
  `new_principal` decimal(15,2) NOT NULL,
  `additional_deposit_id` bigint unsigned NULL,
  `new_term_months` smallint unsigned NOT NULL,
  `renewed_at` date NOT NULL,
  `notes` text NULL,
  `created_by` bigint unsigned NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `fd_renewals` ADD CONSTRAINT `fd_renewals_old_fixed_deposit_id_foreign` FOREIGN KEY (`old_fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE RESTRICT;
ALTER TABLE `fd_renewals` ADD CONSTRAINT `fd_renewals_new_fixed_deposit_id_foreign` FOREIGN KEY (`new_fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE RESTRICT;
ALTER TABLE `fd_renewals` ADD CONSTRAINT `fd_renewals_additional_deposit_id_foreign` FOREIGN KEY (`additional_deposit_id`) REFERENCES `fd_deposits` (`id`) ON DELETE SET NULL;
ALTER TABLE `fd_renewals` ADD CONSTRAINT `fd_renewals_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 2026_07_28_102006_create_fd_withdrawals_table
CREATE TABLE `fd_withdrawals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `fixed_deposit_id` bigint unsigned NOT NULL,
  `voucher_no` varchar(64) NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `interest_amount` decimal(15,2) NOT NULL DEFAULT '0',
  `total_amount` decimal(15,2) NOT NULL,
  `payment_method` varchar(16) NOT NULL,
  `reference_no` varchar(64) NULL,
  `notes` text NULL,
  `closed_at` date NOT NULL,
  `created_by` bigint unsigned NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `fd_withdrawals` ADD CONSTRAINT `fd_withdrawals_fixed_deposit_id_foreign` FOREIGN KEY (`fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE RESTRICT;
ALTER TABLE `fd_withdrawals` ADD CONSTRAINT `fd_withdrawals_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `fd_withdrawals` ADD UNIQUE `fd_withdrawals_fixed_deposit_id_unique`(`fixed_deposit_id`);
ALTER TABLE `fd_withdrawals` ADD UNIQUE `fd_withdrawals_voucher_no_unique`(`voucher_no`);

-- 2026_07_28_102007_add_funding_product_id_to_number_tables
ALTER TABLE `number_sequences` ADD `funding_product_id` bigint unsigned NOT NULL DEFAULT '0' AFTER `loan_type_id`;
ALTER TABLE `number_sequences` DROP INDEX `number_sequences_key_unique`;
ALTER TABLE `number_sequences` ADD UNIQUE `number_sequences_key_unique`(`module`, `branch_id`, `loan_type_id`, `funding_product_id`, `year`);
ALTER TABLE `generated_numbers` ADD `funding_product_id` bigint unsigned NULL AFTER `loan_type_id`;
ALTER TABLE `generated_numbers` ADD CONSTRAINT `generated_numbers_funding_product_id_foreign` FOREIGN KEY (`funding_product_id`) REFERENCES `funding_products` (`id`) ON DELETE SET NULL;

-- Keep Laravel's migration history in sync so `php artisan migrate` on this
-- server doesn't try to re-run these files (and so `migrate:rollback` can
-- undo them later). Adjust the batch number if this server is already past 19.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_07_28_102001_create_funding_products_table', 19),
  ('2026_07_28_102002_create_fixed_deposits_table', 19),
  ('2026_07_28_102003_create_fd_deposits_table', 19),
  ('2026_07_28_102004_create_fd_interest_payments_table', 19),
  ('2026_07_28_102005_create_fd_renewals_table', 19),
  ('2026_07_28_102006_create_fd_withdrawals_table', 19),
  ('2026_07_28_102007_add_funding_product_id_to_number_tables', 19);

COMMIT;
