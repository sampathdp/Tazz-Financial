/* NMS-Auto — app shell (sidebar, topbar), ported from shell.jsx */
import { useEffect, useState } from 'react'
import type { CSSProperties } from 'react'
import { useLocation } from 'react-router-dom'
import { Icon } from './Icon'
import { NotificationBell, useNotify } from './Notify'
import DB from '../data'
import { auth, type AuthUser } from '../api'
import { canAccessRoute } from '../lib/permissions'

export interface NavLeaf {
  id: string; label: string; icon: string; star?: boolean
  /** Not built yet — shown (admin-only) as a preview of the planned structure. See lib/permissions.ts canAccessRoute(). */
  soon?: boolean
}
/** A collapsible sub-category inside a section (currently only Reports uses
 *  this — one level of nesting, not arbitrary depth). */
export interface NavGroup { group: string; icon: string; items: NavLeaf[] }
export interface NavSection { section: string; icon: string; items: (NavLeaf | NavGroup)[] }
export type NavNode = NavLeaf | NavSection

export const NAV: NavNode[] = [
  { id: 'dash', label: 'Dashboard', icon: 'dash' },
  { id: 'customers', label: 'Customer Management', icon: 'users' },

  { section: 'Finance', icon: 'wallet', items: [
    { id: 'finance/dashboard', label: 'Finance Dashboard', icon: 'wallet' },
    { id: 'finance/requests', label: 'Finance Requests', icon: 'receipt' },
    { id: 'finance/transactions', label: 'Cash Transactions', icon: 'receipt' },
    { id: 'finance/transfers', label: 'Transfers', icon: 'swap' },
    { id: 'finance/floats', label: 'Cash Floats', icon: 'coins' },
    { id: 'finance/sessions', label: 'Daily Cash Sessions', icon: 'clock' },
    { id: 'finance/audit', label: 'Audit Log', icon: 'list' },
    { id: 'finance/settings', label: 'Finance Settings', icon: 'shield' },
    { id: 'finance/calculator', label: 'Leasing / Loan Calculator', icon: 'calc' },
  ] },

  { section: 'Loan Management', icon: 'coins', items: [
    { id: 'loan/types', label: 'Loan Types', icon: 'layers' },
    { id: 'loan/apps', label: 'Loan Applications', icon: 'doc' },
    { id: 'loan/active', label: 'Active Loans', icon: 'coins' },
    { id: 'loan/payments', label: 'Loan Payments', icon: 'receipt' },
    /** Admin-only — cheque realize/reject. Intentionally left out of
     *  lib/permissions.ts's ROUTE_MODULE so canAccessRoute() falls back to its
     *  unmapped-route rule (admin role only), not the permission system. */
    { id: 'loan/cheques', label: 'Cheque Clearance', icon: 'cheque' },
    { id: 'loan/overdue', label: 'Overdue Loans', icon: 'alert' },
    { id: 'loan/provisions', label: 'Loan Provisioning', icon: 'shield' },
    { id: 'loan/doc-tracking', label: 'Document Tracking', icon: 'tag' },
    { id: 'loan/commissions', label: 'Sales Executive Commissions', icon: 'target' },
    /** Admin-only — installment skip / waivers. Intentionally left out of
     *  lib/permissions.ts's ROUTE_MODULE so canAccessRoute() falls back
     *  to its unmapped-route rule (admin role only), not the permission system. */
    { id: 'loan/adjustments', label: 'Loan Adjustments', icon: 'shield' },
  ] },

  { section: 'Speed Drafts', icon: 'coins', items: [
    { id: 'sd/types', label: 'Speed Draft Types', icon: 'layers' },
    { id: 'sd/drafts', label: 'Speed Drafts', icon: 'coins' },
    { id: 'sd/payments', label: 'Speed Draft Payments', icon: 'receipt' },
  ] },

  { section: 'Funding / Investment', icon: 'pie', items: [
    { id: 'fund/dashboard', label: 'Funding Dashboard', icon: 'pie' },
    { id: 'fund/products', label: 'Funding Products', icon: 'layers' },
    { id: 'fund/deposits', label: 'Deposits', icon: 'wallet' },
    { id: 'fund/fds', label: 'Fixed Deposits', icon: 'coins' },
    { id: 'fund/borrowings', label: 'Borrowings', icon: 'wallet' },
    { id: 'fund/deposit-payment', label: 'Deposit Payment', icon: 'receipt' },
    { id: 'fund/interest-due', label: 'Interest Due', icon: 'wallet' },
    { id: 'fund/reports', label: 'Funding Reports', icon: 'chart' },
  ] },

  { section: 'Pawning Management', icon: 'tag', items: [
    { id: 'pawn/types', label: 'Pawning Types', icon: 'layers' },
    { id: 'pawn/tickets', label: 'Pawn Tickets', icon: 'tag' },
    { id: 'pawn/auctions', label: 'Auctions', icon: 'store' },
  ] },

  { section: 'Cashbook Management', icon: 'wallet', items: [
    { id: 'cash/book', label: 'Cashbook', icon: 'wallet' },
    { id: 'cash/transfers', label: 'Cash Transfers', icon: 'swap' },
    { id: 'cash/recon', label: 'Cash Reconciliation', icon: 'adjust' },
  ] },

  { section: 'Bank Management', icon: 'building', items: [
    { id: 'bank/accounts', label: 'Bank Accounts', icon: 'building' },
    { id: 'bank/txns', label: 'Bank Transactions', icon: 'receipt' },
    { id: 'bank/recon', label: 'Bank Reconciliation', icon: 'adjust' },
  ] },

  { section: 'Accounting', icon: 'list', items: [
    { id: 'acc/coa', label: 'Chart of Accounts', icon: 'list' },
    { id: 'acc/journal', label: 'Journal Entries', icon: 'doc' },
    { id: 'acc/mje', label: 'Manual Journal Entries', icon: 'edit' },
    { id: 'acc/gl', label: 'General Ledger', icon: 'layers' },
    { id: 'acc/tb', label: 'Trial Balance', icon: 'chart' },
    { id: 'acc/pl', label: 'Profit & Loss', icon: 'pie' },
    { id: 'acc/bs', label: 'Balance Sheet', icon: 'grid' },
    { id: 'acc/cf', label: 'Cash Flow', icon: 'swap' },
    { id: 'acc/drift', label: 'Ledger Drift', icon: 'adjust' },
  ] },

  { section: 'Expense Management', icon: 'wallet', items: [
    { id: 'exp/vendors', label: 'Vendors', icon: 'store' },
    { id: 'exp/expenses', label: 'Expenses', icon: 'wallet' },
    /** View/void only — a voucher is only ever created as the result of
     *  paying an Expense (see ExpenseController::pay on the backend). */
    { id: 'exp/vouchers', label: 'Payment Vouchers', icon: 'doc' },
    { id: 'exp/petty-cash', label: 'Petty Cash', icon: 'coins' },
  ] },

  { section: 'Masters', icon: 'layers', items: [
    { id: 'masters/expense-categories', label: 'Expense Categories', icon: 'tag' },
    { id: 'masters/banks', label: 'Banks', icon: 'building' },
    { id: 'masters/document-categories', label: 'Document Categories', icon: 'file-text' },
    { id: 'masters/charge-categories', label: 'Charge Categories', icon: 'tag' },
  ] },

  { id: 'subs/list', label: 'Subscriptions & Renewals', icon: 'calendar' },

  { section: 'Reports', icon: 'chart', items: [
    { group: 'Loan Reports', icon: 'coins', items: [
      { id: 'rep/loans-summary', label: 'Portfolio Summary', icon: 'coins' },
      { id: 'rep/loans-par', label: 'PAR / NPL Aging', icon: 'target' },
      { id: 'rep/loans-arrears', label: 'Arrears', icon: 'alert' },
      { id: 'rep/loans-disbursement', label: 'Disbursement', icon: 'upload' },
      { id: 'rep/loans-closed', label: 'Closed Loans', icon: 'check' },
      { id: 'rep/loans-detail', label: 'Loan Detail Report', icon: 'list' },
    ] },
    { group: 'Collection Reports', icon: 'receipt', items: [
      { id: 'rep/coll-daily', label: 'Daily Cash Collection', icon: 'wallet' },
      { id: 'rep/coll-summary', label: 'Collection Summary', icon: 'chart' },
      { id: 'rep/coll-officers', label: 'Officer Performance', icon: 'users' },
      { id: 'rep/coll-trend', label: 'Collection Trend', icon: 'arrowUp' },
    ] },
    { group: 'Customer Reports', icon: 'users', items: [
      { id: 'rep/cust-summary', label: 'Customer Summary', icon: 'users' },
      { id: 'rep/cust-activity', label: 'Customer Activity', icon: 'list' },
      { id: 'rep/cust-credit', label: 'Customer Credit Report', icon: 'shield' },
    ] },
    { group: 'Investor Reports', icon: 'pie', items: [
      { id: 'rep/inv-summary', label: 'Investor Summary', icon: 'pie' },
      { id: 'rep/inv-directory', label: 'Investor Directory', icon: 'list' },
    ] },
    { group: 'Pawn Reports', icon: 'tag', items: [
      { id: 'rep/pawn', label: 'Pawn Reports', icon: 'tag', soon: true },
    ] },
    { group: 'Cash & Bank Reports', icon: 'building', items: [
      { id: 'rep/cb-position', label: 'Cash & Bank Position', icon: 'building' },
      { id: 'rep/cb-cashbook', label: 'Cashbook Activity', icon: 'receipt' },
      { id: 'rep/cb-bank', label: 'Bank Activity', icon: 'swap' },
    ] },
    { group: 'Expense Reports', icon: 'wallet', items: [
      { id: 'rep/exp-summary', label: 'Expense Summary', icon: 'chart' },
      { id: 'rep/exp-list', label: 'Expense List', icon: 'list' },
      { id: 'rep/exp-vendor', label: 'Expense by Vendor', icon: 'store' },
    ] },
  ] },

  { section: 'Notifications', icon: 'bell', items: [
    { id: 'notif/templates', label: 'Notification Templates', icon: 'bell', soon: true },
    { id: 'notif/log', label: 'Notifications', icon: 'mail', soon: true },
  ] },

  { section: 'Administration', icon: 'shield', items: [
    { id: 'ad/approvals', label: 'Approvals', icon: 'check' },
    { id: 'ad/approval-types', label: 'Approval Types', icon: 'shield' },
    { id: 'ad/branches', label: 'Branch Management', icon: 'building' },
    { id: 'ad/roles', label: 'Role Management', icon: 'shield' },
    { id: 'ad/users', label: 'User Management', icon: 'users' },
    { id: 'ad/perm', label: 'User Permission', icon: 'shield' },
    { id: 'ad/audit', label: 'Audit Logs', icon: 'list', soon: true },
    { id: 'ad/login-activity', label: 'Login Activity', icon: 'clock', soon: true },
    { id: 'ad/company', label: 'Company Profile', icon: 'building' },
    { id: 'ad/maintenance', label: 'Data Maintenance', icon: 'trash' },
  ] },
]

export const flatNav: Record<string, NavLeaf> = {}
NAV.forEach((n) => {
  if ('items' in n) n.items.forEach((i) => ('group' in i ? i.items.forEach((gi) => (flatNav[gi.id] = gi)) : (flatNav[i.id] = i)))
  else flatNav[n.id] = n
})

function NavItem({ item, active, sub, onClick }: { item: NavLeaf; active: boolean; sub?: boolean; onClick: () => void }) {
  return (
    <button onClick={onClick} className={'mms-nav' + (active ? ' active' : '')} style={{
      display: 'flex', alignItems: 'center', gap: 10, width: '100%', textAlign: 'left',
      padding: sub ? '8px 10px 8px 12px' : '9px 10px', marginTop: 2,
      background: active ? 'var(--ac-dim)' : 'transparent', border: '1px solid ' + (active ? 'var(--ac-line)' : 'transparent'),
      borderRadius: 'var(--r-s)', color: active ? 'var(--ac-bright)' : 'var(--tx-1)', fontSize: 13, fontWeight: active ? 600 : 500,
      transition: 'all .14s',
    }}>
      <Icon n={item.icon} s={17} c={active ? 'var(--ac-bright)' : 'var(--tx-2)'} />
      <span style={{ flex: 1, opacity: item.soon ? 0.7 : 1 }}>{item.label}</span>
      {item.soon && <span style={{ fontSize: 9.5, fontWeight: 700, letterSpacing: '.04em', color: 'var(--tx-3)', border: '1px solid var(--line)', borderRadius: 4, padding: '1px 5px' }}>SOON</span>}
      {item.star && <span style={{ width: 5, height: 5, borderRadius: '50%', background: 'var(--ac)', opacity: active ? 0 : 0.7 }} />}
    </button>
  )
}

export function Sidebar({ go, open, setOpen, company, user }: { go: (r: string) => void; open: boolean; setOpen: (b: boolean) => void; company: { name: string, tagline: string }; user?: AuthUser | null }) {
  const location = useLocation()
  // A nav id is "active" if it's the exact current path, or an ancestor of it
  // (e.g. id 'loan/apps' stays active on '/loan/apps/new' or '/loan/apps/12').
  const isActive = (id: string) => location.pathname === '/' + id || location.pathname.startsWith('/' + id + '/')
  const route = location.pathname.slice(1)
  // Filter NAV by permission: drop hidden leaves, drop empty groups, collapse empty sections.
  const visibleNav: NavNode[] = NAV
    .map((n) => {
      if ('section' in n) {
        const items = n.items
          .map((i) => {
            if ('group' in i) {
              const gi = i.items.filter((leaf) => canAccessRoute(user, leaf.id))
              return gi.length ? { ...i, items: gi } : null
            }
            return canAccessRoute(user, i.id) ? i : null
          })
          .filter((i): i is NavLeaf | NavGroup => i !== null)
        return items.length ? { ...n, items } : null
      }
      return canAccessRoute(user, n.id) ? n : null
    })
    .filter((n): n is NavNode => n !== null)

  // Group toggle state keyed by "section::group" so group names don't collide across sections.
  const groupKey = (section: string, group: string) => `${section}::${group}`

  const [exp, setExp] = useState<Record<string, boolean>>(() => {
    // All sections/groups collapsed by default; only auto-expand the one containing the active route.
    const o: Record<string, boolean> = {}
    NAV.forEach((n) => {
      if (!('section' in n)) return
      let sectionActive = false
      n.items.forEach((i) => {
        if ('group' in i) {
          const active = i.items.some((leaf) => leaf.id === route)
          o[groupKey(n.section, i.group)] = active
          if (active) sectionActive = true
        } else if (i.id === route) {
          sectionActive = true
        }
      })
      o[n.section] = sectionActive
    })
    return o
  })
  return (
    <>
      <div onClick={() => setOpen(false)} className="sb-scrim" style={{ display: open ? 'block' : 'none' }} />
      <aside className={'mms-sb' + (open ? ' open' : '')} style={{ width: 'var(--sb-w)' }}>
        <div className="row gap-3" style={{ padding: '16px 18px', borderBottom: '1px solid var(--line)', minHeight: 'var(--topbar-h)', alignItems: 'center' }}>
          <img src="/logo.jpeg" alt="" style={{ width: 34, height: 34, borderRadius: 9, objectFit: 'cover', flexShrink: 0 }} />
          <div style={{ lineHeight: 1.15, minWidth: 0 }}>
            <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 15, letterSpacing: '-0.01em' }} id="brand-name">{company.name}</div>
            <div className="eyebrow" style={{ fontSize: 9 }}>{company.tagline}</div>
          </div>
        </div>
        <nav style={{ padding: '10px 10px 24px', overflowY: 'auto', flex: 1 }}>
          {visibleNav.map((n, i) => {
            if (!('section' in n)) return <NavItem key={n.id} item={n} active={isActive(n.id)} onClick={() => { go(n.id); setOpen(false) }} />
            const open2 = exp[n.section]
            return (
              <div key={n.section} style={{ marginTop: i ? 8 : 0 }}>
                <button onClick={() => setExp((e) => ({ ...e, [n.section]: !e[n.section] }))} className="row between" style={{ width: '100%', background: 'none', border: 'none', padding: '7px 10px', color: 'var(--tx-2)' }}>
                  <span className="eyebrow" style={{ fontSize: 10 }}>{n.section}</span>
                  <Icon n="chevd" s={13} style={{ transform: open2 ? 'none' : 'rotate(-90deg)', transition: 'transform .2s' }} />
                </button>
                <div style={{ maxHeight: open2 ? '3000px' : 0, overflow: 'hidden', transition: 'max-height .3s var(--ease)' }}>
                  {n.items.map((it) => {
                    if (!('group' in it)) return <NavItem key={it.id} item={it} active={isActive(it.id)} sub onClick={() => { go(it.id); setOpen(false) }} />
                    const gKey = groupKey(n.section, it.group)
                    const open3 = exp[gKey]
                    return (
                      <div key={gKey}>
                        <button onClick={() => setExp((e) => ({ ...e, [gKey]: !e[gKey] }))} className="row gap-2" style={{ width: '100%', background: 'none', border: 'none', padding: '7px 10px 7px 20px', color: 'var(--tx-2)', alignItems: 'center' }}>
                          <Icon n={it.icon} s={14} c="var(--tx-3)" />
                          <span style={{ flex: 1, textAlign: 'left', fontSize: 11.5, fontWeight: 600 }}>{it.group}</span>
                          <Icon n="chevd" s={12} style={{ transform: open3 ? 'none' : 'rotate(-90deg)', transition: 'transform .2s' }} />
                        </button>
                        <div style={{ maxHeight: open3 ? it.items.length * 40 + 'px' : 0, overflow: 'hidden', transition: 'max-height .3s var(--ease)' }}>
                          {it.items.map((leaf) => (
                            <div key={leaf.id} style={{ paddingLeft: 14 }}>
                              <NavItem item={leaf} active={isActive(leaf.id)} sub onClick={() => { go(leaf.id); setOpen(false) }} />
                            </div>
                          ))}
                        </div>
                      </div>
                    )
                  })}
                </div>
              </div>
            )
          })}
        </nav>
      </aside>
    </>
  )
}

export function ThemeToggle() {
  const [theme, setTheme] = useState<string>(() => document.documentElement.dataset.theme || 'dark')
  const toggle = () => {
    const next = theme === 'dark' ? 'light' : 'dark'
    setTheme(next)
    document.documentElement.dataset.theme = next
    localStorage.setItem('mms-theme', next)
  }
  const isDark = theme === 'dark'
  return (
    <button
      className="mms-icobtn"
      onClick={toggle}
      title={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
      aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
      style={{ background: 'var(--bg-2)', border: '1px solid var(--line)', borderRadius: 8, padding: 8, color: 'var(--tx-1)' }}
    >
      <Icon n={isDark ? 'sun' : 'moon'} s={18} />
    </button>
  )
}

export function Topbar({ setOpen, go }: { setOpen: (fn: (o: boolean) => boolean) => void; go: (r: string) => void }) {
  const notify = useNotify()
  // Let notification-center clicks navigate via the app router.
  useEffect(() => { notify.setRouter(go) }, [notify, go])
  const headStyle: CSSProperties = { height: 'var(--topbar-h)', padding: '0 20px', borderBottom: '1px solid var(--line)', background: 'var(--top-bg)', backdropFilter: 'blur(10px)', position: 'sticky', top: 0, zIndex: 50 }
  return (
    <header className="mms-top row between" style={headStyle}>
      <div className="row gap-3">
        <button className="mms-burger" onClick={() => setOpen((o) => !o)} style={{ background: 'var(--bg-2)', border: '1px solid var(--line)', borderRadius: 8, padding: 8, color: 'var(--tx-1)', display: 'none' }}><Icon n="menu" s={18} /></button>
      </div>
      <div className="row gap-3">
        <ThemeToggle />
        <NotificationBell />
        <div className="row gap-2" style={{ paddingLeft: 6, borderLeft: '1px solid var(--line)' }}>
          <div style={{ width: 34, height: 34, borderRadius: '50%', background: 'var(--bg-3)', border: '1px solid var(--line)', display: 'grid', placeItems: 'center', fontFamily: 'Saira', fontWeight: 700, fontSize: 13, color: 'var(--ac-bright)' }}>AD</div>
          <div className="hide-sm" style={{ lineHeight: 1.2 }}>
            <div style={{ fontSize: 13, fontWeight: 600 }}>Admin User</div>
            <div className="t-2" style={{ fontSize: 11 }}>Administrator</div>
          </div>
          <button
            className="mms-icobtn"
            title="Logout"
            aria-label="Logout"
            onClick={async () => {
              try { await auth.logout() } catch {}
              auth.setToken(null)
              window.location.reload()
            }}
            style={{ background: 'var(--bg-2)', border: '1px solid var(--line)', borderRadius: 8, padding: 8, color: 'var(--tx-1)', marginLeft: 6 }}
          >
            <Icon n="logout" s={18} />
          </button>
        </div>
      </div>
    </header>
  )
}
