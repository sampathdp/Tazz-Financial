-- FD joint holders, nominees and manual commissions (matches migration 2026_10_28_000001).
-- fixed_deposits.customer_bank_detail_id is already nullable, so no change there.
CREATE TABLE IF NOT EXISTS `fd_joint_holders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fixed_deposit_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `relationship` varchar(64) DEFAULT NULL,
  `operating_instruction` enum('either_or_survivor','both_to_sign') NOT NULL DEFAULT 'either_or_survivor',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_joint_holders_fixed_deposit_id_customer_id_unique` (`fixed_deposit_id`,`customer_id`),
  CONSTRAINT `fd_joint_holders_fixed_deposit_id_foreign` FOREIGN KEY (`fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fd_joint_holders_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fd_nominees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fixed_deposit_id` bigint unsigned NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `relationship` varchar(64) DEFAULT NULL,
  `nic_no` varchar(32) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `share_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
  `guardian_name` varchar(150) DEFAULT NULL,
  `guardian_nic_no` varchar(32) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fd_nominees_fixed_deposit_id_foreign` FOREIGN KEY (`fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fd_commissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fixed_deposit_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `recipient_type` enum('staff','outsider') NOT NULL,
  `staff_id` bigint unsigned DEFAULT NULL,
  `recipient_name` varchar(150) NOT NULL,
  `recipient_phone` varchar(32) DEFAULT NULL,
  `commission_type` enum('percentage','fixed') NOT NULL,
  `commission_value` decimal(12,2) NOT NULL,
  `commission_amount` decimal(14,2) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_amount` decimal(14,2) DEFAULT NULL,
  `payment_method` varchar(16) DEFAULT NULL,
  `cash_account_id` bigint unsigned DEFAULT NULL,
  `bank_account_id` bigint unsigned DEFAULT NULL,
  `reference_no` varchar(64) DEFAULT NULL,
  `payment_notes` varchar(255) DEFAULT NULL,
  `paid_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fd_commissions_fixed_deposit_id_foreign` FOREIGN KEY (`fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fd_commissions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fd_commissions_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fd_commissions_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fd_commissions_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fd_commissions_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fd_commissions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
