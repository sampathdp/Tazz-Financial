-- Live DB update for current uncommitted backend changes (as of 2026-08-07).
-- Covers: 2 pending migrations + new "Loan Calculator" permission module.
-- Idempotent guards included — safe to re-run if a step partially applied.
-- Run as one transaction.

START TRANSACTION;

-- 1) Migration: 2026_08_07_000001_add_allow_method_choice_to_funding_products
ALTER TABLE `funding_products`
  ADD COLUMN `allow_method_choice` TINYINT(1) NOT NULL DEFAULT 0 AFTER `interest_method`;

-- 2) Migration: 2026_08_07_000002_add_effective_rates_to_loan_facility_details
ALTER TABLE `loan_facility_details`
  ADD COLUMN `effective_monthly_rate` DECIMAL(10,8) NULL AFTER `interest_method`,
  ADD COLUMN `effective_annual_rate` DECIMAL(10,8) NULL AFTER `effective_monthly_rate`;

-- 3) Record both migrations as applied, so `php artisan migrate` never re-runs
--    (or conflicts with) what this script just did.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_07_000001_add_allow_method_choice_to_funding_products', ((SELECT m.max_batch FROM (SELECT COALESCE(MAX(batch),0)+1 AS max_batch FROM `migrations`) m))
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_08_07_000001_add_allow_method_choice_to_funding_products');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_07_000002_add_effective_rates_to_loan_facility_details', ((SELECT m.max_batch FROM (SELECT COALESCE(MAX(batch),0)+1 AS max_batch FROM `migrations`) m))
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_08_07_000002_add_effective_rates_to_loan_facility_details');

-- 4) New permission catalog module: "Loan Calculator" (Permissions::MODULES).
--    App\Support\Permissions::permissionIdMap() would create these lazily the
--    next time any role's permission grid is saved — inserting them directly
--    here makes the module show up in Role Management immediately, with all
--    grants defaulted to off until an admin toggles them.
INSERT INTO `permissions` (`module`, `action`, `created_at`)
SELECT 'Loan Calculator', a.action, NOW()
FROM (
  SELECT 'View' AS action UNION ALL SELECT 'Create' UNION ALL SELECT 'Edit'
  UNION ALL SELECT 'Delete' UNION ALL SELECT 'Approve' UNION ALL SELECT 'Export'
) a
WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` p WHERE p.module = 'Loan Calculator' AND p.action = a.action
);

-- 5) OPTIONAL — mirrors the seed-role grants added in DatabaseSeeder.php
--    ('Loan Calculator' => View for Branch Manager / Staff / Marketing
--    Executive). Only applies if a role with that exact slug exists live;
--    no-ops otherwise. Skip/comment out if live roles were customized and
--    you'd rather grant this by hand in Role Management.
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT r.id, p.id, NOW()
FROM `roles` r
JOIN `permissions` p ON p.module = 'Loan Calculator' AND p.action = 'View'
WHERE r.slug IN ('branch_manager', 'staff', 'marketing_executive')
  AND NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

COMMIT;
