-- ============================================================================
-- Manual equivalent of migrations 2026_08_30_000001 .. 000004
-- Run ONCE on the live database in place of `php artisan migrate`.
--
--   mysql -u <user> -p <database> < 2026_08_30_seed_approval_types.sql
--
-- Seeds 4 approval_types rows + backfills a PendingApproval approval_requests
-- row for every subject still sitting in a pending state, then records the 4
-- migrations so `php artisan migrate` will not try to run them again.
--
-- Safe to re-run: every statement is guarded (approval_types.code /
-- approval_requests(type,subject) / migrations.migration).
-- MySQL / MariaDB syntax. Wrap in a transaction if your engine is InnoDB.
-- ============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. loan_write_off  (no backfill)
-- ---------------------------------------------------------------------------
INSERT INTO approval_types (code, name, description, is_system, required_approvals, created_at, updated_at)
SELECT 'loan_write_off',
       'Loan Write-off',
       'Approving a request to write off an active loan as bad debt.',
       1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM approval_types WHERE code = 'loan_write_off');

-- ---------------------------------------------------------------------------
-- 2. payment_settlement  + backfill pending full-settlement payments
-- ---------------------------------------------------------------------------
INSERT INTO approval_types (code, name, description, is_system, required_approvals, created_at, updated_at)
SELECT 'payment_settlement',
       'Loan Payment Settlement',
       'Approving a full-settlement loan payment before it is applied to the schedule.',
       1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM approval_types WHERE code = 'payment_settlement');

INSERT INTO approval_requests
    (approval_type_id, subject_id, requested_by, requested_at, reason, required_approvals, status, created_at, updated_at)
SELECT (SELECT id FROM approval_types WHERE code = 'payment_settlement'),
       fp.id, fp.received_by, COALESCE(fp.created_at, NOW()), fp.notes, 1, 'PendingApproval', NOW(), NOW()
FROM facility_payments fp
WHERE fp.payment_type = 'Settlement'
  AND fp.approval_status = 'PendingApproval'
  AND NOT EXISTS (
      SELECT 1 FROM approval_requests ar
      WHERE ar.approval_type_id = (SELECT id FROM approval_types WHERE code = 'payment_settlement')
        AND ar.subject_id = fp.id
  );

-- ---------------------------------------------------------------------------
-- 3. cash_transfer  + backfill pending transfers
-- ---------------------------------------------------------------------------
INSERT INTO approval_types (code, name, description, is_system, required_approvals, created_at, updated_at)
SELECT 'cash_transfer',
       'Cash Transfer',
       'Approving a cash transfer between two cash accounts / tills.',
       1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM approval_types WHERE code = 'cash_transfer');

INSERT INTO approval_requests
    (approval_type_id, subject_id, requested_by, requested_at, reason, required_approvals, status, created_at, updated_at)
SELECT (SELECT id FROM approval_types WHERE code = 'cash_transfer'),
       ct.id, ct.requested_by, COALESCE(ct.created_at, NOW()), ct.description, 1, 'PendingApproval', NOW(), NOW()
FROM cash_transfers ct
WHERE ct.status = 'Pending'
  AND NOT EXISTS (
      SELECT 1 FROM approval_requests ar
      WHERE ar.approval_type_id = (SELECT id FROM approval_types WHERE code = 'cash_transfer')
        AND ar.subject_id = ct.id
  );

-- ---------------------------------------------------------------------------
-- 4. expense_approval  + backfill pending expenses
-- ---------------------------------------------------------------------------
INSERT INTO approval_types (code, name, description, is_system, required_approvals, created_at, updated_at)
SELECT 'expense_approval',
       'Expense Approval',
       'Approving a pending expense before it can be paid.',
       1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM approval_types WHERE code = 'expense_approval');

INSERT INTO approval_requests
    (approval_type_id, subject_id, requested_by, requested_at, reason, required_approvals, status, created_at, updated_at)
SELECT (SELECT id FROM approval_types WHERE code = 'expense_approval'),
       e.id, e.requested_by, COALESCE(e.created_at, NOW()), e.description, 1, 'PendingApproval', NOW(), NOW()
FROM expenses e
WHERE e.status = 'Pending'
  AND NOT EXISTS (
      SELECT 1 FROM approval_requests ar
      WHERE ar.approval_type_id = (SELECT id FROM approval_types WHERE code = 'expense_approval')
        AND ar.subject_id = e.id
  );

-- ---------------------------------------------------------------------------
-- Record the migrations so artisan does not re-run them
-- ---------------------------------------------------------------------------
INSERT INTO migrations (migration, batch)
SELECT v.migration,
       (SELECT b FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS b FROM migrations) x)
FROM (
             SELECT '2026_08_30_000001_seed_loan_write_off_approval_type'   AS migration
   UNION ALL SELECT '2026_08_30_000002_seed_payment_settlement_approval_type'
   UNION ALL SELECT '2026_08_30_000003_seed_cash_transfer_approval_type'
   UNION ALL SELECT '2026_08_30_000004_seed_expense_approval_type'
) v
WHERE NOT EXISTS (SELECT 1 FROM migrations m WHERE m.migration = v.migration);

COMMIT;

-- ---------------------------------------------------------------------------
-- Verify
-- ---------------------------------------------------------------------------
-- SELECT code, required_approvals FROM approval_types
--   WHERE code IN ('loan_write_off','payment_settlement','cash_transfer','expense_approval');
-- SELECT t.code, COUNT(*) FROM approval_requests r
--   JOIN approval_types t ON t.id = r.approval_type_id
--   WHERE r.status = 'PendingApproval' GROUP BY t.code;
