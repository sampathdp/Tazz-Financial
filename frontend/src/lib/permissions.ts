/* Frontend permission helpers — read the user.permissions[] surfaced by /me.
   admin role bypasses all checks. */
import type { AuthUser } from '../api'

export type Action = 'View' | 'Create' | 'Edit' | 'Delete' | 'Approve'

export function userCan(user: AuthUser | null | undefined, module: string, action: Action = 'View'): boolean {
  if (!user) return false
  if (user.role === 'admin') return true
  const list = user.permissions || []
  return list.includes(`${module}:${action}`)
}

/** Map sidebar route ids to the permission module they correspond to. */
export const ROUTE_MODULE: Record<string, string> = {
  dash: 'Dashboard',
  'm/item': 'Item Master',
  'm/customer': 'Customer Master',
  'm/supplier': 'Supplier Master',
  'm/brand': 'Brand Master',
  'm/services': 'Services',
  'm/employee': 'Employee Master',
  'm/payment': 'Payment Master',
  'm/bank': 'Bank Master',
  'm/branch': 'Branch Master',
  'm/expenseType': 'Expense Type',
  'm/credit': 'Credit Period',
  'm/remark': 'Invoice Remark',
  'dc/po': 'Purchase Order',

  'dc/grn': 'GRN',
  'dc/tracking': 'PO Tracking',
  'dc/quote': 'Quotation',
  'dc/order': 'Sales Order',
  'dc/invoice': 'Sales Invoice',
  'dc/return': 'Sales Return',
  'dc/receipt': 'Payment Receipt',
  'dc/expense': 'Expense',
  'st/transfer': 'Stock Transfer',
  'st/adjust': 'Stock Adjustment',
  'st/bin': 'BIN Card',
  'st/live': 'Live Stock',
  'st/price': 'Price Control',
  reports: 'Reports',
  'ad/reps': 'Sales Reps',
  'ad/users': 'User Management',
  'ad/perm': 'User Permission',
  'ad/company': 'Company Profile',
}

export function canAccessRoute(user: AuthUser | null | undefined, routeId: string): boolean {
  if (!user) return false
  if (user.role === 'admin') return true // admin sees everything
  const mod = ROUTE_MODULE[routeId]
  if (!mod) return false // fail closed: unmapped route → deny for non-admins
  return userCan(user, mod, 'View')
}
