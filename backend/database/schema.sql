
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `approval_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `approval_type_id` bigint unsigned NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `requested_by` bigint unsigned NOT NULL,
  `requested_at` datetime NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `required_approvals` tinyint unsigned NOT NULL DEFAULT '1',
  `status` enum('PendingApproval','Approved','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PendingApproval',
  `first_approved_by` bigint unsigned DEFAULT NULL,
  `first_approved_at` datetime DEFAULT NULL,
  `second_approved_by` bigint unsigned DEFAULT NULL,
  `second_approved_at` datetime DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_requests_requested_by_foreign` (`requested_by`),
  KEY `approval_requests_first_approved_by_foreign` (`first_approved_by`),
  KEY `approval_requests_second_approved_by_foreign` (`second_approved_by`),
  KEY `approval_requests_rejected_by_foreign` (`rejected_by`),
  KEY `approval_requests_approval_type_id_subject_id_status_index` (`approval_type_id`,`subject_id`,`status`),
  CONSTRAINT `approval_requests_approval_type_id_foreign` FOREIGN KEY (`approval_type_id`) REFERENCES `approval_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approval_requests_first_approved_by_foreign` FOREIGN KEY (`first_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approval_requests_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approval_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `approval_requests_second_approved_by_foreign` FOREIGN KEY (`second_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_type_approvers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_type_approvers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `approval_type_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `approval_type_approvers_approval_type_id_user_id_unique` (`approval_type_id`,`user_id`),
  KEY `approval_type_approvers_user_id_foreign` (`user_id`),
  CONSTRAINT `approval_type_approvers_approval_type_id_foreign` FOREIGN KEY (`approval_type_id`) REFERENCES `approval_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approval_type_approvers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `required_approvals` tinyint unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `approval_types_code_unique` (`code`),
  CONSTRAINT `chk_approval_types_required_approvals` CHECK ((`required_approvals` in (1,2)))
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `chart_of_account_id` bigint unsigned DEFAULT NULL,
  `bank_id` bigint unsigned NOT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_branch_name` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_number` varchar(34) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` enum('Savings','Current','Fixed Deposit') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Savings',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'LKR',
  `opening_balance` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `current_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_accounts_account_number` (`account_number`),
  UNIQUE KEY `uq_bank_accounts_coa` (`chart_of_account_id`),
  KEY `ix_bank_accounts_branch_id` (`branch_id`),
  KEY `ix_bank_accounts_bank_id` (`bank_id`),
  KEY `ix_bank_accounts_status` (`status`),
  CONSTRAINT `fk_bank_accounts_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`),
  CONSTRAINT `fk_bank_accounts_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bank_accounts_coa` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank_reconciliations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_reconciliations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bank_account_id` bigint unsigned NOT NULL,
  `statement_date` date NOT NULL,
  `statement_balance` decimal(15,2) NOT NULL,
  `book_balance` decimal(15,2) NOT NULL,
  `difference` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('Pending','Reconciled') NOT NULL DEFAULT 'Pending',
  `notes` varchar(255) DEFAULT NULL,
  `reconciled_by` bigint unsigned DEFAULT NULL,
  `reconciled_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_reconciliations_account_date` (`bank_account_id`,`statement_date`),
  KEY `ix_bank_reconciliations_status` (`status`),
  KEY `ix_bank_reconciliations_reconciled_by` (`reconciled_by`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bank_account_id` bigint unsigned NOT NULL,
  `contra_kind` enum('Cash','Bank','Ledger') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contra_cash_account_id` bigint unsigned DEFAULT NULL,
  `contra_bank_account_id` bigint unsigned DEFAULT NULL,
  `contra_chart_of_account_id` bigint unsigned DEFAULT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `txn_date` date NOT NULL,
  `transaction_type` enum('Deposit','Withdrawal','TransferIn','TransferOut') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `status` enum('Cleared','Pending') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Cleared',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_bank_transactions_account_date` (`bank_account_id`,`txn_date`),
  KEY `ix_bank_transactions_status` (`status`),
  KEY `ix_bank_transactions_journal_entry` (`journal_entry_id`),
  KEY `ix_bank_transactions_created_by` (`created_by`),
  KEY `bank_transactions_contra_cash_account_id_foreign` (`contra_cash_account_id`),
  KEY `bank_transactions_contra_bank_account_id_foreign` (`contra_bank_account_id`),
  KEY `bank_transactions_contra_chart_of_account_id_foreign` (`contra_chart_of_account_id`),
  CONSTRAINT `bank_transactions_contra_bank_account_id_foreign` FOREIGN KEY (`contra_bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_contra_cash_account_id_foreign` FOREIGN KEY (`contra_cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_contra_chart_of_account_id_foreign` FOREIGN KEY (`contra_chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bt_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`),
  CONSTRAINT `fk_bt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bt_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `banks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `banks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `short_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_banks_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint unsigned DEFAULT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_type` enum('Branch','Center') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Branch',
  `is_head_office` tinyint(1) NOT NULL DEFAULT '0',
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_code` (`code`),
  KEY `ix_branches_parent_id` (`parent_id`),
  KEY `ix_branches_status` (`status`),
  CONSTRAINT `fk_branches_parent` FOREIGN KEY (`parent_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cash_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `chart_of_account_id` bigint unsigned DEFAULT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `opening_balance` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `current_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_accounts_coa` (`chart_of_account_id`),
  KEY `ix_cash_accounts_branch_id` (`branch_id`),
  KEY `ix_cash_accounts_status` (`status`),
  CONSTRAINT `fk_cash_accounts_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_cash_accounts_coa` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cash_reconciliations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_reconciliations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `as_at_date` date NOT NULL,
  `system_cash_total` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `cheque_in_hand_total` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `postdated_cheque_total` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `today_cash_receipts` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `today_cheque_receipts` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `cheque_realized_total` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `receipts_to_be_cancelled` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `encashment` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `cash_float` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `petty_cash` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `other_branch_cash` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `other_branch_id` bigint unsigned DEFAULT NULL,
  `other_branch_cheque` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `other_branch_cheque_branch_id` bigint unsigned DEFAULT NULL,
  `total_cash` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `total_cheque` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `denominations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `coins_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `counted_total` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `variance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reconciled_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_reconciliations_branch_date` (`branch_id`,`as_at_date`),
  KEY `ix_cash_reconciliations_other_branch` (`other_branch_id`),
  KEY `ix_cash_reconciliations_other_branch_cheque` (`other_branch_cheque_branch_id`),
  KEY `ix_cash_reconciliations_reconciled_by` (`reconciled_by`),
  CONSTRAINT `fk_cr_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_cr_other_branch` FOREIGN KEY (`other_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cr_other_branch_cheque` FOREIGN KEY (`other_branch_cheque_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cr_reconciled_by` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cash_reconciliations_chk_1` CHECK (((`denominations` is null) or json_valid(`denominations`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cash_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `from_cash_account_id` bigint unsigned NOT NULL,
  `to_cash_account_id` bigint unsigned NOT NULL,
  `transfer_date` date NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Completed','Rejected','Voided') DEFAULT 'Pending',
  `requested_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` bigint unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cash_transfers_from` (`from_cash_account_id`),
  KEY `ix_cash_transfers_to` (`to_cash_account_id`),
  KEY `ix_cash_transfers_status` (`status`),
  KEY `ix_cash_transfers_requested_by` (`requested_by`),
  KEY `ix_cash_transfers_approved_by` (`approved_by`),
  KEY `cash_transfers_voided_by_foreign` (`voided_by`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cashbook_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cashbook_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `cash_account_id` bigint unsigned NOT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `entry_date` date NOT NULL,
  `direction` enum('In','Out') COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('Loan Collection','Expense','Transfer','Deposit','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `source_type` enum('LoanPayment','Expense','CashTransfer','FdDeposit','FdWithdrawal','FdInterestPayment','FdPartPayment','ManualAdjustment') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cashbook_entries_account_date` (`cash_account_id`,`entry_date`),
  KEY `ix_cashbook_entries_branch_id` (`branch_id`),
  KEY `ix_cashbook_entries_source` (`source_type`,`source_id`),
  KEY `ix_cashbook_entries_created_by` (`created_by`),
  KEY `ix_cashbook_entries_approved_by` (`approved_by`),
  KEY `ix_cashbook_entries_journal_entry` (`journal_entry_id`),
  KEY `cashbook_entries_branch_id_entry_date_index` (`branch_id`,`entry_date`),
  CONSTRAINT `fk_ce_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ce_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_ce_cash_account` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`),
  CONSTRAINT `fk_ce_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ce_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `charge_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `charge_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chart_of_account_id` bigint unsigned DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `charge_categories_name_unique` (`name`),
  KEY `charge_categories_chart_of_account_id_foreign` (`chart_of_account_id`),
  CONSTRAINT `charge_categories_chart_of_account_id_foreign` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chart_of_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chart_of_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` enum('Asset','Liability','Equity','Income','Expense') COLLATE utf8mb4_unicode_ci NOT NULL,
  `normal_balance` enum('Debit','Credit') COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coa_code` (`code`),
  KEY `ix_coa_type` (`account_type`),
  KEY `ix_coa_parent_id` (`parent_id`),
  CONSTRAINT `fk_coa_parent` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_profile`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_profile` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tazz Investment & Credit Pvt Ltd',
  `tagline` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'LKR — Sri Lankan Rupee',
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `credit_facilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `credit_facilities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_uuid` char(36) NOT NULL,
  `code` varchar(24) NOT NULL,
  `facility_type` enum('Loan','SpeedDraft','Pawning') NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `principal_amount` decimal(15,2) unsigned NOT NULL,
  `interest_rate_percent` decimal(6,3) unsigned NOT NULL,
  `rate_period` enum('monthly','annual','daily') DEFAULT 'monthly',
  `term_months` smallint unsigned NOT NULL,
  `start_date` date DEFAULT NULL,
  `maturity_date` date DEFAULT NULL,
  `disbursed_amount` decimal(15,2) unsigned DEFAULT NULL,
  `disbursed_at` datetime DEFAULT NULL,
  `outstanding_balance_cache` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('Pending','Approved','Rejected','Cancelled','Active','Closed','Restructured','WrittenOff') DEFAULT 'Pending',
  `applied_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_reason` varchar(255) DEFAULT NULL,
  `cancelled_reason` varchar(255) DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `written_off_reason` varchar(255) DEFAULT NULL,
  `written_off_by` bigint unsigned DEFAULT NULL,
  `npl_flag` tinyint(1) NOT NULL DEFAULT '0',
  `provision_required_pct` tinyint unsigned NOT NULL DEFAULT '0',
  `provision_required_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `provision_posted_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `provision_last_evaluated_at` timestamp NULL DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credit_facilities_public_uuid_unique` (`public_uuid`),
  UNIQUE KEY `credit_facilities_code_unique` (`code`),
  KEY `credit_facilities_branch_id_foreign` (`branch_id`),
  KEY `credit_facilities_applied_by_foreign` (`applied_by`),
  KEY `credit_facilities_approved_by_foreign` (`approved_by`),
  KEY `credit_facilities_created_by_foreign` (`created_by`),
  KEY `credit_facilities_facility_type_status_branch_id_index` (`facility_type`,`status`,`branch_id`),
  KEY `credit_facilities_customer_id_index` (`customer_id`),
  KEY `credit_facilities_cancelled_by_foreign` (`cancelled_by`),
  KEY `credit_facilities_disbursed_at_index` (`disbursed_at`),
  KEY `credit_facilities_status_closed_at_index` (`status`,`closed_at`),
  KEY `credit_facilities_written_off_by_foreign` (`written_off_by`),
  CONSTRAINT `credit_facilities_written_off_by_foreign` FOREIGN KEY (`written_off_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_business_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_business_details` (
  `customer_id` bigint unsigned NOT NULL,
  `business_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_reg_no` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_type` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`customer_id`),
  UNIQUE KEY `customer_business_details_business_reg_no_unique` (`business_reg_no`),
  CONSTRAINT `customer_business_details_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `doc_type` enum('NIC','Passport','DrivingLicense','UtilityBill','Photo','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `customer_documents_customer_id_index` (`customer_id`),
  CONSTRAINT `customer_documents_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_employment_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_employment_details` (
  `customer_id` bigint unsigned NOT NULL,
  `employer_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `job_title` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employer_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employer_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employment_start_date` date DEFAULT NULL,
  PRIMARY KEY (`customer_id`),
  CONSTRAINT `customer_employment_details_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` enum('Mr.','Mrs.','Miss','Ms.','Dr.','Rev.') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_denoted_by_initials` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `surname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `initials` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nic_no` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passport_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driving_license_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `gender` enum('Male','Female','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alt_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occupation_type` enum('Employed','SelfEmployed','BusinessOwner','Unemployed','Retired','Student','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `monthly_income` decimal(15,2) unsigned DEFAULT NULL,
  `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive','Blacklisted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `is_guarantor` tinyint(1) NOT NULL DEFAULT '0',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `nic_no_active` varchar(15) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when (`deleted_at` is null) then `nic_no` else NULL end)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_public_uuid_unique` (`public_uuid`),
  UNIQUE KEY `customers_code_unique` (`code`),
  UNIQUE KEY `customers_nic_no_active_unique` (`nic_no_active`),
  KEY `customers_created_by_foreign` (`created_by`),
  KEY `customers_branch_id_index` (`branch_id`),
  KEY `customers_status_index` (`status`),
  FULLTEXT KEY `ftx_customers_name` (`full_name`),
  CONSTRAINT `customers_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `customers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_categories_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_number_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_number_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `number_sequence_id` bigint unsigned NOT NULL,
  `generated_number` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` enum('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `generated_by` bigint unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_number_log_number` (`generated_number`),
  KEY `ix_document_number_log_source` (`source_type`,`source_id`),
  KEY `ix_document_number_log_sequence` (`number_sequence_id`),
  KEY `fk_dnl_generated_by` (`generated_by`),
  CONSTRAINT `fk_dnl_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dnl_sequence` FOREIGN KEY (`number_sequence_id`) REFERENCES `number_sequences` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=372 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chart_of_account_id` bigint unsigned DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_expense_categories_code` (`code`),
  KEY `ix_expense_categories_coa` (`chart_of_account_id`),
  CONSTRAINT `fk_ec_coa` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `expense_category_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `expense_date` date NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','Paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `requested_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_expenses_code` (`code`),
  KEY `ix_expenses_branch_status` (`branch_id`,`status`),
  KEY `ix_expenses_category` (`expense_category_id`),
  KEY `ix_expenses_vendor` (`vendor_id`),
  KEY `ix_expenses_requested_by` (`requested_by`),
  KEY `ix_expenses_approved_by` (`approved_by`),
  KEY `expenses_status_index` (`status`),
  CONSTRAINT `fk_expenses_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expenses_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_expenses_category` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `fk_expenses_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expenses_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_charge_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_charge_allocations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `facility_payment_id` bigint unsigned NOT NULL,
  `facility_charge_id` bigint unsigned NOT NULL,
  `amount_applied` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_charge_allocations_facility_payment_id_index` (`facility_payment_id`),
  KEY `facility_charge_allocations_facility_charge_id_index` (`facility_charge_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_charges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `charge_category_id` bigint unsigned DEFAULT NULL,
  `due_amount` decimal(15,2) unsigned NOT NULL,
  `paid_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `charged_at` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_charges_branch_id_foreign` (`branch_id`),
  KEY `facility_charges_created_by_foreign` (`created_by`),
  KEY `facility_charges_charge_category_id_foreign` (`charge_category_id`),
  KEY `facility_charges_credit_facility_id_charge_category_id_index` (`credit_facility_id`,`charge_category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_co_applicants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_co_applicants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `co_applicant_customer_id` bigint unsigned NOT NULL,
  `share_percent` decimal(5,2) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fca_facility_co_applicant` (`credit_facility_id`,`co_applicant_customer_id`),
  KEY `facility_co_applicants_co_applicant_customer_id_index` (`co_applicant_customer_id`),
  CONSTRAINT `facility_co_applicants_co_applicant_customer_id_foreign` FOREIGN KEY (`co_applicant_customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_collateral`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_collateral` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `collateral_type` enum('Vehicle','Jewelry','PawnedJewelry','Property','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `collateral_category` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estimated_value` decimal(15,2) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_collateral_credit_facility_id_index` (`credit_facility_id`),
  KEY `facility_collateral_collateral_type_index` (`collateral_type`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_collateral_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_collateral_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `facility_collateral_id` bigint unsigned NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_collateral_documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `facility_collateral_documents_facility_collateral_id_index` (`facility_collateral_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_collateral_jewelry_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_collateral_jewelry_details` (
  `facility_collateral_id` bigint unsigned NOT NULL,
  `weight_grams` decimal(10,3) unsigned DEFAULT NULL,
  `purity` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `appraised_value` decimal(15,2) unsigned DEFAULT NULL,
  PRIMARY KEY (`facility_collateral_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_collateral_pawn_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_collateral_pawn_details` (
  `facility_collateral_id` bigint unsigned NOT NULL,
  `item_no` smallint unsigned NOT NULL,
  `article_type_id` bigint unsigned NOT NULL,
  `declared_karat` decimal(4,2) unsigned DEFAULT NULL,
  `tested_karat` decimal(4,2) unsigned NOT NULL,
  `gross_weight_grams` decimal(10,3) unsigned NOT NULL,
  `net_weight_grams` decimal(10,3) unsigned NOT NULL,
  `rate_per_gram` decimal(10,2) unsigned NOT NULL,
  `appraised_value` decimal(15,2) unsigned NOT NULL,
  `max_loan_value` decimal(15,2) unsigned NOT NULL,
  `item_status` enum('Pledged','Released','Auctioned','Forfeited') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pledged',
  `appraised_by` bigint unsigned DEFAULT NULL,
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`facility_collateral_id`),
  KEY `facility_collateral_pawn_details_appraised_by_foreign` (`appraised_by`),
  KEY `facility_collateral_pawn_details_article_type_id_index` (`article_type_id`),
  KEY `facility_collateral_pawn_details_item_status_index` (`item_status`),
  CONSTRAINT `facility_collateral_pawn_details_appraised_by_foreign` FOREIGN KEY (`appraised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `facility_collateral_pawn_details_article_type_id_foreign` FOREIGN KEY (`article_type_id`) REFERENCES `pawning_article_types` (`id`),
  CONSTRAINT `fk_fcpd_collateral` FOREIGN KEY (`facility_collateral_id`) REFERENCES `facility_collateral` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_fcpd_weight` CHECK ((`net_weight_grams` <= `gross_weight_grams`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_collateral_vehicle_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_collateral_vehicle_details` (
  `facility_collateral_id` bigint unsigned NOT NULL,
  `vehicle_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `make_model` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chassis_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `engine_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year_of_manufacture` smallint unsigned DEFAULT NULL,
  PRIMARY KEY (`facility_collateral_id`),
  UNIQUE KEY `facility_collateral_vehicle_details_registration_no_unique` (`registration_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_diary_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_diary_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `follow_up_date` date DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_diary_notes_created_by_foreign` (`created_by`),
  KEY `facility_diary_notes_credit_facility_id_index` (`credit_facility_id`),
  KEY `facility_diary_notes_follow_up_date_index` (`follow_up_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `document_category_id` bigint unsigned DEFAULT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `facility_documents_credit_facility_id_index` (`credit_facility_id`),
  KEY `facility_documents_document_category_id_foreign` (`document_category_id`),
  CONSTRAINT `facility_documents_document_category_id_foreign` FOREIGN KEY (`document_category_id`) REFERENCES `document_categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_guarantors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_guarantors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `guarantor_customer_id` bigint unsigned NOT NULL,
  `guaranteed_amount` decimal(15,2) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fg_facility_guarantor` (`credit_facility_id`,`guarantor_customer_id`),
  KEY `facility_guarantors_guarantor_customer_id_index` (`guarantor_customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_insurance_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_insurance_details` (
  `credit_facility_id` bigint unsigned NOT NULL,
  `insurance_company` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `policy_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(15,2) unsigned DEFAULT NULL,
  `contact_person` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `coverage` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`credit_facility_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_payment_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_payment_allocations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `facility_payment_id` bigint unsigned NOT NULL,
  `facility_schedule_id` bigint unsigned DEFAULT NULL,
  `principal_applied` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `interest_applied` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `penalty_applied` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_payment_allocations_facility_payment_id_index` (`facility_payment_id`),
  KEY `facility_payment_allocations_facility_schedule_id_index` (`facility_schedule_id`)
) ENGINE=InnoDB AUTO_INCREMENT=153 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) NOT NULL,
  `credit_facility_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `payment_type` enum('Regular','Interest','CapitalReduction','Settlement','ManualAllocation') DEFAULT 'Regular',
  `payment_method` enum('Cash','Bank','Cheque') NOT NULL DEFAULT 'Cash',
  `status` enum('Active','Voided') NOT NULL DEFAULT 'Active',
  `approval_status` enum('Approved','PendingApproval','Rejected') NOT NULL DEFAULT 'Approved',
  `clearance_status` enum('Pending','Realized','Bounced') DEFAULT NULL,
  `receipt_no` varchar(32) DEFAULT NULL,
  `bank_id` bigint unsigned DEFAULT NULL,
  `cheque_date` date DEFAULT NULL,
  `realized_at` date DEFAULT NULL,
  `received_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_at` datetime NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `manual_allocation` json DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` bigint unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `facility_payments_code_unique` (`code`),
  KEY `facility_payments_bank_id_foreign` (`bank_id`),
  KEY `facility_payments_received_by_foreign` (`received_by`),
  KEY `facility_payments_approved_by_foreign` (`approved_by`),
  KEY `facility_payments_voided_by_foreign` (`voided_by`),
  KEY `facility_payments_credit_facility_id_paid_at_index` (`credit_facility_id`,`paid_at`),
  KEY `facility_payments_branch_id_paid_at_index` (`branch_id`,`paid_at`),
  KEY `facility_payments_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_post_dated_cheques`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_post_dated_cheques` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `cheque_no` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_id` bigint unsigned DEFAULT NULL,
  `cheque_date` date NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `status` enum('Pending','Handed','Realized','Bounced','Returned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `handed_at` date DEFAULT NULL,
  `realized_at` date DEFAULT NULL,
  `facility_payment_id` bigint unsigned DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `facility_post_dated_cheques_cheque_no_bank_id_unique` (`cheque_no`,`bank_id`),
  KEY `facility_post_dated_cheques_branch_id_foreign` (`branch_id`),
  KEY `facility_post_dated_cheques_bank_id_foreign` (`bank_id`),
  KEY `facility_post_dated_cheques_facility_payment_id_foreign` (`facility_payment_id`),
  KEY `facility_post_dated_cheques_created_by_foreign` (`created_by`),
  KEY `facility_post_dated_cheques_credit_facility_id_status_index` (`credit_facility_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_schedules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `installment_no` smallint unsigned NOT NULL,
  `due_date` date NOT NULL,
  `principal_due` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `interest_due` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `waived_interest` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `penalty_due` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `total_due` decimal(15,2) unsigned NOT NULL,
  `paid_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `paid_at` datetime DEFAULT NULL,
  `status` enum('Pending','Partial','Paid') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `facility_schedules_credit_facility_id_installment_no_unique` (`credit_facility_id`,`installment_no`),
  KEY `facility_schedules_status_due_date_index` (`status`,`due_date`),
  KEY `facility_schedules_due_date_index` (`due_date`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_status_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_status_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `event_type` enum('Terminated','Legal','SeizingOrderIssued','Repossessed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` date NOT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_status_events_recorded_by_foreign` (`recorded_by`),
  KEY `facility_status_events_credit_facility_id_event_type_index` (`credit_facility_id`,`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facility_waivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_waivers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `waiver_type` enum('Interest','Penalty','Charge','Principal') NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `waived_at` date NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_waivers_approved_by_foreign` (`approved_by`),
  KEY `facility_waivers_created_by_foreign` (`created_by`),
  KEY `facility_waivers_credit_facility_id_index` (`credit_facility_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_failed_jobs_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fd_deposits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fd_deposits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deposit_number` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `deposit_date` date NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `payment_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Available','Allocated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Available',
  `fixed_deposit_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_deposits_deposit_number_unique` (`deposit_number`),
  KEY `fd_deposits_created_by_foreign` (`created_by`),
  KEY `fd_deposits_branch_id_index` (`branch_id`),
  KEY `fd_deposits_customer_id_index` (`customer_id`),
  KEY `fd_deposits_fixed_deposit_id_index` (`fixed_deposit_id`),
  KEY `fd_deposits_bank_id_index` (`bank_id`),
  KEY `fd_deposits_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fd_interest_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fd_interest_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fixed_deposit_id` bigint unsigned NOT NULL,
  `installment_no` smallint unsigned NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `status` enum('Pending','Partial','Paid') NOT NULL DEFAULT 'Pending',
  `paid_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `paid_at` date DEFAULT NULL,
  `payment_method` enum('Cash','Bank','Cheque') DEFAULT NULL,
  `reference_no` varchar(64) DEFAULT NULL,
  `paid_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_interest_payments_fixed_deposit_id_installment_no_unique` (`fixed_deposit_id`,`installment_no`),
  KEY `fd_interest_payments_paid_by_foreign` (`paid_by`),
  KEY `fd_interest_payments_status_due_date_index` (`status`,`due_date`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fd_part_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fd_part_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fixed_deposit_id` bigint unsigned NOT NULL,
  `voucher_no` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `principal_amount` decimal(15,2) unsigned NOT NULL,
  `rate_percent` decimal(6,3) unsigned NOT NULL,
  `held_days` int unsigned NOT NULL,
  `interest_entitlement` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `interest_paid` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `clawback_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `net_amount_paid` decimal(15,2) unsigned NOT NULL,
  `payment_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `paid_at` date NOT NULL,
  `principal_before` decimal(15,2) unsigned NOT NULL,
  `monthly_interest_before` decimal(15,2) DEFAULT NULL,
  `total_interest_before` decimal(15,2) unsigned NOT NULL,
  `maturity_amount_before` decimal(15,2) unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_part_payments_voucher_no_unique` (`voucher_no`),
  KEY `fd_part_payments_created_by_foreign` (`created_by`),
  KEY `fd_part_payments_fixed_deposit_id_paid_at_index` (`fixed_deposit_id`,`paid_at`),
  CONSTRAINT `fd_part_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_fdpp_net` CHECK ((`net_amount_paid` = ((`principal_amount` + `interest_paid`) - `clawback_amount`)))
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fd_renewals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fd_renewals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `old_fixed_deposit_id` bigint unsigned NOT NULL,
  `new_fixed_deposit_id` bigint unsigned NOT NULL,
  `old_principal` decimal(15,2) unsigned NOT NULL,
  `additional_deposit_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `new_principal` decimal(15,2) unsigned NOT NULL,
  `additional_deposit_id` bigint unsigned DEFAULT NULL,
  `new_term_months` smallint unsigned NOT NULL,
  `renewed_at` date NOT NULL,
  `notes` text,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_renewals_new_fixed_deposit_id_unique` (`new_fixed_deposit_id`),
  KEY `fd_renewals_created_by_foreign` (`created_by`),
  KEY `fd_renewals_old_fixed_deposit_id_index` (`old_fixed_deposit_id`),
  KEY `fd_renewals_additional_deposit_id_index` (`additional_deposit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fd_withdrawals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fd_withdrawals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fixed_deposit_id` bigint unsigned NOT NULL,
  `voucher_no` varchar(24) NOT NULL,
  `principal_amount` decimal(15,2) unsigned NOT NULL,
  `interest_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `total_amount` decimal(15,2) unsigned NOT NULL,
  `payment_method` enum('Cash','Bank','Cheque') NOT NULL,
  `reference_no` varchar(64) DEFAULT NULL,
  `notes` text,
  `closed_at` date NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_withdrawals_fixed_deposit_id_unique` (`fixed_deposit_id`),
  UNIQUE KEY `fd_withdrawals_voucher_no_unique` (`voucher_no`),
  KEY `fd_withdrawals_created_by_foreign` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fixed_deposits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fixed_deposits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_uuid` char(36) NOT NULL,
  `fd_number` varchar(24) NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `funding_product_id` bigint unsigned NOT NULL,
  `principal_amount` decimal(15,2) unsigned NOT NULL,
  `interest_rate_percent` decimal(6,3) unsigned NOT NULL,
  `interest_payment_method` enum('monthly','maturity') NOT NULL,
  `term_months` smallint unsigned NOT NULL,
  `start_date` date NOT NULL,
  `maturity_date` date NOT NULL,
  `monthly_interest_amount` decimal(15,2) unsigned DEFAULT NULL,
  `total_interest_amount` decimal(15,2) unsigned NOT NULL,
  `maturity_amount` decimal(15,2) unsigned NOT NULL,
  `next_interest_date` date DEFAULT NULL,
  `status` enum('Active','Matured','Renewed','Closed') NOT NULL DEFAULT 'Active',
  `renewed_from_id` bigint unsigned DEFAULT NULL,
  `renewed_to_id` bigint unsigned DEFAULT NULL,
  `closed_at` date DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fixed_deposits_public_uuid_unique` (`public_uuid`),
  UNIQUE KEY `fixed_deposits_fd_number_unique` (`fd_number`),
  KEY `fixed_deposits_renewed_from_id_foreign` (`renewed_from_id`),
  KEY `fixed_deposits_renewed_to_id_foreign` (`renewed_to_id`),
  KEY `fixed_deposits_created_by_foreign` (`created_by`),
  KEY `fixed_deposits_branch_id_index` (`branch_id`),
  KEY `fixed_deposits_customer_id_index` (`customer_id`),
  KEY `fixed_deposits_funding_product_id_index` (`funding_product_id`),
  KEY `fixed_deposits_status_maturity_date_index` (`status`,`maturity_date`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `funding_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `funding_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `code` varchar(24) NOT NULL,
  `interest_method` enum('monthly','maturity') NOT NULL,
  `allow_method_choice` tinyint(1) NOT NULL DEFAULT '0',
  `annual_rate_percent` decimal(6,3) unsigned NOT NULL,
  `premature_withdrawal_rate_percent` decimal(6,3) DEFAULT NULL,
  `monthly_rate_percent` decimal(6,3) unsigned DEFAULT NULL,
  `rate_is_manual` tinyint(1) NOT NULL DEFAULT '0',
  `allowed_terms_months` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `allow_custom_term` tinyint(1) NOT NULL DEFAULT '0',
  `min_deposit_amount` decimal(15,2) unsigned NOT NULL,
  `max_deposit_amount` decimal(15,2) unsigned DEFAULT NULL,
  `reference_format` varchar(64) DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_funding_products_code` (`code`),
  CONSTRAINT `funding_products_chk_1` CHECK (json_valid(`allowed_terms_months`))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_date` date NOT NULL,
  `source_type` enum('Loan','Customer','LoanPayment','LoanProvision','SpeedDraft','SpeedDraftPayment','SpeedDraftRenewal','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','SalesExecutiveCommission','ManualAdjustment','OpeningBalance') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `posted_by` bigint unsigned DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `status` enum('Posted','Reversed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Posted',
  `reversal_of_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_entries_code` (`code`),
  KEY `ix_journal_entries_source` (`source_type`,`source_id`),
  KEY `ix_journal_entries_entry_date` (`entry_date`),
  KEY `ix_journal_entries_branch_id` (`branch_id`),
  KEY `ix_journal_entries_posted_by` (`posted_by`),
  KEY `ix_journal_entries_reversal_of` (`reversal_of_id`),
  KEY `journal_entries_branch_id_status_entry_date_index` (`branch_id`,`status`,`entry_date`)
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `journal_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `journal_entry_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `entry_date` date NOT NULL,
  `debit` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `credit` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_journal_lines_account_date` (`account_id`,`entry_date`),
  KEY `ix_journal_lines_journal_entry` (`journal_entry_id`)
) ENGINE=InnoDB AUTO_INCREMENT=226 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_facility_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_facility_details` (
  `credit_facility_id` bigint unsigned NOT NULL,
  `loan_type_id` bigint unsigned NOT NULL,
  `vehicle_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `down_payment` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `disbursement_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interest_method` enum('flat','reducing_balance') COLLATE utf8mb4_unicode_ci NOT NULL,
  `effective_monthly_rate` decimal(10,8) DEFAULT NULL,
  `effective_annual_rate` decimal(10,8) DEFAULT NULL,
  `installment_frequency` enum('daily','weekly','monthly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `purpose` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `application_date` date DEFAULT NULL,
  `reference_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loan_officer_id` bigint unsigned DEFAULT NULL,
  `recovery_officer_id` bigint unsigned DEFAULT NULL,
  `first_installment_date` date DEFAULT NULL,
  `freeze_date` date DEFAULT NULL,
  `confirmed_date` date DEFAULT NULL,
  `confirmed_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`credit_facility_id`),
  KEY `loan_facility_details_loan_type_id_foreign` (`loan_type_id`),
  KEY `loan_facility_details_confirmed_by_foreign` (`confirmed_by`),
  KEY `loan_facility_details_loan_officer_id_index` (`loan_officer_id`),
  KEY `loan_facility_details_recovery_officer_id_index` (`recovery_officer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_provisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_provisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `action` enum('Post','Recover') COLLATE utf8mb4_unicode_ci NOT NULL,
  `overdue_installments_at_action` decimal(8,2) NOT NULL,
  `slab_pct` tinyint unsigned NOT NULL,
  `base_amount` decimal(14,2) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `balance_after` decimal(14,2) NOT NULL,
  `posted_by` bigint unsigned DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_provisions_posted_by_foreign` (`posted_by`),
  KEY `loan_provisions_credit_facility_id_created_at_index` (`credit_facility_id`,`created_at`),
  CONSTRAINT `loan_provisions_credit_facility_id_foreign` FOREIGN KEY (`credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_provisions_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint unsigned DEFAULT NULL,
  `name` varchar(128) NOT NULL,
  `code` varchar(24) NOT NULL,
  `reference_format` varchar(64) DEFAULT NULL,
  `is_product` tinyint(1) NOT NULL DEFAULT '1',
  `category` enum('Consumer','Leasing','Mortgage','RealEstate','SME') DEFAULT NULL,
  `frequency` enum('daily','weekly','monthly') DEFAULT NULL,
  `collection_days` enum('all_days','week_days') NOT NULL DEFAULT 'all_days',
  `interest_method` enum('flat','reducing_balance') DEFAULT NULL,
  `interest_rate_percent` decimal(6,3) unsigned DEFAULT NULL,
  `rate_period` enum('monthly','annual') NOT NULL DEFAULT 'monthly',
  `min_amount` decimal(15,2) unsigned DEFAULT NULL,
  `max_amount` decimal(15,2) unsigned DEFAULT NULL,
  `min_term_months` smallint unsigned DEFAULT NULL,
  `max_term_months` smallint unsigned DEFAULT NULL,
  `grace_period_days` smallint unsigned NOT NULL DEFAULT '5',
  `default_charge_rate_percent` decimal(6,3) unsigned NOT NULL DEFAULT '0.000',
  `processing_fee_percent` decimal(6,3) unsigned NOT NULL DEFAULT '0.000',
  `commission_type` enum('fixed','percentage') NOT NULL DEFAULT 'fixed',
  `commission_value` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `min_paid_installments` smallint unsigned NOT NULL DEFAULT '0',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loan_types_code` (`code`),
  KEY `ix_loan_types_parent_id` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=180 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `number_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `number_sequences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `module` enum('loan','customer','payment','fd_deposit','fixed_deposit','fd_withdrawal','journal','expense','payment_voucher','speed_draft','speed_draft_payment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `loan_type_id` bigint unsigned DEFAULT NULL,
  `funding_product_id` bigint unsigned DEFAULT NULL,
  `year` smallint unsigned DEFAULT NULL,
  `last_sequence` int unsigned NOT NULL DEFAULT '0',
  `branch_key` bigint unsigned GENERATED ALWAYS AS (coalesce(`branch_id`,0)) STORED,
  `loan_type_key` bigint unsigned GENERATED ALWAYS AS (coalesce(`loan_type_id`,0)) STORED,
  `funding_product_key` bigint unsigned GENERATED ALWAYS AS (coalesce(`funding_product_id`,0)) STORED,
  `year_key` smallint unsigned GENERATED ALWAYS AS (coalesce(`year`,0)) STORED,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_number_sequences_key` (`module`,`branch_key`,`loan_type_key`,`funding_product_key`,`year_key`),
  KEY `ix_number_sequences_branch` (`branch_id`),
  KEY `ix_number_sequences_loan_type` (`loan_type_id`),
  KEY `ix_number_sequences_funding_product` (`funding_product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=374 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pawning_article_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pawning_article_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pawning_article_types_name_unique` (`name`),
  UNIQUE KEY `pawning_article_types_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pawning_auction_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pawning_auction_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pawning_auction_id` bigint unsigned NOT NULL,
  `facility_collateral_id` bigint unsigned NOT NULL,
  `credit_facility_id` bigint unsigned NOT NULL,
  `outstanding_capital` decimal(15,2) unsigned NOT NULL,
  `outstanding_interest` decimal(15,2) unsigned NOT NULL,
  `reserve_price` decimal(15,2) unsigned DEFAULT NULL,
  `hammer_price` decimal(15,2) unsigned DEFAULT NULL,
  `buyer_name` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `buyer_nic` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `surplus_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `settlement_status` enum('Pending','Refunded','Forfeited') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `status` enum('Listed','Sold','Unsold','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Listed',
  `sold_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pai_auction_collateral` (`pawning_auction_id`,`facility_collateral_id`),
  KEY `pawning_auction_items_facility_collateral_id_foreign` (`facility_collateral_id`),
  KEY `pawning_auction_items_credit_facility_id_index` (`credit_facility_id`),
  CONSTRAINT `pawning_auction_items_credit_facility_id_foreign` FOREIGN KEY (`credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pawning_auction_items_facility_collateral_id_foreign` FOREIGN KEY (`facility_collateral_id`) REFERENCES `facility_collateral` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pawning_auction_items_pawning_auction_id_foreign` FOREIGN KEY (`pawning_auction_id`) REFERENCES `pawning_auctions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_pai_outstanding` CHECK (((`outstanding_capital` + `outstanding_interest`) > 0))
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pawning_auctions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pawning_auctions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `auction_no` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scheduled_date` date NOT NULL,
  `notice_published_date` date DEFAULT NULL,
  `auctioneer_name` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `venue` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Scheduled','Completed','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Scheduled',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pawning_auctions_auction_no_unique` (`auction_no`),
  KEY `pawning_auctions_created_by_foreign` (`created_by`),
  KEY `pawning_auctions_branch_id_scheduled_date_index` (`branch_id`,`scheduled_date`),
  CONSTRAINT `pawning_auctions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pawning_auctions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pawning_facility_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pawning_facility_details` (
  `credit_facility_id` bigint unsigned NOT NULL,
  `pawning_type_id` bigint unsigned NOT NULL,
  `applied_rate_percent` decimal(6,3) unsigned NOT NULL,
  `is_special_rate` tinyint(1) NOT NULL DEFAULT '0',
  `total_gross_weight_grams` decimal(10,3) unsigned NOT NULL DEFAULT '0.000',
  `total_net_weight_grams` decimal(10,3) unsigned NOT NULL DEFAULT '0.000',
  `total_appraised_value` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `repay_due_date` date NOT NULL,
  `renewal_count` smallint unsigned NOT NULL DEFAULT '0',
  `previous_facility_id` bigint unsigned DEFAULT NULL,
  `purpose` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`credit_facility_id`),
  KEY `pawning_facility_details_pawning_type_id_foreign` (`pawning_type_id`),
  KEY `pawning_facility_details_previous_facility_id_index` (`previous_facility_id`),
  CONSTRAINT `pawning_facility_details_credit_facility_id_foreign` FOREIGN KEY (`credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pawning_facility_details_pawning_type_id_foreign` FOREIGN KEY (`pawning_type_id`) REFERENCES `pawning_types` (`id`),
  CONSTRAINT `pawning_facility_details_previous_facility_id_foreign` FOREIGN KEY (`previous_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_pfd_weight` CHECK ((`total_net_weight_grams` <= `total_gross_weight_grams`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pawning_gold_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pawning_gold_rates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `effective_date` date NOT NULL,
  `karat` decimal(4,2) unsigned NOT NULL,
  `rate_per_gram` decimal(10,2) unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pgr_date_karat_branch` (`effective_date`,`karat`,`branch_id`),
  KEY `pawning_gold_rates_branch_id_foreign` (`branch_id`),
  KEY `pawning_gold_rates_created_by_foreign` (`created_by`),
  CONSTRAINT `pawning_gold_rates_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pawning_gold_rates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_pgr_rate` CHECK ((`rate_per_gram` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pawning_redemption_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pawning_redemption_items` (
  `pawning_redemption_id` bigint unsigned NOT NULL,
  `facility_collateral_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`pawning_redemption_id`,`facility_collateral_id`),
  KEY `pawning_redemption_items_facility_collateral_id_index` (`facility_collateral_id`),
  CONSTRAINT `pawning_redemption_items_facility_collateral_id_foreign` FOREIGN KEY (`facility_collateral_id`) REFERENCES `facility_collateral` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pawning_redemption_items_pawning_redemption_id_foreign` FOREIGN KEY (`pawning_redemption_id`) REFERENCES `pawning_redemptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pawning_redemptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pawning_redemptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `redemption_type` enum('Full','Partial') COLLATE utf8mb4_unicode_ci NOT NULL,
  `capital_settled` decimal(15,2) unsigned NOT NULL,
  `interest_settled` decimal(15,2) unsigned NOT NULL,
  `total_settled` decimal(15,2) unsigned NOT NULL,
  `receipt_no` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facility_payment_id` bigint unsigned DEFAULT NULL,
  `settled_by` bigint unsigned DEFAULT NULL,
  `settled_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pawning_redemptions_receipt_no_unique` (`receipt_no`),
  KEY `pawning_redemptions_facility_payment_id_foreign` (`facility_payment_id`),
  KEY `pawning_redemptions_settled_by_foreign` (`settled_by`),
  KEY `pawning_redemptions_credit_facility_id_settled_at_index` (`credit_facility_id`,`settled_at`),
  CONSTRAINT `pawning_redemptions_credit_facility_id_foreign` FOREIGN KEY (`credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pawning_redemptions_facility_payment_id_foreign` FOREIGN KEY (`facility_payment_id`) REFERENCES `facility_payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pawning_redemptions_settled_by_foreign` FOREIGN KEY (`settled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_pr_total` CHECK ((`total_settled` = (`capital_settled` + `interest_settled`)))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pawning_renewals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pawning_renewals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `renewal_no` smallint unsigned NOT NULL,
  `old_due_date` date NOT NULL,
  `new_due_date` date NOT NULL,
  `capital_before` decimal(15,2) unsigned NOT NULL,
  `interest_settled` decimal(15,2) unsigned NOT NULL,
  `capital_after` decimal(15,2) unsigned NOT NULL,
  `applied_rate_percent` decimal(6,3) unsigned NOT NULL,
  `facility_payment_id` bigint unsigned DEFAULT NULL,
  `renewed_by` bigint unsigned DEFAULT NULL,
  `renewed_at` datetime NOT NULL,
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pawning_renewals_credit_facility_id_renewal_no_unique` (`credit_facility_id`,`renewal_no`),
  KEY `pawning_renewals_facility_payment_id_foreign` (`facility_payment_id`),
  KEY `pawning_renewals_renewed_by_foreign` (`renewed_by`),
  CONSTRAINT `pawning_renewals_credit_facility_id_foreign` FOREIGN KEY (`credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pawning_renewals_facility_payment_id_foreign` FOREIGN KEY (`facility_payment_id`) REFERENCES `facility_payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pawning_renewals_renewed_by_foreign` FOREIGN KEY (`renewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pawning_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pawning_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `normal_rate_percent` decimal(6,3) unsigned NOT NULL,
  `special_rate_percent` decimal(6,3) unsigned DEFAULT NULL,
  `special_rate_days` smallint unsigned DEFAULT NULL,
  `cycle_days` smallint unsigned NOT NULL,
  `ltv_percent` decimal(5,2) unsigned NOT NULL,
  `max_renewal_count` smallint unsigned DEFAULT NULL,
  `grace_period_days` smallint unsigned NOT NULL DEFAULT '5',
  `auction_eligible_days` smallint unsigned NOT NULL,
  `auction_notice_days` smallint unsigned NOT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pawning_types_code_unique` (`code`),
  CONSTRAINT `chk_pt_cycle_days` CHECK ((`cycle_days` > 0)),
  CONSTRAINT `chk_pt_ltv` CHECK (((`ltv_percent` > 0) and (`ltv_percent` <= 100))),
  CONSTRAINT `chk_pt_special_rate` CHECK (((`special_rate_percent` is null) or (`special_rate_percent` <= `normal_rate_percent`)))
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_vouchers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) NOT NULL,
  `expense_id` bigint unsigned DEFAULT NULL,
  `vendor_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `payment_method` enum('Cash','Bank','Cheque') NOT NULL DEFAULT 'Cash',
  `paid_at` datetime NOT NULL,
  `paid_by` bigint unsigned DEFAULT NULL,
  `cash_account_id` bigint unsigned DEFAULT NULL,
  `bank_account_id` bigint unsigned DEFAULT NULL,
  `cheque_no` varchar(20) DEFAULT NULL,
  `status` enum('Issued','Voided') NOT NULL DEFAULT 'Issued',
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` bigint unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_vouchers_code` (`code`),
  KEY `ix_payment_vouchers_expense` (`expense_id`),
  KEY `ix_payment_vouchers_vendor` (`vendor_id`),
  KEY `ix_payment_vouchers_paid_by` (`paid_by`),
  KEY `ix_payment_vouchers_cash_account` (`cash_account_id`),
  KEY `ix_payment_vouchers_bank_account` (`bank_account_id`),
  KEY `ix_payment_vouchers_journal_entry` (`journal_entry_id`),
  KEY `ix_payment_vouchers_paid_at` (`paid_at`),
  KEY `payment_vouchers_voided_by_foreign` (`voided_by`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `module` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` enum('View','Create','Edit','Delete','Approve','Export') COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_module_action` (`module`,`action`)
) ENGINE=InnoDB AUTO_INCREMENT=199 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pat_token` (`token`),
  KEY `ix_pat_tokenable` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `petty_cash_funds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_funds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `custodian_id` bigint unsigned DEFAULT NULL,
  `chart_of_account_id` bigint unsigned DEFAULT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `float_limit` decimal(15,2) unsigned NOT NULL,
  `current_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `petty_cash_funds_code_unique` (`code`),
  UNIQUE KEY `petty_cash_funds_chart_of_account_id_unique` (`chart_of_account_id`),
  KEY `petty_cash_funds_custodian_id_foreign` (`custodian_id`),
  KEY `petty_cash_funds_branch_id_status_index` (`branch_id`,`status`),
  CONSTRAINT `petty_cash_funds_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `petty_cash_funds_chart_of_account_id_foreign` FOREIGN KEY (`chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `petty_cash_funds_custodian_id_foreign` FOREIGN KEY (`custodian_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_pcf_float_limit` CHECK ((`float_limit` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `petty_cash_replenishments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_replenishments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `petty_cash_fund_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `funding_method` enum('Cash','Bank') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Cash',
  `cash_account_id` bigint unsigned DEFAULT NULL,
  `bank_account_id` bigint unsigned DEFAULT NULL,
  `paid_at` datetime NOT NULL,
  `paid_by` bigint unsigned DEFAULT NULL,
  `status` enum('Issued','Voided') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Issued',
  `void_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `voided_by` bigint unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
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
  CONSTRAINT `chk_pcr_amount` CHECK ((`amount` > 0)),
  CONSTRAINT `chk_pcr_funding_source` CHECK ((((`funding_method` = _utf8mb4'Cash') and (`cash_account_id` is not null)) or ((`funding_method` = _utf8mb4'Bank') and (`bank_account_id` is not null))))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `petty_cash_vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_vouchers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `petty_cash_fund_id` bigint unsigned NOT NULL,
  `expense_category_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `spent_at` date NOT NULL,
  `paid_by` bigint unsigned DEFAULT NULL,
  `status` enum('Issued','Voided') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Issued',
  `void_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `voided_by` bigint unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
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
  CONSTRAINT `chk_pcv_amount` CHECK ((`amount` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint unsigned NOT NULL,
  `permission_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_permissions` (`role_id`,`permission_id`),
  KEY `ix_role_permissions_permission` (`permission_id`)
) ENGINE=InnoDB AUTO_INCREMENT=160 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `can_view_all_branches` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_executive_commissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_executive_commissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `sales_executive_id` bigint unsigned NOT NULL,
  `loan_type_id` bigint unsigned NOT NULL,
  `cycle_no` smallint unsigned NOT NULL DEFAULT '1',
  `from_installment` smallint unsigned NOT NULL DEFAULT '0',
  `commission_type` enum('fixed','percentage') COLLATE utf8mb4_unicode_ci NOT NULL,
  `commission_value` decimal(15,2) unsigned NOT NULL,
  `commission_amount` decimal(15,2) unsigned NOT NULL,
  `required_installments` smallint unsigned NOT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_amount` decimal(15,2) unsigned DEFAULT NULL,
  `payment_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cash_account_id` bigint unsigned DEFAULT NULL,
  `bank_account_id` bigint unsigned DEFAULT NULL,
  `reference_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_executive_commissions_loan_type_id_foreign` (`loan_type_id`),
  KEY `sales_executive_commissions_paid_by_foreign` (`paid_by`),
  KEY `sales_executive_commissions_credit_facility_id_cycle_no_index` (`credit_facility_id`,`cycle_no`),
  KEY `sales_executive_commissions_sales_executive_id_index` (`sales_executive_id`),
  KEY `sales_executive_commissions_cash_account_id_foreign` (`cash_account_id`),
  KEY `sales_executive_commissions_bank_account_id_foreign` (`bank_account_id`),
  KEY `sales_executive_commissions_loan_type_id_index` (`loan_type_id`),
  CONSTRAINT `sales_executive_commissions_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_executive_commissions_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `speed_draft_facility_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `speed_draft_facility_details` (
  `credit_facility_id` bigint unsigned NOT NULL,
  `speed_draft_type_id` bigint unsigned NOT NULL,
  `vehicle_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_frequency` enum('daily','weekly','monthly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `disbursement_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`credit_facility_id`),
  KEY `speed_draft_facility_details_speed_draft_type_id_index` (`speed_draft_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `speed_draft_renewals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `speed_draft_renewals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `old_credit_facility_id` bigint unsigned NOT NULL,
  `new_credit_facility_id` bigint unsigned NOT NULL,
  `capital_before` decimal(15,2) unsigned NOT NULL,
  `interest_due` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `interest_collected` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `penalty_due` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `penalty_collected` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `capital_payment` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `capital_after` decimal(15,2) unsigned NOT NULL,
  `new_term_months` smallint unsigned NOT NULL,
  `facility_payment_id` bigint unsigned DEFAULT NULL,
  `renewed_by` bigint unsigned DEFAULT NULL,
  `renewed_at` datetime NOT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `speed_draft_renewals_new_credit_facility_id_unique` (`new_credit_facility_id`),
  KEY `speed_draft_renewals_facility_payment_id_foreign` (`facility_payment_id`),
  KEY `speed_draft_renewals_renewed_by_foreign` (`renewed_by`),
  KEY `speed_draft_renewals_old_credit_facility_id_index` (`old_credit_facility_id`),
  CONSTRAINT `speed_draft_renewals_facility_payment_id_foreign` FOREIGN KEY (`facility_payment_id`) REFERENCES `facility_payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `speed_draft_renewals_new_credit_facility_id_foreign` FOREIGN KEY (`new_credit_facility_id`) REFERENCES `credit_facilities` (`id`),
  CONSTRAINT `speed_draft_renewals_old_credit_facility_id_foreign` FOREIGN KEY (`old_credit_facility_id`) REFERENCES `credit_facilities` (`id`),
  CONSTRAINT `speed_draft_renewals_renewed_by_foreign` FOREIGN KEY (`renewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_sdr_capital_after` CHECK ((`capital_after` = (((`capital_before` - `capital_payment`) + (`interest_due` - `interest_collected`)) + (`penalty_due` - `penalty_collected`)))),
  CONSTRAINT `chk_sdr_interest_collected` CHECK ((`interest_collected` <= `interest_due`)),
  CONSTRAINT `chk_sdr_penalty_collected` CHECK ((`penalty_collected` <= `penalty_due`))
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `speed_draft_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `speed_draft_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `code` varchar(24) NOT NULL,
  `category` enum('Loan','Mortgage','GoldPawning','Lease') NOT NULL,
  `reference_format` varchar(64) DEFAULT NULL,
  `interest_rate_percent` decimal(6,3) unsigned NOT NULL,
  `rate_period` enum('monthly','annual','daily') DEFAULT 'monthly',
  `min_amount` decimal(15,2) unsigned DEFAULT NULL,
  `max_amount` decimal(15,2) unsigned DEFAULT NULL,
  `min_period_months` smallint unsigned DEFAULT NULL,
  `max_period_months` smallint unsigned DEFAULT NULL,
  `grace_period_days` smallint unsigned NOT NULL DEFAULT '5',
  `default_charge_rate_percent` decimal(6,3) DEFAULT NULL,
  `requires_collateral` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_speed_draft_types_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Domain','Hosting','Software License','Legal Document','Insurance','Other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other',
  `provider` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cost` decimal(12,2) DEFAULT NULL,
  `currency` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'LKR',
  `start_date` date DEFAULT NULL,
  `expiry_date` date NOT NULL,
  `reminder_days_before` smallint unsigned NOT NULL DEFAULT '30',
  `status` enum('Active','Inactive','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `branch_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscriptions_branch_id_foreign` (`branch_id`),
  KEY `subscriptions_created_by_foreign` (`created_by`),
  KEY `subscriptions_expiry_date_index` (`expiry_date`),
  CONSTRAINT `subscriptions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscriptions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_permission_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_permission_overrides` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `permission_id` bigint unsigned NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_permission_overrides` (`user_id`,`permission_id`),
  KEY `ix_user_permission_overrides_permission` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_public_uuid` (`public_uuid`),
  KEY `ix_users_status` (`status`),
  KEY `ix_users_role_id` (`role_id`),
  KEY `ix_users_branch_id` (`branch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_no` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendors_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

