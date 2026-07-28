/* Axios client for the Laravel API. Base URL is proxied to http://localhost:8000 in dev (see vite.config.ts). */
import axios from 'axios'

export const http = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  timeout: 30_000,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

const genReqId = () =>
  (typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : Math.random().toString(36).slice(2) + Date.now().toString(36))

http.interceptors.request.use((cfg) => {
  const t = localStorage.getItem('mms-token')
  if (t) cfg.headers.Authorization = `Bearer ${t}`
  cfg.headers['X-Request-Id'] = genReqId()
  return cfg
})

/* ---------- list cache (stale-while-fresh) ----------
   GET list endpoints are cached for a short window so re-visiting a screen is
   instant instead of round-tripping the server every time. Any successful write
   (POST/PUT/DELETE) clears the whole cache, so lists never go stale after a
   mutation — correctness over cleverness. */
const LIST_CACHE_TTL = 30_000
const listCache = new Map<string, { ts: number; data: any[] }>()
export const clearListCache = (name?: string) => { if (name) listCache.delete(name); else listCache.clear() }

let unauthorizedHandled = false
http.interceptors.response.use(
  (r) => {
    const m = r.config.method?.toLowerCase()
    if (m && m !== 'get') listCache.clear()
    return r
  },
  (err) => {
    const status = err?.response?.status
    if (status === 401 && localStorage.getItem('mms-token') && !unauthorizedHandled) {
      unauthorizedHandled = true
      localStorage.removeItem('mms-token')
      window.location.reload()
    }
    if (status === 429 && import.meta.env.DEV) {
      console.warn('[api] rate limited; retry after', err?.response?.headers?.['retry-after'])
    }
    return Promise.reject(err)
  },
)

/* ---------- snake_case <-> camelCase mapping ----------
   Laravel returns snake_case (avg_cost, stock_by_branch, raised_by).
   The React screens use camelCase. Transform at the API boundary
   so neither side has to care about the other's naming. */
const toCamel = (s: string) => s.replace(/_([a-z0-9])/g, (_, c) => c.toUpperCase())
const toSnake = (s: string) => s.replace(/[A-Z]/g, (c) => '_' + c.toLowerCase())

function deepMap(v: any, key: (s: string) => string): any {
  if (Array.isArray(v)) return v.map((x) => deepMap(x, key))
  if (v && typeof v === 'object' && v.constructor === Object) {
    const out: Record<string, any> = {}
    for (const k of Object.keys(v)) out[key(k)] = deepMap(v[k], key)
    return out
  }
  return v
}

const camelize = (v: any) => deepMap(v, toCamel)
const snakeize = (v: any) => deepMap(v, toSnake)

/* Generic CRUD helper. All requests/responses are auto-converted. */
export function resource<T = any>(name: string) {
  return {
    list: () => {
      const hit = listCache.get(name)
      if (hit && Date.now() - hit.ts < LIST_CACHE_TTL) return Promise.resolve(hit.data.slice() as T[])
      return http.get(`/${name}`).then((r) => {
        const data = camelize(r.data) as T[]
        listCache.set(name, { ts: Date.now(), data: data as any[] })
        return (data as any[]).slice() as T[]
      })
    },
    get: (id: string | number) => http.get(`/${name}/${id}`).then((r) => camelize(r.data) as T),
    create: (body: Partial<T>) => http.post(`/${name}`, snakeize(body)).then((r) => camelize(r.data) as T),
    update: (id: string | number, body: Partial<T>) => http.put(`/${name}/${id}`, snakeize(body)).then((r) => camelize(r.data) as T),
    remove: (id: string | number) => http.delete(`/${name}/${id}`).then((r) => r.data),
  }
}

export type BranchType = 'Branch' | 'Center'

export interface Branch {
  id: number; name: string; code: string;
  type?: BranchType;
  /** Owning branch — set only when type is 'Center'. */
  parentId?: number | null; parent?: { id: number; name: string; code: string } | null;
  address?: string | null; phone?: string | null; email?: string | null;
  isHeadOffice?: boolean; status?: 'Active' | 'Inactive';
}

export interface Role {
  id: number; name: string; slug: string; description?: string | null;
  isSystem?: boolean; canViewAllBranches?: boolean; usersCount?: number;
}

export type OccupationType = 'Employed' | 'SelfEmployed' | 'BusinessOwner' | 'Unemployed' | 'Retired' | 'Student' | 'Other'

export type CustomerTitle = 'Mr.' | 'Mrs.' | 'Miss' | 'Ms.' | 'Dr.' | 'Rev.'

export interface Customer {
  id: number; branchId: number; branch?: { id: number; name: string; code: string };
  code: string;
  /** Derived display name — "Title Initials Surname" (e.g. "Mr. J.K. Perera"). Not editable directly. */
  fullName: string;
  title?: CustomerTitle | null; surname: string;
  /** Given name(s) written out in full, excluding surname — for formal documents only. */
  nameInFull?: string | null;
  /** Abbreviated given-name initials, e.g. "J.K." */
  initials: string;
  nicNo?: string | null; passportNo?: string | null; drivingLicenseNo?: string | null;
  dob?: string | null; gender?: string | null;
  phone?: string | null; altPhone?: string | null; email?: string | null; address?: string | null;
  occupationType?: OccupationType | null;
  employerName?: string | null; jobTitle?: string | null; employerAddress?: string | null; employerPhone?: string | null; employmentStartDate?: string | null;
  businessName?: string | null; businessRegNo?: string | null; businessType?: string | null; businessAddress?: string | null; businessPhone?: string | null;
  monthlyIncome?: number | string | null;
  status?: 'Active' | 'Blacklisted' | 'Inactive';
  /** Label, not a restriction — any customer can still be picked as a loan guarantor regardless. */
  isGuarantor?: boolean;
  notes?: string | null;
}

export type CustomerDocType = 'NIC' | 'Passport' | 'DrivingLicense' | 'UtilityBill' | 'Photo' | 'Other'

export interface CustomerDocument {
  id: number; customerId: number; docType: CustomerDocType; filePath: string; createdAt?: string;
}

export interface AuthUser {
  id: number; name: string; email: string;
  /** Role slug — kept for back-compat with `role === 'admin'` checks. */
  role?: string;
  roleId?: number;
  branch?: { id: number; name: string; code: string } | null;
  status?: 'Active' | 'Inactive';
  /** Flat list of "Module:Action" strings the role is allowed to perform. */
  permissions?: string[];
}

export interface PermUser {
  id: number; name: string; email: string; role?: string; roleName?: string;
}

/** {module: {action: bool}} */
type Grid = Record<string, Record<string, boolean>>

export interface PermissionMatrix {
  users: PermUser[];
  modules: string[];
  actions: string[];
  /** Keyed by user id (as string). Effective (role + override) grid. */
  matrix: Record<string, Grid>;
  /** Keyed by user id (as string). Which cells are explicit overrides. */
  overrides: Record<string, Grid>;
}

export const permissionsApi = {
  list: () => http.get<PermissionMatrix>('/permissions').then((r) => r.data),
  updateUser: (userId: number, modules: Record<string, Record<string, boolean | null>>) =>
    http.put(`/permissions/${userId}`, { modules }).then((r) => r.data),
  roleGrid: (roleId: number) => http.get(`/roles/${roleId}/permissions`).then((r) => camelize(r.data) as { role: Role; modules: string[]; actions: string[]; matrix: Grid }),
  updateRole: (roleId: number, modules: Grid) =>
    http.put(`/roles/${roleId}/permissions`, { modules }).then((r) => r.data),
}

export type LoanFrequency = 'daily' | 'weekly' | 'monthly'
export type InterestMethod = 'flat' | 'reducing_balance'

export interface LoanType {
  id: number; parentId?: number | null;
  name: string; code: string;
  /** Template for this loan type's generated reference numbers, e.g. "{BRANCH}-{CODE}-{YEAR}-{SEQ:6}". Null = system default. */
  referenceFormat?: string | null;
  /** false = organizational category only (not selectable when issuing a loan) */
  isProduct: boolean;
  frequency?: LoanFrequency | null;
  /** Daily products only — which calendar days installments are due on. */
  collectionDays?: 'all_days' | 'week_days' | null;
  interestMethod?: InterestMethod | null;
  interestRatePercent?: number | string | null;
  ratePeriod?: 'monthly' | 'annual' | null;
  minAmount?: number | string | null;
  maxAmount?: number | string | null;
  minTermMonths?: number | null;
  maxTermMonths?: number | null;
  penaltyRatePercent?: number | string | null;
  penaltyMethod?: 'flat' | 'daily' | null;
  /** Days after due_date before default interest starts accruing. Default 5. */
  gracePeriodDays?: number | null;
  processingFeePercent?: number | string | null;
  sortOrder?: number;
  status?: 'Active' | 'Inactive';
}

export type LoanStatus = 'Pending' | 'Approved' | 'Rejected' | 'Active' | 'Closed' | 'WrittenOff' | 'Restructured'

export interface LoanGuarantorInput {
  guarantorId: number;
  guaranteedAmount: number | string;
  guarantor?: { id: number; fullName: string; code: string; nicNo?: string | null; phone?: string | null };
}

export interface LoanCollateralInput {
  id?: number;
  type: string;
  description: string;
  estimatedValue?: number | string | null;
}

export interface Loan {
  id: number; code: string;
  branchId: number; branch?: { id: number; name: string; code: string };
  customerId: number; customer?: { id: number; fullName: string; code: string; nicNo?: string | null; phone?: string | null };
  loanTypeId: number; loanType?: { id: number; name: string; code: string; parentId?: number | null };
  principalAmount: number | string;
  /** Paid upfront; the schedule amortizes principalAmount - downPayment. */
  downPayment?: number | string;
  interestRatePercent?: number | string;
  interestMethod?: InterestMethod;
  termMonths: number;
  installmentFrequency: LoanFrequency;
  purpose?: string | null;
  loanOfficerId?: number | null; loanOfficer?: { id: number; name: string } | null;
  recoveryOfficerId?: number | null; recoveryOfficer?: { id: number; name: string } | null;
  status: LoanStatus;
  appliedBy?: number | null; applier?: { id: number; name: string } | null;
  approver?: { id: number; name: string } | null;
  approvedAt?: string | null;
  rejectedReason?: string | null;
  guarantors?: LoanGuarantorInput[];
  collateral?: LoanCollateralInput[];
  schedules?: LoanSchedule[];
  payments?: LoanPayment[];
  charges?: LoanCharge[];
  postDatedCheques?: LoanPostDatedCheque[];
  documents?: LoanDocument[];
  diaryNotes?: LoanDiaryNote[];
  waivers?: LoanWaiver[];
  summary?: LoanSummary;
  disbursedAmount?: number | string | null;
  disbursedAt?: string | null;
  firstInstallmentDate?: string | null;
  isTerminated?: boolean; terminatedAt?: string | null;
  isLegal?: boolean; legalAt?: string | null;
  seizingOrderIssued?: boolean; seizingOrderAt?: string | null;
  isRepossessed?: boolean; repossessedAt?: string | null;
  freezeDate?: string | null;
  confirmedDate?: string | null; confirmedBy?: { id: number; name: string } | null;
  /** From index()'s withSum — total scheduled vs paid across all installments, for a cheap outstanding-balance column. */
  totalDueSum?: number | string | null;
  paidSum?: number | string | null;
  createdAt?: string;
}

export type ScheduleStatus = 'Pending' | 'Paid' | 'Partial' | 'Overdue'

export interface LoanSchedule {
  id: number; loanId: number; installmentNo: number; dueDate: string;
  principalDue: number | string; interestDue: number | string; penaltyDue: number | string; totalDue: number | string;
  paidAmount: number | string; paidAt?: string | null; status: ScheduleStatus;
}

export type PaymentMethod = 'Cash' | 'Bank' | 'Cheque'
export type PaymentStatus = 'Active' | 'Voided'
export type PaymentApprovalStatus = 'Approved' | 'PendingApproval' | 'Rejected'
export type PaymentClearanceStatus = 'Pending' | 'Realized' | 'Bounced'

export interface LoanPayment {
  id: number; code: string; loanId: number;
  amount: number | string; paymentMethod: PaymentMethod;
  receiver?: { id: number; name: string } | null;
  paidAt: string; receiptNo?: string | null; notes?: string | null;
  /** Only set when paymentMethod is Cheque. */
  bankName?: string | null; chequeDate?: string | null;
  /** Cheque payments only — null for Cash/Bank/settlement. Starts 'Pending' and holds the
   *  amount out of the loan's balance until manually cleared via loanPaymentsApi.clearCheque(). */
  clearanceStatus?: PaymentClearanceStatus | null;
  status?: PaymentStatus;
  /** A full-settlement request pays off the whole outstanding balance and needs
   *  approval before it's allocated/closes the loan — see approvalStatus. */
  isSettlement?: boolean;
  approvalStatus?: PaymentApprovalStatus;
  approver?: { id: number; name: string } | null; approvedAt?: string | null;
  voidReason?: string | null; voider?: { id: number; name: string } | null; voidedAt?: string | null;
  createdAt?: string;
}

export type LoanChargeType = 'Document' | 'Legal' | 'Valuation' | 'Other'

export interface LoanCharge {
  id: number; loanId: number; chargeType: LoanChargeType;
  dueAmount: number | string; paidAmount: number | string; chargedAt: string;
  notes?: string | null; creator?: { id: number; name: string } | null;
}

export type ChequeStatus = 'Pending' | 'Handed' | 'Realized' | 'Bounced' | 'Returned'

export interface LoanPostDatedCheque {
  id: number; loanId: number; chequeNo: string; bankName: string; chequeDate: string;
  amount: number | string; status: ChequeStatus; handedAt?: string | null; notes?: string | null;
  creator?: { id: number; name: string } | null;
  /** Present only on the cross-loan listing (Cheque Management screen), not the per-loan panel. */
  loan?: { id: number; code: string; customer?: { id: number; fullName: string } | null; branch?: { id: number; name: string; code: string } | null } | null;
  /** Cross-loan listing only. 'PostDated' = a real cheque record you can Realize/Bounce.
   *  'Payment' = an instant Cheque-method payment already applied to the balance — shown
   *  read-only as Realized so the screen is a complete cheque history. */
  source?: 'PostDated' | 'Payment';
}

export type LoanDocType = 'ContractImage' | 'Agreement' | 'IdCopy' | 'Other'

export interface LoanDocument {
  id: number; loanId: number; docType: LoanDocType; filePath: string; createdAt?: string;
}

export interface LoanDiaryNote {
  id: number; loanId: number; note: string; followUpDate?: string | null;
  creator?: { id: number; name: string } | null; createdAt?: string;
}

export type WaiverType = 'Interest' | 'Penalty' | 'Charge' | 'Principal'

export interface LoanWaiver {
  id: number; loanId: number; waiverType: WaiverType; amount: number | string;
  reason?: string | null; waivedAt: string;
  approver?: { id: number; name: string } | null;
}

export interface LoanDueTypeRow {
  type: string; due: number; paid: number; balance: number; recoveryPct: number;
}

export interface LoanSummary {
  dueBreakdown: LoanDueTypeRow[];
  arrears: {
    rentalArrears: number; otherChargeArrears: number; defaultCharges: number; totalArrears: number;
    overPayment: number; futureCapital: number; futureInterest: number; totalInterestRemaining: number;
    balancePrePayment: number; amountToBeCollected: number;
  };
  recovery: {
    lastPaymentDate: string | null; lastPaymentAmount: number | null;
    fallenDueRentals: number; settledRentals: number; outstandingRentals: number; futureRentals: number;
  };
}

export interface LoanStatusFlags {
  isTerminated?: boolean; terminatedAt?: string;
  isLegal?: boolean; legalAt?: string;
  seizingOrderIssued?: boolean; seizingOrderAt?: string;
  isRepossessed?: boolean; repossessedAt?: string;
  freezeDate?: string;
}

export const branchesApi = resource<Branch>('branches')
export const customersApi = resource<Customer>('customers')
export const loanTypesApi = resource<LoanType>('loan-types')

export type BankAccountType = 'Savings' | 'Current' | 'Fixed Deposit'

export interface BankAccount {
  id: number;
  branchId?: number | null; branch?: { id: number; name: string; code: string } | null;
  name: string; bankName: string; branchName?: string | null;
  accountNumber: string; accountType?: BankAccountType;
  currency?: string;
  openingBalance?: number | string; currentBalance?: number | string;
  status?: 'Active' | 'Inactive';
  notes?: string | null;
}

export type BankTxnType = 'Deposit' | 'Withdrawal' | 'TransferIn' | 'TransferOut'
export type BankTxnStatus = 'Cleared' | 'Pending'

export interface BankTransaction {
  id: number;
  bankAccountId: number; bankAccount?: { id: number; name: string; bankName: string; accountNumber: string };
  txnDate: string; type: BankTxnType;
  referenceNo?: string | null; description?: string | null;
  amount: number | string; balanceAfter?: number | string;
  status?: BankTxnStatus;
  creator?: { id: number; name: string } | null;
  createdAt?: string;
}

export type BankReconStatus = 'Pending' | 'Reconciled'

export interface BankReconciliation {
  id: number;
  bankAccountId: number; bankAccount?: { id: number; name: string; bankName: string; accountNumber: string };
  statementDate: string;
  statementBalance: number | string; bookBalance?: number | string; difference?: number | string;
  status?: BankReconStatus;
  notes?: string | null;
  reconciler?: { id: number; name: string } | null;
  reconciledAt?: string | null;
}

export const bankAccountsApi = resource<BankAccount>('bank-accounts')

export const bankTransactionsApi = {
  list: (bankAccountId?: number) =>
    http.get('/bank-transactions', { params: bankAccountId ? { bank_account_id: bankAccountId } : {} }).then((r) => camelize(r.data) as BankTransaction[]),
  create: (body: Partial<BankTransaction>) => http.post('/bank-transactions', snakeize(body)).then((r) => camelize(r.data) as BankTransaction),
  remove: (id: number) => http.delete(`/bank-transactions/${id}`).then((r) => r.data),
}

export const bankReconciliationsApi = {
  list: (bankAccountId?: number) =>
    http.get('/bank-reconciliations', { params: bankAccountId ? { bank_account_id: bankAccountId } : {} }).then((r) => camelize(r.data) as BankReconciliation[]),
  create: (body: Partial<BankReconciliation>) => http.post('/bank-reconciliations', snakeize(body)).then((r) => camelize(r.data) as BankReconciliation),
  update: (id: number, body: Partial<BankReconciliation>) => http.put(`/bank-reconciliations/${id}`, snakeize(body)).then((r) => camelize(r.data) as BankReconciliation),
  remove: (id: number) => http.delete(`/bank-reconciliations/${id}`).then((r) => r.data),
}

/** Shared lookup/category lists (Expense Categories, Bank Account Types, ...).
 *  `type` is the whitelist key from the backend's MasterCategoryController::TYPES —
 *  add a new lookup list there, then a screen instance here with the same type. */
export type MasterCategoryType = 'expense_category' | 'bank_account_type'

export interface MasterCategory {
  id: number;
  type: MasterCategoryType;
  name: string;
  code?: string | null;
  description?: string | null;
  status?: 'Active' | 'Inactive';
  sortOrder?: number;
}

export const masterCategoriesApi = {
  list: (type: MasterCategoryType) =>
    http.get('/master-categories', { params: { type } }).then((r) => camelize(r.data) as MasterCategory[]),
  create: (body: Partial<MasterCategory>) => http.post('/master-categories', snakeize(body)).then((r) => camelize(r.data) as MasterCategory),
  update: (id: number, body: Partial<MasterCategory>) => http.put(`/master-categories/${id}`, snakeize(body)).then((r) => camelize(r.data) as MasterCategory),
  remove: (id: number) => http.delete(`/master-categories/${id}`).then((r) => r.data),
}

export interface GeneratedNumber {
  id: number; module: string; number: string;
  branchId?: number | null; branch?: { id: number; name: string; code: string } | null;
  loanTypeId?: number | null; loanType?: { id: number; name: string; code: string } | null;
  year?: number | null; sequence: number;
  generatedBy?: number | null; generator?: { id: number; name: string } | null;
  createdAt?: string;
}

export const generatedNumbersApi = resource<GeneratedNumber>('generated-numbers')

export const loansApi = {
  list: () => http.get('/loans').then((r) => camelize(r.data) as Loan[]),
  get: (id: number) => http.get(`/loans/${id}`).then((r) => camelize(r.data) as Loan),
  create: (body: Partial<Loan>) => http.post('/loans', snakeize(body)).then((r) => camelize(r.data) as Loan),
  update: (id: number, body: Partial<Loan>) => http.put(`/loans/${id}`, snakeize(body)).then((r) => camelize(r.data) as Loan),
  remove: (id: number) => http.delete(`/loans/${id}`).then((r) => r.data),
  approve: (id: number) => http.post(`/loans/${id}/approve`).then((r) => camelize(r.data) as Loan),
  reject: (id: number, reason: string) => http.post(`/loans/${id}/reject`, { rejected_reason: reason }).then((r) => camelize(r.data) as Loan),
  disburse: (id: number, body: { disbursedAmount?: number | string; disbursedAt?: string; firstInstallmentDate: string }) =>
    http.post(`/loans/${id}/disburse`, snakeize(body)).then((r) => camelize(r.data) as Loan),
  updateStatusFlags: (id: number, body: LoanStatusFlags) =>
    http.patch(`/loans/${id}/status-flags`, snakeize(body)).then((r) => camelize(r.data) as Loan),
}

export const loanSchedulesApi = {
  /** Pushes an unpaid installment to the back of the schedule (due one period
   *  after the current last installment) instead of collecting it on time. */
  skip: (loanId: number, scheduleId: number) => http.post(`/loans/${loanId}/schedules/${scheduleId}/skip`).then((r) => camelize(r.data) as Loan),
}

export const loanPaymentsApi = {
  list: (loanId: number) => http.get(`/loans/${loanId}/payments`).then((r) => camelize(r.data) as LoanPayment[]),
  create: (loanId: number, body: { amount: number | string; paymentMethod?: PaymentMethod; paidAt?: string; receiptNo?: string; bankName?: string; chequeDate?: string; notes?: string; isSettlement?: boolean }) =>
    http.post(`/loans/${loanId}/payments`, snakeize(body)).then((r) => camelize(r.data) as LoanPayment),
  remove: (paymentId: number, voidReason?: string) => http.delete(`/loan-payments/${paymentId}`, { data: { void_reason: voidReason } }).then((r) => r.data),
  approveSettlement: (paymentId: number) => http.post(`/loan-payments/${paymentId}/approve-settlement`).then((r) => camelize(r.data) as LoanPayment),
  rejectSettlement: (paymentId: number, voidReason?: string) =>
    http.post(`/loan-payments/${paymentId}/reject-settlement`, snakeize({ voidReason })).then((r) => camelize(r.data) as LoanPayment),
  /** Realize applies the held amount to the schedule; Bounced voids the payment (it was never applied). */
  clearCheque: (paymentId: number, status: 'Realized' | 'Bounced') =>
    http.patch(`/loan-payments/${paymentId}/clear-cheque`, { status }).then((r) => camelize(r.data) as LoanPayment),
}

export const loanChargesApi = {
  list: (loanId: number) => http.get(`/loans/${loanId}/charges`).then((r) => camelize(r.data) as LoanCharge[]),
  create: (loanId: number, body: { chargeType: LoanChargeType; dueAmount: number | string; paidAmount?: number | string; chargedAt?: string; notes?: string }) =>
    http.post(`/loans/${loanId}/charges`, snakeize(body)).then((r) => camelize(r.data) as LoanCharge),
  update: (chargeId: number, body: Partial<LoanCharge>) => http.put(`/loan-charges/${chargeId}`, snakeize(body)).then((r) => camelize(r.data) as LoanCharge),
  remove: (chargeId: number) => http.delete(`/loan-charges/${chargeId}`).then((r) => r.data),
}

export const loanPostDatedChequesApi = {
  list: (loanId: number) => http.get(`/loans/${loanId}/post-dated-cheques`).then((r) => camelize(r.data) as LoanPostDatedCheque[]),
  /** Cross-loan listing for the Cheque Management screen. */
  listAll: (params?: { status?: ChequeStatus; dueToday?: boolean }) =>
    http.get('/post-dated-cheques', { params: { status: params?.status, due_today: params?.dueToday ? 1 : undefined } }).then((r) => camelize(r.data) as LoanPostDatedCheque[]),
  create: (loanId: number, body: { chequeNo: string; bankName: string; chequeDate: string; amount: number | string; notes?: string }) =>
    http.post(`/loans/${loanId}/post-dated-cheques`, snakeize(body)).then((r) => camelize(r.data) as LoanPostDatedCheque),
  updateStatus: (chequeId: number, status: ChequeStatus, notes?: string) =>
    http.patch(`/loan-post-dated-cheques/${chequeId}`, { status, notes }).then((r) => camelize(r.data) as LoanPostDatedCheque),
  remove: (chequeId: number) => http.delete(`/loan-post-dated-cheques/${chequeId}`).then((r) => r.data),
}

export const loanDocumentsApi = {
  list: (loanId: number) => http.get(`/loans/${loanId}`).then((r) => (camelize(r.data).documents || []) as LoanDocument[]),
  upload: (loanId: number, file: File, docType: LoanDocType) => {
    const form = new FormData()
    form.append('file', file)
    form.append('doc_type', docType)
    return http.post(`/loans/${loanId}/documents`, form).then((r) => camelize(r.data) as LoanDocument)
  },
  remove: (loanId: number, documentId: number) => http.delete(`/loans/${loanId}/documents/${documentId}`).then((r) => r.data),
  download: (loanId: number, documentId: number) =>
    http.get(`/loans/${loanId}/documents/${documentId}/file`, { responseType: 'blob' }).then((r) => r.data as Blob),
}

export const loanDiaryNotesApi = {
  list: (loanId: number) => http.get(`/loans/${loanId}/diary-notes`).then((r) => camelize(r.data) as LoanDiaryNote[]),
  create: (loanId: number, body: { note: string; followUpDate?: string }) =>
    http.post(`/loans/${loanId}/diary-notes`, snakeize(body)).then((r) => camelize(r.data) as LoanDiaryNote),
  remove: (noteId: number) => http.delete(`/loan-diary-notes/${noteId}`).then((r) => r.data),
}

export const loanWaiversApi = {
  list: (loanId: number) => http.get(`/loans/${loanId}/waivers`).then((r) => camelize(r.data) as LoanWaiver[]),
  create: (loanId: number, body: { waiverType: WaiverType; amount: number | string; reason?: string; waivedAt?: string }) =>
    http.post(`/loans/${loanId}/waivers`, snakeize(body)).then((r) => camelize(r.data) as LoanWaiver),
  remove: (waiverId: number) => http.delete(`/loan-waivers/${waiverId}`).then((r) => r.data),
}

export const customerDocumentsApi = {
  list: (customerId: number) => http.get(`/customers/${customerId}`).then((r) => (camelize(r.data).documents || []) as CustomerDocument[]),
  upload: (customerId: number, file: File, docType: CustomerDocType) => {
    const form = new FormData()
    form.append('file', file)
    form.append('doc_type', docType)
    return http.post(`/customers/${customerId}/documents`, form).then((r) => camelize(r.data) as CustomerDocument)
  },
  remove: (customerId: number, documentId: number) =>
    http.delete(`/customers/${customerId}/documents/${documentId}`).then((r) => r.data),
  /** Fetches the file as a blob (the route is auth-protected, so it can't be a plain <a href>). */
  download: (customerId: number, documentId: number) =>
    http.get(`/customers/${customerId}/documents/${documentId}/file`, { responseType: 'blob' }).then((r) => r.data as Blob),
}

export const customerLoansApi = {
  /** Every loan this customer has ever borrowed (any status), plus every loan they guarantee. */
  list: (customerId: number) =>
    http.get(`/customers/${customerId}/loans`).then((r) => camelize(r.data) as { borrower: Loan[]; guarantor: Loan[] }),
}

/* ======================= Funding (Fixed Deposit) Management ======================= */

export type FdInterestMethod = 'monthly' | 'maturity'
export type FdPaymentMethod = 'Cash' | 'Cheque' | 'BankTransfer'
export type FdDepositStatus = 'Available' | 'Allocated'
export type FixedDepositStatus = 'Active' | 'Closed' | 'Renewed'

export interface FundingProduct {
  id: number;
  name: string; code: string;
  interestMethod: FdInterestMethod;
  annualRatePercent: number | string;
  /** Auto = annualRatePercent/12 unless rateIsManual. */
  monthlyRatePercent?: number | string | null;
  rateIsManual?: boolean;
  allowedTermsMonths: number[];
  allowCustomTerm?: boolean;
  minDepositAmount: number | string;
  maxDepositAmount?: number | string | null;
  /** Template for this product's generated FD numbers, e.g. "{BRANCH}-FD-{CODE}-{YEAR}-{SEQ:6}". Null = system default. */
  referenceFormat?: string | null;
  sortOrder?: number;
  status?: 'Active' | 'Inactive';
}

export interface FdDeposit {
  id: number; depositNumber: string;
  branchId: number; branch?: { id: number; name: string; code: string };
  customerId: number; customer?: { id: number; fullName: string; code: string; nicNo?: string | null; phone?: string | null };
  depositDate: string; amount: number | string;
  paymentMethod: FdPaymentMethod;
  referenceNo?: string | null; bankName?: string | null; notes?: string | null;
  status: FdDepositStatus;
  fixedDepositId?: number | null; fixedDeposit?: { id: number; fdNumber: string } | null;
  creator?: { id: number; name: string } | null;
  createdAt?: string;
}

export interface FdInterestPayment {
  id: number; fixedDepositId: number;
  installmentNo: number; dueDate: string; amount: number | string;
  status: 'Pending' | 'Paid';
  paidAmount?: number | string; paidAt?: string | null;
  paymentMethod?: FdPaymentMethod | null; referenceNo?: string | null;
  payer?: { id: number; name: string } | null;
}

export interface FdWithdrawal {
  id: number; fixedDepositId: number; voucherNo: string;
  principalAmount: number | string; interestAmount: number | string; totalAmount: number | string;
  paymentMethod: FdPaymentMethod; referenceNo?: string | null; notes?: string | null;
  closedAt: string;
  creator?: { id: number; name: string } | null;
}

export interface FdRenewal {
  id: number;
  oldFixedDepositId: number; oldFixedDeposit?: { id: number; fdNumber: string; customer?: { id: number; fullName: string; code: string } } | null;
  newFixedDepositId: number; newFixedDeposit?: { id: number; fdNumber: string } | null;
  oldPrincipal: number | string; additionalDepositAmount: number | string; newPrincipal: number | string;
  additionalDepositId?: number | null; additionalDeposit?: FdDeposit | null;
  newTermMonths: number; renewedAt: string; notes?: string | null;
  creator?: { id: number; name: string } | null;
}

export interface FixedDeposit {
  id: number; fdNumber: string;
  branchId: number; branch?: { id: number; name: string; code: string };
  customerId: number; customer?: { id: number; fullName: string; code: string; nicNo?: string | null; phone?: string | null; email?: string | null; address?: string | null };
  fundingProductId: number; fundingProduct?: FundingProduct;
  principalAmount: number | string;
  interestRatePercent: number | string;
  interestPaymentMethod: FdInterestMethod;
  termMonths: number; startDate: string; maturityDate: string;
  monthlyInterestAmount?: number | string | null;
  totalInterestAmount: number | string;
  maturityAmount: number | string;
  nextInterestDate?: string | null;
  status: FixedDepositStatus;
  /** Derived server-side: status === 'Active' && maturityDate <= today. Not a stored value. */
  isMatured?: boolean;
  renewedFromId?: number | null; renewedFrom?: { id: number; fdNumber: string } | null;
  renewedToId?: number | null; renewedTo?: { id: number; fdNumber: string } | null;
  closedAt?: string | null;
  deposits?: FdDeposit[];
  interestPayments?: FdInterestPayment[];
  withdrawal?: FdWithdrawal | null;
  createdAt?: string;
}

export const fundingProductsApi = resource<FundingProduct>('funding-products')

export const fdDepositsApi = {
  list: (params?: { status?: FdDepositStatus; customerId?: number }) =>
    http.get('/fd-deposits', { params }).then((r) => camelize(r.data) as FdDeposit[]),
  get: (id: number) => http.get(`/fd-deposits/${id}`).then((r) => camelize(r.data) as FdDeposit),
  create: (body: { branchId: number; customerId: number; depositDate: string; amount: number | string; paymentMethod: FdPaymentMethod; referenceNo?: string; bankName?: string; notes?: string }) =>
    http.post('/fd-deposits', snakeize(body)).then((r) => camelize(r.data) as FdDeposit),
  remove: (id: number) => http.delete(`/fd-deposits/${id}`).then((r) => r.data),
}

export interface FdEnhancementInput {
  amount: number | string; paymentMethod: FdPaymentMethod;
  depositDate?: string; referenceNo?: string; bankName?: string; notes?: string;
}

export const fixedDepositsApi = {
  list: (params?: { status?: FixedDepositStatus; customerId?: number; matured?: boolean; maturingWithinDays?: number }) =>
    http.get('/fixed-deposits', { params: snakeize(params || {}) }).then((r) => camelize(r.data) as FixedDeposit[]),
  get: (id: number) => http.get(`/fixed-deposits/${id}`).then((r) => camelize(r.data) as FixedDeposit),
  create: (body: { depositId: number; fundingProductId: number; termMonths: number; startDate: string }) =>
    http.post('/fixed-deposits', snakeize(body)).then((r) => camelize(r.data) as FixedDeposit),
  renew: (id: number, body: { termMonths: number; notes?: string; enhancement?: FdEnhancementInput }) =>
    http.post(`/fixed-deposits/${id}/renew`, snakeize(body)).then((r) => camelize(r.data) as { fixedDeposit: FixedDeposit; enhancementDeposit: FdDeposit | null }),
  withdraw: (id: number, body: { paymentMethod: FdPaymentMethod; referenceNo?: string; notes?: string; closedAt?: string }) =>
    http.post(`/fixed-deposits/${id}/withdraw`, snakeize(body)).then((r) => camelize(r.data) as FdWithdrawal & { fixedDeposit: FixedDeposit }),
}

export const fdInterestPaymentsApi = {
  list: (fixedDepositId: number) => http.get(`/fixed-deposits/${fixedDepositId}/interest-payments`).then((r) => camelize(r.data) as FdInterestPayment[]),
  pay: (id: number, body: { paidAt?: string; paymentMethod: FdPaymentMethod; referenceNo?: string }) =>
    http.post(`/fd-interest-payments/${id}/pay`, snakeize(body)).then((r) => camelize(r.data) as FdInterestPayment),
}

export type FundingReportType =
  | 'active' | 'maturing-soon' | 'matured' | 'monthly-interest' | 'interest-paid'
  | 'deposit-history' | 'customer-funding-history' | 'renewal-history' | 'capital-enhancements'

export const fundingReportsApi = {
  get: (type: FundingReportType, params?: Record<string, any>) =>
    http.get(`/funding-reports/${type}`, { params: snakeize(params || {}) }).then((r) => camelize(r.data)),
}

export interface FundingDashboardSummary {
  totalFundingPortfolio: number; activeFds: number; monthlyInterestPayable: number;
  maturingThisMonth: number; newDepositsToday: number; renewalsThisMonth: number;
}

export const fundingDashboardApi = {
  get: () => http.get('/funding-dashboard').then((r) => camelize(r.data) as FundingDashboardSummary),
}

export const customerFundingApi = {
  /** Every deposit and Fixed Deposit this customer holds. */
  list: (customerId: number) =>
    http.get(`/customers/${customerId}/funding`).then((r) => camelize(r.data) as { deposits: FdDeposit[]; fixedDeposits: FixedDeposit[] }),
}

export const rolesApi = {
  list: () => http.get<Role[]>('/roles').then((r) => camelize(r.data) as Role[]),
  create: (body: { name: string; description?: string; canViewAllBranches?: boolean }) =>
    http.post('/roles', snakeize(body)).then((r) => camelize(r.data) as Role),
  update: (id: number, body: { name?: string; description?: string; canViewAllBranches?: boolean }) =>
    http.put(`/roles/${id}`, snakeize(body)).then((r) => camelize(r.data) as Role),
  remove: (id: number) => http.delete(`/roles/${id}`).then((r) => r.data),
}

export const auth = {
  login: (email: string, password: string) =>
    http.post<{ token: string; user: AuthUser }>('/login', { email, password }).then((r) => r.data),
  me: () => http.get<AuthUser>('/me').then((r) => r.data),
  logout: () => http.post('/logout').then((r) => r.data),
  setToken: (t: string | null) => {
    if (t) localStorage.setItem('mms-token', t)
    else localStorage.removeItem('mms-token')
  },
  getToken: () => localStorage.getItem('mms-token'),
}

const API_BASE = import.meta.env.VITE_API_URL || '/api'

export const company = {
  get: () => http.get('/company-profile').then((r) => camelize(r.data)),
  update: (body: any) => http.put('/company-profile', snakeize(body)).then((r) => camelize(r.data)),
  /** Public, pre-auth — name/tagline only, for the login screen. */
  branding: () => http.get('/company-profile/branding').then((r) => camelize(r.data)) as Promise<{ name: string; tagline: string; hasLogo: boolean }>,
  uploadLogo: (file: File) => {
    const form = new FormData()
    form.append('logo', file)
    return http.post('/company-profile/logo', form).then((r) => camelize(r.data))
  },
  /** Always the same endpoint — append a cache-busting `?v=` when rendering. */
  logoUrl: `${API_BASE}/company-profile/logo`,
}

export const api = {
  users: resource('users'),
  dashboard: () => http.get('/dashboard').then((r) => camelize(r.data)),
}

export default api
