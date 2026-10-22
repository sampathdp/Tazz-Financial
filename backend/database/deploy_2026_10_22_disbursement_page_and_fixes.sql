-- Live server update, 2026-10-22. Safe to re-run (every step checks first). Take a DB backup before running.
-- 1) Repair fd_withdrawals.settled_credit_facility_id (migration 2026_10_13_000004 only half applied)
-- 2) Switch off the daily cash session requirement (screen removed)
-- 3) Add the "Loan Disbursement" permission module and copy access from Active Loans
-- 4) Record the new migration so `php artisan migrate` does not try to run it again

-- 1) ---------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fd_withdrawals' AND COLUMN_NAME = 'settled_credit_facility_id');
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `fd_withdrawals` ADD COLUMN `settled_credit_facility_id` BIGINT UNSIGNED NULL AFTER `closed_at`, ADD CONSTRAINT `fd_withdrawals_settled_credit_facility_id_foreign` FOREIGN KEY (`settled_credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE SET NULL',
  'SELECT ''settled_credit_facility_id already present'' AS info');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) -- -------------------------------------------------------------------
UPDATE `finance_settings` SET `value` = '0' WHERE `key` = 'require_cash_session';

-- 3) ---------------------------------------------------------------------
INSERT INTO `permissions` (`module`, `action`, `created_at`)
SELECT 'Loan Disbursement', a.action, NOW()
FROM (SELECT 'View' AS action UNION ALL SELECT 'Create' UNION ALL SELECT 'Edit'
      UNION ALL SELECT 'Delete' UNION ALL SELECT 'Approve' UNION ALL SELECT 'Export') a
WHERE NOT EXISTS (SELECT 1 FROM `permissions` p WHERE p.module = 'Loan Disbursement' AND p.action = a.action);

-- Roles that could view / request / disburse before keep that ability on the new page.
-- Review afterwards in Role Management: give Finance View + Approve (and Create for requesters).
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT rp.role_id, np.id, NOW()
FROM `role_permissions` rp
JOIN `permissions` op ON op.id = rp.permission_id AND op.module = 'Active Loans' AND op.action IN ('View', 'Create', 'Approve')
JOIN `permissions` np ON np.module = 'Loan Disbursement' AND np.action = op.action
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` x WHERE x.role_id = rp.role_id AND x.permission_id = np.id);

-- 4) ---------------------------------------------------------------------
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_22_000001_disable_require_cash_session', (SELECT COALESCE(MAX(batch), 0) FROM (SELECT batch FROM `migrations`) m)
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_22_000001_disable_require_cash_session');
