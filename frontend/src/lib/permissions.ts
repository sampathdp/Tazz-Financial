/* Frontend permission helpers — read the user.permissions[] surfaced by /me.
   The server already merges role grid + per-user overrides into that flat
   list, so no client-side merge logic is needed here. */
import type { ReactNode } from 'react'
import type { AuthUser } from '../api'

export type Action = 'View' | 'Create' | 'Edit' | 'Delete' | 'Approve' | 'Export'

export function userCan(user: AuthUser | null | undefined, module: string, action: Action = 'View'): boolean {
  if (!user) return false
  const list = user.permissions || []
  return list.includes(`${module}:${action}`)
}

/** Gate for page/button-level UI: renders children only if the user has the
 *  given module+action. This is the standard pattern every screen uses to
 *  hide/disable Add/Edit/Delete/Approve/Export buttons. */
export function Can({ user, module, action = 'View', children }: {
  user: AuthUser | null | undefined
  module: string
  action?: Action
  children: ReactNode
}) {
  return userCan(user, module, action) ? children : null
}

/** Map sidebar route ids to the permission module they correspond to. */
export const ROUTE_MODULE: Record<string, string> = {
  dash: 'Dashboard',
  customers: 'Customer Management',
  'loan/types': 'Loan Types',
  'loan/apps': 'Loan Applications',
  'loan/active': 'Active Loans',
  'loan/overdue': 'Overdue Loans',
  'loan/payments': 'Loan Payments',
  'loan/doc-tracking': 'Document Tracking',
  // 'loan/adjustments' is intentionally NOT mapped here — canAccessRoute() below
  // falls back to an admin-only check for any route id absent from this map.
  'ad/branches': 'Branch Management',
  'ad/roles': 'Role Management',
  'ad/users': 'User Management',
  'ad/perm': 'User Permission',
  'ad/company': 'Company Profile',
  'bank/accounts': 'Bank Accounts',
  'bank/txns': 'Bank Transactions',
  'bank/recon': 'Bank Reconciliation',
  'masters/expense-categories': 'Masters',
  'masters/bank-account-types': 'Masters',
  'fund/dashboard': 'Funding Reports',
  'fund/products': 'Funding Products',
  'fund/deposits': 'Deposits',
  'fund/fds': 'Fixed Deposits',
  'fund/reports': 'Funding Reports',
}

export function canAccessRoute(user: AuthUser | null | undefined, routeId: string): boolean {
  if (!user) return false
  const mod = ROUTE_MODULE[routeId]
  // Unmapped route = either a module that isn't built yet ("soon" nav items,
  // shown to admins as a preview) or one deliberately kept out of the
  // permission system because it must always be admin-only regardless of
  // role/user overrides (e.g. 'loan/adjustments'). Either way: admins can
  // reach it, everyone else is denied and never sees it in the sidebar
  // (see Shell.tsx's visibleNav).
  if (!mod) return user.role === 'admin'
  return userCan(user, mod, 'View')
}
