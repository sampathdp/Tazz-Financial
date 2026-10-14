-- =====================================================================
-- Deploy script: Loan against Fixed Deposit (5 migrations dated 2026_10_13_*).
-- Apply to the LIVE database. Equivalent to: php artisan migrate --force
--
--   2026_10_13_000001  fd_loan_policies (lending rules per FD product)
--   2026_10_13_000002  facility_collateral.collateral_type += 'FixedDeposit'
--   2026_10_13_000003  facility_collateral_fd_details (the lien)
--   2026_10_13_000004  fixed_deposits.lien_amount
--   2026_10_13_000005  facility_payments.payment_method += 'FdAdjustment',
--                      fd_withdrawals.adjusted_amount / settled_credit_facility_id
--
-- Requires MySQL 8.0.16+ / MariaDB 10.2.1+ (CHECK constraints, generated column).
-- BACK UP THE DATABASE FIRST. Run once, top to bottom. Not re-runnable: the
-- CREATE TABLEs use IF NOT EXISTS but ALTER ... ADD / MODIFY statements will
-- error on a second run (that means it already worked).
-- DDL auto-commits in MySQL, so this cannot be rolled back as one transaction;
-- if it fails partway, fix the cause and continue from the failing statement.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 2026_10_13_000001: fd_loan_policies
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fd_loan_policies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `funding_product_id` bigint unsigned NOT NULL,
  `max_ltv_percent` decimal(5,2) unsigned NOT NULL DEFAULT '90.00',
  `rate_mode` enum('spread','fixed') NOT NULL DEFAULT 'spread',
  `rate_spread_percent` decimal(6,3) unsigned NOT NULL DEFAULT '2.000',
  `fixed_rate_percent` decimal(6,3) unsigned DEFAULT NULL,
  `min_loan_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `max_loan_amount` decimal(15,2) unsigned DEFAULT NULL,
  `processing_fee_percent` decimal(5,2) unsigned NOT NULL DEFAULT '0.00',
  `min_days_to_maturity` smallint unsigned NOT NULL DEFAULT '30',
  `cap_term_to_maturity` tinyint(1) NOT NULL DEFAULT '1',
  `auto_adjust_on_maturity` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_loan_policies_funding_product_id_unique` (`funding_product_id`),
  CONSTRAINT `fd_loan_policies_funding_product_id_foreign` FOREIGN KEY (`funding_product_id`) REFERENCES `funding_products` (`id`),
  CONSTRAINT `chk_fdlp_ltv` CHECK ((`max_ltv_percent` > 0) AND (`max_ltv_percent` <= 100)),
  CONSTRAINT `chk_fdlp_rate` CHECK ((`rate_mode` <> 'fixed') OR (`fixed_rate_percent` IS NOT NULL)),
  CONSTRAINT `chk_fdlp_amounts` CHECK ((`max_loan_amount` IS NULL) OR (`max_loan_amount` >= `min_loan_amount`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2026_10_13_000002: new collateral type 'FixedDeposit'
-- ---------------------------------------------------------------------
ALTER TABLE `facility_collateral`
  MODIFY `collateral_type` ENUM('Vehicle', 'Jewelry', 'PawnedJewelry', 'FixedDeposit', 'Property', 'Other') NOT NULL;

-- ---------------------------------------------------------------------
-- 2026_10_13_000004 (part 1): fixed_deposits.lien_amount
-- (before the lien table so the lien table can be backfilled-checked against it)
-- ---------------------------------------------------------------------
ALTER TABLE `fixed_deposits`
  ADD COLUMN `lien_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00' AFTER `maturity_amount`,
  ADD INDEX `fixed_deposits_lien_amount_index` (`lien_amount`);
ALTER TABLE `fixed_deposits` ADD CONSTRAINT `chk_fd_lien` CHECK (`lien_amount` <= `principal_amount`);

-- ---------------------------------------------------------------------
-- 2026_10_13_000005 (part 1): fd_withdrawals columns used by the lien table's FK
-- ---------------------------------------------------------------------
ALTER TABLE `fd_withdrawals`
  ADD COLUMN `adjusted_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00' AFTER `total_amount`;
ALTER TABLE `fd_withdrawals` ADD CONSTRAINT `chk_fdw_adjusted` CHECK (`adjusted_amount` <= `total_amount`);
ALTER TABLE `fd_withdrawals`
  ADD COLUMN `settled_credit_facility_id` bigint unsigned DEFAULT NULL AFTER `closed_at`,
  ADD CONSTRAINT `fd_withdrawals_settled_credit_facility_id_foreign` FOREIGN KEY (`settled_credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 2026_10_13_000003: facility_collateral_fd_details (the lien)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `facility_collateral_fd_details` (
  `facility_collateral_id` bigint unsigned NOT NULL,
  `credit_facility_id` bigint unsigned NOT NULL,
  `fixed_deposit_id` bigint unsigned NOT NULL,
  `fd_loan_policy_id` bigint unsigned DEFAULT NULL,
  `fd_principal_snapshot` decimal(15,2) unsigned NOT NULL,
  `fd_rate_snapshot` decimal(6,3) unsigned NOT NULL,
  `fd_maturity_snapshot` date NOT NULL,
  `ltv_percent` decimal(5,2) unsigned NOT NULL,
  `lien_amount` decimal(15,2) unsigned NOT NULL,
  `max_loan_value` decimal(15,2) unsigned NOT NULL,
  `lien_status` enum('Active','Released','Settled') NOT NULL DEFAULT 'Active',
  `marked_at` datetime NOT NULL,
  `marked_by` bigint unsigned DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `released_by` bigint unsigned DEFAULT NULL,
  `release_reason` varchar(255) DEFAULT NULL,
  `settled_amount` decimal(15,2) unsigned DEFAULT NULL,
  `settlement_withdrawal_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `active_fd_id` bigint unsigned GENERATED ALWAYS AS (IF(`lien_status` = 'Active', `fixed_deposit_id`, NULL)) VIRTUAL,
  PRIMARY KEY (`facility_collateral_id`),
  UNIQUE KEY `uq_fcfd_active_fd` (`active_fd_id`),
  KEY `facility_collateral_fd_details_credit_facility_id_index` (`credit_facility_id`),
  KEY `idx_fcfd_fd_status` (`fixed_deposit_id`, `lien_status`),
  CONSTRAINT `fk_fcfd_collateral` FOREIGN KEY (`facility_collateral_id`) REFERENCES `facility_collateral` (`id`) ON DELETE CASCADE,
  CONSTRAINT `facility_collateral_fd_details_credit_facility_id_foreign` FOREIGN KEY (`credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `facility_collateral_fd_details_fixed_deposit_id_foreign` FOREIGN KEY (`fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`),
  CONSTRAINT `facility_collateral_fd_details_fd_loan_policy_id_foreign` FOREIGN KEY (`fd_loan_policy_id`) REFERENCES `fd_loan_policies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `facility_collateral_fd_details_marked_by_foreign` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `facility_collateral_fd_details_released_by_foreign` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `facility_collateral_fd_details_settlement_withdrawal_id_foreign` FOREIGN KEY (`settlement_withdrawal_id`) REFERENCES `fd_withdrawals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_fcfd_lien` CHECK ((`lien_amount` > 0) AND (`lien_amount` <= `fd_principal_snapshot`)),
  CONSTRAINT `chk_fcfd_ltv` CHECK ((`ltv_percent` > 0) AND (`ltv_percent` <= 100)),
  CONSTRAINT `chk_fcfd_closed` CHECK ((`lien_status` = 'Active') OR (`released_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2026_10_13_000005 (part 2): repayment made by adjusting an FD
-- ---------------------------------------------------------------------
ALTER TABLE `facility_payments`
  MODIFY `payment_method` ENUM('Cash', 'Bank', 'Cheque', 'FdAdjustment') NOT NULL DEFAULT 'Cash';

-- ---------------------------------------------------------------------
-- Record the 5 migrations as applied, so a later `php artisan migrate`
-- does not try to run them again.
-- ---------------------------------------------------------------------
SET @deploy_batch := (SELECT MAX(batch) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_10_13_000001_create_fd_loan_policies_table', @deploy_batch),
  ('2026_10_13_000002_add_fixed_deposit_to_facility_collateral_type', @deploy_batch),
  ('2026_10_13_000003_create_facility_collateral_fd_details_table', @deploy_batch),
  ('2026_10_13_000004_add_lien_columns_to_fixed_deposits', @deploy_batch),
  ('2026_10_13_000005_add_fd_adjustment_columns', @deploy_batch);

-- ---------------------------------------------------------------------
-- OPTIONAL: turn on loans-against-FD for existing FD products.
-- Nothing is enabled until a policy row exists (or is saved on the
-- Funding Products screen). This enables every product at 90% LTV,
-- FD rate + 2%. Edit the values or delete this block as needed.
-- ---------------------------------------------------------------------
-- INSERT INTO `fd_loan_policies` (`funding_product_id`, `created_at`, `updated_at`)
-- SELECT `id`, NOW(), NOW() FROM `funding_products`
-- WHERE `id` NOT IN (SELECT `funding_product_id` FROM `fd_loan_policies`);

-- Verify
SELECT COUNT(*) AS fd_loan_policies_rows FROM `fd_loan_policies`;
SHOW COLUMNS FROM `fixed_deposits` LIKE 'lien_amount';
SHOW TABLES LIKE 'facility_collateral_fd_details';
