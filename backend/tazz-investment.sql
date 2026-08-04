-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 03, 2026 at 10:41 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `tazz-investment`
--

-- --------------------------------------------------------

--
-- Table structure for table `banks`
--

CREATE TABLE `banks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL,
  `code` varchar(32) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bank_accounts`
--

CREATE TABLE `bank_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(128) NOT NULL,
  `bank_name` varchar(128) NOT NULL,
  `branch_name` varchar(128) DEFAULT NULL,
  `account_number` varchar(64) NOT NULL,
  `account_type` varchar(20) NOT NULL DEFAULT 'Savings',
  `currency` varchar(8) NOT NULL DEFAULT 'LKR',
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bank_account_links`
--

CREATE TABLE `bank_account_links` (
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `bank_account_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bank_reconciliations`
--

CREATE TABLE `bank_reconciliations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `bank_account_id` bigint(20) UNSIGNED NOT NULL,
  `statement_date` date NOT NULL,
  `statement_balance` decimal(15,2) NOT NULL,
  `book_balance` decimal(15,2) NOT NULL,
  `difference` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` varchar(16) NOT NULL DEFAULT 'Pending',
  `notes` varchar(255) DEFAULT NULL,
  `reconciled_by` bigint(20) UNSIGNED DEFAULT NULL,
  `reconciled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bank_transactions`
--

CREATE TABLE `bank_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `bank_account_id` bigint(20) UNSIGNED NOT NULL,
  `txn_date` date NOT NULL,
  `type` varchar(16) NOT NULL,
  `reference_no` varchar(64) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Cleared',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(128) NOT NULL,
  `code` varchar(16) NOT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'Branch',
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `is_head_office` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cashbook_entries`
--

CREATE TABLE `cashbook_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `cash_account_id` bigint(20) UNSIGNED NOT NULL,
  `entry_date` date NOT NULL,
  `type` varchar(8) NOT NULL,
  `category` varchar(32) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `reference_type` varchar(191) DEFAULT NULL,
  `reference_id` bigint(20) UNSIGNED DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cash_accounts`
--

CREATE TABLE `cash_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cash_reconciliations`
--

CREATE TABLE `cash_reconciliations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `as_at_date` date NOT NULL,
  `system_cash_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cheque_in_hand_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `postdated_cheque_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `today_cash_receipts` decimal(15,2) NOT NULL DEFAULT 0.00,
  `today_cheque_receipts` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cheque_realized_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `receipts_to_be_cancelled` decimal(15,2) NOT NULL DEFAULT 0.00,
  `encashment` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cash_float` decimal(15,2) NOT NULL DEFAULT 0.00,
  `petty_cash` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other_branch_cash` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other_branch_code` varchar(16) DEFAULT NULL,
  `other_branch_cheque` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other_branch_cheque_code` varchar(16) DEFAULT NULL,
  `total_cash` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_cheque` decimal(15,2) NOT NULL DEFAULT 0.00,
  `denominations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`denominations`)),
  `coins_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `counted_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `variance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `reconciled_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cash_till_links`
--

CREATE TABLE `cash_till_links` (
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `cash_account_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cash_transfers`
--

CREATE TABLE `cash_transfers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `from_cash_account_id` bigint(20) UNSIGNED NOT NULL,
  `to_cash_account_id` bigint(20) UNSIGNED NOT NULL,
  `transfer_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Pending',
  `requested_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chart_of_accounts`
--

CREATE TABLE `chart_of_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(16) NOT NULL,
  `name` varchar(128) NOT NULL,
  `type` varchar(16) NOT NULL,
  `normal_balance` varchar(8) NOT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `full_name` varchar(191) NOT NULL,
  `title` varchar(10) DEFAULT NULL,
  `surname` varchar(100) DEFAULT NULL,
  `name_in_full` varchar(191) DEFAULT NULL,
  `initials` varchar(32) DEFAULT NULL,
  `nic_no` varchar(32) DEFAULT NULL,
  `passport_no` varchar(32) DEFAULT NULL,
  `driving_license_no` varchar(32) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `gender` varchar(16) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `alt_phone` varchar(32) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `occupation_type` varchar(20) DEFAULT NULL,
  `employer_name` varchar(191) DEFAULT NULL,
  `job_title` varchar(128) DEFAULT NULL,
  `employer_address` varchar(255) DEFAULT NULL,
  `employer_phone` varchar(32) DEFAULT NULL,
  `employment_start_date` date DEFAULT NULL,
  `business_name` varchar(191) DEFAULT NULL,
  `business_reg_no` varchar(64) DEFAULT NULL,
  `business_type` varchar(128) DEFAULT NULL,
  `business_address` varchar(255) DEFAULT NULL,
  `business_phone` varchar(32) DEFAULT NULL,
  `monthly_income` decimal(15,2) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `is_guarantor` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_documents`
--

CREATE TABLE `customer_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `doc_type` varchar(32) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `expense_category_id` bigint(20) UNSIGNED NOT NULL,
  `vendor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `expense_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Pending',
  `requested_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL,
  `code` varchar(32) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(191) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fd_deposits`
--

CREATE TABLE `fd_deposits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `deposit_number` varchar(64) NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `deposit_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` varchar(16) NOT NULL,
  `reference_no` varchar(64) DEFAULT NULL,
  `bank_name` varchar(128) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Available',
  `fixed_deposit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fd_interest_payments`
--

CREATE TABLE `fd_interest_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fixed_deposit_id` bigint(20) UNSIGNED NOT NULL,
  `installment_no` smallint(5) UNSIGNED NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Pending',
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_at` date DEFAULT NULL,
  `payment_method` varchar(16) DEFAULT NULL,
  `reference_no` varchar(64) DEFAULT NULL,
  `paid_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fd_renewals`
--

CREATE TABLE `fd_renewals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `old_fixed_deposit_id` bigint(20) UNSIGNED NOT NULL,
  `new_fixed_deposit_id` bigint(20) UNSIGNED NOT NULL,
  `old_principal` decimal(15,2) NOT NULL,
  `additional_deposit_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `new_principal` decimal(15,2) NOT NULL,
  `additional_deposit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `new_term_months` smallint(5) UNSIGNED NOT NULL,
  `renewed_at` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fd_withdrawals`
--

CREATE TABLE `fd_withdrawals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fixed_deposit_id` bigint(20) UNSIGNED NOT NULL,
  `voucher_no` varchar(64) NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `interest_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL,
  `payment_method` varchar(16) NOT NULL,
  `reference_no` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `closed_at` date NOT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fixed_deposits`
--

CREATE TABLE `fixed_deposits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fd_number` varchar(64) NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `funding_product_id` bigint(20) UNSIGNED NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `interest_rate_percent` decimal(6,3) NOT NULL,
  `interest_payment_method` varchar(16) NOT NULL,
  `term_months` smallint(5) UNSIGNED NOT NULL,
  `start_date` date NOT NULL,
  `maturity_date` date NOT NULL,
  `monthly_interest_amount` decimal(15,2) DEFAULT NULL,
  `total_interest_amount` decimal(15,2) NOT NULL,
  `maturity_amount` decimal(15,2) NOT NULL,
  `next_interest_date` date DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `renewed_from_id` bigint(20) UNSIGNED DEFAULT NULL,
  `renewed_to_id` bigint(20) UNSIGNED DEFAULT NULL,
  `closed_at` date DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `funding_products`
--

CREATE TABLE `funding_products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL,
  `code` varchar(32) NOT NULL,
  `interest_method` varchar(16) NOT NULL,
  `annual_rate_percent` decimal(6,3) NOT NULL,
  `monthly_rate_percent` decimal(6,3) DEFAULT NULL,
  `rate_is_manual` tinyint(1) NOT NULL DEFAULT 0,
  `allowed_terms_months` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`allowed_terms_months`)),
  `allow_custom_term` tinyint(1) NOT NULL DEFAULT 0,
  `min_deposit_amount` decimal(15,2) NOT NULL,
  `max_deposit_amount` decimal(15,2) DEFAULT NULL,
  `reference_format` varchar(191) DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `generated_numbers`
--

CREATE TABLE `generated_numbers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `module` varchar(32) NOT NULL,
  `number` varchar(64) NOT NULL,
  `reference_type` varchar(191) DEFAULT NULL,
  `reference_id` bigint(20) UNSIGNED DEFAULT NULL,
  `branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `loan_type_id` bigint(20) UNSIGNED DEFAULT NULL,
  `funding_product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `year` smallint(5) UNSIGNED DEFAULT NULL,
  `sequence` int(10) UNSIGNED NOT NULL,
  `generated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `entry_date` date NOT NULL,
  `reference_type` varchar(191) DEFAULT NULL,
  `reference_id` bigint(20) UNSIGNED DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `posted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Posted',
  `reversal_of_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_lines`
--

CREATE TABLE `journal_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `journal_entry_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loans`
--

CREATE TABLE `loans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `loan_type_id` bigint(20) UNSIGNED NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `down_payment` decimal(15,2) NOT NULL DEFAULT 0.00,
  `interest_rate_percent` decimal(6,3) NOT NULL,
  `interest_method` varchar(20) NOT NULL,
  `rate_period` varchar(16) NOT NULL DEFAULT 'monthly',
  `term_months` smallint(5) UNSIGNED NOT NULL,
  `installment_frequency` varchar(16) NOT NULL DEFAULT 'monthly',
  `purpose` varchar(255) DEFAULT NULL,
  `application_date` date DEFAULT NULL,
  `reference_name` varchar(150) DEFAULT NULL,
  `reference_id_no` varchar(64) DEFAULT NULL,
  `reference_address` varchar(255) DEFAULT NULL,
  `loan_officer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recovery_officer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `disbursed_amount` decimal(15,2) DEFAULT NULL,
  `disbursed_at` datetime DEFAULT NULL,
  `first_installment_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `is_terminated` tinyint(1) NOT NULL DEFAULT 0,
  `terminated_at` datetime DEFAULT NULL,
  `is_legal` tinyint(1) NOT NULL DEFAULT 0,
  `legal_at` datetime DEFAULT NULL,
  `seizing_order_issued` tinyint(1) NOT NULL DEFAULT 0,
  `seizing_order_at` datetime DEFAULT NULL,
  `is_repossessed` tinyint(1) NOT NULL DEFAULT 0,
  `repossessed_at` datetime DEFAULT NULL,
  `freeze_date` date DEFAULT NULL,
  `confirmed_date` date DEFAULT NULL,
  `confirmed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `applied_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_reason` varchar(255) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_charges`
--

CREATE TABLE `loan_charges` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `charge_type` varchar(32) NOT NULL,
  `due_amount` decimal(15,2) NOT NULL,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `charged_at` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_charge_allocations`
--

CREATE TABLE `loan_charge_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_payment_id` bigint(20) UNSIGNED NOT NULL,
  `loan_charge_id` bigint(20) UNSIGNED NOT NULL,
  `amount_applied` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_collateral`
--

CREATE TABLE `loan_collateral` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(64) NOT NULL,
  `vehicle_type` varchar(30) DEFAULT NULL,
  `make_model` varchar(150) DEFAULT NULL,
  `registration_no` varchar(50) DEFAULT NULL,
  `chassis_no` varchar(100) DEFAULT NULL,
  `engine_no` varchar(100) DEFAULT NULL,
  `year_of_manufacture` smallint(5) UNSIGNED DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `estimated_value` decimal(15,2) DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_collateral_documents`
--

CREATE TABLE `loan_collateral_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_collateral_id` bigint(20) UNSIGNED NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_diary_notes`
--

CREATE TABLE `loan_diary_notes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `note` varchar(500) NOT NULL,
  `follow_up_date` date DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_documents`
--

CREATE TABLE `loan_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `doc_type` varchar(32) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_guarantors`
--

CREATE TABLE `loan_guarantors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `guarantor_id` bigint(20) UNSIGNED NOT NULL,
  `guaranteed_amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_payments`
--

CREATE TABLE `loan_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `approval_status` varchar(20) NOT NULL DEFAULT 'Approved',
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `payment_method` varchar(16) NOT NULL DEFAULT 'Cash',
  `clearance_status` varchar(16) DEFAULT NULL,
  `is_settlement` tinyint(1) NOT NULL DEFAULT 0,
  `received_by` bigint(20) UNSIGNED DEFAULT NULL,
  `paid_at` datetime NOT NULL,
  `receipt_no` varchar(32) DEFAULT NULL,
  `bank_name` varchar(191) DEFAULT NULL,
  `cheque_date` date DEFAULT NULL,
  `realized_at` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` bigint(20) UNSIGNED DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_payment_allocations`
--

CREATE TABLE `loan_payment_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_payment_id` bigint(20) UNSIGNED NOT NULL,
  `loan_schedule_id` bigint(20) UNSIGNED NOT NULL,
  `principal_applied` decimal(15,2) NOT NULL DEFAULT 0.00,
  `interest_applied` decimal(15,2) NOT NULL DEFAULT 0.00,
  `penalty_applied` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_post_dated_cheques`
--

CREATE TABLE `loan_post_dated_cheques` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `cheque_no` varchar(32) NOT NULL,
  `bank_name` varchar(128) NOT NULL,
  `cheque_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Pending',
  `handed_at` date DEFAULT NULL,
  `realized_at` date DEFAULT NULL,
  `loan_payment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_schedules`
--

CREATE TABLE `loan_schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `installment_no` smallint(5) UNSIGNED NOT NULL,
  `due_date` date NOT NULL,
  `principal_due` decimal(15,2) NOT NULL,
  `interest_due` decimal(15,2) NOT NULL,
  `penalty_due` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_due` decimal(15,2) NOT NULL,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_at` datetime DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_types`
--

CREATE TABLE `loan_types` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(128) NOT NULL,
  `code` varchar(32) NOT NULL,
  `reference_format` varchar(191) DEFAULT NULL,
  `is_product` tinyint(1) NOT NULL DEFAULT 1,
  `frequency` varchar(16) DEFAULT NULL,
  `collection_days` varchar(16) NOT NULL DEFAULT 'all_days',
  `interest_method` varchar(20) DEFAULT NULL,
  `interest_rate_percent` decimal(6,3) DEFAULT NULL,
  `rate_period` varchar(16) DEFAULT 'monthly',
  `min_amount` decimal(15,2) DEFAULT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `min_term_months` smallint(5) UNSIGNED DEFAULT NULL,
  `max_term_months` smallint(5) UNSIGNED DEFAULT NULL,
  `penalty_rate_percent` decimal(6,3) NOT NULL DEFAULT 0.000,
  `penalty_method` varchar(16) NOT NULL DEFAULT 'flat',
  `grace_period_days` smallint(5) UNSIGNED NOT NULL DEFAULT 5,
  `default_charge_rate_percent` decimal(6,3) NOT NULL DEFAULT 0.000,
  `processing_fee_percent` decimal(6,3) NOT NULL DEFAULT 0.000,
  `commission_type` varchar(16) NOT NULL DEFAULT 'fixed',
  `commission_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `min_paid_installments` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_waivers`
--

CREATE TABLE `loan_waivers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `waiver_type` varchar(32) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `waived_at` date NOT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(191) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `number_sequences`
--

CREATE TABLE `number_sequences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `module` varchar(32) NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `loan_type_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `funding_product_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `year` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `last_sequence` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(191) NOT NULL,
  `token` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_vouchers`
--

CREATE TABLE `payment_vouchers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `expense_id` bigint(20) UNSIGNED DEFAULT NULL,
  `vendor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` varchar(16) NOT NULL DEFAULT 'Cash',
  `paid_at` datetime NOT NULL,
  `paid_by` bigint(20) UNSIGNED DEFAULT NULL,
  `cash_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `bank_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cheque_no` varchar(32) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Issued',
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(191) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(64) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `can_view_all_branches` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `module` varchar(64) NOT NULL,
  `action` varchar(16) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_executive_commissions`
--

CREATE TABLE `sales_executive_commissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `loan_id` bigint(20) UNSIGNED NOT NULL,
  `sales_executive_id` bigint(20) UNSIGNED NOT NULL,
  `loan_type_id` bigint(20) UNSIGNED NOT NULL,
  `cycle_no` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `from_installment` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `commission_type` varchar(16) NOT NULL,
  `commission_value` decimal(15,2) NOT NULL,
  `commission_amount` decimal(15,2) NOT NULL,
  `required_installments` smallint(5) UNSIGNED NOT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_amount` decimal(15,2) DEFAULT NULL,
  `payment_method` varchar(16) DEFAULT NULL,
  `reference_no` varchar(64) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `paid_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `speed_drafts`
--

CREATE TABLE `speed_drafts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `speed_draft_type_id` bigint(20) UNSIGNED NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `outstanding_capital` decimal(15,2) NOT NULL,
  `interest_rate_percent` decimal(6,3) NOT NULL,
  `rate_period` varchar(16) NOT NULL DEFAULT 'monthly',
  `period_months` smallint(5) UNSIGNED NOT NULL,
  `payment_frequency` varchar(16) NOT NULL DEFAULT 'monthly',
  `start_date` date DEFAULT NULL,
  `maturity_date` date DEFAULT NULL,
  `disbursement_method` varchar(16) DEFAULT NULL,
  `disbursed_amount` decimal(15,2) DEFAULT NULL,
  `disbursed_at` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `applied_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_reason` varchar(255) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `speed_draft_collateral`
--

CREATE TABLE `speed_draft_collateral` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `speed_draft_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(32) NOT NULL,
  `description` varchar(255) NOT NULL,
  `weight` decimal(10,3) DEFAULT NULL,
  `purity` varchar(32) DEFAULT NULL,
  `appraised_value` decimal(15,2) DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `speed_draft_payments`
--

CREATE TABLE `speed_draft_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `speed_draft_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_type` varchar(16) NOT NULL,
  `payment_method` varchar(16) NOT NULL DEFAULT 'Cash',
  `received_by` bigint(20) UNSIGNED DEFAULT NULL,
  `paid_at` datetime NOT NULL,
  `receipt_no` varchar(32) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` bigint(20) UNSIGNED DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `speed_draft_payment_allocations`
--

CREATE TABLE `speed_draft_payment_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `speed_draft_payment_id` bigint(20) UNSIGNED NOT NULL,
  `speed_draft_schedule_id` bigint(20) UNSIGNED DEFAULT NULL,
  `interest_applied` decimal(15,2) NOT NULL DEFAULT 0.00,
  `capital_applied` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `speed_draft_schedules`
--

CREATE TABLE `speed_draft_schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `speed_draft_id` bigint(20) UNSIGNED NOT NULL,
  `installment_no` smallint(5) UNSIGNED NOT NULL,
  `due_date` date NOT NULL,
  `interest_due` decimal(15,2) NOT NULL,
  `capital_due` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_due` decimal(15,2) NOT NULL,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_at` datetime DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `speed_draft_types`
--

CREATE TABLE `speed_draft_types` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL,
  `code` varchar(32) NOT NULL,
  `category` varchar(20) NOT NULL,
  `reference_format` varchar(191) DEFAULT NULL,
  `interest_rate_percent` decimal(6,3) NOT NULL,
  `rate_period` varchar(16) NOT NULL DEFAULT 'monthly',
  `min_amount` decimal(15,2) DEFAULT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `min_period_months` smallint(5) UNSIGNED DEFAULT NULL,
  `max_period_months` smallint(5) UNSIGNED DEFAULT NULL,
  `grace_period_days` smallint(5) UNSIGNED NOT NULL DEFAULT 5,
  `requires_collateral` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `email` varchar(191) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(191) NOT NULL,
  `role_id` bigint(20) UNSIGNED DEFAULT NULL,
  `branch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `module` varchar(64) NOT NULL,
  `action` varchar(16) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendors`
--

CREATE TABLE `vendors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `code` varchar(32) NOT NULL,
  `contact_person` varchar(191) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_no` varchar(64) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `banks`
--
ALTER TABLE `banks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `banks_name_unique` (`name`);

--
-- Indexes for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bank_accounts_account_number_unique` (`account_number`),
  ADD KEY `bank_accounts_status_index` (`status`),
  ADD KEY `bank_accounts_branch_id_foreign` (`branch_id`);

--
-- Indexes for table `bank_account_links`
--
ALTER TABLE `bank_account_links`
  ADD PRIMARY KEY (`account_id`),
  ADD UNIQUE KEY `bank_account_links_bank_account_id_unique` (`bank_account_id`);

--
-- Indexes for table `bank_reconciliations`
--
ALTER TABLE `bank_reconciliations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bank_reconciliations_status_index` (`status`),
  ADD KEY `bank_reconciliations_bank_account_id_foreign` (`bank_account_id`),
  ADD KEY `bank_reconciliations_reconciled_by_foreign` (`reconciled_by`);

--
-- Indexes for table `bank_transactions`
--
ALTER TABLE `bank_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bank_transactions_txn_date_index` (`txn_date`),
  ADD KEY `bank_transactions_bank_account_id_foreign` (`bank_account_id`),
  ADD KEY `bank_transactions_created_by_foreign` (`created_by`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `branches_code_unique` (`code`),
  ADD KEY `branches_status_index` (`status`),
  ADD KEY `branches_parent_id_foreign` (`parent_id`);

--
-- Indexes for table `cashbook_entries`
--
ALTER TABLE `cashbook_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cashbook_entries_branch_id_foreign` (`branch_id`),
  ADD KEY `cashbook_entries_cash_account_id_foreign` (`cash_account_id`),
  ADD KEY `cashbook_entries_created_by_foreign` (`created_by`),
  ADD KEY `cashbook_entries_approved_by_foreign` (`approved_by`),
  ADD KEY `cashbook_entries_entry_date_index` (`entry_date`),
  ADD KEY `cashbook_entries_reference_type_reference_id_index` (`reference_type`,`reference_id`);

--
-- Indexes for table `cash_accounts`
--
ALTER TABLE `cash_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cash_accounts_branch_id_foreign` (`branch_id`),
  ADD KEY `cash_accounts_status_index` (`status`);

--
-- Indexes for table `cash_reconciliations`
--
ALTER TABLE `cash_reconciliations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cash_reconciliations_branch_id_as_at_date_unique` (`branch_id`,`as_at_date`),
  ADD KEY `cash_reconciliations_reconciled_by_foreign` (`reconciled_by`);

--
-- Indexes for table `cash_till_links`
--
ALTER TABLE `cash_till_links`
  ADD PRIMARY KEY (`account_id`),
  ADD UNIQUE KEY `cash_till_links_cash_account_id_unique` (`cash_account_id`);

--
-- Indexes for table `cash_transfers`
--
ALTER TABLE `cash_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cash_transfers_from_cash_account_id_foreign` (`from_cash_account_id`),
  ADD KEY `cash_transfers_to_cash_account_id_foreign` (`to_cash_account_id`),
  ADD KEY `cash_transfers_requested_by_foreign` (`requested_by`),
  ADD KEY `cash_transfers_approved_by_foreign` (`approved_by`),
  ADD KEY `cash_transfers_status_index` (`status`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chart_of_accounts_code_unique` (`code`),
  ADD KEY `chart_of_accounts_type_index` (`type`),
  ADD KEY `chart_of_accounts_parent_id_index` (`parent_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `customers_code_unique` (`code`),
  ADD UNIQUE KEY `customers_nic_no_unique` (`nic_no`),
  ADD KEY `customers_branch_id_foreign` (`branch_id`),
  ADD KEY `customers_created_by_foreign` (`created_by`),
  ADD KEY `customers_status_index` (`status`);

--
-- Indexes for table `customer_documents`
--
ALTER TABLE `customer_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_documents_customer_id_foreign` (`customer_id`),
  ADD KEY `customer_documents_uploaded_by_foreign` (`uploaded_by`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `expenses_code_unique` (`code`),
  ADD KEY `expenses_branch_id_foreign` (`branch_id`),
  ADD KEY `expenses_expense_category_id_foreign` (`expense_category_id`),
  ADD KEY `expenses_vendor_id_foreign` (`vendor_id`),
  ADD KEY `expenses_requested_by_foreign` (`requested_by`),
  ADD KEY `expenses_approved_by_foreign` (`approved_by`),
  ADD KEY `expenses_status_index` (`status`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `expense_categories_code_unique` (`code`),
  ADD KEY `expense_categories_account_id_foreign` (`account_id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `fd_deposits`
--
ALTER TABLE `fd_deposits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `fd_deposits_deposit_number_unique` (`deposit_number`),
  ADD KEY `fd_deposits_branch_id_foreign` (`branch_id`),
  ADD KEY `fd_deposits_customer_id_foreign` (`customer_id`),
  ADD KEY `fd_deposits_fixed_deposit_id_foreign` (`fixed_deposit_id`),
  ADD KEY `fd_deposits_created_by_foreign` (`created_by`),
  ADD KEY `fd_deposits_status_index` (`status`);

--
-- Indexes for table `fd_interest_payments`
--
ALTER TABLE `fd_interest_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fd_interest_payments_paid_by_foreign` (`paid_by`),
  ADD KEY `fd_interest_payments_fixed_deposit_id_status_index` (`fixed_deposit_id`,`status`),
  ADD KEY `fd_interest_payments_due_date_index` (`due_date`);

--
-- Indexes for table `fd_renewals`
--
ALTER TABLE `fd_renewals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fd_renewals_old_fixed_deposit_id_foreign` (`old_fixed_deposit_id`),
  ADD KEY `fd_renewals_new_fixed_deposit_id_foreign` (`new_fixed_deposit_id`),
  ADD KEY `fd_renewals_additional_deposit_id_foreign` (`additional_deposit_id`),
  ADD KEY `fd_renewals_created_by_foreign` (`created_by`);

--
-- Indexes for table `fd_withdrawals`
--
ALTER TABLE `fd_withdrawals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `fd_withdrawals_fixed_deposit_id_unique` (`fixed_deposit_id`),
  ADD UNIQUE KEY `fd_withdrawals_voucher_no_unique` (`voucher_no`),
  ADD KEY `fd_withdrawals_created_by_foreign` (`created_by`);

--
-- Indexes for table `fixed_deposits`
--
ALTER TABLE `fixed_deposits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `fixed_deposits_fd_number_unique` (`fd_number`),
  ADD KEY `fixed_deposits_branch_id_foreign` (`branch_id`),
  ADD KEY `fixed_deposits_customer_id_foreign` (`customer_id`),
  ADD KEY `fixed_deposits_funding_product_id_foreign` (`funding_product_id`),
  ADD KEY `fixed_deposits_renewed_from_id_foreign` (`renewed_from_id`),
  ADD KEY `fixed_deposits_renewed_to_id_foreign` (`renewed_to_id`),
  ADD KEY `fixed_deposits_created_by_foreign` (`created_by`),
  ADD KEY `fixed_deposits_status_index` (`status`),
  ADD KEY `fixed_deposits_maturity_date_index` (`maturity_date`);

--
-- Indexes for table `funding_products`
--
ALTER TABLE `funding_products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `funding_products_code_unique` (`code`);

--
-- Indexes for table `generated_numbers`
--
ALTER TABLE `generated_numbers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `generated_numbers_number_unique` (`number`),
  ADD KEY `generated_numbers_reference_type_reference_id_index` (`reference_type`,`reference_id`),
  ADD KEY `generated_numbers_branch_id_foreign` (`branch_id`),
  ADD KEY `generated_numbers_loan_type_id_foreign` (`loan_type_id`),
  ADD KEY `generated_numbers_generated_by_foreign` (`generated_by`),
  ADD KEY `generated_numbers_module_branch_id_loan_type_id_year_index` (`module`,`branch_id`,`loan_type_id`,`year`),
  ADD KEY `generated_numbers_funding_product_id_foreign` (`funding_product_id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `journal_entries_code_unique` (`code`),
  ADD KEY `journal_entries_branch_id_foreign` (`branch_id`),
  ADD KEY `journal_entries_posted_by_foreign` (`posted_by`),
  ADD KEY `journal_entries_reversal_of_id_foreign` (`reversal_of_id`),
  ADD KEY `journal_entries_reference_type_reference_id_index` (`reference_type`,`reference_id`),
  ADD KEY `journal_entries_entry_date_index` (`entry_date`);

--
-- Indexes for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `journal_lines_journal_entry_id_foreign` (`journal_entry_id`),
  ADD KEY `journal_lines_account_id_index` (`account_id`);

--
-- Indexes for table `loans`
--
ALTER TABLE `loans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `loans_code_unique` (`code`),
  ADD KEY `loans_branch_id_foreign` (`branch_id`),
  ADD KEY `loans_customer_id_foreign` (`customer_id`),
  ADD KEY `loans_loan_type_id_foreign` (`loan_type_id`),
  ADD KEY `loans_loan_officer_id_foreign` (`loan_officer_id`),
  ADD KEY `loans_recovery_officer_id_foreign` (`recovery_officer_id`),
  ADD KEY `loans_applied_by_foreign` (`applied_by`),
  ADD KEY `loans_approved_by_foreign` (`approved_by`),
  ADD KEY `loans_created_by_foreign` (`created_by`),
  ADD KEY `loans_status_index` (`status`),
  ADD KEY `loans_confirmed_by_foreign` (`confirmed_by`);

--
-- Indexes for table `loan_charges`
--
ALTER TABLE `loan_charges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_charges_branch_id_foreign` (`branch_id`),
  ADD KEY `loan_charges_created_by_foreign` (`created_by`),
  ADD KEY `loan_charges_loan_id_charge_type_index` (`loan_id`,`charge_type`);

--
-- Indexes for table `loan_charge_allocations`
--
ALTER TABLE `loan_charge_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_charge_allocations_loan_payment_id_foreign` (`loan_payment_id`),
  ADD KEY `loan_charge_allocations_loan_charge_id_foreign` (`loan_charge_id`);

--
-- Indexes for table `loan_collateral`
--
ALTER TABLE `loan_collateral`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_collateral_loan_id_foreign` (`loan_id`);

--
-- Indexes for table `loan_collateral_documents`
--
ALTER TABLE `loan_collateral_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_collateral_documents_loan_collateral_id_foreign` (`loan_collateral_id`),
  ADD KEY `loan_collateral_documents_uploaded_by_foreign` (`uploaded_by`);

--
-- Indexes for table `loan_diary_notes`
--
ALTER TABLE `loan_diary_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_diary_notes_loan_id_foreign` (`loan_id`),
  ADD KEY `loan_diary_notes_created_by_foreign` (`created_by`);

--
-- Indexes for table `loan_documents`
--
ALTER TABLE `loan_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_documents_loan_id_foreign` (`loan_id`),
  ADD KEY `loan_documents_uploaded_by_foreign` (`uploaded_by`);

--
-- Indexes for table `loan_guarantors`
--
ALTER TABLE `loan_guarantors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `loan_guarantors_loan_id_guarantor_id_unique` (`loan_id`,`guarantor_id`),
  ADD KEY `loan_guarantors_guarantor_id_foreign` (`guarantor_id`);

--
-- Indexes for table `loan_payments`
--
ALTER TABLE `loan_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `loan_payments_code_unique` (`code`),
  ADD KEY `loan_payments_loan_id_foreign` (`loan_id`),
  ADD KEY `loan_payments_branch_id_foreign` (`branch_id`),
  ADD KEY `loan_payments_received_by_foreign` (`received_by`),
  ADD KEY `loan_payments_voided_by_foreign` (`voided_by`),
  ADD KEY `loan_payments_approved_by_foreign` (`approved_by`);

--
-- Indexes for table `loan_payment_allocations`
--
ALTER TABLE `loan_payment_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_payment_allocations_loan_payment_id_foreign` (`loan_payment_id`),
  ADD KEY `loan_payment_allocations_loan_schedule_id_foreign` (`loan_schedule_id`);

--
-- Indexes for table `loan_post_dated_cheques`
--
ALTER TABLE `loan_post_dated_cheques`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_post_dated_cheques_branch_id_foreign` (`branch_id`),
  ADD KEY `loan_post_dated_cheques_created_by_foreign` (`created_by`),
  ADD KEY `loan_post_dated_cheques_loan_id_status_index` (`loan_id`,`status`),
  ADD KEY `loan_post_dated_cheques_loan_payment_id_foreign` (`loan_payment_id`);

--
-- Indexes for table `loan_schedules`
--
ALTER TABLE `loan_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `loan_schedules_loan_id_installment_no_unique` (`loan_id`,`installment_no`),
  ADD KEY `loan_schedules_due_date_index` (`due_date`),
  ADD KEY `loan_schedules_status_index` (`status`);

--
-- Indexes for table `loan_types`
--
ALTER TABLE `loan_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `loan_types_code_unique` (`code`),
  ADD KEY `loan_types_parent_id_foreign` (`parent_id`);

--
-- Indexes for table `loan_waivers`
--
ALTER TABLE `loan_waivers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_waivers_loan_id_foreign` (`loan_id`),
  ADD KEY `loan_waivers_approved_by_foreign` (`approved_by`),
  ADD KEY `loan_waivers_created_by_foreign` (`created_by`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `number_sequences`
--
ALTER TABLE `number_sequences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `number_sequences_key_unique` (`module`,`branch_id`,`loan_type_id`,`funding_product_id`,`year`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD KEY `password_resets_email_index` (`email`);

--
-- Indexes for table `payment_vouchers`
--
ALTER TABLE `payment_vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_vouchers_code_unique` (`code`),
  ADD KEY `payment_vouchers_expense_id_foreign` (`expense_id`),
  ADD KEY `payment_vouchers_vendor_id_foreign` (`vendor_id`),
  ADD KEY `payment_vouchers_paid_by_foreign` (`paid_by`),
  ADD KEY `payment_vouchers_cash_account_id_foreign` (`cash_account_id`),
  ADD KEY `payment_vouchers_bank_account_id_foreign` (`bank_account_id`),
  ADD KEY `payment_vouchers_journal_entry_id_foreign` (`journal_entry_id`),
  ADD KEY `payment_vouchers_paid_at_index` (`paid_at`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_slug_unique` (`slug`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_perm_unique` (`role_id`,`module`,`action`);

--
-- Indexes for table `sales_executive_commissions`
--
ALTER TABLE `sales_executive_commissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sales_executive_commissions_paid_by_foreign` (`paid_by`),
  ADD KEY `sales_executive_commissions_sales_executive_id_index` (`sales_executive_id`),
  ADD KEY `sales_executive_commissions_loan_type_id_index` (`loan_type_id`),
  ADD KEY `sales_executive_commissions_loan_id_index` (`loan_id`);

--
-- Indexes for table `speed_drafts`
--
ALTER TABLE `speed_drafts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `speed_drafts_code_unique` (`code`),
  ADD KEY `speed_drafts_branch_id_foreign` (`branch_id`),
  ADD KEY `speed_drafts_customer_id_foreign` (`customer_id`),
  ADD KEY `speed_drafts_speed_draft_type_id_foreign` (`speed_draft_type_id`),
  ADD KEY `speed_drafts_applied_by_foreign` (`applied_by`),
  ADD KEY `speed_drafts_approved_by_foreign` (`approved_by`),
  ADD KEY `speed_drafts_created_by_foreign` (`created_by`),
  ADD KEY `speed_drafts_status_index` (`status`);

--
-- Indexes for table `speed_draft_collateral`
--
ALTER TABLE `speed_draft_collateral`
  ADD PRIMARY KEY (`id`),
  ADD KEY `speed_draft_collateral_speed_draft_id_foreign` (`speed_draft_id`);

--
-- Indexes for table `speed_draft_payments`
--
ALTER TABLE `speed_draft_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `speed_draft_payments_code_unique` (`code`),
  ADD KEY `speed_draft_payments_speed_draft_id_foreign` (`speed_draft_id`),
  ADD KEY `speed_draft_payments_branch_id_foreign` (`branch_id`),
  ADD KEY `speed_draft_payments_received_by_foreign` (`received_by`),
  ADD KEY `speed_draft_payments_voided_by_foreign` (`voided_by`);

--
-- Indexes for table `speed_draft_payment_allocations`
--
ALTER TABLE `speed_draft_payment_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `speed_draft_payment_allocations_speed_draft_payment_id_foreign` (`speed_draft_payment_id`),
  ADD KEY `speed_draft_payment_allocations_speed_draft_schedule_id_foreign` (`speed_draft_schedule_id`);

--
-- Indexes for table `speed_draft_schedules`
--
ALTER TABLE `speed_draft_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `speed_draft_schedules_speed_draft_id_installment_no_unique` (`speed_draft_id`,`installment_no`),
  ADD KEY `speed_draft_schedules_due_date_index` (`due_date`),
  ADD KEY `speed_draft_schedules_status_index` (`status`);

--
-- Indexes for table `speed_draft_types`
--
ALTER TABLE `speed_draft_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `speed_draft_types_code_unique` (`code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD KEY `users_status_index` (`status`),
  ADD KEY `users_role_id_foreign` (`role_id`),
  ADD KEY `users_branch_id_foreign` (`branch_id`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_perm_unique` (`user_id`,`module`,`action`),
  ADD KEY `user_permissions_user_id_index` (`user_id`);

--
-- Indexes for table `vendors`
--
ALTER TABLE `vendors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vendors_code_unique` (`code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `banks`
--
ALTER TABLE `banks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_reconciliations`
--
ALTER TABLE `bank_reconciliations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bank_transactions`
--
ALTER TABLE `bank_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cashbook_entries`
--
ALTER TABLE `cashbook_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_accounts`
--
ALTER TABLE `cash_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_reconciliations`
--
ALTER TABLE `cash_reconciliations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_transfers`
--
ALTER TABLE `cash_transfers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_documents`
--
ALTER TABLE `customer_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fd_deposits`
--
ALTER TABLE `fd_deposits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fd_interest_payments`
--
ALTER TABLE `fd_interest_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fd_renewals`
--
ALTER TABLE `fd_renewals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fd_withdrawals`
--
ALTER TABLE `fd_withdrawals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fixed_deposits`
--
ALTER TABLE `fixed_deposits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `funding_products`
--
ALTER TABLE `funding_products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `generated_numbers`
--
ALTER TABLE `generated_numbers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_lines`
--
ALTER TABLE `journal_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loans`
--
ALTER TABLE `loans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_charges`
--
ALTER TABLE `loan_charges`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_charge_allocations`
--
ALTER TABLE `loan_charge_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_collateral`
--
ALTER TABLE `loan_collateral`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_collateral_documents`
--
ALTER TABLE `loan_collateral_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_diary_notes`
--
ALTER TABLE `loan_diary_notes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_documents`
--
ALTER TABLE `loan_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_guarantors`
--
ALTER TABLE `loan_guarantors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_payments`
--
ALTER TABLE `loan_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_payment_allocations`
--
ALTER TABLE `loan_payment_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_post_dated_cheques`
--
ALTER TABLE `loan_post_dated_cheques`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_schedules`
--
ALTER TABLE `loan_schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_types`
--
ALTER TABLE `loan_types`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_waivers`
--
ALTER TABLE `loan_waivers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `number_sequences`
--
ALTER TABLE `number_sequences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_vouchers`
--
ALTER TABLE `payment_vouchers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_executive_commissions`
--
ALTER TABLE `sales_executive_commissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `speed_drafts`
--
ALTER TABLE `speed_drafts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `speed_draft_collateral`
--
ALTER TABLE `speed_draft_collateral`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `speed_draft_payments`
--
ALTER TABLE `speed_draft_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `speed_draft_payment_allocations`
--
ALTER TABLE `speed_draft_payment_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `speed_draft_schedules`
--
ALTER TABLE `speed_draft_schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `speed_draft_types`
--
ALTER TABLE `speed_draft_types`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendors`
--
ALTER TABLE `vendors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  ADD CONSTRAINT `bank_accounts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `bank_account_links`
--
ALTER TABLE `bank_account_links`
  ADD CONSTRAINT `bank_account_links_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bank_account_links_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bank_reconciliations`
--
ALTER TABLE `bank_reconciliations`
  ADD CONSTRAINT `bank_reconciliations_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`),
  ADD CONSTRAINT `bank_reconciliations_reconciled_by_foreign` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `bank_transactions`
--
ALTER TABLE `bank_transactions`
  ADD CONSTRAINT `bank_transactions_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`),
  ADD CONSTRAINT `bank_transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `branches`
--
ALTER TABLE `branches`
  ADD CONSTRAINT `branches_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `branches` (`id`) ON DELETE NO ACTION;

--
-- Constraints for table `cashbook_entries`
--
ALTER TABLE `cashbook_entries`
  ADD CONSTRAINT `cashbook_entries_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `cashbook_entries_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `cashbook_entries_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`),
  ADD CONSTRAINT `cashbook_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `cash_accounts`
--
ALTER TABLE `cash_accounts`
  ADD CONSTRAINT `cash_accounts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`);

--
-- Constraints for table `cash_reconciliations`
--
ALTER TABLE `cash_reconciliations`
  ADD CONSTRAINT `cash_reconciliations_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `cash_reconciliations_reconciled_by_foreign` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `cash_till_links`
--
ALTER TABLE `cash_till_links`
  ADD CONSTRAINT `cash_till_links_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cash_till_links_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cash_transfers`
--
ALTER TABLE `cash_transfers`
  ADD CONSTRAINT `cash_transfers_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `cash_transfers_from_cash_account_id_foreign` FOREIGN KEY (`from_cash_account_id`) REFERENCES `cash_accounts` (`id`),
  ADD CONSTRAINT `cash_transfers_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `cash_transfers_to_cash_account_id_foreign` FOREIGN KEY (`to_cash_account_id`) REFERENCES `cash_accounts` (`id`);

--
-- Constraints for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD CONSTRAINT `chart_of_accounts_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`);

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `customers_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `customers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_documents`
--
ALTER TABLE `customer_documents`
  ADD CONSTRAINT `customer_documents_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `expenses_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `expenses_expense_category_id_foreign` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`),
  ADD CONSTRAINT `expenses_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `expenses_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD CONSTRAINT `expense_categories_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fd_deposits`
--
ALTER TABLE `fd_deposits`
  ADD CONSTRAINT `fd_deposits_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fd_deposits_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fd_deposits_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fd_deposits_fixed_deposit_id_foreign` FOREIGN KEY (`fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fd_interest_payments`
--
ALTER TABLE `fd_interest_payments`
  ADD CONSTRAINT `fd_interest_payments_fixed_deposit_id_foreign` FOREIGN KEY (`fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fd_interest_payments_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fd_renewals`
--
ALTER TABLE `fd_renewals`
  ADD CONSTRAINT `fd_renewals_additional_deposit_id_foreign` FOREIGN KEY (`additional_deposit_id`) REFERENCES `fd_deposits` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fd_renewals_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fd_renewals_new_fixed_deposit_id_foreign` FOREIGN KEY (`new_fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`),
  ADD CONSTRAINT `fd_renewals_old_fixed_deposit_id_foreign` FOREIGN KEY (`old_fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`);

--
-- Constraints for table `fd_withdrawals`
--
ALTER TABLE `fd_withdrawals`
  ADD CONSTRAINT `fd_withdrawals_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fd_withdrawals_fixed_deposit_id_foreign` FOREIGN KEY (`fixed_deposit_id`) REFERENCES `fixed_deposits` (`id`);

--
-- Constraints for table `fixed_deposits`
--
ALTER TABLE `fixed_deposits`
  ADD CONSTRAINT `fixed_deposits_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `fixed_deposits_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fixed_deposits_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fixed_deposits_funding_product_id_foreign` FOREIGN KEY (`funding_product_id`) REFERENCES `funding_products` (`id`),
  ADD CONSTRAINT `fixed_deposits_renewed_from_id_foreign` FOREIGN KEY (`renewed_from_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fixed_deposits_renewed_to_id_foreign` FOREIGN KEY (`renewed_to_id`) REFERENCES `fixed_deposits` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `generated_numbers`
--
ALTER TABLE `generated_numbers`
  ADD CONSTRAINT `generated_numbers_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `generated_numbers_funding_product_id_foreign` FOREIGN KEY (`funding_product_id`) REFERENCES `funding_products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `generated_numbers_generated_by_foreign` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `generated_numbers_loan_type_id_foreign` FOREIGN KEY (`loan_type_id`) REFERENCES `loan_types` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `journal_entries_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `journal_entries_reversal_of_id_foreign` FOREIGN KEY (`reversal_of_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `journal_lines`
--
ALTER TABLE `journal_lines`
  ADD CONSTRAINT `journal_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  ADD CONSTRAINT `journal_lines_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loans`
--
ALTER TABLE `loans`
  ADD CONSTRAINT `loans_applied_by_foreign` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loans_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loans_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `loans_confirmed_by_foreign` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loans_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loans_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `loans_loan_officer_id_foreign` FOREIGN KEY (`loan_officer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loans_loan_type_id_foreign` FOREIGN KEY (`loan_type_id`) REFERENCES `loan_types` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `loans_recovery_officer_id_foreign` FOREIGN KEY (`recovery_officer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `loan_charges`
--
ALTER TABLE `loan_charges`
  ADD CONSTRAINT `loan_charges_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `loan_charges_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loan_charges_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loan_charge_allocations`
--
ALTER TABLE `loan_charge_allocations`
  ADD CONSTRAINT `loan_charge_allocations_loan_charge_id_foreign` FOREIGN KEY (`loan_charge_id`) REFERENCES `loan_charges` (`id`),
  ADD CONSTRAINT `loan_charge_allocations_loan_payment_id_foreign` FOREIGN KEY (`loan_payment_id`) REFERENCES `loan_payments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loan_collateral`
--
ALTER TABLE `loan_collateral`
  ADD CONSTRAINT `loan_collateral_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loan_collateral_documents`
--
ALTER TABLE `loan_collateral_documents`
  ADD CONSTRAINT `loan_collateral_documents_loan_collateral_id_foreign` FOREIGN KEY (`loan_collateral_id`) REFERENCES `loan_collateral` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `loan_collateral_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `loan_diary_notes`
--
ALTER TABLE `loan_diary_notes`
  ADD CONSTRAINT `loan_diary_notes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loan_diary_notes_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loan_documents`
--
ALTER TABLE `loan_documents`
  ADD CONSTRAINT `loan_documents_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `loan_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `loan_guarantors`
--
ALTER TABLE `loan_guarantors`
  ADD CONSTRAINT `loan_guarantors_guarantor_id_foreign` FOREIGN KEY (`guarantor_id`) REFERENCES `customers` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `loan_guarantors_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loan_payments`
--
ALTER TABLE `loan_payments`
  ADD CONSTRAINT `loan_payments_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loan_payments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `loan_payments_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `loan_payments_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loan_payments_voided_by_foreign` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `loan_payment_allocations`
--
ALTER TABLE `loan_payment_allocations`
  ADD CONSTRAINT `loan_payment_allocations_loan_payment_id_foreign` FOREIGN KEY (`loan_payment_id`) REFERENCES `loan_payments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `loan_payment_allocations_loan_schedule_id_foreign` FOREIGN KEY (`loan_schedule_id`) REFERENCES `loan_schedules` (`id`) ON DELETE NO ACTION;

--
-- Constraints for table `loan_post_dated_cheques`
--
ALTER TABLE `loan_post_dated_cheques`
  ADD CONSTRAINT `loan_post_dated_cheques_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `loan_post_dated_cheques_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loan_post_dated_cheques_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `loan_post_dated_cheques_loan_payment_id_foreign` FOREIGN KEY (`loan_payment_id`) REFERENCES `loan_payments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `loan_schedules`
--
ALTER TABLE `loan_schedules`
  ADD CONSTRAINT `loan_schedules_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loan_types`
--
ALTER TABLE `loan_types`
  ADD CONSTRAINT `loan_types_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `loan_types` (`id`) ON DELETE NO ACTION;

--
-- Constraints for table `loan_waivers`
--
ALTER TABLE `loan_waivers`
  ADD CONSTRAINT `loan_waivers_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loan_waivers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `loan_waivers_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_vouchers`
--
ALTER TABLE `payment_vouchers`
  ADD CONSTRAINT `payment_vouchers_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`),
  ADD CONSTRAINT `payment_vouchers_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`),
  ADD CONSTRAINT `payment_vouchers_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payment_vouchers_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payment_vouchers_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payment_vouchers_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_executive_commissions`
--
ALTER TABLE `sales_executive_commissions`
  ADD CONSTRAINT `sales_executive_commissions_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`),
  ADD CONSTRAINT `sales_executive_commissions_loan_type_id_foreign` FOREIGN KEY (`loan_type_id`) REFERENCES `loan_types` (`id`),
  ADD CONSTRAINT `sales_executive_commissions_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_executive_commissions_sales_executive_id_foreign` FOREIGN KEY (`sales_executive_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `speed_drafts`
--
ALTER TABLE `speed_drafts`
  ADD CONSTRAINT `speed_drafts_applied_by_foreign` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `speed_drafts_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `speed_drafts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `speed_drafts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `speed_drafts_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `speed_drafts_speed_draft_type_id_foreign` FOREIGN KEY (`speed_draft_type_id`) REFERENCES `speed_draft_types` (`id`);

--
-- Constraints for table `speed_draft_collateral`
--
ALTER TABLE `speed_draft_collateral`
  ADD CONSTRAINT `speed_draft_collateral_speed_draft_id_foreign` FOREIGN KEY (`speed_draft_id`) REFERENCES `speed_drafts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `speed_draft_payments`
--
ALTER TABLE `speed_draft_payments`
  ADD CONSTRAINT `speed_draft_payments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `speed_draft_payments_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `speed_draft_payments_speed_draft_id_foreign` FOREIGN KEY (`speed_draft_id`) REFERENCES `speed_drafts` (`id`),
  ADD CONSTRAINT `speed_draft_payments_voided_by_foreign` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `speed_draft_payment_allocations`
--
ALTER TABLE `speed_draft_payment_allocations`
  ADD CONSTRAINT `speed_draft_payment_allocations_speed_draft_payment_id_foreign` FOREIGN KEY (`speed_draft_payment_id`) REFERENCES `speed_draft_payments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `speed_draft_payment_allocations_speed_draft_schedule_id_foreign` FOREIGN KEY (`speed_draft_schedule_id`) REFERENCES `speed_draft_schedules` (`id`);

--
-- Constraints for table `speed_draft_schedules`
--
ALTER TABLE `speed_draft_schedules`
  ADD CONSTRAINT `speed_draft_schedules_speed_draft_id_foreign` FOREIGN KEY (`speed_draft_id`) REFERENCES `speed_drafts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `user_permissions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
