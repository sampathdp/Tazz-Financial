/* NMS-Auto — app shell (sidebar, topbar), ported from shell.jsx */
import { useEffect, useState } from 'react'
import type { CSSProperties } from 'react'
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
export interface NavSection { section: string; icon: string; items: NavLeaf[] }
export type NavNode = NavLeaf | NavSection

export const NAV: NavNode[] = [
  { id: 'dash', label: 'Dashboard', icon: 'dash' },
  { id: 'customers', label: 'Customer Management', icon: 'users' },

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
    { id: 'loan/doc-tracking', label: 'Document Tracking', icon: 'tag' },
    { id: 'loan/commissions', label: 'Sales Executive Commissions', icon: 'target' },
    /** Admin-only — installment skip / waivers. Intentionally left out of
     *  lib/permissions.ts's ROUTE_MODULE so canAccessRoute() falls back
     *  to its unmapped-route rule (admin role only), not the permission system. */
    { id: 'loan/adjustments', label: 'Loan Adjustments', icon: 'shield' },
  ] },

  { section: 'Funding / Investment', icon: 'pie', items: [
    { id: 'fund/dashboard', label: 'Funding Dashboard', icon: 'pie' },
    { id: 'fund/products', label: 'Funding Products', icon: 'layers' },
    { id: 'fund/deposits', label: 'Deposits', icon: 'wallet' },
    { id: 'fund/fds', label: 'Fixed Deposits', icon: 'coins' },
    { id: 'fund/reports', label: 'Funding Reports', icon: 'chart' },
  ] },

  { section: 'Pawning Management', icon: 'tag', items: [
    { id: 'pawn/tickets', label: 'Pawn Tickets', icon: 'tag', soon: true },
    { id: 'pawn/auctions', label: 'Auctions', icon: 'store', soon: true },
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
    { id: 'acc/coa', label: 'Chart of Accounts', icon: 'list', soon: true },
    { id: 'acc/journal', label: 'Journal Entries', icon: 'doc', soon: true },
    { id: 'acc/gl', label: 'General Ledger', icon: 'layers', soon: true },
    { id: 'acc/tb', label: 'Trial Balance', icon: 'chart', soon: true },
    { id: 'acc/pl', label: 'Profit & Loss', icon: 'pie', soon: true },
    { id: 'acc/bs', label: 'Balance Sheet', icon: 'grid', soon: true },
    { id: 'acc/cf', label: 'Cash Flow', icon: 'swap', soon: true },
  ] },

  { section: 'Expense Management', icon: 'wallet', items: [
    { id: 'exp/vendors', label: 'Vendors', icon: 'store', soon: true },
    { id: 'exp/expenses', label: 'Expenses', icon: 'wallet', soon: true },
    { id: 'exp/vouchers', label: 'Payment Vouchers', icon: 'doc', soon: true },
  ] },

  { section: 'Masters', icon: 'layers', items: [
    { id: 'masters/expense-categories', label: 'Expense Categories', icon: 'tag' },
    { id: 'masters/banks', label: 'Banks', icon: 'building' },
  ] },

  { section: 'Reports', icon: 'chart', items: [
    { id: 'rep/loans', label: 'Loan Reports', icon: 'coins', soon: true },
    { id: 'rep/collections', label: 'Collection Reports', icon: 'receipt', soon: true },
    { id: 'rep/customers', label: 'Customer Reports', icon: 'users', soon: true },
    { id: 'rep/investors', label: 'Investor Reports', icon: 'pie', soon: true },
    { id: 'rep/pawn', label: 'Pawn Reports', icon: 'tag', soon: true },
    { id: 'rep/cashbank', label: 'Cash & Bank Reports', icon: 'building', soon: true },
    { id: 'rep/expenses', label: 'Expense Reports', icon: 'wallet', soon: true },
  ] },

  { section: 'Notifications', icon: 'bell', items: [
    { id: 'notif/templates', label: 'Notification Templates', icon: 'bell', soon: true },
    { id: 'notif/log', label: 'Notifications', icon: 'mail', soon: true },
  ] },

  { section: 'Administration', icon: 'shield', items: [
    { id: 'ad/branches', label: 'Branch Management', icon: 'building' },
    { id: 'ad/roles', label: 'Role Management', icon: 'shield' },
    { id: 'ad/users', label: 'User Management', icon: 'users' },
    { id: 'ad/perm', label: 'User Permission', icon: 'shield' },
    { id: 'ad/audit', label: 'Audit Logs', icon: 'list', soon: true },
    { id: 'ad/login-activity', label: 'Login Activity', icon: 'clock', soon: true },
    { id: 'ad/company', label: 'Company Profile', icon: 'building' },
  ] },
]

export const flatNav: Record<string, NavLeaf> = {}
NAV.forEach((n) => {
  if ('items' in n) n.items.forEach((i) => (flatNav[i.id] = i))
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

export function Sidebar({ route, go, open, setOpen, company, user }: { route: string; go: (r: string) => void; open: boolean; setOpen: (b: boolean) => void; company: { name: string, tagline: string }; user?: AuthUser | null }) {
  // Filter NAV by permission: drop hidden leaves and collapse empty sections.
  const visibleNav: NavNode[] = NAV
    .map((n) => {
      if ('section' in n) {
        const items = n.items.filter((i) => canAccessRoute(user, i.id))
        return items.length ? { ...n, items } : null
      }
      return canAccessRoute(user, n.id) ? n : null
    })
    .filter((n): n is NavNode => n !== null)

  const [exp, setExp] = useState<Record<string, boolean>>(() => {
    // All sections collapsed by default; only auto-expand the one containing the active route.
    const o: Record<string, boolean> = {}
    NAV.forEach((n) => {
      if ('section' in n) o[n.section] = n.items.some((i) => i.id === route)
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
            if (!('section' in n)) return <NavItem key={n.id} item={n} active={route === n.id} onClick={() => { go(n.id); setOpen(false) }} />
            const open2 = exp[n.section]
            return (
              <div key={n.section} style={{ marginTop: i ? 8 : 0 }}>
                <button onClick={() => setExp((e) => ({ ...e, [n.section]: !e[n.section] }))} className="row between" style={{ width: '100%', background: 'none', border: 'none', padding: '7px 10px', color: 'var(--tx-2)' }}>
                  <span className="eyebrow" style={{ fontSize: 10 }}>{n.section}</span>
                  <Icon n="chevd" s={13} style={{ transform: open2 ? 'none' : 'rotate(-90deg)', transition: 'transform .2s' }} />
                </button>
                <div style={{ maxHeight: open2 ? n.items.length * 40 + 'px' : 0, overflow: 'hidden', transition: 'max-height .3s var(--ease)' }}>
                  {n.items.map((it) => <NavItem key={it.id} item={it} active={route === it.id} sub onClick={() => { go(it.id); setOpen(false) }} />)}
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

export function Topbar({ setOpen, go }: { setOpen: (fn: (o: boolean) => boolean) => void; route: string; go: (r: string) => void }) {
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
              localStorage.removeItem('mms-route')
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
