/* Frontend permission helpers — read the user.permissions[] surfaced by /me.
   The server already merges role grid + per-user overrides into that flat
   list, so no client-side merge logic is needed here. */
import type { ReactNode } from 'react'
import type { AuthUser } from '../api'

export type Action = 'View' | 'Create' | 'Edit' | 'Delete' | 'Approve' | 'Export'

/** Admin-only features (e.g. editing a facility) — mirrors the backend `admin` middleware. */
/** True for the built-in 'admin' and 'super_admin' system roles. */
export const isAdminRole = (slug?: string | null): boolean => slug === 'admin' || slug === 'super_admin'
export const isAdmin = (user: AuthUser | null | undefined): boolean => isAdminRole(user?.role)

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
  'finance/dashboard': 'Finance Dashboard',
  'finance/requests': 'Finance Requests',
  'finance/transfers': 'Finance Transfers',
  'finance/floats': 'Cash Floats',
  'finance/settings': 'Finance Settings',
  'finance/transactions': 'Cash Transactions',
  'finance/audit': 'Finance Audit',
  'finance/reports': 'Finance Reports',
  'loan/apps': 'Loan Applications',
  'loan/fd-loan': 'Loan Applications',
  'loan/active': 'Active Loans',
  'loan/disbursement': 'Loan Disbursement',
  'loan/overdue': 'Overdue Loans',
  'loan/provisions': 'Loan Provisioning',
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
  'rep/cust-blacklisted': 'Customer Reports',
  'rep/cust-credit': 'Customer Reports',
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
  'rep/loans-commissions': 'Sales Executive Commissions',
  'rep/fd-commissions': 'Fixed Deposits',
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
  'cash/recon': 'Cash Reconciliation',
  'bank/accounts': 'Bank Accounts',
  'bank/txns': 'Bank Transactions',
  'bank/recon': 'Bank Reconciliation',
  'masters/expense-categories': 'Masters',
  'masters/banks': 'Masters',
  'acc/coa': 'Chart of Accounts',
  'acc/journal': 'Accounting Reports',
  'acc/mje': 'Manual Journal Entries',
  'acc/gl': 'Accounting Reports',
  'acc/tb': 'Accounting Reports',
  'acc/pl': 'Accounting Reports',
  'acc/bs': 'Accounting Reports',
  'acc/cf': 'Accounting Reports',
  'acc/ib': 'Accounting Reports',
  'acc/drift': 'Accounting Reports',
  'exp/vendors': 'Vendors',
  'exp/staff': 'Staff',
  'exp/expenses': 'Expenses',
  'exp/vouchers': 'Payment Vouchers',
  'exp/petty-cash': 'Petty Cash',
  'exp/fixed-assets': 'Fixed Assets',
  'fund/dashboard': 'Funding Reports',
  'fund/products': 'Funding Products',
  'fund/deposits': 'Deposits',
  'fund/fds': 'Fixed Deposits',
  'fund/borrowings': 'Borrowings',
  'fund/branch-funding': 'Branch Funding',
  'fund/deposit-payment': 'Fixed Deposits',
  'fund/premature-withdrawal': 'Fixed Deposits',
  'fund/interest-due': 'Fixed Deposits',
  'fund/reports': 'Funding Reports',
  'rep/fund-active': 'Funding Reports',
  'rep/fund-maturing': 'Funding Reports',
  'rep/fund-matured': 'Funding Reports',
  'rep/fund-monthly-interest': 'Funding Reports',
  'rep/fund-interest-paid': 'Funding Reports',
  'rep/fund-deposits': 'Funding Reports',
  'rep/fund-customer': 'Funding Reports',
  'rep/fund-renewals': 'Funding Reports',
  'rep/fund-enhancements': 'Funding Reports',
  'rep/fund-withdrawals': 'Funding Reports',
  'fund/savings-products': 'Savings Products',
  'fund/savings': 'Savings Accounts',
  'fund/savings-collection': 'Savings Collection',
  'fund/savings-interest': 'Savings Interest',
  'rep/savings': 'Savings Reports',
  'subs/list': 'Subscriptions',
}

export function canAccessRoute(user: AuthUser | null | undefined, routeId: string): boolean {
  if (!user) return false
  if (routeId === 'profile') return true // every signed-in user has a profile
  const mod = ROUTE_MODULE[routeId]
  // Unmapped route = either a module that isn't built yet ("soon" nav items,
  // shown to admins as a preview) or one deliberately kept out of the
  // permission system because it must always be admin-only regardless of
  // role/user overrides (e.g. 'loan/adjustments'). Either way: admins can
  // reach it, everyone else is denied and never sees it in the sidebar
  // (see Shell.tsx's visibleNav).
  if (!mod) return isAdminRole(user.role)
  return userCan(user, mod, 'View')
}
