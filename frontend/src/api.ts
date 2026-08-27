/* Axios client for the Laravel API. Base URL is proxied to http://localhost:8000 in dev (see vite.config.ts). */
import axios from 'axios'
import { beginDownload, endDownload } from './lib/downloadProgress'

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
  if (cfg.responseType === 'blob') beginDownload()
  return cfg
})

/* ---------- list cache (stale-while-fresh) -----
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
    if (r.config.responseType === 'blob') endDownload()
    return r
  },
  (err) => {
    if (err?.config?.responseType === 'blob') endDownload()
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
  branchType?: BranchType;
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

export interface CustomerEmploymentDetail {
  employerName?: string | null; jobTitle?: string | null; employerAddress?: string | null; employerPhone?: string | null; employmentStartDate?: string | null;
}
export interface CustomerBusinessDetail {
  businessName?: string | null; businessRegNo?: string | null; businessType?: string | null; businessAddress?: string | null; businessPhone?: string | null;
}

export interface Customer {
  id: number; branchId: number; branch?: { id: number; name: string; code: string };
  code: string;
  /** Derived display name — "Title Initials Surname" (e.g. "Mr. J.K. Perera"). Not editable directly. */
  fullName: string;
  title?: CustomerTitle | null; surname: string;
  /** Given name(s) written out in full, excluding surname — for formal documents only. */
  nameDenotedByInitials?: string | null;
  /** Abbreviated given-name initials, e.g. "J.K." */
  initials: string;
  nicNo?: string | null; passportNo?: string | null; drivingLicenseNo?: string | null;
  dob?: string | null; gender?: string | null;
  phone?: string | null; altPhone?: string | null; email?: string | null; address?: string | null;
  occupationType?: OccupationType | null;
  /** Write-only convenience — sending these flat on create/update is still accepted (see
   *  CustomerController::syncDetail) and gets split server-side into the 1:1 detail tables
   *  below. On READ, the persisted values come back nested under employmentDetail/
   *  businessDetail instead — these flat fields are never populated in a GET response. */
  employerName?: string | null; jobTitle?: string | null; employerAddress?: string | null; employerPhone?: string | null; employmentStartDate?: string | null;
  businessName?: string | null; businessRegNo?: string | null; businessType?: string | null; businessAddress?: string | null; businessPhone?: string | null;
  /** Populated only when occupationType === 'Employed'. */
  employmentDetail?: CustomerEmploymentDetail | null;
  /** Populated only when occupationType is SelfEmployed or BusinessOwner. */
  businessDetail?: CustomerBusinessDetail | null;
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
  /** True for roles not confined to one branch (admin, head office, auditor, ...) — see Role.can_view_all_branches / BelongsToBranch. Gates the branch filter on report screens. */
  canViewAllBranches?: boolean;
  /** Flat list of "Module:Action" strings the role is allowed to perform. */
  permissions?: string[];
  /** Codes of approval_types this user may approve/reject (e.g. 'loan_cancel',
   *  'payment_void') — separate from the module permission grid above, see
   *  ApprovalType::isAuthorized(). Admins get every code. */
  approvalTypes?: string[];
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

/** Flat business-line classification for a loan product, independent of where
 *  it sits in the parentId tree — lets the rest of the app identify "is this
 *  a Consumer/SME/... loan" without walking the parent chain. Required for
 *  product rows (isProduct true); null for category/folder rows. */
export type LoanTypeCategory = 'Consumer' | 'Leasing' | 'Mortgage' | 'RealEstate' | 'SME'

export interface LoanType {
  id: number; parentId?: number | null;
  name: string; code: string;
  /** Template for this loan type's generated reference numbers, e.g. "{BRANCH}-{CODE}-{YEAR}-{SEQ:6}". Null = system default. */
  referenceFormat?: string | null;
  /** false = organizational category only (not selectable when issuing a loan) */
  isProduct: boolean;
  category?: LoanTypeCategory | null;
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
  /** Days after due_date before default charges start accruing. Default 5. */
  gracePeriodDays?: number | null;
  /** Rate applied per day (day-by-day, non-compounding) to an overdue installment's remaining balance, once past gracePeriodDays. Default Charges = installment balance x this rate% x days late. */
  defaultChargeRatePercent?: number | string | null;
  processingFeePercent?: number | string | null;
  /** Fixed amount, or a percentage of interest collected on the loan so far — see commissionType. */
  commissionType?: 'fixed' | 'percentage' | null;
  commissionValue?: number | string | null;
  /** Paid installments required before the sales executive becomes commission-eligible. 0 = immediately eligible on disbursal. */
  minPaidInstallments?: number | null;
  sortOrder?: number;
  status?: 'Active' | 'Inactive';
}

export type LoanStatus = 'Pending' | 'Approved' | 'Rejected' | 'Cancelled' | 'Active' | 'Closed' | 'WrittenOff' | 'Restructured'

export interface LoanGuarantorInput {
  id?: number;
  guarantorCustomerId: number;
  guaranteedAmount: number | string;
  guarantor?: { id: number; fullName: string; code: string; nicNo?: string | null; phone?: string | null };
}

/** A joint applicant/co-borrower on the loan — customerId on Loan stays the primary applicant. */
export interface LoanCoApplicantInput {
  id?: number;
  coApplicantCustomerId: number;
  sharePercent?: number | string | null;
  coApplicant?: { id: number; fullName: string; code: string; nicNo?: string | null; phone?: string | null };
}

export interface LoanCollateralDocument {
  id: number; facilityCollateralId: number; filePath: string;
  uploadedBy?: number | null; uploader?: { id: number; name: string } | null;
  createdAt?: string;
}

export type CollateralType = 'Vehicle' | 'Jewelry' | 'PawnedJewelry' | 'Property' | 'Other'

export interface LoanCollateralVehicleDetail {
  vehicleType?: string | null; makeModel?: string | null; registrationNo?: string | null;
  chassisNo?: string | null; engineNo?: string | null; yearOfManufacture?: number | string | null;
}

export interface LoanCollateralInput {
  id?: number;
  /** Structural type — drives which extension detail table (vehicleDetail here) applies. */
  collateralType?: CollateralType | null;
  /** Free-text descriptive label (e.g. "Toyota Aqua", replaces the old unconstrained "type" string). */
  collateralCategory?: string | null;
  description: string;
  estimatedValue?: number | string | null;
  /** Write-side convenience — flat fields accepted on create/update when collateralType
   *  is Vehicle (see LoanController::validated()). On READ these come back nested under
   *  vehicleDetail instead — these flat fields are never populated in a GET response. */
  vehicleType?: string | null;
  makeModel?: string | null;
  registrationNo?: string | null;
  chassisNo?: string | null;
  engineNo?: string | null;
  yearOfManufacture?: number | string | null;
  vehicleDetail?: LoanCollateralVehicleDetail | null;
  documents?: LoanCollateralDocument[];
}

/** Loan-specific fields (loan_facility_details, a 1:1 extension of the shared
 *  credit_facilities row) — always present as a nested relation on a real Loan.
 *  Read-only; see the flat write-side fields on Loan below for create/update. */
export interface LoanFacilityDetail {
  loanTypeId: number;
  loanType?: { id: number; name: string; code: string; parentId?: number | null; category?: LoanTypeCategory | null; interestMethod?: InterestMethod; interestRatePercent?: number | string; ratePeriod?: 'monthly' | 'annual'; defaultChargeRatePercent?: number | string | null; gracePeriodDays?: number | null; processingFeePercent?: number | string | null } | null;
  /** Registration number of the leased vehicle — required when loanType.category === 'Leasing'. */
  vehicleNo?: string | null;
  /** Paid upfront; the schedule amortizes principalAmount - downPayment. */
  downPayment?: number | string | null;
  /** Set once disbursed — see loansApi.disburse(). */
  disbursementMethod?: 'Cash' | 'Bank' | 'Cheque' | null;
  interestMethod?: InterestMethod | null;
  /** Flat Rate loans only: equivalent reducing-balance rate implied by the generated EMI, as a percentage. Null until disbursed, and always null for Reducing Rate loans (their entered rate already IS the effective rate — see interestRatePercent on Loan). */
  effectiveMonthlyRate?: number | string | null;
  effectiveAnnualRate?: number | string | null;
  installmentFrequency: LoanFrequency;
  purpose?: string | null;
  /** Back-datable application date shown/printed as the loan's date — distinct from createdAt (the real save timestamp). */
  applicationDate?: string | null;
  /** Reference person recorded on the application — a contactable third party, not a guarantor. */
  referenceName?: string | null;
  referenceIdNo?: string | null;
  referenceAddress?: string | null;
  loanOfficerId?: number | null; loanOfficer?: { id: number; name: string } | null;
  recoveryOfficerId?: number | null; recoveryOfficer?: { id: number; name: string } | null;
  firstInstallmentDate?: string | null;
  freezeDate?: string | null;
  confirmedDate?: string | null; confirmedBy?: { id: number; name: string } | null;
}

/** Insurance policy on the leased/mortgaged asset — shared shape for both Loan
 *  (required when loanType.category is 'Leasing' or 'Mortgage') and SpeedDraft
 *  (required when speedDraftType.category is 'Lease' or 'Mortgage'); not
 *  applicable to any other category. Same key ("insurance") used for both
 *  write and read, like collateral/guarantors. */
export interface FacilityInsuranceDetail {
  insuranceCompany?: string | null;
  policyNo?: string | null;
  amount?: number | string | null;
  contactPerson?: string | null;
  coverage?: string | null;
  remarks?: string | null;
}

export type FacilityStatusEventType = 'Terminated' | 'Legal' | 'SeizingOrderIssued' | 'Repossessed'

/** One row per occurrence (a history log) — replaces the old boolean flag +
 *  timestamp pairs (isTerminated/terminatedAt, isLegal/legalAt, ...) on Loan. */
export interface FacilityStatusEvent {
  id: number; creditFacilityId: number;
  eventType: FacilityStatusEventType; eventDate?: string | null;
  notes?: string | null;
  recordedBy?: { id: number; name: string } | null;
  createdAt?: string;
}

export type ApprovalRequestStatus = 'PendingApproval' | 'Approved' | 'Rejected'

/** A cashier-submitted request to cancel a loan — a row in the shared
 *  approval_requests table (approvalTypeId for code 'loan_cancel', subjectId =
 *  the loan's id) — only takes effect once requiredApprovals distinct people
 *  approve it, snapshotted at request time. See LoanController::cancelRequest(). */
export interface LoanCancelRequest {
  id: number; approvalTypeId: number; subjectId: number;
  requester?: { id: number; name: string } | null; requestedAt: string;
  reason: string; requiredApprovals: number; status: ApprovalRequestStatus;
  firstApprover?: { id: number; name: string } | null; firstApprovedAt?: string | null;
  secondApprover?: { id: number; name: string } | null; secondApprovedAt?: string | null;
  rejecter?: { id: number; name: string } | null; rejectedAt?: string | null; rejectReason?: string | null;
}

export interface Loan {
  id: number; code: string;
  branchId: number; branch?: { id: number; name: string; code: string };
  customerId: number;
  /** On loansApi.get()'s detail load, the server sends the wider column set below
   *  (see LoanController::DETAIL_RELATIONS); list/create responses only populate
   *  the narrower id/fullName/code/nicNo/phone subset. */
  customer?: {
    id: number; fullName: string; code: string; nicNo?: string | null; phone?: string | null;
    passportNo?: string | null; drivingLicenseNo?: string | null; email?: string | null; address?: string | null;
    occupationType?: OccupationType | null; monthlyIncome?: number | string | null;
    status?: 'Active' | 'Blacklisted' | 'Inactive'; isGuarantor?: boolean;
    branch?: { id: number; name: string; code: string } | null;
  };
  principalAmount: number | string;
  interestRatePercent?: number | string;
  ratePeriod?: 'monthly' | 'annual';
  termMonths: number;
  status: LoanStatus;
  appliedBy?: number | null; applier?: { id: number; name: string } | null;
  approver?: { id: number; name: string } | null;
  approvedAt?: string | null;
  rejectedReason?: string | null;
  canceller?: { id: number; name: string } | null;
  cancelledAt?: string | null;
  cancelledReason?: string | null;
  writtenOffBy?: { id: number; name: string } | null;
  writtenOffReason?: string | null;
  /** Impairment/NPL provisioning cache — kept fresh by the daily loans:recompute-provisioning
   *  command and after every payment (LoanPaymentAllocator). provisionPostedAmount is the one
   *  authoritative (not cached) field — it only moves when a user posts via loanProvisionsApi.post(). */
  nplFlag?: boolean;
  provisionRequiredPct?: number;
  provisionRequiredAmount?: number | string;
  provisionPostedAmount?: number | string;
  /** The one cancel request awaiting approval right now, if any — see loansApi.requestCancel(). */
  pendingCancelRequest?: LoanCancelRequest | null;
  /** The approval_requests row (code 'loan_application') created at submission time — only present while status is Pending. */
  pendingApplicationRequest?: LoanCancelRequest | null;
  cancelRequests?: LoanCancelRequest[];
  disbursedAmount?: number | string | null;
  disbursedAt?: string | null;
  outstandingBalanceCache?: number | string | null;
  closedAt?: string | null;

  /** Loan-specific fields, nested — see LoanFacilityDetail. Always present on a real Loan. */
  loanDetail?: LoanFacilityDetail | null;

  /** Write-side convenience — flat fields accepted by loansApi.create()/update()
   *  (see backend LoanController::validated()). On READ these are never populated;
   *  read the same data from loanDetail above instead. Kept flat here only so
   *  create/edit forms can spread a Partial<Loan> directly into form state. */
  loanTypeId?: number;
  downPayment?: number | string;
  installmentFrequency?: LoanFrequency;
  purpose?: string | null;
  applicationDate?: string | null;
  referenceName?: string | null;
  referenceIdNo?: string | null;
  referenceAddress?: string | null;
  loanOfficerId?: number | null;
  firstInstallmentDate?: string | null;
  /** Required when the selected loan type's category is 'Leasing'. Write-flat/read-nested, same rule as the fields above. */
  vehicleNo?: string | null;

  /** Required when the selected loan type's category is 'Leasing' or 'Mortgage'.
   *  Same key on both write and read (see FacilityInsuranceDetail). */
  insurance?: FacilityInsuranceDetail | null;

  guarantors?: LoanGuarantorInput[];
  coApplicants?: LoanCoApplicantInput[];
  collateral?: LoanCollateralInput[];
  schedules?: LoanSchedule[];
  payments?: LoanPayment[];
  charges?: LoanCharge[];
  postDatedCheques?: LoanPostDatedCheque[];
  documents?: LoanDocument[];
  diaryNotes?: LoanDiaryNote[];
  waivers?: LoanWaiver[];
  /** History log of Terminated/Legal/SeizingOrderIssued/Repossessed occurrences — replaces the old boolean flags. */
  statusEvents?: FacilityStatusEvent[];
  summary?: LoanSummary;
  /** From index()'s withSum — total scheduled vs paid across all installments, for a cheap outstanding-balance column. */
  totalDueSum?: number | string | null;
  paidSum?: number | string | null;
  createdAt?: string;
}

export type CommissionStatus = 'Pending' | 'Eligible' | 'Paid'
export type CommissionPaymentStatus = 'Current' | 'Overdue' | 'Closed'

/** One row per commission cycle (the first created at disbursal — see backend
 *  LoanController::disburse() — with a percentage-type loan going on to start a
 *  new cycle each time one is paid, so a loan can have several of these over its
 *  life), tracking the sales executive's commission through Pending -> Eligible -> Paid. */
export interface SalesExecutiveCommission {
  id: number;
  loanId: number; loanCode: string; customerName: string;
  loanTypeId: number; loanTypeName: string;
  salesExecutiveId: number; salesExecutiveName: string;
  /** 1-based cycle number for this loan — cycle 2+ only exists for a percentage-type
   *  commission whose previous cycle has already been paid. */
  cycleNo: number;
  loanAmount: number | string; commissionAmount: number | string;
  /** paidInstallments counts only installments paid within THIS cycle (capped at
   *  requiredInstallments) — not the loan's total paid installments. */
  requiredInstallments: number; paidInstallments: number;
  /** Pending/Eligible are derived live server-side (never persisted); only Paid is a real stored state. */
  commissionStatus: CommissionStatus;
  /** The underlying loan's repayment health — distinct from commissionStatus. */
  paymentStatus: CommissionPaymentStatus;
  paidAt?: string | null;
  paymentAmount?: number | string | null;
  paymentMethod?: PaymentMethod | null;
  cashAccountId?: number | null;
  bankAccountId?: number | null;
  referenceNo?: string | null;
  notes?: string | null;
}

export const salesExecutiveCommissionsApi = {
  list: (params: { salesExecutiveId?: number | ''; loanTypeId?: number | ''; status?: CommissionStatus | '' } = {}) =>
    http.get('/sales-executive-commissions', { params: snakeize({
      salesExecutiveId: params.salesExecutiveId || undefined,
      loanTypeId: params.loanTypeId || undefined,
      status: params.status || undefined,
    }) }).then((r) => camelize(r.data) as SalesExecutiveCommission[]),
  pay: (id: number, body: { paymentDate: string; paymentAmount: number | string; paymentMethod: PaymentMethod; cashAccountId?: number; bankAccountId?: number; referenceNo?: string; notes?: string }) =>
    http.post(`/sales-executive-commissions/${id}/pay`, snakeize(body)).then((r) => camelize(r.data) as SalesExecutiveCommission),
  /** Undoes a mistaken payout — reverses GL + restores the till/bank balance (see backend SalesExecutiveCommissionController::void). */
  void: (id: number) => http.post(`/sales-executive-commissions/${id}/void`).then((r) => camelize(r.data) as SalesExecutiveCommission),
}

export type ScheduleStatus = 'Pending' | 'Paid' | 'Partial' | 'Overdue'

export interface LoanSchedule {
  id: number; creditFacilityId: number; installmentNo: number; dueDate: string;
  principalDue: number | string; interestDue: number | string; totalDue: number | string;
  paidAmount: number | string; paidAt?: string | null; status: ScheduleStatus;
}

export type PaymentMethod = 'Cash' | 'Bank' | 'Cheque'
export type PaymentStatus = 'Active' | 'Voided'
export type PaymentApprovalStatus = 'Approved' | 'PendingApproval' | 'Rejected'
export type PaymentClearanceStatus = 'Pending' | 'Realized' | 'Bounced'

export type LoanPaymentType = 'Regular' | 'Settlement' | 'ManualAllocation'

/** A cashier-chosen split of a payment across buckets — see
 *  LoanPaymentAllocator::applyManualAllocation(). Keys mirror the payload
 *  loanPaymentsApi.create() sends when allocationMode is 'manual'. */
export interface PaymentAllocationSplit {
  interest: number; odCharge: number; otherCharges: number; capital: number
}

/** A cashier-submitted request to void a payment receipt — same shared
 *  approval_requests row shape as LoanCancelRequest (approvalTypeId for code
 *  'payment_void', subjectId = the payment's id). See LoanPaymentController::voidRequest(). */
export interface LoanPaymentVoidRequest {
  id: number; approvalTypeId: number; subjectId: number;
  requester?: { id: number; name: string } | null; requestedAt: string;
  reason?: string | null; requiredApprovals: number; status: ApprovalRequestStatus;
  firstApprover?: { id: number; name: string } | null; firstApprovedAt?: string | null;
  secondApprover?: { id: number; name: string } | null; secondApprovedAt?: string | null;
  rejecter?: { id: number; name: string } | null; rejectedAt?: string | null; rejectReason?: string | null;
}

export interface LoanPayment {
  id: number; code: string; creditFacilityId: number;
  amount: number | string; paymentMethod: PaymentMethod;
  /** Loan payments only ever use Regular or Settlement (SpeedDraft payments use
   *  Interest/CapitalReduction/Settlement — see SpeedDraftPayment.paymentType). */
  paymentType?: LoanPaymentType;
  receiver?: { id: number; name: string } | null;
  paidAt: string; receiptNo?: string | null; notes?: string | null;
  /** Only set when paymentMethod is Cheque. */
  bankId?: number | null; bank?: { id: number; name: string } | null; chequeDate?: string | null;
  /** Cheque payments only — null for Cash/Bank/settlement. Starts 'Pending' and holds the
   *  amount out of the loan's balance until manually cleared via loanPaymentsApi.clearCheque(). */
  clearanceStatus?: PaymentClearanceStatus | null;
  /** The date the cheque actually cleared at the bank (check-in date) — set when
   *  clearCheque() marks it Realized. This, not paidAt or chequeDate, is the date the
   *  amount is considered to have landed in the loan. */
  realizedAt?: string | null;
  status?: PaymentStatus;
  /** Request-only convenience for loanPaymentsApi.create() — the server translates this
   *  into paymentType: 'Settlement'|'Regular'. Never present on a GET response; read
   *  paymentType instead. */
  isSettlement?: boolean;
  approvalStatus?: PaymentApprovalStatus;
  approver?: { id: number; name: string } | null; approvedAt?: string | null;
  voidReason?: string | null; voider?: { id: number; name: string } | null; voidedAt?: string | null;
  /** The one void request awaiting approval right now, if any — see loanPaymentsApi.requestVoid(). */
  pendingVoidRequest?: LoanPaymentVoidRequest | null;
  /** Set only when paymentType is 'ManualAllocation' — the cashier-requested split,
   *  applied once pendingAllocationRequest is approved (see LoanPaymentAllocator::applyManualAllocation()). */
  manualAllocation?: PaymentAllocationSplit | null;
  /** The one manual-allocation request awaiting approval right now, if any —
   *  same shared approval_requests shape as pendingVoidRequest, just for code
   *  'payment_manual_allocation'. */
  pendingAllocationRequest?: LoanPaymentVoidRequest | null;
  createdAt?: string;
}

export interface LoanCharge {
  id: number; creditFacilityId: number; chargeCategoryId: number;
  dueAmount: number | string; paidAmount: number | string; chargedAt: string;
  notes?: string | null; creator?: { id: number; name: string } | null;
  category?: ChargeCategory;
}

export type ChequeStatus = 'Pending' | 'Handed' | 'Realized' | 'Bounced' | 'Returned'

export interface LoanPostDatedCheque {
  id: number; creditFacilityId: number; chequeNo: string;
  /** Per-loan listing (GET .../post-dated-cheques) shape — a real bank reference. */
  bankId?: number | null; bank?: { id: number; name: string } | null;
  /** Cross-loan listing only (GET /post-dated-cheques) — already resolved to the bank's name server-side. */
  bankName?: string | null;
  chequeDate: string;
  amount: number | string; status: ChequeStatus; handedAt?: string | null; notes?: string | null;
  /** The date the cheque actually cleared at the bank (check-in date) — set when marked
   *  Realized. This is the date the amount is considered to have landed in the loan. */
  realizedAt?: string | null;
  creator?: { id: number; name: string } | null;
  /** Present only on the cross-loan listing (Cheque Management screen), not the per-loan panel. */
  loan?: { id: number; code: string; customer?: { id: number; fullName: string } | null; branch?: { id: number; name: string; code: string } | null } | null;
  /** Cross-loan listing only. 'PostDated' = a real cheque record you can Realize/Bounce.
   *  'Payment' = an instant Cheque-method payment already applied to the balance — shown
   *  read-only as Realized so the screen is a complete cheque history. */
  source?: 'PostDated' | 'Payment';
}

export interface DocumentCategory {
  id: number; name: string; status: string; sortOrder: number;
}

export interface ChargeCategory {
  id: number; name: string; status: string; sortOrder: number;
  chartOfAccountId?: number | null; chartOfAccount?: ChartOfAccount | null;
}


export interface LoanDocument {
  id: number; creditFacilityId: number; documentCategoryId: number; filePath: string; createdAt?: string;
  category?: DocumentCategory;
}

export interface LoanDiaryNote {
  id: number; creditFacilityId: number; note: string; followUpDate?: string | null;
  creator?: { id: number; name: string } | null; createdAt?: string;
}

export type WaiverType = 'Interest' | 'Penalty' | 'Charge' | 'Principal'

export interface LoanWaiver {
  id: number; creditFacilityId: number; waiverType: WaiverType; amount: number | string;
  reason?: string | null; waivedAt: string;
  approver?: { id: number; name: string } | null;
}

/** A loan with at least one past-due, not-fully-paid installment — see loansApi.overdue(). */
export interface OverdueLoan extends Loan {
  oldestOverdueDate: string;
  daysOverdue: number;
  /** Fractional count — a partially paid overdue installment contributes less than 1 (see LoanSummary::overdueList). */
  overdueInstallments: number;
  /** Principal + interest arrears on the overdue installments (excludes penalty). */
  overdueAmount: number;
  /** Default/OD interest accrued on the overdue installments. */
  penaltyDue: number;
}

/** One Active loan currently NPL or with a required/posted provision mismatch — see loanProvisionsApi.list(). */
export interface LoanProvisionRow {
  id: number; code: string;
  customer?: { id: number; fullName: string; code: string; nicNo?: string | null } | null;
  branch?: { id: number; name: string; code: string } | null;
  loanType?: { id: number; name: string; code: string } | null;
  nplFlag: boolean;
  provisionRequiredPct: number;
  provisionRequiredAmount: number;
  provisionPostedAmount: number;
  provisionDelta: number;
  provisionLastEvaluatedAt?: string | null;
}

/** One Post/Reverse booking against a loan's provision — see loanProvisionsApi.history(). */
export interface LoanProvisionEntry {
  id: number; creditFacilityId: number; action: 'Post' | 'Recover';
  overdueInstallmentsAtAction: number; slabPct: number;
  baseAmount: number; amount: number; balanceAfter: number;
  postedBy?: { id: number; name: string } | null;
  notes?: string | null; createdAt: string;
}

export interface LoanDueTypeRow {
  type: string; due: number; paid: number; balance: number; recoveryPct: number;
}

export interface LoanSchedulePenaltyRow {
  scheduleId: number; installmentNo: number; dueDate: string; due: number; paid: number;
}

export interface LoanSummary {
  dueBreakdown: LoanDueTypeRow[];
  /** Per-installment Default Charges (OD) — only rows with penalty due or paid > 0. Not on LoanSchedule itself since penalty isn't persisted. */
  schedulePenalties: LoanSchedulePenaltyRow[];
  arrears: {
    rentalArrears: number; otherChargeArrears: number; defaultCharges: number; totalArrears: number;
    overPayment: number; futureCapital: number; futureInterest: number; totalInterestRemaining: number;
    balancePrePayment: number; amountToBeCollected: number;
    /** Sum of all waivers (write-offs) recorded on this loan — already netted out of every figure above. */
    totalWaived: number;
    /** Past due date and not fully paid — same definition as loansApi.overdue().
     *  overdueInstallments is fractional: a partially paid installment contributes less than 1. */
    daysOverdue: number; overdueInstallments: number;
  };
  recovery: {
    lastPaymentDate: string | null; lastPaymentAmount: number | null;
    /** Fractional, not row counts — a rental 60% collected contributes 0.6 settled / 0.4 outstanding. */
    fallenDueRentals: number; settledRentals: number; outstandingRentals: number; futureRentals: number;
  };
}

/** Request-only shape for loansApi.updateStatusFlags() — still boolean-flag-shaped
 *  on the wire, but the server turns each truthy flag into one FacilityStatusEvent
 *  row rather than persisting a flag column. Read the current state back from
 *  Loan.statusEvents (latest row per eventType), not from this shape. */
export interface LoanStatusFlags {
  isTerminated?: boolean; terminatedAt?: string;
  isLegal?: boolean; legalAt?: string;
  seizingOrderIssued?: boolean; seizingOrderAt?: string;
  isRepossessed?: boolean; repossessedAt?: string;
  freezeDate?: string;
  notes?: string;
}

export const branchesApi = resource<Branch>('branches')
export const customersApi = {
  ...resource<Customer>('customers'),
  /** Server-paginated + searched — powers the Customers browse screen so it
   *  never has to fetch the whole customer base. list() above is untouched
   *  and still used everywhere a picker needs the full list for local search. */
  browse: (params: { search?: string; page: number; perPage?: number }) =>
    http.get('/customers', { params: snakeize(params) }).then((r) => camelize(r.data) as Paginated<Customer>),
}
export const loanTypesApi = resource<LoanType>('loan-types')

/** A local bank (Masters > Banks) — used everywhere a "Bank Name" field is
 *  picked (Bank Accounts, Loan Payments/Cheques, Fixed Deposits) via the
 *  shared `BankSelect` component, instead of free-typed text. */
export interface Bank {
  id: number; name: string;
  shortCode?: string | null;
  description?: string | null;
  status?: 'Active' | 'Inactive';
  sortOrder?: number;
}
export const banksApi = resource<Bank>('banks')

export type BankAccountType = 'Savings' | 'Current' | 'Fixed Deposit'

export interface BankAccount {
  id: number;
  branchId?: number | null; branch?: { id: number; name: string; code: string } | null;
  name: string; bankId: number; bank?: { id: number; name: string } | null;
  /** The bank's own branch this account is held at (e.g. "Kandy Branch") — distinct from our own `branch` above. */
  bankBranchName?: string | null;
  accountNumber: string; accountType?: BankAccountType;
  currency?: string;
  openingBalance?: number | string; currentBalance?: number | string;
  status?: 'Active' | 'Inactive';
  notes?: string | null;
}

export type BankTxnType = 'Deposit' | 'Withdrawal' | 'TransferIn' | 'TransferOut'
export type BankTxnStatus = 'Cleared' | 'Pending'
export type BankTxnContraKind = 'Cash' | 'Bank' | 'Ledger'

export interface BankTransaction {
  id: number;
  bankAccountId: number; bankAccount?: { id: number; name: string; bankId: number; accountNumber: string; bank?: { id: number; name: string } | null };
  txnDate: string; transactionType: BankTxnType;
  referenceNo?: string | null; description?: string | null;
  amount: number | string; balanceAfter?: number | string;
  status?: BankTxnStatus;
  /** The other side of this transaction's double entry — a till/bank account whose own balance moves too, or a GL account directly (bank charges, interest, an owner injection, ...). See BankTransactionController::applyContra(). */
  contraKind?: BankTxnContraKind;
  contraCashAccountId?: number | null;
  contraBankAccountId?: number | null;
  contraChartOfAccountId?: number | null;
  creator?: { id: number; name: string } | null;
  createdAt?: string;
}

export type BankReconStatus = 'Pending' | 'Reconciled'

export interface BankReconciliation {
  id: number;
  bankAccountId: number; bankAccount?: { id: number; name: string; bankId: number; accountNumber: string; bank?: { id: number; name: string } | null };
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

/* =========================== Cashbook Management =========================== */

export interface CashAccount {
  id: number;
  branchId: number; branch?: { id: number; name: string; code: string } | null;
  name: string;
  openingBalance?: number | string; currentBalance?: number | string;
  status?: 'Active' | 'Inactive';
}

export type CashEntryType = 'In' | 'Out'
export type CashEntryCategory = 'Loan Collection' | 'Expense' | 'Transfer' | 'Deposit' | 'Other'

export interface CashbookEntry {
  id: number;
  cashAccountId: number; cashAccount?: { id: number; name: string; branchId: number };
  entryDate: string; direction: CashEntryType; category: CashEntryCategory;
  amount: number | string; balanceAfter?: number | string;
  description?: string | null;
  creator?: { id: number; name: string } | null;
  createdAt?: string;
}

export type CashTransferStatus = 'Pending' | 'Completed' | 'Rejected' | 'Voided'

export interface CashTransfer {
  id: number;
  fromCashAccountId: number; fromAccount?: { id: number; name: string };
  toCashAccountId: number; toAccount?: { id: number; name: string };
  transferDate: string;
  amount: number | string;
  description?: string | null;
  status?: CashTransferStatus;
  requester?: { id: number; name: string } | null;
  approver?: { id: number; name: string } | null;
  approvedAt?: string | null;
  voider?: { id: number; name: string } | null;
  voidReason?: string | null;
  voidedAt?: string | null;
}

/** Denomination note value -> quantity counted, e.g. { "5000": 20, "2000": 0, ... }. */
export type Denominations = Record<string, number>
export const DENOMINATION_VALUES = [5000, 2000, 1000, 500, 200, 100, 50, 20, 10] as const

export interface CashReconciliation {
  id: number;
  branchId: number; branch?: { id: number; name: string; code: string };
  asAtDate: string;
  systemCashTotal?: number | string; chequeInHandTotal?: number | string; postdatedChequeTotal?: number | string;
  todayCashReceipts?: number | string; todayChequeReceipts?: number | string; chequeRealizedTotal?: number | string;
  receiptsToBeCancelled?: number | string; encashment?: number | string; cashFloat?: number | string; pettyCash?: number | string;
  otherBranchCash?: number | string; otherBranchId?: number | null; otherBranch?: { id: number; name: string; code: string } | null;
  otherBranchCheque?: number | string; otherBranchChequeBranchId?: number | null; otherBranchChequeBranch?: { id: number; name: string; code: string } | null;
  totalCash?: number | string; totalCheque?: number | string;
  denominations?: Denominations | null; coinsAmount?: number | string;
  countedTotal?: number | string; variance?: number | string;
  notes?: string | null;
  reconciler?: { id: number; name: string } | null;
}

export interface CashReconciliationSummary {
  cashAccounts: { id: number; name: string; currentBalance: number | string }[];
  systemCashTotal: number | string;
  chequeInHandTotal: number | string;
  postdatedChequeTotal: number | string;
  todayCashReceipts: number | string;
  todayChequeReceipts: number | string;
  chequeRealizedTotal: number | string;
  existing: CashReconciliation | null;
}

export const cashAccountsApi = resource<CashAccount>('cash-accounts')

export const cashbookEntriesApi = {
  list: (cashAccountId?: number) =>
    http.get('/cashbook-entries', { params: cashAccountId ? { cash_account_id: cashAccountId } : {} }).then((r) => camelize(r.data) as CashbookEntry[]),
  create: (body: Partial<CashbookEntry>) => http.post('/cashbook-entries', snakeize(body)).then((r) => camelize(r.data) as CashbookEntry),
  remove: (id: number) => http.delete(`/cashbook-entries/${id}`).then((r) => r.data),
}

export const cashTransfersApi = {
  list: (cashAccountId?: number) =>
    http.get('/cash-transfers', { params: cashAccountId ? { cash_account_id: cashAccountId } : {} }).then((r) => camelize(r.data) as CashTransfer[]),
  create: (body: Partial<CashTransfer>) => http.post('/cash-transfers', snakeize(body)).then((r) => camelize(r.data) as CashTransfer),
  approve: (id: number) => http.put(`/cash-transfers/${id}/approve`).then((r) => camelize(r.data) as CashTransfer),
  reject: (id: number) => http.put(`/cash-transfers/${id}/reject`).then((r) => camelize(r.data) as CashTransfer),
  remove: (id: number) => http.delete(`/cash-transfers/${id}`).then((r) => r.data),
  /** Undoes a mistakenly-approved transfer — moves both till balances back and reverses the GL entry (see backend CashTransferController::void). */
  void: (id: number, voidReason?: string) => http.post(`/cash-transfers/${id}/void`, { void_reason: voidReason }).then((r) => camelize(r.data) as CashTransfer),
}

export const cashReconciliationsApi = {
  list: (branchId?: number) =>
    http.get('/cash-reconciliations', { params: branchId ? { branch_id: branchId } : {} }).then((r) => camelize(r.data) as CashReconciliation[]),
  summary: (branchId: number, asAtDate: string) =>
    http.get('/cash-reconciliations/summary', { params: { branch_id: branchId, as_at_date: asAtDate } }).then((r) => camelize(r.data) as CashReconciliationSummary),
  save: (body: Partial<CashReconciliation>) => http.post('/cash-reconciliations', snakeize(body)).then((r) => camelize(r.data) as CashReconciliation),
  remove: (id: number) => http.delete(`/cash-reconciliations/${id}`).then((r) => r.data),
}

/** A spend category (Masters > Expense Categories) — same shape/pattern as
 *  `Bank` / `banksApi` above, each master list gets its own dedicated
 *  table/resource rather than a shared `type`-discriminated lookup. */
export interface ExpenseCategory {
  id: number; name: string;
  code?: string | null;
  description?: string | null;
  // Which chart-of-accounts expense account (e.g. 5010 Operating Expenses) this
  // category's spend posts to — required before any expense in it can be paid.
  chartOfAccountId?: number | null;
  chartOfAccount?: { id: number; code: string; name: string } | null;
  status?: 'Active' | 'Inactive';
  sortOrder?: number;
}
export const expenseCategoriesApi = resource<ExpenseCategory>('expense-categories')

export const documentCategoriesApi = resource<DocumentCategory>('document-categories')

export type SubscriptionType = 'Domain' | 'Hosting' | 'Software License' | 'Legal Document' | 'Insurance' | 'Other'

/** A recurring subscription (domain, hosting, software license) or a
 *  legal/compliance document with a hard expiry date — reminderDaysBefore
 *  drives when it starts showing up in the NotificationBell (see
 *  subscriptionsApi.dueSoon() and Notify.tsx's loadSystemAlerts()). */
export interface Subscription {
  id: number; name: string;
  type: SubscriptionType;
  provider?: string | null;
  referenceNo?: string | null;
  cost?: number | string | null;
  currency?: string;
  startDate?: string | null;
  expiryDate: string;
  reminderDaysBefore?: number;
  status?: 'Active' | 'Inactive' | 'Cancelled';
  branchId?: number | null; branch?: { id: number; name: string; code: string } | null;
  notes?: string | null;
  createdBy?: number | null; creator?: { id: number; name: string } | null;
}

export const subscriptionsApi = {
  ...resource<Subscription>('subscriptions'),
  /** Active items inside their own reminder window (already-expired items too) — feeds the NotificationBell. */
  dueSoon: () => http.get('/subscriptions/due-soon').then((r) => camelize(r.data) as Subscription[]),
}

export interface GeneratedNumber {
  id: number; module: string; number: string;
  branchId?: number | null; branch?: { id: number; name: string; code: string } | null;
  loanTypeId?: number | null; loanType?: { id: number; name: string; code: string } | null;
  year?: number | null; sequence: number;
  generatedBy?: number | null; generator?: { id: number; name: string } | null;
  createdAt?: string;
}

export const generatedNumbersApi = {
  ...resource<GeneratedNumber>('generated-numbers'),
  /** Server-paginated + filtered — powers Document Tracking. list() above (capped at 500, no filters) is kept only for any other caller. */
  browse: (params: { search?: string; module?: string; year?: string; page: number; perPage?: number }) =>
    http.get('/generated-numbers', { params: snakeize(params) }).then((r) => camelize(r.data) as Paginated<GeneratedNumber>),
  /** Every year a number has ever been generated in — for the year filter dropdown, independent of the current page/filters. */
  years: () => http.get('/generated-numbers-years').then((r) => r.data as number[]),
}

/** Laravel paginate() envelope, camelized — see loansApi.browse()/customersApi.browse(). */
export interface Paginated<T> {
  data: T[]; currentPage: number; lastPage: number; perPage: number; total: number
}

export const loansApi = {
  list: () => http.get('/loans').then((r) => camelize(r.data) as Loan[]),
  /** Server-paginated + searched — powers the Active Loans browse screen so it
   *  never has to fetch the whole loan book. Unlike list(), always scoped to
   *  LIVE_STATUSES on the server unless `status` narrows it further (see
   *  LoanController::index's paginated branch). */
  browse: (params: { status?: string; search?: string; page: number; perPage?: number }) =>
    http.get('/loans', { params: snakeize(params) }).then((r) => camelize(r.data) as Paginated<Loan>),
  /** Counts per LIVE_STATUSES status for the Active Loans filter chips — independent of the current search text, same as before pagination. */
  statusCounts: () => http.get('/loans-status-counts').then((r) => camelize(r.data) as Record<string, number>),
  get: (id: number) => http.get(`/loans/${id}`).then((r) => camelize(r.data) as Loan),
  create: (body: Partial<Loan>) => http.post('/loans', snakeize(body)).then((r) => camelize(r.data) as Loan),
  update: (id: number, body: Partial<Loan>) => http.put(`/loans/${id}`, snakeize(body)).then((r) => camelize(r.data) as Loan),
  remove: (id: number, reason: string) => http.delete(`/loans/${id}`, { data: { rejected_reason: reason } }).then((r) => r.data),
  approve: (id: number) => http.post(`/loans/${id}/approve`).then((r) => camelize(r.data) as Loan),
  reject: (id: number, reason: string) => http.post(`/loans/${id}/reject`, { rejected_reason: reason }).then((r) => camelize(r.data) as Loan),
  /** Submits a cancel request — doesn't cancel the loan itself, see LoanCancelRequest. */
  requestCancel: (id: number, reason: string) => http.post(`/loans/${id}/cancel-request`, { cancelled_reason: reason }).then((r) => camelize(r.data) as LoanCancelRequest),
  approveCancelRequest: (requestId: number) => http.post(`/loan-cancel-requests/${requestId}/approve`).then((r) => camelize(r.data) as LoanCancelRequest),
  rejectCancelRequest: (requestId: number, reason?: string) => http.post(`/loan-cancel-requests/${requestId}/reject`, snakeize({ rejectReason: reason })).then((r) => camelize(r.data) as LoanCancelRequest),
  /** Admin-only (see backend LoanController::writeOff) — direct bad-debt write-off, posts the outstanding balance as a loss and closes the loan. */
  writeOff: (id: number, reason: string) => http.post(`/loans/${id}/write-off`, { reason }).then((r) => camelize(r.data) as Loan),
  /** Manual close for a loan that's fully settled but didn't auto-close — see backend LoanController::close(). */
  close: (id: number) => http.post(`/loans/${id}/close`).then((r) => camelize(r.data) as Loan),
  disburse: (id: number, body: { disbursedAmount?: number | string; disbursedAt?: string; firstInstallmentDate: string; disbursementMethod: 'Cash' | 'Bank' | 'Cheque' }) =>
    http.post(`/loans/${id}/disburse`, snakeize(body)).then((r) => camelize(r.data) as Loan),
  updateStatusFlags: (id: number, body: LoanStatusFlags) =>
    http.patch(`/loans/${id}/status-flags`, snakeize(body)).then((r) => camelize(r.data) as Loan),
  updateOfficers: (id: number, body: { loanOfficerId?: number | null; recoveryOfficerId?: number | null }) =>
    http.patch(`/loans/${id}/officers`, snakeize(body)).then((r) => camelize(r.data) as Loan),
  overdue: (params?: { from?: string; to?: string }) =>
    http.get('/loans-overdue', { params }).then((r) => camelize(r.data) as OverdueLoan[]),
}

export const loanProvisionsApi = {
  /** Every Active loan currently NPL or with a required/posted provision mismatch — see LoanProvisionController::index(). */
  list: () => http.get('/loans-provisions').then((r) => camelize(r.data) as LoanProvisionRow[]),
  /** Books the delta between required and posted for this one loan — posted:false means it was already in sync, nothing booked. */
  post: (loanId: number) =>
    http.post(`/loans/${loanId}/post-provision`).then((r) => camelize(r.data) as { posted: boolean; loan: Pick<LoanProvisionRow, 'id' | 'code' | 'nplFlag' | 'provisionRequiredPct' | 'provisionRequiredAmount' | 'provisionPostedAmount' | 'provisionDelta'> }),
  history: (loanId: number) => http.get(`/loans/${loanId}/provisions`).then((r) => camelize(r.data) as LoanProvisionEntry[]),
}

/** One due date shared by unpaid installments across a loan type's active loans —
 *  see loanSchedulesApi.skipDates(). */
export interface SkipDateOption {
  dueDate: string; loanCount: number; installmentCount: number; totalAmount: number
}
export interface BulkSkipResult {
  loansAffected: number; installmentsSkipped: number
  errors: { loanId: number; loanCode: string; message: string }[]
}

export const loanSchedulesApi = {
  /** Pushes an unpaid installment to the back of the schedule (due one period
   *  after the current last installment) instead of collecting it on time. */
  skip: (loanId: number, scheduleId: number) => http.post(`/loans/${loanId}/schedules/${scheduleId}/skip`).then((r) => camelize(r.data) as Loan),
  /** Distinct due dates with at least one skippable installment across this loan
   *  type's active loans — powers the bulk-skip date picklist. */
  skipDates: (loanTypeId: number) => http.get(`/loan-types/${loanTypeId}/skip-dates`).then((r) => camelize(r.data) as SkipDateOption[]),
  /** For every active loan of this loan type, skips whichever unpaid installment
   *  falls on one of the given dates (each loan pushed independently, same rule
   *  as skip() — paid/partially paid installments are left alone). */
  bulkSkip: (loanTypeId: number, dates: string[]) =>
    http.post(`/loan-types/${loanTypeId}/schedules/bulk-skip`, { dates }).then((r) => camelize(r.data) as BulkSkipResult),
}

export const loanPaymentsApi = {
  list: (loanId: number) => http.get(`/loans/${loanId}/payments`).then((r) => camelize(r.data) as LoanPayment[]),
  /** Preview-only: the receipt number the next payment on this loan would get — for
   *  display in the payment form before it's submitted. Not reserved until the payment
   *  is actually created, so it can shift if another payment lands first. */
  nextReceiptNo: (loanId: number, paymentMethod: PaymentMethod) =>
    http.get(`/loans/${loanId}/next-receipt-no`, { params: { payment_method: paymentMethod } }).then((r) => r.data.code as string),
  /** What's currently due per bucket — powers the manual-allocation breakdown in
   *  the Make Payment modal. See LoanPaymentAllocator::outstandingByBucket(). */
  outstandingByBucket: (loanId: number, asOf?: string) =>
    http.get(`/loans/${loanId}/outstanding-by-bucket`, { params: snakeize({ asOf }) }).then((r) => camelize(r.data) as PaymentAllocationSplit),
  create: (loanId: number, body: { amount: number | string; paymentMethod?: PaymentMethod; paidAt?: string; receiptNo?: string; bankId?: number; chequeDate?: string; notes?: string; isSettlement?: boolean; allocationMode?: 'auto' | 'manual'; allocations?: PaymentAllocationSplit }) =>
    http.post(`/loans/${loanId}/payments`, snakeize(body)).then((r) => camelize(r.data) as LoanPayment),
  /** Submits a void request — doesn't void the payment itself, see LoanPaymentVoidRequest. */
  requestVoid: (paymentId: number, voidReason?: string) => http.post(`/loan-payments/${paymentId}/void-request`, { void_reason: voidReason }).then((r) => camelize(r.data) as LoanPaymentVoidRequest),
  approveVoidRequest: (requestId: number) => http.post(`/loan-payment-void-requests/${requestId}/approve`).then((r) => camelize(r.data) as LoanPaymentVoidRequest),
  rejectVoidRequest: (requestId: number, reason?: string) => http.post(`/loan-payment-void-requests/${requestId}/reject`, snakeize({ rejectReason: reason })).then((r) => camelize(r.data) as LoanPaymentVoidRequest),
  /** Approve/reject a pending manual-allocation request — see loanPaymentsApi.create()'s
   *  allocationMode: 'manual' and LoanPaymentController::approveAllocationRequest(). */
  approveAllocationRequest: (requestId: number) => http.post(`/loan-payment-allocation-requests/${requestId}/approve`).then((r) => camelize(r.data) as LoanPaymentVoidRequest),
  rejectAllocationRequest: (requestId: number, reason?: string) => http.post(`/loan-payment-allocation-requests/${requestId}/reject`, snakeize({ rejectReason: reason })).then((r) => camelize(r.data) as LoanPaymentVoidRequest),
  approveSettlement: (paymentId: number) => http.post(`/loan-payments/${paymentId}/approve-settlement`).then((r) => camelize(r.data) as LoanPayment),
  rejectSettlement: (paymentId: number, voidReason?: string) =>
    http.post(`/loan-payments/${paymentId}/reject-settlement`, snakeize({ voidReason })).then((r) => camelize(r.data) as LoanPayment),
  /** Realize applies the held amount to the schedule as of `realizedAt` (the cheque's
   *  check-in/clearing date — required when status is 'Realized'); Bounced voids the
   *  payment (it was never applied). */
  clearCheque: (paymentId: number, status: 'Realized' | 'Bounced', realizedAt?: string) =>
    http.patch(`/loan-payments/${paymentId}/clear-cheque`, snakeize({ status, realizedAt })).then((r) => camelize(r.data) as LoanPayment),
}

/** Admin-only consolidated inbox — see ApprovalController::index(). */
export interface ApprovalsInbox {
  loanApplicationRequests: (LoanCancelRequest & {
    loan?: { id: number; code: string; principalAmount: number | string; status: LoanStatus; customer?: { id: number; fullName: string }; loanDetail?: { loanType?: { id: number; name: string } | null } | null } | null
  })[]
  speedDraftApplicationRequests: (LoanCancelRequest & {
    speedDraft?: { id: number; code: string; principalAmount: number | string; status: SpeedDraftStatus; customer?: { id: number; fullName: string }; speedDraftDetail?: { speedDraftType?: { id: number; name: string } | null } | null } | null
  })[]
  loanCancelRequests: (LoanCancelRequest & {
    loan?: { id: number; code: string; principalAmount: number | string; status: LoanStatus; customer?: { id: number; fullName: string } } | null
  })[]
  paymentVoidRequests: (LoanPaymentVoidRequest & {
    payment?: {
      id: number; code: string; amount: number | string; paymentType?: LoanPaymentType
      facility?: { id: number; code: string; customer?: { id: number; fullName: string } } | null
    } | null
  })[]
  allocationRequests: (LoanPaymentVoidRequest & {
    payment?: {
      id: number; code: string; amount: number | string; paymentType?: LoanPaymentType; manualAllocation?: PaymentAllocationSplit | null
      facility?: { id: number; code: string; customer?: { id: number; fullName: string } } | null
    } | null
  })[]
}

export const approvalsApi = {
  list: () => http.get('/approvals').then((r) => camelize(r.data) as ApprovalsInbox),
}

/** A named, data-driven "approval point" (e.g. loan_cancel, payment_void) —
 *  admin-manageable list of who may approve it, decoupled from the module
 *  permission grid so new approval points don't need a code deploy to
 *  configure approvers for. See ApprovalType::isAuthorized(). */
export interface ApprovalType {
  id: number; code: string; name: string; description?: string | null;
  isSystem?: boolean;
  /** How many distinct people must approve a request of this type before it executes — 1 or 2. */
  requiredApprovals?: number;
  approvers?: { id: number; name: string }[];
}

export const approvalTypesApi = {
  list: () => http.get('/approval-types').then((r) => camelize(r.data) as ApprovalType[]),
  create: (body: { code: string; name: string; description?: string; requiredApprovals?: number }) =>
    http.post('/approval-types', snakeize(body)).then((r) => camelize(r.data) as ApprovalType),
  update: (id: number, body: { name: string; description?: string; requiredApprovals?: number }) =>
    http.put(`/approval-types/${id}`, snakeize(body)).then((r) => camelize(r.data) as ApprovalType),
  remove: (id: number) => http.delete(`/approval-types/${id}`).then((r) => r.data),
  updateApprovers: (id: number, userIds: number[]) =>
    http.put(`/approval-types/${id}/approvers`, { user_ids: userIds }).then((r) => camelize(r.data) as ApprovalType),
}

export const loanChargesApi = {
  list: (loanId: number) => http.get(`/loans/${loanId}/charges`).then((r) => camelize(r.data) as LoanCharge[]),
  create: (loanId: number, body: { chargeCategoryId: number; dueAmount: number | string; paidAmount?: number | string; chargedAt?: string; notes?: string }) =>
    http.post(`/loans/${loanId}/charges`, snakeize(body)).then((r) => camelize(r.data) as LoanCharge),
  update: (chargeId: number, body: Partial<LoanCharge>) => http.put(`/loan-charges/${chargeId}`, snakeize(body)).then((r) => camelize(r.data) as LoanCharge),
  remove: (chargeId: number) => http.delete(`/loan-charges/${chargeId}`).then((r) => r.data),
}

export const chargeCategoriesApi = resource<ChargeCategory>('charge-categories')

export const loanPostDatedChequesApi = {
  list: (loanId: number) => http.get(`/loans/${loanId}/post-dated-cheques`).then((r) => camelize(r.data) as LoanPostDatedCheque[]),
  /** Cross-loan listing for the Cheque Management screen. */
  listAll: (params?: { status?: ChequeStatus; dueToday?: boolean; fromDate?: string; toDate?: string }) =>
    http.get('/post-dated-cheques', { params: {
      status: params?.status, due_today: params?.dueToday ? 1 : undefined,
      from_date: params?.fromDate || undefined, to_date: params?.toDate || undefined,
    } }).then((r) => camelize(r.data) as LoanPostDatedCheque[]),
  create: (loanId: number, body: { chequeNo: string; bankId: number; chequeDate: string; amount: number | string; notes?: string }) =>
    http.post(`/loans/${loanId}/post-dated-cheques`, snakeize(body)).then((r) => camelize(r.data) as LoanPostDatedCheque),
  /** realizedAt (the cheque's check-in/clearing date) is required when status is
   *  'Realized' — that's the day the amount actually applies against the loan. */
  updateStatus: (chequeId: number, status: ChequeStatus, notes?: string, realizedAt?: string) =>
    http.patch(`/loan-post-dated-cheques/${chequeId}`, snakeize({ status, notes, realizedAt })).then((r) => camelize(r.data) as LoanPostDatedCheque),
  remove: (chequeId: number) => http.delete(`/loan-post-dated-cheques/${chequeId}`).then((r) => r.data),
}

export const loanDocumentsApi = {
  list: (loanId: number) => http.get(`/loans/${loanId}`).then((r) => (camelize(r.data).documents || []) as LoanDocument[]),
  upload: (loanId: number, file: File, documentCategoryId: number) => {
    const form = new FormData()
    form.append('file', file)
    form.append('document_category_id', String(documentCategoryId))
    return http.post(`/loans/${loanId}/documents`, form).then((r) => camelize(r.data) as LoanDocument)
  },
  remove: (loanId: number, documentId: number) => http.delete(`/loans/${loanId}/documents/${documentId}`).then((r) => r.data),
  download: (loanId: number, documentId: number) =>
    http.get(`/loans/${loanId}/documents/${documentId}/file`, { responseType: 'blob' }).then((r) => r.data as Blob),
}

export const loanCollateralDocumentsApi = {
  upload: (loanId: number, collateralId: number, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return http.post(`/loans/${loanId}/collateral/${collateralId}/documents`, form).then((r) => camelize(r.data) as LoanCollateralDocument)
  },
  remove: (loanId: number, collateralId: number, documentId: number) =>
    http.delete(`/loans/${loanId}/collateral/${collateralId}/documents/${documentId}`).then((r) => r.data),
  download: (loanId: number, collateralId: number, documentId: number) =>
    http.get(`/loans/${loanId}/collateral/${collateralId}/documents/${documentId}/file`, { responseType: 'blob' }).then((r) => r.data as Blob),
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

/* ======================= Speed Drafts (Loans / Mortgages / Gold Pawning / Leases — interest-only) =======================
   Unlike Loan, a Speed Draft never amortizes principal into the schedule — the
   customer pays interest only every month on outstanding_capital, and the full
   capital falls due as a lump sum at maturity (see the final schedule row's
   capitalDue). Capital can also be reduced early at any time (payment type
   'CapitalReduction'), which re-prices every future month's interest — see
   backend App\Support\SpeedDraftScheduler. */

export type SpeedDraftCategory = 'Loan' | 'Mortgage' | 'GoldPawning' | 'Lease'
export type SpeedDraftStatus = 'Pending' | 'Approved' | 'Rejected' | 'Active' | 'Closed'
export type SpeedDraftPaymentType = 'Interest' | 'CapitalReduction' | 'Settlement'
export type SpeedDraftScheduleStatus = 'Pending' | 'Paid' | 'Partial'

export interface SpeedDraftType {
  id: number;
  name: string; code: string;
  category: SpeedDraftCategory;
  /** Template for this type's generated Speed Draft numbers, e.g. "{BRANCH}-SD-{YEAR}-{SEQ:6}". Null = system default. */
  referenceFormat?: string | null;
  interestRatePercent: number | string;
  ratePeriod?: 'monthly' | 'annual' | 'daily';
  minAmount?: number | string | null;
  maxAmount?: number | string | null;
  minPeriodMonths?: number | null;
  maxPeriodMonths?: number | null;
  /** Days after an interest installment's due date before it's shown as Overdue, and before defaultChargeRatePercent starts accruing. */
  gracePeriodDays?: number;
  /** Monthly penalty rate applied to an overdue installment's unpaid balance once past gracePeriodDays — see SpeedDraftSummary.penaltyDue. Null/0 = no penalty. */
  defaultChargeRatePercent?: number | string | null;
  /** Gold Pawning / Lease typically pledge an asset — see SpeedDraft.collateral. */
  requiresCollateral?: boolean;
  sortOrder?: number;
  status?: 'Active' | 'Inactive';
}
export const speedDraftTypesApi = resource<SpeedDraftType>('speed-draft-types')

export interface SpeedDraftCollateralDocument {
  id: number; facilityCollateralId: number; filePath: string;
  uploadedBy?: number | null; uploader?: { id: number; name: string } | null;
  createdAt?: string;
}

export interface SpeedDraftCollateralJewelryDetail {
  weightGrams?: number | string | null; purity?: string | null; appraisedValue?: number | string | null;
}

export interface SpeedDraftCollateralInput {
  id?: number;
  /** Structural type — SpeedDraftCollateralController only ever populates jewelryDetail
   *  regardless of this value (defaults to 'Jewelry' if omitted on create). */
  collateralType?: CollateralType | null;
  collateralCategory?: string | null;
  description: string;
  estimatedValue?: number | string | null;
  /** Write-side convenience — flat fields accepted on create/update. On READ these
   *  come back nested under jewelryDetail instead (see backend
   *  SpeedDraftCollateralController, which maps estimatedValue = appraisedValue). */
  weightGrams?: number | string | null;
  purity?: string | null;
  appraisedValue?: number | string | null;
  jewelryDetail?: SpeedDraftCollateralJewelryDetail | null;
  documents?: SpeedDraftCollateralDocument[];
}

export interface SpeedDraftSchedule {
  id: number; creditFacilityId: number; installmentNo: number; dueDate: string;
  interestDue: number | string;
  /** Only ever non-zero on the final (maturity) installment — a live preview of the
   *  outstanding capital, not a fixed historical figure (see SpeedDraftScheduler).
   *  This is the shared facility_schedules.principal_due column, not a separate field. */
  principalDue: number | string;
  totalDue: number | string;
  /** Interest collected against this installment — capital is never tracked per-row (see SpeedDraft.outstandingBalanceCache). */
  paidAmount: number | string;
  paidAt?: string | null;
  status: SpeedDraftScheduleStatus;
}

export interface SpeedDraftPayment {
  id: number; code: string; creditFacilityId: number;
  amount: number | string;
  paymentType: SpeedDraftPaymentType;
  paymentMethod: PaymentMethod;
  receiver?: { id: number; name: string } | null;
  paidAt: string; receiptNo?: string | null; notes?: string | null;
  status?: 'Active' | 'Voided';
  voidReason?: string | null; voider?: { id: number; name: string } | null; voidedAt?: string | null;
  createdAt?: string;
}

export interface SpeedDraftSummary {
  principalAmount: number; outstandingCapital: number; totalCapitalPaid: number;
  totalInterestDue: number; totalInterestPaid: number; outstandingInterest: number;
  /** Live-estimated penalty on overdue installments — see SpeedDraftType.defaultChargeRatePercent. Never persisted, recalculated fresh. */
  penaltyDue: number;
  /** Full outstanding interest + penalty + full outstanding capital — what a "Settle Full Balance" action would collect today. */
  amountToSettleToday: number;
  nextDueDate: string | null; nextDueAmount: number | null;
  daysOverdue: number; overdueInstallments: number;
  totalInstallments: number; paidInstallments: number;
}

/** Audit/link row for speedDraftsApi.renew() — mirrors FdRenewal's new-account-close-old shape. */
export interface SpeedDraftRenewal {
  id: number; oldCreditFacilityId: number; newCreditFacilityId: number;
  capitalBefore: number | string;
  interestDue: number | string; interestCollected: number | string;
  penaltyDue: number | string; penaltyCollected: number | string;
  capitalPayment: number | string; capitalAfter: number | string;
  newTermMonths: number;
  facilityPaymentId?: number | null;
  renewedBy?: { id: number; name: string } | null; renewedAt: string;
  notes?: string | null;
}

/** SpeedDraft-specific fields (speed_draft_facility_details, a 1:1 extension of
 *  the shared credit_facilities row) — always present as a nested relation on a
 *  real SpeedDraft. Read-only; see the flat write-side fields on SpeedDraft below. */
export interface SpeedDraftFacilityDetail {
  speedDraftTypeId: number;
  speedDraftType?: SpeedDraftType | null;
  /** Registration number of the leased vehicle — required when speedDraftType.category === 'Lease'. */
  vehicleNo?: string | null;
  paymentFrequency?: string | null;
  /** Set once disbursed — see speedDraftsApi.disburse(). */
  disbursementMethod?: 'Cash' | 'Bank' | 'Cheque' | null;
}

export interface SpeedDraft {
  id: number; code: string;
  branchId: number; branch?: { id: number; name: string; code: string };
  customerId: number; customer?: { id: number; fullName: string; code: string; nicNo?: string | null; phone?: string | null };
  principalAmount: number | string;
  /** Starts equal to principalAmount; reduced by CapitalReduction/Settlement payments. */
  outstandingBalanceCache: number | string;
  interestRatePercent: number | string;
  ratePeriod?: 'monthly' | 'annual' | 'daily';
  termMonths: number;
  /** Set once disbursed — see speedDraftsApi.disburse(). */
  startDate?: string | null;
  maturityDate?: string | null;
  disbursedAmount?: number | string | null;
  disbursedAt?: string | null;
  status: SpeedDraftStatus;
  appliedBy?: number | null; applier?: { id: number; name: string } | null;
  approver?: { id: number; name: string } | null;
  approvedAt?: string | null;
  rejectedReason?: string | null;
  closedAt?: string | null;
  /** The approval_requests row (code 'speed_draft_application') created at submission time — only present while status is Pending. */
  pendingApplicationRequest?: LoanCancelRequest | null;
  /** Present once this draft has been renewed into a new one — see speedDraftsApi.renew(). */
  renewedTo?: SpeedDraftRenewal | null;
  /** Present when this draft was itself created by renewing an older, now-Closed one. */
  renewedFrom?: SpeedDraftRenewal | null;

  /** SpeedDraft-specific fields, nested — see SpeedDraftFacilityDetail. Always present on a real SpeedDraft. */
  speedDraftDetail?: SpeedDraftFacilityDetail | null;

  /** Write-side convenience — flat fields accepted by speedDraftsApi.create()/update()
   *  (see backend SpeedDraftController::validated()). On READ these are never
   *  populated; read the same data from speedDraftDetail above instead. */
  speedDraftTypeId?: number;
  paymentFrequency?: string;
  /** Required when the selected speed draft type's category is 'Lease'. Write-flat/read-nested, same rule as the fields above. */
  vehicleNo?: string | null;

  /** Required when the selected speed draft type's category is 'Lease' or 'Mortgage'.
   *  Same key on both write and read (see FacilityInsuranceDetail). */
  insurance?: FacilityInsuranceDetail | null;

  collateral?: SpeedDraftCollateralInput[];
  schedules?: SpeedDraftSchedule[];
  payments?: SpeedDraftPayment[];
  summary?: SpeedDraftSummary;
  createdAt?: string;
}

/** An Active draft with at least one not-yet-paid installment past its grace period — see speedDraftsApi.overdue(). */
export interface OverdueSpeedDraft extends SpeedDraft {
  oldestOverdueDate: string;
  daysOverdue: number;
  overdueInstallments: number;
  overdueInterest: number;
}

export const speedDraftsApi = {
  list: (params?: { status?: SpeedDraftStatus }) =>
    http.get('/speed-drafts', { params: { status: params?.status || undefined } }).then((r) => camelize(r.data) as SpeedDraft[]),
  /** Server-paginated + searched — powers the Speed Drafts browse screen. list() above is untouched and still used by the PaymentReceipt picker. */
  browse: (params: { status?: string; search?: string; page: number; perPage?: number }) =>
    http.get('/speed-drafts', { params: snakeize(params) }).then((r) => camelize(r.data) as Paginated<SpeedDraft>),
  statusCounts: () => http.get('/speed-drafts-status-counts').then((r) => camelize(r.data) as Record<string, number>),
  get: (id: number) => http.get(`/speed-drafts/${id}`).then((r) => camelize(r.data) as SpeedDraft),
  create: (body: Partial<SpeedDraft> & { collateral?: SpeedDraftCollateralInput[] }) =>
    http.post('/speed-drafts', snakeize(body)).then((r) => camelize(r.data) as SpeedDraft),
  update: (id: number, body: Partial<SpeedDraft> & { collateral?: SpeedDraftCollateralInput[] }) =>
    http.put(`/speed-drafts/${id}`, snakeize(body)).then((r) => camelize(r.data) as SpeedDraft),
  remove: (id: number) => http.delete(`/speed-drafts/${id}`).then((r) => r.data),
  approve: (id: number) => http.post(`/speed-drafts/${id}/approve`).then((r) => camelize(r.data) as SpeedDraft),
  reject: (id: number, reason?: string) => http.post(`/speed-drafts/${id}/reject`, { rejected_reason: reason }).then((r) => camelize(r.data) as SpeedDraft),
  disburse: (id: number, body: { disbursementMethod: 'Cash' | 'Bank'; disbursedAmount?: number | string; startDate: string }) =>
    http.post(`/speed-drafts/${id}/disburse`, snakeize(body)).then((r) => camelize(r.data) as SpeedDraft),
  overdue: () => http.get('/speed-drafts-overdue').then((r) => camelize(r.data) as OverdueSpeedDraft[]),
  /** Renews a matured Speed Draft under the same product for a new term — closes this draft, creates a new one.
   *  Any interest/penalty not covered by `payment` is capitalized into the new principal (see backend SpeedDraftController::renew()). */
  renew: (id: number, body: {
    termMonths: number; notes?: string;
    payment?: { amount: number | string; paymentMethod?: PaymentMethod; paidAt?: string; receiptNo?: string; notes?: string };
  }) =>
    http.post(`/speed-drafts/${id}/renew`, snakeize(body)).then((r) => camelize(r.data) as { speedDraft: SpeedDraft; oldSpeedDraft: SpeedDraft; renewal: SpeedDraftRenewal }),
}

export const speedDraftCollateralApi = {
  list: (speedDraftId: number) => http.get(`/speed-drafts/${speedDraftId}/collateral`).then((r) => camelize(r.data) as SpeedDraftCollateralInput[]),
  create: (speedDraftId: number, body: SpeedDraftCollateralInput) =>
    http.post(`/speed-drafts/${speedDraftId}/collateral`, snakeize(body)).then((r) => camelize(r.data) as SpeedDraftCollateralInput),
  update: (collateralId: number, body: SpeedDraftCollateralInput) =>
    http.put(`/speed-draft-collateral/${collateralId}`, snakeize(body)).then((r) => camelize(r.data) as SpeedDraftCollateralInput),
  remove: (collateralId: number) => http.delete(`/speed-draft-collateral/${collateralId}`).then((r) => r.data),
}

export const speedDraftCollateralDocumentsApi = {
  upload: (collateralId: number, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return http.post(`/speed-draft-collateral/${collateralId}/documents`, form).then((r) => camelize(r.data) as SpeedDraftCollateralDocument)
  },
  remove: (collateralId: number, documentId: number) =>
    http.delete(`/speed-draft-collateral/${collateralId}/documents/${documentId}`).then((r) => r.data),
  download: (collateralId: number, documentId: number) =>
    http.get(`/speed-draft-collateral/${collateralId}/documents/${documentId}/file`, { responseType: 'blob' }).then((r) => r.data as Blob),
}

export const speedDraftPaymentsApi = {
  list: (speedDraftId: number) => http.get(`/speed-drafts/${speedDraftId}/payments`).then((r) => camelize(r.data) as SpeedDraftPayment[]),
  /** Preview-only: the receipt number the next payment on this draft would get — see NumberGenerator::peek(). */
  nextReceiptNo: (speedDraftId: number, paymentType: SpeedDraftPaymentType) =>
    http.get(`/speed-drafts/${speedDraftId}/next-receipt-no`, { params: { payment_type: paymentType } }).then((r) => r.data.code as string),
  create: (speedDraftId: number, body: { amount: number | string; paymentType: SpeedDraftPaymentType; paymentMethod?: PaymentMethod; paidAt?: string; receiptNo?: string; notes?: string }) =>
    http.post(`/speed-drafts/${speedDraftId}/payments`, snakeize(body)).then((r) => camelize(r.data) as SpeedDraftPayment),
  remove: (paymentId: number, voidReason?: string) => http.delete(`/speed-draft-payments/${paymentId}`, { data: { void_reason: voidReason } }).then((r) => r.data),
}

/* ======================= Funding (Fixed Deposit) Management ======================= */

export type FdInterestMethod = 'monthly' | 'maturity'
export type FdPaymentMethod = 'Cash' | 'Bank' | 'Cheque'
export type FdDepositStatus = 'Available' | 'Allocated'
export type FixedDepositStatus = 'Active' | 'Closed' | 'Renewed'

export interface FundingProduct {
  id: number;
  name: string; code: string;
  interestMethod: FdInterestMethod;
  /** When true, the FD create form lets the customer pick monthly vs maturity instead of locking to interestMethod. */
  allowMethodChoice?: boolean;
  annualRatePercent: number | string;
  /** Auto = annualRatePercent/12 unless rateIsManual. */
  monthlyRatePercent?: number | string | null;
  rateIsManual?: boolean;
  /** Reduced rate applied to a premature part-payment (see fixedDepositsApi.partPayment). Null = part-payment blocked for this product until set. */
  prematureWithdrawalRatePercent?: number | string | null;
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
  referenceNo?: string | null; bankId?: number | null; bank?: { id: number; name: string } | null; notes?: string | null;
  status: FdDepositStatus;
  fixedDepositId?: number | null; fixedDeposit?: { id: number; fdNumber: string } | null;
  creator?: { id: number; name: string } | null;
  createdAt?: string;
}

export interface FdInterestPayment {
  id: number; fixedDepositId: number;
  installmentNo: number; dueDate: string; amount: number | string;
  /** 'Partial' is reserved headroom — pay() only ever pays the full scheduled amount today. */
  status: 'Pending' | 'Partial' | 'Paid';
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

/** A premature partial withdrawal — the FD stays open on the reduced principal (unlike FdWithdrawal, which always closes it). See backend FixedDepositController::partPayment(). */
export interface FdPartPayment {
  id: number; fixedDepositId: number; voucherNo: string;
  principalAmount: number | string; ratePercent: number | string; heldDays: number;
  interestEntitlement: number | string;
  /** Cash interest paid out on this transaction — only nonzero for an interest-at-maturity FD. */
  interestPaid: number | string;
  /** Deducted from the principal returned when a monthly-method FD's prior payouts exceeded the reduced entitlement. */
  clawbackAmount: number | string;
  netAmountPaid: number | string;
  paymentMethod: FdPaymentMethod; referenceNo?: string | null; notes?: string | null;
  paidAt: string;
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
  partPayments?: FdPartPayment[];
  createdAt?: string;
}

export const fundingProductsApi = resource<FundingProduct>('funding-products')

export const fdDepositsApi = {
  list: (params?: { status?: FdDepositStatus; customerId?: number }) =>
    http.get('/fd-deposits', { params }).then((r) => camelize(r.data) as FdDeposit[]),
  get: (id: number) => http.get(`/fd-deposits/${id}`).then((r) => camelize(r.data) as FdDeposit),
  /** Preview-only: the deposit number the next deposit at this branch would get — for
   *  display in the form before it's submitted. Not reserved until the deposit is
   *  actually created, so it can shift if another deposit lands first. */
  nextDepositNo: (branchId: number) =>
    http.get('/fd-deposits/next-number', { params: { branch_id: branchId } }).then((r) => r.data.code as string),
  create: (body: { branchId: number; customerId: number; depositDate: string; amount: number | string; paymentMethod: FdPaymentMethod; referenceNo?: string; bankId?: number; notes?: string }) =>
    http.post('/fd-deposits', snakeize(body)).then((r) => camelize(r.data) as FdDeposit),
  remove: (id: number) => http.delete(`/fd-deposits/${id}`).then((r) => r.data),
}

export interface FdEnhancementInput {
  amount: number | string; paymentMethod: FdPaymentMethod;
  depositDate?: string; referenceNo?: string; bankId?: number; notes?: string;
}

export const fixedDepositsApi = {
  list: (params?: { status?: FixedDepositStatus; customerId?: number; matured?: boolean; maturingWithinDays?: number }) =>
    http.get('/fixed-deposits', { params: snakeize(params || {}) }).then((r) => camelize(r.data) as FixedDeposit[]),
  get: (id: number) => http.get(`/fixed-deposits/${id}`).then((r) => camelize(r.data) as FixedDeposit),
  create: (body: { depositId: number; fundingProductId: number; termMonths: number; startDate: string; interestPaymentMethod?: FdInterestMethod }) =>
    http.post('/fixed-deposits', snakeize(body)).then((r) => camelize(r.data) as FixedDeposit),
  renew: (id: number, body: { termMonths: number; notes?: string; enhancement?: FdEnhancementInput }) =>
    http.post(`/fixed-deposits/${id}/renew`, snakeize(body)).then((r) => camelize(r.data) as { fixedDeposit: FixedDeposit; enhancementDeposit: FdDeposit | null }),
  withdraw: (id: number, body: { paymentMethod: FdPaymentMethod; referenceNo?: string; notes?: string; closedAt?: string }) =>
    http.post(`/fixed-deposits/${id}/withdraw`, snakeize(body)).then((r) => camelize(r.data) as FdWithdrawal & { fixedDeposit: FixedDeposit }),
  /** Undoes a mistaken closure — reverses GL/Cashbook and reopens the FD (see backend FixedDepositController::voidWithdrawal). */
  voidWithdrawal: (id: number) => http.post(`/fixed-deposits/${id}/void-withdrawal`).then((r) => camelize(r.data) as FixedDeposit),
  /** Premature partial withdrawal — FD stays open on the reduced principal (see backend FixedDepositController::partPayment). */
  partPayment: (id: number, body: { amount: number | string; paymentMethod: FdPaymentMethod; referenceNo?: string; notes?: string; paidAt?: string }) =>
    http.post(`/fixed-deposits/${id}/part-payment`, snakeize(body)).then((r) => camelize(r.data) as FdPartPayment & { fixedDeposit: FixedDeposit }),
  /** Undoes a mistaken part payment — only allowed on the FD's most recent one (see backend FixedDepositController::voidPartPayment). */
  voidPartPayment: (partPaymentId: number) => http.post(`/fd-part-payments/${partPaymentId}/void`).then((r) => camelize(r.data) as FixedDeposit),
}

export interface FinanceCalcCharge { label?: string; amount: number | string; financed?: boolean }
export interface FinanceCalcInput {
  loanTypeId?: number; loanAmount: number | string; downPayment?: number | string; termMonths: number;
  interestMethod: InterestMethod; interestRatePercent: number | string;
  ratePeriod?: 'monthly' | 'annual'; installmentFrequency?: LoanFrequency;
  firstInstallmentDate?: string; charges?: FinanceCalcCharge[];
}
export interface FinanceCalcScheduleRow {
  installmentNo: number; dueDate: string; principalDue: number; interestDue: number; totalDue: number; balance: number;
}
export interface FinanceCalcResult {
  loanAmount: number; downPayment: number; financedChargesTotal: number; separateChargesTotal: number;
  financeAmount: number; customerUpfrontPayment: number;
  interestMethod: InterestMethod; ratePeriod: 'monthly' | 'annual'; enteredRate: number;
  effectiveMonthlyRate: number | null; effectiveAnnualRate: number | null;
  termMonths: number; installmentFrequency: LoanFrequency;
  monthlyRental: number; totalInterest: number; totalPayable: number;
  schedule: FinanceCalcScheduleRow[];
}

/** Calls the same LoanAmortizer/AerCalculator LoanController::disburse() uses (via
 *  FinanceCalculationService) — never persists anything. No client-side amortization
 *  math exists in this app; every recalculation goes through this endpoint. */
export const financeCalculatorApi = {
  calculate: (body: FinanceCalcInput) =>
    http.post('/finance-calculator/calculate', snakeize(body)).then((r) => camelize(r.data) as FinanceCalcResult),
}

export const fdInterestPaymentsApi = {
  list: (fixedDepositId: number) => http.get(`/fixed-deposits/${fixedDepositId}/interest-payments`).then((r) => camelize(r.data) as FdInterestPayment[]),
  pay: (id: number, body: { paidAt?: string; paymentMethod: FdPaymentMethod; referenceNo?: string }) =>
    http.post(`/fd-interest-payments/${id}/pay`, snakeize(body)).then((r) => camelize(r.data) as FdInterestPayment),
  /** Undoes a mistaken interest payment — reverses GL/Cashbook and puts the installment back to Pending (see backend FdInterestPaymentController::void). */
  void: (id: number) => http.post(`/fd-interest-payments/${id}/void`).then((r) => camelize(r.data) as FdInterestPayment),
}

/** Shared by every *ReportsApi below — same `{prefix}-reports/{type}[/pdf]` shape as the backend's *ReportController classes. */
const reportPdf = (prefix: string, type: string, params?: Record<string, any>) =>
  http.get(`/${prefix}-reports/${type}/pdf`, { params: snakeize(params || {}), responseType: 'blob' }).then((r) => r.data as Blob)

export type FundingReportType =
  | 'active' | 'maturing-soon' | 'matured' | 'monthly-interest' | 'interest-paid'
  | 'deposit-history' | 'customer-funding-history' | 'renewal-history' | 'capital-enhancements'

export const fundingReportsApi = {
  get: (type: FundingReportType, params?: Record<string, any>) =>
    http.get(`/funding-reports/${type}`, { params: snakeize(params || {}) }).then((r) => camelize(r.data)),
  pdf: (type: FundingReportType, params?: Record<string, any>) => reportPdf('funding', type, params),
}

export type LoanReportType = 'portfolio-summary' | 'arrears' | 'par-aging' | 'disbursement' | 'closed-loans' | 'loan-detail'

/** One flattened row returned by loanReportsApi.get('loan-detail', ...) — see
 *  LoanReportController::loanDetail(). Distinct from Loan (which mirrors the
 *  nested DB shape); this is already a flat report row. */
export interface LoanDetailReportRow {
  code: string; status: LoanStatus; branch?: string | null; branchCode?: string | null;
  customerCode?: string | null; customerName?: string | null; nicNo?: string | null; passportNo?: string | null;
  phone?: string | null; email?: string | null; address?: string | null; occupation?: string | null; monthlyIncome: number;
  loanType?: string | null; loanCategory?: string | null;
  principal: number; interestRate: number; ratePeriod?: string | null; termMonths: number;
  frequency?: string | null; interestMethod?: string | null; downPayment: number; purpose?: string | null;
  applicationDate?: string | null; approvedAt?: string | null; approvedBy?: string | null;
  disbursedAt?: string | null; disbursedAmount: number; firstInstallmentDate?: string | null;
  startDate?: string | null; maturityDate?: string | null; closedAt?: string | null;
  loanOfficer?: string | null; recoveryOfficer?: string | null;
  referenceName?: string | null; referenceIdNo?: string | null; referenceAddress?: string | null; vehicleNo?: string | null;
  guarantors?: string | null; coApplicants?: string | null; collateral?: string | null; collateralValue: number;
  insuranceCompany?: string | null; insurancePolicyNo?: string | null; insuranceAmount: number;
  chargesDue: number; chargesPaid: number;
  totalScheduled: number; totalPaid: number; outstandingBalance: number;
  daysOverdue: number; overdueAmount: number; penaltyDue: number;
  rejectedReason?: string | null; cancelledReason?: string | null; cancelledAt?: string | null; createdAt?: string | null;
}

export const loanReportsApi = {
  /** signal lets a caller (Loan Detail Report — the heaviest report endpoint) cancel a stale in-flight request when filters change again before it lands. */
  get: (type: LoanReportType, params?: Record<string, any>, signal?: AbortSignal) =>
    http.get(`/loan-reports/${type}`, { params: snakeize(params || {}), signal }).then((r) => camelize(r.data)),
  pdf: (type: LoanReportType, params?: Record<string, any>) => reportPdf('loan', type, params),
}

export type CollectionReportType = 'daily-cash' | 'collection-summary' | 'officer-performance' | 'trend'

export const collectionReportsApi = {
  get: (type: CollectionReportType, params?: Record<string, any>) =>
    http.get(`/collection-reports/${type}`, { params: snakeize(params || {}) }).then((r) => camelize(r.data)),
  pdf: (type: CollectionReportType, params?: Record<string, any>) => reportPdf('collection', type, params),
}

export type CustomerReportType = 'summary' | 'activity'

export const customerReportsApi = {
  get: (type: CustomerReportType, params?: Record<string, any>) =>
    http.get(`/customer-reports/${type}`, { params: snakeize(params || {}) }).then((r) => camelize(r.data)),
  pdf: (type: CustomerReportType, params?: Record<string, any>) => reportPdf('customer', type, params),
}

export type InvestorReportType = 'summary' | 'directory'

export const investorReportsApi = {
  get: (type: InvestorReportType, params?: Record<string, any>) =>
    http.get(`/investor-reports/${type}`, { params: snakeize(params || {}) }).then((r) => camelize(r.data)),
  pdf: (type: InvestorReportType, params?: Record<string, any>) => reportPdf('investor', type, params),
}

export type CashBankReportType = 'position' | 'cashbook-activity' | 'bank-activity'

export const cashBankReportsApi = {
  get: (type: CashBankReportType, params?: Record<string, any>) =>
    http.get(`/cash-bank-reports/${type}`, { params: snakeize(params || {}) }).then((r) => camelize(r.data)),
  pdf: (type: CashBankReportType, params?: Record<string, any>) => reportPdf('cash-bank', type, params),
}

export type ExpenseReportType = 'summary' | 'list' | 'by-vendor'

export const expenseReportsApi = {
  get: (type: ExpenseReportType, params?: Record<string, any>) =>
    http.get(`/expense-reports/${type}`, { params: snakeize(params || {}) }).then((r) => camelize(r.data)),
  pdf: (type: ExpenseReportType, params?: Record<string, any>) => reportPdf('expense', type, params),
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

/* ============================================================
   Accounting: Chart of Accounts / Journal / Reports
   No stored balance/report tables anywhere — every figure below is computed
   live off journal_lines (see ChartOfAccount::balancesAsOf on the backend),
   so Trial Balance, P&L, Balance Sheet and the Chart of Accounts list can
   never disagree with each other.
   ============================================================ */
export type AccountType = 'Asset' | 'Liability' | 'Equity' | 'Income' | 'Expense'

export interface ChartOfAccount {
  id: number; code: string; name: string; accountType: AccountType; normalBalance: 'Debit' | 'Credit';
  parentId?: number | null; parent?: { id: number; code: string; name: string } | null;
  isSystem?: boolean; status?: 'Active' | 'Inactive';
  totalDebit?: string; totalCredit?: string; balance?: string;
}
export const chartOfAccountsApi = {
  list: (asOf?: string) => http.get('/chart-of-accounts', { params: asOf ? { as_of: asOf } : {} }).then((r) => camelize(r.data) as ChartOfAccount[]),
  create: (body: { code: string; name: string; accountType: AccountType; normalBalance: 'Debit' | 'Credit'; parentId?: number | null; status?: string }) =>
    http.post('/chart-of-accounts', snakeize(body)).then((r) => camelize(r.data) as ChartOfAccount),
  update: (id: number, body: Partial<ChartOfAccount>) => http.put(`/chart-of-accounts/${id}`, snakeize(body)).then((r) => camelize(r.data) as ChartOfAccount),
}

export interface JournalLine {
  id: number; accountId: number; account?: { id: number; code: string; name: string } | null;
  debit: string | number; credit: string | number; description?: string | null;
}
export interface JournalEntry {
  id: number; code: string; entryDate: string;
  sourceType?: string | null; sourceId?: number | null; description?: string | null;
  branchId?: number | null; branch?: { id: number; name: string; code: string } | null;
  postedBy?: number | null; poster?: { id: number; name: string } | null; postedAt?: string | null;
  status: 'Posted' | 'Reversed'; reversalOfId?: number | null;
  lines?: JournalLine[];
}
export const journalEntriesApi = {
  list: (params?: { from?: string; to?: string; branchId?: number; status?: string }) =>
    http.get('/journal-entries', { params: snakeize(params || {}) }).then((r) => camelize(r.data) as JournalEntry[]),
  /** Server-paginated — powers the Journal Entries browse screen so it never has to fetch the whole GL audit trail. */
  browse: (params: { from?: string; to?: string; branchId?: number; status?: string; page: number; perPage?: number }) =>
    http.get('/journal-entries', { params: snakeize(params) }).then((r) => camelize(r.data) as Paginated<JournalEntry>),
}

export interface GeneralLedgerLine {
  journalEntryCode: string; entryDate: string; description?: string | null;
  sourceType?: string | null; sourceId?: number | null;
  debit: string | number; credit: string | number; runningBalance: string;
}
export interface GeneralLedgerReport {
  account: { id: number; code: string; name: string; accountType: AccountType; normalBalance: 'Debit' | 'Credit' };
  lines: GeneralLedgerLine[];
}
export interface TrialBalanceRow { accountId: number; code: string; name: string; accountType: AccountType; totalDebit: string; totalCredit: string; balance: string }
export interface PLRow { accountId: number; code: string; name: string; balance: string }
export interface PLIncomeRow { accountId: number; code: string; name: string; cashBalance: string; accrualBalance: string }
export interface PLWaterfall {
  lendingIncome: string; interZonalInterest: string; totalIncome: string; interestCost: string;
  netIncomeExOther: string; documentationIncome: string; realEstateProfit: string; refundsOnWriteOffs: string; netIncome: string;
  staffCost: { salary: string; allowances: string; total: string };
  otherOperatingExpenses: string; profitBeforeHoAbsorption: string; hoExpAbsorption: string;
  netProfitBeforeTaxImpairment: string; provisioningForImpairment: string; badDebtRecovery: string; netProfitAfterImpairment: string;
  vatNbt: string; incomeTax: string; netProfitAfterTax: string; netProfitBeforeInterZonal: string;
}
export interface PLProductRow {
  loanType: string; interestCash: string; interestAccrual: string; charges: string; penalty: string; totalCash: string; totalAccrual: string;
}
export interface ProfitLossReport {
  from?: string | null; to?: string | null; income: PLIncomeRow[]; expense: PLRow[];
  totalIncomeCash: string; totalIncomeAccrual: string; totalExpense: string;
  netProfitCash: string; netProfitAccrual: string;
  waterfallCash: PLWaterfall; waterfallAccrual: PLWaterfall; incomeByProduct: PLProductRow[];
}
export interface BalanceSheetRow { accountId: number; code: string; name: string; balance: string }
export interface BalanceSheetReport {
  asOf: string; assets: BalanceSheetRow[]; liabilities: BalanceSheetRow[]; equity: BalanceSheetRow[];
  totalAssets: string; totalLiabilities: string; totalEquity: string; totalLiabilitiesAndEquity: string; balanced: boolean;
}
/** One cash till or bank account: its own `current_balance` ("book") against the GL's derived balance for its linked chart_of_accounts leaf ("gl"). Nonzero variance = the two disagree. */
export interface LedgerDriftRow {
  kind: 'CashAccount' | 'BankAccount'; id: number; name: string;
  glAccountId: number; glAccountCode: string; bookBalance: string; glBalance: string; variance: string;
}
export interface LedgerDriftReport { asOf: string; rows: LedgerDriftRow[]; hasDrift: boolean }
export interface CashFlowRow { code: string; name: string; amount: number }
export interface CashFlowReport {
  from: string; to: string;
  operatingActivities: CashFlowRow[]; investingActivities: CashFlowRow[]; financingActivities: CashFlowRow[];
  netCashFromOperating: string; netCashFromInvesting: string; netCashFromFinancing: string;
  netChangeInCash: string; openingCash: string; closingCash: string; reconciled: boolean;
}
export const accountingReportsApi = {
  trialBalance: (asOf?: string) => http.get('/accounting-reports/trial-balance', { params: asOf ? { as_of: asOf } : {} }).then((r) => camelize(r.data) as TrialBalanceRow[]),
  generalLedger: (accountId: number, params?: { from?: string; to?: string }) =>
    http.get('/accounting-reports/general-ledger', { params: snakeize({ accountId, ...params }) }).then((r) => camelize(r.data) as GeneralLedgerReport),
  profitLoss: (params?: { from?: string; to?: string }) =>
    http.get('/accounting-reports/profit-loss', { params: snakeize(params || {}) }).then((r) => camelize(r.data) as ProfitLossReport),
  balanceSheet: (asOf?: string) => http.get('/accounting-reports/balance-sheet', { params: asOf ? { as_of: asOf } : {} }).then((r) => camelize(r.data) as BalanceSheetReport),
  cashFlow: (params?: { from?: string; to?: string }) =>
    http.get('/accounting-reports/cash-flow', { params: snakeize(params || {}) }).then((r) => camelize(r.data) as CashFlowReport),
  ledgerDrift: (asOf?: string) => http.get('/accounting-reports/ledger-drift', { params: asOf ? { as_of: asOf } : {} }).then((r) => camelize(r.data) as LedgerDriftReport),
  pdf: (type: 'trial-balance' | 'general-ledger' | 'profit-loss' | 'balance-sheet' | 'cash-flow' | 'ledger-drift', params?: Record<string, any>) =>
    http.get(`/accounting-reports/${type}/pdf`, { params: snakeize(params || {}), responseType: 'blob' }).then((r) => r.data as Blob),
}

/* ============================================================
   Expense Management: Vendors / Expenses / Payment Vouchers
   ============================================================ */
export interface Vendor {
  id: number; name: string; code: string;
  contactPerson?: string | null; phone?: string | null; email?: string | null; address?: string | null; taxNo?: string | null;
  status?: 'Active' | 'Inactive';
}
export const vendorsApi = resource<Vendor>('vendors')

export type ExpenseStatus = 'Pending' | 'Approved' | 'Rejected' | 'Paid'
export type VoucherStatus = 'Issued' | 'Voided'
export interface PaymentVoucher {
  id: number; code: string; expenseId?: number | null; vendorId?: number | null; amount: string | number;
  paymentMethod: PaymentMethod; paidAt: string; paidBy?: number | null;
  cashAccountId?: number | null; bankAccountId?: number | null; chequeNo?: string | null;
  status: VoucherStatus; journalEntryId?: number | null; journalEntry?: JournalEntry | null;
  voidReason?: string | null; voidedBy?: number | null; voidedAt?: string | null; voider?: { id: number; name: string } | null;
  expense?: { id: number; code: string; branchId: number; description?: string | null; branch?: { id: number; name: string; code: string } | null } | null;
  vendor?: { id: number; name: string } | null; payer?: { id: number; name: string } | null;
  cashAccount?: { id: number; name: string } | null; bankAccount?: { id: number; name: string } | null;
}
export interface Expense {
  id: number; code: string; branchId: number; branch?: { id: number; name: string; code: string } | null;
  expenseCategoryId: number; category?: { id: number; name: string; chartOfAccountId?: number | null } | null;
  vendorId?: number | null; vendor?: { id: number; name: string } | null;
  amount: string | number; expenseDate: string; description?: string | null;
  status: ExpenseStatus;
  requestedBy?: number | null; requester?: { id: number; name: string } | null;
  approvedBy?: number | null; approver?: { id: number; name: string } | null; approvedAt?: string | null;
  voucher?: PaymentVoucher | null;
}
export const expensesApi = {
  list: (params?: { status?: ExpenseStatus; branchId?: number }) =>
    http.get('/expenses', { params: snakeize(params || {}) }).then((r) => camelize(r.data) as Expense[]),
  create: (body: { branchId: number; expenseCategoryId: number; vendorId?: number; amount: number | string; expenseDate: string; description?: string }) =>
    http.post('/expenses', snakeize(body)).then((r) => camelize(r.data) as Expense),
  approve: (id: number) => http.put(`/expenses/${id}/approve`).then((r) => camelize(r.data) as Expense),
  reject: (id: number) => http.put(`/expenses/${id}/reject`).then((r) => camelize(r.data) as Expense),
  pay: (id: number, body: { paymentMethod: PaymentMethod; cashAccountId?: number; bankAccountId?: number; chequeNo?: string; paidAt?: string }) =>
    http.post(`/expenses/${id}/pay`, snakeize(body)).then((r) => camelize(r.data) as PaymentVoucher),
}

export const paymentVouchersApi = {
  list: (params?: { status?: VoucherStatus; branchId?: number }) =>
    http.get('/payment-vouchers', { params: snakeize(params || {}) }).then((r) => camelize(r.data) as PaymentVoucher[]),
  void: (id: number, voidReason?: string) =>
    http.delete(`/payment-vouchers/${id}`, { data: { void_reason: voidReason } }).then((r) => camelize(r.data) as PaymentVoucher),
}

/* ============================================================
   Petty Cash: Funds (imprest float) / Replenishments (top-up) / Vouchers (spend)
   ============================================================ */
export interface PettyCashFund {
  id: number; code: string; branchId: number; branch?: { id: number; name: string; code: string } | null;
  custodianId?: number | null; custodian?: { id: number; name: string } | null;
  chartOfAccountId?: number | null; name: string;
  floatLimit: string | number; currentBalance: string | number; status: 'Active' | 'Inactive';
}
export const pettyCashFundsApi = {
  list: (branchId?: number) => http.get('/petty-cash-funds', { params: branchId ? { branch_id: branchId } : {} }).then((r) => camelize(r.data) as PettyCashFund[]),
  create: (body: { branchId: number; custodianId?: number; name: string; floatLimit: number | string; status?: 'Active' | 'Inactive' }) =>
    http.post('/petty-cash-funds', snakeize(body)).then((r) => camelize(r.data) as PettyCashFund),
  update: (id: number, body: Partial<{ branchId: number; custodianId?: number; name: string; floatLimit: number | string; status: 'Active' | 'Inactive' }>) =>
    http.put(`/petty-cash-funds/${id}`, snakeize(body)).then((r) => camelize(r.data) as PettyCashFund),
  remove: (id: number) => http.delete(`/petty-cash-funds/${id}`).then((r) => r.data),
}

export interface PettyCashReplenishment {
  id: number; code: string; pettyCashFundId: number; fund?: { id: number; code: string; name: string; branchId: number; branch?: { id: number; name: string; code: string } | null } | null;
  amount: string | number; fundingMethod: 'Cash' | 'Bank';
  cashAccountId?: number | null; bankAccountId?: number | null;
  cashAccount?: { id: number; name: string } | null; bankAccount?: { id: number; name: string } | null;
  paidAt: string; paidBy?: number | null; payer?: { id: number; name: string } | null;
  status: VoucherStatus; voidReason?: string | null; voidedBy?: number | null; voidedAt?: string | null; voider?: { id: number; name: string } | null;
  journalEntryId?: number | null; journalEntry?: JournalEntry | null;
}
export const pettyCashReplenishmentsApi = {
  list: (params?: { pettyCashFundId?: number; status?: VoucherStatus }) =>
    http.get('/petty-cash-replenishments', { params: snakeize(params || {}) }).then((r) => camelize(r.data) as PettyCashReplenishment[]),
  create: (body: { pettyCashFundId: number; amount: number | string; fundingMethod: 'Cash' | 'Bank'; cashAccountId?: number; bankAccountId?: number; paidAt?: string }) =>
    http.post('/petty-cash-replenishments', snakeize(body)).then((r) => camelize(r.data) as PettyCashReplenishment),
  void: (id: number, voidReason?: string) =>
    http.delete(`/petty-cash-replenishments/${id}`, { data: { void_reason: voidReason } }).then((r) => camelize(r.data) as PettyCashReplenishment),
}

export interface PettyCashVoucher {
  id: number; code: string; pettyCashFundId: number; fund?: { id: number; code: string; name: string; branchId: number; branch?: { id: number; name: string; code: string } | null } | null;
  expenseCategoryId: number; category?: { id: number; name: string } | null;
  vendorId?: number | null; vendor?: { id: number; name: string } | null;
  amount: string | number; description?: string | null; spentAt: string;
  paidBy?: number | null; payer?: { id: number; name: string } | null;
  status: VoucherStatus; voidReason?: string | null; voidedBy?: number | null; voidedAt?: string | null; voider?: { id: number; name: string } | null;
  journalEntryId?: number | null; journalEntry?: JournalEntry | null;
}
export const pettyCashVouchersApi = {
  list: (params?: { pettyCashFundId?: number; status?: VoucherStatus }) =>
    http.get('/petty-cash-vouchers', { params: snakeize(params || {}) }).then((r) => camelize(r.data) as PettyCashVoucher[]),
  create: (body: { pettyCashFundId: number; expenseCategoryId: number; vendorId?: number; amount: number | string; description?: string; spentAt?: string }) =>
    http.post('/petty-cash-vouchers', snakeize(body)).then((r) => camelize(r.data) as PettyCashVoucher),
  void: (id: number, voidReason?: string) =>
    http.delete(`/petty-cash-vouchers/${id}`, { data: { void_reason: voidReason } }).then((r) => camelize(r.data) as PettyCashVoucher),
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

/* ======================= Pawning (Gold Loan) Management =======================
   A pawning ticket is issued and disbursed in one step (see backend
   PawningController's docblock) — there is no Pending/Approved/disburse chain
   like Loan/SpeedDraft, and no installment schedule: interest is flat and
   cycle-based (see PawningSummary below), settled via renew() or redeem(). */

export type PawningStatus = 'Active' | 'Closed' | 'Cancelled' | 'WrittenOff'
export type PawningItemStatus = 'Pledged' | 'Released' | 'Auctioned' | 'Forfeited'
export type PawningRedemptionType = 'Full' | 'Partial'
export type PawningAuctionStatus = 'Scheduled' | 'Completed' | 'Cancelled'
export type PawningAuctionItemStatus = 'Listed' | 'Sold' | 'Unsold' | 'Cancelled'

export interface PawningType {
  id: number; name: string; code: string;
  normalRatePercent: number | string;
  /** Discounted rate for settling within specialRateDays of the current cycle's start — null if this scheme offers no discount. */
  specialRatePercent?: number | string | null;
  specialRateDays?: number | null;
  /** Ticket cycle length in days (e.g. 30) — the real tenure; credit_facilities.termMonths is only a coarse month-equivalent. */
  cycleDays: number;
  /** Max advance as a % of appraised article value. */
  ltvPercent: number | string;
  maxRenewalCount?: number | null;
  gracePeriodDays?: number;
  /** Days overdue (past repayDueDate + gracePeriodDays) before a ticket's articles become auction-eligible. */
  auctionEligibleDays: number;
  /** Statutory notice period required before an auction sale. */
  auctionNoticeDays: number;
  status?: 'Active' | 'Inactive';
  sortOrder?: number;
}
export const pawningTypesApi = resource<PawningType>('pawning-types')

export interface PawningArticleType {
  id: number; name: string; code: string;
  status?: 'Active' | 'Inactive'; sortOrder?: number;
}
export const pawningArticleTypesApi = resource<PawningArticleType>('pawning-article-types')

export interface PawningGoldRate {
  id: number; effectiveDate: string; karat: number | string; ratePerGram: number | string;
  branchId?: number | null; branch?: { id: number; name: string; code: string } | null;
  createdAt?: string;
}
export const pawningGoldRatesApi = {
  list: (karat?: number) => http.get('/pawning-gold-rates', { params: { karat } }).then((r) => camelize(r.data) as PawningGoldRate[]),
  /** The rate to prefill an appraisal with — organisation-wide unless a branch-specific rate exists for that date. */
  latest: (karat: number, branchId?: number, asOf?: string) =>
    http.get('/pawning-gold-rates/latest', { params: { karat, branch_id: branchId, as_of: asOf } }).then((r) => camelize(r.data).rate as PawningGoldRate | null),
  create: (body: { effectiveDate: string; karat: number | string; ratePerGram: number | string; branchId?: number | null }) =>
    http.post('/pawning-gold-rates', snakeize(body)).then((r) => camelize(r.data) as PawningGoldRate),
  remove: (id: number) => http.delete(`/pawning-gold-rates/${id}`).then((r) => r.data),
}

/** One appraised article, 1:1 on a PawnedJewelry facility_collateral row. */
export interface PawningArticleAppraisal {
  itemNo: number;
  articleTypeId: number; articleType?: PawningArticleType | null;
  /** What the customer states — may differ from testedKarat, which is what the loan is actually based on. */
  declaredKarat?: number | string | null;
  testedKarat: number | string;
  grossWeightGrams: number | string;
  netWeightGrams: number | string;
  ratePerGram: number | string;
  appraisedValue: number | string;
  maxLoanValue: number | string;
  itemStatus: PawningItemStatus;
  appraisedBy?: { id: number; name: string } | null;
  remark?: string | null;
}

export interface PawningCollateralItem {
  id: number; creditFacilityId: number;
  collateralType: 'PawnedJewelry';
  description: string; estimatedValue?: number | string | null;
  pawnDetail?: PawningArticleAppraisal | null;
}

/** Write-side: one article line on pawningsApi.create()'s `articles` array. */
export interface PawningArticleInput {
  articleTypeId: number;
  description?: string;
  declaredKarat?: number | string | null;
  testedKarat: number | string;
  grossWeightGrams: number | string;
  netWeightGrams: number | string;
  ratePerGram: number | string;
  remark?: string | null;
}

export interface PawningFacilityDetail {
  pawningTypeId: number; pawningType?: PawningType | null;
  appliedRatePercent: number | string;
  isSpecialRate: boolean;
  totalGrossWeightGrams: number | string;
  totalNetWeightGrams: number | string;
  totalAppraisedValue: number | string;
  repayDueDate: string;
  renewalCount: number;
  previousFacilityId?: number | null; previousFacility?: { id: number; code: string } | null;
  purpose?: string | null;
}

export interface PawningPayment {
  id: number; code: string; creditFacilityId: number;
  amount: number | string;
  paymentType: 'Regular' | 'Interest' | 'CapitalReduction' | 'Settlement';
  paymentMethod: PaymentMethod;
  receiver?: { id: number; name: string } | null;
  paidAt: string; receiptNo?: string | null; notes?: string | null;
  status?: 'Active' | 'Voided';
  voider?: { id: number; name: string } | null; voidedAt?: string | null;
}

export interface PawningRenewal {
  id: number; creditFacilityId: number; renewalNo: number;
  oldDueDate: string; newDueDate: string;
  capitalBefore: number | string; interestSettled: number | string; capitalAfter: number | string;
  appliedRatePercent: number | string;
  renewedBy?: { id: number; name: string } | null; renewedAt: string;
  remark?: string | null;
}

export interface PawningRedemption {
  id: number; creditFacilityId: number; redemptionType: PawningRedemptionType;
  capitalSettled: number | string; interestSettled: number | string; totalSettled: number | string;
  receiptNo?: string | null;
  settledBy?: { id: number; name: string } | null; settledAt: string;
  /** Which articles this redemption released. */
  items?: PawningCollateralItem[];
}

/** Live quote (never stored) — what settling now would cost, at today's cycle position. See backend PawningInterestCalculator. */
export interface PawningInterestQuote {
  rate: number; isSpecial: boolean; daysElapsed: number; interest: number;
}

export interface Pawning {
  id: number; code: string;
  branchId: number; branch?: { id: number; name: string; code: string };
  customerId: number; customer?: { id: number; fullName: string; code: string; nicNo?: string | null; phone?: string | null; address?: string | null };
  principalAmount: number | string;
  /** Starts equal to principalAmount; reduced by renew()'s capital_payment and redeem(). */
  outstandingBalanceCache: number | string;
  interestRatePercent: number | string;
  startDate?: string | null;
  maturityDate?: string | null;
  disbursedAmount?: number | string | null;
  disbursedAt?: string | null;
  status: PawningStatus;
  closedAt?: string | null;
  applier?: { id: number; name: string } | null;
  creator?: { id: number; name: string } | null;

  pawningDetail?: PawningFacilityDetail | null;
  collateral?: PawningCollateralItem[];
  payments?: PawningPayment[];
  renewals?: PawningRenewal[];
  redemptions?: PawningRedemption[];

  /** Write-side only — pawningsApi.create()'s article lines. Never populated on read. */
  articles?: PawningArticleInput[];
  purpose?: string | null;
  previousFacilityId?: number | null;

  summary?: PawningInterestQuote;
}

/** An Active ticket past repayDueDate + the scheme's grace period — see pawningsApi.overdue(). */
export interface OverduePawning extends Pawning {
  daysOverdue: number;
  repayDueDate: string;
}

export const pawningsApi = {
  list: (status?: PawningStatus) => http.get('/pawnings', { params: { status } }).then((r) => camelize(r.data) as Pawning[]),
  get: (id: number) => http.get(`/pawnings/${id}`).then((r) => camelize(r.data) as Pawning),
  overdue: () => http.get('/pawnings-overdue').then((r) => camelize(r.data) as OverduePawning[]),
  create: (body: { branchId: number; customerId: number; pawningTypeId: number; principalAmount: number | string; startDate?: string; purpose?: string; previousFacilityId?: number; articles: PawningArticleInput[] }) =>
    http.post('/pawnings', snakeize(body)).then((r) => camelize(r.data) as Pawning),
  renew: (id: number, body?: { capitalPayment?: number | string; paymentMethod?: PaymentMethod; paidAt?: string; receiptNo?: string; notes?: string }) =>
    http.post(`/pawnings/${id}/renew`, snakeize(body || {})).then((r) => camelize(r.data) as Pawning),
  redeem: (id: number, body: { redemptionType: PawningRedemptionType; facilityCollateralIds?: number[]; paymentMethod?: PaymentMethod; paidAt?: string; receiptNo?: string; notes?: string }) =>
    http.post(`/pawnings/${id}/redeem`, snakeize(body)).then((r) => camelize(r.data) as Pawning),
  /** Voids a renewal/redemption payment and unwinds its effect — only the most recent Active payment on the ticket may be voided (see backend PawningController::voidPayment). */
  voidPayment: (paymentId: number, voidReason?: string) =>
    http.post(`/pawning-payments/${paymentId}/void`, { void_reason: voidReason }).then((r) => camelize(r.data) as Pawning),
}

export interface PawningAuctionItem {
  id: number; pawningAuctionId: number; facilityCollateralId: number; creditFacilityId: number;
  outstandingCapital: number | string; outstandingInterest: number | string;
  reservePrice?: number | string | null; hammerPrice?: number | string | null;
  buyerName?: string | null; buyerNic?: string | null;
  surplusAmount: number | string;
  settlementStatus: 'Pending' | 'Refunded' | 'Forfeited';
  status: PawningAuctionItemStatus;
  soldAt?: string | null;
  collateral?: PawningCollateralItem | null;
  facility?: { id: number; code: string; customer?: { id: number; fullName: string; code: string; nicNo?: string | null } } | null;
}

export interface PawningAuction {
  id: number; branchId: number; branch?: { id: number; name: string; code: string };
  auctionNo: string; scheduledDate: string; noticePublishedDate?: string | null;
  auctioneerName?: string | null; venue?: string | null;
  status: PawningAuctionStatus;
  creator?: { id: number; name: string } | null;
  itemsCount?: number;
  items?: PawningAuctionItem[];
}

/** An Active ticket's pledged article past its scheme's auctionEligibleDays — the pool a new auction batch is built from. See pawningAuctionsApi.eligible(). */
export interface PawningAuctionEligiblePawning extends Pawning {
  daysPastEligible: number;
}

export const pawningAuctionsApi = {
  list: (params?: { status?: PawningAuctionStatus; branchId?: number }) =>
    http.get('/pawning-auctions', { params: snakeize(params || {}) }).then((r) => camelize(r.data) as PawningAuction[]),
  get: (id: number) => http.get(`/pawning-auctions/${id}`).then((r) => camelize(r.data) as PawningAuction),
  eligible: (branchId?: number) =>
    http.get('/pawning-auctions-eligible', { params: { branch_id: branchId } }).then((r) => camelize(r.data) as PawningAuctionEligiblePawning[]),
  create: (body: { branchId: number; scheduledDate: string; auctioneerName?: string; venue?: string }) =>
    http.post('/pawning-auctions', snakeize(body)).then((r) => camelize(r.data) as PawningAuction),
  addItems: (auctionId: number, facilityCollateralIds: number[]) =>
    http.post(`/pawning-auctions/${auctionId}/items`, { facility_collateral_ids: facilityCollateralIds }).then((r) => camelize(r.data) as PawningAuction),
  complete: (auctionId: number) => http.post(`/pawning-auctions/${auctionId}/complete`).then((r) => camelize(r.data) as PawningAuction),
  recordSale: (itemId: number, body: { hammerPrice: number | string; buyerName?: string; buyerNic?: string }) =>
    http.post(`/pawning-auction-items/${itemId}/sale`, snakeize(body)).then((r) => camelize(r.data) as PawningAuctionItem),
  markUnsold: (itemId: number) => http.post(`/pawning-auction-items/${itemId}/unsold`).then((r) => camelize(r.data) as PawningAuctionItem),
  /** Pays a Sold lot's surplus out to the pledger — Dr 2110, Cr till (see PawningLedgerPoster::postSurplusRefund). */
  settleSurplus: (itemId: number) => http.post(`/pawning-auction-items/${itemId}/settle-surplus`).then((r) => camelize(r.data) as PawningAuctionItem),
  /** Surplus unclaimed within the branch's window — becomes the company's (see PawningLedgerPoster::postSurplusForfeiture). */
  forfeitSurplus: (itemId: number) => http.post(`/pawning-auction-items/${itemId}/forfeit-surplus`).then((r) => camelize(r.data) as PawningAuctionItem),
}

export interface DashboardSummary {
  totalAssets: number; totalLiabilities: number; totalDeposits: number;
  totalLoanPortfolio: number; outstandingLoanBalance: number; todaysCollections: number;
  monthIncome: number; monthExpense: number; netProfit: number;
  arrearsAmount: number; defaultLoans: number; activeCustomers: number; activeLoanOfficers: number;
  bankBalances: number; cashInHand: number;
}

export const api = {
  users: {
    ...resource('users'),
    /** Active Marketing Executives — the sales-executive picklist used by
     *  the Loan Officer dropdown on Loan Applications / Loan Detail. */
    marketingExecutives: () =>
      http.get('/users/marketing-executives').then((r) => camelize(r.data) as { id: number; name: string }[]),
    /** Every active user — the custodian picklist used by Petty Cash Funds. */
    active: () =>
      http.get('/users/active').then((r) => camelize(r.data) as { id: number; name: string }[]),
  },
  dashboard: () => http.get('/dashboard').then((r) => camelize(r.data) as DashboardSummary),
}

export default api
