/* Tazz Investment & Credit — Speed Draft Detail: shared by the Speed Drafts list/detail
   switcher for every status (Pending/Approved/Active/Closed/Rejected). Approve/Reject/
   Disburse live here (mirrors LoanDetail's DisburseForm); actually recording interest or
   capital payments happens on the standalone Speed Draft Payments screen, same split as
   Loan (ActiveLoans/LoanDetail show history, PaymentReceipt records payments). */
import { useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Card, Btn, Badge, Field, Input, Select, Table, Td, Modal, ErrorBanner, statusTone } from '../../components/ui'
import { Icon } from '../../components/Icon'
import {
  speedDraftsApi, speedDraftCollateralApi, speedDraftCollateralDocumentsApi, speedDraftPaymentsApi,
  type SpeedDraft, type SpeedDraftPayment, type SpeedDraftCollateralInput, type SpeedDraftCollateralDocument, type AuthUser, type PaymentMethod,
} from '../../api'
import { Can, canApprove } from '../../lib/permissions'
import { useNotify } from '../../components/Notify'
import { getErrorText } from '../../lib/apiError'
import type { Go } from '../types'

const num = (v: unknown) => Number(v ?? 0)
const round2 = (v: number) => Math.round(v * 100) / 100
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const CATEGORY_LABEL: Record<string, string> = { Loan: 'Loan', Mortgage: 'Mortgage', GoldPawning: 'Gold Pawning', Lease: 'Lease' }
const PAYMENT_TYPE_LABEL: Record<string, string> = { Interest: 'Interest', CapitalReduction: 'Capital Reduction', Settlement: 'Full Settlement' }

function Kpi({ label, value, tone }: { label: string; value: ReactNode; tone?: string }) {
  return (
    <div style={{ padding: 14, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
      <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em' }}>{label}</div>
      <div style={{ fontFamily: 'JetBrains Mono', fontSize: 18, fontWeight: 700, marginTop: 4, color: tone || 'var(--tx-0)' }}>{value}</div>
    </div>
  )
}
function KpiGrid({ children }: { children: ReactNode }) {
  return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 12, marginBottom: 20 }}>{children}</div>
}

const SECTIONS = [
  { id: 'overview', label: 'Overview', icon: 'chart' },
  { id: 'schedule', label: 'Schedule', icon: 'calendar' },
  { id: 'payments', label: 'Payment History', icon: 'receipt' },
  { id: 'collateral', label: 'Collateral', icon: 'tag' },
] as const
type SectionId = typeof SECTIONS[number]['id']

export function SpeedDraftDetail({ speedDraft, me, onChanged, go, onWithdrawn }: {
  speedDraft: SpeedDraft; me?: AuthUser | null; onChanged: () => void; go?: Go; onWithdrawn?: () => void
}) {
  const notify = useNotify()
  const [section, setSection] = useState<SectionId>('overview')
  const showCollateral = !!speedDraft.speedDraftDetail?.speedDraftType?.requiresCollateral

  const summary = speedDraft.summary
  const schedules = (speedDraft.schedules || []).slice().sort((a, b) => a.installmentNo - b.installmentNo)
  const payments = (speedDraft.payments || []).slice().sort((a, b) => new Date(b.paidAt).getTime() - new Date(a.paidAt).getTime())
  const interestPayments = payments.filter((p) => p.paymentType === 'Interest' || p.paymentType === 'Settlement')
  const capitalPayments = payments.filter((p) => p.paymentType === 'CapitalReduction' || p.paymentType === 'Settlement')

  return (
    <div>
      <div className="row between wrap gap-2" style={{ marginBottom: 16 }}>
        <div className="row gap-2" style={{ borderBottom: '1px solid var(--line)', paddingBottom: 4 }}>
          {SECTIONS.filter((s) => s.id !== 'collateral' || showCollateral).map((s) => (
            <Btn key={s.id} size="sm" variant="ghost" icon={s.icon} active={section === s.id} onClick={() => setSection(s.id)}>{s.label}</Btn>
          ))}
        </div>
        <StatusActions speedDraft={speedDraft} me={me} onChanged={onChanged} go={go} onWithdrawn={onWithdrawn} />
      </div>

      {section === 'overview' && <OverviewPanel speedDraft={speedDraft} />}
      {section === 'schedule' && (
        <Card pad={0}>
          <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Interest Schedule</div>
          <Table cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Interest Due', align: 'right' }, { label: 'Capital Due', align: 'right' }, { label: 'Total Due', align: 'right' }, { label: 'Paid', align: 'right' }, { label: 'Status', align: 'center' }]}
            rows={schedules} empty="Schedule is generated once the draft is disbursed."
            render={(r) => <>
              <Td>{r.installmentNo}</Td>
              <Td>{dateOnly(r.dueDate)}</Td>
              <Td mono align="right">{money(r.interestDue)}</Td>
              <Td mono align="right">{num(r.principalDue) > 0 ? money(r.principalDue) : '—'}</Td>
              <Td mono align="right">{money(r.totalDue)}</Td>
              <Td mono align="right">{money(r.paidAmount)}</Td>
              <Td align="center"><Badge tone={statusTone(r.status)} dot>{r.status}</Badge></Td>
            </>} />
        </Card>
      )}
      {section === 'payments' && (
        <div className="col gap-4">
          <Card pad={0}>
            <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Interest Payments</div>
            <PaymentTable rows={interestPayments} empty="No interest payments recorded yet." />
          </Card>
          <Card pad={0}>
            <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Capital Reductions</div>
            <PaymentTable rows={capitalPayments} empty="No capital reduction payments recorded yet." />
          </Card>
        </div>
      )}
      {showCollateral && section === 'collateral' && <CollateralPanel speedDraft={speedDraft} me={me} onChanged={onChanged} />}
    </div>
  )
}

function PaymentTable({ rows, empty }: { rows: SpeedDraftPayment[]; empty: string }) {
  return (
    <Table cols={[{ label: 'Receipt No' }, { label: 'Date' }, { label: 'Type' }, { label: 'Method' }, { label: 'Amount', align: 'right' }, { label: 'Received By' }, { label: 'Status', align: 'center' }]}
      rows={rows} empty={empty}
      render={(p) => <>
        <Td mono style={{ fontWeight: 600 }}>{p.code}</Td>
        <Td>{dateOnly(p.paidAt)}</Td>
        <Td>{PAYMENT_TYPE_LABEL[p.paymentType] || p.paymentType}</Td>
        <Td>{p.paymentMethod}</Td>
        <Td mono align="right">{money(p.amount)}</Td>
        <Td>{p.receiver?.name || '—'}</Td>
        <Td align="center">{p.status === 'Voided' ? <Badge tone="red" dot>Voided</Badge> : <Badge tone="green" dot>Recorded</Badge>}</Td>
      </>} />
  )
}

function OverviewPanel({ speedDraft: d }: { speedDraft: SpeedDraft }) {
  const s = d.summary
  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '10px 20px', fontSize: 12.5, marginBottom: 20 }}>
        <div><span className="t-2">Customer: </span><b>{d.customer?.fullName}</b></div>
        <div><span className="t-2">Type: </span><b>{d.speedDraftDetail?.speedDraftType?.name} ({CATEGORY_LABEL[d.speedDraftDetail?.speedDraftType?.category || ''] || d.speedDraftDetail?.speedDraftType?.category})</b></div>
        <div><span className="t-2">Rate: </span><b>{Number(d.interestRatePercent).toFixed(3)}% {d.ratePeriod === 'annual' ? 'p.a.' : d.ratePeriod === 'daily' ? 'p.d.' : 'p.m.'}</b></div>
        <div><span className="t-2">Period: </span><b>{d.termMonths} months</b></div>
        <div><span className="t-2">Start: </span><b>{dateOnly(d.startDate)}</b></div>
        <div><span className="t-2">Maturity: </span><b>{dateOnly(d.maturityDate)}</b></div>
        {!!d.speedDraftDetail?.vehicleNo && <div><span className="t-2">Vehicle No: </span><b>{d.speedDraftDetail.vehicleNo}</b></div>}
      </div>

      {d.insurance && (
        <div style={{ marginBottom: 20 }}>
          <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 6 }}>Insurance Policy</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '6px 20px', fontSize: 12.5 }}>
            <div><span className="t-2">Company: </span>{d.insurance.insuranceCompany || '—'}</div>
            <div><span className="t-2">Policy No: </span>{d.insurance.policyNo || '—'}</div>
            <div><span className="t-2">Amount: </span>{d.insurance.amount != null ? money(d.insurance.amount) : '—'}</div>
            <div><span className="t-2">Contact Person: </span>{d.insurance.contactPerson || '—'}</div>
            <div><span className="t-2">Coverage: </span>{d.insurance.coverage || '—'}</div>
            {d.insurance.remarks && <div style={{ gridColumn: '1/-1' }}><span className="t-2">Remark: </span>{d.insurance.remarks}</div>}
          </div>
        </div>
      )}

      {s ? (
        <KpiGrid>
          <Kpi label="Principal" value={money(s.principalAmount)} />
          <Kpi label="Outstanding Capital" value={money(s.outstandingCapital)} tone={s.outstandingCapital > 0 ? 'var(--bad)' : 'var(--ok)'} />
          <Kpi label="Capital Paid" value={money(s.totalCapitalPaid)} />
          <Kpi label="Interest Paid" value={money(s.totalInterestPaid)} />
          <Kpi label="Outstanding Interest" value={money(s.outstandingInterest)} tone={s.outstandingInterest > 0 ? 'var(--bad)' : 'var(--ok)'} />
          {s.penaltyDue > 0 && <Kpi label="Penalty Due" value={money(s.penaltyDue)} tone="var(--bad)" />}
          <Kpi label="Amount to Settle Today" value={money(s.amountToSettleToday)} />
          <Kpi label="Next Due" value={s.nextDueDate ? `${dateOnly(s.nextDueDate)} — ${money(s.nextDueAmount)}` : '—'} />
          {s.overdueInstallments > 0 && <Kpi label="Overdue Installments" value={`${s.overdueInstallments} (${s.daysOverdue}d)`} tone="var(--bad)" />}
        </KpiGrid>
      ) : (
        <div className="t-2" style={{ fontSize: 12.5 }}>Summary figures are available once the draft is disbursed and active.</div>
      )}

      {d.rejectedReason && (
        <div style={{ background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', padding: '10px 14px', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
          Rejected: {d.rejectedReason}
        </div>
      )}
    </div>
  )
}

function StatusActions({ speedDraft: d, me, onChanged, go, onWithdrawn }: {
  speedDraft: SpeedDraft; me?: AuthUser | null; onChanged: () => void; go?: Go; onWithdrawn?: () => void
}) {
  const notify = useNotify()
  const [busy, setBusy] = useState(false)
  const [disburseOpen, setDisburseOpen] = useState(false)
  const [method, setMethod] = useState<'Cash' | 'Bank'>('Cash')
  const [amount, setAmount] = useState(String(d.principalAmount))
  const [startDate, setStartDate] = useState(todayIso())
  const [err, setErr] = useState('')

  const [renewOpen, setRenewOpen] = useState(false)
  const [renewTermMonths, setRenewTermMonths] = useState(d.termMonths)
  const [renewPayNow, setRenewPayNow] = useState(false)
  const [renewAmount, setRenewAmount] = useState('')
  const [renewMethod, setRenewMethod] = useState<PaymentMethod>('Cash')
  const [renewPaidAt, setRenewPaidAt] = useState(todayIso())
  const [renewReceiptNo, setRenewReceiptNo] = useState('')
  const [renewNotes, setRenewNotes] = useState('')
  const [renewErr, setRenewErr] = useState('')

  const approve = async () => {
    setBusy(true)
    try { await speedDraftsApi.approve(d.id); notify.success('Speed Draft approved'); onChanged() }
    catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
    finally { setBusy(false) }
  }
  const reject = async () => {
    const reason = await notify.prompt({ title: 'Reject this Speed Draft?', msg: 'Give a reason for the customer/officer.', placeholder: 'Reason', danger: true, okText: 'Reject' })
    if (reason === null) return
    setBusy(true)
    try { await speedDraftsApi.reject(d.id, reason || undefined); notify.success('Speed Draft rejected'); onChanged() }
    catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
    finally { setBusy(false) }
  }
  const withdraw = async () => {
    const ok = await notify.confirm({ title: `Withdraw Speed Draft ${d.code}?`, msg: 'This deletes the application. This action cannot be undone.', danger: true, okText: 'Withdraw' })
    if (!ok) return
    setBusy(true)
    try { await speedDraftsApi.remove(d.id); notify.success('Application withdrawn', d.code); onWithdrawn?.() }
    catch (ex: any) { notify.error('Withdraw failed', getErrorText(ex)) }
    finally { setBusy(false) }
  }
  const disburse = async () => {
    if (!amount || Number(amount) <= 0) { setErr('Enter the disbursed amount.'); return }
    if (!startDate) { setErr('Start date is required.'); return }
    setBusy(true); setErr('')
    try {
      await speedDraftsApi.disburse(d.id, { disbursementMethod: method, disbursedAmount: amount, startDate })
      setDisburseOpen(false)
      notify.success('Speed Draft disbursed', 'Interest schedule generated')
      onChanged()
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Disbursement failed'))
    } finally { setBusy(false) }
  }

  const openRenew = () => {
    setRenewTermMonths(d.termMonths); setRenewPayNow(false); setRenewAmount('')
    setRenewMethod('Cash'); setRenewPaidAt(todayIso()); setRenewReceiptNo(''); setRenewNotes(''); setRenewErr('')
    setRenewOpen(true)
  }
  const renew = async () => {
    if (!renewTermMonths || renewTermMonths <= 0) { setRenewErr('Enter the new term in months.'); return }
    if (renewPayNow && (!renewAmount || Number(renewAmount) <= 0)) { setRenewErr('Enter the payment amount, or turn off "Record a payment now".'); return }
    setBusy(true); setRenewErr('')
    try {
      const res = await speedDraftsApi.renew(d.id, {
        termMonths: renewTermMonths,
        notes: renewNotes || undefined,
        payment: renewPayNow ? {
          amount: renewAmount, paymentMethod: renewMethod, paidAt: renewPaidAt,
          receiptNo: renewReceiptNo || undefined, notes: undefined,
        } : undefined,
      })
      setRenewOpen(false)
      notify.success('Speed Draft renewed', `New draft ${res.speedDraft.code}`)
      onChanged()
    } catch (ex: any) {
      setRenewErr(getErrorText(ex, 'Renewal failed'))
    } finally { setBusy(false) }
  }
  const renewDueToClear = round2(num(d.summary?.outstandingInterest) + num(d.summary?.penaltyDue))
  const renewCollected = renewPayNow ? Math.min(Number(renewAmount) || 0, renewDueToClear) : 0
  const renewCapitalized = round2(renewDueToClear - renewCollected)
  const renewCapitalPayment = renewPayNow ? Math.max(0, (Number(renewAmount) || 0) - renewDueToClear) : 0
  const renewNewPrincipal = round2(num(d.summary?.outstandingCapital) - renewCapitalPayment + renewCapitalized)

  if (d.status === 'Pending') {
    return (
      <div className="row gap-2">
        <Can user={me} module="Speed Drafts" action="Edit">
          <Btn variant="solid" size="sm" icon="edit" onClick={() => go?.(`sd/drafts/${d.id}/edit`)}>Edit</Btn>
        </Can>
        {canApprove(me, 'speed_draft_application') && me?.id !== d.pendingApplicationRequest?.requester?.id && (
          <>
            <Btn variant="primary" size="sm" icon="check" onClick={approve} disabled={busy}>Approve</Btn>
            <Btn variant="danger" size="sm" icon="x" onClick={reject} disabled={busy}>Reject</Btn>
          </>
        )}
        <Can user={me} module="Speed Drafts" action="Delete">
          <Btn variant="solid" size="sm" icon="trash" onClick={withdraw} disabled={busy}>Withdraw</Btn>
        </Can>
      </div>
    )
  }
  if (d.status === 'Approved') {
    return (
      <>
        <Can user={me} module="Speed Drafts" action="Approve">
          <Btn variant="primary" size="sm" icon="flag" onClick={() => setDisburseOpen(true)}>Disburse</Btn>
        </Can>
        <Modal open={disburseOpen} onClose={() => setDisburseOpen(false)} title="Disburse Speed Draft" sub={d.code}
          footer={<>
            <Btn variant="solid" onClick={() => setDisburseOpen(false)}>Cancel</Btn>
            <Btn variant="primary" icon="check" onClick={disburse} disabled={busy}>{busy ? 'Disbursing…' : 'Disburse & Generate Schedule'}</Btn>
          </>}>
          <div className="col gap-3">
            <ErrorBanner error={err} style={{ marginBottom: 0 }} />
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <Field label="Disbursement Method"><Select value={method} onChange={(e) => setMethod(e.target.value as any)}>
                <option value="Cash">Cash</option><option value="Bank">Bank Transfer</option>
              </Select></Field>
              <Field label="Disbursed Amount"><Input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
              <Field label="Start Date"><Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} /></Field>
            </div>
            <div className="t-2" style={{ fontSize: 11.5 }}>
              Generates a {d.termMonths}-month interest-only schedule from the start date — interest is due monthly on the outstanding capital, with the full capital falling due at maturity.
            </div>
          </div>
        </Modal>
      </>
    )
  }
  if (d.status === 'Active') {
    return (
      <>
        <Can user={me} module="Speed Draft Payments" action="Create">
          <Btn variant="solid" size="sm" icon="swap" onClick={openRenew}>Renew</Btn>
        </Can>
        <Modal open={renewOpen} onClose={() => setRenewOpen(false)} title="Renew Speed Draft" sub={d.code} width={560}
          footer={<>
            <Btn variant="solid" onClick={() => setRenewOpen(false)}>Cancel</Btn>
            <Btn variant="primary" icon="check" onClick={renew} disabled={busy}>{busy ? 'Renewing…' : 'Renew'}</Btn>
          </>}>
          <div className="col gap-3">
            <ErrorBanner error={renewErr} style={{ marginBottom: 0 }} />
            <div className="t-2" style={{ fontSize: 11.5 }}>
              Closes this draft and opens a new one under the same product for a fresh term. Any interest/penalty not covered by a payment below is added to the new principal — the books still recognize that income now, just financed by the loan instead of cash.
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <Field label="New Term (months)"><Input type="number" min={1} value={renewTermMonths} onChange={(e) => setRenewTermMonths(Number(e.target.value))} /></Field>
            </div>

            <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer' }}>
              <input type="checkbox" checked={renewPayNow} onChange={(e) => setRenewPayNow(e.target.checked)} />
              <span style={{ fontSize: 13 }}>Record a payment now</span>
            </label>
            {renewPayNow && (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <Field label="Amount"><Input type="number" value={renewAmount} onChange={(e) => setRenewAmount(e.target.value)} /></Field>
                <Field label="Payment Method"><Select value={renewMethod} onChange={(e) => setRenewMethod(e.target.value as PaymentMethod)}>
                  <option value="Cash">Cash</option><option value="Bank">Bank</option><option value="Cheque">Cheque</option>
                </Select></Field>
                <Field label="Date"><Input type="date" value={renewPaidAt} max={todayIso()} onChange={(e) => setRenewPaidAt(e.target.value)} /></Field>
                {renewMethod !== 'Cash' && <Field label="Reference No."><Input value={renewReceiptNo} onChange={(e) => setRenewReceiptNo(e.target.value)} /></Field>}
              </div>
            )}
            <Field label="Notes"><Input value={renewNotes} onChange={(e) => setRenewNotes(e.target.value)} placeholder="Optional" /></Field>

            <div style={{ padding: 12, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)', fontSize: 12.5 }}>
              <div className="row between" style={{ marginBottom: 4 }}><span className="t-2">Outstanding Capital</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(d.summary?.outstandingCapital)}</span></div>
              <div className="row between" style={{ marginBottom: 4 }}><span className="t-2">Interest + Penalty Due</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(renewDueToClear)}</span></div>
              {renewPayNow && <div className="row between" style={{ marginBottom: 4 }}><span className="t-2">Collected Now</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(renewCollected)}</span></div>}
              {renewCapitalized > 0 && <div className="row between" style={{ marginBottom: 4 }}><span className="t-2">Capitalized into New Principal</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(renewCapitalized)}</span></div>}
              {renewCapitalPayment > 0 && <div className="row between" style={{ marginBottom: 4 }}><span className="t-2">Extra Reduces Capital</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(renewCapitalPayment)}</span></div>}
              <div className="row between" style={{ fontWeight: 700, paddingTop: 6, borderTop: '1px solid var(--line)' }}><span>New Principal</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(renewNewPrincipal)}</span></div>
            </div>
          </div>
        </Modal>
      </>
    )
  }
  return null
}

function CollateralPanel({ speedDraft: d, me, onChanged }: { speedDraft: SpeedDraft; me?: AuthUser | null; onChanged: () => void }) {
  const notify = useNotify()
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<SpeedDraftCollateralInput | null>(null)
  const [form, setForm] = useState<Partial<SpeedDraftCollateralInput>>({ collateralType: 'Jewelry' })
  const [busy, setBusy] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [err, setErr] = useState('')
  const fileInput = useRef<HTMLInputElement>(null)
  const items = d.collateral || []

  const openCreate = () => { setEditing(null); setForm({ collateralType: 'Jewelry' }); setErr(''); setModal(true) }
  // jewelryDetail is a read-only nested relation — flatten it back onto the
  // row's flat write fields, which is what this form edits.
  const openEdit = (item: SpeedDraftCollateralInput) => { setEditing(item); setForm({ ...item, ...(item.jewelryDetail || {}) }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.collateralType || !form.description) { setErr('Type and description are required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing?.id) await speedDraftCollateralApi.update(editing.id, form as SpeedDraftCollateralInput)
      else await speedDraftCollateralApi.create(d.id, form as SpeedDraftCollateralInput)
      close(); onChanged()
      notify.success(editing ? 'Collateral updated' : 'Collateral added')
    } catch (ex: any) { setErr(getErrorText(ex, 'Save failed')) }
    finally { setBusy(false) }
  }
  const remove = async (item: SpeedDraftCollateralInput) => {
    const ok = await notify.confirm({ title: 'Remove this collateral item?', danger: true, okText: 'Remove' })
    if (!ok || !item.id) return
    try { await speedDraftCollateralApi.remove(item.id); onChanged(); notify.success('Collateral removed') }
    catch (ex: any) { notify.error('Remove failed', getErrorText(ex)) }
  }

  // Attachments only apply once the item has a real id — new items are saved
  // first (via the form above), then reopened to attach photos.
  const uploadDoc = async (file: File) => {
    if (!editing?.id) return
    setUploading(true)
    try {
      const doc = await speedDraftCollateralDocumentsApi.upload(editing.id, file)
      setForm((s) => ({ ...s, documents: [...(s.documents || []), doc] }))
      onChanged()
    } catch (ex: any) { notify.error('Upload failed', getErrorText(ex)) }
    finally { setUploading(false) }
  }
  const removeDoc = async (doc: SpeedDraftCollateralDocument) => {
    if (!editing?.id) return
    const ok = await notify.confirm({ title: 'Delete this attachment?', danger: true, okText: 'Delete' })
    if (!ok) return
    try {
      await speedDraftCollateralDocumentsApi.remove(editing.id, doc.id)
      setForm((s) => ({ ...s, documents: (s.documents || []).filter((x) => x.id !== doc.id) }))
      onChanged()
    } catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }
  const viewDoc = async (doc: SpeedDraftCollateralDocument) => {
    if (!editing?.id) return
    const win = window.open('', '_blank')
    try {
      const blob = await speedDraftCollateralDocumentsApi.download(editing.id, doc.id)
      const url = URL.createObjectURL(blob)
      if (win) win.location.href = url; else window.open(url, '_blank')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch { win?.close(); notify.error('Could not open attachment') }
  }

  return (
    <Card pad={0}>
      <div className="row between wrap gap-2" style={{ padding: '14px 16px 0' }}>
        <div className="eyebrow">Pledged Collateral</div>
        <Can user={me} module="Speed Drafts" action="Edit">
          <Btn size="sm" variant="primary" icon="plus" onClick={openCreate}>Add Collateral</Btn>
        </Can>
      </div>
      <Table cols={[{ label: 'Type' }, { label: 'Description' }, { label: 'Weight (g)', align: 'right' }, { label: 'Purity' }, { label: 'Appraised Value', align: 'right' }, { label: '', w: 90 }]}
        rows={items} empty="No collateral recorded yet."
        render={(c) => <>
          <Td>{c.collateralType}</Td>
          <Td>{c.description}</Td>
          <Td mono align="right">{c.jewelryDetail?.weightGrams != null ? Number(c.jewelryDetail.weightGrams).toFixed(3) : '—'}</Td>
          <Td>{c.jewelryDetail?.purity || '—'}</Td>
          <Td mono align="right">{c.estimatedValue != null ? money(c.estimatedValue) : '—'}</Td>
          <Td align="center">
            <Can user={me} module="Speed Drafts" action="Edit">
              <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                <button className="mms-act" title="Edit" onClick={() => openEdit(c)}><Icon n="edit" s={14} /></button>
                <button className="mms-act" title="Remove" onClick={() => remove(c)}><Icon n="trash" s={14} /></button>
              </div>
            </Can>
          </Td>
        </>} />

      <Modal open={modal} onClose={close} title={editing ? 'Edit Collateral' : 'Add Collateral'} sub={d.code}
        footer={<>
          <Btn variant="solid" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save'}</Btn>
        </>}>
        <div className="col gap-3">
          <ErrorBanner error={err} style={{ marginBottom: 0 }} />
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Type"><Select value={form.collateralType || 'Jewelry'} onChange={(e) => setForm((s) => ({ ...s, collateralType: e.target.value as any }))}>
              <option value="Jewelry">Jewelry</option><option value="Vehicle">Vehicle</option><option value="Property">Property</option><option value="Other">Other</option>
            </Select></Field>
            <Field label="Description"><Input value={form.description || ''} onChange={(e) => setForm((s) => ({ ...s, description: e.target.value }))} /></Field>
            <Field label="Weight (grams)"><Input type="number" step="0.001" value={form.weightGrams ?? ''} onChange={(e) => setForm((s) => ({ ...s, weightGrams: e.target.value as any }))} /></Field>
            <Field label="Purity"><Input value={form.purity || ''} onChange={(e) => setForm((s) => ({ ...s, purity: e.target.value }))} placeholder="e.g. 22K" /></Field>
            <Field label="Appraised Value"><Input type="number" value={form.appraisedValue ?? ''} onChange={(e) => setForm((s) => ({ ...s, appraisedValue: e.target.value as any }))} /></Field>
          </div>

          <div className="col gap-2">
            <span className="t-2" style={{ fontSize: 11.5, fontWeight: 600, textTransform: 'uppercase' }}>Attachments</span>
            {!editing?.id ? (
              <span className="t-2" style={{ fontSize: 12 }}>Save this item first, then reopen it to attach photos.</span>
            ) : (
              <div className="row gap-2 wrap" style={{ alignItems: 'center' }}>
                {(form.documents || []).map((doc) => (
                  <div key={doc.id} className="row gap-1" style={{ alignItems: 'center', padding: '3px 8px', background: 'var(--bg-1)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', fontSize: 11.5 }}>
                    <Icon n="doc" s={13} />
                    <span>{doc.filePath.split('/').pop()}</span>
                    <button className="mms-act" title="View" onClick={() => viewDoc(doc)} style={{ padding: 0 }}><Icon n="eye" s={13} /></button>
                    <button className="mms-act" title="Remove" onClick={() => removeDoc(doc)} style={{ padding: 0 }}><Icon n="trash" s={13} /></button>
                  </div>
                ))}
                <input ref={fileInput} type="file" accept="image/png,image/jpeg,image/webp,application/pdf"
                  onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadDoc(f); e.target.value = '' }} style={{ display: 'none' }} />
                <Btn size="sm" variant="solid" icon="upload" onClick={() => fileInput.current?.click()} disabled={uploading}>{uploading ? 'Uploading…' : 'Attach File'}</Btn>
              </div>
            )}
          </div>
        </div>
      </Modal>
    </Card>
  )
}
