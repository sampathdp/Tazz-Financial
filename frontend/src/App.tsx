/* NMS-Auto — app router + screen registry (replaces window.SCREENS) */
import { lazy, Suspense, useEffect, useState } from 'react'
import type { ComponentType, ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { WinDockProvider, WinDockBar, useWinDock } from './components/WinDock'
import { Sidebar, Topbar, flatNav } from './components/Shell'
import { Icon } from './components/Icon'
import { Btn } from './components/ui'
import { ErrorBoundary } from './components/ErrorBoundary'
import DB from './data'
import type { Go, ScreenProps } from './screens/types'
import Login from './screens/Login'
import { auth, type AuthUser } from './api'
import { canAccessRoute } from './lib/permissions'
import { WhatsNewModal } from './components/WhatsNewModal'

const DashScreen = lazy(() => import('./screens/Dashboard'))
const CustomersScreen = lazy(() => import('./screens/Customers').then((m) => ({ default: m.CustomersScreen })))
const LoanInquiryScreen = lazy(() => import('./screens/LoanInquiry').then((m) => ({ default: m.LoanInquiryScreen })))
const LoanTypesScreen = lazy(() => import('./screens/LoanTypes').then((m) => ({ default: m.LoanTypesScreen })))
const LoanCalculatorScreen = lazy(() => import('./screens/LoanCalculator').then((m) => ({ default: m.LoanCalculatorScreen })))
const SpeedDraftCalculatorScreen = lazy(() => import('./screens/SpeedDraftCalculator').then((m) => ({ default: m.SpeedDraftCalculatorScreen })))
const LoanApplicationsScreen = lazy(() => import('./screens/LoanApplications').then((m) => ({ default: m.LoanApplicationsScreen })))
const LoanDisbursementPageScreen = lazy(() => import('./screens/LoanDisbursement').then((m) => ({ default: m.LoanDisbursementScreen })))
const ActiveLoansScreen = lazy(() => import('./screens/ActiveLoans').then((m) => ({ default: m.ActiveLoansScreen })))
const OverdueLoansScreen = lazy(() => import('./screens/OverdueLoans').then((m) => ({ default: m.OverdueLoansScreen })))
const LoanProvisionsScreen = lazy(() => import('./screens/LoanProvisions').then((m) => ({ default: m.LoanProvisionsScreen })))
const PaymentReceiptScreen = lazy(() => import('./screens/PaymentReceipt').then((m) => ({ default: m.PaymentReceiptScreen })))
const DocumentTrackingScreen = lazy(() => import('./screens/DocumentTracking').then((m) => ({ default: m.DocumentTrackingScreen })))
const BlacklistScreen = lazy(() => import('./screens/Blacklist').then((m) => ({ default: m.BlacklistScreen })))
const LoanAdjustmentsScreen = lazy(() => import('./screens/LoanAdjustments').then((m) => ({ default: m.LoanAdjustmentsScreen })))
const ChequeClearanceScreen = lazy(() => import('./screens/ChequeClearance').then((m) => ({ default: m.ChequeClearanceScreen })))
const FdCommissionsScreen = lazy(() => import('./screens/FdCommissions').then((m) => ({ default: m.FdCommissionsScreen })))
const SalesExecutiveCommissionsScreen =lazy(() => import('./screens/SalesExecutiveCommissions').then((m) => ({ default: m.SalesExecutiveCommissionsScreen })))
const SpeedDraftTypesScreen = lazy(() => import('./screens/SpeedDraftTypes').then((m) => ({ default: m.SpeedDraftTypesScreen })))
const SpeedDraftsScreen = lazy(() => import('./screens/SpeedDrafts').then((m) => ({ default: m.SpeedDraftsScreen })))
const SpeedDraftPaymentReceiptScreen = lazy(() => import('./screens/PaymentReceipt').then((m) => ({ default: m.SpeedDraftPaymentReceiptScreen })))
const PawningTypesScreen = lazy(() => import('./screens/PawningTypes').then((m) => ({ default: m.PawningTypesScreen })))
const PawningsScreen = lazy(() => import('./screens/Pawnings').then((m) => ({ default: m.PawningsScreen })))
const PawningAuctionsScreen = lazy(() => import('./screens/PawningAuctions').then((m) => ({ default: m.PawningAuctionsScreen })))
const FundingDashboardScreen = lazy(() => import('./screens/FundingDashboard').then((m) => ({ default: m.FundingDashboardScreen })))
const FdLoanApplicationScreen = lazy(() => import('./screens/FdLoanApplication').then((m) => ({ default: m.FdLoanApplicationScreen })))
const FundingProductsScreen = lazy(() => import('./screens/FundingProducts').then((m) => ({ default: m.FundingProductsScreen })))
const DepositsScreen = lazy(() => import('./screens/Deposits').then((m) => ({ default: m.DepositsScreen })))
const FixedDepositsScreen = lazy(() => import('./screens/FixedDeposits').then((m) => ({ default: m.FixedDepositsScreen })))
const DepositPaymentScreen = lazy(() => import('./screens/DepositPayment').then((m) => ({ default: m.DepositPaymentScreen })))
const BorrowingsScreen = lazy(() => import('./screens/Borrowings').then((m) => ({ default: m.BorrowingsScreen })))
const BranchFundingScreen = lazy(() => import('./screens/BranchFunding').then((m) => ({ default: m.BranchFundingScreen })))
const PrematureWithdrawalScreen = lazy(() => import('./screens/PrematureWithdrawal').then((m) => ({ default: m.PrematureWithdrawalScreen })))
const FdInterestDueScreen = lazy(() => import('./screens/FdInterestDue').then((m) => ({ default: m.FdInterestDueScreen })))
const FundingActiveFdsReport = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingActiveFdsReport })))
const FundingMaturingSoonReport = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingMaturingSoonReport })))
const FundingMaturedReport = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingMaturedReport })))
const FundingMonthlyInterestReport = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingMonthlyInterestReport })))
const FundingInterestPaymentsReport = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingInterestPaymentsReport })))
const FundingDepositHistoryReport = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingDepositHistoryReport })))
const FundingCustomerFundingReport = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingCustomerFundingReport })))
const FundingRenewalHistoryReport = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingRenewalHistoryReport })))
const FundingCapitalEnhancementsReport = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingCapitalEnhancementsReport })))
const FundingWithdrawalsReport = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingWithdrawalsReport })))
const FundingReportsScreen = lazy(() => import('./screens/FundingReports').then((m) => ({ default: m.FundingReportsScreen })))
const SavingsProductsScreen = lazy(() => import('./screens/SavingsProducts').then((m) => ({ default: m.SavingsProductsScreen })))
const SavingsAccountsScreen = lazy(() => import('./screens/SavingsAccounts').then((m) => ({ default: m.SavingsAccountsScreen })))
const SavingsCollectionScreen = lazy(() => import('./screens/SavingsCollection').then((m) => ({ default: m.SavingsCollectionScreen })))
const SavingsInterestScreen = lazy(() => import('./screens/SavingsInterest').then((m) => ({ default: m.SavingsInterestScreen })))
const SavingsReportsScreen = lazy(() => import('./screens/SavingsReports').then((m) => ({ default: m.SavingsReportsScreen })))
const named = <M, K extends keyof M>(loader: () => Promise<M>, key: K) =>
  lazy(() => loader().then((m) => ({ default: m[key] as unknown as ComponentType<any> })))

const ApprovalsScreen = named(() => import('./screens/Approvals'), 'ApprovalsScreen')
const ApprovalTypesScreen = named(() => import('./screens/ApprovalTypes'), 'ApprovalTypesScreen')

const BranchesScreen = named(() => import('./screens/Admin'), 'BranchesScreen')
const RolesScreen = named(() => import('./screens/Admin'), 'RolesScreen')
const UsersScreen = named(() => import('./screens/Admin'), 'UsersScreen')
const PermScreen = named(() => import('./screens/Admin'), 'PermScreen')
const CompanyScreen = named(() => import('./screens/Admin'), 'CompanyScreen')
const MaintenanceScreen = named(() => import('./screens/Maintenance'), 'MaintenanceScreen')

const FinanceDashboardScreen = named(() => import('./screens/Finance'), 'FinanceDashboardScreen')
const FinanceRequestsScreen = named(() => import('./screens/Finance'), 'FinanceRequestsScreen')
const FinanceTransfersScreen = named(() => import('./screens/Finance'), 'FinanceTransfersScreen')
const CashFloatsScreen = named(() => import('./screens/Finance'), 'CashFloatsScreen')
const FinanceSettingsScreen = named(() => import('./screens/Finance'), 'FinanceSettingsScreen')
const CashTransactionsScreen = named(() => import('./screens/Finance'), 'CashTransactionsScreen')
const AuditLogScreen = named(() => import('./screens/Finance'), 'AuditLogScreen')
const FinanceReportsScreen = named(() => import('./screens/FinanceReports'), 'FinanceReportsScreen')

const CashbookScreen = named(() => import('./screens/Cashbook'), 'CashbookScreen')
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
const ManualJournalEntriesScreen = named(() => import('./screens/Accounting'), 'ManualJournalEntriesScreen')
const GeneralLedgerScreen = named(() => import('./screens/Accounting'), 'GeneralLedgerScreen')
const TrialBalanceScreen = named(() => import('./screens/Accounting'), 'TrialBalanceScreen')
const ProfitLossScreen = named(() => import('./screens/Accounting'), 'ProfitLossScreen')
const BalanceSheetScreen = named(() => import('./screens/Accounting'), 'BalanceSheetScreen')
const CashFlowScreen = named(() => import('./screens/Accounting'), 'CashFlowScreen')
const InterBranchScreen = named(() => import('./screens/Accounting'), 'InterBranchScreen')
const LedgerDriftScreen = named(() => import('./screens/Accounting'), 'LedgerDriftScreen')

const VendorsScreen = named(() => import('./screens/Expenses'), 'VendorsScreen')
const StaffScreen = named(() => import('./screens/Staff'), 'StaffScreen')
const ProfileScreen = named(() => import('./screens/Profile'), 'ProfileScreen')
const ExpensesScreen = named(() => import('./screens/Expenses'), 'ExpensesScreen')
const PaymentVouchersScreen = named(() => import('./screens/Expenses'), 'PaymentVouchersScreen')
const PettyCashScreen = named(() => import('./screens/PettyCash'), 'PettyCashScreen')
const FixedAssetsScreen = named(() => import('./screens/FixedAssets'), 'FixedAssetsScreen')

const LoanPortfolioSummaryScreen = named(() => import('./screens/LoanReports'), 'LoanPortfolioSummaryScreen')
const LoanParAgingScreen = named(() => import('./screens/LoanReports'), 'LoanParAgingScreen')
const LoanArrearsScreen = named(() => import('./screens/LoanReports'), 'LoanArrearsScreen')
const LoanDisbursementScreen = named(() => import('./screens/LoanReports'), 'LoanDisbursementScreen')
const LoanClosedLoansScreen = named(() => import('./screens/LoanReports'), 'LoanClosedLoansScreen')
const LoanDetailReportScreen = named(() => import('./screens/LoanReports'), 'LoanDetailReportScreen')
const SpeedDraftPortfolioSummaryScreen = named(() => import('./screens/SpeedDraftReports'), 'SpeedDraftPortfolioSummaryScreen')
const SpeedDraftParAgingScreen = named(() => import('./screens/SpeedDraftReports'), 'SpeedDraftParAgingScreen')
const SpeedDraftArrearsScreen = named(() => import('./screens/SpeedDraftReports'), 'SpeedDraftArrearsScreen')
const SpeedDraftDisbursementReportScreen = named(() => import('./screens/SpeedDraftReports'), 'SpeedDraftDisbursementReportScreen')
const SpeedDraftClosedScreen = named(() => import('./screens/SpeedDraftReports'), 'SpeedDraftClosedScreen')
const SpeedDraftCollectionsScreen = named(() => import('./screens/SpeedDraftReports'), 'SpeedDraftCollectionsScreen')
const SpeedDraftMaturityScreen = named(() => import('./screens/SpeedDraftReports'), 'SpeedDraftMaturityScreen')
const SpeedDraftDetailReportScreen = named(() => import('./screens/SpeedDraftReports'), 'SpeedDraftDetailReportScreen')

const CollectionDailyCashScreen = named(() => import('./screens/CollectionReports'), 'CollectionDailyCashScreen')
const CollectionSummaryScreen = named(() => import('./screens/CollectionReports'), 'CollectionSummaryScreen')
const CollectionOfficerPerformanceScreen = named(() => import('./screens/CollectionReports'), 'CollectionOfficerPerformanceScreen')
const CollectionDueScreen = named(() => import('./screens/CollectionReports'), 'CollectionDueScreen')
const CollectionTrendScreen = named(() => import('./screens/CollectionReports'), 'CollectionTrendScreen')

const CustomerSummaryScreen = named(() => import('./screens/CustomerReports'), 'CustomerSummaryScreen')
const CustomerActivityScreen = named(() => import('./screens/CustomerReports'), 'CustomerActivityScreen')
const CustomerBlacklistedScreen = named(() => import('./screens/CustomerReports'), 'CustomerBlacklistedScreen')
const CustomerCreditReportScreen = named(() => import('./screens/CustomerReports'), 'CustomerCreditReportScreen')

const InvestorSummaryScreen = named(() => import('./screens/InvestorReports'), 'InvestorSummaryScreen')
const InvestorDirectoryScreen = named(() => import('./screens/InvestorReports'), 'InvestorDirectoryScreen')

const CashBankPositionScreen = named(() => import('./screens/CashBankReports'), 'CashBankPositionScreen')
const CashbookActivityScreen = named(() => import('./screens/CashBankReports'), 'CashbookActivityScreen')
const BankActivityScreen = named(() => import('./screens/CashBankReports'), 'BankActivityScreen')

const ExpenseSummaryScreen = named(() => import('./screens/ExpenseReports'), 'ExpenseSummaryScreen')
const ExpenseListScreen = named(() => import('./screens/ExpenseReports'), 'ExpenseListScreen')
const ExpenseByVendorScreen = named(() => import('./screens/ExpenseReports'), 'ExpenseByVendorScreen')

const SubscriptionsScreen = named(() => import('./screens/Subscriptions'), 'SubscriptionsScreen')

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

// Server unreachable at boot. The session token is kept — only a real 401 may log the user out.
function BootOffline({ onRetry, retrying }: { onRetry: () => void; retrying: boolean }) {
  return (
    <div className="col center" style={{ minHeight: '100vh', padding: 24, textAlign: 'center', color: 'var(--tx-2)' }}>
      <h2 style={{ fontSize: 18, color: 'var(--tx-0)', marginBottom: 6 }}>Can't reach the server</h2>
      <p style={{ fontSize: 13, maxWidth: 380, marginBottom: 16 }}>
        Check your internet connection. You are still signed in — nothing was lost.
      </p>
      <Btn variant="primary" onClick={onRetry} disabled={retrying}>{retrying ? 'Retrying…' : 'Try again'}</Btn>
    </div>
  )
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms))

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
  'loan/inquiry': LoanInquiryScreen,
  'loan/types': LoanTypesScreen,
  'finance/calculator': LoanCalculatorScreen,
  'finance/dashboard': FinanceDashboardScreen,
  'finance/requests': FinanceRequestsScreen,
  'finance/transfers': FinanceTransfersScreen,
  'finance/floats': CashFloatsScreen,
  'finance/settings': FinanceSettingsScreen,
  'finance/transactions': CashTransactionsScreen,
  'finance/audit': AuditLogScreen,
  'finance/reports': FinanceReportsScreen,
  'loan/apps': LoanApplicationsScreen,
  'loan/fd-loan': FdLoanApplicationScreen,
  'loan/active': ActiveLoansScreen,
  'loan/disbursement': LoanDisbursementPageScreen,
  'loan/overdue': OverdueLoansScreen,
  'loan/provisions': LoanProvisionsScreen,
  'loan/payments': PaymentReceiptScreen,
  'loan/doc-tracking': DocumentTrackingScreen,
  'loan/cheques': ChequeClearanceScreen,
  'loan/adjustments': LoanAdjustmentsScreen,
  'blacklist': BlacklistScreen,
  'rep/loans-commissions': SalesExecutiveCommissionsScreen,
  'rep/fd-commissions': FdCommissionsScreen,
  'sd/calculator': SpeedDraftCalculatorScreen,
  'sd/types': SpeedDraftTypesScreen,
  'sd/drafts': SpeedDraftsScreen,
  'sd/payments': SpeedDraftPaymentReceiptScreen,
  'pawn/types': PawningTypesScreen,
  'pawn/tickets': PawningsScreen,
  'pawn/auctions': PawningAuctionsScreen,
  'ad/approvals': ApprovalsScreen,
  'ad/approval-types': ApprovalTypesScreen,
  'ad/branches': BranchesScreen,
  'ad/roles': RolesScreen,
  'ad/users': UsersScreen,
  'ad/perm': PermScreen,
  'ad/company': CompanyScreen,
  'ad/maintenance': MaintenanceScreen,
  'cash/book': CashbookScreen,
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
  'acc/mje': ManualJournalEntriesScreen,
  'acc/gl': GeneralLedgerScreen,
  'acc/tb': TrialBalanceScreen,
  'acc/pl': ProfitLossScreen,
  'acc/bs': BalanceSheetScreen,
  'acc/cf': CashFlowScreen,
  'acc/ib': InterBranchScreen,
  'acc/drift': LedgerDriftScreen,
  'exp/vendors': VendorsScreen,
  'exp/staff': StaffScreen,
  'profile': ProfileScreen,
  'exp/expenses': ExpensesScreen,
  'exp/vouchers': PaymentVouchersScreen,
  'exp/petty-cash': PettyCashScreen,
  'exp/fixed-assets': FixedAssetsScreen,
  'fund/dashboard': FundingDashboardScreen,
  'fund/products': FundingProductsScreen,
  'fund/deposits': DepositsScreen,
  'fund/fds': FixedDepositsScreen,
  'fund/deposit-payment': DepositPaymentScreen,
  'fund/borrowings': BorrowingsScreen,
  'fund/branch-funding': BranchFundingScreen,
  'fund/premature-withdrawal': PrematureWithdrawalScreen,
  'fund/interest-due': FdInterestDueScreen,
  'fund/reports': FundingReportsScreen,
  'rep/fund-active': FundingActiveFdsReport,
  'rep/fund-maturing': FundingMaturingSoonReport,
  'rep/fund-matured': FundingMaturedReport,
  'rep/fund-monthly-interest': FundingMonthlyInterestReport,
  'rep/fund-interest-paid': FundingInterestPaymentsReport,
  'rep/fund-deposits': FundingDepositHistoryReport,
  'rep/fund-customer': FundingCustomerFundingReport,
  'rep/fund-renewals': FundingRenewalHistoryReport,
  'rep/fund-enhancements': FundingCapitalEnhancementsReport,
  'rep/fund-withdrawals': FundingWithdrawalsReport,
  'fund/savings-products': SavingsProductsScreen,
  'fund/savings': SavingsAccountsScreen,
  'fund/savings-collection': SavingsCollectionScreen,
  'fund/savings-interest': SavingsInterestScreen,
  'rep/savings': SavingsReportsScreen,
  'rep/loans-summary': LoanPortfolioSummaryScreen,
  'rep/loans-par': LoanParAgingScreen,
  'rep/loans-arrears': LoanArrearsScreen,
  'rep/loans-disbursement': LoanDisbursementScreen,
  'rep/loans-closed': LoanClosedLoansScreen,
  'rep/loans-detail': LoanDetailReportScreen,
  'rep/sd-summary': SpeedDraftPortfolioSummaryScreen,
  'rep/sd-par': SpeedDraftParAgingScreen,
  'rep/sd-arrears': SpeedDraftArrearsScreen,
  'rep/sd-disbursement': SpeedDraftDisbursementReportScreen,
  'rep/sd-closed': SpeedDraftClosedScreen,
  'rep/sd-collections': SpeedDraftCollectionsScreen,
  'rep/sd-maturity': SpeedDraftMaturityScreen,
  'rep/sd-detail': SpeedDraftDetailReportScreen,
  'rep/coll-daily': CollectionDailyCashScreen,
  'rep/coll-summary': CollectionSummaryScreen,
  'rep/coll-officers': CollectionOfficerPerformanceScreen,
  'rep/coll-due': CollectionDueScreen,
  'rep/coll-trend': CollectionTrendScreen,
  'rep/cust-summary': CustomerSummaryScreen,
  'rep/cust-activity': CustomerActivityScreen,
  'rep/cust-blacklisted': CustomerBlacklistedScreen,
  'rep/cust-credit': CustomerCreditReportScreen,
  'rep/inv-summary': InvestorSummaryScreen,
  'rep/inv-directory': InvestorDirectoryScreen,
  'rep/cb-position': CashBankPositionScreen,
  'rep/cb-cashbook': CashbookActivityScreen,
  'rep/cb-bank': BankActivityScreen,
  'rep/exp-summary': ExpenseSummaryScreen,
  'rep/exp-list': ExpenseListScreen,
  'rep/exp-vendor': ExpenseByVendorScreen,
  'subs/list': SubscriptionsScreen,
}

// Every nav entry (built screens + "soon" placeholders) gets a real route, so
// every screen in the system has its own URL and its own browser-history
// entry — that's what makes Back/Forward and refresh behave correctly.
const ROUTE_IDS = [...Object.keys(flatNav), 'profile']

// Screens with a list + create/edit/detail flow also get /new and /:id
// sibling routes, so which record/form is open lives in the URL — that's
// what makes a hard refresh land back on the same form instead of the list.
const FORM_ROUTE_IDS = new Set([
  'loan/apps', 'loan/active', 'loan/disbursement', 'loan/overdue', 'customers', 'sd/drafts', 'pawn/tickets', 'pawn/auctions',
])

// One-off deeper sub-paths (a screen nested inside another screen's record
// view) that don't fit the flat /new + /:id shape above.
const EXTRA_ROUTES: { path: string; forId: string }[] = [
  { path: 'customers/:id/edit', forId: 'customers' },
  { path: 'customers/:id/credit-report', forId: 'customers' },
  { path: 'customers/:id/loan/:loanId', forId: 'customers' },
  { path: 'sd/drafts/:id/edit', forId: 'sd/drafts' },
  { path: 'loan/apps/:id/edit', forId: 'loan/apps' },
]

// Renders the current page plus every minimized page (kept mounted but hidden,
// so their in-progress form state survives navigation). Each instance gets its
// own <Routes location> so useLocation/useParams inside it stay on its own URL.
function PageHost({ renderRoutes }: { renderRoutes: () => ReactNode }) {
  const location = useLocation()
  const dock = useWinDock()
  const cur = location.pathname
  const keys = Array.from(new Set([...dock.items.map((i) => i.key), cur]))
  return (
    <>
      {keys.map((k) => {
        const visible = k === cur && !dock.isMin(k)
        const loc = k === cur ? location : (dock.items.find((i) => i.key === k)?.to ?? k)
        return (
          <div key={k} style={{ display: visible ? undefined : 'none' }}>
            <ErrorBoundary>
              <Routes location={loc}>
                {renderRoutes()}
                <Route index element={<Navigate to="/dash" replace />} />
                <Route path="*" element={<Navigate to="/dash" replace />} />
              </Routes>
            </ErrorBoundary>
          </div>
        )
      })}
    </>
  )
}

function renderAppRoutes(user: AuthUser, go: Go, setBrand: (b: { name: string; tagline: string }) => void): ReactNode {
  return (
    <>
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
    </>
  )
}

function AppShell() {
  const navigate = useNavigate()
  const location = useLocation()
  const [open, setOpen] = useState(false)
  const [brand, setBrand] = useState({ name: DB.company.name, tagline: DB.company.tagline })
  const [user, setUser] = useState<AuthUser | null>(null)
  const [authReady, setAuthReady] = useState(false)
  const go: Go = (r) => { navigate('/' + r); window.scrollTo(0, 0) }
  useEffect(() => { document.title = brand.name + ' — Admin Panel' }, [brand.name])

  const [bootOffline, setBootOffline] = useState(false)
  const [retrying, setRetrying] = useState(false)

  const loadSession = async () => {
    const token = auth.getToken()
    if (!token) { setAuthReady(true); return }
    setRetrying(true)
    const delays = [0, 1000, 2500]
    for (let i = 0; i < delays.length; i++) {
      if (delays[i]) await sleep(delays[i])
      try {
        setUser(await auth.me())
        setBootOffline(false)
        setRetrying(false)
        setAuthReady(true)
        return
      } catch (err: any) {
        const status = err?.response?.status
        // Network failure / timeout / 5xx: transient — retry, never drop the token.
        if (!status || status >= 500) continue
        auth.setToken(null) // 401/403/etc: session really is invalid
        setRetrying(false)
        setAuthReady(true)
        return
      }
    }
    setBootOffline(true)
    setRetrying(false)
    setAuthReady(true)
  }

  useEffect(() => {
    import('./api').then(({ company }) => {
      company.branding().then(b => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {})
    })
    loadSession()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (!authReady) return <BootLoader />
  if (bootOffline) return <BootOffline onRetry={loadSession} retrying={retrying} />

  return (
    <Routes>
      <Route
        path="/login"
        element={user ? <Navigate to={(location.state as any)?.from?.pathname || '/dash'} replace /> : <Login onAuth={setUser} />}
      />
      <Route
        element={
          user ? (
            <WinDockProvider><div style={{ display: 'flex', minHeight: '100vh' }}>
              <WinDockBar />
              <WhatsNewModal user={user} />
              <Sidebar go={go} open={open} setOpen={setOpen} company={brand} user={user} />
              <div className="mms-main" style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
                <Topbar setOpen={setOpen} go={go} user={user} />
                <main className="fade-in" style={{ padding: '24px clamp(16px,3vw,32px) 60px', flex: 1 }}>
                  <PageHost renderRoutes={() => renderAppRoutes(user, go, setBrand)} />
                </main>
              </div>
            </div></WinDockProvider>
          ) : (
            <Navigate to="/login" state={{ from: location }} replace />
          )
        }
      >
        <Route path="*" element={null} />
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
