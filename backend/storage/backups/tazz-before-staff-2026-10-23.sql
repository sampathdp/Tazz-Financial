-- MySQL dump 10.13  Distrib 8.4.7, for Win64 (x86_64)
--
-- Host: localhost    Database: tazz
-- ------------------------------------------------------
-- Server version	8.4.7

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

--
-- Table structure for table `approval_requests`
--

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
  `third_approved_by` bigint unsigned DEFAULT NULL,
  `third_approved_at` datetime DEFAULT NULL,
  `fourth_approved_by` bigint unsigned DEFAULT NULL,
  `fourth_approved_at` datetime DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_requests_approval_type_id_subject_id_status_index` (`approval_type_id`,`subject_id`,`status`),
  KEY `approval_requests_requested_by_foreign` (`requested_by`),
  KEY `approval_requests_first_approved_by_foreign` (`first_approved_by`),
  KEY `approval_requests_second_approved_by_foreign` (`second_approved_by`),
  KEY `approval_requests_rejected_by_foreign` (`rejected_by`),
  KEY `fk_ar_third_approved_by` (`third_approved_by`),
  KEY `fk_ar_fourth_approved_by` (`fourth_approved_by`),
  CONSTRAINT `approval_requests_approval_type_id_foreign` FOREIGN KEY (`approval_type_id`) REFERENCES `approval_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approval_requests_first_approved_by_foreign` FOREIGN KEY (`first_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approval_requests_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approval_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `approval_requests_second_approved_by_foreign` FOREIGN KEY (`second_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ar_fourth_approved_by` FOREIGN KEY (`fourth_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ar_third_approved_by` FOREIGN KEY (`third_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_requests`
--

LOCK TABLES `approval_requests` WRITE;
/*!40000 ALTER TABLE `approval_requests` DISABLE KEYS */;
INSERT INTO `approval_requests` VALUES (1,6,1,12,'2026-10-14 14:43:49',NULL,1,'Approved',1,'2026-10-14 14:44:04',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-14 09:13:49','2026-10-14 09:14:04'),(2,6,2,1,'2026-10-22 13:24:33',NULL,1,'Approved',12,'2026-10-22 13:31:19',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-22 07:54:33','2026-10-22 08:01:19'),(3,11,1,12,'2026-10-22 14:04:55',NULL,1,'Approved',1,'2026-10-22 14:05:19',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-22 08:34:55','2026-10-22 08:35:19'),(4,7,3,1,'2026-10-22 14:20:46',NULL,1,'PendingApproval',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-10-22 08:50:46','2026-10-22 08:50:46');
/*!40000 ALTER TABLE `approval_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `approval_tiers`
--

DROP TABLE IF EXISTS `approval_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_tiers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `approval_type_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `min_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `max_amount` decimal(15,2) DEFAULT NULL,
  `required_approvals` tinyint unsigned NOT NULL DEFAULT '1',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_atier_type_branch` (`approval_type_id`,`branch_id`),
  KEY `fk_atier_branch` (`branch_id`),
  CONSTRAINT `fk_atier_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_atier_type` FOREIGN KEY (`approval_type_id`) REFERENCES `approval_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_tiers`
--

LOCK TABLES `approval_tiers` WRITE;
/*!40000 ALTER TABLE `approval_tiers` DISABLE KEYS */;
/*!40000 ALTER TABLE `approval_tiers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `approval_type_approvers`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_type_approvers`
--

LOCK TABLES `approval_type_approvers` WRITE;
/*!40000 ALTER TABLE `approval_type_approvers` DISABLE KEYS */;
/*!40000 ALTER TABLE `approval_type_approvers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `approval_types`
--

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
  UNIQUE KEY `approval_types_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approval_types`
--

LOCK TABLES `approval_types` WRITE;
/*!40000 ALTER TABLE `approval_types` DISABLE KEYS */;
INSERT INTO `approval_types` VALUES (1,'loan_cancel','Loan Cancellation','Approving a request to cancel an approved-not-yet-disbursed or freshly-disbursed loan.',1,1,'2026-08-17 19:11:15','2026-08-17 19:11:15'),(2,'payment_void','Payment Receipt Cancellation','Approving a request to void a loan payment receipt.',1,1,'2026-08-17 19:11:15','2026-08-17 19:11:15'),(3,'odi_waiveoff','waive off','Default Charges',0,2,'2026-08-19 21:42:33','2026-08-19 21:42:33'),(4,'payment_manual_allocation','Payment Manual Allocation','Approving a cashier-requested manual interest/capital/OD/other-charges split for a loan payment.',1,1,'2026-08-19 17:56:56','2026-08-19 17:56:56'),(5,'write_off','write_off','impairment loans can be write off fire approval',0,2,'2026-08-20 22:20:54','2026-08-20 22:20:54'),(6,'loan_application','Loan Application','Approving a new Pending loan application.',1,1,'2026-08-22 09:58:28','2026-08-22 09:58:28'),(7,'speed_draft_application','Speed Draft Application','Approving a new Pending Speed Draft.',1,1,'2026-08-22 09:58:28','2026-08-22 09:58:28'),(8,'loan_write_off','Loan Write-off','Approving a request to write off an active loan as bad debt.',1,1,'2026-08-30 15:19:24','2026-08-30 15:19:24'),(9,'payment_settlement','Loan Payment Settlement','Approving a full-settlement loan payment before it is applied to the schedule.',1,1,'2026-08-30 15:19:24','2026-08-30 15:19:24'),(10,'cash_transfer','Cash Transfer','Approving a cash transfer between two cash accounts / tills.',1,1,'2026-08-30 15:19:24','2026-08-30 15:19:24'),(11,'expense_approval','Expense Approval','Approving a pending expense before it can be paid.',1,1,'2026-08-30 15:19:24','2026-08-30 15:19:24'),(12,'manual_journal_entry','Manual Journal Entry','Approving a manually-authored journal entry before it posts to the general ledger.',1,1,'2026-08-31 18:34:46','2026-08-31 18:34:46'),(13,'finance_request_payment','Finance Request Payment','Approving an outbound payment raised as a finance request.',1,1,'2026-09-01 12:56:54','2026-09-01 12:56:54'),(14,'finance_transfer','Finance Transfer','Approving a cash or bank transfer executed through the Finance module.',1,1,'2026-09-01 12:56:54','2026-09-01 12:56:54'),(15,'finance_float_issue','Cash Float Issue','Approving the issue or top-up of a cashier float.',1,1,'2026-09-01 12:56:54','2026-09-01 12:56:54'),(16,'finance_float_settlement','Cash Float Settlement','Approving a float settlement and its shortage / excess posting.',1,1,'2026-09-01 12:56:54','2026-09-01 12:56:54'),(17,'finance_reversal','Finance Transaction Reversal','Approving the reversal of a completed finance transaction.',1,1,'2026-09-01 12:56:54','2026-09-01 12:56:54'),(18,'finance_reconciliation_variance','Cash Reconciliation Variance','Signing off a branch cash reconciliation that has a counted-vs-system difference.',1,1,'2026-09-01 12:56:55','2026-09-01 12:56:55'),(19,'savings_withdrawal','Savings Withdrawal','Approving a large savings withdrawal before the cash is paid out.',1,1,'2026-09-08 18:32:39','2026-09-08 18:32:39'),(20,'savings_closure','Savings Account Closure','Approving the closure of a savings account and payout of its balance.',1,1,'2026-09-08 18:32:39','2026-09-08 18:32:39'),(21,'savings_interest_run','Savings Interest Run','Approving a batch savings interest posting before it hits the ledger.',1,1,'2026-09-08 18:32:39','2026-09-08 18:32:39'),(22,'savings_txn_void','Savings Transaction Void','Approving the reversal of a posted savings transaction.',1,1,'2026-09-08 18:32:39','2026-09-08 18:32:39');
/*!40000 ALTER TABLE `approval_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_accounts`
--

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
  `overdraft_limit` decimal(15,2) unsigned DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_accounts`
--

LOCK TABLES `bank_accounts` WRITE;
/*!40000 ALTER TABLE `bank_accounts` DISABLE KEYS */;
INSERT INTO `bank_accounts` VALUES (2,1,45,3,'Head OFZ Operation','Horana','8145004014','Savings','LKR',12345.00,12345.00,'Active',NULL,'For all loans collections','2026-08-14 21:21:22','2026-08-20 21:52:51'),(3,1,46,11,'DOC Charge','Colombo','107710006176','Current','LKR',28000.00,28000.00,'Active',NULL,'Loan Charges','2026-08-14 21:22:55','2026-08-14 21:22:55'),(4,1,54,11,'Expenses Account','horana','107710006177','Current','LKR',2000000.00,2000000.00,'Active',NULL,'Company account','2026-08-20 19:49:10','2026-08-20 21:53:44');
/*!40000 ALTER TABLE `bank_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_reconciliations`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_reconciliations`
--

LOCK TABLES `bank_reconciliations` WRITE;
/*!40000 ALTER TABLE `bank_reconciliations` DISABLE KEYS */;
/*!40000 ALTER TABLE `bank_reconciliations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_transactions`
--

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
  `finance_transaction_id` bigint unsigned DEFAULT NULL,
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
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_bank_transactions_account_date` (`bank_account_id`,`txn_date`),
  KEY `ix_bank_transactions_status` (`status`),
  KEY `ix_bank_transactions_journal_entry` (`journal_entry_id`),
  KEY `ix_bank_transactions_created_by` (`created_by`),
  KEY `bank_transactions_contra_cash_account_id_foreign` (`contra_cash_account_id`),
  KEY `bank_transactions_contra_bank_account_id_foreign` (`contra_bank_account_id`),
  KEY `bank_transactions_contra_chart_of_account_id_foreign` (`contra_chart_of_account_id`),
  KEY `bank_transactions_finance_transaction_id_foreign` (`finance_transaction_id`),
  KEY `bank_transactions_branch_id_index` (`branch_id`),
  CONSTRAINT `bank_transactions_contra_bank_account_id_foreign` FOREIGN KEY (`contra_bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_contra_cash_account_id_foreign` FOREIGN KEY (`contra_cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_contra_chart_of_account_id_foreign` FOREIGN KEY (`contra_chart_of_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_finance_transaction_id_foreign` FOREIGN KEY (`finance_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bt_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`),
  CONSTRAINT `fk_bt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bt_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_transactions`
--

LOCK TABLES `bank_transactions` WRITE;
/*!40000 ALTER TABLE `bank_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `bank_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `banks`
--

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

--
-- Dumping data for table `banks`
--

LOCK TABLES `banks` WRITE;
/*!40000 ALTER TABLE `banks` DISABLE KEYS */;
INSERT INTO `banks` VALUES (1,'Bank of Ceylon','BOC',NULL,'Active',1,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(2,'People\'s Bank','PB',NULL,'Active',2,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(3,'Commercial Bank of Ceylon PLC','COMB',NULL,'Active',3,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(4,'Hatton National Bank PLC','HNB',NULL,'Active',4,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(5,'Sampath Bank PLC','SAMP',NULL,'Active',5,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(6,'Seylan Bank PLC','SEY',NULL,'Active',6,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(7,'National Savings Bank','NSB',NULL,'Active',7,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(8,'National Development Bank PLC','NDB',NULL,'Active',8,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(9,'DFCC Bank PLC','DFCC',NULL,'Active',9,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(10,'Nations Trust Bank PLC','NTB',NULL,'Active',10,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(11,'Pan Asia Banking Corporation PLC','PABC',NULL,'Active',11,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(12,'Union Bank of Colombo PLC','UBC',NULL,'Active',12,'2026-08-03 12:11:42','2026-08-03 12:11:42'),(13,'Cargills Bank Ltd','CARG',NULL,'Active',13,'2026-08-03 12:11:42','2026-08-03 12:11:42');
/*!40000 ALTER TABLE `banks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `borrowing_renewals`
--

DROP TABLE IF EXISTS `borrowing_renewals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `borrowing_renewals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `borrowing_id` bigint unsigned NOT NULL,
  `renewed_on` date NOT NULL,
  `previous_maturity_date` date DEFAULT NULL,
  `new_maturity_date` date NOT NULL,
  `previous_term_months` smallint unsigned DEFAULT NULL,
  `new_term_months` smallint unsigned DEFAULT NULL,
  `previous_interest_rate_percent` decimal(6,3) unsigned DEFAULT NULL,
  `new_interest_rate_percent` decimal(6,3) unsigned DEFAULT NULL,
  `interest_paid_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `borrowing_renewals_borrowing_id_index` (`borrowing_id`),
  KEY `borrowing_renewals_created_by_foreign` (`created_by`),
  CONSTRAINT `borrowing_renewals_borrowing_id_foreign` FOREIGN KEY (`borrowing_id`) REFERENCES `borrowings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `borrowing_renewals_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `borrowing_renewals`
--

LOCK TABLES `borrowing_renewals` WRITE;
/*!40000 ALTER TABLE `borrowing_renewals` DISABLE KEYS */;
/*!40000 ALTER TABLE `borrowing_renewals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `borrowing_repledged_items`
--

DROP TABLE IF EXISTS `borrowing_repledged_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `borrowing_repledged_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `borrowing_id` bigint unsigned NOT NULL,
  `pawning_id` bigint unsigned NOT NULL,
  `appraised_value_snapshot` decimal(15,2) unsigned DEFAULT NULL,
  `financier_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_reference` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `borrowing_repledged_items_borrowing_id_pawning_id_unique` (`borrowing_id`,`pawning_id`),
  KEY `borrowing_repledged_items_pawning_id_index` (`pawning_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `borrowing_repledged_items`
--

LOCK TABLES `borrowing_repledged_items` WRITE;
/*!40000 ALTER TABLE `borrowing_repledged_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `borrowing_repledged_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `borrowing_transactions`
--

DROP TABLE IF EXISTS `borrowing_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `borrowing_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `borrowing_id` bigint unsigned NOT NULL,
  `voucher_no` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` enum('Drawdown','InterestPayment','PrincipalRepayment','Settlement') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `txn_date` date NOT NULL,
  `payment_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cash_account_id` bigint unsigned DEFAULT NULL,
  `bank_account_id` bigint unsigned DEFAULT NULL,
  `reference_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `outstanding_before` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `accrued_interest_cleared` decimal(15,2) NOT NULL DEFAULT '0.00',
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `borrowing_transactions_voucher_no_unique` (`voucher_no`),
  KEY `borrowing_transactions_borrowing_id_txn_date_index` (`borrowing_id`,`txn_date`),
  KEY `borrowing_transactions_cash_account_id_foreign` (`cash_account_id`),
  KEY `borrowing_transactions_bank_account_id_foreign` (`bank_account_id`),
  KEY `borrowing_transactions_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `borrowing_transactions_created_by_foreign` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `borrowing_transactions`
--

LOCK TABLES `borrowing_transactions` WRITE;
/*!40000 ALTER TABLE `borrowing_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `borrowing_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `borrowings`
--

DROP TABLE IF EXISTS `borrowings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `borrowings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `borrowing_number` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `type` enum('Party','Overdraft','RePledgedPawning','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `lender_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purpose` text COLLATE utf8mb4_unicode_ci,
  `principal_amount` decimal(15,2) unsigned NOT NULL,
  `outstanding_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `interest_rate_percent` decimal(6,3) unsigned DEFAULT NULL,
  `term_months` smallint unsigned DEFAULT NULL,
  `borrowing_date` date NOT NULL,
  `maturity_date` date DEFAULT NULL,
  `interest_accrued_through` date DEFAULT NULL,
  `accrued_interest_unpaid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `on_maturity` enum('Renew','Settle') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_cash_account_id` bigint unsigned DEFAULT NULL,
  `receipt_bank_account_id` bigint unsigned DEFAULT NULL,
  `bank_account_id` bigint unsigned DEFAULT NULL,
  `reference_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contract_no` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Active','Settled','Void') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `borrowings_borrowing_number_unique` (`borrowing_number`),
  KEY `borrowings_branch_id_index` (`branch_id`),
  KEY `borrowings_type_index` (`type`),
  KEY `borrowings_status_index` (`status`),
  KEY `borrowings_bank_account_id_index` (`bank_account_id`),
  KEY `borrowings_receipt_cash_account_id_foreign` (`receipt_cash_account_id`),
  KEY `borrowings_receipt_bank_account_id_foreign` (`receipt_bank_account_id`),
  KEY `borrowings_created_by_foreign` (`created_by`),
  CONSTRAINT `borrowings_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `borrowings_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `borrowings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `borrowings_receipt_bank_account_id_foreign` FOREIGN KEY (`receipt_bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `borrowings_receipt_cash_account_id_foreign` FOREIGN KEY (`receipt_cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `borrowings`
--

LOCK TABLES `borrowings` WRITE;
/*!40000 ALTER TABLE `borrowings` DISABLE KEYS */;
/*!40000 ALTER TABLE `borrowings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branch_funding_agreements`
--

DROP TABLE IF EXISTS `branch_funding_agreements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branch_funding_agreements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lender_branch_id` bigint unsigned NOT NULL,
  `borrower_branch_id` bigint unsigned NOT NULL,
  `lender_account_id` bigint unsigned NOT NULL,
  `borrower_account_id` bigint unsigned NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `outstanding_principal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `interest_rate_percent` decimal(8,3) NOT NULL DEFAULT '0.000',
  `accrual_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `interest_accrued_through` date DEFAULT NULL,
  `accrued_interest_unpaid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `request_date` date NOT NULL,
  `disbursed_on` date DEFAULT NULL,
  `purpose` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Requested',
  `requested_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_funding_agreements_code_unique` (`code`),
  KEY `branch_funding_agreements_lender_branch_id_index` (`lender_branch_id`),
  KEY `branch_funding_agreements_borrower_branch_id_index` (`borrower_branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branch_funding_agreements`
--

LOCK TABLES `branch_funding_agreements` WRITE;
/*!40000 ALTER TABLE `branch_funding_agreements` DISABLE KEYS */;
/*!40000 ALTER TABLE `branch_funding_agreements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branch_funding_transactions`
--

DROP TABLE IF EXISTS `branch_funding_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branch_funding_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agreement_id` bigint unsigned NOT NULL,
  `kind` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `txn_date` date NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `interest_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `branch_funding_transactions_agreement_id_index` (`agreement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branch_funding_transactions`
--

LOCK TABLES `branch_funding_transactions` WRITE;
/*!40000 ALTER TABLE `branch_funding_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `branch_funding_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branches`
--

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
  `funding_borrow_limit` decimal(15,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_code` (`code`),
  KEY `ix_branches_parent_id` (`parent_id`),
  KEY `ix_branches_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branches`
--

LOCK TABLES `branches` WRITE;
/*!40000 ALTER TABLE `branches` DISABLE KEYS */;
INSERT INTO `branches` VALUES (1,NULL,'Head Office','HO','Branch',1,'','','','Active','2026-08-03 11:44:08','2026-08-03 11:44:08',NULL),(5,NULL,'Horana','HR','Branch',0,NULL,NULL,NULL,'Active','2026-10-14 08:17:48','2026-10-14 08:17:48',NULL);
/*!40000 ALTER TABLE `branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cash_accounts`
--

DROP TABLE IF EXISTS `cash_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `account_type` enum('HeadOfficeMain','BranchMain','PettyCash','CashierFloat','CollectionCash','InTransit','Other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BranchMain',
  `purpose` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'General',
  `responsible_user_id` bigint unsigned DEFAULT NULL,
  `parent_cash_account_id` bigint unsigned DEFAULT NULL,
  `chart_of_account_id` bigint unsigned DEFAULT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `opening_balance` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `current_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `allow_negative` tinyint(1) NOT NULL DEFAULT '0',
  `last_reconciled_at` datetime DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `purpose_key` varchar(24) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when ((`purpose` <> _utf8mb4'General') and (`account_type` in (_utf8mb4'BranchMain',_utf8mb4'HeadOfficeMain'))) then `purpose` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_accounts_coa` (`chart_of_account_id`),
  UNIQUE KEY `uq_cash_accounts_branch_purpose` (`branch_id`,`purpose_key`),
  UNIQUE KEY `uq_cash_accounts_code` (`code`),
  KEY `ix_cash_accounts_branch_id` (`branch_id`),
  KEY `ix_cash_accounts_status` (`status`),
  KEY `fk_cash_accounts_responsible_user` (`responsible_user_id`),
  KEY `fk_cash_accounts_parent` (`parent_cash_account_id`),
  KEY `ix_cash_accounts_type_branch` (`account_type`,`branch_id`),
  KEY `cash_accounts_branch_id_purpose_index` (`branch_id`,`purpose`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_accounts`
--

LOCK TABLES `cash_accounts` WRITE;
/*!40000 ALTER TABLE `cash_accounts` DISABLE KEYS */;
INSERT INTO `cash_accounts` (`id`, `code`, `branch_id`, `account_type`, `purpose`, `responsible_user_id`, `parent_cash_account_id`, `chart_of_account_id`, `name`, `opening_balance`, `current_balance`, `allow_negative`, `last_reconciled_at`, `status`, `created_at`, `updated_at`) VALUES (15,'HO-CASH',1,'HeadOfficeMain','Collections',NULL,NULL,47,'Collection',0.00,0.00,0,NULL,'Active','2026-08-14 21:23:48','2026-10-08 12:01:07'),(16,'HB-CASH',1,'BranchMain','FixedDeposits',NULL,NULL,48,'FD',0.00,1000000.00,0,NULL,'Active','2026-08-14 21:24:04','2026-10-22 08:32:48'),(17,'HB-CASH-17',1,'BranchMain','DocumentCharges',NULL,NULL,49,'Doc charge',0.00,12500.00,0,NULL,'Active','2026-08-14 21:24:18','2026-10-22 08:30:09'),(18,'HO-CASH-18',1,'HeadOfficeMain','General',NULL,NULL,50,'Cash Float',0.00,0.00,0,NULL,'Active','2026-08-14 21:26:40','2026-08-29 19:12:38'),(21,'HR-CASH-21',5,'BranchMain','General',NULL,NULL,93,'Horana - Main Till',0.00,0.00,0,NULL,'Active','2026-10-14 08:17:48','2026-10-14 08:17:48'),(22,'HO-CASH-22',1,'HeadOfficeMain','General',NULL,NULL,94,'Head Office - Disbursements Till',60000.00,10000.00,0,NULL,'Active','2026-10-14 08:59:04','2026-10-14 09:26:56'),(23,'HO-CASH-23',1,'HeadOfficeMain','Savings',NULL,NULL,95,'Head Office - Savings Till',0.00,0.00,0,NULL,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(24,'HO-CASH-24',1,'HeadOfficeMain','Pawning',NULL,NULL,96,'Head Office - Pawning Till',0.00,0.00,0,NULL,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(25,'HO-CASH-25',1,'HeadOfficeMain','Expenses',NULL,NULL,97,'Head Office - Expenses Till',0.00,0.00,0,NULL,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(26,'HR-CASH-26',5,'BranchMain','Collections',NULL,NULL,98,'Horana - Collections Till',0.00,0.00,0,NULL,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(27,'HR-CASH-27',5,'BranchMain','DocumentCharges',NULL,NULL,99,'Horana - Document Charges Till',0.00,0.00,0,NULL,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(29,'HR-CASH-29',5,'BranchMain','FixedDeposits',NULL,NULL,101,'Horana - Fixed Deposits Till',0.00,0.00,0,NULL,'Active','2026-10-14 08:59:05','2026-10-14 08:59:05'),(30,'HR-CASH-30',5,'BranchMain','Savings',NULL,NULL,102,'Horana - Savings Till',0.00,0.00,0,NULL,'Active','2026-10-14 08:59:05','2026-10-14 08:59:05'),(31,'HR-CASH-31',5,'BranchMain','Pawning',NULL,NULL,103,'Horana - Pawning Till',0.00,0.00,0,NULL,'Active','2026-10-14 08:59:05','2026-10-14 08:59:05'),(32,'HR-CASH-32',5,'BranchMain','Expenses',NULL,NULL,104,'Horana - Expenses Till',0.00,0.00,0,NULL,'Active','2026-10-14 08:59:05','2026-10-14 08:59:05');
/*!40000 ALTER TABLE `cash_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cash_float_settlements`
--

DROP TABLE IF EXISTS `cash_float_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_float_settlements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cash_account_id` bigint unsigned NOT NULL,
  `home_cash_account_id` bigint unsigned NOT NULL,
  `custodian_id` bigint unsigned DEFAULT NULL,
  `issued_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `expected_amount` decimal(15,2) DEFAULT NULL,
  `counted_amount` decimal(15,2) DEFAULT NULL,
  `variance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `shortage` decimal(15,2) NOT NULL DEFAULT '0.00',
  `excess` decimal(15,2) NOT NULL DEFAULT '0.00',
  `returned_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('Open','SettlementRequested','Settled','Cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Open',
  `approval_request_id` bigint unsigned DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `opened_by` bigint unsigned DEFAULT NULL,
  `settled_by` bigint unsigned DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cfs_float_status` (`cash_account_id`,`status`),
  KEY `fk_cfs_home` (`home_cash_account_id`),
  KEY `fk_cfs_custodian` (`custodian_id`),
  KEY `fk_cfs_approval` (`approval_request_id`),
  KEY `fk_cfs_opened_by` (`opened_by`),
  KEY `fk_cfs_settled_by` (`settled_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_float_settlements`
--

LOCK TABLES `cash_float_settlements` WRITE;
/*!40000 ALTER TABLE `cash_float_settlements` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_float_settlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cash_reconciliations`
--

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
  `status` enum('Draft','Verified') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reconciled_by` bigint unsigned DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_reconciliations_branch_date` (`branch_id`,`as_at_date`),
  KEY `ix_cash_reconciliations_other_branch` (`other_branch_id`),
  KEY `ix_cash_reconciliations_other_branch_cheque` (`other_branch_cheque_branch_id`),
  KEY `ix_cash_reconciliations_reconciled_by` (`reconciled_by`),
  CONSTRAINT `cash_reconciliations_chk_1` CHECK (((`denominations` is null) or json_valid(`denominations`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_reconciliations`
--

LOCK TABLES `cash_reconciliations` WRITE;
/*!40000 ALTER TABLE `cash_reconciliations` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_reconciliations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cash_sessions`
--

DROP TABLE IF EXISTS `cash_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cash_account_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `business_date` date NOT NULL,
  `opening_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `opening_expected` decimal(15,2) NOT NULL DEFAULT '0.00',
  `expected_closing` decimal(15,2) DEFAULT NULL,
  `counted_closing` decimal(15,2) DEFAULT NULL,
  `variance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `shortage` decimal(15,2) NOT NULL DEFAULT '0.00',
  `excess` decimal(15,2) NOT NULL DEFAULT '0.00',
  `denominations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `coins_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('Open','Closed','Verified') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Open',
  `notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `opened_by` bigint unsigned DEFAULT NULL,
  `closed_by` bigint unsigned DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_sessions_account_date` (`cash_account_id`,`business_date`),
  KEY `ix_cash_sessions_branch_date` (`branch_id`,`business_date`),
  KEY `ix_cash_sessions_status` (`status`),
  KEY `fk_cs_opened_by` (`opened_by`),
  KEY `fk_cs_closed_by` (`closed_by`),
  KEY `fk_cs_verified_by` (`verified_by`),
  CONSTRAINT `cash_sessions_chk_1` CHECK (json_valid(`denominations`))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_sessions`
--

LOCK TABLES `cash_sessions` WRITE;
/*!40000 ALTER TABLE `cash_sessions` DISABLE KEYS */;
INSERT INTO `cash_sessions` VALUES (1,17,1,'2026-10-14',0.00,0.00,NULL,NULL,0.00,0.00,0.00,NULL,0.00,'Open',NULL,12,NULL,NULL,'2026-10-14 14:44:59',NULL,NULL,'2026-10-14 09:14:59','2026-10-14 09:14:59'),(2,22,1,'2026-10-14',60000.00,0.00,NULL,NULL,0.00,0.00,0.00,NULL,0.00,'Open','this first time',12,NULL,NULL,'2026-10-14 14:46:15',NULL,NULL,'2026-10-14 09:16:15','2026-10-14 09:24:30');
/*!40000 ALTER TABLE `cash_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cash_transfers`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_transfers`
--

LOCK TABLES `cash_transfers` WRITE;
/*!40000 ALTER TABLE `cash_transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cashbook_entries`
--

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
  `category` enum('Loan Collection','Expense','Transfer','Deposit','Savings','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `source_type` enum('LoanPayment','Expense','CashTransfer','FdDeposit','FdWithdrawal','FdInterestPayment','FdPartPayment','ManualAdjustment','Borrowing','BorrowingTransaction','FinanceTransaction','FdRenewal') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  KEY `cashbook_entries_branch_id_entry_date_index` (`branch_id`,`entry_date`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cashbook_entries`
--

LOCK TABLES `cashbook_entries` WRITE;
/*!40000 ALTER TABLE `cashbook_entries` DISABLE KEYS */;
INSERT INTO `cashbook_entries` VALUES (1,1,17,1,'2026-10-14','In','Loan Collection',500.00,500.00,'FinanceTransaction',1,'Loan payment HO-CA-26-10-00001',1,NULL,'2026-10-14 09:15:04','2026-10-14 09:15:04'),(2,1,22,3,'2026-10-14','Out','Other',50000.00,10000.00,'FinanceTransaction',2,'Loan disbursed (Cash) - HO-MI-WK-26-000001',1,NULL,'2026-10-14 09:26:56','2026-10-14 09:26:56'),(3,1,17,4,'2026-10-22','In','Loan Collection',12000.00,12500.00,'FinanceTransaction',3,'Loan payment HO-CA-26-10-00002',12,NULL,'2026-10-22 08:30:09','2026-10-22 08:30:09'),(4,1,16,5,'2026-10-22','In','Other',1000000.00,1000000.00,'FinanceTransaction',4,'FD deposit received - HO-DEP-26-10-000001',12,NULL,'2026-10-22 08:32:48','2026-10-22 08:32:48');
/*!40000 ALTER TABLE `cashbook_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `charge_categories`
--

DROP TABLE IF EXISTS `charge_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `charge_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chart_of_account_id` bigint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `charge_categories_name_unique` (`name`),
  KEY `charge_categories_chart_of_account_id_foreign` (`chart_of_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `charge_categories`
--

LOCK TABLES `charge_categories` WRITE;
/*!40000 ALTER TABLE `charge_categories` DISABLE KEYS */;
INSERT INTO `charge_categories` VALUES (1,'Document',56,'Active',1,'2026-08-05 17:59:54','2026-08-05 17:59:54'),(2,'Legal',NULL,'Active',2,'2026-08-05 17:59:54','2026-08-05 17:59:54'),(3,'Valuation',NULL,'Active',3,'2026-08-05 17:59:54','2026-08-05 17:59:54'),(4,'Other',NULL,'Active',4,'2026-08-05 17:59:54','2026-08-05 17:59:54'),(5,'Postal Charges',NULL,'Active',0,'2026-08-06 21:18:02','2026-08-06 21:18:02'),(6,'LOD Charges',NULL,'Active',0,'2026-08-06 21:18:25','2026-08-06 21:18:25'),(7,'Service Charges',NULL,'Active',0,'2026-08-15 12:21:35','2026-08-15 12:21:35'),(8,'Visit Charges',NULL,'Active',0,'2026-08-15 20:24:45','2026-08-15 20:24:45');
/*!40000 ALTER TABLE `charge_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chart_of_accounts`
--

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
  `is_postable` tinyint(1) NOT NULL DEFAULT '1',
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coa_code` (`code`),
  KEY `ix_coa_type` (`account_type`),
  KEY `ix_coa_parent_id` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=113 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chart_of_accounts`
--

LOCK TABLES `chart_of_accounts` WRITE;
/*!40000 ALTER TABLE `chart_of_accounts` DISABLE KEYS */;
INSERT INTO `chart_of_accounts` VALUES (7,'1010','Cash in Hand','Asset','Debit',NULL,1,0,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(8,'1020','Bank Accounts','Asset','Debit',NULL,1,0,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(9,'1030','Cheques / Undeposited Funds','Asset','Debit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(10,'1100','Loan Principal Outstanding','Asset','Debit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(11,'1110','Speed Draft Capital Outstanding','Asset','Debit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(12,'1150','Interest Receivable','Asset','Debit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(13,'1190','Provision for Loan Losses','Asset','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(14,'1300','Fixed Assets','Asset','Debit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(15,'2010','Customer Fixed Deposits Payable','Liability','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(16,'2020','Interest Payable on FDs','Liability','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(17,'2100','Unapplied Loan Receipts','Liability','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(18,'2300','Accrued Expenses','Liability','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(19,'3010','Share Capital','Equity','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(20,'3015','Opening Balance Equity','Equity','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(21,'3020','Retained Earnings','Equity','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(22,'3030','Current Year Profit / Loss','Equity','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(23,'4010','Interest Income - Loans','Income','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(24,'4015','Interest Income - Speed Drafts','Income','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(25,'4020','Loan Charges / Fees','Income','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(26,'4030','Penalty Income','Income','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(27,'4900','Other Income','Income','Credit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(28,'5010','Operating Expenses','Expense','Debit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(29,'5100','Interest Expense - FDs','Expense','Debit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(30,'5200','Staff Salaries & Commission','Expense','Debit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(31,'5300','Provision for Loan Losses','Expense','Debit',NULL,1,1,'Active','2026-08-03 12:13:15','2026-08-03 12:13:15'),(44,'1015','Petty Cash Floats','Asset','Debit',NULL,1,0,'Active','2026-08-12 08:40:44','2026-08-12 08:40:44'),(45,'1020-2','Bank - Collection','Asset','Debit',8,0,1,'Active','2026-08-14 21:21:22','2026-08-14 21:21:22'),(46,'1020-3','Bank - DOC Charge','Asset','Debit',8,0,1,'Active','2026-08-14 21:22:55','2026-08-14 21:22:55'),(47,'1010-15','Cash in Hand - Collection','Asset','Debit',7,0,1,'Active','2026-08-14 21:23:48','2026-08-14 21:23:48'),(48,'1010-16','Cash in Hand - FD','Asset','Debit',7,0,1,'Active','2026-08-14 21:24:04','2026-08-14 21:24:04'),(49,'1010-17','Cash in Hand - Doc charge','Asset','Debit',7,0,1,'Active','2026-08-14 21:24:18','2026-08-14 21:24:18'),(50,'1010-18','Cash in Hand - Cash Float','Asset','Debit',7,0,1,'Active','2026-08-14 21:26:40','2026-08-14 21:26:40'),(51,'6060','incentive FDs','Expense','Debit',30,0,1,'Active','2026-08-15 09:46:23','2026-08-15 09:46:23'),(52,'6061','Incentive Loans','Expense','Debit',30,0,1,'Active','2026-08-15 09:50:05','2026-08-15 09:50:05'),(53,'6062','Service Charge','Income','Credit',27,0,1,'Active','2026-08-15 12:20:05','2026-08-15 12:20:05'),(54,'1020-4','Bank - Pan Asia','Asset','Debit',8,0,1,'Active','2026-08-20 19:49:10','2026-08-20 19:49:10'),(55,'4040','Inter Branch / Zonal Interest Income (Exp)','Income','Credit',NULL,1,1,'Active','2026-08-21 07:48:26','2026-08-21 07:48:26'),(56,'4910','Documentation Income (Other Income)','Income','Credit',27,1,1,'Active','2026-08-21 07:48:26','2026-08-21 07:48:26'),(57,'4920','Real Estate Profit','Income','Credit',27,1,1,'Active','2026-08-21 07:48:26','2026-08-21 07:48:26'),(58,'4930','Refunds on Write-offs from General Treasury','Income','Credit',27,1,1,'Active','2026-08-21 07:48:26','2026-08-21 07:48:26'),(59,'5210','Salary / EPF / ETF','Expense','Debit',30,1,1,'Active','2026-08-21 07:48:26','2026-08-21 07:48:26'),(60,'5220','Staff Allowances','Expense','Debit',30,1,1,'Active','2026-08-21 07:48:26','2026-08-21 07:48:26'),(61,'5400','HO Expense Absorption','Expense','Debit',NULL,1,1,'Active','2026-08-21 07:48:26','2026-08-21 07:48:26'),(62,'5500','VAT & NBT on Financial Services','Expense','Debit',NULL,1,1,'Active','2026-08-21 07:48:27','2026-08-21 07:48:27'),(63,'5600','Income Tax','Expense','Debit',NULL,1,1,'Active','2026-08-21 07:48:27','2026-08-21 07:48:27'),(64,'1120','Pawning Capital Outstanding','Asset','Debit',NULL,1,1,'Active','2026-08-21 07:48:27','2026-08-21 07:48:27'),(65,'4025','Interest Income - Pawning','Income','Credit',NULL,1,1,'Active','2026-08-21 07:48:27','2026-08-21 07:48:27'),(66,'2110','Pawning Auction Surplus Payable','Liability','Credit',NULL,1,1,'Active','2026-08-21 07:48:27','2026-08-21 07:48:27'),(67,'4050','Bad Debt Recovery Income','Income','Credit',NULL,1,1,'Active','2026-08-27 06:00:51','2026-08-27 06:00:51'),(68,'2200','Borrowings - Party / Institutional','Liability','Credit',NULL,1,1,'Active','2026-08-30 18:37:51','2026-08-30 18:37:51'),(69,'2210','Bank Overdrafts','Liability','Credit',NULL,1,1,'Active','2026-08-30 18:37:51','2026-08-30 18:37:51'),(70,'2130','Re-Pledged Pawning Liability','Liability','Credit',NULL,1,1,'Active','2026-08-30 18:37:51','2026-08-30 18:37:51'),(71,'5110','Interest Expense - Borrowings','Expense','Debit',NULL,1,1,'Active','2026-08-30 18:37:51','2026-08-30 18:37:51'),(76,'5310','Cash Shortage','Expense','Debit',NULL,1,1,'Active','2026-09-01 12:56:54','2026-09-01 12:56:54'),(77,'4905','Cash Overage','Income','Credit',NULL,1,1,'Active','2026-09-01 12:56:54','2026-09-01 12:56:54'),(78,'2050','Customer Savings Deposits Payable','Liability','Credit',NULL,1,1,'Active','2026-09-10 18:22:48','2026-09-10 18:22:48'),(79,'5120','Interest Expense - Savings Deposits','Expense','Debit',NULL,1,1,'Active','2026-09-10 18:22:48','2026-09-10 18:22:48'),(80,'4045','Savings Account Fee Income','Income','Credit',NULL,1,1,'Active','2026-09-10 18:22:48','2026-09-10 18:22:48'),(93,'1010-21','Cash in Hand - Horana - Main Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:17:48','2026-10-14 08:17:48'),(94,'1010-22','Cash in Hand - Head Office - Disbursements Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(95,'1010-23','Cash in Hand - Head Office - Savings Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(96,'1010-24','Cash in Hand - Head Office - Pawning Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(97,'1010-25','Cash in Hand - Head Office - Expenses Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(98,'1010-26','Cash in Hand - Horana - Collections Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(99,'1010-27','Cash in Hand - Horana - Document Charges Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:59:04','2026-10-14 08:59:04'),(101,'1010-29','Cash in Hand - Horana - Fixed Deposits Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:59:05','2026-10-14 08:59:05'),(102,'1010-30','Cash in Hand - Horana - Savings Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:59:05','2026-10-14 08:59:05'),(103,'1010-31','Cash in Hand - Horana - Pawning Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:59:05','2026-10-14 08:59:05'),(104,'1010-32','Cash in Hand - Horana - Expenses Till','Asset','Debit',7,0,1,'Active','2026-10-14 08:59:05','2026-10-14 08:59:05'),(105,'1400','Head Office Account (Due from Head Office / Branches)','Asset','Debit',NULL,1,1,'Active','2026-10-14 09:11:07','2026-10-14 09:11:07'),(106,'2400','Branch Current Account (Due to Head Office / Branches)','Liability','Credit',NULL,1,1,'Active','2026-10-14 09:11:07','2026-10-14 09:11:07'),(107,'1410','Branch Funding Receivable (Lent via Head Office)','Asset','Debit',NULL,1,1,'Active','2026-10-15 09:52:37','2026-10-15 09:52:37'),(108,'1411','Due from Borrower Branches (Funding)','Asset','Debit',NULL,1,1,'Active','2026-10-15 09:52:37','2026-10-15 09:52:37'),(109,'2410','Branch Funding Payable (Borrowed via Head Office)','Liability','Credit',NULL,1,1,'Active','2026-10-15 09:52:37','2026-10-15 09:52:37'),(110,'2411','Due to Lender Branches (Funding)','Liability','Credit',NULL,1,1,'Active','2026-10-15 09:52:37','2026-10-15 09:52:37'),(111,'5130','Inter-Branch Interest Expense','Expense','Debit',NULL,1,1,'Active','2026-10-15 09:52:37','2026-10-15 09:52:37'),(112,'2220','Accrued Interest Payable - Borrowings','Liability','Credit',NULL,1,1,'Active','2026-10-21 12:47:26','2026-10-21 12:47:26');
/*!40000 ALTER TABLE `chart_of_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_profile`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_profile`
--

LOCK TABLES `company_profile` WRITE;
/*!40000 ALTER TABLE `company_profile` DISABLE KEYS */;
INSERT INTO `company_profile` VALUES (1,'Tazz Investment & Credit Pvt Ltd',NULL,'No:14/19A,Subasadaka Mawatha,Egodauyana,Moratuwa.','0776712027','tazzinvestment.credit@gmail.com',NULL,'LKR — Sri Lankan Rupee',NULL,'2026-08-21 12:38:37','2026-08-30 19:32:36'),(2,'Tazz Investment & Credit Pvt Ltd',NULL,NULL,NULL,NULL,NULL,'LKR — Sri Lankan Rupee',NULL,'2026-08-21 12:38:37','2026-08-21 12:38:37'),(3,'Tazz Investment & Credit Pvt Ltd',NULL,NULL,NULL,NULL,NULL,'LKR — Sri Lankan Rupee',NULL,'2026-08-21 12:38:37','2026-08-21 12:38:37');
/*!40000 ALTER TABLE `company_profile` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `credit_facilities`
--

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
  `restructured_from_id` bigint unsigned DEFAULT NULL,
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
  `customer_bank_detail_id` bigint unsigned DEFAULT NULL,
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
  KEY `credit_facilities_restructured_from_id_foreign` (`restructured_from_id`),
  KEY `credit_facilities_customer_bank_detail_id_foreign` (`customer_bank_detail_id`),
  CONSTRAINT `credit_facilities_customer_bank_detail_id_foreign` FOREIGN KEY (`customer_bank_detail_id`) REFERENCES `customer_bank_details` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `credit_facilities`
--

LOCK TABLES `credit_facilities` WRITE;
/*!40000 ALTER TABLE `credit_facilities` DISABLE KEYS */;
INSERT INTO `credit_facilities` VALUES (1,'0fbdc7cf-90cf-40ed-9345-ba95d168213a','HO-MI-WK-26-000001','Loan',1,54,50000.00,5.000,'monthly',3,NULL,NULL,50000.00,'2026-10-14 00:00:00',50000.00,'Active',NULL,12,1,'2026-10-14 14:44:04',NULL,NULL,NULL,NULL,NULL,NULL,0,0,0.00,0.00,'2026-10-14 09:15:04',NULL,12,'2026-10-14 09:13:49','2026-10-14 09:26:56',NULL,NULL),(2,'e4632def-e550-4dae-9ecf-ca72609bca0a','HO-SMED-26-000002','Loan',1,54,600000.00,8.000,'monthly',3,NULL,NULL,NULL,NULL,0.00,'Approved',NULL,1,12,'2026-10-22 13:31:19',NULL,NULL,NULL,NULL,NULL,NULL,0,0,0.00,0.00,'2026-10-22 08:30:09',NULL,1,'2026-10-22 07:54:33','2026-10-22 08:30:09',NULL,1),(3,'d37e42f4-7382-4737-8894-e0ba2b51f3a3','HO-SD-26-000001','SpeedDraft',1,53,10000000.00,5.000,'monthly',1,NULL,NULL,NULL,NULL,10000000.00,'Pending',NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,0,0.00,0.00,NULL,NULL,1,'2026-10-22 08:50:46','2026-10-22 08:50:46',NULL,NULL);
/*!40000 ALTER TABLE `credit_facilities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_bank_details`
--

DROP TABLE IF EXISTS `customer_bank_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_bank_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `bank_id` bigint unsigned NOT NULL,
  `account_holder` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_branch` text COLLATE utf8mb4_unicode_ci,
  `account_number` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_bank_details_customer_id_foreign` (`customer_id`),
  KEY `customer_bank_details_bank_id_foreign` (`bank_id`),
  CONSTRAINT `customer_bank_details_bank_id_foreign` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`),
  CONSTRAINT `customer_bank_details_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_bank_details`
--

LOCK TABLES `customer_bank_details` WRITE;
/*!40000 ALTER TABLE `customer_bank_details` DISABLE KEYS */;
INSERT INTO `customer_bank_details` VALUES (1,54,1,'eyJpdiI6InlKbjNuQTlNejFsUUE2bjRYSVV6Y0E9PSIsInZhbHVlIjoiWldKdFAvK0RGZEFWL2hUbDJDM3dnZz09IiwibWFjIjoiODE4NjkyZmFlMzAxOWZmMmU2YTU4ZGNhMDUxODRlN2VmNWRiZmQ0NDE0MTg4NDAzMTE0ZGY5ZjQwODA0NWU4YiIsInRhZyI6IiJ9','eyJpdiI6Ik5YMjFYOHc5WUMrNksyajZhb2ZvS1E9PSIsInZhbHVlIjoibTIwYXVtcEgrL1VKaHNoOTBCVlBvQT09IiwibWFjIjoiOTkzYWU2YmFlNTExZmU4NTc4ZTEwYTJlNGUzNzUxZjMwZTI1OTZiOWUzODQyMTRkMDU3ZGFkZDY4MDc3MDk4MCIsInRhZyI6IiJ9','eyJpdiI6IlpOcmM1MVZPcDkxbzI5Y0oyRWFsa2c9PSIsInZhbHVlIjoiTDZwbXpHYkg5dDhzamUzbFdyRjBmQT09IiwibWFjIjoiMTliNmIzMzUzM2M5MjM5ZmIyZjE4YjUzZDdmMmVlOTY0YWEwZWIxODA5ZjQ4OTg0NDY5OWQ0M2VjMDliYWY1YSIsInRhZyI6IiJ9',1,'2026-10-22 07:54:16','2026-10-22 07:54:16',NULL);
/*!40000 ALTER TABLE `customer_bank_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_business_details`
--

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
  UNIQUE KEY `customer_business_details_business_reg_no_unique` (`business_reg_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_business_details`
--

LOCK TABLES `customer_business_details` WRITE;
/*!40000 ALTER TABLE `customer_business_details` DISABLE KEYS */;
INSERT INTO `customer_business_details` VALUES (4,'Perera Hardware Store',NULL,NULL,NULL,NULL),(5,'Fernando Tailoring',NULL,NULL,NULL,NULL),(42,'Tazz Investment & Credit Pvt Ltd',NULL,'Finance','Rathnapura Rd Horana',NULL),(44,'Tazz iNvestment & Credit Pvt Ltd',NULL,'Finanace',NULL,NULL),(45,'Power Watch-Panadura',NULL,'Watch Selling','No:80,Senanayaka Mw Panadura.','0382245199'),(46,'Vonel Trading',NULL,'Hardwear','NO:252/1,Galle Road,Panadura.','0382232279'),(47,'Power Watch',NULL,'watch selling','No:D S Senanayaka Mawatha,Panadura.',NULL),(51,'Devmina Publication',NULL,'Publication','Panadura',NULL);
/*!40000 ALTER TABLE `customer_business_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_documents`
--

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
  KEY `customer_documents_customer_id_index` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_documents`
--

LOCK TABLES `customer_documents` WRITE;
/*!40000 ALTER TABLE `customer_documents` DISABLE KEYS */;
INSERT INTO `customer_documents` VALUES (1,45,'NIC','customers/45/documents/K8iBiLMSs8pCx8fvi84hwdt54G4wKLYr1jr2RUge.jpg',1,'2026-08-11 11:57:58','2026-08-11 11:57:58'),(2,45,'NIC','customers/45/documents/T2RGQYoxOOEgVUSup2oLCcQt0iJ2Knm664PJLKDd.jpg',1,'2026-08-11 11:58:29','2026-08-11 11:58:29'),(3,45,'Photo','customers/45/documents/ZZRKAvmsAz8qaUPBbTOdhsLFwO1JEmLNjCOD77nf.jpg',1,'2026-08-11 12:00:07','2026-08-11 12:00:07'),(4,45,'Photo','customers/45/documents/lGgPdNwYJ1zrgizZ3179pt7h0wXnxNITfIGkbZ8c.jpg',1,'2026-08-11 12:01:54','2026-08-11 12:01:54'),(5,46,'NIC','customers/46/documents/Ko6KUoGkz6xoPEn4KnZBfqXIPikFwf5PlpP8r4TT.jpg',1,'2026-08-11 12:14:12','2026-08-11 12:14:12'),(6,46,'NIC','customers/46/documents/dzmHCQfqT2P7MJXjYYkEr7YZCuN4F8GGYW1YIntI.jpg',1,'2026-08-11 12:14:26','2026-08-11 12:14:26'),(7,47,'NIC','customers/47/documents/wf4RYVGZCkSQyWgrgyBoO7jXTYPRzSyqH33jMWYw.jpg',1,'2026-08-11 12:20:56','2026-08-11 12:20:56'),(8,47,'NIC','customers/47/documents/qzC28B2FzwpcB4uHJV9dFoF5s6axyZhgOgcvo1v9.jpg',1,'2026-08-11 12:21:23','2026-08-11 12:21:23'),(9,53,'NIC','customers/53/documents/VNKJVK7TsJrZJDxtHsvFVheA7QpfVzMLDi4JkYZz.jpg',1,'2026-08-29 18:43:51','2026-08-29 18:43:51'),(10,53,'NIC','customers/53/documents/lQvn9rYCnmILiRw01NGSb3r7J2L9KPkLTYN4SKYI.pdf',1,'2026-08-30 18:28:59','2026-08-30 18:28:59'),(11,54,'Photo','customers/54/documents/USQeaib87jGUcZ1jyFdbi84mv9UD0Ilb6r6W2DRv.jpg',1,'2026-10-10 14:21:49','2026-10-10 14:21:49'),(12,54,'Photo','customers/54/documents/oaLEGBOMZMaId8S5vZrSiR5oyqSwt06fjOHRjVtI.jpg',1,'2026-10-10 14:22:29','2026-10-10 14:22:29');
/*!40000 ALTER TABLE `customer_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_employment_details`
--

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
  PRIMARY KEY (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_employment_details`
--

LOCK TABLES `customer_employment_details` WRITE;
/*!40000 ALTER TABLE `customer_employment_details` DISABLE KEYS */;
INSERT INTO `customer_employment_details` VALUES (3,'Ceylon Textiles Ltd','Supervisor',NULL,NULL,NULL),(6,'Horana Plantations','Field Officer',NULL,NULL,NULL),(8,'Ceylon Textiles Ltd','Accountant',NULL,NULL,NULL),(41,'12512','125125','125125',NULL,NULL);
/*!40000 ALTER TABLE `customer_employment_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

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
  `blacklist_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `blacklist_note` text COLLATE utf8mb4_unicode_ci,
  `blacklisted_at` timestamp NULL DEFAULT NULL,
  `blacklisted_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_public_uuid_unique` (`public_uuid`),
  UNIQUE KEY `customers_code_unique` (`code`),
  UNIQUE KEY `customers_nic_no_active_unique` (`nic_no_active`),
  KEY `customers_created_by_foreign` (`created_by`),
  KEY `customers_branch_id_index` (`branch_id`),
  KEY `customers_status_index` (`status`),
  FULLTEXT KEY `ftx_customers_name` (`full_name`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` (`id`, `public_uuid`, `branch_id`, `code`, `title`, `full_name`, `name_denoted_by_initials`, `surname`, `initials`, `nic_no`, `passport_no`, `driving_license_no`, `dob`, `gender`, `phone`, `alt_phone`, `email`, `address`, `occupation_type`, `monthly_income`, `photo_path`, `status`, `is_guarantor`, `notes`, `created_by`, `created_at`, `updated_at`, `deleted_at`, `blacklist_reason`, `blacklist_note`, `blacklisted_at`, `blacklisted_by`) VALUES (3,'fd6de46a-1438-4e56-b73d-ba6e8db48e27',1,'CUS-000001',NULL,'D.P.P.K.S. Silva',NULL,NULL,NULL,'892152977V',NULL,NULL,NULL,NULL,'0771234567',NULL,NULL,NULL,'Employed',85000.00,NULL,'Active',0,NULL,1,'2026-08-03 23:07:55','2026-08-03 23:07:55',NULL,NULL,NULL,NULL,NULL),(4,'cecc5f86-a933-4f5d-a145-8953689ef3d1',1,'CUS-000002',NULL,'W.A. Perera',NULL,NULL,NULL,'901234567V',NULL,NULL,NULL,NULL,'0712345678',NULL,NULL,NULL,'BusinessOwner',150000.00,NULL,'Active',0,NULL,1,'2026-08-03 23:07:55','2026-08-03 23:07:55',NULL,NULL,NULL,NULL,NULL),(5,'46d0b412-e897-460e-8c39-e78bd4856ce0',1,'CUS-000003',NULL,'R.M. Fernando',NULL,NULL,NULL,'855566778V',NULL,NULL,NULL,NULL,'0783456789',NULL,NULL,NULL,'SelfEmployed',60000.00,NULL,'Active',0,NULL,1,'2026-08-03 23:07:55','2026-08-03 23:07:55',NULL,NULL,NULL,NULL,NULL),(6,'cae30d68-e902-435d-93eb-782d93963dfb',1,'CUS-000004',NULL,'K.G. Jayasuriya',NULL,NULL,NULL,'927788990V',NULL,NULL,NULL,NULL,'0754567890',NULL,NULL,NULL,'Employed',55000.00,NULL,'Active',0,NULL,1,'2026-08-03 23:07:55','2026-08-03 23:07:55',NULL,NULL,NULL,NULL,NULL),(7,'bec0ea06-6b31-40c8-89f5-05b59791e770',1,'CUS-000005',NULL,'N.T. Bandara',NULL,NULL,NULL,'885544332V',NULL,NULL,NULL,NULL,'0765678901',NULL,NULL,NULL,'Retired',30000.00,NULL,'Active',0,NULL,1,'2026-08-03 23:07:55','2026-08-03 23:07:55',NULL,NULL,NULL,NULL,NULL),(8,'59c0a2c4-b337-48d3-a162-5f214d3a5b84',1,'CUS-000006',NULL,'S.A.D.C. Silva',NULL,NULL,NULL,'910011223V',NULL,NULL,NULL,NULL,'0776789012',NULL,NULL,NULL,'Employed',95000.00,NULL,'Active',1,NULL,1,'2026-08-03 23:07:55','2026-08-03 23:07:55',NULL,NULL,NULL,NULL,NULL),(41,'494aeab7-cb7d-4faf-852c-a0f276b2b88b',1,'CUS-000007','Mrs.','Mrs. sfs ydjyt','sdsdf','ydjyt','sfs','2d2','d2d','2d2d2d',NULL,'Female','0754453334',NULL,'sampathdp28@gmail.com','115/A,Gagabada Road,\nHirana,Panadura','Employed',125125.00,NULL,'Active',0,NULL,1,'2026-08-06 21:50:13','2026-08-06 21:50:18','2026-08-06 21:50:18',NULL,NULL,NULL,NULL),(42,'cbaf529d-e757-4cbc-bfcd-065919925f47',1,'CUS-000008','Mr.','Mr. B H T R Peiris','Babina Hannadige Thishan Randasiri','Peiris','B H T R','198602200776',NULL,'4443222','1986-01-22','Male','0776712027',NULL,NULL,'No:14/19 A,Subasadaka Mawatha,Egodauyana,Moratuwa.','BusinessOwner',2000000.00,NULL,'Active',0,NULL,1,'2026-08-06 21:56:43','2026-08-06 21:56:43',NULL,NULL,NULL,NULL,NULL),(43,'f2901732-4c57-4889-864e-2521da50bd13',1,'CUS-000009','Mrs.','Mrs. S K S D De Silva','Sembukutti Kankanange Sanuja Deepthi','De Silva','S K S D','198775302046',NULL,NULL,'1987-09-09','Female','0762201828',NULL,NULL,'No:(1/67,Luckey Seven Pura,Egodauyana,Moratuwa.','BusinessOwner',1000000.00,NULL,'Active',0,NULL,1,'2026-08-06 21:56:52','2026-08-06 21:56:52',NULL,NULL,NULL,NULL,NULL),(44,'7a8a2c08-b84a-44cd-92fa-6ea8b134a39e',1,'CUS-000010','Mr.','Mr. D G T L Yapa','Damayangoda Gamage Tharuka Lakshan','Yapa','D G T L','199526595333',NULL,NULL,'1995-11-02','Male',NULL,NULL,NULL,'Kudamalmbe Lulbadduwa Iththapana,mathugama','BusinessOwner',3000000.00,'customers/44/photo/OSC1Z0POPguW5ZY3MJMnz4ltJpIm1oFcuyYSxmgD.jpg','Active',0,NULL,1,'2026-08-08 07:16:30','2026-09-08 21:02:47',NULL,NULL,NULL,NULL,NULL),(45,'f06fdda6-1bc2-4e53-b8fa-ef46d98e82f2',1,'CUS-000011','Mr.','Mr. R P L P Surendra','Rajapaksha Pathiranalage Lasantha Priyalal','Surendra','R P L P','197706300410',NULL,NULL,'1977-03-03','Male','0771616084','0771616084',NULL,'No:146,Sri Maha Vihara Road,Panadura.','BusinessOwner',1000000.00,NULL,'Active',0,'He is doing Watch selling Business in Panadura town and this business place his own building,',1,'2026-08-11 11:47:40','2026-08-11 12:03:14',NULL,NULL,NULL,NULL,NULL),(46,'f58f58fe-9a28-439a-bb7c-b831660d1113',1,'CUS-000012','Mr.','Mr. H V D Soyza','Hannadige Venura Dinesh','Soyza','H V D','197827700039',NULL,NULL,'1978-10-03','Male','0777768534',NULL,NULL,'No:252,Gall Road,Panadura.','BusinessOwner',1200000.00,NULL,'Active',1,'He have two Hardwear Business ( Near the Palama Junction )',1,'2026-08-11 12:13:52','2026-08-11 12:13:52',NULL,NULL,NULL,NULL,NULL),(47,'fc25ef96-97e4-4677-b2e0-03f6149bbd6f',1,'CUS-000013','Mrs.','Mrs. K N R Thabrew','Kanahalage NiwanthiRukmali','Thabrew','K N R','197985501833',NULL,NULL,'0079-12-20','Female',NULL,NULL,NULL,'146,SRI MAHA VIHARA ROAD PANADURA.','BusinessOwner',100000.00,NULL,'Active',1,'Borrower Wife .',1,'2026-08-11 12:20:29','2026-08-11 12:20:29',NULL,NULL,NULL,NULL,NULL),(48,'843329f6-f404-4b11-9ec9-d680293c0d7d',1,'CUS-000014','Mr.','Mr. M G R Indika','M Rohan Indika','Indika','M G R','831891459V',NULL,NULL,NULL,'Male',NULL,NULL,NULL,'45/1,Niyandagala,Pannipitiya',NULL,NULL,NULL,'Active',0,NULL,1,'2026-08-12 23:56:39','2026-08-12 23:56:39',NULL,NULL,NULL,NULL,NULL),(49,'35f09254-3459-4edf-b380-baef14e0a002',1,'CUS-000015','Mr.','Mr. G S Welikada','Gayath Saranga Walikada','Welikada','G S','98000803079',NULL,NULL,'1980-01-08','Male','0772799298',NULL,NULL,'346 C,Ovitiyagala,Horana','BusinessOwner',NULL,NULL,'Active',0,'Shoo shelling',1,'2026-08-17 14:31:47','2026-08-17 14:31:47',NULL,NULL,NULL,NULL,NULL),(50,'6c6ce311-8735-4a99-afd1-3eebd48901a6',1,'CUS-000016','Mr.','Mr. Tharusha Nethmina Walikada','Tharusha Nethmina Welikada','Walikada','Tharusha Nethmina',NULL,NULL,NULL,NULL,NULL,'0740407191',NULL,NULL,NULL,NULL,NULL,NULL,'Active',0,NULL,1,'2026-08-17 14:35:11','2026-08-17 14:35:11',NULL,NULL,NULL,NULL,NULL),(51,'8852a66f-6e62-455f-8a63-ab92f379773e',1,'CUS-000017','Mr.','Mr. W A C I Perera','Wickramasinghe Arachchige Chanuka Inesh','Perera','W A C I','920940870V',NULL,NULL,'1992-04-03','Male','0702091351',NULL,NULL,'No:376/8,Diganathuduwa Road,Pamunugama,Alubomulla,Panadura.','BusinessOwner',2000000.00,NULL,'Active',0,'Business',1,'2026-08-17 14:45:18','2026-08-17 14:45:18',NULL,NULL,NULL,NULL,NULL),(52,'b69a92ea-1184-4027-9343-1786a60e8477',1,'CUS-000018','Mrs.','Mrs. K Kanchana','Kadireshan','Kanchana','K',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Active',0,NULL,1,'2026-08-17 20:41:04','2026-08-17 20:41:04',NULL,NULL,NULL,NULL,NULL),(53,'cc1f8f25-e734-49e7-a58a-2c4437f4a7a4',1,'CUS-000019','Mr.','Mr. N K S Jayarathne','Nawarathnalage Kalana Sandaruwan','Jayarathne','N K S','862441397V',NULL,NULL,'1986-08-31','Male',NULL,NULL,NULL,'No:180/1C,@0 Kanuwa,Narthanagala,Horana.',NULL,80000.00,'customers/53/photo/3arhvFk38B7TKyh2KOBWwcTgIsfwyJEgidQF8nhj.jpg','Active',0,'Leasing',1,'2026-08-19 20:29:23','2026-08-30 18:08:49',NULL,NULL,NULL,NULL,NULL),(54,'27bf3c84-f03b-4d13-8782-8c3476a53696',1,'CUS-000020','Mr.','Mr. A A Suraj Asanka','A Suraj','Asanka','A A Suraj','901402370V',NULL,NULL,'1990-05-19','Male',NULL,NULL,NULL,'No:53,widharshana Mawatha,Galawila watta Homagama','BusinessOwner',NULL,'customers/54/photo/VaOgmygMPn5rCw3yrvJewusVml8oWhtvKDV4QT7o.jpg','Active',0,NULL,1,'2026-10-10 14:09:39','2026-10-10 14:10:41',NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_categories`
--

DROP TABLE IF EXISTS `document_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_categories_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_categories`
--

LOCK TABLES `document_categories` WRITE;
/*!40000 ALTER TABLE `document_categories` DISABLE KEYS */;
INSERT INTO `document_categories` VALUES (1,'Contract Image','Active',1,'2026-08-05 17:59:53','2026-08-05 17:59:53'),(2,'Agreement','Active',2,'2026-08-05 17:59:53','2026-08-05 17:59:53'),(3,'ID Copy','Active',3,'2026-08-05 17:59:53','2026-08-05 17:59:53'),(4,'Other','Active',4,'2026-08-05 17:59:53','2026-08-05 17:59:53'),(5,'CR Book','Active',0,'2026-08-06 10:09:00','2026-08-06 10:09:00'),(6,'BR','Active',0,'2026-08-06 21:19:01','2026-08-06 21:19:01');
/*!40000 ALTER TABLE `document_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_number_log`
--

DROP TABLE IF EXISTS `document_number_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_number_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `number_sequence_id` bigint unsigned NOT NULL,
  `generated_number` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` enum('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher','Borrowing','BorrowingTransaction','ManualJournalEntry','FinanceTransaction','FinanceRequest','SavingsAccount','SavingsTransaction','SavingsCollectionSheet','SavingsInterestRun') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `generated_by` bigint unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_number_log_number` (`generated_number`),
  KEY `ix_document_number_log_source` (`source_type`,`source_id`),
  KEY `ix_document_number_log_sequence` (`number_sequence_id`),
  KEY `fk_dnl_generated_by` (`generated_by`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_number_log`
--

LOCK TABLES `document_number_log` WRITE;
/*!40000 ALTER TABLE `document_number_log` DISABLE KEYS */;
INSERT INTO `document_number_log` VALUES (1,1,'HO-MI-WK-26-000001','Loan',1,12,'2026-10-14 09:13:49'),(2,2,'HO-CA-26-10-00001','FacilityPayment',1,1,'2026-10-14 09:15:04'),(3,3,'JV-26-000001','JournalEntry',1,NULL,'2026-10-14 09:15:04'),(4,4,'FTX-26-000001','FinanceTransaction',1,NULL,'2026-10-14 09:15:04'),(5,3,'JV-26-000002','JournalEntry',2,NULL,'2026-10-14 09:26:03'),(6,3,'JV-26-000003','JournalEntry',3,NULL,'2026-10-14 09:26:56'),(7,4,'FTX-26-000002','FinanceTransaction',2,NULL,'2026-10-14 09:26:56'),(8,8,'HO-SMED-26-000002','Loan',2,1,'2026-10-22 07:54:33'),(9,2,'HO-CA-26-10-00002','FacilityPayment',2,12,'2026-10-22 08:30:09'),(10,3,'JV-26-000004','JournalEntry',4,NULL,'2026-10-22 08:30:09'),(11,4,'FTX-26-000003','FinanceTransaction',3,NULL,'2026-10-22 08:30:09'),(12,12,'HO-DEP-26-10-000001','FdDeposit',1,12,'2026-10-22 08:32:48'),(13,3,'JV-26-000005','JournalEntry',5,NULL,'2026-10-22 08:32:48'),(14,4,'FTX-26-000004','FinanceTransaction',4,NULL,'2026-10-22 08:32:48'),(15,15,'HO-EXP-26-00001','Expense',1,NULL,'2026-10-22 08:34:54'),(16,3,'JV-26-000006','JournalEntry',6,NULL,'2026-10-22 08:35:19'),(18,18,'HO-SD-26-000001','SpeedDraft',3,1,'2026-10-22 08:50:46');
/*!40000 ALTER TABLE `document_number_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_categories`
--

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
  KEY `ix_expense_categories_coa` (`chart_of_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_categories`
--

LOCK TABLES `expense_categories` WRITE;
/*!40000 ALTER TABLE `expense_categories` DISABLE KEYS */;
INSERT INTO `expense_categories` VALUES (2,'Incentive FD','6060','Deposit commission',30,'Active',0,'2026-08-15 09:30:29','2026-08-15 09:32:31'),(3,'Loan Incentive','6061','Loan commission',30,'Active',0,'2026-08-15 09:32:17','2026-08-15 09:32:17'),(4,'Salary','5200-100','Staff Salary',30,'Active',0,'2026-08-20 19:34:22','2026-08-20 19:34:22'),(5,'Staff allowance','SA','Allawances',60,'Active',0,'2026-08-29 19:41:31','2026-08-29 19:41:31');
/*!40000 ALTER TABLE `expense_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

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
  KEY `expenses_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
INSERT INTO `expenses` VALUES (1,'HO-EXP-26-00001',1,2,3,10000.00,'2026-10-22',NULL,'Approved',12,1,'2026-10-22 14:05:19','2026-10-22 08:34:55','2026-10-22 08:35:19');
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_charge_allocations`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_charge_allocations`
--

LOCK TABLES `facility_charge_allocations` WRITE;
/*!40000 ALTER TABLE `facility_charge_allocations` DISABLE KEYS */;
INSERT INTO `facility_charge_allocations` VALUES (1,1,1,500.00,'2026-10-14 09:15:04','2026-10-14 09:15:04'),(2,2,2,12000.00,'2026-10-22 08:30:09','2026-10-22 08:30:09');
/*!40000 ALTER TABLE `facility_charge_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_charges`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_charges`
--

LOCK TABLES `facility_charges` WRITE;
/*!40000 ALTER TABLE `facility_charges` DISABLE KEYS */;
INSERT INTO `facility_charges` VALUES (1,1,1,1,500.00,500.00,'2026-10-14',NULL,1,'2026-10-14 09:14:19','2026-10-14 09:15:04'),(2,2,1,1,12000.00,12000.00,'2026-10-22',NULL,1,'2026-10-22 08:10:34','2026-10-22 08:30:09');
/*!40000 ALTER TABLE `facility_charges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_co_applicants`
--

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
  KEY `facility_co_applicants_co_applicant_customer_id_index` (`co_applicant_customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_co_applicants`
--

LOCK TABLES `facility_co_applicants` WRITE;
/*!40000 ALTER TABLE `facility_co_applicants` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_co_applicants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_collateral`
--

DROP TABLE IF EXISTS `facility_collateral`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_collateral` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `collateral_type` enum('Vehicle','Jewelry','PawnedJewelry','FixedDeposit','Property','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `collateral_category` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estimated_value` decimal(15,2) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_collateral_credit_facility_id_index` (`credit_facility_id`),
  KEY `facility_collateral_collateral_type_index` (`collateral_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_collateral`
--

LOCK TABLES `facility_collateral` WRITE;
/*!40000 ALTER TABLE `facility_collateral` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_collateral` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_collateral_documents`
--

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

--
-- Dumping data for table `facility_collateral_documents`
--

LOCK TABLES `facility_collateral_documents` WRITE;
/*!40000 ALTER TABLE `facility_collateral_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_collateral_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_collateral_fd_details`
--

DROP TABLE IF EXISTS `facility_collateral_fd_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_collateral_fd_details` (
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
  `lien_status` enum('Active','Released','Settled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `marked_at` datetime NOT NULL,
  `marked_by` bigint unsigned DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `released_by` bigint unsigned DEFAULT NULL,
  `release_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settled_amount` decimal(15,2) unsigned DEFAULT NULL,
  `settlement_withdrawal_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `active_fd_id` bigint unsigned GENERATED ALWAYS AS (if((`lien_status` = _utf8mb4'Active'),`fixed_deposit_id`,NULL)) VIRTUAL,
  PRIMARY KEY (`facility_collateral_id`),
  UNIQUE KEY `uq_fcfd_active_fd` (`active_fd_id`),
  KEY `facility_collateral_fd_details_credit_facility_id_index` (`credit_facility_id`),
  KEY `idx_fcfd_fd_status` (`fixed_deposit_id`,`lien_status`),
  KEY `facility_collateral_fd_details_fd_loan_policy_id_foreign` (`fd_loan_policy_id`),
  KEY `facility_collateral_fd_details_marked_by_foreign` (`marked_by`),
  KEY `facility_collateral_fd_details_released_by_foreign` (`released_by`),
  KEY `facility_collateral_fd_details_settlement_withdrawal_id_foreign` (`settlement_withdrawal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_collateral_fd_details`
--

LOCK TABLES `facility_collateral_fd_details` WRITE;
/*!40000 ALTER TABLE `facility_collateral_fd_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_collateral_fd_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_collateral_jewelry_details`
--

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

--
-- Dumping data for table `facility_collateral_jewelry_details`
--

LOCK TABLES `facility_collateral_jewelry_details` WRITE;
/*!40000 ALTER TABLE `facility_collateral_jewelry_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_collateral_jewelry_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_collateral_pawn_details`
--

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
  KEY `facility_collateral_pawn_details_article_type_id_index` (`article_type_id`),
  KEY `facility_collateral_pawn_details_item_status_index` (`item_status`),
  KEY `facility_collateral_pawn_details_appraised_by_foreign` (`appraised_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_collateral_pawn_details`
--

LOCK TABLES `facility_collateral_pawn_details` WRITE;
/*!40000 ALTER TABLE `facility_collateral_pawn_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_collateral_pawn_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_collateral_vehicle_details`
--

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

--
-- Dumping data for table `facility_collateral_vehicle_details`
--

LOCK TABLES `facility_collateral_vehicle_details` WRITE;
/*!40000 ALTER TABLE `facility_collateral_vehicle_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_collateral_vehicle_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_diary_notes`
--

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

--
-- Dumping data for table `facility_diary_notes`
--

LOCK TABLES `facility_diary_notes` WRITE;
/*!40000 ALTER TABLE `facility_diary_notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_diary_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_documents`
--

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
  KEY `facility_documents_document_category_id_foreign` (`document_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_documents`
--

LOCK TABLES `facility_documents` WRITE;
/*!40000 ALTER TABLE `facility_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_guarantors`
--

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

--
-- Dumping data for table `facility_guarantors`
--

LOCK TABLES `facility_guarantors` WRITE;
/*!40000 ALTER TABLE `facility_guarantors` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_guarantors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_insurance_details`
--

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

--
-- Dumping data for table `facility_insurance_details`
--

LOCK TABLES `facility_insurance_details` WRITE;
/*!40000 ALTER TABLE `facility_insurance_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_insurance_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_payment_allocations`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_payment_allocations`
--

LOCK TABLES `facility_payment_allocations` WRITE;
/*!40000 ALTER TABLE `facility_payment_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_payment_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_payments`
--

DROP TABLE IF EXISTS `facility_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) NOT NULL,
  `credit_facility_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `collected_branch_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `payment_type` enum('Regular','Interest','CapitalReduction','Settlement','ManualAllocation') DEFAULT 'Regular',
  `payment_method` enum('Cash','Bank','Cheque','FdAdjustment') NOT NULL DEFAULT 'Cash',
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
  `manual_allocation` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
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
  KEY `facility_payments_status_index` (`status`),
  CONSTRAINT `facility_payments_chk_1` CHECK (json_valid(`manual_allocation`))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_payments`
--

LOCK TABLES `facility_payments` WRITE;
/*!40000 ALTER TABLE `facility_payments` DISABLE KEYS */;
INSERT INTO `facility_payments` VALUES (1,'HO-CA-26-10-00001',1,1,NULL,500.00,'Regular','Cash','Active','Approved',NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,'2026-10-14 00:00:00','Document charge',NULL,NULL,NULL,NULL,'2026-10-14 09:15:04','2026-10-14 09:15:04'),(2,'HO-CA-26-10-00002',2,1,NULL,12000.00,'Regular','Cash','Active','Approved',NULL,NULL,NULL,NULL,NULL,12,NULL,NULL,'2026-10-22 00:00:00','Document charge',NULL,NULL,NULL,NULL,'2026-10-22 08:30:09','2026-10-22 08:30:09');
/*!40000 ALTER TABLE `facility_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_post_dated_cheques`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_post_dated_cheques`
--

LOCK TABLES `facility_post_dated_cheques` WRITE;
/*!40000 ALTER TABLE `facility_post_dated_cheques` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_post_dated_cheques` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_reschedules`
--

DROP TABLE IF EXISTS `facility_reschedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `facility_reschedules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `credit_facility_id` bigint unsigned NOT NULL,
  `new_credit_facility_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `as_of_date` date NOT NULL,
  `capital_outstanding` decimal(15,2) NOT NULL,
  `unpaid_interest` decimal(15,2) NOT NULL,
  `overdue_interest` decimal(15,2) NOT NULL,
  `default_charges` decimal(15,2) NOT NULL,
  `other_charges` decimal(15,2) NOT NULL,
  `interest_deduction` decimal(15,2) NOT NULL DEFAULT '0.00',
  `capitalised_interest` decimal(15,2) NOT NULL DEFAULT '0.00',
  `interest_written_off` decimal(15,2) NOT NULL DEFAULT '0.00',
  `default_charges_action` enum('retain','waive') COLLATE utf8mb4_unicode_ci NOT NULL,
  `retained_default_charges` decimal(15,2) NOT NULL DEFAULT '0.00',
  `other_charges_rolled` decimal(15,2) NOT NULL DEFAULT '0.00',
  `added_charges_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `added_charges` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `new_principal` decimal(15,2) NOT NULL,
  `new_interest_rate_percent` decimal(6,3) NOT NULL,
  `new_term_months` smallint unsigned NOT NULL,
  `new_interest_method` enum('flat','reducing_balance') COLLATE utf8mb4_unicode_ci NOT NULL,
  `new_installment_frequency` enum('daily','weekly','monthly') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facility_reschedules_new_credit_facility_id_foreign` (`new_credit_facility_id`),
  KEY `facility_reschedules_branch_id_foreign` (`branch_id`),
  KEY `facility_reschedules_created_by_foreign` (`created_by`),
  KEY `facility_reschedules_credit_facility_id_index` (`credit_facility_id`),
  CONSTRAINT `facility_reschedules_chk_1` CHECK (json_valid(`added_charges`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_reschedules`
--

LOCK TABLES `facility_reschedules` WRITE;
/*!40000 ALTER TABLE `facility_reschedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_reschedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_schedules`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_schedules`
--

LOCK TABLES `facility_schedules` WRITE;
/*!40000 ALTER TABLE `facility_schedules` DISABLE KEYS */;
INSERT INTO `facility_schedules` VALUES (37,1,1,'2026-10-14',3887.92,625.00,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(38,1,2,'2026-10-21',3936.52,576.40,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(39,1,3,'2026-10-28',3985.73,527.19,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(40,1,4,'2026-11-04',4035.55,477.37,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(41,1,5,'2026-11-11',4085.99,426.93,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(42,1,6,'2026-11-18',4137.07,375.85,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(43,1,7,'2026-11-25',4188.78,324.14,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(44,1,8,'2026-12-02',4241.14,271.78,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(45,1,9,'2026-12-09',4294.15,218.77,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(46,1,10,'2026-12-16',4347.83,165.09,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(47,1,11,'2026-12-23',4402.18,110.74,0.00,0.00,4512.92,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56'),(48,1,12,'2026-12-30',4457.14,55.71,0.00,0.00,4512.85,0.00,NULL,'Pending','2026-10-14 09:26:56','2026-10-14 09:26:56');
/*!40000 ALTER TABLE `facility_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_status_events`
--

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

--
-- Dumping data for table `facility_status_events`
--

LOCK TABLES `facility_status_events` WRITE;
/*!40000 ALTER TABLE `facility_status_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_status_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facility_waivers`
--

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
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facility_waivers`
--

LOCK TABLES `facility_waivers` WRITE;
/*!40000 ALTER TABLE `facility_waivers` DISABLE KEYS */;
/*!40000 ALTER TABLE `facility_waivers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

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

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fd_deposits`
--

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
  `bank_account_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_deposits_deposit_number_unique` (`deposit_number`),
  KEY `fd_deposits_created_by_foreign` (`created_by`),
  KEY `fd_deposits_branch_id_index` (`branch_id`),
  KEY `fd_deposits_customer_id_index` (`customer_id`),
  KEY `fd_deposits_fixed_deposit_id_index` (`fixed_deposit_id`),
  KEY `fd_deposits_bank_id_index` (`bank_id`),
  KEY `fd_deposits_status_index` (`status`),
  KEY `fd_deposits_bank_account_id_foreign` (`bank_account_id`),
  CONSTRAINT `fd_deposits_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fd_deposits`
--

LOCK TABLES `fd_deposits` WRITE;
/*!40000 ALTER TABLE `fd_deposits` DISABLE KEYS */;
INSERT INTO `fd_deposits` VALUES (1,'HO-DEP-26-10-000001',1,54,'2026-10-22',1000000.00,'Cash',NULL,NULL,NULL,'Available',NULL,12,'2026-10-22 08:32:48','2026-10-22 08:32:48',NULL);
/*!40000 ALTER TABLE `fd_deposits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fd_interest_payments`
--

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
  `bank_account_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_interest_payments_fixed_deposit_id_installment_no_unique` (`fixed_deposit_id`,`installment_no`),
  KEY `fd_interest_payments_paid_by_foreign` (`paid_by`),
  KEY `fd_interest_payments_status_due_date_index` (`status`,`due_date`),
  KEY `fd_interest_payments_bank_account_id_foreign` (`bank_account_id`),
  CONSTRAINT `fd_interest_payments_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fd_interest_payments`
--

LOCK TABLES `fd_interest_payments` WRITE;
/*!40000 ALTER TABLE `fd_interest_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `fd_interest_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fd_loan_policies`
--

DROP TABLE IF EXISTS `fd_loan_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fd_loan_policies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `funding_product_id` bigint unsigned NOT NULL,
  `max_ltv_percent` decimal(5,2) unsigned NOT NULL DEFAULT '90.00',
  `rate_mode` enum('spread','fixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'spread',
  `rate_spread_percent` decimal(6,3) unsigned NOT NULL DEFAULT '2.000',
  `fixed_rate_percent` decimal(6,3) unsigned DEFAULT NULL,
  `min_loan_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `max_loan_amount` decimal(15,2) unsigned DEFAULT NULL,
  `processing_fee_percent` decimal(5,2) unsigned NOT NULL DEFAULT '0.00',
  `min_days_to_maturity` smallint unsigned NOT NULL DEFAULT '30',
  `cap_term_to_maturity` tinyint(1) NOT NULL DEFAULT '1',
  `auto_adjust_on_maturity` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_loan_policies_funding_product_id_unique` (`funding_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fd_loan_policies`
--

LOCK TABLES `fd_loan_policies` WRITE;
/*!40000 ALTER TABLE `fd_loan_policies` DISABLE KEYS */;
/*!40000 ALTER TABLE `fd_loan_policies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fd_part_payments`
--

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
  `bank_account_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_part_payments_voucher_no_unique` (`voucher_no`),
  KEY `fd_part_payments_fixed_deposit_id_paid_at_index` (`fixed_deposit_id`,`paid_at`),
  KEY `fd_part_payments_created_by_foreign` (`created_by`),
  KEY `fd_part_payments_bank_account_id_foreign` (`bank_account_id`),
  CONSTRAINT `fd_part_payments_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fd_part_payments`
--

LOCK TABLES `fd_part_payments` WRITE;
/*!40000 ALTER TABLE `fd_part_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `fd_part_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fd_renewals`
--

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
  `interest_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `capitalised_interest` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `interest_payment_method` varchar(16) DEFAULT NULL,
  `is_auto` tinyint(1) NOT NULL DEFAULT '0',
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
  KEY `fd_renewals_additional_deposit_id_index` (`additional_deposit_id`),
  CONSTRAINT `chk_fdr_new_principal` CHECK ((`new_principal` = ((`old_principal` + `capitalised_interest`) + `additional_deposit_amount`)))
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fd_renewals`
--

LOCK TABLES `fd_renewals` WRITE;
/*!40000 ALTER TABLE `fd_renewals` DISABLE KEYS */;
/*!40000 ALTER TABLE `fd_renewals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fd_withdrawals`
--

DROP TABLE IF EXISTS `fd_withdrawals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fd_withdrawals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fixed_deposit_id` bigint unsigned NOT NULL,
  `voucher_no` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `principal_amount` decimal(15,2) unsigned NOT NULL,
  `interest_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `total_amount` decimal(15,2) unsigned NOT NULL,
  `adjusted_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `post_maturity_days` smallint unsigned NOT NULL DEFAULT '0',
  `post_maturity_rate_percent` decimal(6,3) DEFAULT NULL,
  `post_maturity_interest` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `payment_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `closed_at` date NOT NULL,
  `settled_credit_facility_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `bank_account_id` bigint unsigned DEFAULT NULL,
  `is_premature` tinyint(1) NOT NULL DEFAULT '0',
  `premature_rate_percent` decimal(6,3) DEFAULT NULL,
  `premature_held_days` int unsigned DEFAULT NULL,
  `premature_clawback` decimal(15,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `fd_withdrawals_fixed_deposit_id_unique` (`fixed_deposit_id`),
  UNIQUE KEY `fd_withdrawals_voucher_no_unique` (`voucher_no`),
  KEY `fd_withdrawals_created_by_foreign` (`created_by`),
  KEY `fd_withdrawals_bank_account_id_foreign` (`bank_account_id`),
  KEY `fd_withdrawals_settled_credit_facility_id_foreign` (`settled_credit_facility_id`),
  CONSTRAINT `fd_withdrawals_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fd_withdrawals_settled_credit_facility_id_foreign` FOREIGN KEY (`settled_credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fd_withdrawals`
--

LOCK TABLES `fd_withdrawals` WRITE;
/*!40000 ALTER TABLE `fd_withdrawals` DISABLE KEYS */;
/*!40000 ALTER TABLE `fd_withdrawals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_audit_events`
--

DROP TABLE IF EXISTS `finance_audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_audit_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint unsigned DEFAULT NULL,
  `action` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `subject_type` varchar(48) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `from_status` varchar(32) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `to_status` varchar(32) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `reason` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_fae_subject` (`subject_type`,`subject_id`),
  KEY `ix_fae_action_date` (`action`,`created_at`),
  KEY `ix_fae_branch_date` (`branch_id`,`created_at`),
  KEY `fk_fae_actor` (`actor_id`),
  CONSTRAINT `finance_audit_events_chk_1` CHECK (json_valid(`meta`))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_audit_events`
--

LOCK TABLES `finance_audit_events` WRITE;
/*!40000 ALTER TABLE `finance_audit_events` DISABLE KEYS */;
INSERT INTO `finance_audit_events` VALUES (1,1,'transaction.executed','FinanceTransaction',1,NULL,'Completed',500.00,1,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0','{\"txn_type\":\"COLLECTION\",\"code\":\"FTX-26-000001\",\"source\":\"LoanPayment\"}','2026-10-14 09:15:04'),(2,1,'transaction.executed','FinanceTransaction',2,NULL,'Completed',50000.00,1,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0','{\"txn_type\":\"DISBURSEMENT\",\"code\":\"FTX-26-000002\",\"source\":\"Loan\"}','2026-10-14 09:26:56'),(3,12,'transaction.executed','FinanceTransaction',3,NULL,'Completed',12000.00,1,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:158.0) Gecko/20100101 Firefox/158.0','{\"txn_type\":\"COLLECTION\",\"code\":\"FTX-26-000003\",\"source\":\"LoanPayment\"}','2026-10-22 08:30:09'),(4,12,'transaction.executed','FinanceTransaction',4,NULL,'Completed',1000000.00,1,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:158.0) Gecko/20100101 Firefox/158.0','{\"txn_type\":\"CASH_IN\",\"code\":\"FTX-26-000004\",\"source\":\"FdDeposit\"}','2026-10-22 08:32:48');
/*!40000 ALTER TABLE `finance_audit_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_requests`
--

DROP TABLE IF EXISTS `finance_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_type` enum('LoanDisbursement','ExpensePayment','SupplierPayment','Refund','Advance','CashFloat','BranchFunding','CommissionPayout','FdPayout','CashTransfer','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_module` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'LKR',
  `payee_type` enum('Customer','Vendor','User','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payee_id` bigint unsigned DEFAULT NULL,
  `payee_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purpose` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` enum('Normal','Urgent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Normal',
  `status` enum('Draft','Submitted','PendingApproval','Approved','Rejected','ReadyForPayment','Processing','InTransit','Paid','Completed','Cancelled','Reversed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `approval_request_id` bigint unsigned DEFAULT NULL,
  `source_cash_account_id` bigint unsigned DEFAULT NULL,
  `source_bank_account_id` bigint unsigned DEFAULT NULL,
  `finance_transaction_id` bigint unsigned DEFAULT NULL,
  `idempotency_key` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `executed_by` bigint unsigned DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `executed_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_requests_code_unique` (`code`),
  UNIQUE KEY `finance_requests_idempotency_key_unique` (`idempotency_key`),
  KEY `ix_fr_status_branch` (`status`,`branch_id`),
  KEY `ix_fr_type_status` (`request_type`,`status`),
  KEY `ix_fr_source` (`source_module`,`source_id`),
  KEY `fk_fr_branch` (`branch_id`),
  KEY `fk_fr_approval_request` (`approval_request_id`),
  KEY `fk_fr_cash_account` (`source_cash_account_id`),
  KEY `fk_fr_bank_account` (`source_bank_account_id`),
  KEY `fk_fr_finance_txn` (`finance_transaction_id`),
  KEY `fk_fr_requested_by` (`requested_by`),
  KEY `fk_fr_approved_by` (`approved_by`),
  KEY `fk_fr_executed_by` (`executed_by`),
  CONSTRAINT `finance_requests_chk_1` CHECK (json_valid(`meta`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_requests`
--

LOCK TABLES `finance_requests` WRITE;
/*!40000 ALTER TABLE `finance_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_settings`
--

DROP TABLE IF EXISTS `finance_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `value` text COLLATE utf8mb4_general_ci,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_settings_key_unique` (`key`),
  KEY `fk_fset_updated_by` (`updated_by`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_settings`
--

LOCK TABLES `finance_settings` WRITE;
/*!40000 ALTER TABLE `finance_settings` DISABLE KEYS */;
INSERT INTO `finance_settings` VALUES (1,'require_cash_session','0',1,'2026-09-01 12:56:55','2026-09-26 09:00:45'),(2,'reversal_requires_approval','1',1,'2026-09-01 12:56:55','2026-09-01 12:56:55'),(3,'savings_large_withdrawal_threshold','0',1,'2026-09-08 18:32:39','2026-09-08 18:32:39');
/*!40000 ALTER TABLE `finance_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_transactions`
--

DROP TABLE IF EXISTS `finance_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `txn_type` enum('CASH_IN','CASH_OUT','TRANSFER','DISBURSEMENT','EXPENSE_PAYMENT','FLOAT_ISSUE','FLOAT_RETURN','COLLECTION','BANK_DEPOSIT','BANK_WITHDRAWAL','ADJUSTMENT','REVERSAL') COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` enum('In','Out','Move') COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_account_type` enum('cash','bank') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_account_id` bigint unsigned DEFAULT NULL,
  `dest_account_type` enum('cash','bank') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dest_account_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'LKR',
  `branch_id` bigint unsigned DEFAULT NULL,
  `finance_request_id` bigint unsigned DEFAULT NULL,
  `source_module` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `status` enum('Draft','Pending','Completed','InTransit','Reversed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Completed',
  `reversal_of_id` bigint unsigned DEFAULT NULL,
  `idempotency_key` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `requested_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `executed_by` bigint unsigned DEFAULT NULL,
  `received_by` bigint unsigned DEFAULT NULL,
  `executed_at` datetime DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_transactions_code_unique` (`code`),
  UNIQUE KEY `finance_transactions_idempotency_key_unique` (`idempotency_key`),
  KEY `ix_ftx_source_account` (`source_account_type`,`source_account_id`,`executed_at`),
  KEY `ix_ftx_dest_account` (`dest_account_type`,`dest_account_id`,`executed_at`),
  KEY `ix_ftx_branch_date` (`branch_id`,`executed_at`),
  KEY `ix_ftx_type_status` (`txn_type`,`status`),
  KEY `ix_ftx_source` (`source_module`,`source_id`),
  KEY `fk_ftx_journal_entry` (`journal_entry_id`),
  KEY `fk_ftx_reversal_of` (`reversal_of_id`),
  KEY `fk_ftx_requested_by` (`requested_by`),
  KEY `fk_ftx_approved_by` (`approved_by`),
  KEY `fk_ftx_executed_by` (`executed_by`),
  KEY `fk_ftx_received_by` (`received_by`),
  KEY `fk_ftx_finance_request` (`finance_request_id`),
  CONSTRAINT `chk_ftx_amount` CHECK ((`amount` > 0)),
  CONSTRAINT `finance_transactions_chk_1` CHECK (json_valid(`meta`))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_transactions`
--

LOCK TABLES `finance_transactions` WRITE;
/*!40000 ALTER TABLE `finance_transactions` DISABLE KEYS */;
INSERT INTO `finance_transactions` VALUES (1,'FTX-26-000001','COLLECTION','In',NULL,NULL,'cash',17,500.00,'LKR',1,NULL,'LoanPayment',1,1,'Completed',NULL,'loan-payment-1','Loan payment HO-CA-26-10-00001',NULL,NULL,NULL,1,NULL,'2026-10-14 14:45:04',NULL,'2026-10-14 09:15:04','2026-10-14 09:15:04'),(2,'FTX-26-000002','DISBURSEMENT','Out','cash',22,NULL,NULL,50000.00,'LKR',1,NULL,'Loan',1,3,'Completed',NULL,'loan-disbursement-1','Loan disbursed (Cash) - HO-MI-WK-26-000001',NULL,NULL,NULL,1,NULL,'2026-10-14 14:56:56',NULL,'2026-10-14 09:26:56','2026-10-14 09:26:56'),(3,'FTX-26-000003','COLLECTION','In',NULL,NULL,'cash',17,12000.00,'LKR',1,NULL,'LoanPayment',2,4,'Completed',NULL,'loan-payment-2','Loan payment HO-CA-26-10-00002',NULL,NULL,NULL,12,NULL,'2026-10-22 14:00:09',NULL,'2026-10-22 08:30:09','2026-10-22 08:30:09'),(4,'FTX-26-000004','CASH_IN','In',NULL,NULL,'cash',16,1000000.00,'LKR',1,NULL,'FdDeposit',1,5,'Completed',NULL,'fd-deposit-1','FD deposit received - HO-DEP-26-10-000001',NULL,NULL,NULL,12,NULL,'2026-10-22 14:02:48',NULL,'2026-10-22 08:32:48','2026-10-22 08:32:48');
/*!40000 ALTER TABLE `finance_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fixed_deposits`
--

DROP TABLE IF EXISTS `fixed_deposits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fixed_deposits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fd_number` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `funding_product_id` bigint unsigned NOT NULL,
  `principal_amount` decimal(15,2) unsigned NOT NULL,
  `interest_rate_percent` decimal(6,3) unsigned NOT NULL,
  `interest_payment_method` enum('monthly','maturity') COLLATE utf8mb4_unicode_ci NOT NULL,
  `term_months` smallint unsigned NOT NULL,
  `start_date` date NOT NULL,
  `maturity_date` date NOT NULL,
  `monthly_interest_amount` decimal(15,2) unsigned DEFAULT NULL,
  `total_interest_amount` decimal(15,2) unsigned NOT NULL,
  `maturity_amount` decimal(15,2) unsigned NOT NULL,
  `lien_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `auto_renew` tinyint(1) NOT NULL DEFAULT '0',
  `auto_renew_mode` enum('principal_only','principal_and_interest') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auto_renew_term_months` smallint unsigned DEFAULT NULL,
  `next_interest_date` date DEFAULT NULL,
  `status` enum('Active','Matured','Renewed','Closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `renewed_from_id` bigint unsigned DEFAULT NULL,
  `renewed_to_id` bigint unsigned DEFAULT NULL,
  `closed_at` date DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `customer_bank_detail_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fixed_deposits_public_uuid_unique` (`public_uuid`),
  UNIQUE KEY `fixed_deposits_fd_number_unique` (`fd_number`),
  KEY `fixed_deposits_renewed_from_id_foreign` (`renewed_from_id`),
  KEY `fixed_deposits_renewed_to_id_foreign` (`renewed_to_id`),
  KEY `fixed_deposits_created_by_foreign` (`created_by`),
  KEY `fixed_deposits_branch_id_index` (`branch_id`),
  KEY `fixed_deposits_customer_id_index` (`customer_id`),
  KEY `fixed_deposits_funding_product_id_index` (`funding_product_id`),
  KEY `fixed_deposits_status_maturity_date_index` (`status`,`maturity_date`),
  KEY `fixed_deposits_lien_amount_index` (`lien_amount`),
  KEY `fixed_deposits_customer_bank_detail_id_foreign` (`customer_bank_detail_id`),
  CONSTRAINT `fixed_deposits_customer_bank_detail_id_foreign` FOREIGN KEY (`customer_bank_detail_id`) REFERENCES `customer_bank_details` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fixed_deposits`
--

LOCK TABLES `fixed_deposits` WRITE;
/*!40000 ALTER TABLE `fixed_deposits` DISABLE KEYS */;
/*!40000 ALTER TABLE `fixed_deposits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `funding_products`
--

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
  `post_maturity_rate_percent` decimal(6,3) DEFAULT NULL,
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

--
-- Dumping data for table `funding_products`
--

LOCK TABLES `funding_products` WRITE;
/*!40000 ALTER TABLE `funding_products` DISABLE KEYS */;
INSERT INTO `funding_products` VALUES (2,'Pro-Note','PN','maturity',0,20.000,NULL,NULL,1.667,0,'[3,6,12]',1,1000.00,100000000.00,NULL,0,'Active','2026-08-07 10:58:53','2026-08-07 10:58:53'),(4,'PRO-MONTHLY','PRM','monthly',1,20.000,12.660,NULL,1.667,1,'[3,6,12]',1,100.00,900000000.00,NULL,0,'Active','2026-08-08 10:27:15','2026-08-31 13:34:08');
/*!40000 ALTER TABLE `funding_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `idempotency_keys`
--

DROP TABLE IF EXISTS `idempotency_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `idempotency_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `idempotency_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `response_status` smallint unsigned DEFAULT NULL,
  `response_body` mediumtext COLLATE utf8mb4_unicode_ci,
  `locked_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idempotency` (`idempotency_key`,`method`,`path`),
  KEY `idempotency_keys_created_at_index` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `idempotency_keys`
--

LOCK TABLES `idempotency_keys` WRITE;
/*!40000 ALTER TABLE `idempotency_keys` DISABLE KEYS */;
INSERT INTO `idempotency_keys` VALUES (6,'2e3bb0e4-c817-41b7-8745-5cb482ddbe1e','POST','/api/loans/2/payments','c44a6e626204eb6a3166d680b383c53df3c3836131ac0137b2c3ee25f8215e26',201,'{\"code\":\"HO-CA-26-10-00002\",\"credit_facility_id\":2,\"branch_id\":1,\"collected_branch_id\":null,\"amount\":\"12000.00\",\"payment_method\":\"Cash\",\"payment_type\":\"Regular\",\"clearance_status\":null,\"received_by\":12,\"paid_at\":\"2026-10-22T00:00:00.000000Z\",\"receipt_no\":null,\"bank_id\":null,\"cheque_date\":null,\"notes\":\"Document charge\",\"approval_status\":\"Approved\",\"manual_allocation\":null,\"updated_at\":\"2026-10-22T14:00:09.000000Z\",\"created_at\":\"2026-10-22T14:00:09.000000Z\",\"id\":2,\"facility\":{\"id\":2,\"public_uuid\":\"e4632def-e550-4dae-9ecf-ca72609bca0a\",\"code\":\"HO-SMED-26-000002\",\"facility_type\":\"Loan\",\"branch_id\":1,\"customer_id\":54,\"principal_amount\":\"600000.00\",\"interest_rate_percent\":\"8.000\",\"rate_period\":\"monthly\",\"term_months\":3,\"start_date\":null,\"maturity_date\":null,\"disbursed_amount\":null,\"disbursed_at\":null,\"outstanding_balance_cache\":\"0.00\",\"status\":\"Approved\",\"restructured_from_id\":null,\"applied_by\":1,\"approved_by\":12,\"approved_at\":\"2026-10-22T13:31:19.000000Z\",\"rejected_reason\":null,\"cancelled_reason\":null,\"cancelled_by\":null,\"cancelled_at\":null,\"written_off_reason\":null,\"written_off_by\":null,\"npl_flag\":false,\"provision_required_pct\":0,\"provision_required_amount\":\"0.00\",\"provision_posted_amount\":\"0.00\",\"provision_last_evaluated_at\":\"2026-10-22T14:00:09.000000Z\",\"closed_at\":null,\"created_by\":1,\"created_at\":\"2026-10-22T13:24:33.000000Z\",\"updated_at\":\"2026-10-22T14:00:09.000000Z\",\"deleted_at\":null,\"customer_bank_detail_id\":1},\"receiver\":{\"id\":12,\"name\":\"ada\"}}','2026-10-22 08:30:09','2026-10-22 08:30:09','2026-10-22 08:30:09','2026-10-22 08:30:09'),(7,'2c380ebe-dbdc-4dba-a1c9-bc107576da0f','POST','/api/fd-deposits','81aab4f366107a8959b1a72b1a86bc941be50ee5ba4232e3f74db782ee664644',201,'{\"branch_id\":1,\"customer_id\":54,\"deposit_date\":\"2026-10-22\",\"amount\":\"1000000.00\",\"payment_method\":\"Cash\",\"created_by\":12,\"status\":\"Available\",\"deposit_number\":\"HO-DEP-26-10-000001\",\"updated_at\":\"2026-10-22T14:02:48.000000Z\",\"created_at\":\"2026-10-22T14:02:48.000000Z\",\"id\":1,\"customer\":{\"id\":54,\"full_name\":\"Mr. A A Suraj Asanka\",\"code\":\"CUS-000020\",\"nic_no\":\"901402370V\",\"phone\":null,\"has_photo\":false},\"branch\":{\"id\":1,\"name\":\"Head Office\",\"code\":\"HO\"},\"fixed_deposit\":null,\"bank\":null,\"creator\":{\"id\":12,\"name\":\"ada\"}}','2026-10-22 08:32:48','2026-10-22 08:32:48','2026-10-22 08:32:48','2026-10-22 08:32:48'),(8,'af9392df-d95a-49b7-90bb-4d55a60e8da4','POST','/api/expenses','87fddcbe0a7f7130a82ec0bb3fa956554a56b9703bca33d5b4913990a861c59f',201,'{\"branch_id\":1,\"expense_category_id\":2,\"vendor_id\":3,\"amount\":\"10000.00\",\"expense_date\":\"2026-10-22T00:00:00.000000Z\",\"code\":\"HO-EXP-26-00001\",\"status\":\"Pending\",\"requested_by\":12,\"updated_at\":\"2026-10-22T14:04:55.000000Z\",\"created_at\":\"2026-10-22T14:04:55.000000Z\",\"id\":1,\"category\":{\"id\":2,\"name\":\"Incentive FD\"}}','2026-10-22 08:34:54','2026-10-22 08:34:55','2026-10-22 08:34:54','2026-10-22 08:34:55');
/*!40000 ALTER TABLE `idempotency_keys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_entries`
--

DROP TABLE IF EXISTS `journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_date` date NOT NULL,
  `source_type` enum('Loan','Customer','LoanPayment','LoanProvision','LoanReschedule','SpeedDraft','SpeedDraftPayment','SpeedDraftRenewal','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdPartPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','SalesExecutiveCommission','ManualAdjustment','OpeningBalance','Borrowing','BorrowingTransaction','FinanceTransaction','BankTransaction','SavingsTransaction','SavingsInterestRun','BranchFunding','BorrowingAccrual') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `counterparty_branch_id` bigint unsigned DEFAULT NULL,
  `posted_by` bigint unsigned DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `status` enum('Posted','Reversed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Posted',
  `reversal_of_id` bigint unsigned DEFAULT NULL,
  `related_entry_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_entries_code` (`code`),
  KEY `ix_journal_entries_source` (`source_type`,`source_id`),
  KEY `ix_journal_entries_entry_date` (`entry_date`),
  KEY `ix_journal_entries_branch_id` (`branch_id`),
  KEY `ix_journal_entries_posted_by` (`posted_by`),
  KEY `ix_journal_entries_reversal_of` (`reversal_of_id`),
  KEY `journal_entries_branch_id_status_entry_date_index` (`branch_id`,`status`,`entry_date`),
  KEY `journal_entries_related_entry_id_index` (`related_entry_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_entries`
--

LOCK TABLES `journal_entries` WRITE;
/*!40000 ALTER TABLE `journal_entries` DISABLE KEYS */;
INSERT INTO `journal_entries` VALUES (1,'JV-26-000001','2026-10-14','LoanPayment',1,'Loan payment HO-CA-26-10-00001',1,NULL,1,'2026-10-14 14:45:04','Posted',NULL,NULL,'2026-10-14 09:15:04','2026-10-14 09:15:04'),(2,'JV-26-000002','2026-10-14','OpeningBalance',NULL,'Opening balance - Head Office - Disbursements Till',1,NULL,NULL,'2026-10-14 14:56:03','Posted',NULL,NULL,'2026-10-14 09:26:03','2026-10-14 09:26:03'),(3,'JV-26-000003','2026-10-14','Loan',1,'Loan disbursed (Cash) - HO-MI-WK-26-000001',1,NULL,1,'2026-10-14 14:56:56','Posted',NULL,NULL,'2026-10-14 09:26:56','2026-10-14 09:26:56'),(4,'JV-26-000004','2026-10-22','LoanPayment',2,'Loan payment HO-CA-26-10-00002',1,NULL,12,'2026-10-22 14:00:09','Posted',NULL,NULL,'2026-10-22 08:30:09','2026-10-22 08:30:09'),(5,'JV-26-000005','2026-10-22','FdDeposit',1,'FD deposit received - HO-DEP-26-10-000001',1,NULL,12,'2026-10-22 14:02:48','Posted',NULL,NULL,'2026-10-22 08:32:48','2026-10-22 08:32:48'),(6,'JV-26-000006','2026-10-22','Expense',1,'Expense accrued HO-EXP-26-00001',1,NULL,1,'2026-10-22 14:05:19','Posted',NULL,NULL,'2026-10-22 08:35:19','2026-10-22 08:35:19');
/*!40000 ALTER TABLE `journal_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_lines`
--

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
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_journal_lines_account_date` (`account_id`,`entry_date`),
  KEY `ix_journal_lines_journal_entry` (`journal_entry_id`),
  CONSTRAINT `chk_journal_lines_one_side` CHECK ((((`debit` = 0) and (`credit` > 0)) or ((`debit` > 0) and (`credit` = 0))))
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_lines`
--

LOCK TABLES `journal_lines` WRITE;
/*!40000 ALTER TABLE `journal_lines` DISABLE KEYS */;
INSERT INTO `journal_lines` VALUES (1,1,49,'2026-10-14',500.00,0.00,NULL,'2026-10-14 09:15:04','2026-10-14 09:15:04'),(2,1,56,'2026-10-14',0.00,500.00,NULL,'2026-10-14 09:15:04','2026-10-14 09:15:04'),(3,2,94,'2026-10-14',60000.00,0.00,NULL,'2026-10-14 09:26:03','2026-10-14 09:26:03'),(4,2,20,'2026-10-14',0.00,60000.00,NULL,'2026-10-14 09:26:03','2026-10-14 09:26:03'),(5,3,10,'2026-10-14',50000.00,0.00,NULL,'2026-10-14 09:26:56','2026-10-14 09:26:56'),(6,3,94,'2026-10-14',0.00,50000.00,NULL,'2026-10-14 09:26:56','2026-10-14 09:26:56'),(7,4,49,'2026-10-22',12000.00,0.00,NULL,'2026-10-22 08:30:09','2026-10-22 08:30:09'),(8,4,56,'2026-10-22',0.00,12000.00,NULL,'2026-10-22 08:30:09','2026-10-22 08:30:09'),(9,5,48,'2026-10-22',1000000.00,0.00,NULL,'2026-10-22 08:32:48','2026-10-22 08:32:48'),(10,5,15,'2026-10-22',0.00,1000000.00,NULL,'2026-10-22 08:32:48','2026-10-22 08:32:48'),(11,6,30,'2026-10-22',10000.00,0.00,NULL,'2026-10-22 08:35:19','2026-10-22 08:35:19'),(12,6,18,'2026-10-22',0.00,10000.00,NULL,'2026-10-22 08:35:19','2026-10-22 08:35:19');
/*!40000 ALTER TABLE `journal_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_facility_details`
--

DROP TABLE IF EXISTS `loan_facility_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_facility_details` (
  `credit_facility_id` bigint unsigned NOT NULL,
  `loan_type_id` bigint unsigned NOT NULL,
  `vehicle_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `down_payment` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `document_charge_amount` decimal(15,2) DEFAULT NULL,
  `disbursement_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disbursement_bank_account_id` bigint unsigned DEFAULT NULL,
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
  KEY `loan_facility_details_recovery_officer_id_index` (`recovery_officer_id`),
  KEY `lfd_disb_bank_acc_fk` (`disbursement_bank_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_facility_details`
--

LOCK TABLES `loan_facility_details` WRITE;
/*!40000 ALTER TABLE `loan_facility_details` DISABLE KEYS */;
INSERT INTO `loan_facility_details` VALUES (1,8,NULL,0.00,NULL,'Cash',NULL,'reducing_balance',NULL,NULL,'weekly',NULL,'2026-10-14',NULL,NULL,NULL,NULL,NULL,'2026-10-14',NULL,NULL,NULL),(2,6,NULL,0.00,NULL,NULL,NULL,'flat',NULL,NULL,'daily',NULL,'2026-10-22',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `loan_facility_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_provisions`
--

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
  KEY `loan_provisions_credit_facility_id_created_at_index` (`credit_facility_id`,`created_at`),
  KEY `loan_provisions_posted_by_foreign` (`posted_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_provisions`
--

LOCK TABLES `loan_provisions` WRITE;
/*!40000 ALTER TABLE `loan_provisions` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_provisions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_types`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_types`
--

LOCK TABLES `loan_types` WRITE;
/*!40000 ALTER TABLE `loan_types` DISABLE KEYS */;
INSERT INTO `loan_types` VALUES (1,NULL,'Leasing','LE',NULL,1,'Leasing','monthly','all_days','reducing_balance',60.000,'annual',100000.00,10000000.00,12,60,5,6.000,2.000,'percentage',1.00,1,0,'Active','2026-08-03 11:44:08','2026-08-05 00:10:54'),(2,NULL,'Consumer Loan','CO',NULL,1,'Consumer','monthly','all_days','reducing_balance',3.000,'monthly',10000.00,1000000.00,3,36,5,0.000,2.000,'fixed',0.00,0,0,'Active','2026-08-03 11:44:08','2026-08-05 00:06:30'),(3,NULL,'Mortgage','MO',NULL,1,'Mortgage','monthly','all_days','reducing_balance',60.000,'annual',1000000.00,50000000.00,12,240,5,6.000,1.000,'fixed',10000.00,1,0,'Active','2026-08-03 11:44:08','2026-08-10 21:52:57'),(4,NULL,'Real Estate','REALESTATE',NULL,1,'RealEstate','monthly','all_days','reducing_balance',2.000,'monthly',2000000.00,100000000.00,24,300,5,0.000,0.000,'fixed',0.00,0,0,'Active','2026-08-03 11:44:08','2026-08-04 11:45:15'),(5,NULL,'SME','SME',NULL,0,NULL,NULL,'all_days',NULL,NULL,'monthly',NULL,NULL,NULL,NULL,5,0.000,0.000,'fixed',0.00,0,0,'Active','2026-08-03 11:44:08','2026-08-03 11:44:08'),(6,5,'Daily','SMED',NULL,1,'SME','daily','week_days','flat',8.000,'monthly',10000.00,5000000.00,1,12,5,6.000,2.000,'percentage',15.00,20,0,'Active','2026-08-03 11:44:08','2026-08-04 22:26:00'),(7,5,'Micro','MI',NULL,0,NULL,NULL,'all_days',NULL,NULL,'monthly',NULL,NULL,NULL,NULL,5,0.000,0.000,'fixed',0.00,0,0,'Active','2026-08-03 11:44:08','2026-08-04 22:23:36'),(8,7,'Weekly','MI-WK',NULL,1,'SME','weekly','all_days','reducing_balance',5.000,'monthly',5000.00,100000.00,1,6,3,6.000,1.000,'percentage',1.00,4,0,'Active','2026-08-03 11:44:08','2026-08-04 22:23:13'),(9,7,'Monthly','MI-MO',NULL,1,'SME','monthly','all_days','reducing_balance',4.000,'monthly',5000.00,150000.00,1,12,5,0.000,0.000,'percentage',1.00,4,0,'Active','2026-08-03 11:44:08','2026-08-04 22:24:30'),(10,6,'SME-WEEKLY','SMEW','{BRANCH}-{CODE}-{YEAR:2}-{SEQ:6}',1,'SME','weekly','all_days','flat',8.000,'monthly',1000.00,2000000000.00,1,12,5,6.000,2.000,'percentage',15.00,4,0,'Active','2026-08-05 00:02:09','2026-08-05 00:16:43'),(11,6,'SME-MONTHLY','SMEM','{BRANCH}-{CODE}-{YEAR:2}-{SEQ:6}',1,'SME','monthly','all_days','flat',8.000,'monthly',1.00,30000000.00,1,12,5,6.000,2.000,'percentage',15.00,1,0,'Active','2026-08-05 00:18:57','2026-08-05 00:18:57'),(12,1,'V-FIN','VF','{BRANCH}-{CODE}-{YEAR:2}-{SEQ:6}',1,'Leasing','monthly','all_days','reducing_balance',8.000,'monthly',10.00,20000000000.00,1,600,3,6.000,2.000,'percentage',3.00,1,0,'Active','2026-08-05 00:40:27','2026-08-05 00:40:27'),(14,5,'Platinum','DP','{BRANCH}-{CODE}-{YEAR:2}-{SEQ:6}',1,'SME','monthly','all_days','reducing_balance',5.000,'monthly',1000.00,100000000.00,1,12,5,6.000,1.000,'fixed',5.00,4,0,'Active','2026-08-10 09:10:34','2026-08-10 09:10:34'),(15,5,'SMEWeek','SMEW1','{BRANCH}-{CODE}-{YEAR:2}-{SEQ:6}',1,'SME','weekly','all_days','flat',7.000,'monthly',10000.00,1000000000.00,1,12,5,6.000,1.000,'percentage',15.00,4,0,'Active','2026-08-29 06:41:23','2026-08-29 06:41:23'),(16,NULL,'DL Monthly','MONTHL','{BRANCH}-{CODE}-{YEAR:2}-{SEQ:6}',1,'SME','monthly','all_days','flat',6.000,'monthly',10000.00,200000000.00,1,12,5,8.000,1.000,'percentage',15.00,4,0,'Active','2026-10-10 14:28:10','2026-10-10 14:28:10');
/*!40000 ALTER TABLE `loan_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `manual_journal_entries`
--

DROP TABLE IF EXISTS `manual_journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `manual_journal_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `status` enum('PendingApproval','Approved','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PendingApproval',
  `requested_by` bigint unsigned NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `reject_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `manual_journal_entries_code_unique` (`code`),
  KEY `manual_journal_entries_status_entry_date_index` (`status`,`entry_date`),
  KEY `manual_journal_entries_branch_id_foreign` (`branch_id`),
  KEY `manual_journal_entries_requested_by_foreign` (`requested_by`),
  KEY `manual_journal_entries_journal_entry_id_foreign` (`journal_entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `manual_journal_entries`
--

LOCK TABLES `manual_journal_entries` WRITE;
/*!40000 ALTER TABLE `manual_journal_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `manual_journal_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `manual_journal_entry_lines`
--

DROP TABLE IF EXISTS `manual_journal_entry_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `manual_journal_entry_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `manual_journal_entry_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `credit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `manual_journal_entry_lines_manual_journal_entry_id_foreign` (`manual_journal_entry_id`),
  KEY `manual_journal_entry_lines_account_id_foreign` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `manual_journal_entry_lines`
--

LOCK TABLES `manual_journal_entry_lines` WRITE;
/*!40000 ALTER TABLE `manual_journal_entry_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `manual_journal_entry_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=238 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2014_10_12_000000_create_users_table',5),(2,'2014_10_12_100000_create_password_reset_tokens_table',5),(3,'2019_08_19_000000_create_failed_jobs_table',5),(4,'2019_12_14_000001_create_personal_access_tokens_table',5),(5,'2026_07_21_000001_create_roles_table',5),(6,'2026_07_21_000002_create_branches_table',5),(7,'2026_07_21_000003_create_permissions_table',5),(8,'2026_07_21_000004_create_role_permissions_table',5),(9,'2026_07_21_000005_finalize_users_table',5),(10,'2026_07_21_000006_create_user_permission_overrides_table',5),(11,'2026_07_23_000001_create_loan_types_table',5),(12,'2026_07_24_000001_create_loan_applications_tables',5),(13,'2026_07_25_000001_create_loan_schedule_and_payments_tables',5),(14,'2026_07_26_000001_add_status_flags_to_loans_table',5),(15,'2026_07_26_000002_create_loan_charges_table',5),(16,'2026_07_26_000003_create_loan_post_dated_cheques_table',5),(17,'2026_07_26_000004_create_loan_documents_table',5),(18,'2026_07_26_000005_create_loan_diary_notes_table',5),(19,'2026_07_26_000006_create_loan_waivers_table',5),(20,'2026_07_26_000007_add_void_fields_to_loan_payments_table',5),(21,'2026_07_26_000012_add_settlement_approval_to_loan_payments_table',5),(22,'2026_07_26_000013_add_cheque_details_to_loan_payments_table',5),(23,'2026_07_27_101021_add_clearance_status_to_loan_payments_table',5),(24,'2026_07_28_101301_create_loan_charge_allocations_table',5),(25,'2026_07_28_102001_create_funding_products_table',5),(31,'2026_07_28_102008_create_number_sequences_table',5),(32,'2026_07_28_102009_create_document_number_log_table',5),(33,'2026_07_29_000001_add_realized_at_to_cheque_tables',5),(34,'2026_07_30_000003_create_banks_table',5),(35,'2026_08_01_000002_create_sales_executive_commissions_table',5),(36,'2026_08_01_000003_add_reference_person_to_loans_table',5),(37,'2026_08_01_000006_create_chart_of_accounts_table',5),(38,'2026_08_01_000007_create_journal_entries_and_journal_lines_tables',5),(39,'2026_08_01_000008_convert_legacy_myisam_tables_to_innodb',5),(40,'2026_08_01_000012_seed_chart_of_accounts',5),(41,'2026_08_01_000013_add_unapplied_loan_receipts_account',5),(42,'2026_08_01_000014_make_sales_executive_commissions_recurring',5),(43,'2026_08_01_000016_add_application_date_to_loans_table',5),(44,'2026_08_01_102001_create_bank_accounts_table',5),(45,'2026_08_01_102002_create_bank_transactions_table',5),(46,'2026_08_01_102003_create_bank_reconciliations_table',5),(47,'2026_08_01_102004_create_cash_accounts_table',5),(48,'2026_08_01_102005_create_cashbook_entries_table',5),(49,'2026_08_01_102006_create_cash_transfers_table',5),(50,'2026_08_01_102007_create_cash_reconciliations_table',5),(51,'2026_08_01_103001_create_vendors_table',5),(52,'2026_08_01_103002_create_expense_categories_table',5),(53,'2026_08_01_103003_create_expenses_table',5),(54,'2026_08_01_103004_create_payment_vouchers_table',5),(55,'2026_08_03_000001_create_speed_draft_types_table',5),(56,'2026_08_03_000002_create_speed_draft_applications_tables',5),(57,'2026_08_03_000003_create_speed_draft_schedule_and_payments_tables',5),(58,'2026_08_03_000004_add_speed_draft_accounts_to_chart_of_accounts',5),(59,'2026_08_03_000005_add_vehicle_details_to_loan_collateral_table',5),(60,'2026_08_03_000007_drop_dead_penalty_and_document_path_columns',5),(61,'2026_08_03_000008_add_opening_balance_equity_account',5),(62,'2026_08_03_000009_create_speed_draft_collateral_documents_table',5),(63,'2026_07_22_000001_create_customers_table',6),(64,'2026_07_22_000002_create_customer_employment_details_table',6),(65,'2026_07_22_000003_create_customer_business_details_table',6),(66,'2026_07_22_000004_create_customer_documents_table',6),(67,'2026_08_03_000010_create_credit_facilities_table',7),(68,'2026_08_03_000011_create_loan_facility_details_table',7),(69,'2026_08_03_000012_create_speed_draft_facility_details_table',7),(70,'2026_08_03_000013_create_facility_status_events_table',7),(71,'2026_08_03_000014_create_facility_schedules_table',7),(72,'2026_08_03_000015_create_facility_payments_table',7),(73,'2026_08_03_000016_create_facility_payment_allocations_table',7),(74,'2026_08_03_000017_create_facility_charges_table',7),(75,'2026_08_03_000018_create_facility_charge_allocations_table',7),(76,'2026_08_03_000019_create_facility_collateral_table',7),(77,'2026_08_03_000020_create_facility_collateral_vehicle_details_table',8),(78,'2026_08_03_000021_create_facility_collateral_jewelry_details_table',8),(79,'2026_08_03_000022_create_facility_collateral_documents_table',8),(80,'2026_08_03_000023_create_facility_documents_table',8),(81,'2026_08_03_000024_create_facility_diary_notes_table',8),(82,'2026_08_03_000025_create_facility_guarantors_table',9),(83,'2026_08_03_000026_create_facility_post_dated_cheques_table',9),(84,'2026_08_03_000027_create_facility_waivers_table',9),(85,'2026_08_03_000028_create_sales_executive_commissions_table',9),(86,'2026_08_03_000029_update_document_number_log_source_type_enum',10),(87,'2026_08_04_000001_create_fixed_deposits_table',11),(88,'2026_08_04_000002_create_fd_deposits_table',11),(89,'2026_08_04_000003_create_fd_interest_payments_table',11),(90,'2026_08_04_000004_create_fd_renewals_table',11),(91,'2026_08_04_000005_create_fd_withdrawals_table',11),(92,'2026_08_04_000006_add_fd_interest_payment_to_cashbook_source_type',11),(93,'2026_08_04_000007_add_category_to_loan_types_table',12),(94,'2026_08_04_000008_add_vehicle_no_to_loan_facility_details',13),(96,'2026_08_04_000009_create_facility_insurance_details_table',14),(97,'2026_08_04_000010_add_vehicle_no_to_speed_draft_facility_details',14),(98,'2026_08_05_164607_create_document_categories_table',15),(99,'2026_08_05_164615_update_facility_documents_for_categories',15),(100,'2026_08_05_170921_create_charge_categories_table',15),(101,'2026_08_05_170946_update_facility_charges_for_categories',15),(102,'2026_08_07_000001_add_allow_method_choice_to_funding_products',16),(103,'2026_08_07_000002_add_effective_rates_to_loan_facility_details',17),(104,'2026_08_12_000001_add_void_fields_to_payment_vouchers_table',18),(105,'2026_08_12_000002_add_petty_cash_control_account',18),(106,'2026_08_12_000003_create_petty_cash_funds_table',18),(107,'2026_08_12_000004_create_petty_cash_replenishments_table',18),(108,'2026_08_12_000005_create_petty_cash_vouchers_table',18),(109,'2026_08_12_000006_update_document_number_log_source_type_for_petty_cash',18),(110,'2026_08_12_000007_add_petty_cash_to_journal_entries_source_type',18),(111,'2026_08_14_000001_add_cancelled_status_to_credit_facilities',19),(112,'2026_08_18_000001_create_approval_types_table',20),(113,'2026_08_18_000002_create_approval_type_approvers_table',20),(114,'2026_08_18_000003_create_approval_requests_table',20),(115,'2026_08_18_000004_add_chart_of_account_id_to_charge_categories',21),(116,'2026_08_18_000005_add_accounts_to_sales_executive_commissions',21),(117,'2026_08_18_000006_add_contra_account_to_bank_transactions',21),(118,'2026_08_19_000001_add_manual_allocation_to_facility_payments_table',21),(119,'2026_08_19_000002_seed_payment_manual_allocation_approval_type',21),(120,'2026_08_19_000001_add_manual_allocation_to_facility_payments_table',41),(121,'2026_08_19_000002_seed_payment_manual_allocation_approval_type',41),(122,'2026_08_20_000001_add_pl_waterfall_accounts_to_chart_of_accounts',42),(123,'2026_08_20_000002_add_pawning_to_credit_facilities_facility_type',42),(124,'2026_08_20_000003_create_pawning_types_table',42),(125,'2026_08_20_000004_create_pawning_article_types_table',42),(126,'2026_08_20_000005_create_pawning_gold_rates_table',42),(127,'2026_08_20_000006_create_pawning_facility_details_table',42),(128,'2026_08_20_000007_add_pawned_jewelry_to_facility_collateral_type',42),(129,'2026_08_20_000008_create_facility_collateral_pawn_details_table',42),(130,'2026_08_20_000009_create_pawning_renewals_table',42),(131,'2026_08_20_000010_create_pawning_redemptions_table',42),(132,'2026_08_20_000011_create_pawning_redemption_items_table',42),(133,'2026_08_20_000012_create_pawning_auctions_table',42),(134,'2026_08_20_000013_create_pawning_auction_items_table',42),(135,'2026_08_20_000014_add_pawning_to_document_number_log_source_type',42),(136,'2026_08_20_000015_add_pawning_accounts_to_chart_of_accounts',42),(137,'2026_08_20_000016_add_pawning_to_journal_entries_source_type',42),(138,'2026_08_20_000017_add_pawning_auction_to_document_number_log_source_type',42),(139,'2026_08_20_000018_add_pawning_auction_accounting',42),(140,'2026_08_20_000018_add_report_performance_indexes',42),(141,'2026_08_20_000019_add_more_report_performance_indexes',42),(142,'2026_08_20_000019_create_company_profile_table',42),(143,'2026_08_20_000020_add_disbursement_method_to_loan_facility_details',42),(144,'2026_08_20_000021_add_write_off_columns_to_credit_facilities',42),(145,'2026_08_20_000022_add_void_to_cash_transfers',42),(146,'2026_08_22_000001_seed_application_approval_types',43),(147,'2026_08_22_000002_add_premature_withdrawal_rate_to_funding_products',44),(148,'2026_08_22_000003_create_fd_part_payments_table',45),(149,'2026_08_22_000004_add_fd_part_payment_to_source_type_enums',46),(150,'2026_08_30_000001_seed_loan_write_off_approval_type',47),(151,'2026_08_30_000002_seed_payment_settlement_approval_type',47),(152,'2026_08_30_000003_seed_cash_transfer_approval_type',47),(153,'2026_08_30_000004_seed_expense_approval_type',47),(154,'2026_08_30_000010_seed_borrowing_accounts_to_chart_of_accounts',48),(155,'2026_08_30_000011_create_borrowings_table',48),(156,'2026_08_30_000012_create_borrowing_transactions_table',48),(157,'2026_08_30_000013_create_borrowing_repledged_items_table',48),(158,'2026_08_30_000014_add_overdraft_limit_to_bank_accounts',48),(159,'2026_08_30_000015_add_borrowing_to_source_type_enums',48),(160,'2026_08_31_000001_add_term_and_maturity_to_borrowings',49),(161,'2026_08_31_000002_create_borrowing_renewals_table',49),(162,'2026_08_31_000003_add_disbursement_bank_account_to_facility_details',50),(163,'2026_08_31_000004_create_manual_journal_entries_tables',50),(164,'2026_08_31_000005_add_manual_journal_entry_to_document_number_log_enum',50),(165,'2026_09_08_000001_seed_savings_accounts_to_chart_of_accounts',51),(166,'2026_09_08_000002_create_savings_products_table',51),(167,'2026_09_08_000003_create_savings_accounts_table',51),(168,'2026_09_08_000004_create_savings_collection_sheets_table',51),(169,'2026_09_08_000005_create_savings_interest_runs_table',51),(170,'2026_09_08_000006_create_savings_transactions_table',51),(171,'2026_09_08_000007_create_savings_collection_lines_table',51),(172,'2026_09_08_000008_add_savings_to_source_type_enums',51),(173,'2026_09_08_000009_seed_savings_permissions',51),(174,'2026_09_08_000010_seed_savings_approval_types',51),(175,'2026_09_08_000001_seed_savings_accounts_to_chart_of_accounts',52),(176,'2026_09_08_000002_create_savings_products_table',52),(177,'2026_09_08_000003_create_savings_accounts_table',52),(178,'2026_09_08_000004_create_savings_collection_sheets_table',52),(179,'2026_09_08_000005_create_savings_interest_runs_table',52),(180,'2026_09_08_000006_create_savings_transactions_table',52),(181,'2026_09_08_000007_create_savings_collection_lines_table',52),(182,'2026_09_08_000008_add_savings_to_source_type_enums',52),(183,'2026_09_08_000009_seed_savings_permissions',52),(184,'2026_09_08_000010_seed_savings_approval_types',52),(185,'2014_10_12_100000_create_password_resets_table',53),(186,'2026_06_26_000001_add_role_to_users',53),(187,'2026_07_22_000001_create_customer_management_tables',54),(188,'2026_07_26_000009_create_number_sequences_table',54),(189,'2026_07_27_000001_create_bank_accounts_table',54),(190,'2026_07_27_000002_create_bank_transactions_table',54),(191,'2026_07_27_000003_create_bank_reconciliations_table',54),(192,'2026_07_28_102002_create_fixed_deposits_table',54),(193,'2026_07_28_102003_create_fd_deposits_table',54),(194,'2026_07_28_102004_create_fd_interest_payments_table',54),(195,'2026_07_28_102005_create_fd_renewals_table',54),(196,'2026_07_28_102006_create_fd_withdrawals_table',54),(197,'2026_07_31_000001_create_cash_accounts_table',54),(198,'2026_07_31_000002_create_cashbook_entries_table',54),(199,'2026_07_31_000003_create_cash_transfers_table',54),(200,'2026_07_31_000004_create_cash_reconciliations_table',54),(201,'2026_08_01_000010_create_vendors_table',54),(202,'2026_08_01_000011_create_expenses_and_payment_vouchers_tables',54),(203,'2026_08_09_000001_create_facility_co_applicants_table',54),(204,'2026_08_26_000001_create_subscriptions_table',54),(205,'2026_08_27_000003_create_loan_provisions_table',54),(206,'2026_08_29_000003_create_facility_reschedules_table',54),(207,'2026_09_01_000004_create_finance_transactions_table',54),(208,'2026_09_01_000011_create_cash_float_settlements',54),(209,'2026_09_01_000012_create_finance_settings_and_cash_sessions',54),(210,'2026_09_01_000014_create_finance_audit_events',54),(211,'2026_09_02_000002_create_idempotency_keys_table',54),(212,'2026_09_11_000001_drop_legacy_role_column_from_users',55),(213,'2026_10_13_000001_create_fd_loan_policies_table',56),(214,'2026_10_13_000002_add_fixed_deposit_to_facility_collateral_type',56),(215,'2026_10_13_000003_create_facility_collateral_fd_details_table',56),(216,'2026_10_13_000004_add_lien_columns_to_fixed_deposits',56),(217,'2026_10_13_000005_add_fd_adjustment_columns',56),(218,'2026_08_11_000001_add_waived_interest_to_facility_schedules',1),(219,'2026_08_16_000001_add_daily_rate_period',1),(220,'2026_08_21_000001_add_sales_executive_commission_to_journal_entries',1),(221,'2026_10_14_000001_map_document_charge_to_documentation_income',57),(222,'2026_10_14_000002_add_purpose_to_cash_accounts',57),(223,'2026_10_14_000003_add_bank_account_to_fd_and_savings_movements',57),(224,'2026_10_16_000001_enforce_cash_account_purpose',58),(225,'2026_10_15_000001_add_is_postable_and_unique_till_codes',59),(226,'2026_10_15_000002_add_branch_id_to_money_child_tables',60),(227,'2026_10_15_000003_add_inter_branch_accounts',61),(228,'2026_10_15_000004_add_collected_branch_id_to_facility_payments',62),(229,'2026_10_16_000002_remove_disbursement_tills',63),(230,'2026_10_17_000001_add_branch_funding',64),(231,'2026_10_17_000002_add_branch_funding_journal_source_type',65),(232,'2026_10_19_000001_add_premature_closure_to_fd_withdrawals',66),(233,'2026_10_21_000001_create_customer_bank_details',67),(234,'2026_10_17_000003_add_document_charge_amount_to_loan_facility_details',68),(235,'2026_10_18_000002_add_borrowing_interest_accrual',69),(236,'2026_10_19_000002_add_blacklist_details_to_customers',70),(237,'2026_10_18_000001_add_fd_auto_renew_and_post_maturity_interest',70);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `number_sequences`
--

DROP TABLE IF EXISTS `number_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `number_sequences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `module` enum('loan','customer','payment','fd_deposit','fixed_deposit','fd_withdrawal','fd_part_payment','journal','finance_transaction','finance_request','manual_journal','expense','payment_voucher','petty_cash_fund','petty_cash_replenishment','petty_cash_voucher','speed_draft','speed_draft_payment','pawning','pawning_auction','borrowing','borrowing_txn','savings_account','savings_txn','savings_collection','savings_interest') COLLATE utf8mb4_unicode_ci NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `number_sequences`
--

LOCK TABLES `number_sequences` WRITE;
/*!40000 ALTER TABLE `number_sequences` DISABLE KEYS */;
INSERT INTO `number_sequences` (`id`, `module`, `branch_id`, `loan_type_id`, `funding_product_id`, `year`, `last_sequence`, `created_at`, `updated_at`) VALUES (1,'loan',NULL,8,NULL,2026,1,'2026-10-14 09:13:49','2026-10-14 09:13:49'),(2,'payment',NULL,NULL,NULL,2026,2,'2026-10-14 09:15:04','2026-10-22 08:30:09'),(3,'journal',NULL,NULL,NULL,2026,6,'2026-10-14 09:15:04','2026-10-22 08:35:19'),(4,'finance_transaction',NULL,NULL,NULL,2026,4,'2026-10-14 09:15:04','2026-10-22 08:32:48'),(8,'loan',NULL,NULL,NULL,2026,2,'2026-10-22 07:54:33','2026-10-22 07:54:33'),(12,'fd_deposit',NULL,NULL,NULL,2026,1,'2026-10-22 08:32:48','2026-10-22 08:32:48'),(15,'expense',NULL,NULL,NULL,2026,1,'2026-10-22 08:34:54','2026-10-22 08:34:54'),(18,'speed_draft',NULL,NULL,NULL,2026,1,'2026-10-22 08:50:46','2026-10-22 08:50:46');
/*!40000 ALTER TABLE `number_sequences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

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

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_resets_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pawning_article_types`
--

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

--
-- Dumping data for table `pawning_article_types`
--

LOCK TABLES `pawning_article_types` WRITE;
/*!40000 ALTER TABLE `pawning_article_types` DISABLE KEYS */;
INSERT INTO `pawning_article_types` VALUES (1,'Chain','CHAIN','Active',1,'2026-08-21 07:48:27','2026-08-21 07:48:27'),(2,'Necklace','NECK','Active',2,'2026-08-21 07:48:27','2026-08-21 07:48:27'),(3,'Ring','RING','Active',3,'2026-08-21 07:48:27','2026-08-21 07:48:27'),(4,'Bangle','BANGLE','Active',4,'2026-08-21 07:48:27','2026-08-21 07:48:27'),(5,'Bracelet','BRACE','Active',5,'2026-08-21 07:48:27','2026-08-21 07:48:27'),(6,'Ear Stud','ESTUD','Active',6,'2026-08-21 07:48:27','2026-08-21 07:48:27'),(7,'Pendant','PEND','Active',7,'2026-08-21 07:48:27','2026-08-21 07:48:27'),(8,'Coin','COIN','Active',8,'2026-08-21 07:48:27','2026-08-21 07:48:27'),(9,'Bar','BAR','Active',9,'2026-08-21 07:48:27','2026-08-21 07:48:27'),(10,'Anklet','ANKLET','Active',10,'2026-08-21 07:48:27','2026-08-21 07:48:27'),(11,'Other','OTHER','Active',11,'2026-08-21 07:48:27','2026-08-21 07:48:27');
/*!40000 ALTER TABLE `pawning_article_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pawning_auction_items`
--

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
  KEY `pawning_auction_items_credit_facility_id_index` (`credit_facility_id`),
  KEY `pawning_auction_items_facility_collateral_id_foreign` (`facility_collateral_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pawning_auction_items`
--

LOCK TABLES `pawning_auction_items` WRITE;
/*!40000 ALTER TABLE `pawning_auction_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `pawning_auction_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pawning_auctions`
--

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
  KEY `pawning_auctions_branch_id_scheduled_date_index` (`branch_id`,`scheduled_date`),
  KEY `pawning_auctions_created_by_foreign` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pawning_auctions`
--

LOCK TABLES `pawning_auctions` WRITE;
/*!40000 ALTER TABLE `pawning_auctions` DISABLE KEYS */;
/*!40000 ALTER TABLE `pawning_auctions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pawning_facility_details`
--

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
  KEY `pawning_facility_details_previous_facility_id_index` (`previous_facility_id`),
  KEY `pawning_facility_details_pawning_type_id_foreign` (`pawning_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pawning_facility_details`
--

LOCK TABLES `pawning_facility_details` WRITE;
/*!40000 ALTER TABLE `pawning_facility_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `pawning_facility_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pawning_gold_rates`
--

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
  KEY `pawning_gold_rates_created_by_foreign` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pawning_gold_rates`
--

LOCK TABLES `pawning_gold_rates` WRITE;
/*!40000 ALTER TABLE `pawning_gold_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `pawning_gold_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pawning_redemption_items`
--

DROP TABLE IF EXISTS `pawning_redemption_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pawning_redemption_items` (
  `pawning_redemption_id` bigint unsigned NOT NULL,
  `facility_collateral_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`pawning_redemption_id`,`facility_collateral_id`),
  KEY `pawning_redemption_items_facility_collateral_id_index` (`facility_collateral_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pawning_redemption_items`
--

LOCK TABLES `pawning_redemption_items` WRITE;
/*!40000 ALTER TABLE `pawning_redemption_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `pawning_redemption_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pawning_redemptions`
--

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
  KEY `pawning_redemptions_credit_facility_id_settled_at_index` (`credit_facility_id`,`settled_at`),
  KEY `pawning_redemptions_facility_payment_id_foreign` (`facility_payment_id`),
  KEY `pawning_redemptions_settled_by_foreign` (`settled_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pawning_redemptions`
--

LOCK TABLES `pawning_redemptions` WRITE;
/*!40000 ALTER TABLE `pawning_redemptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `pawning_redemptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pawning_renewals`
--

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
  KEY `pawning_renewals_renewed_by_foreign` (`renewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pawning_renewals`
--

LOCK TABLES `pawning_renewals` WRITE;
/*!40000 ALTER TABLE `pawning_renewals` DISABLE KEYS */;
/*!40000 ALTER TABLE `pawning_renewals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pawning_types`
--

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
  UNIQUE KEY `pawning_types_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pawning_types`
--

LOCK TABLES `pawning_types` WRITE;
/*!40000 ALTER TABLE `pawning_types` DISABLE KEYS */;
INSERT INTO `pawning_types` VALUES (1,'RAN NAYA','RN',1.980,1.000,10,30,80.00,3,0,90,30,'Active',0,'2026-08-22 08:43:51','2026-08-22 08:43:51');
/*!40000 ALTER TABLE `pawning_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_vouchers`
--

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
  `finance_transaction_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_vouchers_code` (`code`),
  KEY `ix_payment_vouchers_expense` (`expense_id`),
  KEY `ix_payment_vouchers_vendor` (`vendor_id`),
  KEY `ix_payment_vouchers_paid_by` (`paid_by`),
  KEY `ix_payment_vouchers_cash_account` (`cash_account_id`),
  KEY `ix_payment_vouchers_bank_account` (`bank_account_id`),
  KEY `ix_payment_vouchers_journal_entry` (`journal_entry_id`),
  KEY `ix_payment_vouchers_paid_at` (`paid_at`),
  KEY `payment_vouchers_voided_by_foreign` (`voided_by`),
  KEY `fk_pv_finance_txn` (`finance_transaction_id`),
  KEY `payment_vouchers_branch_id_index` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_vouchers`
--

LOCK TABLES `payment_vouchers` WRITE;
/*!40000 ALTER TABLE `payment_vouchers` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_vouchers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=310 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'Dashboard','View','2026-08-03 11:44:08'),(2,'Dashboard','Create','2026-08-03 11:44:08'),(3,'Dashboard','Edit','2026-08-03 11:44:08'),(4,'Dashboard','Delete','2026-08-03 11:44:08'),(5,'Dashboard','Approve','2026-08-03 11:44:08'),(6,'Dashboard','Export','2026-08-03 11:44:08'),(7,'Branch Management','View','2026-08-03 11:44:08'),(8,'Branch Management','Create','2026-08-03 11:44:08'),(9,'Branch Management','Edit','2026-08-03 11:44:08'),(10,'Branch Management','Delete','2026-08-03 11:44:08'),(11,'Branch Management','Approve','2026-08-03 11:44:08'),(12,'Branch Management','Export','2026-08-03 11:44:08'),(13,'Role Management','View','2026-08-03 11:44:08'),(14,'Role Management','Create','2026-08-03 11:44:08'),(15,'Role Management','Edit','2026-08-03 11:44:08'),(16,'Role Management','Delete','2026-08-03 11:44:08'),(17,'Role Management','Approve','2026-08-03 11:44:08'),(18,'Role Management','Export','2026-08-03 11:44:08'),(19,'User Management','View','2026-08-03 11:44:08'),(20,'User Management','Create','2026-08-03 11:44:08'),(21,'User Management','Edit','2026-08-03 11:44:08'),(22,'User Management','Delete','2026-08-03 11:44:08'),(23,'User Management','Approve','2026-08-03 11:44:08'),(24,'User Management','Export','2026-08-03 11:44:08'),(25,'User Permission','View','2026-08-03 11:44:08'),(26,'User Permission','Create','2026-08-03 11:44:08'),(27,'User Permission','Edit','2026-08-03 11:44:08'),(28,'User Permission','Delete','2026-08-03 11:44:08'),(29,'User Permission','Approve','2026-08-03 11:44:08'),(30,'User Permission','Export','2026-08-03 11:44:08'),(31,'Company Profile','View','2026-08-03 11:44:08'),(32,'Company Profile','Create','2026-08-03 11:44:08'),(33,'Company Profile','Edit','2026-08-03 11:44:08'),(34,'Company Profile','Delete','2026-08-03 11:44:08'),(35,'Company Profile','Approve','2026-08-03 11:44:08'),(36,'Company Profile','Export','2026-08-03 11:44:08'),(37,'Customer Management','View','2026-08-03 11:44:08'),(38,'Customer Management','Create','2026-08-03 11:44:08'),(39,'Customer Management','Edit','2026-08-03 11:44:08'),(40,'Customer Management','Delete','2026-08-03 11:44:08'),(41,'Customer Management','Approve','2026-08-03 11:44:08'),(42,'Customer Management','Export','2026-08-03 11:44:08'),(43,'Loan Types','View','2026-08-03 11:44:08'),(44,'Loan Types','Create','2026-08-03 11:44:08'),(45,'Loan Types','Edit','2026-08-03 11:44:08'),(46,'Loan Types','Delete','2026-08-03 11:44:08'),(47,'Loan Types','Approve','2026-08-03 11:44:08'),(48,'Loan Types','Export','2026-08-03 11:44:08'),(49,'Loan Applications','View','2026-08-03 11:44:08'),(50,'Loan Applications','Create','2026-08-03 11:44:08'),(51,'Loan Applications','Edit','2026-08-03 11:44:08'),(52,'Loan Applications','Delete','2026-08-03 11:44:08'),(53,'Loan Applications','Approve','2026-08-03 11:44:08'),(54,'Loan Applications','Export','2026-08-03 11:44:08'),(55,'Active Loans','View','2026-08-03 11:44:08'),(56,'Active Loans','Create','2026-08-03 11:44:08'),(57,'Active Loans','Edit','2026-08-03 11:44:08'),(58,'Active Loans','Delete','2026-08-03 11:44:08'),(59,'Active Loans','Approve','2026-08-03 11:44:08'),(60,'Active Loans','Export','2026-08-03 11:44:08'),(61,'Loan Payments','View','2026-08-03 11:44:08'),(62,'Loan Payments','Create','2026-08-03 11:44:08'),(63,'Loan Payments','Edit','2026-08-03 11:44:08'),(64,'Loan Payments','Delete','2026-08-03 11:44:08'),(65,'Loan Payments','Approve','2026-08-03 11:44:08'),(66,'Loan Payments','Export','2026-08-03 11:44:08'),(67,'Sales Executive Commissions','View','2026-08-03 11:44:08'),(68,'Sales Executive Commissions','Create','2026-08-03 11:44:08'),(69,'Sales Executive Commissions','Edit','2026-08-03 11:44:08'),(70,'Sales Executive Commissions','Delete','2026-08-03 11:44:08'),(71,'Sales Executive Commissions','Approve','2026-08-03 11:44:08'),(72,'Sales Executive Commissions','Export','2026-08-03 11:44:08'),(73,'Overdue Loans','View','2026-08-03 11:44:08'),(74,'Overdue Loans','Create','2026-08-03 11:44:08'),(75,'Overdue Loans','Edit','2026-08-03 11:44:08'),(76,'Overdue Loans','Delete','2026-08-03 11:44:08'),(77,'Overdue Loans','Approve','2026-08-03 11:44:08'),(78,'Overdue Loans','Export','2026-08-03 11:44:08'),(79,'Document Tracking','View','2026-08-03 11:44:08'),(80,'Document Tracking','Create','2026-08-03 11:44:08'),(81,'Document Tracking','Edit','2026-08-03 11:44:08'),(82,'Document Tracking','Delete','2026-08-03 11:44:08'),(83,'Document Tracking','Approve','2026-08-03 11:44:08'),(84,'Document Tracking','Export','2026-08-03 11:44:08'),(85,'Speed Draft Types','View','2026-08-03 11:44:08'),(86,'Speed Draft Types','Create','2026-08-03 11:44:08'),(87,'Speed Draft Types','Edit','2026-08-03 11:44:08'),(88,'Speed Draft Types','Delete','2026-08-03 11:44:08'),(89,'Speed Draft Types','Approve','2026-08-03 11:44:08'),(90,'Speed Draft Types','Export','2026-08-03 11:44:08'),(91,'Speed Drafts','View','2026-08-03 11:44:08'),(92,'Speed Drafts','Create','2026-08-03 11:44:08'),(93,'Speed Drafts','Edit','2026-08-03 11:44:08'),(94,'Speed Drafts','Delete','2026-08-03 11:44:08'),(95,'Speed Drafts','Approve','2026-08-03 11:44:08'),(96,'Speed Drafts','Export','2026-08-03 11:44:08'),(97,'Speed Draft Payments','View','2026-08-03 11:44:08'),(98,'Speed Draft Payments','Create','2026-08-03 11:44:08'),(99,'Speed Draft Payments','Edit','2026-08-03 11:44:08'),(100,'Speed Draft Payments','Delete','2026-08-03 11:44:08'),(101,'Speed Draft Payments','Approve','2026-08-03 11:44:08'),(102,'Speed Draft Payments','Export','2026-08-03 11:44:08'),(103,'Cashbook','View','2026-08-03 11:44:08'),(104,'Cashbook','Create','2026-08-03 11:44:08'),(105,'Cashbook','Edit','2026-08-03 11:44:08'),(106,'Cashbook','Delete','2026-08-03 11:44:08'),(107,'Cashbook','Approve','2026-08-03 11:44:08'),(108,'Cashbook','Export','2026-08-03 11:44:08'),(115,'Cash Reconciliation','View','2026-08-03 11:44:08'),(116,'Cash Reconciliation','Create','2026-08-03 11:44:08'),(117,'Cash Reconciliation','Edit','2026-08-03 11:44:08'),(118,'Cash Reconciliation','Delete','2026-08-03 11:44:08'),(119,'Cash Reconciliation','Approve','2026-08-03 11:44:08'),(120,'Cash Reconciliation','Export','2026-08-03 11:44:08'),(121,'Bank Accounts','View','2026-08-03 11:44:08'),(122,'Bank Accounts','Create','2026-08-03 11:44:08'),(123,'Bank Accounts','Edit','2026-08-03 11:44:08'),(124,'Bank Accounts','Delete','2026-08-03 11:44:08'),(125,'Bank Accounts','Approve','2026-08-03 11:44:08'),(126,'Bank Accounts','Export','2026-08-03 11:44:08'),(127,'Bank Transactions','View','2026-08-03 11:44:08'),(128,'Bank Transactions','Create','2026-08-03 11:44:08'),(129,'Bank Transactions','Edit','2026-08-03 11:44:08'),(130,'Bank Transactions','Delete','2026-08-03 11:44:08'),(131,'Bank Transactions','Approve','2026-08-03 11:44:08'),(132,'Bank Transactions','Export','2026-08-03 11:44:08'),(133,'Bank Reconciliation','View','2026-08-03 11:44:08'),(134,'Bank Reconciliation','Create','2026-08-03 11:44:08'),(135,'Bank Reconciliation','Edit','2026-08-03 11:44:08'),(136,'Bank Reconciliation','Delete','2026-08-03 11:44:08'),(137,'Bank Reconciliation','Approve','2026-08-03 11:44:08'),(138,'Bank Reconciliation','Export','2026-08-03 11:44:08'),(139,'Masters','View','2026-08-03 11:44:08'),(140,'Masters','Create','2026-08-03 11:44:08'),(141,'Masters','Edit','2026-08-03 11:44:08'),(142,'Masters','Delete','2026-08-03 11:44:08'),(143,'Masters','Approve','2026-08-03 11:44:08'),(144,'Masters','Export','2026-08-03 11:44:08'),(145,'Funding Products','View','2026-08-03 11:44:08'),(146,'Funding Products','Create','2026-08-03 11:44:08'),(147,'Funding Products','Edit','2026-08-03 11:44:08'),(148,'Funding Products','Delete','2026-08-03 11:44:08'),(149,'Funding Products','Approve','2026-08-03 11:44:08'),(150,'Funding Products','Export','2026-08-03 11:44:08'),(151,'Deposits','View','2026-08-03 11:44:08'),(152,'Deposits','Create','2026-08-03 11:44:08'),(153,'Deposits','Edit','2026-08-03 11:44:08'),(154,'Deposits','Delete','2026-08-03 11:44:08'),(155,'Deposits','Approve','2026-08-03 11:44:08'),(156,'Deposits','Export','2026-08-03 11:44:08'),(157,'Fixed Deposits','View','2026-08-03 11:44:08'),(158,'Fixed Deposits','Create','2026-08-03 11:44:08'),(159,'Fixed Deposits','Edit','2026-08-03 11:44:08'),(160,'Fixed Deposits','Delete','2026-08-03 11:44:08'),(161,'Fixed Deposits','Approve','2026-08-03 11:44:08'),(162,'Fixed Deposits','Export','2026-08-03 11:44:08'),(163,'Funding Reports','View','2026-08-03 11:44:08'),(164,'Funding Reports','Create','2026-08-03 11:44:08'),(165,'Funding Reports','Edit','2026-08-03 11:44:08'),(166,'Funding Reports','Delete','2026-08-03 11:44:08'),(167,'Funding Reports','Approve','2026-08-03 11:44:08'),(168,'Funding Reports','Export','2026-08-03 11:44:08'),(169,'Chart of Accounts','View','2026-08-03 11:44:08'),(170,'Chart of Accounts','Create','2026-08-03 11:44:08'),(171,'Chart of Accounts','Edit','2026-08-03 11:44:08'),(172,'Chart of Accounts','Delete','2026-08-03 11:44:08'),(173,'Chart of Accounts','Approve','2026-08-03 11:44:08'),(174,'Chart of Accounts','Export','2026-08-03 11:44:08'),(175,'Accounting Reports','View','2026-08-03 11:44:08'),(176,'Accounting Reports','Create','2026-08-03 11:44:08'),(177,'Accounting Reports','Edit','2026-08-03 11:44:08'),(178,'Accounting Reports','Delete','2026-08-03 11:44:08'),(179,'Accounting Reports','Approve','2026-08-03 11:44:08'),(180,'Accounting Reports','Export','2026-08-03 11:44:08'),(181,'Vendors','View','2026-08-03 11:44:08'),(182,'Vendors','Create','2026-08-03 11:44:08'),(183,'Vendors','Edit','2026-08-03 11:44:08'),(184,'Vendors','Delete','2026-08-03 11:44:08'),(185,'Vendors','Approve','2026-08-03 11:44:08'),(186,'Vendors','Export','2026-08-03 11:44:08'),(187,'Expenses','View','2026-08-03 11:44:08'),(188,'Expenses','Create','2026-08-03 11:44:08'),(189,'Expenses','Edit','2026-08-03 11:44:08'),(190,'Expenses','Delete','2026-08-03 11:44:08'),(191,'Expenses','Approve','2026-08-03 11:44:08'),(192,'Expenses','Export','2026-08-03 11:44:08'),(193,'Loan Calculator','View','2026-08-07 09:45:06'),(194,'Loan Calculator','Create','2026-08-07 09:45:06'),(195,'Loan Calculator','Edit','2026-08-07 09:45:06'),(196,'Loan Calculator','Delete','2026-08-07 09:45:06'),(197,'Loan Calculator','Approve','2026-08-07 09:45:06'),(198,'Loan Calculator','Export','2026-08-07 09:45:06'),(199,'Loan Reports','View','2026-08-20 22:21:49'),(200,'Loan Reports','Create','2026-08-20 22:21:49'),(201,'Loan Reports','Edit','2026-08-20 22:21:49'),(202,'Loan Reports','Delete','2026-08-20 22:21:49'),(203,'Loan Reports','Approve','2026-08-20 22:21:49'),(204,'Loan Reports','Export','2026-08-20 22:21:49'),(205,'Collection Reports','View','2026-08-20 22:21:49'),(206,'Collection Reports','Create','2026-08-20 22:21:49'),(207,'Collection Reports','Edit','2026-08-20 22:21:49'),(208,'Collection Reports','Delete','2026-08-20 22:21:49'),(209,'Collection Reports','Approve','2026-08-20 22:21:49'),(210,'Collection Reports','Export','2026-08-20 22:21:49'),(211,'Customer Reports','View','2026-08-20 22:21:49'),(212,'Customer Reports','Create','2026-08-20 22:21:49'),(213,'Customer Reports','Edit','2026-08-20 22:21:49'),(214,'Customer Reports','Delete','2026-08-20 22:21:49'),(215,'Customer Reports','Approve','2026-08-20 22:21:49'),(216,'Customer Reports','Export','2026-08-20 22:21:49'),(217,'Investor Reports','View','2026-08-20 22:21:49'),(218,'Investor Reports','Create','2026-08-20 22:21:49'),(219,'Investor Reports','Edit','2026-08-20 22:21:49'),(220,'Investor Reports','Delete','2026-08-20 22:21:49'),(221,'Investor Reports','Approve','2026-08-20 22:21:49'),(222,'Investor Reports','Export','2026-08-20 22:21:49'),(223,'Cash & Bank Reports','View','2026-08-20 22:21:49'),(224,'Cash & Bank Reports','Create','2026-08-20 22:21:49'),(225,'Cash & Bank Reports','Edit','2026-08-20 22:21:49'),(226,'Cash & Bank Reports','Delete','2026-08-20 22:21:49'),(227,'Cash & Bank Reports','Approve','2026-08-20 22:21:49'),(228,'Cash & Bank Reports','Export','2026-08-20 22:21:49'),(229,'Expense Reports','View','2026-08-20 22:21:49'),(230,'Expense Reports','Create','2026-08-20 22:21:49'),(231,'Expense Reports','Edit','2026-08-20 22:21:49'),(232,'Expense Reports','Delete','2026-08-20 22:21:49'),(233,'Expense Reports','Approve','2026-08-20 22:21:49'),(234,'Expense Reports','Export','2026-08-20 22:21:49'),(235,'Payment Vouchers','View','2026-08-20 22:21:49'),(236,'Payment Vouchers','Create','2026-08-20 22:21:49'),(237,'Payment Vouchers','Edit','2026-08-20 22:21:49'),(238,'Payment Vouchers','Delete','2026-08-20 22:21:49'),(239,'Payment Vouchers','Approve','2026-08-20 22:21:49'),(240,'Payment Vouchers','Export','2026-08-20 22:21:49'),(241,'Petty Cash','View','2026-08-20 22:21:49'),(242,'Petty Cash','Create','2026-08-20 22:21:49'),(243,'Petty Cash','Edit','2026-08-20 22:21:49'),(244,'Petty Cash','Delete','2026-08-20 22:21:49'),(245,'Petty Cash','Approve','2026-08-20 22:21:49'),(246,'Petty Cash','Export','2026-08-20 22:21:49'),(247,'Pawning Types','View','2026-08-24 21:46:04'),(248,'Pawning Types','Create','2026-08-24 21:46:04'),(249,'Pawning Types','Edit','2026-08-24 21:46:04'),(250,'Pawning Types','Delete','2026-08-24 21:46:04'),(251,'Pawning Types','Approve','2026-08-24 21:46:04'),(252,'Pawning Types','Export','2026-08-24 21:46:04'),(253,'Pawnings','View','2026-08-24 21:46:04'),(254,'Pawnings','Create','2026-08-24 21:46:04'),(255,'Pawnings','Edit','2026-08-24 21:46:04'),(256,'Pawnings','Delete','2026-08-24 21:46:04'),(257,'Pawnings','Approve','2026-08-24 21:46:04'),(258,'Pawnings','Export','2026-08-24 21:46:04'),(259,'Pawning Payments','View','2026-08-24 21:46:04'),(260,'Pawning Payments','Create','2026-08-24 21:46:04'),(261,'Pawning Payments','Edit','2026-08-24 21:46:04'),(262,'Pawning Payments','Delete','2026-08-24 21:46:04'),(263,'Pawning Payments','Approve','2026-08-24 21:46:04'),(264,'Pawning Payments','Export','2026-08-24 21:46:04'),(265,'Pawning Auctions','View','2026-08-24 21:46:04'),(266,'Pawning Auctions','Create','2026-08-24 21:46:04'),(267,'Pawning Auctions','Edit','2026-08-24 21:46:04'),(268,'Pawning Auctions','Delete','2026-08-24 21:46:04'),(269,'Pawning Auctions','Approve','2026-08-24 21:46:04'),(270,'Pawning Auctions','Export','2026-08-24 21:46:04'),(271,'Cash Transactions','View',NULL),(272,'Cash Transactions','Create',NULL),(273,'Cash Transactions','Edit',NULL),(274,'Cash Transactions','Delete',NULL),(275,'Cash Transactions','Approve',NULL),(276,'Cash Transactions','Export',NULL),(277,'Finance Audit','View',NULL),(278,'Finance Audit','Export',NULL),(279,'Savings Products','View','2026-09-08 18:32:39'),(280,'Savings Accounts','View','2026-09-08 18:32:39'),(281,'Savings Collection','View','2026-09-08 18:32:39'),(282,'Savings Interest','View','2026-09-08 18:32:39'),(283,'Savings Reports','View','2026-09-08 18:32:39'),(284,'Savings Products','Create','2026-09-08 18:32:39'),(285,'Savings Accounts','Create','2026-09-08 18:32:39'),(286,'Savings Collection','Create','2026-09-08 18:32:39'),(287,'Savings Interest','Create','2026-09-08 18:32:39'),(288,'Savings Reports','Create','2026-09-08 18:32:39'),(289,'Savings Products','Edit','2026-09-08 18:32:39'),(290,'Savings Accounts','Edit','2026-09-08 18:32:39'),(291,'Savings Collection','Edit','2026-09-08 18:32:39'),(292,'Savings Interest','Edit','2026-09-08 18:32:39'),(293,'Savings Reports','Edit','2026-09-08 18:32:39'),(294,'Savings Products','Delete','2026-09-08 18:32:39'),(295,'Savings Accounts','Delete','2026-09-08 18:32:39'),(296,'Savings Collection','Delete','2026-09-08 18:32:39'),(297,'Savings Interest','Delete','2026-09-08 18:32:39'),(298,'Savings Reports','Delete','2026-09-08 18:32:39'),(299,'Savings Products','Approve','2026-09-08 18:32:39'),(300,'Savings Accounts','Approve','2026-09-08 18:32:39'),(301,'Savings Collection','Approve','2026-09-08 18:32:39'),(302,'Savings Interest','Approve','2026-09-08 18:32:39'),(303,'Savings Reports','Approve','2026-09-08 18:32:39'),(304,'Savings Products','Export','2026-09-08 18:32:39'),(305,'Savings Accounts','Export','2026-09-08 18:32:39'),(306,'Savings Collection','Export','2026-09-08 18:32:39'),(307,'Savings Interest','Export','2026-09-08 18:32:39'),(308,'Savings Reports','Export','2026-09-08 18:32:39');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
INSERT INTO `personal_access_tokens` VALUES (1,'App\\Models\\User',1,'mms-web','e6a3c0f35dc987835f575629600a61c3dc0fd3963c892afa1cf489a150fdd018','[\"*\"]','2026-10-14 09:49:35',NULL,'2026-10-14 08:12:44','2026-10-14 09:49:35'),(3,'App\\Models\\User',1,'mms-web','858ab75cda27daadab18ea93832fe5c36411876e2b3198de79e12ed328b32d93','[\"*\"]','2026-10-15 10:56:54',NULL,'2026-10-15 10:48:24','2026-10-15 10:56:54'),(4,'App\\Models\\User',1,'mms-web','5413275470e7d734cf80b47afe16ad58047efa0f743aec835421eac226f7cdef','[\"*\"]','2026-10-16 01:04:03',NULL,'2026-10-16 01:01:31','2026-10-16 01:04:03'),(5,'App\\Models\\User',1,'mms-web','1e4c1040879bc0e118563cd0bd42c9d4b8198785ac04627a25116bdae11c034d','[\"*\"]','2026-10-17 12:55:31',NULL,'2026-10-17 12:26:58','2026-10-17 12:55:31'),(6,'App\\Models\\User',1,'mms-web','b2e253ba8e4c6eae69919382194dda7bfd60799a66181dfd4475899af8d617c5','[\"*\"]','2026-10-18 02:37:24',NULL,'2026-10-18 02:37:23','2026-10-18 02:37:24'),(7,'App\\Models\\User',1,'mms-web','cc8a78b7c824138430f6403b8edf490b015a57c7f06aad1cfc4d3301e8765429','[\"*\"]',NULL,NULL,'2026-10-21 12:54:14','2026-10-21 12:54:14'),(8,'App\\Models\\User',1,'mms-web','07f57797e4cb02572c64f17032f6820d8464d0b95a0327479aeb007126c68d32','[\"*\"]','2026-10-21 12:55:26',NULL,'2026-10-21 12:54:23','2026-10-21 12:55:26'),(9,'App\\Models\\User',1,'mms-web','b801fb635e6206c6b4cac84fd3e5363165c6bfb87cafff008f9c8f743eed16e8','[\"*\"]','2026-10-22 08:50:47',NULL,'2026-10-22 07:50:03','2026-10-22 08:50:47'),(11,'App\\Models\\User',12,'mms-web','873d3082c2b5865ad26ce49a9d67032b731cfcb87488a241233e9ecc841abae0','[\"*\"]','2026-10-22 09:42:20',NULL,'2026-10-22 08:00:56','2026-10-22 09:42:20');
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `petty_cash_funds`
--

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
  KEY `petty_cash_funds_branch_id_status_index` (`branch_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_funds`
--

LOCK TABLES `petty_cash_funds` WRITE;
/*!40000 ALTER TABLE `petty_cash_funds` DISABLE KEYS */;
/*!40000 ALTER TABLE `petty_cash_funds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `petty_cash_replenishments`
--

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
  KEY `petty_cash_replenishments_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_replenishments`
--

LOCK TABLES `petty_cash_replenishments` WRITE;
/*!40000 ALTER TABLE `petty_cash_replenishments` DISABLE KEYS */;
/*!40000 ALTER TABLE `petty_cash_replenishments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `petty_cash_vouchers`
--

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
  KEY `petty_cash_vouchers_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_vouchers`
--

LOCK TABLES `petty_cash_vouchers` WRITE;
/*!40000 ALTER TABLE `petty_cash_vouchers` DISABLE KEYS */;
/*!40000 ALTER TABLE `petty_cash_vouchers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=276 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (120,3,1,'2026-08-03 23:07:54'),(121,3,37,'2026-08-03 23:07:54'),(122,3,38,'2026-08-03 23:07:54'),(123,3,39,'2026-08-03 23:07:54'),(124,3,43,'2026-08-03 23:07:54'),(125,3,49,'2026-08-03 23:07:54'),(126,3,50,'2026-08-03 23:07:54'),(127,3,51,'2026-08-03 23:07:54'),(128,3,55,'2026-08-03 23:07:54'),(129,3,61,'2026-08-03 23:07:54'),(130,3,62,'2026-08-03 23:07:54'),(131,3,63,'2026-08-03 23:07:54'),(132,3,73,'2026-08-03 23:07:54'),(133,3,151,'2026-08-03 23:07:54'),(134,3,152,'2026-08-03 23:07:54'),(135,3,157,'2026-08-03 23:07:54'),(136,3,158,'2026-08-03 23:07:54'),(149,5,1,'2026-08-03 23:07:55'),(150,5,37,'2026-08-03 23:07:55'),(151,5,38,'2026-08-03 23:07:55'),(152,5,39,'2026-08-03 23:07:55'),(153,5,43,'2026-08-03 23:07:55'),(154,5,49,'2026-08-03 23:07:55'),(155,5,50,'2026-08-03 23:07:55'),(156,5,55,'2026-08-03 23:07:55'),(158,5,193,'2026-08-07 09:45:06'),(159,3,193,'2026-08-07 09:45:06'),(160,2,1,'2026-08-20 22:21:49'),(161,2,5,'2026-08-20 22:21:49'),(162,2,7,'2026-08-20 22:21:49'),(163,2,19,'2026-08-20 22:21:49'),(164,2,20,'2026-08-20 22:21:49'),(165,2,21,'2026-08-20 22:21:49'),(166,2,37,'2026-08-20 22:21:49'),(167,2,38,'2026-08-20 22:21:49'),(168,2,39,'2026-08-20 22:21:49'),(169,2,40,'2026-08-20 22:21:49'),(170,2,43,'2026-08-20 22:21:49'),(171,2,193,'2026-08-20 22:21:49'),(172,2,49,'2026-08-20 22:21:49'),(173,2,50,'2026-08-20 22:21:49'),(174,2,51,'2026-08-20 22:21:49'),(175,2,52,'2026-08-20 22:21:49'),(176,2,53,'2026-08-20 22:21:49'),(177,2,55,'2026-08-20 22:21:49'),(178,2,59,'2026-08-20 22:21:49'),(179,2,61,'2026-08-20 22:21:49'),(180,2,62,'2026-08-20 22:21:49'),(181,2,63,'2026-08-20 22:21:49'),(182,2,64,'2026-08-20 22:21:49'),(183,2,65,'2026-08-20 22:21:49'),(184,2,67,'2026-08-20 22:21:49'),(185,2,73,'2026-08-20 22:21:49'),(186,2,121,'2026-08-20 22:21:49'),(187,2,122,'2026-08-20 22:21:49'),(188,2,123,'2026-08-20 22:21:49'),(189,2,127,'2026-08-20 22:21:49'),(190,2,128,'2026-08-20 22:21:49'),(191,2,130,'2026-08-20 22:21:49'),(192,2,133,'2026-08-20 22:21:49'),(193,2,134,'2026-08-20 22:21:49'),(194,2,135,'2026-08-20 22:21:49'),(195,2,145,'2026-08-20 22:21:49'),(196,2,151,'2026-08-20 22:21:49'),(197,2,152,'2026-08-20 22:21:49'),(198,2,154,'2026-08-20 22:21:49'),(199,2,157,'2026-08-20 22:21:49'),(200,2,158,'2026-08-20 22:21:49'),(201,2,159,'2026-08-20 22:21:49'),(202,2,163,'2026-08-20 22:21:49'),(203,4,1,'2026-08-20 22:22:34'),(204,4,37,'2026-08-20 22:22:34'),(205,4,193,'2026-08-20 22:22:34'),(206,4,49,'2026-08-20 22:22:34'),(207,4,55,'2026-08-20 22:22:34'),(208,4,61,'2026-08-20 22:22:34'),(209,4,62,'2026-08-20 22:22:34'),(210,4,63,'2026-08-20 22:22:34'),(211,4,199,'2026-08-20 22:22:34'),(212,4,205,'2026-08-20 22:22:34'),(213,4,223,'2026-08-20 22:22:34'),(214,4,115,'2026-08-20 22:22:34'),(215,4,121,'2026-08-20 22:22:34'),(216,4,151,'2026-08-20 22:22:34'),(217,4,152,'2026-08-20 22:22:34'),(218,4,157,'2026-08-20 22:22:34'),(219,4,158,'2026-08-20 22:22:34'),(220,4,159,'2026-08-20 22:22:34'),(221,8,280,'2026-09-08 18:32:39'),(222,7,280,'2026-09-08 18:32:39'),(223,6,280,'2026-09-08 18:32:39'),(224,8,285,'2026-09-08 18:32:39'),(225,7,285,'2026-09-08 18:32:39'),(226,6,285,'2026-09-08 18:32:39'),(227,8,290,'2026-09-08 18:32:39'),(228,7,290,'2026-09-08 18:32:39'),(229,6,290,'2026-09-08 18:32:39'),(230,8,295,'2026-09-08 18:32:39'),(231,7,295,'2026-09-08 18:32:39'),(232,8,300,'2026-09-08 18:32:39'),(233,7,300,'2026-09-08 18:32:39'),(234,8,305,'2026-09-08 18:32:39'),(235,8,281,'2026-09-08 18:32:39'),(236,7,281,'2026-09-08 18:32:39'),(237,6,281,'2026-09-08 18:32:39'),(238,8,286,'2026-09-08 18:32:39'),(239,7,286,'2026-09-08 18:32:39'),(240,6,286,'2026-09-08 18:32:39'),(241,8,291,'2026-09-08 18:32:39'),(242,7,291,'2026-09-08 18:32:39'),(243,6,291,'2026-09-08 18:32:39'),(244,8,296,'2026-09-08 18:32:39'),(245,7,296,'2026-09-08 18:32:39'),(246,8,306,'2026-09-08 18:32:39'),(247,8,282,'2026-09-08 18:32:39'),(248,7,282,'2026-09-08 18:32:39'),(249,6,282,'2026-09-08 18:32:39'),(250,8,287,'2026-09-08 18:32:39'),(251,7,287,'2026-09-08 18:32:39'),(252,8,292,'2026-09-08 18:32:39'),(253,7,292,'2026-09-08 18:32:39'),(254,8,297,'2026-09-08 18:32:39'),(255,8,302,'2026-09-08 18:32:39'),(256,7,302,'2026-09-08 18:32:39'),(257,8,307,'2026-09-08 18:32:39'),(258,8,279,'2026-09-08 18:32:39'),(259,7,279,'2026-09-08 18:32:39'),(260,6,279,'2026-09-08 18:32:39'),(261,8,284,'2026-09-08 18:32:39'),(262,7,284,'2026-09-08 18:32:39'),(263,8,289,'2026-09-08 18:32:39'),(264,7,289,'2026-09-08 18:32:39'),(265,8,294,'2026-09-08 18:32:39'),(266,7,294,'2026-09-08 18:32:39'),(267,8,304,'2026-09-08 18:32:39'),(268,8,283,'2026-09-08 18:32:39'),(269,7,283,'2026-09-08 18:32:39'),(270,6,283,'2026-09-08 18:32:39'),(271,8,308,'2026-09-08 18:32:39'),(272,7,308,'2026-09-08 18:32:39');
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Administrator','admin','Full, implicit access to every module. Cannot be edited or deleted.',1,1,'2026-08-03 11:44:08','2026-08-03 11:44:08'),(2,'Branch Manager','branch_manager','Runs day-to-day operations for a single branch.',0,0,'2026-08-03 11:44:08','2026-08-03 11:44:08'),(3,'Staff','staff','General branch staff with read access to their own branch.',0,0,'2026-08-03 11:44:08','2026-08-03 11:44:08'),(4,'Cashier','cashier','Records loan payments at the counter, including full settlements (which a Branch Manager must approve).',0,0,'2026-08-03 11:44:08','2026-08-03 11:44:08'),(5,'Marketing Executive','marketing_executive','Sources customers and loan applications in the field.',0,0,'2026-08-03 11:44:08','2026-08-03 11:44:08'),(6,'Finance Officer','finance_officer','Raises and executes routine finance requests and runs daily cash sessions for a single branch.',0,0,'2026-09-01 12:42:04','2026-09-01 12:42:04'),(7,'Finance Manager','finance_manager','Company-wide finance authority: approves requests and transfers, issues and settles floats, signs off cash variances.',0,1,'2026-09-01 12:42:04','2026-09-01 12:42:04'),(8,'Finance Administrator','finance_admin','Full Finance module control including approval-tier and threshold settings, plus read access to the accounting side.',0,1,'2026-09-01 12:42:04','2026-09-01 12:42:04');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_executive_commissions`
--

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
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_executive_commissions_loan_type_id_foreign` (`loan_type_id`),
  KEY `sales_executive_commissions_paid_by_foreign` (`paid_by`),
  KEY `sales_executive_commissions_credit_facility_id_cycle_no_index` (`credit_facility_id`,`cycle_no`),
  KEY `sales_executive_commissions_sales_executive_id_index` (`sales_executive_id`),
  KEY `sales_executive_commissions_cash_account_id_foreign` (`cash_account_id`),
  KEY `sales_executive_commissions_bank_account_id_foreign` (`bank_account_id`),
  KEY `sales_executive_commissions_loan_type_id_index` (`loan_type_id`),
  KEY `sales_executive_commissions_branch_id_index` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_executive_commissions`
--

LOCK TABLES `sales_executive_commissions` WRITE;
/*!40000 ALTER TABLE `sales_executive_commissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales_executive_commissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `savings_accounts`
--

DROP TABLE IF EXISTS `savings_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_number` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `savings_product_id` bigint unsigned NOT NULL,
  `interest_rate_percent` decimal(6,3) unsigned NOT NULL,
  `interest_calc_method` enum('min_monthly_balance','daily_balance','average_balance') COLLATE utf8mb4_unicode_ci NOT NULL,
  `interest_posting_frequency` enum('monthly','quarterly','annually') COLLATE utf8mb4_unicode_ci NOT NULL,
  `min_balance` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `withdrawal_fee` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `open_date` date NOT NULL,
  `current_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `interest_accrued` decimal(15,2) NOT NULL DEFAULT '0.00',
  `last_interest_posted_on` date DEFAULT NULL,
  `last_activity_on` date DEFAULT NULL,
  `status` enum('Active','Dormant','Frozen','Closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `closed_on` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `savings_accounts_public_uuid_unique` (`public_uuid`),
  UNIQUE KEY `savings_accounts_account_number_unique` (`account_number`),
  KEY `savings_accounts_created_by_foreign` (`created_by`),
  KEY `savings_accounts_branch_id_index` (`branch_id`),
  KEY `savings_accounts_customer_id_index` (`customer_id`),
  KEY `savings_accounts_savings_product_id_index` (`savings_product_id`),
  KEY `savings_accounts_status_branch_id_index` (`status`,`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `savings_accounts`
--

LOCK TABLES `savings_accounts` WRITE;
/*!40000 ALTER TABLE `savings_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `savings_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `savings_collection_lines`
--

DROP TABLE IF EXISTS `savings_collection_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_collection_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `savings_collection_sheet_id` bigint unsigned NOT NULL,
  `savings_account_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `collected` tinyint(1) NOT NULL DEFAULT '1',
  `reference_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `savings_transaction_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scl_sheet_account` (`savings_collection_sheet_id`,`savings_account_id`),
  KEY `savings_collection_lines_savings_transaction_id_foreign` (`savings_transaction_id`),
  KEY `savings_collection_lines_savings_account_id_index` (`savings_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `savings_collection_lines`
--

LOCK TABLES `savings_collection_lines` WRITE;
/*!40000 ALTER TABLE `savings_collection_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `savings_collection_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `savings_collection_sheets`
--

DROP TABLE IF EXISTS `savings_collection_sheets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_collection_sheets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sheet_number` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `collector_id` bigint unsigned NOT NULL,
  `collection_date` date NOT NULL,
  `status` enum('Open','Posted','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Open',
  `total_amount` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `line_count` int unsigned NOT NULL DEFAULT '0',
  `posted_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `savings_collection_sheets_sheet_number_unique` (`sheet_number`),
  KEY `savings_collection_sheets_created_by_foreign` (`created_by`),
  KEY `savings_collection_sheets_branch_id_collection_date_index` (`branch_id`,`collection_date`),
  KEY `savings_collection_sheets_status_index` (`status`),
  KEY `savings_collection_sheets_collector_id_index` (`collector_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `savings_collection_sheets`
--

LOCK TABLES `savings_collection_sheets` WRITE;
/*!40000 ALTER TABLE `savings_collection_sheets` DISABLE KEYS */;
/*!40000 ALTER TABLE `savings_collection_sheets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `savings_interest_runs`
--

DROP TABLE IF EXISTS `savings_interest_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_interest_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_number` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `status` enum('Draft','Posted','Voided') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `account_count` int unsigned NOT NULL DEFAULT '0',
  `total_interest` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `posted_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `posted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `savings_interest_runs_run_number_unique` (`run_number`),
  KEY `savings_interest_runs_branch_id_foreign` (`branch_id`),
  KEY `savings_interest_runs_created_by_foreign` (`created_by`),
  KEY `savings_interest_runs_posted_by_foreign` (`posted_by`),
  KEY `savings_interest_runs_status_index` (`status`),
  KEY `savings_interest_runs_period_from_period_to_index` (`period_from`,`period_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `savings_interest_runs`
--

LOCK TABLES `savings_interest_runs` WRITE;
/*!40000 ALTER TABLE `savings_interest_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `savings_interest_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `savings_products`
--

DROP TABLE IF EXISTS `savings_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `interest_rate_percent` decimal(6,3) unsigned NOT NULL,
  `interest_calc_method` enum('min_monthly_balance','daily_balance','average_balance') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'min_monthly_balance',
  `interest_posting_frequency` enum('monthly','quarterly','annually') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `min_opening_deposit` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `min_balance` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `withdrawal_fee` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `ledger_fee` decimal(15,2) unsigned NOT NULL DEFAULT '0.00',
  `dormancy_days` smallint unsigned NOT NULL DEFAULT '0',
  `reference_format` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `savings_products_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `savings_products`
--

LOCK TABLES `savings_products` WRITE;
/*!40000 ALTER TABLE `savings_products` DISABLE KEYS */;
INSERT INTO `savings_products` VALUES (1,'Daily Saving','DS',12.000,'min_monthly_balance','monthly',1000.00,1000.00,100.00,100.00,0,NULL,0,'Active','2026-09-10 21:10:00','2026-09-10 21:10:00');
/*!40000 ALTER TABLE `savings_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `savings_transactions`
--

DROP TABLE IF EXISTS `savings_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `txn_number` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `savings_account_id` bigint unsigned NOT NULL,
  `txn_date` date NOT NULL,
  `type` enum('Deposit','Withdrawal','InterestPosting','Fee','Adjustment','OpeningBalance') COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` enum('In','Out') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) unsigned NOT NULL,
  `running_balance` decimal(15,2) NOT NULL,
  `payment_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_id` bigint unsigned DEFAULT NULL,
  `reference_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `collection_sheet_id` bigint unsigned DEFAULT NULL,
  `interest_run_id` bigint unsigned DEFAULT NULL,
  `finance_transaction_id` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Posted','Voided') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Posted',
  `void_of_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `bank_account_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `savings_transactions_txn_number_unique` (`txn_number`),
  KEY `savings_transactions_bank_id_foreign` (`bank_id`),
  KEY `savings_transactions_finance_transaction_id_foreign` (`finance_transaction_id`),
  KEY `savings_transactions_void_of_id_foreign` (`void_of_id`),
  KEY `savings_transactions_created_by_foreign` (`created_by`),
  KEY `savings_transactions_savings_account_id_id_index` (`savings_account_id`,`id`),
  KEY `savings_transactions_collection_sheet_id_index` (`collection_sheet_id`),
  KEY `savings_transactions_interest_run_id_index` (`interest_run_id`),
  KEY `savings_transactions_type_txn_date_index` (`type`,`txn_date`),
  KEY `savings_transactions_bank_account_id_foreign` (`bank_account_id`),
  KEY `savings_transactions_branch_id_index` (`branch_id`),
  CONSTRAINT `savings_transactions_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `savings_transactions`
--

LOCK TABLES `savings_transactions` WRITE;
/*!40000 ALTER TABLE `savings_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `savings_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `speed_draft_facility_details`
--

DROP TABLE IF EXISTS `speed_draft_facility_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `speed_draft_facility_details` (
  `credit_facility_id` bigint unsigned NOT NULL,
  `speed_draft_type_id` bigint unsigned NOT NULL,
  `vehicle_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_frequency` enum('daily','weekly','monthly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `disbursement_method` enum('Cash','Bank','Cheque') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disbursement_bank_account_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`credit_facility_id`),
  KEY `speed_draft_facility_details_speed_draft_type_id_index` (`speed_draft_type_id`),
  KEY `sdfd_disb_bank_acc_fk` (`disbursement_bank_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `speed_draft_facility_details`
--

LOCK TABLES `speed_draft_facility_details` WRITE;
/*!40000 ALTER TABLE `speed_draft_facility_details` DISABLE KEYS */;
INSERT INTO `speed_draft_facility_details` VALUES (3,2,NULL,'monthly',NULL,NULL);
/*!40000 ALTER TABLE `speed_draft_facility_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `speed_draft_renewals`
--

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
  KEY `speed_draft_renewals_old_credit_facility_id_index` (`old_credit_facility_id`),
  KEY `speed_draft_renewals_facility_payment_id_foreign` (`facility_payment_id`),
  KEY `speed_draft_renewals_renewed_by_foreign` (`renewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `speed_draft_renewals`
--

LOCK TABLES `speed_draft_renewals` WRITE;
/*!40000 ALTER TABLE `speed_draft_renewals` DISABLE KEYS */;
/*!40000 ALTER TABLE `speed_draft_renewals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `speed_draft_types`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `speed_draft_types`
--

LOCK TABLES `speed_draft_types` WRITE;
/*!40000 ALTER TABLE `speed_draft_types` DISABLE KEYS */;
INSERT INTO `speed_draft_types` VALUES (1,'FLEX','FX','Lease','{BRANCH}-SD-{YEAR:2}-{SEQ:6}',5.000,'monthly',10000.00,4000000.00,1,12,5,NULL,1,0,'Active','2026-08-04 21:58:22','2026-08-04 21:58:22'),(2,'Gold Loan','GL','GoldPawning','{BRANCH}-SD-{YEAR:2}-{SEQ:6}',3.500,'monthly',1000.00,10000000.00,1,3,1,NULL,1,0,'Active','2026-08-04 22:06:43','2026-08-04 22:06:43'),(3,'safas','ASFASF','Loan','{BRANCH}-SD-{YEAR:2}-{SEQ:6}',23.000,'monthly',1212.00,1212.00,1,13,5,NULL,0,0,'Active','2026-08-06 22:02:57','2026-08-06 22:02:57'),(4,'FlexD','FLD','Loan','{BRANCH}-SD-{YEAR:2}-{SEQ:6}',5.000,'daily',10000.00,10000000000.00,1,36,5,NULL,1,0,'Active','2026-08-22 05:22:03','2026-08-22 05:22:03');
/*!40000 ALTER TABLE `speed_draft_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscriptions`
--

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
  KEY `subscriptions_expiry_date_index` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscriptions`
--

LOCK TABLES `subscriptions` WRITE;
/*!40000 ALTER TABLE `subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_permission_overrides`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=247 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_permission_overrides`
--

LOCK TABLES `user_permission_overrides` WRITE;
/*!40000 ALTER TABLE `user_permission_overrides` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_permission_overrides` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

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
  `branch` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'607ba240-31ea-4cbd-8123-9c83dac80e7d','System Administrator','admin@absl.local',NULL,'$2y$10$ezDRFClUXRu/OY/VBHppRuffZz5/pYbCKevFsY.Eh8YtlzPRRV3Hq',NULL,1,1,'Active',NULL,'2026-08-03 11:44:08','2026-10-13 12:31:48'),(12,'4ec830ad-7d64-4d49-bec9-568833222a7a','ada','sampathdp28@gmail.com',NULL,'$2y$10$D6RiVIUZRKUoib67nFfWvOSSuhRfISJGawSpmH/EJG8wTTywTWZHG',NULL,1,1,'Active',NULL,'2026-10-14 08:26:10','2026-10-22 08:00:44');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vendors`
--

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vendors`
--

LOCK TABLES `vendors` WRITE;
/*!40000 ALTER TABLE `vendors` DISABLE KEYS */;
INSERT INTO `vendors` VALUES (2,'Porrnika Hansani','10000100','Poornika','0754571628','hansiwadduwage95@gmail.com','Batgoda Galpatha','223333','Active','2026-08-20 19:36:19','2026-08-20 19:36:19'),(3,'All Staff','10000000','HR','0','hr@gmail.com','Head Ofz','123456','Active','2026-08-20 19:42:19','2026-08-20 19:42:19');
/*!40000 ALTER TABLE `vendors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'tazz'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-23 22:20:34
