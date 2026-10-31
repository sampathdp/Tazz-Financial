-- =====================================================================================
-- ABSL Financial - LIVE SERVER UPDATE (written for the live server: MariaDB 11.4; also runs on MySQL 8)
--
-- Run LIVE_DB_CHECK.sql first. Back up first:  mysqldump --single-transaction --routines -u USER -p DBNAME > backup.sql
-- Run in a quiet window (stop the app / maintenance mode): converting MyISAM tables locks each table briefly.
-- Every step is idempotent: running the file twice changes nothing the second time.
--
-- !!! DO NOT RUN `php artisan migrate` ON THIS SERVER. !!!
-- The live `migrations` table lists 224 migrations, but 34 migration files in the repo are not listed even
-- though their changes are already in the database (it was restored from a dump / patched by hand). `artisan
-- migrate` would try to run them again and stop half way on "column/table already exists". Step 3 below
-- records them as done instead. Everything those migrations did is verified by LIVE_DB_CHECK.sql.
--
-- IN phpMyAdmin: select the live database in the left tree (NOT information_schema), open the SQL tab,
-- paste this whole file. If it complains about DELIMITER, tick nothing - phpMyAdmin supports it in the SQL tab.
-- =====================================================================================

-- ------------------------------------------------------------------ STEP 0: guard - a real database must be selected
DROP PROCEDURE IF EXISTS absl_require_db;
DELIMITER $$
CREATE PROCEDURE absl_require_db()
BEGIN
  IF DATABASE() IS NULL OR DATABASE() IN ('information_schema', 'mysql', 'sys', 'performance_schema') OR NOT EXISTS (
       SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No application database selected: click your database in the left tree first (it must contain the migrations table).';
  END IF;
END$$
DELIMITER ;
CALL absl_require_db();
DROP PROCEDURE absl_require_db;

-- ------------------------------------------------------------------ STEP 1a: branch_funding_agreements (the table that breaks local imports)
-- `code` is varchar(255) on a MyISAM table behind a MariaDB hash-unique key. The migrations define varchar(191)
-- on InnoDB, which is a normal, portable unique key. Codes are like 'BFA-26-00001' (12 chars).
DROP PROCEDURE IF EXISTS absl_fix_bfa;
DELIMITER $$
CREATE PROCEDURE absl_fix_bfa()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'branch_funding_agreements') THEN
    SET @maxlen = (SELECT COALESCE(MAX(CHAR_LENGTH(code)), 0) FROM branch_funding_agreements);
    IF @maxlen > 191 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'branch_funding_agreements.code has a value longer than 191 characters - shorten it by hand first.';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'branch_funding_agreements' AND index_name = 'branch_funding_agreements_code_unique') THEN
      ALTER TABLE `branch_funding_agreements` DROP INDEX `branch_funding_agreements_code_unique`;
    END IF;
    IF (SELECT engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'branch_funding_agreements') <> 'InnoDB' THEN
      ALTER TABLE `branch_funding_agreements` ENGINE=InnoDB;
    END IF;
    IF (SELECT character_maximum_length FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'branch_funding_agreements' AND column_name = 'code') > 191 THEN
      ALTER TABLE `branch_funding_agreements` MODIFY `code` VARCHAR(191) NOT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'branch_funding_agreements' AND index_name = 'branch_funding_agreements_code_unique') THEN
      ALTER TABLE `branch_funding_agreements` ADD UNIQUE KEY `branch_funding_agreements_code_unique` (`code`);
    END IF;
  END IF;
END$$
DELIMITER ;
CALL absl_fix_bfa();
DROP PROCEDURE absl_fix_bfa;

-- ------------------------------------------------------------------ STEP 1b: every table -> InnoDB (MyISAM ignores transactions)
DROP PROCEDURE IF EXISTS absl_convert_innodb;
DELIMITER $$
CREATE PROCEDURE absl_convert_innodb()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE tname VARCHAR(128);
  DECLARE cur CURSOR FOR SELECT table_name FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND engine <> 'InnoDB';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN cur;
  loop1: LOOP
    FETCH cur INTO tname;
    IF done = 1 THEN LEAVE loop1; END IF;
    SET @s = CONCAT('ALTER TABLE `', tname, '` ENGINE=InnoDB');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END LOOP;
  CLOSE cur;
END$$
DELIMITER ;
CALL absl_convert_innodb();
DROP PROCEDURE absl_convert_innodb;

-- ------------------------------------------------------------------ STEP 1c: latin1 tables -> utf8mb4 (mixing latin1 and utf8mb4 columns in a join gives "Illegal mix of collations")
DROP PROCEDURE IF EXISTS absl_convert_charset;
DELIMITER $$
CREATE PROCEDURE absl_convert_charset()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE tname VARCHAR(128);
  DECLARE cur CURSOR FOR SELECT table_name FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_collation LIKE 'latin1%';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN cur;
  loop1: LOOP
    FETCH cur INTO tname;
    IF done = 1 THEN LEAVE loop1; END IF;
    SET @s = CONCAT('ALTER TABLE `', tname, '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END LOOP;
  CLOSE cur;
END$$
DELIMITER ;
CALL absl_convert_charset();
DROP PROCEDURE absl_convert_charset;

-- ------------------------------------------------------------------ STEP 2: new columns, one-live-row keys, audit table
DROP PROCEDURE IF EXISTS absl_add_col;
DROP PROCEDURE IF EXISTS absl_void_schema;
DELIMITER $$
CREATE PROCEDURE absl_add_col(IN t VARCHAR(64), IN c VARCHAR(64), IN ddl VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = t AND column_name = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', ddl);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$

CREATE PROCEDURE absl_void_schema()
BEGIN
  DECLARE i INT DEFAULT 0;
  DECLARE tn VARCHAR(64);
  WHILE i < 6 DO
    SET tn = ELT(i + 1, 'fd_withdrawals', 'fd_part_payments', 'pawning_renewals', 'pawning_redemptions', 'borrowing_transactions', 'facility_waivers');
    CALL absl_add_col(tn, 'voided_at', 'TIMESTAMP NULL DEFAULT NULL');
    CALL absl_add_col(tn, 'voided_by', 'BIGINT UNSIGNED NULL DEFAULT NULL');
    CALL absl_add_col(tn, 'void_reason', 'VARCHAR(255) NULL DEFAULT NULL');
    SET i = i + 1;
  END WHILE;

  CALL absl_add_col('facility_waivers', 'journal_entry_id', 'BIGINT UNSIGNED NULL DEFAULT NULL');
  CALL absl_add_col('facility_waivers', 'posted_amount', 'DECIMAL(15,2) NULL DEFAULT NULL');

  -- fd_renewals columns from migration 2026_10_18_000001 that the live table lacks
  CALL absl_add_col('fd_renewals', 'interest_amount', 'DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0');
  CALL absl_add_col('fd_renewals', 'capitalised_interest', 'DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0');
  CALL absl_add_col('fd_renewals', 'interest_payment_method', 'VARCHAR(16) NULL DEFAULT NULL');
  CALL absl_add_col('fd_renewals', 'is_auto', 'TINYINT(1) NOT NULL DEFAULT 0');

  -- fd_withdrawals: one LIVE withdrawal per FD (voided rows no longer count)
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'fd_withdrawals' AND column_name = 'live_fd_id') THEN
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'fd_withdrawals' AND index_name = 'fd_withdrawals_fd_idx') THEN
      ALTER TABLE `fd_withdrawals` ADD INDEX `fd_withdrawals_fd_idx` (`fixed_deposit_id`);
    END IF;
    ALTER TABLE `fd_withdrawals` ADD COLUMN `live_fd_id` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`voided_at` IS NULL, `fixed_deposit_id`, NULL)) STORED;
    ALTER TABLE `fd_withdrawals` ADD UNIQUE INDEX `fd_withdrawals_live_fd_unique` (`live_fd_id`);
    IF EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'fd_withdrawals' AND index_name = 'fd_withdrawals_fixed_deposit_id_unique') THEN
      ALTER TABLE `fd_withdrawals` DROP INDEX `fd_withdrawals_fixed_deposit_id_unique`;
    END IF;
  END IF;

  -- pawning_renewals: renewal numbers unique among LIVE renewals
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'pawning_renewals' AND column_name = 'live_renewal_no') THEN
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'pawning_renewals' AND index_name = 'pawning_renewals_facility_idx') THEN
      ALTER TABLE `pawning_renewals` ADD INDEX `pawning_renewals_facility_idx` (`credit_facility_id`);
    END IF;
    ALTER TABLE `pawning_renewals` ADD COLUMN `live_renewal_no` INT GENERATED ALWAYS AS (IF(`voided_at` IS NULL, `renewal_no`, NULL)) STORED;
    ALTER TABLE `pawning_renewals` ADD UNIQUE INDEX `pawning_renewals_live_unique` (`credit_facility_id`, `live_renewal_no`);
    IF EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'pawning_renewals' AND index_name = 'pawning_renewals_credit_facility_id_renewal_no_unique') THEN
      ALTER TABLE `pawning_renewals` DROP INDEX `pawning_renewals_credit_facility_id_renewal_no_unique`;
    END IF;
  END IF;
END$$
DELIMITER ;
CALL absl_void_schema();
DROP PROCEDURE absl_void_schema;
DROP PROCEDURE absl_add_col;

CREATE TABLE IF NOT EXISTS `audit_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` VARCHAR(64) NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `method` VARCHAR(8) NOT NULL,
  `path` VARCHAR(255) NOT NULL,
  `status` SMALLINT UNSIGNED NOT NULL,
  `ip` VARCHAR(45) NULL,
  `payload` LONGTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `audit_events_request_id_index` (`request_id`),
  KEY `audit_events_user_id_index` (`user_id`),
  KEY `audit_events_path_index` (`path`),
  KEY `audit_events_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ STEP 3: record the migrations (so `artisan migrate` has nothing left to run)
-- Generated: every migration file in the repo that the live `migrations` table does not list. Their changes are
-- already present (or applied by the steps above); constraints that are still missing are added by
-- LIVE_SCHEMA_REPAIR.sql, not by migrations.
INSERT INTO migrations (migration, batch)
SELECT m.name, (SELECT COALESCE(MAX(b.batch), 0) + 1 FROM (SELECT batch FROM migrations) b)
  FROM (
        SELECT '2026_08_11_000001_add_waived_interest_to_facility_schedules' AS name
        UNION ALL SELECT '2026_08_16_000001_add_daily_rate_period'
        UNION ALL SELECT '2026_08_21_000001_add_sales_executive_commission_to_journal_entries'
        UNION ALL SELECT '2026_08_26_000002_add_default_charge_rate_to_speed_draft_types'
        UNION ALL SELECT '2026_08_26_000003_create_speed_draft_renewals_table'
        UNION ALL SELECT '2026_08_26_000004_add_speed_draft_renewal_to_journal_entries'
        UNION ALL SELECT '2026_08_27_000001_convert_remaining_myisam_tables_to_innodb'
        UNION ALL SELECT '2026_08_27_000002_add_provisioning_columns_to_credit_facilities'
        UNION ALL SELECT '2026_08_27_000004_add_loan_provision_to_journal_entries'
        UNION ALL SELECT '2026_08_27_000005_seed_bad_debt_recovery_account'
        UNION ALL SELECT '2026_08_27_000006_rename_reverse_to_recover_on_loan_provisions'
        UNION ALL SELECT '2026_08_29_000001_add_loan_reschedule_to_journal_entries'
        UNION ALL SELECT '2026_08_29_000002_add_restructured_from_to_credit_facilities'
        UNION ALL SELECT '2026_09_01_000001_add_finance_metadata_to_cash_accounts'
        UNION ALL SELECT '2026_09_01_000002_seed_finance_roles'
        UNION ALL SELECT '2026_09_01_000003_add_finance_transaction_to_source_type_enums'
        UNION ALL SELECT '2026_09_01_000005_widen_number_sequences_module_enum'
        UNION ALL SELECT '2026_09_01_000006_create_approval_tiers_and_extend_approvals'
        UNION ALL SELECT '2026_09_01_000007_create_finance_requests_table'
        UNION ALL SELECT '2026_09_01_000008_seed_finance_approval_types'
        UNION ALL SELECT '2026_09_01_000009_add_finance_transaction_to_payment_vouchers'
        UNION ALL SELECT '2026_09_01_000010_add_in_transit_to_finance_requests_status'
        UNION ALL SELECT '2026_09_01_000013_add_verify_lock_to_cash_reconciliations'
        UNION ALL SELECT '2026_09_01_000015_grant_finance_phase8_permissions'
        UNION ALL SELECT '2026_09_01_000016_reseed_borrowing_liability_accounts'
        UNION ALL SELECT '2026_09_01_000017_restore_fd_part_payment_journal_source_type'
        UNION ALL SELECT '2026_09_01_000018_add_finance_transaction_id_to_bank_transactions'
        UNION ALL SELECT '2026_09_01_000019_add_bank_transaction_journal_source_type'
        UNION ALL SELECT '2026_09_01_000020_retire_cash_transfers_module'
        UNION ALL SELECT '2026_09_02_000001_restore_journal_lines_one_side_check'
        UNION ALL SELECT '2026_10_26_000001_add_registration_no_to_company_profile'
        UNION ALL SELECT '2026_10_28_000001_create_fd_joint_holders_nominees_commissions'
        UNION ALL SELECT '2026_10_31_000001_convert_myisam_tables_to_innodb'
        UNION ALL SELECT '2026_10_31_000002_void_instead_of_delete_and_audit_events'
       ) m
 WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.name);

-- ------------------------------------------------------------------ STEP 4: verify
-- Run LIVE_DB_CHECK.sql again. Expected after this file: only MISSING foreign key / MISSING check rows remain
-- (those are added by LIVE_SCHEMA_REPAIR.sql). No MISSING table/column/unique/primary key, no "PROBLEM not InnoDB".

-- ------------------------------------------------------------------ ROLLBACK
-- Do not drop the new columns or audit_events once they hold data. Restore from the backup instead:
--   mysql -u USER -p DBNAME < backup.sql
-- Converting back to MyISAM is deliberately not offered: it would silently disable transactions again.
