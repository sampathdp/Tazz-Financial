-- --------------------------------------------------------
-- Migration: 2026_08_01_000002_create_sales_executive_commissions_table.php
-- --------------------------------------------------------
create table `sales_executive_commissions` (`id` bigint unsigned not null auto_increment primary key, `loan_id` bigint unsigned not null, `sales_executive_id` bigint unsigned not null, `loan_type_id` bigint unsigned not null, `commission_type` varchar(16) not null, `commission_value` decimal(15, 2) not null, `commission_amount` decimal(15, 2) not null, `required_installments` smallint unsigned not null, `paid_at` datetime null, `payment_amount` decimal(15, 2) null, `payment_method` varchar(16) null, `reference_no` varchar(64) null, `notes` varchar(255) null, `paid_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `sales_executive_commissions` add constraint `sales_executive_commissions_loan_id_foreign` foreign key (`loan_id`) references `loans` (`id`) on delete restrict;
alter table `sales_executive_commissions` add constraint `sales_executive_commissions_sales_executive_id_foreign` foreign key (`sales_executive_id`) references `users` (`id`) on delete restrict;
alter table `sales_executive_commissions` add constraint `sales_executive_commissions_loan_type_id_foreign` foreign key (`loan_type_id`) references `loan_types` (`id`) on delete restrict;
alter table `sales_executive_commissions` add constraint `sales_executive_commissions_paid_by_foreign` foreign key (`paid_by`) references `users` (`id`) on delete set null;
alter table `sales_executive_commissions` add index `sales_executive_commissions_sales_executive_id_index`(`sales_executive_id`);
alter table `sales_executive_commissions` add index `sales_executive_commissions_loan_type_id_index`(`loan_type_id`);
alter table `sales_executive_commissions` add unique `sales_executive_commissions_loan_id_unique`(`loan_id`);

-- --------------------------------------------------------
-- Migration: 2026_08_01_000006_create_chart_of_accounts_table.php
-- --------------------------------------------------------
create table `chart_of_accounts` (`id` bigint unsigned not null auto_increment primary key, `code` varchar(16) not null, `name` varchar(128) not null, `type` varchar(16) not null, `normal_balance` varchar(8) not null, `parent_id` bigint unsigned null, `is_system` tinyint(1) not null default '0', `status` varchar(16) not null default 'Active', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `chart_of_accounts` add constraint `chart_of_accounts_parent_id_foreign` foreign key (`parent_id`) references `chart_of_accounts` (`id`) on delete restrict;
alter table `chart_of_accounts` add index `chart_of_accounts_type_index`(`type`);
alter table `chart_of_accounts` add index `chart_of_accounts_parent_id_index`(`parent_id`);
alter table `chart_of_accounts` add unique `chart_of_accounts_code_unique`(`code`);

-- --------------------------------------------------------
-- Migration: 2026_08_01_000007_create_journal_entries_and_journal_lines_tables.php
-- --------------------------------------------------------
create table `journal_entries` (`id` bigint unsigned not null auto_increment primary key, `code` varchar(32) not null, `entry_date` date not null, `reference_type` varchar(191) null, `reference_id` bigint unsigned null, `description` varchar(255) null, `branch_id` bigint unsigned null, `posted_by` bigint unsigned null, `posted_at` datetime null, `status` varchar(16) not null default 'Posted', `reversal_of_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `journal_entries` add constraint `journal_entries_branch_id_foreign` foreign key (`branch_id`) references `branches` (`id`) on delete set null;
alter table `journal_entries` add constraint `journal_entries_posted_by_foreign` foreign key (`posted_by`) references `users` (`id`) on delete set null;
alter table `journal_entries` add constraint `journal_entries_reversal_of_id_foreign` foreign key (`reversal_of_id`) references `journal_entries` (`id`) on delete set null;
alter table `journal_entries` add index `journal_entries_reference_type_reference_id_index`(`reference_type`, `reference_id`);
alter table `journal_entries` add index `journal_entries_entry_date_index`(`entry_date`);
alter table `journal_entries` add unique `journal_entries_code_unique`(`code`);
create table `journal_lines` (`id` bigint unsigned not null auto_increment primary key, `journal_entry_id` bigint unsigned not null, `account_id` bigint unsigned not null, `debit` decimal(15, 2) not null default '0', `credit` decimal(15, 2) not null default '0', `description` varchar(255) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `journal_lines` add constraint `journal_lines_journal_entry_id_foreign` foreign key (`journal_entry_id`) references `journal_entries` (`id`) on delete cascade;
alter table `journal_lines` add constraint `journal_lines_account_id_foreign` foreign key (`account_id`) references `chart_of_accounts` (`id`) on delete restrict;
alter table `journal_lines` add index `journal_lines_account_id_index`(`account_id`);

-- --------------------------------------------------------
-- Migration: 2026_08_01_000008_convert_legacy_myisam_tables_to_innodb.php
-- --------------------------------------------------------
ALTER TABLE `cash_accounts` ENGINE = InnoDB;
ALTER TABLE `cashbook_entries` ENGINE = InnoDB;
ALTER TABLE `cash_transfers` ENGINE = InnoDB;
ALTER TABLE `cash_reconciliations` ENGINE = InnoDB;
ALTER TABLE `fixed_deposits` ENGINE = InnoDB;
ALTER TABLE `fd_deposits` ENGINE = InnoDB;
ALTER TABLE `fd_interest_payments` ENGINE = InnoDB;
ALTER TABLE `fd_renewals` ENGINE = InnoDB;
ALTER TABLE `fd_withdrawals` ENGINE = InnoDB;
ALTER TABLE `funding_products` ENGINE = InnoDB;
ALTER TABLE `number_sequences` ENGINE = InnoDB;
ALTER TABLE `generated_numbers` ENGINE = InnoDB;
alter table `cash_accounts` add constraint `cash_accounts_branch_id_foreign` foreign key (`branch_id`) references `branches` (`id`) on delete restrict;
alter table `cashbook_entries` add constraint `cashbook_entries_branch_id_foreign` foreign key (`branch_id`) references `branches` (`id`) on delete restrict;
alter table `cashbook_entries` add constraint `cashbook_entries_cash_account_id_foreign` foreign key (`cash_account_id`) references `cash_accounts` (`id`) on delete restrict;
alter table `cashbook_entries` add constraint `cashbook_entries_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete set null;
alter table `cashbook_entries` add constraint `cashbook_entries_approved_by_foreign` foreign key (`approved_by`) references `users` (`id`) on delete set null;
alter table `cash_transfers` add constraint `cash_transfers_from_cash_account_id_foreign` foreign key (`from_cash_account_id`) references `cash_accounts` (`id`) on delete restrict;
alter table `cash_transfers` add constraint `cash_transfers_to_cash_account_id_foreign` foreign key (`to_cash_account_id`) references `cash_accounts` (`id`) on delete restrict;
alter table `cash_transfers` add constraint `cash_transfers_requested_by_foreign` foreign key (`requested_by`) references `users` (`id`) on delete set null;
alter table `cash_transfers` add constraint `cash_transfers_approved_by_foreign` foreign key (`approved_by`) references `users` (`id`) on delete set null;
alter table `cash_reconciliations` add constraint `cash_reconciliations_branch_id_foreign` foreign key (`branch_id`) references `branches` (`id`) on delete restrict;
alter table `cash_reconciliations` add constraint `cash_reconciliations_reconciled_by_foreign` foreign key (`reconciled_by`) references `users` (`id`) on delete set null;
alter table `fixed_deposits` add constraint `fixed_deposits_branch_id_foreign` foreign key (`branch_id`) references `branches` (`id`) on delete restrict;
alter table `fixed_deposits` add constraint `fixed_deposits_customer_id_foreign` foreign key (`customer_id`) references `customers` (`id`) on delete restrict;
alter table `fixed_deposits` add constraint `fixed_deposits_funding_product_id_foreign` foreign key (`funding_product_id`) references `funding_products` (`id`) on delete restrict;
alter table `fixed_deposits` add constraint `fixed_deposits_renewed_from_id_foreign` foreign key (`renewed_from_id`) references `fixed_deposits` (`id`) on delete set null;
alter table `fixed_deposits` add constraint `fixed_deposits_renewed_to_id_foreign` foreign key (`renewed_to_id`) references `fixed_deposits` (`id`) on delete set null;
alter table `fixed_deposits` add constraint `fixed_deposits_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete set null;
alter table `fd_deposits` add constraint `fd_deposits_branch_id_foreign` foreign key (`branch_id`) references `branches` (`id`) on delete restrict;
alter table `fd_deposits` add constraint `fd_deposits_customer_id_foreign` foreign key (`customer_id`) references `customers` (`id`) on delete restrict;
alter table `fd_deposits` add constraint `fd_deposits_fixed_deposit_id_foreign` foreign key (`fixed_deposit_id`) references `fixed_deposits` (`id`) on delete set null;
alter table `fd_deposits` add constraint `fd_deposits_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete set null;
alter table `fd_interest_payments` add constraint `fd_interest_payments_fixed_deposit_id_foreign` foreign key (`fixed_deposit_id`) references `fixed_deposits` (`id`) on delete cascade;
alter table `fd_interest_payments` add constraint `fd_interest_payments_paid_by_foreign` foreign key (`paid_by`) references `users` (`id`) on delete set null;
alter table `fd_renewals` add constraint `fd_renewals_old_fixed_deposit_id_foreign` foreign key (`old_fixed_deposit_id`) references `fixed_deposits` (`id`) on delete restrict;
alter table `fd_renewals` add constraint `fd_renewals_new_fixed_deposit_id_foreign` foreign key (`new_fixed_deposit_id`) references `fixed_deposits` (`id`) on delete restrict;
alter table `fd_renewals` add constraint `fd_renewals_additional_deposit_id_foreign` foreign key (`additional_deposit_id`) references `fd_deposits` (`id`) on delete set null;
alter table `fd_renewals` add constraint `fd_renewals_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete set null;
alter table `fd_withdrawals` add constraint `fd_withdrawals_fixed_deposit_id_foreign` foreign key (`fixed_deposit_id`) references `fixed_deposits` (`id`) on delete restrict;
alter table `fd_withdrawals` add constraint `fd_withdrawals_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete set null;
alter table `generated_numbers` add constraint `generated_numbers_branch_id_foreign` foreign key (`branch_id`) references `branches` (`id`) on delete set null;
alter table `generated_numbers` add constraint `generated_numbers_loan_type_id_foreign` foreign key (`loan_type_id`) references `loan_types` (`id`) on delete set null;
alter table `generated_numbers` add constraint `generated_numbers_funding_product_id_foreign` foreign key (`funding_product_id`) references `funding_products` (`id`) on delete set null;
alter table `generated_numbers` add constraint `generated_numbers_generated_by_foreign` foreign key (`generated_by`) references `users` (`id`) on delete set null;

-- --------------------------------------------------------
-- Migration: 2026_08_01_000008_create_ledger_account_link_tables.php
-- --------------------------------------------------------
create table `cash_till_links` (`account_id` bigint unsigned not null, `cash_account_id` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cash_till_links` add constraint `cash_till_links_account_id_foreign` foreign key (`account_id`) references `chart_of_accounts` (`id`) on delete cascade;
alter table `cash_till_links` add constraint `cash_till_links_cash_account_id_foreign` foreign key (`cash_account_id`) references `cash_accounts` (`id`) on delete cascade;
alter table `cash_till_links` add primary key `cash_till_links_account_id_primary`(`account_id`);
alter table `cash_till_links` add unique `cash_till_links_cash_account_id_unique`(`cash_account_id`);
create table `bank_account_links` (`account_id` bigint unsigned not null, `bank_account_id` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `bank_account_links` add constraint `bank_account_links_account_id_foreign` foreign key (`account_id`) references `chart_of_accounts` (`id`) on delete cascade;
alter table `bank_account_links` add constraint `bank_account_links_bank_account_id_foreign` foreign key (`bank_account_id`) references `bank_accounts` (`id`) on delete cascade;
alter table `bank_account_links` add primary key `bank_account_links_account_id_primary`(`account_id`);
alter table `bank_account_links` add unique `bank_account_links_bank_account_id_unique`(`bank_account_id`);

-- --------------------------------------------------------
-- Migration: 2026_08_01_000009_add_account_id_foreign_key_to_expense_categories.php
-- --------------------------------------------------------
alter table `expense_categories` add constraint `expense_categories_account_id_foreign` foreign key (`account_id`) references `chart_of_accounts` (`id`) on delete set null;

-- --------------------------------------------------------
-- Migration: 2026_08_01_000010_create_vendors_table.php
-- --------------------------------------------------------
create table `vendors` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(191) not null, `code` varchar(32) not null, `contact_person` varchar(191) null, `phone` varchar(32) null, `email` varchar(190) null, `address` text null, `tax_no` varchar(64) null, `status` varchar(16) not null default 'Active', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `vendors` add unique `vendors_code_unique`(`code`);

-- --------------------------------------------------------
-- Migration: 2026_08_01_000011_create_expenses_and_payment_vouchers_tables.php
-- --------------------------------------------------------
create table `expenses` (`id` bigint unsigned not null auto_increment primary key, `code` varchar(32) not null, `branch_id` bigint unsigned not null, `expense_category_id` bigint unsigned not null, `vendor_id` bigint unsigned null, `amount` decimal(15, 2) not null, `expense_date` date not null, `description` varchar(255) null, `status` varchar(16) not null default 'Pending', `requested_by` bigint unsigned null, `approved_by` bigint unsigned null, `approved_at` datetime null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `expenses` add constraint `expenses_branch_id_foreign` foreign key (`branch_id`) references `branches` (`id`) on delete restrict;
alter table `expenses` add constraint `expenses_expense_category_id_foreign` foreign key (`expense_category_id`) references `expense_categories` (`id`) on delete restrict;
alter table `expenses` add constraint `expenses_vendor_id_foreign` foreign key (`vendor_id`) references `vendors` (`id`) on delete set null;
alter table `expenses` add constraint `expenses_requested_by_foreign` foreign key (`requested_by`) references `users` (`id`) on delete set null;
alter table `expenses` add constraint `expenses_approved_by_foreign` foreign key (`approved_by`) references `users` (`id`) on delete set null;
alter table `expenses` add index `expenses_status_index`(`status`);
alter table `expenses` add unique `expenses_code_unique`(`code`);
create table `payment_vouchers` (`id` bigint unsigned not null auto_increment primary key, `code` varchar(32) not null, `expense_id` bigint unsigned null, `vendor_id` bigint unsigned null, `amount` decimal(15, 2) not null, `payment_method` varchar(16) not null default 'Cash', `paid_at` datetime not null, `paid_by` bigint unsigned null, `cash_account_id` bigint unsigned null, `bank_account_id` bigint unsigned null, `cheque_no` varchar(32) null, `status` varchar(16) not null default 'Issued', `journal_entry_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `payment_vouchers` add constraint `payment_vouchers_expense_id_foreign` foreign key (`expense_id`) references `expenses` (`id`) on delete set null;
alter table `payment_vouchers` add constraint `payment_vouchers_vendor_id_foreign` foreign key (`vendor_id`) references `vendors` (`id`) on delete set null;
alter table `payment_vouchers` add constraint `payment_vouchers_paid_by_foreign` foreign key (`paid_by`) references `users` (`id`) on delete set null;
alter table `payment_vouchers` add constraint `payment_vouchers_cash_account_id_foreign` foreign key (`cash_account_id`) references `cash_accounts` (`id`) on delete restrict;
alter table `payment_vouchers` add constraint `payment_vouchers_bank_account_id_foreign` foreign key (`bank_account_id`) references `bank_accounts` (`id`) on delete restrict;
alter table `payment_vouchers` add constraint `payment_vouchers_journal_entry_id_foreign` foreign key (`journal_entry_id`) references `journal_entries` (`id`) on delete set null;
alter table `payment_vouchers` add index `payment_vouchers_paid_at_index`(`paid_at`);
alter table `payment_vouchers` add unique `payment_vouchers_code_unique`(`code`);

-- --------------------------------------------------------
-- Migration: 2026_08_01_000012_seed_chart_of_accounts.php
-- --------------------------------------------------------
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (1010, 'Cash in Hand', 'Asset', 'Debit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (1020, 'Bank Accounts', 'Asset', 'Debit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (1030, 'Cheques / Undeposited Funds', 'Asset', 'Debit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (1100, 'Loan Principal Outstanding', 'Asset', 'Debit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (1150, 'Interest Receivable', 'Asset', 'Debit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (1190, 'Provision for Loan Losses', 'Asset', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (1300, 'Fixed Assets', 'Asset', 'Debit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (2010, 'Customer Fixed Deposits Payable', 'Liability', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (2020, 'Interest Payable on FDs', 'Liability', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (2300, 'Accrued Expenses', 'Liability', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (3010, 'Share Capital', 'Equity', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (3020, 'Retained Earnings', 'Equity', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (3030, 'Current Year Profit / Loss', 'Equity', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (4010, 'Interest Income - Loans', 'Income', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (4020, 'Loan Charges / Fees', 'Income', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (4030, 'Penalty Income', 'Income', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (4900, 'Other Income', 'Income', 'Credit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (5010, 'Operating Expenses', 'Expense', 'Debit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (5100, 'Interest Expense - FDs', 'Expense', 'Debit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (5200, 'Staff Salaries & Commission', 'Expense', 'Debit', '', '1', 'Active', NOW(), NOW());
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (5300, 'Provision for Loan Losses', 'Expense', 'Debit', '', '1', 'Active', NOW(), NOW());

-- --------------------------------------------------------
-- Migration: 2026_08_01_000013_add_unapplied_loan_receipts_account.php
-- --------------------------------------------------------
insert into `chart_of_accounts` (`code`, `name`, `type`, `normal_balance`, `parent_id`, `is_system`, `status`, `created_at`, `updated_at`) values (2100, 'Unapplied Loan Receipts', 'Liability', 'Credit', '', '1', 'Active', NOW(), NOW());

-- --------------------------------------------------------
-- Migration: 2026_08_01_000014_make_sales_executive_commissions_recurring.php
-- --------------------------------------------------------
alter table `sales_executive_commissions` add `cycle_no` smallint unsigned not null default '1' after `loan_type_id`, add `from_installment` smallint unsigned not null default '0' after `cycle_no`;
alter table `sales_executive_commissions` add index `sales_executive_commissions_loan_id_index`(`loan_id`);

-- --------------------------------------------------------
-- Migration: 2026_08_01_000015_add_default_charge_rate_percent_to_loan_types_table.php
-- --------------------------------------------------------
alter table `loan_types` add `default_charge_rate_percent` decimal(6, 3) not null default '0' after `grace_period_days`;
update `loan_types` set `default_charge_rate_percent` = penalty_rate_percent;

-- Note: In order for artisan on your live server to know that these have been applied,
-- it is still highly recommended to update your `migrations` table or simply run `php artisan migrate` 
-- directly there if you have terminal access. 
