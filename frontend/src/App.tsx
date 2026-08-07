/* NMS-Auto — app router + screen registry (replaces window.SCREENS) */
import { lazy, Suspense, useEffect, useState } from 'react'
import type { ComponentType } from 'react'
import { BrowserRouter, Navigate, Outlet, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
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
const LoanCalculatorScreen = lazy(() => import('./screens/LoanCalculator').then((m) => ({ default: m.LoanCalculatorScreen })))
const LoanApplicationsScreen = lazy(() => import('./screens/LoanApplications').then((m) => ({ default: m.LoanApplicationsScreen })))
const ActiveLoansScreen = lazy(() => import('./screens/ActiveLoans').then((m) => ({ default: m.ActiveLoansScreen })))
const OverdueLoansScreen = lazy(() => import('./screens/OverdueLoans').then((m) => ({ default: m.OverdueLoansScreen })))
const PaymentReceiptScreen = lazy(() => import('./screens/PaymentReceipt').then((m) => ({ default: m.PaymentReceiptScreen })))
const DocumentTrackingScreen = lazy(() => import('./screens/DocumentTracking').then((m) => ({ default: m.DocumentTrackingScreen })))
const LoanAdjustmentsScreen = lazy(() => import('./screens/LoanAdjustments').then((m) => ({ default: m.LoanAdjustmentsScreen })))
const ChequeClearanceScreen = lazy(() => import('./screens/ChequeClearance').then((m) => ({ default: m.ChequeClearanceScreen })))
const SalesExecutiveCommissionsScreen = lazy(() => import('./screens/SalesExecutiveCommissions').then((m) => ({ default: m.SalesExecutiveCommissionsScreen })))
const SpeedDraftTypesScreen = lazy(() => import('./screens/SpeedDraftTypes').then((m) => ({ default: m.SpeedDraftTypesScreen })))
const SpeedDraftsScreen = lazy(() => import('./screens/SpeedDrafts').then((m) => ({ default: m.SpeedDraftsScreen })))
const SpeedDraftPaymentReceiptScreen = lazy(() => import('./screens/SpeedDraftPaymentReceipt').then((m) => ({ default: m.SpeedDraftPaymentReceiptScreen })))
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
const DocumentCategoriesScreen = named(() => import('./screens/Masters'), 'DocumentCategoriesScreen')
const ChargeCategoriesScreen = named(() => import('./screens/Masters'), 'ChargeCategoriesScreen')

const ChartOfAccountsScreen = named(() => import('./screens/Accounting'), 'ChartOfAccountsScreen')
const JournalEntriesScreen = named(() => import('./screens/Accounting'), 'JournalEntriesScreen')
const GeneralLedgerScreen = named(() => import('./screens/Accounting'), 'GeneralLedgerScreen')
const TrialBalanceScreen = named(() => import('./screens/Accounting'), 'TrialBalanceScreen')
const ProfitLossScreen = named(() => import('./screens/Accounting'), 'ProfitLossScreen')
const BalanceSheetScreen = named(() => import('./screens/Accounting'), 'BalanceSheetScreen')
const LedgerDriftScreen = named(() => import('./screens/Accounting'), 'LedgerDriftScreen')

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

// Full-page loader shown only while the token is being (re)validated on boot —
// avoids flashing Login or the wrong screen before we know who's signed in.
function BootLoader() {
  return <div style={{ minHeight: '100vh' }} />
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
  'finance/calculator': LoanCalculatorScreen,
  'loan/apps': LoanApplicationsScreen,
  'loan/active': ActiveLoansScreen,
  'loan/overdue': OverdueLoansScreen,
  'loan/payments': PaymentReceiptScreen,
  'loan/doc-tracking': DocumentTrackingScreen,
  'loan/cheques': ChequeClearanceScreen,
  'loan/adjustments': LoanAdjustmentsScreen,
  'loan/commissions': SalesExecutiveCommissionsScreen,
  'sd/types': SpeedDraftTypesScreen,
  'sd/drafts': SpeedDraftsScreen,
  'sd/payments': SpeedDraftPaymentReceiptScreen,
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
  'masters/document-categories': DocumentCategoriesScreen,
  'masters/charge-categories': ChargeCategoriesScreen,
  'acc/coa': ChartOfAccountsScreen,
  'acc/journal': JournalEntriesScreen,
  'acc/gl': GeneralLedgerScreen,
  'acc/tb': TrialBalanceScreen,
  'acc/pl': ProfitLossScreen,
  'acc/bs': BalanceSheetScreen,
  'acc/drift': LedgerDriftScreen,
  'exp/vendors': VendorsScreen,
  'exp/expenses': ExpensesScreen,
  'fund/dashboard': FundingDashboardScreen,
  'fund/products': FundingProductsScreen,
  'fund/deposits': DepositsScreen,
  'fund/fds': FixedDepositsScreen,
  'fund/reports': FundingReportsScreen,
}

// Every nav entry (built screens + "soon" placeholders) gets a real route, so
// every screen in the system has its own URL and its own browser-history
// entry — that's what makes Back/Forward and refresh behave correctly.
const ROUTE_IDS = Object.keys(flatNav)

// Screens with a list + create/edit/detail flow also get /new and /:id
// sibling routes, so which record/form is open lives in the URL — that's
// what makes a hard refresh land back on the same form instead of the list.
const FORM_ROUTE_IDS = new Set([
  'loan/apps', 'loan/active', 'loan/overdue', 'customers', 'sd/drafts',
])

// One-off deeper sub-paths (a screen nested inside another screen's record
// view) that don't fit the flat /new + /:id shape above.
const EXTRA_ROUTES: { path: string; forId: string }[] = [
  { path: 'customers/:id/edit', forId: 'customers' },
  { path: 'customers/:id/loan/:loanId', forId: 'customers' },
]

function AppShell() {
  const navigate = useNavigate()
  const location = useLocation()
  const [open, setOpen] = useState(false)
  const [brand, setBrand] = useState({ name: DB.company.name, tagline: DB.company.tagline })
  const [user, setUser] = useState<AuthUser | null>(null)
  const [authReady, setAuthReady] = useState(false)
  const go: Go = (r) => { navigate('/' + r); window.scrollTo(0, 0) }
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

  if (!authReady) return <BootLoader />

  return (
    <Routes>
      <Route
        path="/login"
        element={user ? <Navigate to={(location.state as any)?.from?.pathname || '/dash'} replace /> : <Login onAuth={setUser} />}
      />
      <Route
        element={
          user ? (
            <div style={{ display: 'flex', minHeight: '100vh' }}>
              <WhatsNewModal user={user} />
              <Sidebar go={go} open={open} setOpen={setOpen} company={brand} user={user} />
              <div className="mms-main" style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
                <Topbar setOpen={setOpen} go={go} />
                <main className="fade-in" style={{ padding: '24px clamp(16px,3vw,32px) 60px', flex: 1 }}>
                  <Outlet />
                </main>
              </div>
            </div>
          ) : (
            <Navigate to="/login" state={{ from: location }} replace />
          )
        }
      >
        {user && ROUTE_IDS.map((id) => {
          const Screen = SCREENS[id]
          const allowed = canAccessRoute(user, id)
          const element = !allowed ? (
            <Navigate to="/dash" replace />
          ) : Screen ? (
            <Suspense fallback={<ScreenLoader />}><Screen go={go} setBrand={setBrand} user={user} /></Suspense>
          ) : (
            <ComingSoon route={id} />
          )
          if (!FORM_ROUTE_IDS.has(id)) return <Route key={id} path={id} element={element} />
          return [
            <Route key={id} path={id} element={element} />,
            <Route key={id + '/new'} path={id + '/new'} element={element} />,
            <Route key={id + '/:id'} path={id + '/:id'} element={element} />,
          ]
        })}
        {user && EXTRA_ROUTES.map(({ path, forId }) => {
          const Screen = SCREENS[forId]
          if (!Screen || !canAccessRoute(user, forId)) return null
          return <Route key={path} path={path} element={<Suspense fallback={<ScreenLoader />}><Screen go={go} setBrand={setBrand} user={user} /></Suspense>} />
        })}
        <Route index element={<Navigate to="/dash" replace />} />
        <Route path="*" element={<Navigate to="/dash" replace />} />
      </Route>
    </Routes>
  )
}

export default function App() {
  return (
    <BrowserRouter>
      <AppShell />
    </BrowserRouter>
  )
}
