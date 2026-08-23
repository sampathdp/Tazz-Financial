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

/** Whether the user may approve/reject a given approval_types code (e.g.
 *  'loan_cancel', 'payment_void') — separate from the module permission grid,
 *  see ApprovalType::isAuthorized() on the backend. */
export function canApprove(user: AuthUser | null | undefined, code: string): boolean {
  return !!user?.approvalTypes?.includes(code)
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
  'finance/calculator': 'Loan Calculator',
  'loan/apps': 'Loan Applications',
  'loan/active': 'Active Loans',
  'loan/overdue': 'Overdue Loans',
  'rep/loans-summary': 'Loan Reports',
  'rep/loans-par': 'Loan Reports',
  'rep/loans-arrears': 'Loan Reports',
  'rep/loans-disbursement': 'Loan Reports',
  'rep/loans-closed': 'Loan Reports',
  'rep/loans-detail': 'Loan Reports',
  'rep/coll-daily': 'Collection Reports',
  'rep/coll-summary': 'Collection Reports',
  'rep/coll-officers': 'Collection Reports',
  'rep/coll-trend': 'Collection Reports',
  'rep/cust-summary': 'Customer Reports',
  'rep/cust-activity': 'Customer Reports',
  'rep/inv-summary': 'Investor Reports',
  'rep/inv-directory': 'Investor Reports',
  'rep/cb-position': 'Cash & Bank Reports',
  'rep/cb-cashbook': 'Cash & Bank Reports',
  'rep/cb-bank': 'Cash & Bank Reports',
  'rep/exp-summary': 'Expense Reports',
  'rep/exp-list': 'Expense Reports',
  'rep/exp-vendor': 'Expense Reports',
  'loan/payments': 'Loan Payments',
  'loan/doc-tracking': 'Document Tracking',
  'loan/commissions': 'Sales Executive Commissions',
  // 'loan/adjustments', 'ad/approvals', and 'ad/approval-types' are intentionally
  // NOT mapped here — canAccessRoute() below falls back to an admin-only check
  // for any route id absent from this map.
  'sd/types': 'Speed Draft Types',
  'sd/drafts': 'Speed Drafts',
  'sd/payments': 'Speed Draft Payments',
  'pawn/types': 'Pawning Types',
  'pawn/tickets': 'Pawnings',
  'pawn/auctions': 'Pawning Auctions',
  'ad/branches': 'Branch Management',
  'ad/roles': 'Role Management',
  'ad/users': 'User Management',
  'ad/perm': 'User Permission',
  'ad/company': 'Company Profile',
  'cash/book': 'Cashbook',
  'cash/transfers': 'Cash Transfers',
  'cash/recon': 'Cash Reconciliation',
  'bank/accounts': 'Bank Accounts',
  'bank/txns': 'Bank Transactions',
  'bank/recon': 'Bank Reconciliation',
  'masters/expense-categories': 'Masters',
  'masters/banks': 'Masters',
  'acc/coa': 'Chart of Accounts',
  'acc/journal': 'Accounting Reports',
  'acc/gl': 'Accounting Reports',
  'acc/tb': 'Accounting Reports',
  'acc/pl': 'Accounting Reports',
  'acc/bs': 'Accounting Reports',
  'acc/cf': 'Accounting Reports',
  'acc/drift': 'Accounting Reports',
  'exp/vendors': 'Vendors',
  'exp/expenses': 'Expenses',
  'exp/vouchers': 'Payment Vouchers',
  'exp/petty-cash': 'Petty Cash',
  'fund/dashboard': 'Funding Reports',
  'fund/products': 'Funding Products',
  'fund/deposits': 'Deposits',
  'fund/fds': 'Fixed Deposits',
  'fund/deposit-payment': 'Fixed Deposits',
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
