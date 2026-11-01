-- READ-ONLY. Security audit Phase 2, item 5. Run on the live database (phpMyAdmin / mysql client) and send back the output.
-- Contains no passwords or tokens. Do not run any INSERT/UPDATE/DELETE from this file.

-- 1. Roles and how many active users hold each
SELECT r.id, r.slug, r.name, r.is_system, r.can_view_all_branches, COUNT(u.id) AS users
FROM roles r LEFT JOIN users u ON u.role_id = r.id AND u.status = 'Active'
GROUP BY r.id ORDER BY r.id;

-- 2. Per-role count of permissions, plus the security-sensitive ones
SELECT r.slug,
       COUNT(rp.permission_id) AS permissions,
       SUM(p.module = 'Role Management' AND p.action = 'Edit')  AS role_edit,
       SUM(p.module = 'User Permission' AND p.action = 'Edit')  AS user_permission_edit,
       SUM(p.module = 'User Management' AND p.action IN ('Create','Edit','Delete')) AS user_write
FROM roles r
LEFT JOIN role_permissions rp ON rp.role_id = r.id
LEFT JOIN permissions p ON p.id = rp.permission_id
GROUP BY r.id ORDER BY r.id;

-- 3. Every non-admin role that can change roles, user permissions or users (privilege-escalation surface)
SELECT r.slug, p.module, p.action
FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug <> 'admin'
  AND ((p.module = 'Role Management' AND p.action = 'Edit')
    OR (p.module = 'User Permission' AND p.action = 'Edit')
    OR (p.module = 'User Management' AND p.action IN ('Create','Edit','Delete')))
ORDER BY r.slug, p.module, p.action;

-- 4. Per-user overrides (names only, no credentials)
SELECT u.id, u.name, r.slug AS role, p.module, p.action, o.allowed
FROM user_permission_overrides o
JOIN users u ON u.id = o.user_id JOIN roles r ON r.id = u.role_id JOIN permissions p ON p.id = o.permission_id
ORDER BY u.id, p.module, p.action;

-- 5. Active users: role, branch, and whether the role sees all branches
SELECT u.id, u.name, r.slug AS role, r.can_view_all_branches, u.branch_id, u.status
FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.status, r.slug, u.id;

-- 6. Who is an approver for each approval type
SELECT * FROM approval_types;
SELECT * FROM approval_tiers;
