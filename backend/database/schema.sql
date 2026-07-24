-- ============================================================================
-- ABSL Financial — Complete Database Schema
-- Lending / Pawning / Investment / Accounting Management System
-- MySQL 8.0+, InnoDB, utf8mb4
-- ============================================================================
-- Section 0 is the foundation already implemented (auth, branches, roles,
-- permissions) — included for a complete single-file reference. Sections 1-10
-- map 1:1 to the spec's modules 3.2-3.13 and are new. Apply section by
-- section; each is self-contained and ordered so FK targets always exist
-- before they're referenced.
--
-- Conventions used throughout:
--   - money columns:      DECIMAL(15,2)
--   - percentage columns: DECIMAL(6,3)
--   - `code` columns:     human-readable unique reference numbers (app-generated)
--   - `status` columns:   plain VARCHAR, not native ENUM (cheaper to extend)
--   - audit FKs (created_by/approved_by/...): ON DELETE SET NULL — history
--     must survive a user account being removed
--   - branch-owned tables carry `branch_id` (ON DELETE RESTRICT — a branch
--     with live data cannot be deleted)
--   - cross-module linkage (which loan a cashbook entry came from, etc) uses
--     a polymorphic `reference_type` + `reference_id` pair instead of a
--     dedicated FK per possible source table
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- SECTION 0 — Foundation (already implemented; reference only)
-- Modules: 3.12 Branch Management, 3.13 User Management & Security
-- ============================================================================

CREATE TABLE IF NOT EXISTS `roles` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(64) NOT NULL,
  `slug` VARCHAR(64) NOT NULL,
  `description` VARCHAR(255) NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `can_view_all_branches` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `roles_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- `type` = Branch | Center. A Center is a sub-unit used for SME/group-lending
-- (a branch runs several centers); `parent_id` links a Center to its owning
-- Branch and is null for Branch rows.
CREATE TABLE IF NOT EXISTS `branches` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `parent_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(128) NOT NULL,
  `code` VARCHAR(16) NOT NULL,
  `type` VARCHAR(16) NOT NULL DEFAULT 'Branch',
  `address` VARCHAR(255) NULL,
  `phone` VARCHAR(32) NULL,
  `email` VARCHAR(190) NULL,
  `is_head_office` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `branches_code_unique` (`code`),
  KEY `branches_status_index` (`status`),
  KEY `branches_parent_id_index` (`parent_id`),
  CONSTRAINT `branches_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `email_verified_at` DATETIME NULL DEFAULT NULL,
  `password` VARCHAR(191) NOT NULL,
  `role_id` BIGINT UNSIGNED NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `remember_token` VARCHAR(100) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_status_index` (`status`),
  CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `module` VARCHAR(64) NOT NULL,
  `action` VARCHAR(16) NOT NULL, -- View | Create | Edit | Delete | Approve | Export
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `role_perm_unique` (`role_id`, `module`, `action`),
  CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `module` VARCHAR(64) NOT NULL,
  `action` VARCHAR(16) NOT NULL,
  `allowed` TINYINT(1) NOT NULL DEFAULT 1, -- explicit override: 1=force-allow, 0=force-deny
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `user_perm_unique` (`user_id`, `module`, `action`),
  CONSTRAINT `user_permissions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 1 — Customer Management (spec 3.2)
-- ============================================================================

-- A "guarantor" is not a separate person-type — it's a customer acting in a
-- different capacity for a specific loan. Guarantors are therefore drawn
-- from this same table (see `loan_guarantors` in Section 2), not a
-- duplicate side-table: one KYC record per person, no re-entering the same
-- NIC/phone/address/employment data, and a person can be a borrower on
-- their own loan and a guarantor on someone else's without two records.
CREATE TABLE `customers` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `full_name` VARCHAR(191) NOT NULL,
  `nic_no` VARCHAR(32) NULL,
  `passport_no` VARCHAR(32) NULL,
  `driving_license_no` VARCHAR(32) NULL,
  `dob` DATE NULL,
  `gender` VARCHAR(16) NULL,
  `phone` VARCHAR(32) NULL,
  `alt_phone` VARCHAR(32) NULL,
  `email` VARCHAR(190) NULL,
  `address` TEXT NULL,
  `occupation_type` VARCHAR(20) NULL, -- Employed | SelfEmployed | BusinessOwner | Unemployed | Retired | Student | Other
  -- populated when occupation_type = Employed
  `employer_name` VARCHAR(191) NULL,
  `job_title` VARCHAR(128) NULL,
  `employer_address` VARCHAR(255) NULL,
  `employer_phone` VARCHAR(32) NULL,
  `employment_start_date` DATE NULL,
  -- populated when occupation_type = SelfEmployed | BusinessOwner
  `business_name` VARCHAR(191) NULL,
  `business_reg_no` VARCHAR(64) NULL,
  `business_type` VARCHAR(128) NULL,
  `business_address` VARCHAR(255) NULL,
  `business_phone` VARCHAR(32) NULL,
  `monthly_income` DECIMAL(15,2) NULL,
  `photo_path` VARCHAR(255) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'Active', -- Active | Blacklisted | Inactive
  -- Label, not a restriction: any customer can be picked as a loan guarantor
  -- regardless of this flag. Tags people registered primarily to guarantee
  -- someone else's loan, for quick filtering/display.
  `is_guarantor` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `customers_code_unique` (`code`),
  UNIQUE KEY `customers_nic_no_unique` (`nic_no`),
  KEY `customers_status_index` (`status`),
  CONSTRAINT `customers_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `customers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `customer_documents` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `doc_type` VARCHAR(32) NOT NULL, -- NIC | Passport | DrivingLicense | UtilityBill | Photo | Other
  `file_path` VARCHAR(255) NOT NULL,
  `uploaded_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `customer_documents_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 2 — Loan Management (spec 3.3)
-- ============================================================================

-- Self-referencing tree: a loan type can be a pure category (e.g. "SME") that
-- only exists to group children (e.g. "Daily", "Micro"), or a "product" leaf
-- that customers can actually be issued a loan against. `is_product` marks
-- which one a node is, independent of whether it currently has children, so a
-- category can later gain its own directly-issuable terms without restructuring.
CREATE TABLE `loan_types` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `parent_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(128) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `is_product` TINYINT(1) NOT NULL DEFAULT 1, -- true = selectable when issuing a loan; false = organizational category only
  `frequency` VARCHAR(16) NULL, -- daily | weekly | monthly | null (only meaningful on product leaves)
  `interest_method` VARCHAR(20) NULL, -- flat | reducing_balance (required when is_product)
  `interest_rate_percent` DECIMAL(6,3) NULL,
  `rate_period` VARCHAR(16) NULL DEFAULT 'monthly', -- monthly | annual
  `min_amount` DECIMAL(15,2) NULL,
  `max_amount` DECIMAL(15,2) NULL,
  `min_term_months` SMALLINT UNSIGNED NULL,
  `max_term_months` SMALLINT UNSIGNED NULL,
  `penalty_rate_percent` DECIMAL(6,3) NOT NULL DEFAULT 0,
  `penalty_method` VARCHAR(16) NOT NULL DEFAULT 'flat', -- flat | daily
  `processing_fee_percent` DECIMAL(6,3) NOT NULL DEFAULT 0,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `loan_types_code_unique` (`code`),
  KEY `loan_types_parent_id_index` (`parent_id`),
  CONSTRAINT `loan_types_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `loan_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `loans` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(32) NOT NULL,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `loan_type_id` BIGINT UNSIGNED NOT NULL,
  `principal_amount` DECIMAL(15,2) NOT NULL,
  `down_payment` DECIMAL(15,2) NOT NULL DEFAULT 0, -- paid upfront; the schedule amortizes (principal_amount - down_payment)
  `interest_rate_percent` DECIMAL(6,3) NOT NULL, -- defaults from loan_types, but negotiable per application
  `interest_method` VARCHAR(20) NOT NULL,
  `rate_period` VARCHAR(16) NOT NULL DEFAULT 'monthly', -- monthly | annual — snapshot from loan_types
  `term_months` SMALLINT UNSIGNED NOT NULL,
  `installment_frequency` VARCHAR(16) NOT NULL DEFAULT 'monthly', -- monthly | weekly | daily
  `purpose` VARCHAR(255) NULL,
  `loan_officer_id` BIGINT UNSIGNED NULL, -- sales/marketing officer, assigned at application time
  `recovery_officer_id` BIGINT UNSIGNED NULL, -- collections officer, assigned once active
  `disbursed_amount` DECIMAL(15,2) NULL,
  `disbursed_at` DATETIME NULL DEFAULT NULL,
  `first_installment_date` DATE NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'Pending', -- Pending|Approved|Rejected|Active|Closed|WrittenOff|Restructured
  `applied_by` BIGINT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL DEFAULT NULL,
  `rejected_reason` VARCHAR(255) NULL,
  `closed_at` DATETIME NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `loans_code_unique` (`code`),
  KEY `loans_status_index` (`status`),
  CONSTRAINT `loans_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loans_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loans_loan_type_id_foreign` FOREIGN KEY (`loan_type_id`) REFERENCES `loan_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loans_loan_officer_id_foreign` FOREIGN KEY (`loan_officer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_recovery_officer_id_foreign` FOREIGN KEY (`recovery_officer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_applied_by_foreign` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Security/collateral pledged against a loan — vehicle for a lease, property for
-- a mortgage, or none at all for an unsecured consumer loan.
CREATE TABLE `loan_collateral` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `loan_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(64) NOT NULL, -- Vehicle | Property | Equipment | Other
  `description` VARCHAR(255) NOT NULL,
  `estimated_value` DECIMAL(15,2) NULL,
  `document_path` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `loan_collateral_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- guarantor_id references `customers` directly — a guarantor is a customer
-- acting in a different capacity for this loan, not a separate person-type.
CREATE TABLE `loan_guarantors` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `loan_id` BIGINT UNSIGNED NOT NULL,
  `guarantor_id` BIGINT UNSIGNED NOT NULL,
  `guaranteed_amount` DECIMAL(15,2) NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `loan_guarantors_unique` (`loan_id`, `guarantor_id`),
  CONSTRAINT `loan_guarantors_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_guarantors_guarantor_id_foreign` FOREIGN KEY (`guarantor_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `loan_schedules` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `loan_id` BIGINT UNSIGNED NOT NULL,
  `installment_no` SMALLINT UNSIGNED NOT NULL,
  `due_date` DATE NOT NULL,
  `principal_due` DECIMAL(15,2) NOT NULL,
  `interest_due` DECIMAL(15,2) NOT NULL,
  `penalty_due` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total_due` DECIMAL(15,2) NOT NULL,
  `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `paid_at` DATETIME NULL DEFAULT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Pending', -- Pending | Paid | Partial | Overdue
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `loan_schedules_unique` (`loan_id`, `installment_no`),
  KEY `loan_schedules_due_date_index` (`due_date`),
  KEY `loan_schedules_status_index` (`status`),
  CONSTRAINT `loan_schedules_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `loan_payments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(32) NOT NULL,
  `loan_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `payment_method` VARCHAR(16) NOT NULL DEFAULT 'Cash', -- Cash | Bank | Cheque
  `received_by` BIGINT UNSIGNED NULL,
  `paid_at` DATETIME NOT NULL,
  `receipt_no` VARCHAR(32) NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `loan_payments_code_unique` (`code`),
  CONSTRAINT `loan_payments_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loan_payments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loan_payments_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Splits one payment across the installment(s) it settles — needed because a
-- single receipt can cover part of one due, all of one due, or several dues.
CREATE TABLE `loan_payment_allocations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `loan_payment_id` BIGINT UNSIGNED NOT NULL,
  `loan_schedule_id` BIGINT UNSIGNED NOT NULL,
  `principal_applied` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `interest_applied` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `penalty_applied` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `loan_payment_allocations_payment_id_foreign` FOREIGN KEY (`loan_payment_id`) REFERENCES `loan_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_payment_allocations_schedule_id_foreign` FOREIGN KEY (`loan_schedule_id`) REFERENCES `loan_schedules` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `loan_restructures` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `loan_id` BIGINT UNSIGNED NOT NULL,
  `old_term_months` SMALLINT UNSIGNED NOT NULL,
  `new_term_months` SMALLINT UNSIGNED NOT NULL,
  `old_interest_rate_percent` DECIMAL(6,3) NOT NULL,
  `new_interest_rate_percent` DECIMAL(6,3) NOT NULL,
  `reason` VARCHAR(255) NULL,
  `requested_by` BIGINT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL DEFAULT NULL,
  `effective_date` DATE NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `loan_restructures_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_restructures_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_restructures_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 3 — Funding / Investment Management (spec 3.4)
-- ============================================================================

CREATE TABLE `investors` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `full_name` VARCHAR(191) NOT NULL,
  `nic_no` VARCHAR(32) NULL,
  `phone` VARCHAR(32) NULL,
  `email` VARCHAR(190) NULL,
  `address` TEXT NULL,
  `bank_account_no` VARCHAR(64) NULL,
  `bank_name` VARCHAR(128) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `investors_code_unique` (`code`),
  CONSTRAINT `investors_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `investors_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `investment_plans` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(128) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `tenure_months` SMALLINT UNSIGNED NOT NULL,
  `interest_rate_percent` DECIMAL(6,3) NOT NULL,
  `interest_payout` VARCHAR(16) NOT NULL DEFAULT 'at_maturity', -- monthly | at_maturity
  `min_amount` DECIMAL(15,2) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `investment_plans_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `investments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(32) NOT NULL,
  `investor_id` BIGINT UNSIGNED NOT NULL,
  `investment_plan_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `principal_amount` DECIMAL(15,2) NOT NULL,
  `interest_rate_percent` DECIMAL(6,3) NOT NULL, -- snapshot from investment_plans
  `start_date` DATE NOT NULL,
  `maturity_date` DATE NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active', -- Active | Matured | Withdrawn | Renewed
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `investments_code_unique` (`code`),
  KEY `investments_status_index` (`status`),
  CONSTRAINT `investments_investor_id_foreign` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `investments_plan_id_foreign` FOREIGN KEY (`investment_plan_id`) REFERENCES `investment_plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `investments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `investments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `investment_interest_payouts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `investment_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `paid_at` DATETIME NOT NULL,
  `paid_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `investment_payouts_investment_id_foreign` FOREIGN KEY (`investment_id`) REFERENCES `investments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `investment_payouts_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `investment_withdrawals` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `investment_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `type` VARCHAR(16) NOT NULL DEFAULT 'full', -- partial | full | premature
  `penalty_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `withdrawn_at` DATETIME NOT NULL,
  `processed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `investment_withdrawals_investment_id_foreign` FOREIGN KEY (`investment_id`) REFERENCES `investments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `investment_withdrawals_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `investment_renewals` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `investment_id` BIGINT UNSIGNED NOT NULL,
  `old_maturity_date` DATE NOT NULL,
  `new_maturity_date` DATE NOT NULL,
  `new_principal_amount` DECIMAL(15,2) NOT NULL,
  `renewed_at` DATETIME NOT NULL,
  `processed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `investment_renewals_investment_id_foreign` FOREIGN KEY (`investment_id`) REFERENCES `investments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `investment_renewals_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `investment_certificates` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `investment_id` BIGINT UNSIGNED NOT NULL,
  `certificate_no` VARCHAR(32) NOT NULL,
  `issued_at` DATETIME NOT NULL,
  `file_path` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `investment_certificates_no_unique` (`certificate_no`),
  CONSTRAINT `investment_certificates_investment_id_foreign` FOREIGN KEY (`investment_id`) REFERENCES `investments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which investor's deposit funded which loan — capital-source tracking.
CREATE TABLE `loan_funding_allocations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `investment_id` BIGINT UNSIGNED NOT NULL,
  `loan_id` BIGINT UNSIGNED NOT NULL,
  `allocated_amount` DECIMAL(15,2) NOT NULL,
  `allocated_at` DATETIME NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `loan_funding_allocations_investment_id_foreign` FOREIGN KEY (`investment_id`) REFERENCES `investments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loan_funding_allocations_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 4 — Pawning Management (spec 3.5)
-- ============================================================================

CREATE TABLE `pawn_tickets` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(32) NOT NULL,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `valuation_amount` DECIMAL(15,2) NOT NULL,
  `loan_amount` DECIMAL(15,2) NOT NULL,
  `interest_rate_percent` DECIMAL(6,3) NOT NULL,
  `term_months` SMALLINT UNSIGNED NOT NULL,
  `issued_at` DATETIME NOT NULL,
  `due_date` DATE NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'Active', -- Active|Redeemed|Renewed|Overdue|Auctioned|Closed
  `created_by` BIGINT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `pawn_tickets_code_unique` (`code`),
  KEY `pawn_tickets_status_index` (`status`),
  CONSTRAINT `pawn_tickets_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pawn_tickets_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pawn_tickets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pawn_tickets_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `pawn_items` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `pawn_ticket_id` BIGINT UNSIGNED NOT NULL,
  `item_type` VARCHAR(32) NOT NULL DEFAULT 'Gold', -- Gold | Jewelry | Other
  `description` VARCHAR(255) NOT NULL,
  `gross_weight` DECIMAL(10,3) NULL, -- grams
  `net_weight` DECIMAL(10,3) NULL,
  `purity` VARCHAR(16) NULL, -- e.g. 22K, 18K
  `estimated_value` DECIMAL(15,2) NOT NULL,
  `photo_path` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `pawn_items_pawn_ticket_id_foreign` FOREIGN KEY (`pawn_ticket_id`) REFERENCES `pawn_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `pawn_interest_payments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `pawn_ticket_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `paid_at` DATETIME NOT NULL,
  `received_by` BIGINT UNSIGNED NULL,
  `receipt_no` VARCHAR(32) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `pawn_interest_payments_ticket_id_foreign` FOREIGN KEY (`pawn_ticket_id`) REFERENCES `pawn_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pawn_interest_payments_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `pawn_redemptions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `pawn_ticket_id` BIGINT UNSIGNED NOT NULL,
  `principal_amount` DECIMAL(15,2) NOT NULL,
  `interest_amount` DECIMAL(15,2) NOT NULL,
  `total_paid` DECIMAL(15,2) NOT NULL,
  `redeemed_at` DATETIME NOT NULL,
  `processed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `pawn_redemptions_ticket_id_foreign` FOREIGN KEY (`pawn_ticket_id`) REFERENCES `pawn_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pawn_redemptions_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `pawn_renewals` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `pawn_ticket_id` BIGINT UNSIGNED NOT NULL,
  `old_due_date` DATE NOT NULL,
  `new_due_date` DATE NOT NULL,
  `interest_settled_amount` DECIMAL(15,2) NOT NULL,
  `renewed_at` DATETIME NOT NULL,
  `processed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `pawn_renewals_ticket_id_foreign` FOREIGN KEY (`pawn_ticket_id`) REFERENCES `pawn_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pawn_renewals_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `auctions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(32) NOT NULL,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `auction_date` DATE NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Scheduled', -- Scheduled | Completed | Cancelled
  `notes` VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `auctions_code_unique` (`code`),
  CONSTRAINT `auctions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `auctions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `auction_items` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `auction_id` BIGINT UNSIGNED NOT NULL,
  `pawn_ticket_id` BIGINT UNSIGNED NOT NULL,
  `pawn_item_id` BIGINT UNSIGNED NOT NULL,
  `reserve_price` DECIMAL(15,2) NOT NULL,
  `sold_price` DECIMAL(15,2) NULL,
  `buyer_name` VARCHAR(191) NULL,
  `buyer_contact` VARCHAR(64) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Pending', -- Pending | Sold | Unsold
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `auction_items_auction_id_foreign` FOREIGN KEY (`auction_id`) REFERENCES `auctions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `auction_items_pawn_ticket_id_foreign` FOREIGN KEY (`pawn_ticket_id`) REFERENCES `pawn_tickets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `auction_items_pawn_item_id_foreign` FOREIGN KEY (`pawn_item_id`) REFERENCES `pawn_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 5 — Cashbook Management (spec 3.6)
-- ============================================================================

CREATE TABLE `cash_accounts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `current_balance` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `cash_accounts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cashbook_entries` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `cash_account_id` BIGINT UNSIGNED NOT NULL,
  `entry_date` DATE NOT NULL,
  `type` VARCHAR(8) NOT NULL, -- in | out
  `category` VARCHAR(32) NOT NULL, -- LoanCollection | Expense | Transfer | Deposit | Other
  `amount` DECIMAL(15,2) NOT NULL,
  `reference_type` VARCHAR(191) NULL, -- e.g. App\Models\LoanPayment
  `reference_id` BIGINT UNSIGNED NULL,
  `description` VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `cashbook_entries_reference_index` (`reference_type`, `reference_id`),
  KEY `cashbook_entries_entry_date_index` (`entry_date`),
  CONSTRAINT `cashbook_entries_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cashbook_entries_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cashbook_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cashbook_entries_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cash_transfers` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `from_cash_account_id` BIGINT UNSIGNED NOT NULL,
  `to_cash_account_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `transferred_at` DATETIME NOT NULL,
  `requested_by` BIGINT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Pending',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `cash_transfers_from_account_foreign` FOREIGN KEY (`from_cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cash_transfers_to_account_foreign` FOREIGN KEY (`to_cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cash_transfers_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cash_transfers_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cash_reconciliations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `cash_account_id` BIGINT UNSIGNED NOT NULL,
  `reconciliation_date` DATE NOT NULL,
  `opening_balance` DECIMAL(15,2) NOT NULL,
  `expected_closing_balance` DECIMAL(15,2) NOT NULL,
  `actual_counted_balance` DECIMAL(15,2) NOT NULL,
  `variance` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `reconciled_by` BIGINT UNSIGNED NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `cash_reconciliations_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cash_reconciliations_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cash_reconciliations_reconciled_by_foreign` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 6 — Bank Management (spec 3.7)
-- ============================================================================

CREATE TABLE `bank_accounts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `branch_id` BIGINT UNSIGNED NULL, -- NULL = company-wide account
  `bank_name` VARCHAR(128) NOT NULL,
  `branch_name` VARCHAR(128) NULL, -- the bank's own branch name
  `account_no` VARCHAR(64) NOT NULL,
  `account_name` VARCHAR(191) NOT NULL,
  `account_type` VARCHAR(32) NULL,
  `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `current_balance` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `bank_accounts_account_no_unique` (`account_no`),
  CONSTRAINT `bank_accounts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `bank_transactions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bank_account_id` BIGINT UNSIGNED NOT NULL,
  `txn_date` DATE NOT NULL,
  `type` VARCHAR(20) NOT NULL, -- Deposit | Withdrawal | TransferIn | TransferOut | Charge | Interest
  `amount` DECIMAL(15,2) NOT NULL,
  `reference_type` VARCHAR(191) NULL,
  `reference_id` BIGINT UNSIGNED NULL,
  `description` VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `bank_transactions_reference_index` (`reference_type`, `reference_id`),
  KEY `bank_transactions_txn_date_index` (`txn_date`),
  CONSTRAINT `bank_transactions_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `bank_transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `bank_transfers` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `from_bank_account_id` BIGINT UNSIGNED NOT NULL,
  `to_bank_account_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `transferred_at` DATETIME NOT NULL,
  `requested_by` BIGINT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Pending',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `bank_transfers_from_account_foreign` FOREIGN KEY (`from_bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `bank_transfers_to_account_foreign` FOREIGN KEY (`to_bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `bank_transfers_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transfers_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `bank_reconciliations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bank_account_id` BIGINT UNSIGNED NOT NULL,
  `statement_date` DATE NOT NULL,
  `statement_balance` DECIMAL(15,2) NOT NULL,
  `book_balance` DECIMAL(15,2) NOT NULL,
  `variance` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `reconciled_by` BIGINT UNSIGNED NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `bank_reconciliations_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `bank_reconciliations_reconciled_by_foreign` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `bank_reconciliation_items` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bank_reconciliation_id` BIGINT UNSIGNED NOT NULL,
  `bank_transaction_id` BIGINT UNSIGNED NULL,
  `description` VARCHAR(255) NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `matched` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `bank_reconciliation_items_reconciliation_id_foreign` FOREIGN KEY (`bank_reconciliation_id`) REFERENCES `bank_reconciliations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bank_reconciliation_items_transaction_id_foreign` FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 7 — Accounting Management (spec 3.8)
-- Full double-entry core. General Ledger, Trial Balance, P&L, Balance Sheet
-- and Cash Flow are all *derived reports* queried over journal_lines — they
-- are not stored tables.
-- ============================================================================

CREATE TABLE `chart_of_accounts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(16) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `type` VARCHAR(16) NOT NULL, -- asset | liability | equity | income | expense
  `parent_id` BIGINT UNSIGNED NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `chart_of_accounts_code_unique` (`code`),
  KEY `chart_of_accounts_type_index` (`type`),
  CONSTRAINT `chart_of_accounts_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `journal_entries` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(32) NOT NULL,
  `entry_date` DATE NOT NULL,
  `reference_type` VARCHAR(191) NULL, -- e.g. App\Models\LoanDisbursement
  `reference_id` BIGINT UNSIGNED NULL,
  `description` VARCHAR(255) NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `posted_by` BIGINT UNSIGNED NULL,
  `posted_at` DATETIME NULL DEFAULT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Draft', -- Draft | Posted | Reversed
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `journal_entries_code_unique` (`code`),
  KEY `journal_entries_reference_index` (`reference_type`, `reference_id`),
  KEY `journal_entries_entry_date_index` (`entry_date`),
  CONSTRAINT `journal_entries_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_entries_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `journal_lines` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `journal_entry_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `debit` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `credit` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `description` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `journal_lines_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `journal_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 8 — Expense Management (spec 3.9)
-- ============================================================================

CREATE TABLE `expense_categories` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(128) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `account_id` BIGINT UNSIGNED NULL, -- posts to this chart_of_accounts expense account
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `expense_categories_code_unique` (`code`),
  CONSTRAINT `expense_categories_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `vendors` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `contact_person` VARCHAR(191) NULL,
  `phone` VARCHAR(32) NULL,
  `email` VARCHAR(190) NULL,
  `address` TEXT NULL,
  `tax_no` VARCHAR(64) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `vendors_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `expenses` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(32) NOT NULL,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  `expense_category_id` BIGINT UNSIGNED NOT NULL,
  `vendor_id` BIGINT UNSIGNED NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `expense_date` DATE NOT NULL,
  `description` VARCHAR(255) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Pending', -- Pending | Approved | Rejected | Paid
  `requested_by` BIGINT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `expenses_code_unique` (`code`),
  KEY `expenses_status_index` (`status`),
  CONSTRAINT `expenses_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `expenses_category_id_foreign` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `expenses_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `payment_vouchers` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(32) NOT NULL,
  `expense_id` BIGINT UNSIGNED NULL,
  `vendor_id` BIGINT UNSIGNED NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `payment_method` VARCHAR(16) NOT NULL DEFAULT 'Cash', -- Cash | Bank | Cheque
  `paid_at` DATETIME NOT NULL,
  `paid_by` BIGINT UNSIGNED NULL,
  `bank_account_id` BIGINT UNSIGNED NULL,
  `cheque_no` VARCHAR(32) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Issued',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `payment_vouchers_code_unique` (`code`),
  CONSTRAINT `payment_vouchers_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_vouchers_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_vouchers_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_vouchers_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 9 — Notification Management (spec 3.11)
-- ============================================================================

CREATE TABLE `notification_templates` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(64) NOT NULL, -- e.g. loan_due_reminder, pawn_expiry_alert
  `channel` VARCHAR(16) NOT NULL, -- sms | email | in_app
  `subject` VARCHAR(191) NULL,
  `body_template` TEXT NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `notification_templates_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `notifications` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `notifiable_type` VARCHAR(191) NULL, -- App\Models\Customer or App\Models\User
  `notifiable_id` BIGINT UNSIGNED NULL,
  `type` VARCHAR(64) NOT NULL, -- e.g. LoanDueReminder, OverdueAlert, DepositExpiryAlert
  `channel` VARCHAR(16) NOT NULL, -- sms | email | in_app
  `title` VARCHAR(191) NULL,
  `body` TEXT NULL,
  `reference_type` VARCHAR(191) NULL,
  `reference_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Pending', -- Pending | Sent | Failed | Read
  `sent_at` DATETIME NULL DEFAULT NULL,
  `read_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  KEY `notifications_notifiable_index` (`notifiable_type`, `notifiable_id`),
  KEY `notifications_reference_index` (`reference_type`, `reference_id`),
  KEY `notifications_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `notification_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `notification_id` BIGINT UNSIGNED NOT NULL,
  `attempt_no` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `provider` VARCHAR(64) NULL,
  `response_code` VARCHAR(32) NULL,
  `response_message` VARCHAR(255) NULL,
  `attempted_at` DATETIME NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  CONSTRAINT `notification_logs_notification_id_foreign` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SECTION 10 — Security & Audit (spec 3.13 additions beyond the foundation)
-- ============================================================================

CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(64) NOT NULL, -- e.g. created, updated, deleted, approved
  `auditable_type` VARCHAR(191) NOT NULL,
  `auditable_id` BIGINT UNSIGNED NOT NULL,
  `old_values` JSON NULL,
  `new_values` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  KEY `audit_logs_auditable_index` (`auditable_type`, `auditable_id`),
  KEY `audit_logs_user_id_index` (`user_id`),
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `login_activities` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NULL,
  `email` VARCHAR(191) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `status` VARCHAR(16) NOT NULL, -- success | failed
  `attempted_at` DATETIME NOT NULL,
  KEY `login_activities_user_id_index` (`user_id`),
  KEY `login_activities_attempted_at_index` (`attempted_at`),
  CONSTRAINT `login_activities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
