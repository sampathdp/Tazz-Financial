/* NMS-Auto — app router + screen registry (replaces window.SCREENS) */
import { lazy, Suspense, useEffect, useState } from 'react'
import type { ComponentType } from 'react'
import { Sidebar, Topbar, flatNav } from './components/Shell'
import { Icon } from './components/Icon'
import DB from './data'
import type { Go, ScreenProps } from './screens/types'
import Login from './screens/Login'
import { auth, type AuthUser } from './api'
import { canAccessRoute } from './lib/permissions'
import { WhatsNewModal } from './components/WhatsNewModal'

const DashScreen = lazy(() => import('./screens/Dashboard'))
const CustomersScreen = lazy(() => import('./screens/Customers').then((m) => ({ default: m.CustomersScreen })))
const LoanTypesScreen = lazy(() => import('./screens/LoanTypes').then((m) => ({ default: m.LoanTypesScreen })))
const LoanApplicationsScreen = lazy(() => import('./screens/LoanApplications').then((m) => ({ default: m.LoanApplicationsScreen })))
const ActiveLoansScreen = lazy(() => import('./screens/ActiveLoans').then((m) => ({ default: m.ActiveLoansScreen })))
const OverdueLoansScreen = lazy(() => import('./screens/OverdueLoans').then((m) => ({ default: m.OverdueLoansScreen })))
const PaymentReceiptScreen = lazy(() => import('./screens/PaymentReceipt').then((m) => ({ default: m.PaymentReceiptScreen })))
const DocumentTrackingScreen = lazy(() => import('./screens/DocumentTracking').then((m) => ({ default: m.DocumentTrackingScreen })))
const LoanAdjustmentsScreen = lazy(() => import('./screens/LoanAdjustments').then((m) => ({ default: m.LoanAdjustmentsScreen })))
const ChequeClearanceScreen = lazy(() => import('./screens/ChequeClearance').then((m) => ({ default: m.ChequeClearanceScreen })))
const SalesExecutiveCommissionsScreen = lazy(() => import('./screens/SalesExecutiveCommissions').then((m) => ({ default: m.SalesExecutiveCommissionsScreen })))
const FundingDashboardScreen = lazy(() => import('./screens/FundingDashboard').then((m) => ({ default: m.FundingDashboardScreen })))
const FundingProductsScreen = lazy(() => import('./screens/FundingProducts').then((m) => ({ default: m.FundingProductsScreen })))
const DepositsScreen = lazy(() => import('./screens/Deposits').then((m) => ({ default: m.DepositsScreen })))
const FixedDepositsScreen = lazy(() => import('./screens/FixedDeposits').then((m) => ({ default: m.FixedDepositsScreen })))
const FundingReportsScreen = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingReportsScreen })))
const named = <M, K extends keyof M>(loader: () => Promise<M>, key: K) =>
  lazy(() => loader().then((m) => ({ default: m[key] as unknown as ComponentType<any> })))

const BranchesScreen = named(() => import('./screens/Admin'), 'BranchesScreen')
const RolesScreen = named(() => import('./screens/Admin'), 'RolesScreen')
const UsersScreen = named(() => import('./screens/Admin'), 'UsersScreen')
const PermScreen = named(() => import('./screens/Admin'), 'PermScreen')
const CompanyScreen = named(() => import('./screens/Admin'), 'CompanyScreen')

const CashbookScreen = named(() => import('./screens/Cashbook'), 'CashbookScreen')
const CashTransfersScreen = named(() => import('./screens/Cashbook'), 'CashTransfersScreen')
const CashReconciliationScreen = named(() => import('./screens/Cashbook'), 'CashReconciliationScreen')

const BankAccountsScreen = named(() => import('./screens/Bank'), 'BankAccountsScreen')
const BankTransactionsScreen = named(() => import('./screens/Bank'), 'BankTransactionsScreen')
const BankReconciliationScreen = named(() => import('./screens/Bank'), 'BankReconciliationScreen')

const ExpenseCategoriesScreen = named(() => import('./screens/Masters'), 'ExpenseCategoriesScreen')
const BanksScreen = named(() => import('./screens/Masters'), 'BanksScreen')

const ChartOfAccountsScreen = named(() => import('./screens/Accounting'), 'ChartOfAccountsScreen')
const JournalEntriesScreen = named(() => import('./screens/Accounting'), 'JournalEntriesScreen')
const GeneralLedgerScreen = named(() => import('./screens/Accounting'), 'GeneralLedgerScreen')
const TrialBalanceScreen = named(() => import('./screens/Accounting'), 'TrialBalanceScreen')
const ProfitLossScreen = named(() => import('./screens/Accounting'), 'ProfitLossScreen')
const BalanceSheetScreen = named(() => import('./screens/Accounting'), 'BalanceSheetScreen')

const VendorsScreen = named(() => import('./screens/Expenses'), 'VendorsScreen')
const ExpensesScreen = named(() => import('./screens/Expenses'), 'ExpensesScreen')

type ScreenComp = ComponentType<ScreenProps & { setBrand?: (v: { name: string, tagline: string }) => void }>

// Shown only for the split-second a screen chunk is downloading on first visit.
function ScreenLoader() {
  return (
    <div className="row center" style={{ padding: 80, color: 'var(--tx-3)', gap: 10 }}>
      <span className="mms-spin" style={{ width: 18, height: 18, border: '2px solid var(--line)', borderTopColor: 'var(--ac)', borderRadius: '50%', display: 'inline-block' }} />
      <span style={{ fontSize: 13 }}>Loading…</span>
    </div>
  )
}

// Shown for nav items on the roadmap but not built yet (admin-only preview — see canAccessRoute).
function ComingSoon({ route }: { route: string }) {
  const item = flatNav[route]
  return (
    <div className="col center" style={{ padding: '80px 20px', textAlign: 'center', color: 'var(--tx-2)' }}>
      <div style={{ width: 52, height: 52, borderRadius: 'var(--r-m)', background: 'var(--bg-2)', border: '1px solid var(--line)', display: 'grid', placeItems: 'center', marginBottom: 16 }}>
        <Icon n={item?.icon || 'clock'} s={24} c="var(--tx-3)" />
      </div>
      <h2 style={{ fontSize: 18, color: 'var(--tx-0)', marginBottom: 6 }}>{item?.label || 'This screen'} — coming soon</h2>
      <p style={{ fontSize: 13, maxWidth: 380 }}>This module is on the roadmap and not built yet. It's visible to you as an admin so you can see the planned structure — say the word and we'll build it next.</p>
    </div>
  )
}

const SCREENS: Record<string, ScreenComp> = {
  dash: DashScreen,
  customers: CustomersScreen,
  'loan/types': LoanTypesScreen,
  'loan/apps': LoanApplicationsScreen,
  'loan/active': ActiveLoansScreen,
  'loan/overdue': OverdueLoansScreen,
  'loan/payments': PaymentReceiptScreen,
  'loan/doc-tracking': DocumentTrackingScreen,
  'loan/cheques': ChequeClearanceScreen,
  'loan/adjustments': LoanAdjustmentsScreen,
  'loan/commissions': SalesExecutiveCommissionsScreen,
  'ad/branches': BranchesScreen,
  'ad/roles': RolesScreen,
  'ad/users': UsersScreen,
  'ad/perm': PermScreen,
  'ad/company': CompanyScreen,
  'cash/book': CashbookScreen,
  'cash/transfers': CashTransfersScreen,
  'cash/recon': CashReconciliationScreen,
  'bank/accounts': BankAccountsScreen,
  'bank/txns': BankTransactionsScreen,
  'bank/recon': BankReconciliationScreen,
  'masters/expense-categories': ExpenseCategoriesScreen,
  'masters/banks': BanksScreen,
  'acc/coa': ChartOfAccountsScreen,
  'acc/journal': JournalEntriesScreen,
  'acc/gl': GeneralLedgerScreen,
  'acc/tb': TrialBalanceScreen,
  'acc/pl': ProfitLossScreen,
  'acc/bs': BalanceSheetScreen,
  'exp/vendors': VendorsScreen,
  'exp/expenses': ExpensesScreen,
  'fund/dashboard': FundingDashboardScreen,
  'fund/products': FundingProductsScreen,
  'fund/deposits': DepositsScreen,
  'fund/fds': FixedDepositsScreen,
  'fund/reports': FundingReportsScreen,
}

export default function App() {
  const [route, setRoute] = useState<string>(() => localStorage.getItem('mms-route') || 'dash')
  const [open, setOpen] = useState(false)
  const [brand, setBrand] = useState({ name: DB.company.name, tagline: DB.company.tagline })
  const [user, setUser] = useState<AuthUser | null>(null)
  const [authReady, setAuthReady] = useState(false)
  const go: Go = (r) => { setRoute(r); localStorage.setItem('mms-route', r); window.scrollTo(0, 0) }
  useEffect(() => { document.title = brand.name + ' — Admin Panel' }, [brand.name])

  useEffect(() => {
    import('./api').then(({ company }) => {
      company.branding().then(b => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {})
    })
    const token = auth.getToken()
    if (!token) { setAuthReady(true); return }
    auth.me()
      .then((u) => setUser(u))
      .catch(() => auth.setToken(null))
      .finally(() => setAuthReady(true))
  }, [])

  if (!authReady) return null
  if (!user) return <Login onAuth={setUser} />

  // Permission gate: silently downgrade to dashboard if user lacks access to the
  // saved route (e.g. after a permission change). The Sidebar already hides the
  // entries they can't reach, but the route may be persisted in localStorage.
  const effectiveRoute = canAccessRoute(user, route) ? route : 'dash'
  const Screen = SCREENS[effectiveRoute] || (() => <ComingSoon route={effectiveRoute} />)
  const denied = !canAccessRoute(user, route)
  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <WhatsNewModal user={user} />
      <Sidebar route={effectiveRoute} go={go} open={open} setOpen={setOpen} company={brand} user={user} />
      <div className="mms-main" style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <Topbar setOpen={setOpen} route={effectiveRoute} go={go} />
        <main key={effectiveRoute} className="fade-in" style={{ padding: '24px clamp(16px,3vw,32px) 60px', flex: 1 }}>
          {denied
            ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>You do not have permission to view this screen.</div>
            : <Suspense fallback={<ScreenLoader />}><Screen go={go} setBrand={setBrand} user={user} /></Suspense>}
        </main>
      </div>
    </div>
  )
}
