-- ============================================================================
-- LIVE SERVER UPDATE - 2026-10-22 (run once, safe to re-run: every step checks first)
-- Take a database backup before running. Deploy the new backend + frontend build as well.
--
--  1) Repair fd_withdrawals.settled_credit_facility_id (half-applied migration)
--  2) Switch off the daily cash session requirement (feature removed)
--  3) Add the "Loan Disbursement" permission module + copy access from Active Loans
--  4) Expense categories: parent_id column, 16 main categories, 56 sub-categories
--  5) Record the new migrations so `php artisan migrate` does not run them again
-- ============================================================================

-- 1) ---------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fd_withdrawals' AND COLUMN_NAME = 'settled_credit_facility_id');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `fd_withdrawals` ADD COLUMN `settled_credit_facility_id` BIGINT UNSIGNED NULL AFTER `closed_at`, ADD CONSTRAINT `fd_withdrawals_settled_credit_facility_id_foreign` FOREIGN KEY (`settled_credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE SET NULL',
  'SELECT ''settled_credit_facility_id already present'' AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) ---------------------------------------------------------------------------
UPDATE `finance_settings` SET `value` = '0' WHERE `key` = 'require_cash_session';

-- 3) ---------------------------------------------------------------------------
INSERT INTO `permissions` (`module`, `action`, `created_at`)
SELECT 'Loan Disbursement', a.action, NOW()
FROM (SELECT 'View' AS action UNION ALL SELECT 'Create' UNION ALL SELECT 'Edit'
      UNION ALL SELECT 'Delete' UNION ALL SELECT 'Approve' UNION ALL SELECT 'Export') a
WHERE NOT EXISTS (SELECT 1 FROM `permissions` p WHERE p.module = 'Loan Disbursement' AND p.action = a.action);

-- Roles that could view / request / disburse before keep that ability on the new page.
-- Afterwards give Finance View + Approve (and Create for requesters) in Role Management.
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT rp.role_id, np.id, NOW()
FROM `role_permissions` rp
JOIN `permissions` op ON op.id = rp.permission_id AND op.module = 'Active Loans' AND op.action IN ('View', 'Create', 'Approve')
JOIN `permissions` np ON np.module = 'Loan Disbursement' AND np.action = op.action
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` x WHERE x.role_id = rp.role_id AND x.permission_id = np.id);

-- 4) ---------------------------------------------------------------------------
SET @has_parent := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expense_categories' AND COLUMN_NAME = 'parent_id');
SET @sql := IF(@has_parent = 0,
  'ALTER TABLE `expense_categories` ADD COLUMN `parent_id` BIGINT UNSIGNED NULL AFTER `id`, ADD CONSTRAINT `expense_categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL',
  'SELECT ''parent_id already present'' AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Main categories
INSERT INTO `expense_categories` (`name`, `code`, `description`, `chart_of_account_id`, `status`, `sort_order`, `created_at`, `updated_at`)
SELECT m.name, m.code, 'Main category', (SELECT id FROM `chart_of_accounts` WHERE code = m.gl LIMIT 1), 'Active', m.sort, NOW(), NOW()
FROM (
  SELECT 'Salaries & Staff Costs' AS name, 'MAIN-STAFF' AS code, '5200' AS gl, 10 AS sort UNION ALL
  SELECT 'Commissions & Incentives' AS name, 'MAIN-COMM' AS code, '5200' AS gl, 20 AS sort UNION ALL
  SELECT 'Rent & Utilities' AS name, 'MAIN-RENT' AS code, '5010' AS gl, 30 AS sort UNION ALL
  SELECT 'Office & Administration' AS name, 'MAIN-OFFICE' AS code, '5010' AS gl, 40 AS sort UNION ALL
  SELECT 'Marketing & Advertising' AS name, 'MAIN-MKT' AS code, '5010' AS gl, 50 AS sort UNION ALL
  SELECT 'Travel & Vehicle' AS name, 'MAIN-TRAVEL' AS code, '5010' AS gl, 60 AS sort UNION ALL
  SELECT 'Professional & Legal Fees' AS name, 'MAIN-PROF' AS code, '5010' AS gl, 70 AS sort UNION ALL
  SELECT 'Bank & Finance Charges' AS name, 'MAIN-BANK' AS code, '5010' AS gl, 80 AS sort UNION ALL
  SELECT 'Repairs & Maintenance' AS name, 'MAIN-REPAIR' AS code, '5010' AS gl, 90 AS sort UNION ALL
  SELECT 'Insurance' AS name, 'MAIN-INS' AS code, '5010' AS gl, 100 AS sort UNION ALL
  SELECT 'Taxes & Licences' AS name, 'MAIN-TAX' AS code, '5010' AS gl, 110 AS sort UNION ALL
  SELECT 'Miscellaneous' AS name, 'MAIN-MISC' AS code, '5010' AS gl, 120 AS sort UNION ALL
  SELECT 'IT & Communication' AS name, 'MAIN-IT' AS code, '5010' AS gl, 55 AS sort UNION ALL
  SELECT 'Recovery & Collection Costs' AS name, 'MAIN-RECOV' AS code, '5010' AS gl, 75 AS sort UNION ALL
  SELECT 'Depreciation & Amortisation' AS name, 'MAIN-DEPR' AS code, '5010' AS gl, 115 AS sort UNION ALL
  SELECT 'Donations & CSR' AS name, 'MAIN-CSR' AS code, '5010' AS gl, 118 AS sort
) m
WHERE NOT EXISTS (SELECT 1 FROM `expense_categories` e WHERE e.code = m.code);

-- Existing categories go under their main category
UPDATE `expense_categories` c JOIN `expense_categories` p ON p.code = 'MAIN-STAFF' SET c.parent_id = p.id WHERE c.name = 'Salary' AND c.parent_id IS NULL AND c.code NOT LIKE 'MAIN-%';
UPDATE `expense_categories` c JOIN `expense_categories` p ON p.code = 'MAIN-STAFF' SET c.parent_id = p.id WHERE c.name = 'Staff allowance' AND c.parent_id IS NULL AND c.code NOT LIKE 'MAIN-%';
UPDATE `expense_categories` c JOIN `expense_categories` p ON p.code = 'MAIN-COMM' SET c.parent_id = p.id WHERE c.name = 'Incentive FD' AND c.parent_id IS NULL AND c.code NOT LIKE 'MAIN-%';
UPDATE `expense_categories` c JOIN `expense_categories` p ON p.code = 'MAIN-COMM' SET c.parent_id = p.id WHERE c.name = 'Loan Incentive' AND c.parent_id IS NULL AND c.code NOT LIKE 'MAIN-%';

-- Sub-categories (inherit the main category's GL account unless one is given)
INSERT INTO `expense_categories` (`parent_id`, `name`, `code`, `description`, `chart_of_account_id`, `status`, `sort_order`, `created_at`, `updated_at`)
SELECT p.id, s.name, s.code, NULL, COALESCE((SELECT id FROM `chart_of_accounts` WHERE code = s.gl LIMIT 1), p.chart_of_account_id), 'Active', s.sort, NOW(), NOW()
FROM (
  SELECT 'MAIN-STAFF' AS main_code, 'SUB-EPF' AS code, 'EPF / ETF Contribution' AS name, '5210' AS gl, 11 AS sort UNION ALL
  SELECT 'MAIN-STAFF' AS main_code, 'SUB-BONUS' AS code, 'Bonus' AS name, NULL AS gl, 12 AS sort UNION ALL
  SELECT 'MAIN-STAFF' AS main_code, 'SUB-OT' AS code, 'Overtime' AS name, NULL AS gl, 13 AS sort UNION ALL
  SELECT 'MAIN-STAFF' AS main_code, 'SUB-WELFARE' AS code, 'Staff Welfare & Refreshments' AS name, NULL AS gl, 14 AS sort UNION ALL
  SELECT 'MAIN-STAFF' AS main_code, 'SUB-TRAIN' AS code, 'Training & Development' AS name, NULL AS gl, 15 AS sort UNION ALL
  SELECT 'MAIN-STAFF' AS main_code, 'SUB-UNIFORM' AS code, 'Uniforms' AS name, NULL AS gl, 16 AS sort UNION ALL
  SELECT 'MAIN-COMM' AS main_code, 'SUB-SALESCOMM' AS code, 'Sales Executive Commission' AS name, NULL AS gl, 21 AS sort UNION ALL
  SELECT 'MAIN-RENT' AS main_code, 'SUB-RENT' AS code, 'Office / Branch Rent' AS name, NULL AS gl, 31 AS sort UNION ALL
  SELECT 'MAIN-RENT' AS main_code, 'SUB-ELEC' AS code, 'Electricity' AS name, NULL AS gl, 32 AS sort UNION ALL
  SELECT 'MAIN-RENT' AS main_code, 'SUB-WATER' AS code, 'Water' AS name, NULL AS gl, 33 AS sort UNION ALL
  SELECT 'MAIN-RENT' AS main_code, 'SUB-SECURITY' AS code, 'Security Services' AS name, NULL AS gl, 34 AS sort UNION ALL
  SELECT 'MAIN-OFFICE' AS main_code, 'SUB-STATION' AS code, 'Stationery & Printing' AS name, NULL AS gl, 41 AS sort UNION ALL
  SELECT 'MAIN-OFFICE' AS main_code, 'SUB-POST' AS code, 'Postage & Courier' AS name, NULL AS gl, 42 AS sort UNION ALL
  SELECT 'MAIN-OFFICE' AS main_code, 'SUB-CLEAN' AS code, 'Cleaning & Sanitation' AS name, NULL AS gl, 43 AS sort UNION ALL
  SELECT 'MAIN-OFFICE' AS main_code, 'SUB-SUBS' AS code, 'Subscriptions & Memberships' AS name, NULL AS gl, 44 AS sort UNION ALL
  SELECT 'MAIN-OFFICE' AS main_code, 'SUB-ENTERT' AS code, 'Entertainment & Hospitality' AS name, NULL AS gl, 45 AS sort UNION ALL
  SELECT 'MAIN-MKT' AS main_code, 'SUB-ADS' AS code, 'Advertising' AS name, NULL AS gl, 51 AS sort UNION ALL
  SELECT 'MAIN-MKT' AS main_code, 'SUB-PROMO' AS code, 'Promotions & Events' AS name, NULL AS gl, 52 AS sort UNION ALL
  SELECT 'MAIN-MKT' AS main_code, 'SUB-BRAND' AS code, 'Branding & Signage' AS name, NULL AS gl, 53 AS sort UNION ALL
  SELECT 'MAIN-TRAVEL' AS main_code, 'SUB-FUEL' AS code, 'Fuel' AS name, NULL AS gl, 61 AS sort UNION ALL
  SELECT 'MAIN-TRAVEL' AS main_code, 'SUB-VEHMAINT' AS code, 'Vehicle Maintenance' AS name, NULL AS gl, 62 AS sort UNION ALL
  SELECT 'MAIN-TRAVEL' AS main_code, 'SUB-TRAVEL' AS code, 'Travelling & Accommodation' AS name, NULL AS gl, 63 AS sort UNION ALL
  SELECT 'MAIN-TRAVEL' AS main_code, 'SUB-VEHLIC' AS code, 'Vehicle Licences & Parking' AS name, NULL AS gl, 64 AS sort UNION ALL
  SELECT 'MAIN-PROF' AS main_code, 'SUB-AUDIT' AS code, 'Audit Fees' AS name, NULL AS gl, 71 AS sort UNION ALL
  SELECT 'MAIN-PROF' AS main_code, 'SUB-LEGAL' AS code, 'Legal Fees' AS name, NULL AS gl, 72 AS sort UNION ALL
  SELECT 'MAIN-PROF' AS main_code, 'SUB-CONSULT' AS code, 'Consultancy Fees' AS name, NULL AS gl, 73 AS sort UNION ALL
  SELECT 'MAIN-PROF' AS main_code, 'SUB-VALUATION' AS code, 'Valuation Fees' AS name, NULL AS gl, 74 AS sort UNION ALL
  SELECT 'MAIN-BANK' AS main_code, 'SUB-BANKCHG' AS code, 'Bank Charges' AS name, NULL AS gl, 81 AS sort UNION ALL
  SELECT 'MAIN-BANK' AS main_code, 'SUB-TRANSFEE' AS code, 'Fund Transfer & Cheque Fees' AS name, NULL AS gl, 82 AS sort UNION ALL
  SELECT 'MAIN-BANK' AS main_code, 'SUB-CRIB' AS code, 'CRIB / Credit Bureau Charges' AS name, NULL AS gl, 83 AS sort UNION ALL
  SELECT 'MAIN-REPAIR' AS main_code, 'SUB-BLDG' AS code, 'Building Repairs' AS name, NULL AS gl, 91 AS sort UNION ALL
  SELECT 'MAIN-REPAIR' AS main_code, 'SUB-EQUIP' AS code, 'Equipment Repairs' AS name, NULL AS gl, 92 AS sort UNION ALL
  SELECT 'MAIN-REPAIR' AS main_code, 'SUB-FURN' AS code, 'Furniture & Fittings' AS name, NULL AS gl, 93 AS sort UNION ALL
  SELECT 'MAIN-INS' AS main_code, 'SUB-INSPROP' AS code, 'Property & Fire Insurance' AS name, NULL AS gl, 101 AS sort UNION ALL
  SELECT 'MAIN-INS' AS main_code, 'SUB-INSVEH' AS code, 'Vehicle Insurance' AS name, NULL AS gl, 102 AS sort UNION ALL
  SELECT 'MAIN-INS' AS main_code, 'SUB-INSFID' AS code, 'Fidelity / Staff Insurance' AS name, NULL AS gl, 103 AS sort UNION ALL
  SELECT 'MAIN-TAX' AS main_code, 'SUB-BIZLIC' AS code, 'Business Licences & Registration' AS name, NULL AS gl, 111 AS sort UNION ALL
  SELECT 'MAIN-TAX' AS main_code, 'SUB-STAMP' AS code, 'Stamp Duty' AS name, NULL AS gl, 112 AS sort UNION ALL
  SELECT 'MAIN-TAX' AS main_code, 'SUB-RATES' AS code, 'Local Authority Rates' AS name, NULL AS gl, 113 AS sort UNION ALL
  SELECT 'MAIN-MISC' AS main_code, 'SUB-OTHER' AS code, 'Other Expenses' AS name, NULL AS gl, 121 AS sort UNION ALL
  SELECT 'MAIN-IT' AS main_code, 'SUB-SOFT' AS code, 'Software & Licences' AS name, NULL AS gl, 56 AS sort UNION ALL
  SELECT 'MAIN-IT' AS main_code, 'SUB-NET' AS code, 'Telephone & Internet' AS name, NULL AS gl, 57 AS sort UNION ALL
  SELECT 'MAIN-IT' AS main_code, 'SUB-HARD' AS code, 'IT Hardware & Supplies' AS name, NULL AS gl, 58 AS sort UNION ALL
  SELECT 'MAIN-IT' AS main_code, 'SUB-HOST' AS code, 'Hosting & Cloud Services' AS name, NULL AS gl, 59 AS sort UNION ALL
  SELECT 'MAIN-RECOV' AS main_code, 'SUB-REPO' AS code, 'Repossession Costs' AS name, NULL AS gl, 76 AS sort UNION ALL
  SELECT 'MAIN-RECOV' AS main_code, 'SUB-YARD' AS code, 'Yard & Storage Charges' AS name, NULL AS gl, 77 AS sort UNION ALL
  SELECT 'MAIN-RECOV' AS main_code, 'SUB-FIELD' AS code, 'Field Recovery Expenses' AS name, NULL AS gl, 78 AS sort UNION ALL
  SELECT 'MAIN-RECOV' AS main_code, 'SUB-COURT' AS code, 'Court & Legal Recovery Fees' AS name, NULL AS gl, 79 AS sort UNION ALL
  SELECT 'MAIN-DEPR' AS main_code, 'SUB-DEPRPPE' AS code, 'Depreciation - Property & Equipment' AS name, NULL AS gl, 116 AS sort UNION ALL
  SELECT 'MAIN-DEPR' AS main_code, 'SUB-AMORT' AS code, 'Amortisation - Software' AS name, NULL AS gl, 117 AS sort UNION ALL
  SELECT 'MAIN-CSR' AS main_code, 'SUB-DONATE' AS code, 'Donations' AS name, NULL AS gl, 119 AS sort UNION ALL
  SELECT 'MAIN-CSR' AS main_code, 'SUB-CSR' AS code, 'CSR Activities' AS name, NULL AS gl, 120 AS sort
) s
JOIN `expense_categories` p ON p.code = s.main_code
WHERE NOT EXISTS (SELECT 1 FROM `expense_categories` e WHERE e.code = s.code);

-- 5) ---------------------------------------------------------------------------
INSERT INTO `migrations` (`migration`, `batch`)
SELECT n.migration, (SELECT COALESCE(MAX(batch), 0) FROM (SELECT batch FROM `migrations`) b)
FROM (SELECT '2026_10_22_000001_disable_require_cash_session' AS migration
      UNION ALL SELECT '2026_10_22_000002_add_main_categories_to_expense_categories'
      UNION ALL SELECT '2026_10_22_000003_seed_expense_sub_categories') n
WHERE NOT EXISTS (SELECT 1 FROM `migrations` m WHERE m.migration = n.migration);

-- Check afterwards:
--   SELECT COUNT(*) FROM expense_categories WHERE parent_id IS NULL;      -- mains (16 + any you had)
--   SELECT COUNT(*) FROM expense_categories WHERE parent_id IS NOT NULL;  -- subs
--   SELECT value FROM finance_settings WHERE `key` = 'require_cash_session'; -- 0
