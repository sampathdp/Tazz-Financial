/* Tazz Investment & Credit — shared, rich Loan Details view.
   Used by both Loan Applications (read-only view of a decided application)
   and Active Loans (the live contract, with disbursement/payments). One
   implementation so the two screens never drift out of sync with each other. */
import { useEffect, useRef, useState } from 'react'
import { Card, Btn, Badge, Field, Input, Select, BankSelect, Table, Td, Modal, inputStyle, ErrorBanner } from '../../components/ui'
import { Icon } from '../../components/Icon'
import { PaymentBreakdown } from '../../components/PaymentBreakdown'
import { useFilePreview } from '../../components/FilePreview'
import { PrintDocumentsPanel } from './PrintDocuments'
import {
  company, loansApi, customersApi, fdLoansApi, loanPostDatedChequesApi, loanDocumentsApi, loanDiaryNotesApi, loanChargesApi, loanPaymentsApi, loanCollateralDocumentsApi, documentCategoriesApi, chargeCategoriesApi, bankAccountsApi, branchesApi, api,
  type Customer, type Loan, type LoanStatus, type LoanSchedule, type BankAccount,
  type LoanPostDatedCheque, type ChequeStatus,
  type LoanDocument, type DocumentCategory, type LoanDiaryNote,
  type LoanCharge, type LoanPayment, type PaymentMethod, type LoanCollateralDocument,
  type FacilityStatusEventType, type AuthUser,
} from '../../api'
import { Can, canApprove, isAdmin } from '../../lib/permissions'
import { useNotify } from '../../components/Notify'
import { CustomerDetailCard } from '../../components/CustomerDetailCard'
import { CustomerBankDetailPicker } from '../../components/CustomerBankDetailPicker'
import { getErrorText } from '../../lib/apiError'
import type { Go } from '../types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const pct = (v: unknown) => num(v).toFixed(2)
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const statusTone: Record<LoanStatus, 'green' | 'red' | 'amber' | 'blue' | 'neutral'> = {
  Pending: 'amber', Approved: 'blue', Rejected: 'red', Cancelled: 'red', Active: 'green', Lapsed: 'amber', Closed: 'neutral', WrittenOff: 'red', Restructured: 'amber',
}
const scheduleTone: Record<string, 'green' | 'red' | 'amber' | 'neutral'> = {
  Paid: 'green', Partial: 'amber', Overdue: 'red', Pending: 'neutral',
}
const chequeTone: Record<ChequeStatus, 'green' | 'red' | 'amber' | 'neutral' | 'blue'> = {
  Pending: 'neutral', Handed: 'blue', Realized: 'green', Bounced: 'red', Returned: 'amber',
}


const ACTIVE_STAGE_STATUSES: LoanStatus[] = ['Active', 'Lapsed', 'Closed', 'WrittenOff', 'Restructured']

const SECTIONS = [
  { id: 'summary', label: 'Summary', icon: 'chart' },
  { id: 'customer', label: 'Customer Details', icon: 'users' },
  { id: 'due-types', label: 'Due Types', icon: 'coins' },
  { id: 'contract-status', label: 'Contract Status', icon: 'target' },
  { id: 'recovery', label: 'Recovery', icon: 'users' },
  { id: 'schedule', label: 'Rental Schedule', icon: 'calendar' },
  { id: 'receipts', label: 'Receipts', icon: 'receipt' },
  { id: 'security', label: 'Security', icon: 'shield' },
  { id: 'cheques', label: 'Post Dated Cheques', icon: 'cheque' },
  { id: 'ledger', label: 'Ledger Card', icon: 'ledger' },
  { id: 'diary', label: 'Diarizing', icon: 'clock' },
  { id: 'waivers', label: 'Waivers', icon: 'refund' },
  { id: 'images', label: 'Contract Images', icon: 'doc' },
  { id: 'print', label: 'Print Documents', icon: 'print' },
]

/** Icon-chip colour per section, grouped loosely: overview/customer blue, repayment green, recovery amber, documents neutral. */
const SECTION_TONE: Record<string, 'blue' | 'green' | 'amber' | 'red' | 'neutral'> = {
  summary: 'blue', 'contract-status': 'blue', customer: 'blue', security: 'blue',
  schedule: 'green', receipts: 'green', 'due-types': 'green', cheques: 'green', ledger: 'green', waivers: 'green',
  recovery: 'amber', diary: 'amber', images: 'neutral', print: 'neutral',
}

export function LoanDetail({ loan, me, onChanged, go, context }: {
  loan: Loan; me?: AuthUser | null; onChanged: () => void; go?: Go; context: 'applications' | 'active'
}) {
  const notify = useNotify()
  const [statusModal, setStatusModal] = useState(false)
  const [tab, setTab] = useState(SECTIONS[0].id)
  const [busy, setBusy] = useState(false)

  const awaitingDisbursement = (loan.status === 'Approved' || loan.disbursementIncomplete) && context === 'active'
  const awaitingBanner = awaitingDisbursement && (
      <Card style={{ marginBottom: 16 }}>
        <div className="row between wrap gap-2" style={{ alignItems: 'center' }}>
          <div className="eyebrow">{loan.disbursementIncomplete ? 'Disbursement did not finish' : 'Awaiting disbursement'} — handled by Finance on the Loan Disbursement page</div>
          <div className="row gap-2 wrap">
            <DocumentChargeButton loan={loan} me={me} onChanged={onChanged} notify={notify} />
            <Can user={me} module="Loan Disbursement" action="View">
              <Btn size="sm" variant="solid" icon="upload" onClick={() => go?.(`loan/disbursement/${loan.id}`)}>Open in Loan Disbursement</Btn>
            </Can>
          </div>
        </div>
      </Card>
  )

  // Submitting a cancel request uses the same module as today's other decisions
  // on this loan; approving/rejecting one is a deliberately separate permission
  // ('Loan Cancel Approvals') — being able to approve a normal application or
  // disbursement doesn't imply being trusted to approve cancelling one.
  const cancelModule = context === 'active' ? 'Active Loans' : 'Loan Applications'
  const pendingCancel = loan.pendingCancelRequest
  const pendingWriteOff = loan.pendingWriteOffRequest

  const approveApplication = async () => {
    setBusy(true)
    try { await loansApi.approve(loan.id); notify.success('Loan approved', loan.code); onChanged() }
    catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
    finally { setBusy(false) }
  }
  const rejectApplication = async () => {
    const reason = await notify.prompt({ title: `Reject loan ${loan.code}?`, msg: 'Give a reason — this is shown to branch staff.', placeholder: 'Reason for rejection', required: true, danger: true, okText: 'Reject' })
    if (!reason) return
    setBusy(true)
    try { await loansApi.reject(loan.id, reason); notify.success('Loan rejected', loan.code); onChanged() }
    catch (ex: any) { notify.error('Reject failed', getErrorText(ex)) }
    finally { setBusy(false) }
  }
  const withdrawApplication = async () => {
    const reason = await notify.prompt({ title: `Withdraw application ${loan.code}?`, msg: 'Give a reason — the application stays on record, marked Rejected.', placeholder: 'Reason for withdrawal', required: true, danger: true, okText: 'Withdraw' })
    if (!reason) return
    setBusy(true)
    try { await loansApi.remove(loan.id, reason); notify.success('Application withdrawn', loan.code); onChanged() }
    catch (ex: any) { notify.error('Withdraw failed', getErrorText(ex)) }
    finally { setBusy(false) }
  }

  const cancelLoan = async () => {
    const msg = loan.status === 'Active'
      ? 'Disbursed but no payments collected yet — this removes the schedule and reverts the disbursement once approved. Give a reason.'
      : 'Approved but not yet disbursed — give a reason. Needs a second person to approve before it takes effect.'
    const reason = await notify.prompt({ title: `Request cancel for loan ${loan.code}?`, msg, placeholder: 'Reason for cancellation', required: true, danger: true, okText: 'Send Request' })
    if (!reason) return
    try { await loansApi.requestCancel(loan.id, reason); notify.success('Cancel request sent', 'Awaiting approval'); onChanged() }
    catch (ex: any) { notify.error('Request failed', getErrorText(ex)) }
  }

  const approveCancel = async () => {
    if (!pendingCancel) return
    try { await loansApi.approveCancelRequest(pendingCancel.id); notify.success('Approved'); onChanged() }
    catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
  }

  const rejectCancel = async () => {
    if (!pendingCancel) return
    const reason = await notify.prompt({ title: 'Reject this cancel request?', msg: 'Give a reason — the requester can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try { await loansApi.rejectCancelRequest(pendingCancel.id, reason || undefined); notify.success('Cancel request rejected'); onChanged() }
    catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }

  // Loan secured by an FD: the lien row says which FD, and whether it has matured decides if settling is premature.
  const fdLien = loan.collateral?.map((c) => c.fdDetail).find((d) => d?.lienStatus === 'Active') || null
  const settleFromFd = async () => {
    if (!fdLien) return
    const matured = !!fdLien.fixedDeposit && dateOnly(fdLien.fixedDeposit.maturityDate) <= todayIso()
    const ok = await notify.confirm({
      title: `Settle loan ${loan.code} from FD ${fdLien.fixedDeposit?.fdNumber ?? ''}?`,
      msg: `The FD is closed and its ${matured ? 'payout' : 'principal (interest is forfeited — it has not matured)'} is applied to this loan. Any amount above the loan balance is paid to the customer in cash. This cannot be voided.`,
      danger: true, okText: 'Settle from FD',
    })
    if (!ok) return
    try {
      const r = await fdLoansApi.settle(loan.id, { allowPremature: !matured })
      notify.success('FD adjusted against loan', `Applied ${money(r.appliedToLoan)}${r.paidToCustomer > 0 ? ` · pay customer ${money(r.paidToCustomer)}` : ''}${r.remainingLoanBalance > 0 ? ` · balance left ${money(r.remainingLoanBalance)}` : ''}`)
      onChanged()
    } catch (ex: any) { notify.error('Settlement failed', getErrorText(ex)) }
  }

  const writeOffLoan = async () => {
    const reason = await notify.prompt({ title: `Request write-off for loan ${loan.code}?`, msg: `Bad debt — once approved this posts ${money(loan.outstandingBalanceCache)} as a loss and closes the loan. Needs a second person to approve before it takes effect.`, placeholder: 'Reason for write-off', required: true, danger: true, okText: 'Send Request' })
    if (!reason) return
    try { await loansApi.requestWriteOff(loan.id, reason); notify.success('Write-off request sent', 'Awaiting approval'); onChanged() }
    catch (ex: any) { notify.error('Request failed', getErrorText(ex)) }
  }

  const approveWriteOff = async () => {
    if (!pendingWriteOff) return
    try { await loansApi.approveWriteOffRequest(pendingWriteOff.id); notify.success('Approved'); onChanged() }
    catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
  }

  const rejectWriteOff = async () => {
    if (!pendingWriteOff) return
    const reason = await notify.prompt({ title: 'Reject this write-off request?', msg: 'Give a reason — the requester can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try { await loansApi.rejectWriteOffRequest(pendingWriteOff.id, reason || undefined); notify.success('Write-off request rejected'); onChanged() }
    catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }

  // The auto-close on payoff (LoanPaymentAllocator) checks each installment's
  // own bucket split, not just the aggregate due-type totals shown in the Due
  // Type Breakdown card — an early settlement that overpays one installment's
  // interest while underpaying its principal by the same amount nets to 0
  // here but not there, leaving a fully-settled loan stuck Active. This mirrors
  // that same aggregate check so the button only appears when it'll succeed.
  const dueBalance = (loan.summary?.dueBreakdown || []).reduce((a, r) => a + r.balance, 0)
  const canCloseLoan = (loan.status === 'Active' || loan.status === 'Lapsed') && Math.round(dueBalance * 100) / 100 <= 0.01
  const closeLoan = async () => {
    const ok = await notify.confirm({ title: `Close loan ${loan.code}?`, msg: 'This loan is fully settled but did not auto-close. Marks it Closed.', okText: 'Close Loan' })
    if (!ok) return
    try { await loansApi.close(loan.id); notify.success('Loan closed', loan.code); onChanged() }
    catch (ex: any) { notify.error('Close failed', getErrorText(ex)) }
  }

  const eventOf = (type: FacilityStatusEventType) => (loan.statusEvents || []).find((e) => e.eventType === type)
  const flags: { label: string; on?: boolean; at?: string | null }[] = [
    { label: 'Terminated', on: !!eventOf('Terminated'), at: eventOf('Terminated')?.eventDate },
    { label: 'Legal', on: !!eventOf('Legal'), at: eventOf('Legal')?.eventDate },
    { label: 'Seizing Order', on: !!eventOf('SeizingOrderIssued'), at: eventOf('SeizingOrderIssued')?.eventDate },
    { label: 'Repossessed', on: !!eventOf('Repossessed'), at: eventOf('Repossessed')?.eventDate },
  ].filter((f) => f.on)

  return (
    <div>
      {awaitingBanner}
      <div className="row between wrap gap-2 no-print" style={{ marginBottom: 14 }}>
        <div className="row gap-2 wrap" style={{ alignItems: 'center' }}>
          <Badge tone={statusTone[loan.status]} dot>{loan.status}</Badge>
          {flags.map((f) => <Badge key={f.label} tone="red">{f.label}{f.at ? ` · ${dateOnly(f.at)}` : ''}</Badge>)}
          {loan.status === 'Rejected' && loan.rejectedReason && <span className="t-2" style={{ fontSize: 12 }}>Reason: {loan.rejectedReason}</span>}
          {loan.status === 'Cancelled' && loan.cancelledReason && <span className="t-2" style={{ fontSize: 12 }}>Reason: {loan.cancelledReason}</span>}
          {loan.status === 'WrittenOff' && loan.writtenOffReason && <span className="t-2" style={{ fontSize: 12 }}>Reason: {loan.writtenOffReason}{loan.writtenOffBy ? ` — ${loan.writtenOffBy.name}` : ''}</span>}
        </div>
        <div className="row gap-2 wrap">
          {loan.status === 'Pending' && context === 'applications' && (
            <>
              {isAdmin(me) && <Btn size="sm" variant="solid" icon="edit" onClick={() => go?.(`loan/apps/${loan.id}/edit`)}>Edit</Btn>}
              {canApprove(me, 'loan_application') && me?.id !== loan.pendingApplicationRequest?.requester?.id && (
                <>
                  <Btn size="sm" variant="primary" icon="check" onClick={approveApplication} disabled={busy}>Approve</Btn>
                  <Btn size="sm" variant="danger" icon="x" onClick={rejectApplication} disabled={busy}>Reject</Btn>
                </>
              )}
              <Can user={me} module="Loan Applications" action="Delete">
                <Btn size="sm" variant="solid" icon="trash" onClick={withdrawApplication} disabled={busy}>Withdraw</Btn>
              </Can>
            </>
          )}
          {loan.status === 'Approved' && !awaitingDisbursement && <DocumentChargeButton loan={loan} me={me} onChanged={onChanged} notify={notify} />}
          {!pendingCancel && (loan.status === 'Approved' || (loan.status === 'Active' && !loan.payments?.length)) && (
            <Can user={me} module={cancelModule} action="Delete">
              <Btn size="sm" variant="danger" icon="x" onClick={cancelLoan}>Cancel Loan</Btn>
            </Can>
          )}
          {loan.status !== 'Pending' && (
            <Can user={me} module="Active Loans" action="Edit">
              <Btn size="sm" variant="solid" icon="flag" onClick={() => setStatusModal(true)}>Contract Status Change</Btn>
            </Can>
          )}
          {loan.status === 'Active' && fdLien && (
            <Can user={me} module="Active Loans" action="Approve">
              <Btn size="sm" variant="primary" icon="swap" onClick={settleFromFd}>Settle from FD</Btn>
            </Can>
          )}
          {canCloseLoan && (
            <Can user={me} module="Active Loans" action="Edit">
              <Btn size="sm" variant="solid" icon="check" onClick={closeLoan}>Close Loan</Btn>
            </Can>
          )}
          {(loan.status === 'Active' || loan.status === 'Lapsed') && !pendingWriteOff && (() => {
            // Backend also gates this (LoanController::writeOffRequest — see LoanProvisioning)
            // and returns a 422 either way; disabling here is just a UX nicety so the user
            // sees why before clicking, not the source of truth.
            const needsProvision = loan.provisionRequiredPct === 100 && num(loan.provisionPostedAmount) < num(loan.provisionRequiredAmount) - 0.01
            return (
              <Can user={me} module="Active Loans" action="Delete">
                <Btn size="sm" variant="danger" icon="alert" disabled={needsProvision} onClick={writeOffLoan}
                  title={needsProvision ? 'Post the full impairment provision on the Loan Provisioning screen before writing off this loan.' : undefined}>
                  Request Write-off
                </Btn>
              </Can>
            )
          })()}
          {context === 'applications' && go && ACTIVE_STAGE_STATUSES.includes(loan.status) && (
            <Btn size="sm" variant="solid" icon="coins" onClick={() => go('loan/active')}>Open in Active Loans</Btn>
          )}
        </div>
      </div>

      {(loan.restructuredFrom || loan.restructuredTo) && (
        <div className="row gap-2 wrap no-print" style={{ marginBottom: 16, padding: '10px 14px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
          {loan.restructuredFrom && (
            <span>Rescheduled from{' '}
              <button className="mms-act" style={{ color: 'var(--ac-bright)', background: 'transparent', border: 'none', cursor: 'pointer', padding: 0, font: 'inherit' }}
                onClick={() => go?.(`loan/active/${loan.restructuredFrom!.id}`)}>{loan.restructuredFrom.code}</button>
            </span>
          )}
          {loan.restructuredTo && (
            <span>Rescheduled — replaced by{' '}
              <button className="mms-act" style={{ color: 'var(--ac-bright)', background: 'transparent', border: 'none', cursor: 'pointer', padding: 0, font: 'inherit' }}
                onClick={() => go?.(`loan/active/${loan.restructuredTo!.id}`)}>{loan.restructuredTo.code}</button>
            </span>
          )}
        </div>
      )}

      {pendingCancel && (
        <div className="row between wrap gap-2 no-print" style={{ marginBottom: 16, padding: '10px 14px', background: 'var(--warn-dim)', border: '1px solid var(--warn)', borderRadius: 'var(--r-s)' }}>
          <div style={{ fontSize: 12.5 }}>
            <b>Cancel request pending</b> — {pendingCancel.requester?.name || '—'} · {pendingCancel.reason}
            <div className="t-2" style={{ fontSize: 11.5, marginTop: 2 }}>
              {pendingCancel.firstApprover ? `Approved by ${pendingCancel.firstApprover.name}, ` : ''}
              needs {pendingCancel.requiredApprovals - (pendingCancel.firstApprover ? 1 : 0)} more approval{pendingCancel.requiredApprovals - (pendingCancel.firstApprover ? 1 : 0) === 1 ? '' : 's'}
            </div>
          </div>
          {canApprove(me, 'loan_cancel') && me?.id !== pendingCancel.requester?.id && me?.id !== pendingCancel.firstApprover?.id && (
            <div className="row gap-2">
              <Btn size="sm" variant="primary" icon="check" onClick={approveCancel}>Approve</Btn>
              <Btn size="sm" variant="danger" icon="x" onClick={rejectCancel}>Reject</Btn>
            </div>
          )}
        </div>
      )}

      {pendingWriteOff && (
        <div className="row between wrap gap-2 no-print" style={{ marginBottom: 16, padding: '10px 14px', background: 'var(--warn-dim)', border: '1px solid var(--warn)', borderRadius: 'var(--r-s)' }}>
          <div style={{ fontSize: 12.5 }}>
            <b>Write-off request pending</b> — {pendingWriteOff.requester?.name || '—'} · {pendingWriteOff.reason}
            <div className="t-2" style={{ fontSize: 11.5, marginTop: 2 }}>
              {pendingWriteOff.firstApprover ? `Approved by ${pendingWriteOff.firstApprover.name}, ` : ''}
              needs {pendingWriteOff.requiredApprovals - (pendingWriteOff.firstApprover ? 1 : 0)} more approval{pendingWriteOff.requiredApprovals - (pendingWriteOff.firstApprover ? 1 : 0) === 1 ? '' : 's'}
            </div>
          </div>
          {canApprove(me, 'loan_write_off') && me?.id !== pendingWriteOff.requester?.id && me?.id !== pendingWriteOff.firstApprover?.id && (
            <div className="row gap-2">
              <Btn size="sm" variant="primary" icon="check" onClick={approveWriteOff}>Approve</Btn>
              <Btn size="sm" variant="danger" icon="x" onClick={rejectWriteOff}>Reject</Btn>
            </div>
          )}
        </div>
      )}

      {/* Tabs — one section loaded at a time, matching the reference screen's button row without the scroll-everything sprawl. */}
      <div>
        <div className="ld-tiles no-print" role="tablist" aria-label="Loan sections">
          {SECTIONS.map((s) => {
            const on = tab === s.id
            const tone = SECTION_TONE[s.id] ?? 'blue'
            return (
              <button key={s.id} role="tab" aria-selected={on} className={`ld-tile${on ? ' on' : ''}`} onClick={() => setTab(s.id)}>
                <span className="ld-chip" style={{ background: toneDim[tone], color: toneColor[tone] }}><Icon n={s.icon} s={13} /></span>
                <span className="ld-label">{s.label}</span>
              </button>
            )
          })}
        </div>

        <div className="ld-body">
          {tab === 'summary' && <ContractSummary loan={loan} />}
          {tab === 'customer' && <CustomerTab loan={loan} />}
          {tab === 'due-types' && <DueTypeBreakdown loan={loan} />}
          {tab === 'contract-status' && <ArrearsPanel loan={loan} />}
          {tab === 'recovery' && <RecoveryDetails loan={loan} me={me} onChanged={onChanged} notify={notify} />}
          {tab === 'schedule' && <ScheduleTable loan={loan} />}
          {tab === 'receipts' && <ReceiptsPanel loan={loan} me={me} />}
          {tab === 'security' && <SecurityPanel loan={loan} />}
          {tab === 'cheques' && <ChequesPanel loan={loan} me={me} onChanged={onChanged} notify={notify} />}
          {tab === 'ledger' && <LedgerCard loan={loan} />}
          {tab === 'diary' && <DiaryPanel loan={loan} me={me} onChanged={onChanged} notify={notify} />}
          {tab === 'waivers' && <WaiversPanel loan={loan} />}
          {tab === 'images' && <ImagesPanel loan={loan} me={me} />}
          {tab === 'print' && <PrintDocumentsPanel loan={loan} me={me} />}
        </div>
      </div>

      <StatusFlagsModal open={statusModal} onClose={() => setStatusModal(false)} loan={loan} onChanged={onChanged} notify={notify} />
    </div>
  )
}

/* ---------- Colored KPI tiles (same chip-icon pattern as the Dashboard's KPI cards) ---------- */
type Tone = 'blue' | 'green' | 'amber' | 'red' | 'neutral'
const toneColor: Record<Tone, string> = { blue: 'var(--ac-bright)', green: 'var(--ok)', amber: 'var(--warn)', red: 'var(--bad)', neutral: 'var(--tx-1)' }
const toneDim: Record<Tone, string> = { blue: 'var(--ac-dim)', green: 'var(--ok-dim)', amber: 'var(--warn-dim)', red: 'var(--bad-dim)', neutral: 'var(--bg-3)' }

interface KpiItem { label: string; value: React.ReactNode; icon: string; tone?: Tone }
function Kpi({ label, value, icon, tone = 'neutral' }: KpiItem) {
  return (
    <Card pad={16}>
      <div className="row gap-3" style={{ alignItems: 'center' }}>
        <div style={{ width: 38, height: 38, borderRadius: 10, background: toneDim[tone], display: 'grid', placeItems: 'center', color: toneColor[tone], flexShrink: 0 }}>
          <Icon n={icon} s={19} />
        </div>
        <div style={{ minWidth: 0 }}>
          <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 2 }}>{label}</div>
          <div style={{ fontSize: 16.5, fontWeight: 700, color: tone === 'neutral' ? 'var(--tx-0)' : toneColor[tone], lineHeight: 1.25 }}>{value}</div>
        </div>
      </div>
    </Card>
  )
}
function KpiGrid({ items }: { items: KpiItem[] }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 14 }}>
      {items.map((it) => <Kpi key={it.label} {...it} />)}
    </div>
  )
}

/** Full customer profile, active loans and credit report for the applicant — so an approver can review them without leaving the loan. */
function CustomerTab({ loan }: { loan: Loan }) {
  const [customer, setCustomer] = useState<Customer | null>(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => {
    setLoading(true)
    customersApi.get(loan.customerId).then(setCustomer).catch(() => setCustomer(null)).finally(() => setLoading(false))
  }, [loan.customerId])
  if (loading) return <div className="t-2" style={{ fontSize: 12.5 }}>Loading customer…</div>
  if (!customer) return <div className="t-2" style={{ fontSize: 12.5 }}>Customer details unavailable.</div>
  return <CustomerDetailCard c={customer} />
}

function ContractSummary({ loan }: { loan: Loan }) {
  const schedules = loan.schedules || []
  const expiry = schedules.length ? schedules[schedules.length - 1].dueDate : null
  const installmentAmount = schedules.length ? schedules[0].totalDue : null
  const isFlat = loan.loanDetail?.interestMethod === 'flat'
  const aer = loan.loanDetail?.effectiveAnnualRate
  const emr = loan.loanDetail?.effectiveMonthlyRate
  const dash = <span className="t-2">—</span>
  const co = (loan.coApplicants || []).map((ca) => ca.coApplicant?.fullName).filter(Boolean).join(', ')
  const stat = (label: string, value: React.ReactNode, big?: boolean) => (
    <div className={`ld-stat${big ? ' big' : ''}`}><span>{label}</span><b>{value}</b></div>
  )
  const steps: { label: string; date?: string | null }[] = [
    { label: 'Applied', date: loan.applicationDate || loan.loanDetail?.applicationDate || loan.createdAt },
    { label: 'Approved', date: loan.approvedAt },
    { label: 'Disbursed', date: loan.disbursedAt },
    { label: 'Expiry', date: expiry },
    { label: 'Closed', date: loan.closedAt },
  ]
  const firstOpen = steps.findIndex((st) => !st.date)
  type Row = [string, React.ReactNode]
  const details: { title: string; rows: Row[] }[] = [
    { title: 'Contract', rows: [
      ['Contract No', loan.code],
      ['Member', <>{loan.customer?.fullName} <span className="t-2" style={{ fontWeight: 400, fontSize: 11 }}>{loan.customer?.code}</span></>],
      ...(co ? [[`Co-Applicant${(loan.coApplicants?.length || 0) > 1 ? 's' : ''}`, co]] as Row[] : []),
      ['Branch', loan.branch?.name || dash],
      ['Product', loan.loanDetail?.loanType?.name || dash],
      ['Loan Officer', loan.loanDetail?.loanOfficer?.name || dash],
      ['Recovery Officer', loan.loanDetail?.recoveryOfficer?.name || dash],
    ] },
    { title: 'Terms', rows: [
      ['Contract Amount', money(loan.principalAmount)],
      ['Down Payment', money(loan.loanDetail?.downPayment)],
      ['Installment Amount', installmentAmount ? money(installmentAmount) : dash],
      ['Period', `${loan.termMonths} mo`],
      ['Interest Type', isFlat ? 'Flat' : 'Reducing Rate'],
      [isFlat ? 'Entered Rate' : 'Annual Rate', isFlat ? `${loan.interestRatePercent}% Flat` : `${loan.interestRatePercent}%`],
      ...(isFlat && aer != null ? [
        ['Equivalent Annual Effective Rate (AER)', `${pct(aer)}%`],
        ['Equivalent Monthly Effective Rate', `${pct(emr)}%`],
      ] as Row[] : []),
    ] },
    { title: 'Dates', rows: [
      ['Application Date', dateOnly(loan.applicationDate || loan.loanDetail?.applicationDate || loan.createdAt)],
      ['Created On', dateOnly(loan.createdAt)],
      ['Approved On', loan.approvedAt ? dateOnly(loan.approvedAt) : dash],
      ['Disbursed / Activated', loan.disbursedAt ? dateOnly(loan.disbursedAt) : dash],
      ['Expiry', expiry ? dateOnly(expiry) : dash],
      ['Closed On', loan.closedAt ? dateOnly(loan.closedAt) : dash],
    ] },
  ]
  return (
    <div className="ld-sum">
      <div className="ld-hero">
        <div>
          <div className="ld-hero-code">{loan.code}</div>
          <div className="ld-hero-member">{loan.customer?.fullName} <span>{loan.customer?.code}</span></div>
        </div>
        <div className="ld-hero-amt"><span>Contract Amount</span><b>{money(loan.principalAmount)}</b></div>
      </div>

      <div className="ld-stats">
        {stat('Installment', installmentAmount ? money(installmentAmount) : dash)}
        {stat('Period', `${loan.termMonths} months`)}
        {stat(isFlat ? 'Rate (flat)' : 'Annual rate', `${loan.interestRatePercent}%`)}
        {stat('Down payment', money(loan.loanDetail?.downPayment))}
      </div>

      <ol className="ld-steps">
        {steps.map((st, i) => (
          <li key={st.label} className={st.date ? 'done' : i === firstOpen ? 'next' : ''}>
            <i />
            <b>{st.date ? dateOnly(st.date) : 'Not yet'}</b>
            <span>{st.label}</span>
          </li>
        ))}
      </ol>

      <div className="ld-details">
        {details.map((g) => (
          <section key={g.title}>
            <h4>{g.title}</h4>
            <dl>{g.rows.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl>
          </section>
        ))}
      </div>
    </div>
  )
}

/* ---------- Due Type Breakdown ---------- */
function DueTypeBreakdown({ loan }: { loan: Loan }) {
  const rows = loan.summary?.dueBreakdown || []
  const totals = rows.reduce((a, r) => ({ due: a.due + r.due, paid: a.paid + r.paid, balance: a.balance + r.balance }), { due: 0, paid: 0, balance: 0 })
  return (
    <Card pad={0}>
      <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Due Type Breakdown</div>
      <Table cols={[{ label: 'Due Type' }, { label: 'Due Amount', align: 'right' }, { label: 'Paid Amount', align: 'right' }, { label: 'Balance', align: 'right' }, { label: 'Recovery %', align: 'center' }]}
        rows={rows} empty="No charges recorded"
        render={(r) => <>
          <Td style={{ fontWeight: 600 }}>{r.type}</Td>
          <Td mono align="right">{money(r.due)}</Td>
          <Td mono align="right" c="var(--ok)">{money(r.paid)}</Td>
          <Td mono align="right" c={r.balance > 0 ? 'var(--bad)' : 'var(--tx-1)'}>{money(r.balance)}</Td>
          <Td align="center"><Badge tone={r.recoveryPct >= 100 ? 'green' : r.recoveryPct > 0 ? 'amber' : 'red'} dot>{r.recoveryPct.toFixed(2)}%</Badge></Td>
        </>}
        footer={rows.length > 0 ? (
          <tr style={{ fontWeight: 700, borderTop: '2px solid var(--line)' }}>
            <Td>Totals</Td>
            <Td mono align="right">{money(totals.due)}</Td>
            <Td mono align="right">{money(totals.paid)}</Td>
            <Td mono align="right">{money(totals.balance)}</Td>
            <Td></Td>
          </tr>
        ) : undefined}
      />
    </Card>
  )
}

/* ---------- Arrears / Contract Status ---------- */
function ArrearsPanel({ loan }: { loan: Loan }) {
  const a = loan.summary?.arrears
  if (!a) return null
  const documentCharges = loan.summary?.dueBreakdown?.find((r) => r.type === 'Document')
  return (
    <>
      <div className="eyebrow" style={{ marginBottom: 10 }}>Contract Status</div>
      <KpiGrid items={[
        { label: 'Total OD', value: <>{a.daysOverdue} days<div className="t-2" style={{ fontSize: 11, fontWeight: 400 }}>{a.overdueInstallments.toFixed(2)} installments</div></>, icon: 'clock', tone: a.daysOverdue > 0 ? 'red' : 'green' },
        { label: 'Rental Arrears', value: money(a.rentalArrears), icon: 'alert', tone: a.rentalArrears > 0 ? 'red' : 'green' },
        { label: 'Other Charge Arrears', value: money(a.otherChargeArrears), icon: 'alert', tone: a.otherChargeArrears > 0 ? 'red' : 'green' },
        { label: 'Default Charges', value: money(a.defaultCharges), icon: 'alert', tone: a.defaultCharges > 0 ? 'red' : 'green' },
        { label: 'Document Charges', value: money(documentCharges?.balance ?? 0), icon: 'doc', tone: (documentCharges?.balance ?? 0) > 0 ? 'red' : 'green' },
        { label: 'Total Arrears', value: money(a.totalArrears), icon: 'target', tone: a.totalArrears > 0 ? 'red' : 'green' },
        { label: 'Over Payment', value: money(a.overPayment), icon: 'refund', tone: a.overPayment > 0 ? 'blue' : 'neutral' },
        { label: 'Future Capital', value: money(a.futureCapital), icon: 'coins', tone: 'blue' },
        { label: 'Future Interest', value: money(a.futureInterest), icon: 'chart', tone: 'blue' },
        { label: 'Balance Pre-Payment', value: money(a.balancePrePayment), icon: 'wallet', tone: a.balancePrePayment > 0 ? 'amber' : 'green' },
        { label: 'Amount To Be Collected', value: money(a.amountToBeCollected), icon: 'target', tone: 'amber' },
        { label: 'Total Waived', value: money(a.totalWaived), icon: 'refund', tone: a.totalWaived > 0 ? 'blue' : 'neutral' },
      ]} />
    </>
  )
}

/* ---------- Recovery Details ---------- */
function RecoveryDetails({ loan, me, onChanged, notify }: { loan: Loan; me?: AuthUser | null; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const r = loan.summary?.recovery
  const [editing, setEditing] = useState(false)
  const [officers, setOfficers] = useState<{ id: number; name: string }[]>([])
  const [loanOfficerId, setLoanOfficerId] = useState<number | ''>(loan.loanDetail?.loanOfficerId || '')
  const [recoveryOfficerId, setRecoveryOfficerId] = useState<number | ''>(loan.loanDetail?.recoveryOfficerId || '')
  const [busy, setBusy] = useState(false)

  const openEdit = () => {
    api.users.marketingExecutives().then(setOfficers).catch(() => setOfficers([]))
    setLoanOfficerId(loan.loanDetail?.loanOfficerId || ''); setRecoveryOfficerId(loan.loanDetail?.recoveryOfficerId || '')
    setEditing(true)
  }
  const save = async () => {
    setBusy(true)
    try {
      await loansApi.updateOfficers(loan.id, { loanOfficerId: loanOfficerId || null, recoveryOfficerId: recoveryOfficerId || null })
      setEditing(false); onChanged(); notify.success('Officers updated')
    } catch (ex: any) { notify.error('Update failed', getErrorText(ex)) } finally { setBusy(false) }
  }

  return (
    <Card>
      <div className="row between" style={{ marginBottom: 12 }}>
        <div className="eyebrow">Recovery Details</div>
        <Can user={me} module="Active Loans" action="Edit"><Btn size="sm" variant="solid" icon="edit" onClick={openEdit}>Assign Officers</Btn></Can>
      </div>
      <KpiGrid items={[
        { label: 'Follow Up Officer', value: loan.loanDetail?.recoveryOfficer?.name || '—', icon: 'users', tone: 'blue' },
        { label: 'Marketing Executive', value: loan.loanDetail?.loanOfficer?.name || '—', icon: 'users', tone: 'blue' },
        {
          label: 'Last Payment', icon: 'receipt', tone: 'green',
          value: r?.lastPaymentDate ? <>{money(r.lastPaymentAmount)}<div className="t-2" style={{ fontSize: 11, fontWeight: 400 }}>{dateOnly(r.lastPaymentDate)}</div></> : '—',
        },
        { label: 'Fallen Due Rentals', value: (r?.fallenDueRentals ?? 0).toFixed(2), icon: 'alert', tone: (r?.fallenDueRentals ?? 0) > 0 ? 'red' : 'green' },
        { label: 'Settled Rentals', value: (r?.settledRentals ?? 0).toFixed(2), icon: 'check', tone: 'green' },
        { label: 'Outstanding Rentals', value: (r?.outstandingRentals ?? 0).toFixed(2), icon: 'clock', tone: (r?.outstandingRentals ?? 0) > 0 ? 'amber' : 'green' },
        { label: 'Future Rentals', value: (r?.futureRentals ?? 0).toFixed(2), icon: 'calendar', tone: 'neutral' },
      ]} />

      <Modal open={editing} onClose={() => setEditing(false)} title="Assign Officers" footer={<>
        <Btn variant="solid" onClick={() => setEditing(false)}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save'}</Btn>
      </>}>
        <div className="col gap-3">
          <Field label="Marketing Executive"><Select value={loanOfficerId} onChange={(e) => setLoanOfficerId(Number(e.target.value) || '')}>
            <option value="">—</option>{officers.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
          </Select></Field>
          <Field label="Follow Up / Recovery Officer"><Select value={recoveryOfficerId} onChange={(e) => setRecoveryOfficerId(Number(e.target.value) || '')}>
            <option value="">—</option>{officers.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
          </Select></Field>
        </div>
      </Modal>
    </Card>
  )
}

/* ---------- Rental Schedule ---------- */
function ScheduleTable({ loan }: { loan: Loan }) {
  const schedules = loan.schedules || []
  return (
    <Card pad={0}>
      <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Rental Schedule</div>
      <Table cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Principal' }, { label: 'Interest' }, { label: 'Total' }, { label: 'Paid' }, { label: 'Balance' }, { label: 'Status', align: 'center' }]}
        rows={schedules} empty="No schedule generated"
        render={(r: LoanSchedule) => <>
          <Td>{r.installmentNo}</Td>
          <Td>{dateOnly(r.dueDate)}</Td>
          <Td mono>{money(r.principalDue)}</Td>
          <Td mono>{money(r.interestDue)}</Td>
          <Td mono>{money(r.totalDue)}</Td>
          <Td mono>{money(r.paidAmount)}</Td>
          <Td mono>{money(num(r.totalDue) - num(r.paidAmount))}</Td>
          <Td align="center"><Badge tone={scheduleTone[r.status]} dot>{r.status}</Badge></Td>
        </>} />
    </Card>
  )
}

/* ---------- Contract Receipts / Cancelled Receipts (read-only) ---------- */
function ReceiptsPanel({ loan, me }: { loan: Loan; me?: AuthUser | null }) {
  const all = loan.payments || []
  const pending = all.filter((p) => p.approvalStatus === 'PendingApproval')
  const pendingCheque = all.filter((p) => p.paymentMethod === 'Cheque' && p.clearanceStatus === 'Pending' && p.approvalStatus !== 'PendingApproval')
  const active = all.filter((p) => p.status !== 'Voided' && p.approvalStatus !== 'PendingApproval' && !(p.paymentMethod === 'Cheque' && p.clearanceStatus === 'Pending'))
  const voided = all.filter((p) => p.status === 'Voided')
  const returnedCheques = (loan.postDatedCheques || []).filter((c) => c.status === 'Bounced' && !(c as any).facilityPaymentId)
  const [viewReceipt, setViewReceipt] = useState<LoanPayment | null>(null)

  return (
    <>
      <Card style={{ marginBottom: 16 }}>
        <div className="row between" style={{ marginBottom: 12 }}>
          <div className="eyebrow">Contract Receipts</div>
          <Can user={me} module="Loan Payments" action="Create">
            <span className="t-2" style={{ fontSize: 11.5 }}>Record payments on the Payment Receipt screen.</span>
          </Can>
        </div>
        {pending.length > 0 && (
          <div className="col gap-2" style={{ marginBottom: 16 }}>
            {pending.map((p) => (
              <div key={p.id} className="row between wrap" style={{ padding: '8px 12px', background: 'var(--warn-dim)', border: '1px solid var(--warn)', borderRadius: 'var(--r-s)' }}>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <Icon n="clock" s={15} c="var(--warn)" />
                  <span style={{ fontSize: 12.5, fontWeight: 600 }}>{p.code} · Full Settlement</span>
                  <span className="t-2" style={{ fontSize: 12 }}>requested {dateOnly(p.createdAt || p.paidAt)} by {p.receiver?.name} · not yet applied to the schedule</span>
                </div>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(p.amount)}</span>
                  <Badge tone="amber" dot>Pending Approval</Badge>
                </div>
              </div>
            ))}
            <div className="t-2" style={{ fontSize: 11.5 }}>Approve or reject this on the Payment Receipt screen.</div>
          </div>
        )}
        {pendingCheque.length > 0 && (
          <div className="col gap-2" style={{ marginBottom: 16 }}>
            {pendingCheque.map((p) => (
              <div key={p.id} className="row between wrap" style={{ padding: '8px 12px', background: 'var(--warn-dim)', border: '1px solid var(--warn)', borderRadius: 'var(--r-s)' }}>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <Icon n="cheque" s={15} c="var(--warn)" />
                  <span style={{ fontSize: 12.5, fontWeight: 600 }}>{p.code} · Cheque</span>
                  <span className="t-2" style={{ fontSize: 12 }}>{dateOnly(p.paidAt)} · {p.bank?.name}{p.receiptNo ? ` · #${p.receiptNo}` : ''} · awaiting clearance, not yet applied to the schedule</span>
                </div>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(p.amount)}</span>
                  <Badge tone="amber" dot>Pending Clearance</Badge>
                </div>
              </div>
            ))}
            <div className="t-2" style={{ fontSize: 11.5 }}>Realize or reject on the Loan Adjustments screen (admin only).</div>
          </div>
        )}
        {active.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No receipts recorded yet.</div> :
          <div className="col gap-2">
            {active.map((p) => (
              <div key={p.id} className="row between wrap" role="button" tabIndex={0} title="View receipt and how it was allocated"
                onClick={() => setViewReceipt(p)} onKeyDown={(e) => { if (e.key === 'Enter') setViewReceipt(p) }}
                style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', cursor: 'pointer' }}>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <Icon n="receipt" s={15} />
                  <span style={{ fontSize: 12.5, fontWeight: 600 }}>{p.code}</span>
                  <span className="t-2" style={{ fontSize: 12 }}>{dateOnly(p.paidAt)} · {p.paymentMethod}{p.receiptNo ? ` · #${p.receiptNo}` : ''} · {p.receiver?.name}{p.clearanceStatus === 'Realized' ? ` · cheque realized ${dateOnly(p.realizedAt)}` : ''}</span>
                </div>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(p.amount)}</span>
                  {p.pendingVoidRequest && <Badge tone="amber" dot>Cancel Pending</Badge>}
                </div>
              </div>
            ))}
          </div>}
      </Card>

      <Card>
        <div className="eyebrow" style={{ marginBottom: 12 }}>Cancelled / Returned Receipts</div>
        {voided.length === 0 && returnedCheques.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No cancelled or returned receipts.</div> :
          <div className="col gap-2">
            {returnedCheques.map((c) => (
              <div key={`pdc-${c.id}`} className="row between wrap" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', opacity: 0.7 }}>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <Icon n="x" s={15} c="var(--bad)" />
                  <span style={{ fontSize: 12.5, fontWeight: 600, textDecoration: 'line-through' }}>Cheque #{c.chequeNo}</span>
                  <span className="t-2" style={{ fontSize: 12 }}>{dateOnly(c.chequeDate)}{c.bank?.name ? ` · ${c.bank.name}` : ''} · returned before clearing, not applied</span>
                </div>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(c.amount)}</span>
                  <Badge tone="red" dot>Cheque Returned</Badge>
                </div>
              </div>
            ))}
            {voided.map((p) => (
              <div key={p.id} className="row between wrap" role="button" tabIndex={0} title="View receipt and how it was allocated"
                onClick={() => setViewReceipt(p)} onKeyDown={(e) => { if (e.key === 'Enter') setViewReceipt(p) }}
                style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', opacity: 0.7, cursor: 'pointer' }}>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <Icon n="x" s={15} c="var(--bad)" />
                  <span style={{ fontSize: 12.5, fontWeight: 600, textDecoration: 'line-through' }}>{p.code}</span>
                  <span className="t-2" style={{ fontSize: 12 }}>{dateOnly(p.paidAt)} · voided {dateOnly(p.voidedAt)} by {p.voider?.name}{p.voidReason ? ` · ${p.voidReason}` : ''}</span>
                </div>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(p.amount)}</span>
                  <Badge tone="red" dot>{p.clearanceStatus === 'Bounced' ? 'Cheque Returned' : 'Cancelled'}</Badge>
                </div>
              </div>
            ))}
          </div>}
      </Card>

      <Modal open={!!viewReceipt} onClose={() => setViewReceipt(null)} title={viewReceipt ? `Receipt ${viewReceipt.code}` : ''} sub={`${loan.code} — ${loan.customer?.fullName || ''}`}
        footer={<Btn variant="solid" onClick={() => setViewReceipt(null)}>Close</Btn>}>
        {viewReceipt && (() => {
          const p = viewReceipt
          return (
            <div className="col gap-2" style={{ fontSize: 13 }}>
              <div className="row between"><span className="t-2">Date</span><b>{dateOnly(p.paidAt)}</b></div>
              <div className="row between"><span className="t-2">Status</span>
                {p.status === 'Voided' ? <Badge tone="red" dot>{p.clearanceStatus === 'Bounced' ? 'Cheque Returned' : 'Cancelled'}</Badge> : <Badge tone="green" dot>{p.paymentType === 'Settlement' ? 'Settlement' : 'Applied'}</Badge>}
              </div>
              <div className="row between" style={{ paddingTop: 8, borderTop: '1px solid var(--line)' }}>
                <span className="t-2">Amount</span><span style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(p.amount)}</span>
              </div>
              <div className="row between"><span className="t-2">Payment Method</span><b>{p.paymentMethod}{p.paymentType === 'Settlement' ? ' (Settlement)' : ''}</b></div>
              {p.receiptNo && <div className="row between"><span className="t-2">Reference No.</span><b>{p.receiptNo}</b></div>}
              {p.paymentMethod === 'Cheque' && p.bank?.name && <div className="row between"><span className="t-2">Bank</span><b>{p.bank.name}</b></div>}
              {p.paymentMethod === 'Cheque' && p.chequeDate && <div className="row between"><span className="t-2">Cheque Date</span><b>{dateOnly(p.chequeDate)}</b></div>}
              {p.clearanceStatus === 'Realized' && p.realizedAt && <div className="row between"><span className="t-2">Realized On</span><b>{dateOnly(p.realizedAt)}</b></div>}
              {p.notes && <div className="row between"><span className="t-2">Notes</span><b>{p.notes}</b></div>}
              <div className="row between" style={{ paddingTop: 8, borderTop: '1px solid var(--line)' }}><span className="t-2">Received By</span><b>{p.receiver?.name || '—'}</b></div>
              {p.status === 'Voided' && (
                <div className="col gap-1" style={{ marginTop: 4, padding: '8px 12px', background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
                  <div>Voided {dateOnly(p.voidedAt)} by {p.voider?.name || '—'}</div>
                  {p.voidReason && <div>Reason: {p.voidReason}</div>}
                </div>
              )}
              <div style={{ marginTop: 8, paddingTop: 10, borderTop: '1px solid var(--line)' }}><PaymentBreakdown paymentId={p.id} /></div>
            </div>
          )
        })()}
      </Modal>
    </>
  )
}

/* ---------- Security & Collateral ---------- */
function SecurityPanel({ loan }: { loan: Loan }) {
  const notify = useNotify()
  const preview = useFilePreview()
  const hasReference = !!(loan.loanDetail?.referenceName || loan.loanDetail?.referenceIdNo || loan.loanDetail?.referenceAddress)
  const viewDoc = async (collateralId: number, doc: LoanCollateralDocument) => {
    try { preview.open(await loanCollateralDocumentsApi.download(loan.id, collateralId, doc.id), doc.filePath) }
    catch (ex: any) { notify.error('Could not open attachment', getErrorText(ex)) }
  }
  const insurance = loan.insurance
  return (
    <Card>
      <div className="eyebrow" style={{ marginBottom: 12 }}>Security, Collateral & Equipment Details</div>
      {!loan.coApplicants?.length && !loan.guarantors?.length && !loan.collateral?.length && !hasReference && !loan.loanDetail?.vehicleNo && !insurance && <div className="t-2" style={{ fontSize: 12.5 }}>No co-applicants, guarantors, collateral or reference person recorded.</div>}
      {!!loan.coApplicants?.length && (<>
        <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 6 }}>Co-Applicants</div>
        {loan.coApplicants.map((ca, i) => (
          <div key={i} className="row between" style={{ padding: '6px 0', borderBottom: '1px solid var(--line-soft)', fontSize: 12.5 }}>
            <span>{ca.coApplicant?.fullName}</span><span className="t-2">{ca.sharePercent != null ? `${ca.sharePercent}%` : '—'}</span>
          </div>
        ))}
      </>)}
      {!!loan.loanDetail?.vehicleNo && (
        <div style={{ marginBottom: 14 }}>
          <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 6 }}>Vehicle</div>
          <div style={{ fontSize: 12.5 }}><span className="t-2">Vehicle No: </span><b>{loan.loanDetail.vehicleNo}</b></div>
        </div>
      )}
      {!!insurance && (
        <div style={{ marginBottom: 14 }}>
          <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 6 }}>Insurance Policy</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '6px 20px', fontSize: 12.5 }}>
            <div><span className="t-2">Company: </span>{insurance.insuranceCompany || '—'}</div>
            <div><span className="t-2">Policy No: </span>{insurance.policyNo || '—'}</div>
            <div><span className="t-2">Amount: </span>{insurance.amount != null ? money(insurance.amount) : '—'}</div>
            <div><span className="t-2">Contact Person: </span>{insurance.contactPerson || '—'}</div>
            <div><span className="t-2">Coverage: </span>{insurance.coverage || '—'}</div>
            {insurance.remarks && <div style={{ gridColumn: '1/-1' }}><span className="t-2">Remark: </span>{insurance.remarks}</div>}
          </div>
        </div>
      )}
      {hasReference && (<>
        <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 6 }}>Reference Person</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '6px 20px', fontSize: 12.5, marginBottom: 14 }}>
          <div><span className="t-2">Name: </span>{loan.loanDetail?.referenceName || '—'}</div>
          <div><span className="t-2">NIC / Other ID No: </span>{loan.loanDetail?.referenceIdNo || '—'}</div>
          <div><span className="t-2">Address: </span>{loan.loanDetail?.referenceAddress || '—'}</div>
        </div>
      </>)}
      {!!loan.guarantors?.length && (<>
        <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 6 }}>Guarantors</div>
        {loan.guarantors.map((g, i) => (
          <div key={i} className="row between" style={{ padding: '6px 0', borderBottom: '1px solid var(--line-soft)', fontSize: 12.5 }}>
            <span>{g.guarantor?.fullName}</span><span className="t-2">{money(g.guaranteedAmount)}</span>
          </div>
        ))}
      </>)}
      {!!loan.collateral?.length && (<>
        <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', margin: '14px 0 6px' }}>Collateral / Equipment</div>
        {loan.collateral.map((c, i) => {
          const v = c.vehicleDetail
          const vehicleBits = [
            v?.vehicleType, v?.makeModel,
            v?.registrationNo && `Reg: ${v.registrationNo}`,
            v?.chassisNo && `Chassis: ${v.chassisNo}`,
            v?.engineNo && `Engine: ${v.engineNo}`,
            v?.yearOfManufacture && `Yr: ${v.yearOfManufacture}`,
          ].filter(Boolean)
          return (
            <div key={i} style={{ padding: '6px 0', borderBottom: '1px solid var(--line-soft)' }}>
              <div className="row between" style={{ fontSize: 12.5 }}>
                <span>{c.collateralType} — {c.description}</span><span className="t-2">{c.estimatedValue ? money(c.estimatedValue) : '—'}</span>
              </div>
              {c.fdDetail && (
                <div className="row gap-2 wrap" style={{ marginTop: 4, fontSize: 11.5, alignItems: 'center' }}>
                  <Badge tone={c.fdDetail.lienStatus === 'Active' ? 'red' : 'neutral'} dot>Lien {c.fdDetail.lienStatus}</Badge>
                  <span className="t-2">
                    FD {c.fdDetail.fixedDeposit?.fdNumber} · principal {money(c.fdDetail.fdPrincipalSnapshot)} @ {pct(c.fdDetail.fdRateSnapshot)}% · matures {dateOnly(c.fdDetail.fdMaturitySnapshot)} · max loan {money(c.fdDetail.maxLoanValue)} ({pct(c.fdDetail.ltvPercent)}%)
                    {c.fdDetail.releasedAt ? ` · ${c.fdDetail.lienStatus === 'Settled' ? 'settled' : 'released'} ${dateOnly(c.fdDetail.releasedAt)}${c.fdDetail.settledAmount ? ` (${money(c.fdDetail.settledAmount)} applied)` : ''}` : ''}
                  </span>
                </div>
              )}
              {!!vehicleBits.length && <div className="t-2" style={{ fontSize: 11.5, marginTop: 2 }}>{vehicleBits.join(' · ')}</div>}
              {!!c.documents?.length && (
                <div className="row gap-1 wrap" style={{ marginTop: 6 }}>
                  {c.documents.map((d) => (
                    <button key={d.id} className="mms-act" title="View attachment" onClick={() => viewDoc(c.id!, d)}
                      style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 8px', fontSize: 11, border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
                      <Icon n="doc" s={12} /> {d.filePath.split('/').pop()}
                    </button>
                  ))}
                </div>
              )}
            </div>
          )
        })}
      </>)}
      {preview.node}
    </Card>
  )
}

/* ---------- Post Dated Cheques ---------- */
function ChequesPanel({ loan, me, onChanged, notify }: { loan: Loan; me?: AuthUser | null; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const cheques = loan.postDatedCheques || []

  const remove = async (c: LoanPostDatedCheque) => {
    const ok = await notify.confirm({ title: `Delete cheque ${c.chequeNo}?`, danger: true, okText: 'Delete' })
    if (!ok) return
    try { await loanPostDatedChequesApi.remove(c.id); notify.success('Cheque deleted'); onChanged() }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <Card pad={0}>
      <div className="row between" style={{ padding: '14px 16px 0' }}>
        <div className="eyebrow">Post Dated Cheques</div>
        <span className="t-2" style={{ fontSize: 11.5 }}>Record a cheque on the Payment Receipt screen · realize or reject on Loan Adjustments (admin only).</span>
      </div>
      <Table cols={[{ label: 'Cheque No' }, { label: 'Bank' }, { label: 'Cheque Date' }, { label: 'Realized' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 40 }]}
        rows={cheques} empty="No post dated cheques"
        render={(c: LoanPostDatedCheque) => <>
          <Td mono>{c.chequeNo}</Td>
          <Td>{c.bank?.name || '—'}</Td>
          <Td>{dateOnly(c.chequeDate)}</Td>
          <Td>{c.status === 'Realized' ? dateOnly(c.realizedAt) : '—'}</Td>
          <Td mono align="right">{money(c.amount)}</Td>
          <Td align="center"><Badge tone={chequeTone[c.status]} dot>{c.status === 'Bounced' ? 'Returned' : c.status}</Badge></Td>
          <Td align="right">
            {c.status !== 'Realized' && (
              <Can user={me} module="Loan Payments" action="Delete">
                <button className="mms-act" title="Delete" onClick={() => remove(c)}><Icon n="trash" s={14} /></button>
              </Can>
            )}
          </Td>
        </>} />
    </Card>
  )
}

/* ---------- Ledger Card (computed, client-side) ---------- */
function LedgerCard({ loan }: { loan: Loan }) {
  type Row = { date: string; particulars: string; debit: number; credit: number; typeOrder: number; tone?: 'bad' | 'warn' }
  const rows: Row[] = []

  // 1. Charges (Debits)
  // Only the due side is logged here — a charge's paid_amount always comes from
  // a real LoanPayment (see LoanPaymentAllocator::applyAllocation), which is
  // already its own Receipt row below. Adding a second "charge paid" row here
  // would credit that same money twice and understate the running balance.
  for (const c of loan.charges || []) rows.push({ date: c.chargedAt, particulars: `${c.category?.name || 'Category'} charge`, debit: num(c.dueAmount), credit: 0, typeOrder: 1 })
  
  // 2. Schedules (Debits)
  for (const s of loan.schedules || []) rows.push({ date: s.dueDate, particulars: `Installment #${s.installmentNo} due`, debit: num(s.totalDue), credit: 0, typeOrder: 2 })

  // 2b. Default Charges / OD (Debits) — not a column on the schedule itself
  // (penalty is computed live, never persisted — see LoanSummary), so it's
  // posted here from loan.summary.schedulePenalties instead. A receipt's
  // credit already includes whatever penalty it collected, so without this
  // row the ledger balance understates arrears by the penalty amount.
  for (const p of loan.summary?.schedulePenalties || []) rows.push({ date: p.dueDate, particulars: `Default charge (OD) — Installment #${p.installmentNo}`, debit: num(p.due), credit: 0, typeOrder: 2 })

  // 3. Waivers (Credits)
  for (const w of loan.waivers || []) rows.push({ date: w.waivedAt, particulars: `${w.waiverType} waived${w.reason ? ` — ${w.reason}` : ''}`, debit: 0, credit: num(w.amount), typeOrder: 3 })
  
  // 4. Payments (Credits)
  for (const p of loan.payments || []) {
    if (p.approvalStatus === 'PendingApproval') continue
    if (p.status === 'Voided') {
      // Show the receipt and its reversal so a bounced/cancelled payment is
      // visible here (nets to zero) instead of silently missing.
      const bounced = p.clearanceStatus === 'Bounced'
      rows.push({ date: p.paidAt, particulars: `Receipt ${p.code} (${p.paymentMethod})`, debit: 0, credit: num(p.amount), typeOrder: 4, tone: 'bad' })
      rows.push({ date: p.voidedAt || p.paidAt, particulars: `${bounced ? 'Cheque RETURNED' : 'Receipt CANCELLED'} — ${p.code}${p.receiptNo ? ` #${p.receiptNo}` : ''}${p.voidReason && !bounced ? ` — ${p.voidReason}` : ''}`, debit: num(p.amount), credit: 0, typeOrder: 5, tone: 'bad' })
      continue
    }
    if (p.paymentMethod === 'Cheque' && p.clearanceStatus === 'Pending') {
      rows.push({ date: p.paidAt, particulars: `Receipt ${p.code} (Cheque${p.receiptNo ? ` #${p.receiptNo}` : ''}) — pending clearance, ${money(p.amount)} not applied`, debit: 0, credit: 0, typeOrder: 4, tone: 'warn' })
      continue
    }
    rows.push({ date: p.paidAt, particulars: `Receipt ${p.code} (${p.paymentMethod})`, debit: 0, credit: num(p.amount), typeOrder: 4 })
  }

  // Post-dated cheques returned before they ever cleared have no payment behind them, so nothing
  // was credited — listed for information only (0 debit / 0 credit).
  for (const c of loan.postDatedCheques || []) {
    if (c.status !== 'Bounced' || (c as any).facilityPaymentId) continue
    rows.push({ date: ((c as any).updatedAt as string) || c.chequeDate, particulars: `Cheque RETURNED — #${c.chequeNo}${c.bank?.name ? ` (${c.bank.name})` : ''} — ${money(c.amount)} not applied`, debit: 0, credit: 0, typeOrder: 5, tone: 'bad' })
  }

  rows.sort((a, b) => {
    const dCmp = a.date.substring(0, 10).localeCompare(b.date.substring(0, 10))
    if (dCmp !== 0) return dCmp
    return a.typeOrder - b.typeOrder
  })
  let balance = 0
  const withBalance = rows.map((r) => { balance += r.debit - r.credit; return { ...r, balance } })

  return (
    <Card pad={0}>
      <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Ledger Card</div>
      <Table cols={[{ label: 'Date' }, { label: 'Particulars' }, { label: 'Debit', align: 'right' }, { label: 'Credit', align: 'right' }, { label: 'Balance', align: 'right' }]}
        rows={withBalance} empty="No ledger entries yet"
        render={(r) => <>
          <Td>{dateOnly(r.date)}</Td>
          <Td><span style={r.tone ? { color: r.tone === 'bad' ? 'var(--bad)' : 'var(--warn)' } : undefined}>{r.particulars}</span></Td>
          <Td mono align="right">{r.debit ? money(r.debit) : '—'}</Td>
          <Td mono align="right">{r.credit ? money(r.credit) : '—'}</Td>
          <Td mono align="right">{money(r.balance)}</Td>
        </>} />
    </Card>
  )
}

/* ---------- Diarizing ---------- */
function DiaryPanel({ loan, me, onChanged, notify }: { loan: Loan; me?: AuthUser | null; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const notes = loan.diaryNotes || []
  const [note, setNote] = useState('')
  const [followUpDate, setFollowUpDate] = useState('')
  const [busy, setBusy] = useState(false)

  const add = async () => {
    if (!note.trim()) return
    setBusy(true)
    try {
      await loanDiaryNotesApi.create(loan.id, { note: note.trim(), followUpDate: followUpDate || undefined })
      setNote(''); setFollowUpDate(''); notify.success('Note added'); onChanged()
    } catch (ex: any) { notify.error('Add failed', getErrorText(ex)) } finally { setBusy(false) }
  }
  const remove = async (n: LoanDiaryNote) => {
    const ok = await notify.confirm({ title: 'Delete this note?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await loanDiaryNotesApi.remove(n.id); notify.success('Note deleted'); onChanged() }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <Card>
      <div className="eyebrow" style={{ marginBottom: 12 }}>Diarizing</div>
      <Can user={me} module="Active Loans" action="Create">
        <div className="row gap-2 wrap" style={{ marginBottom: 14, alignItems: 'flex-start' }}>
          <textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="Follow-up note…" rows={2} style={{ ...inputStyle, resize: 'vertical', flex: 2, minWidth: 200 }} />
          <Input type="date" value={followUpDate} onChange={(e) => setFollowUpDate(e.target.value)} style={{ maxWidth: 160 }} />
          <Btn variant="primary" size="sm" icon="plus" onClick={add} disabled={busy}>Add</Btn>
        </div>
      </Can>
      {notes.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No diary notes yet.</div> :
        <div className="col gap-2">
          {notes.map((n) => (
            <div key={n.id} className="row between wrap" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
              <div>
                <div style={{ fontSize: 12.5 }}>{n.note}</div>
                <div className="t-2" style={{ fontSize: 11 }}>{n.creator?.name} · {dateOnly(n.createdAt)}{n.followUpDate ? ` · follow up ${dateOnly(n.followUpDate)}` : ''}</div>
              </div>
              <Can user={me} module="Active Loans" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(n)}><Icon n="trash" s={14} /></button></Can>
            </div>
          ))}
        </div>}
    </Card>
  )
}

/* ---------- Waivers (W-Off Set Plan / Waved Off Details) — read-only; recorded on Loan Adjustments (admin) ---------- */
function WaiversPanel({ loan }: { loan: Loan }) {
  const waivers = loan.waivers || []

  return (
    <Card>
      <div className="row between" style={{ marginBottom: 12 }}>
        <div className="eyebrow">Waivers (W-Off Set Plan / Waved Off Details)</div>
        <span className="t-2" style={{ fontSize: 11.5 }}>Recorded on the Loan Adjustments screen (admin only).</span>
      </div>
      {waivers.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No waivers recorded.</div> :
        <div className="col gap-2">
          {waivers.map((w) => (
            <div key={w.id} className="row between wrap" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
              <div>
                <span style={{ fontSize: 12.5, fontWeight: 600 }}>{w.waiverType}</span>
                <span className="t-2" style={{ fontSize: 12 }}> — {w.reason || 'no reason given'} · {dateOnly(w.waivedAt)} · approved by {w.approver?.name}</span>
              </div>
              <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(w.amount)}</span>
            </div>
          ))}
        </div>}
    </Card>
  )
}

/* ---------- Contract Images ---------- */
function ImagesPanel({ loan, me }: { loan: Loan; me?: AuthUser | null }) {
  const notify = useNotify()
  const [docs, setDocs] = useState<LoanDocument[]>(loan.documents || [])
  const [categories, setCategories] = useState<DocumentCategory[]>([])
  const [docCategoryId, setDocCategoryId] = useState<number | ''>('')
  const [uploading, setUploading] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)
  const preview = useFilePreview()

  useEffect(() => setDocs(loan.documents || []), [loan])
  useEffect(() => { documentCategoriesApi.list().then((res) => setCategories(res.filter(c => c.status === 'Active'))) }, [])

  const refresh = () => loanDocumentsApi.list(loan.id).then(setDocs).catch(() => {})

  const pick = async (e: React.ChangeEvent<HTMLInputElement>) => {
    if (!docCategoryId) { notify.error('Please select a document category'); e.target.value = ''; return }
    const files = Array.from(e.target.files || [])
    e.target.value = ''
    if (!files.length) return
    setUploading(true)
    // api layer downscales + re-encodes raster images to WebP before upload (see compressImageToWebp).
    let ok = 0
    const failed: string[] = []
    for (const file of files) {
      try { await loanDocumentsApi.upload(loan.id, file, docCategoryId); ok++ }
      catch (ex: any) { failed.push(`${file.name}: ${getErrorText(ex)}`) }
    }
    refresh()
    if (ok) notify.success(ok === 1 ? 'Image uploaded' : `${ok} images uploaded`)
    if (failed.length) notify.error(`${failed.length} upload(s) failed`, failed.join('\n'))
    setUploading(false)
  }
  const view = async (d: LoanDocument) => {
    try { preview.open(await loanDocumentsApi.download(loan.id, d.id), d.filePath, d.category?.name || 'Document') }
    catch (ex: any) { notify.error('Could not open document', getErrorText(ex)) }
  }
  const remove = async (d: LoanDocument) => {
    const ok = await notify.confirm({ title: 'Delete this document?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await loanDocumentsApi.remove(loan.id, d.id); refresh() }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <Card>
      <div className="eyebrow" style={{ marginBottom: 12 }}>Contract Images</div>
      <Can user={me} module="Active Loans" action="Create">
        <div className="row gap-2" style={{ marginBottom: 12 }}>
          <Select value={docCategoryId} onChange={(e) => setDocCategoryId(Number(e.target.value) || '')} style={{ maxWidth: 180 }}>
            <option value="" disabled>Select category...</option>
            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Select>
          <input ref={fileInput} type="file" accept="image/png,image/jpeg,image/webp,application/pdf" multiple onChange={pick} style={{ display: 'none' }} />
          <Btn variant="solid" icon="upload" size="sm" onClick={() => fileInput.current?.click()} disabled={uploading || !docCategoryId}>{uploading ? 'Uploading…' : 'Upload'}</Btn>
        </div>
      </Can>
      {docs.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No images uploaded yet.</div> :
        <div className="col gap-2">
          {docs.map((d) => (
            <div key={d.id} className="row between" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
              <div className="row gap-2" style={{ alignItems: 'center' }}>
                <Icon n="doc" s={16} />
                <span style={{ fontSize: 12.5 }}>{d.category?.name || 'Document'}</span>
              </div>
              <div className="row gap-1">
                <button className="mms-act" title="View" onClick={() => view(d)}><Icon n="eye" s={15} /></button>
                <Can user={me} module="Active Loans" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(d)}><Icon n="trash" s={15} /></button></Can>
              </div>
            </div>
          ))}
        </div>}
      {preview.node}
    </Card>
  )
}

/* ---------- Contract Status Change ---------- */
function StatusFlagsModal({ open, onClose, loan, onChanged, notify }: {
  open: boolean; onClose: () => void; loan: Loan; onChanged: () => void; notify: ReturnType<typeof useNotify>
}) {
  // Each checkbox here records a NEW FacilityStatusEvent row when checked and
  // saved — it's a one-shot "log this occurrence now" action, not a toggle
  // reflecting current state (the backend keeps an append-only history, not a
  // boolean flag — see the badges/history list below for what's already recorded).
  // So these always start unchecked, regardless of past events.
  const [isTerminated, setIsTerminated] = useState(false)
  const [isLegal, setIsLegal] = useState(false)
  const [seizingOrderIssued, setSeizingOrderIssued] = useState(false)
  const [isRepossessed, setIsRepossessed] = useState(false)
  const [freezeDate, setFreezeDate] = useState(loan.loanDetail?.freezeDate || '')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!open) return
    setIsTerminated(false); setIsLegal(false); setSeizingOrderIssued(false); setIsRepossessed(false)
    setFreezeDate(loan.loanDetail?.freezeDate || '')
  }, [open, loan])

  const save = async () => {
    setBusy(true)
    try {
      await loansApi.updateStatusFlags(loan.id, { isTerminated, isLegal, seizingOrderIssued, isRepossessed, freezeDate: freezeDate || undefined })
      notify.success('Contract status updated'); onChanged(); onClose()
    } catch (ex: any) { notify.error('Update failed', getErrorText(ex)) } finally { setBusy(false) }
  }

  const row = (label: string, checked: boolean, set: (v: boolean) => void) => (
    <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer' }}>
      <input type="checkbox" checked={checked} onChange={(e) => set(e.target.checked)} />
      <span style={{ fontSize: 13 }}>{label}</span>
    </label>
  )

  const history = loan.statusEvents || []

  return (
    <Modal open={open} onClose={onClose} title="Contract Status Change" sub={loan.code} footer={<>
      <Btn variant="solid" onClick={onClose}>Cancel</Btn>
      <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save'}</Btn>
    </>}>
      <div className="col gap-3">
        <div className="t-2" style={{ fontSize: 11.5 }}>Checking a box below logs a new occurrence now — it doesn't toggle an existing one off.</div>
        {row('Terminated', isTerminated, setIsTerminated)}
        {row('Legal', isLegal, setIsLegal)}
        {row('Seizing Order Issued', seizingOrderIssued, setSeizingOrderIssued)}
        {row('Repossessed', isRepossessed, setIsRepossessed)}
        <Field label="Freeze Date"><Input type="date" value={freezeDate} onChange={(e) => setFreezeDate(e.target.value)} /></Field>
        {!!history.length && (
          <div>
            <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 6 }}>History</div>
            <div className="col gap-1">
              {history.map((e) => (
                <div key={e.id} className="row between" style={{ fontSize: 12 }}>
                  <span>{e.eventType}{e.notes ? ` — ${e.notes}` : ''}</span>
                  <span className="t-2">{dateOnly(e.eventDate)}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </Modal>
  )
}

/* ---------- Disburse (Approved -> Active) ---------- */
export function DisburseForm({ loan, me, onChanged, notify }: { loan: Loan; me?: AuthUser | null; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const financedAmount = num(loan.principalAmount) - num(loan.loanDetail?.downPayment)
  const [amount, setAmount] = useState<string>(String(financedAmount))
  const [disbursedAt, setDisbursedAt] = useState(loan.disbursementIncomplete && loan.disbursedAt ? loan.disbursedAt.slice(0, 10) : todayIso())
  const [firstDate, setFirstDate] = useState('')
  // Loans are paid out only from a Head Office bank account: no cash, no other branch's account.
  const [method, setMethod] = useState<'Bank' | 'Cheque'>('Bank')
  const [bankAccountId, setBankAccountId] = useState<number | ''>('')
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])
  // The customer account the money is sent to - can be added / corrected right here.
  const [payoutId, setPayoutId] = useState<number | undefined>(loan.customerBankDetailId ?? undefined)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    Promise.all([bankAccountsApi.list(), branchesApi.list()]).then(([banks, branches]) => {
      // Keyed by String(id) — some hosts return integer/boolean columns as JSON strings, which
      // breaks strict comparisons (same issue as loanTypePath in LoanApplications.tsx).
      const isHo = (v: unknown) => v === true || v === 1 || v === '1' || v === 'true'
      const hoIds = new Set(branches.filter((b) => isHo(b.isHeadOffice)).map((b) => String(b.id)))
      const hoBanks = banks.filter((a) => a.branchId != null && hoIds.has(String(a.branchId)) && a.status !== 'Inactive')
      setBankAccounts(hoBanks)
      const preferred = hoBanks.find((a) => /operation/i.test(a.name)) ?? (hoBanks.length === 1 ? hoBanks[0] : undefined)
      if (preferred) setBankAccountId(preferred.id)
    }).catch(() => setBankAccounts([]))
  }, [])

  const feePercent = num(loan.loanDetail?.loanType?.processingFeePercent)
  const appDocCharge = loan.loanDetail?.documentChargeAmount
  const computedDocCharge = appDocCharge != null && appDocCharge !== '' ? num(appDocCharge) : Math.round(num(amount) * feePercent) / 100
  const docCharge = loan.charges?.find((c) => c.category?.name === 'Document')
  const docBalance = docCharge ? Math.max(0, num(docCharge.dueAmount) - num(docCharge.paidAmount)) : 0
  const chargeRequired = feePercent > 0
  const chargeBlocking = chargeRequired && (!docCharge || docBalance > 0)

  const disburseBody = () => ({
    disbursedAmount: amount, disbursedAt, firstInstallmentDate: firstDate, disbursementMethod: method,
    disbursementBankAccountId: Number(bankAccountId),
  })
  const validateForm = () => {
    if (!firstDate) { setErr('Pick the first installment date.'); return false }
    if (chargeBlocking) { setErr('Settle the document charge before disbursing this loan.'); return false }
    if (!bankAccountId) { setErr('Pick which bank account this loan is paid out of.'); return false }
    if (!payoutId) { setErr("Select or add the customer's payment bank account."); return false }
    return true
  }
  const savePayout = async () => {
    if (payoutId && payoutId !== loan.customerBankDetailId) await loansApi.setPayoutAccount(loan.id, payoutId)
  }

  const disburse = async () => {
    if (!validateForm()) return
    setBusy(true); setErr('')
    try {
      await savePayout()
      await loansApi.disburse(loan.id, disburseBody())
      notify.success('Loan disbursed', loan.code)
      onChanged()
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Disbursement failed'))
    } finally { setBusy(false) }
  }

  const requestDisburse = async () => {
    if (!validateForm()) return
    setBusy(true); setErr('')
    try {
      await savePayout()
      await loansApi.requestDisbursement(loan.id, disburseBody())
      notify.success('Disbursement requested', 'Sent to Finance for approval and payment')
      onChanged()
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not send the request'))
    } finally { setBusy(false) }
  }

  const pendingCancel = loan.pendingCancelRequest
  const pendingDisb = loan.pendingDisbursementRequest

  const cancel = async () => {
    const reason = await notify.prompt({ title: `Request cancel for loan ${loan.code}?`, msg: 'Approved but not yet disbursed — give a reason. Needs a second person to approve before it takes effect.', placeholder: 'Reason for cancellation', required: true, danger: true, okText: 'Send Request' })
    if (!reason) return
    try { await loansApi.requestCancel(loan.id, reason); notify.success('Cancel request sent', 'Awaiting approval'); onChanged() }
    catch (ex: any) { notify.error('Request failed', getErrorText(ex)) }
  }

  const approveCancel = async () => {
    if (!pendingCancel) return
    try { await loansApi.approveCancelRequest(pendingCancel.id); notify.success('Approved'); onChanged() }
    catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
  }

  const rejectCancel = async () => {
    if (!pendingCancel) return
    const reason = await notify.prompt({ title: 'Reject this cancel request?', msg: 'Give a reason — the requester can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try { await loansApi.rejectCancelRequest(pendingCancel.id, reason || undefined); notify.success('Cancel request rejected'); onChanged() }
    catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }

  return (
    <Card>
      <div className="row between wrap gap-2" style={{ marginBottom: 12 }}>
        <div className="eyebrow">Disburse this loan to generate its repayment schedule</div>
        {!pendingCancel && (
          <Can user={me} module="Active Loans" action="Delete">
            <Btn size="sm" variant="danger" icon="x" onClick={cancel}>Cancel Loan</Btn>
          </Can>
        )}
      </div>
      {pendingCancel && (
        <div className="row between wrap gap-2" style={{ marginBottom: 14, padding: '10px 14px', background: 'var(--warn-dim)', border: '1px solid var(--warn)', borderRadius: 'var(--r-s)' }}>
          <div style={{ fontSize: 12.5 }}>
            <b>Cancel request pending</b> — {pendingCancel.requester?.name || '—'} · {pendingCancel.reason}
            <div className="t-2" style={{ fontSize: 11.5, marginTop: 2 }}>
              {pendingCancel.firstApprover ? `Approved by ${pendingCancel.firstApprover.name}, ` : ''}
              needs {pendingCancel.requiredApprovals - (pendingCancel.firstApprover ? 1 : 0)} more approval{pendingCancel.requiredApprovals - (pendingCancel.firstApprover ? 1 : 0) === 1 ? '' : 's'}
            </div>
          </div>
          {canApprove(me, 'loan_cancel') && me?.id !== pendingCancel.requester?.id && me?.id !== pendingCancel.firstApprover?.id && (
            <div className="row gap-2">
              <Btn size="sm" variant="primary" icon="check" onClick={approveCancel}>Approve</Btn>
              <Btn size="sm" variant="danger" icon="x" onClick={rejectCancel}>Reject</Btn>
            </div>
          )}
        </div>
      )}
      {loan.disbursementIncomplete && (
        <div style={{ marginBottom: 14, padding: '10px 14px', borderRadius: 8, border: '1px solid var(--warn)', background: 'var(--warn-dim)', color: 'var(--warn)', fontSize: 13 }}>
          The last disbursement for this loan did not finish (no repayment schedule was created). Review the details and disburse again; money is never paid out twice.
        </div>
      )}
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />

      {pendingDisb && (
        <div className="row between wrap gap-2" style={{ marginBottom: 14, padding: '10px 14px', background: 'var(--ac-dim)', border: '1px solid var(--ac-line)', borderRadius: 'var(--r-s)' }}>
          <div style={{ fontSize: 12.5 }}>
            <b>Disbursement requested</b> — {pendingDisb.code} · {pendingDisb.status.replace(/([A-Z])/g, ' $1').trim()}
            <div className="t-2" style={{ fontSize: 11.5, marginTop: 2 }}>
              {pendingDisb.requester?.name ? `Raised by ${pendingDisb.requester.name}. ` : ''}Finance will approve and release the funds — track it under Finance › Finance Requests.
            </div>
          </div>
        </div>
      )}

      <DocumentChargeSection loan={loan} me={me} docCharge={docCharge} docBalance={docBalance} computedAmount={computedDocCharge} feePercent={feePercent} onChanged={onChanged} notify={notify} />

      <div className="row wrap gap-4" style={{ marginBottom: 14, fontSize: 12.5 }}>
        <div><span className="t-2">Application Date: </span><b>{dateOnly(loan.applicationDate || loan.loanDetail?.applicationDate || loan.createdAt)}</b></div>
        <div><span className="t-2">Approved On: </span><b>{loan.approvedAt ? dateOnly(loan.approvedAt) : '—'}</b></div>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16, marginBottom: 16 }}>
        <Field label="Disbursed Amount"><Input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        <Field label="Disbursed Date"><Input type="date" value={disbursedAt} onChange={(e) => setDisbursedAt(e.target.value)} /></Field>
        <Field label="First Installment Date"><Input type="date" value={firstDate} onChange={(e) => setFirstDate(e.target.value)} /></Field>
        <Field label="Disbursement Method"><Select value={method} onChange={(e) => setMethod(e.target.value as 'Bank' | 'Cheque')}>
          <option value="Bank">Bank Transfer</option><option value="Cheque">Cheque</option>
        </Select></Field>
        <Field label="Paid Out Of (Head Office Bank Account)"><Select value={bankAccountId} onChange={(e) => setBankAccountId(Number(e.target.value) || '')}>
          <option value="">Select bank account…</option>
          {bankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.accountNumber}</option>)}
        </Select></Field>
      </div>
      {!pendingDisb && (
        <div style={{ marginBottom: 16 }}>
          <Field label="Customer Payment Bank Account (add or update if needed)">
            <CustomerBankDetailPicker customerId={loan.customerId} value={payoutId} onChange={setPayoutId} />
          </Field>
        </div>
      )}
      <div className="t-2" style={{ fontSize: 12, marginBottom: 16 }}>
        {loan.termMonths} months · {loan.loanDetail?.installmentFrequency} · {loan.loanDetail?.interestMethod === 'reducing_balance' ? 'Reducing balance' : 'Flat'} interest at {loan.interestRatePercent}%
        {num(loan.loanDetail?.downPayment) > 0 && <> · Down payment {money(loan.loanDetail?.downPayment)} already settled — schedule covers the remaining {money(financedAmount)}</>}
      </div>
      {!pendingDisb && (
        <div className="row gap-2 wrap" style={{ alignItems: 'center' }}>
          <Can user={me} module="Loan Disbursement" action="Create">
            <Btn variant="solid" icon="receipt" onClick={requestDisburse} disabled={busy || chargeBlocking || !!pendingCancel}>{busy ? 'Sending…' : 'Request Disbursement'}</Btn>
          </Can>
          <Can user={me} module="Loan Disbursement" action="Approve">
            <Btn variant="primary" icon="check" onClick={disburse} disabled={busy || chargeBlocking || !!pendingCancel}>{busy ? 'Disbursing…' : 'Disburse Now'}</Btn>
          </Can>
          {chargeBlocking && !pendingCancel && <span className="t-2" style={{ fontSize: 12 }}>{docCharge ? 'Settle' : 'Add and settle'} the document charge above first.</span>}
          {pendingCancel && <span className="t-2" style={{ fontSize: 12 }}>A cancel request is pending — resolve it before disbursing.</span>}
        </div>
      )}
    </Card>
  )
}

/* ---------- Document Charge gate — add/pay a Document charge before disbursing ---------- */
const DOC_CHARGE_METHODS: { value: PaymentMethod; label: string }[] = [
  { value: 'Cash', label: 'Cash' },
  { value: 'Bank', label: 'Bank Transfer' },
  { value: 'Cheque', label: 'Cheque' },
]

function DocumentChargeSection({ loan, me, docCharge, docBalance, computedAmount, feePercent, onChanged, notify }: {
  loan: Loan; me?: AuthUser | null; docCharge?: LoanCharge; docBalance: number; computedAmount: number; feePercent: number
  onChanged: () => void; notify: ReturnType<typeof useNotify>
}) {
  const [notes, setNotes] = useState('')
  const [addBusy, setAddBusy] = useState(false)
  const [addErr, setAddErr] = useState('')
  const [docCategoryId, setDocCategoryId] = useState<number | undefined>(undefined)

  useEffect(() => {
    chargeCategoriesApi.list().then((res) => {
      setDocCategoryId(res.find(c => c.name === 'Document')?.id)
    }).catch(() => {})
  }, [])

  const [method, setMethod] = useState<PaymentMethod>('Cash')
  const [paidAt, setPaidAt] = useState(todayIso())
  const [refNo, setRefNo] = useState('')
  const [bankId, setBankId] = useState<number | undefined>(undefined)
  const [chequeDate, setChequeDate] = useState(todayIso())
  const [nextReceipt, setNextReceipt] = useState('')
  const [payBusy, setPayBusy] = useState(false)
  const [payErr, setPayErr] = useState('')

  const paid = !!docCharge && docBalance <= 0

  useEffect(() => {
    if (!docCharge || method !== 'Cash' || paid) { setNextReceipt(''); return }
    let cancelled = false
    loanPaymentsApi.nextReceiptNo(loan.id, 'Cash').then((code) => { if (!cancelled) setNextReceipt(code) }).catch(() => { if (!cancelled) setNextReceipt('') })
    return () => { cancelled = true }
  }, [loan.id, docCharge, method, paid])

  const addCharge = async () => {
    if (computedAmount <= 0) { setAddErr('Enter the disbursed amount first.'); return }
    setAddBusy(true); setAddErr('')
    try {
      if (!docCategoryId) throw new Error('Document charge category not found.')
      await loanChargesApi.create(loan.id, { chargeCategoryId: docCategoryId, dueAmount: computedAmount, notes: notes || undefined })
      setNotes('')
      notify.success('Document charge added')
      onChanged()
    } catch (ex: any) {
      setAddErr(getErrorText(ex, 'Could not add the document charge.'))
    } finally { setAddBusy(false) }
  }

  const [dueEdit, setDueEdit] = useState('')
  const [updBusy, setUpdBusy] = useState(false)
  useEffect(() => { setDueEdit(docCharge ? String(num(docCharge.dueAmount)) : '') }, [docCharge?.id, docCharge?.dueAmount])

  const updateCharge = async () => {
    if (!docCharge) return
    if (!(num(dueEdit) > 0)) { setPayErr('Enter a charge amount greater than zero.'); return }
    setUpdBusy(true); setPayErr('')
    try {
      await loanChargesApi.update(docCharge.id, { dueAmount: dueEdit } as any)
      notify.success('Document charge updated')
      onChanged()
    } catch (ex: any) {
      setPayErr(getErrorText(ex, 'Could not update the document charge.'))
    } finally { setUpdBusy(false) }
  }

  const payCharge = async () => {
    if (method === 'Cheque' && (!bankId || !chequeDate)) { setPayErr('Bank and Cheque Date are required for a cheque payment.'); return }
    setPayBusy(true); setPayErr('')
    try {
      await loanPaymentsApi.create(loan.id, {
        amount: docBalance, paymentMethod: method, paidAt, receiptNo: refNo || undefined,
        bankId: method === 'Cheque' ? bankId : undefined,
        chequeDate: method === 'Cheque' ? chequeDate : undefined,
        notes: `Document charge${notes ? ` — ${notes}` : ''}`,
      })
      notify.success('Document charge paid')
      onChanged()
    } catch (ex: any) {
      setPayErr(getErrorText(ex, 'Payment failed'))
    } finally { setPayBusy(false) }
  }

  if (!docCharge) {
    if (feePercent <= 0) return null
    return (
      <Card style={{ marginBottom: 16, background: 'var(--bg-0)' }}>
        <div className="eyebrow" style={{ marginBottom: 4 }}>Document Charge — required before disbursing</div>
        <div className="t-2" style={{ fontSize: 12, marginBottom: 10 }}>{feePercent}% processing fee on the disbursed amount.</div>
        <ErrorBanner error={addErr} style={{ marginBottom: 10 }} />
        <div className="row gap-2 wrap" style={{ alignItems: 'flex-end' }}>
          <Field label="Amount"><Input type="number" value={computedAmount.toFixed(2)} readOnly disabled style={{ maxWidth: 140, color: 'var(--tx-2)' }} /></Field>
          <Field label="Notes"><Input value={notes} onChange={(e) => setNotes(e.target.value)} style={{ minWidth: 200 }} /></Field>
          <Can user={me} module="Active Loans" action="Create">
            <Btn variant="solid" icon="plus" onClick={addCharge} disabled={addBusy || computedAmount <= 0}>{addBusy ? 'Adding…' : 'Add Document Charge'}</Btn>
          </Can>
        </div>
      </Card>
    )
  }

  if (paid) {
    return (
      <Card style={{ marginBottom: 16, background: 'var(--ok-dim)', border: '1px solid var(--ok)' }}>
        <div className="row gap-2" style={{ alignItems: 'center' }}>
          <Icon n="check" s={15} c="var(--ok)" />
          <span style={{ fontSize: 12.5 }}>Document charge settled — {money(docCharge.dueAmount)} paid in full.</span>
        </div>
      </Card>
    )
  }

  return (
    <Card style={{ marginBottom: 16, background: 'var(--warn-dim)', border: '1px solid var(--warn)' }}>
      <div className="row between wrap" style={{ marginBottom: 10 }}>
        <div className="eyebrow">Document Charge — payment required before disbursing</div>
        <span style={{ fontSize: 12.5 }}>Due {money(docCharge.dueAmount)} · Paid {money(docCharge.paidAmount)} · Balance <b style={{ fontFamily: 'JetBrains Mono' }}>{money(docBalance)}</b></span>
      </div>
      <ErrorBanner error={payErr} style={{ marginBottom: 10 }} />
      <Can user={me} module="Active Loans" action="Edit">
        <div className="row gap-2 wrap" style={{ alignItems: 'flex-end', marginBottom: 12 }}>
          <Field label="Charge Amount"><Input type="number" value={dueEdit} onChange={(e) => setDueEdit(e.target.value)} style={{ maxWidth: 160 }} /></Field>
          <Btn variant="solid" icon="check" onClick={updateCharge} disabled={updBusy || num(dueEdit) === num(docCharge.dueAmount)}>{updBusy ? 'Updating…' : 'Update Charge'}</Btn>
          <span className="t-2" style={{ fontSize: 11.5 }}>Suggested: {money(computedAmount)} ({feePercent}% of disbursed amount)</span>
        </div>
      </Can>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 12 }}>
        <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
          {DOC_CHARGE_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
        </Select></Field>
        <Field label="Date"><Input type="date" value={paidAt} onChange={(e) => setPaidAt(e.target.value)} /></Field>
        {method === 'Cash'
          ? <Field label="Receipt No."><Input value={nextReceipt || '…'} readOnly disabled style={{ color: 'var(--tx-2)' }} /></Field>
          : <Field label={method === 'Cheque' ? 'Cheque No.' : 'Transfer Reference'}><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>}
        {method === 'Cheque' && <>
          <Field label="Bank"><BankSelect value={bankId} onChange={setBankId} /></Field>
          <Field label="Cheque Date"><Input type="date" value={chequeDate} onChange={(e) => setChequeDate(e.target.value)} /></Field>
        </>}
      </div>
      <Can user={me} module="Loan Payments" action="Create">
        <Btn variant="primary" icon="check" onClick={payCharge} disabled={payBusy} style={{ marginTop: 12 }}>{payBusy ? 'Recording…' : `Record Payment (${money(docBalance)})`}</Btn>
      </Can>
    </Card>
  )
}

/* ---------- Document Charge modal + printable receipt (Approved loans) ---------- */
const escHtml = (s: unknown) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c] as string))

function printDocChargeReceipt(loan: Loan, charge: LoanCharge, payment: LoanPayment | undefined, brand: { name: string; tagline: string }) {
  const w = window.open('', '_blank', 'width=720,height=900')
  if (!w) return
  const row = (k: string, v: unknown) => `<div class="r"><span>${k}</span><b>${escHtml(v)}</b></div>`
  w.document.write(`<!doctype html><html><head><title>Document Charge Receipt ${escHtml(loan.code)}</title>
<style>@page{size:A5;margin:12mm}body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#000;margin:0}
.c{text-align:center;border-bottom:1px dashed #888;padding-bottom:12px;margin-bottom:14px}.c h1{margin:0;font-size:20px}.c h2{margin:8px 0 0;font-size:14px;letter-spacing:.08em}
.r{display:flex;justify-content:space-between;padding:4px 0}.s{border-bottom:1px solid #ccc;padding-bottom:10px;margin-bottom:10px}
.t{font-size:15px;border-top:1px solid #000;margin-top:8px;padding-top:8px}.sig{display:flex;justify-content:space-between;margin-top:36px}</style></head><body>
<div class="c"><h1>${escHtml(brand.name || 'Company')}</h1>${brand.tagline ? `<div>${escHtml(brand.tagline)}</div>` : ''}<h2>DOCUMENT CHARGE RECEIPT</h2></div>
<div class="s">${row('Receipt No', payment?.code || '—')}${row('Date', dateOnly(payment?.paidAt))}</div>
<div class="s">${row('Received From', loan.customer?.fullName)}${row('NIC No', loan.customer?.nicNo || '—')}${row('Loan / Contract No', loan.code)}${row('Product', loan.loanDetail?.loanType?.name || '—')}</div>
<div class="s">${row('Payment Method', payment?.paymentMethod || '—')}${payment?.receiptNo ? row('Reference', payment.receiptNo) : ''}${payment?.bank?.name ? row('Bank', payment.bank.name) : ''}</div>
${row('Document Charge', money(charge.dueAmount))}
<div class="r t"><span>Amount Paid</span><b>${money(payment?.amount ?? charge.paidAmount)}</b></div>
<div class="sig"><div>Received By: <b>${escHtml(payment?.receiver?.name || '—')}</b></div><div>Signature: ____________</div></div>
<script>window.onload=function(){window.print()}</script></body></html>`)
  w.document.close()
}

function DocumentChargeButton({ loan, me, onChanged, notify }: {
  loan: Loan; me?: AuthUser | null; onChanged: () => void; notify: ReturnType<typeof useNotify>
}) {
  const [open, setOpen] = useState(false)
  const [brand, setBrand] = useState<{ name: string; tagline: string }>({ name: '', tagline: '' })
  useEffect(() => { if (open) company.branding().then(setBrand).catch(() => {}) }, [open])

  const feePercent = num(loan.loanDetail?.loanType?.processingFeePercent)
  const financed = num(loan.principalAmount) - num(loan.loanDetail?.downPayment)
  const appDocCharge = loan.loanDetail?.documentChargeAmount
  const computed = appDocCharge != null && appDocCharge !== '' ? num(appDocCharge) : Math.round(financed * feePercent) / 100
  const docCharge = loan.charges?.find((c) => c.category?.name === 'Document')
  const docBalance = docCharge ? Math.max(0, num(docCharge.dueAmount) - num(docCharge.paidAmount)) : 0
  const paid = !!docCharge && docBalance <= 0
  const payment = [...(loan.payments || [])]
    .filter((p) => p.status !== 'Voided' && (p.notes || '').startsWith('Document charge'))
    .sort((a, b) => (a.paidAt < b.paidAt ? 1 : -1))[0]

  if (feePercent <= 0 && !docCharge) return null
  return (
    <>
      <Can user={me} module="Loan Disbursement" action="View">
        <Btn size="sm" variant="solid" icon="receipt" onClick={() => setOpen(true)}>Document Charge</Btn>
      </Can>
      <Modal open={open} onClose={() => setOpen(false)} title={`Document Charge — ${loan.code}`} sub={loan.customer?.fullName} width={720}>
        <DocumentChargeSection loan={loan} me={me} docCharge={docCharge} docBalance={docBalance} computedAmount={computed} feePercent={feePercent} onChanged={onChanged} notify={notify} />
        {paid && docCharge && (
          <Btn variant="primary" icon="print" onClick={() => printDocChargeReceipt(loan, docCharge, payment, brand)}>Print Receipt</Btn>
        )}
      </Modal>
    </>
  )
}

export default LoanDetail
