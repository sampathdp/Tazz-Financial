/* Tazz Investment & Credit — shared, rich Loan Details view.
   Used by both Loan Applications (read-only view of a decided application)
   and Active Loans (the live contract, with disbursement/payments). One
   implementation so the two screens never drift out of sync with each other. */
import { useEffect, useRef, useState } from 'react'
import { Card, Btn, Badge, Field, Input, Select, Table, Td, Modal, inputStyle } from '../../components/ui'
import { Icon } from '../../components/Icon'
import {
  loansApi, loanPaymentsApi, loanChargesApi, loanPostDatedChequesApi, loanDocumentsApi, loanDiaryNotesApi, loanWaiversApi, api,
  type Loan, type LoanStatus, type LoanSchedule, type LoanPayment,
  type LoanCharge, type LoanChargeType, type LoanPostDatedCheque, type ChequeStatus,
  type LoanDocument, type LoanDocType, type LoanDiaryNote, type LoanWaiver, type WaiverType,
  type AuthUser,
} from '../../api'
import { Can } from '../../lib/permissions'
import { useNotify } from '../../components/Notify'
import type { Go } from '../types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const statusTone: Record<LoanStatus, 'green' | 'red' | 'amber' | 'blue' | 'neutral'> = {
  Pending: 'amber', Approved: 'blue', Rejected: 'red', Active: 'green', Closed: 'neutral', WrittenOff: 'red', Restructured: 'amber',
}
const scheduleTone: Record<string, 'green' | 'red' | 'amber' | 'neutral'> = {
  Paid: 'green', Partial: 'amber', Overdue: 'red', Pending: 'neutral',
}
const chequeTone: Record<ChequeStatus, 'green' | 'red' | 'amber' | 'neutral' | 'blue'> = {
  Pending: 'neutral', Handed: 'blue', Realized: 'green', Bounced: 'red', Returned: 'amber',
}

const CHARGE_TYPES: LoanChargeType[] = ['Document', 'Legal', 'Valuation', 'Other']
const DOC_TYPES: { value: LoanDocType; label: string }[] = [
  { value: 'ContractImage', label: 'Contract Image' },
  { value: 'Agreement', label: 'Agreement' },
  { value: 'IdCopy', label: 'ID Copy' },
  { value: 'Other', label: 'Other' },
]
const WAIVER_TYPES: WaiverType[] = ['Interest', 'Penalty', 'Charge', 'Principal']
const ACTIVE_STAGE_STATUSES: LoanStatus[] = ['Active', 'Closed', 'WrittenOff', 'Restructured']

const SECTIONS = [
  { id: 'summary', label: 'Summary', icon: 'chart' },
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
]

export function LoanDetail({ loan, me, onChanged, go, context }: {
  loan: Loan; me?: AuthUser | null; onChanged: () => void; go?: Go; context: 'applications' | 'active'
}) {
  const notify = useNotify()
  const [statusModal, setStatusModal] = useState(false)
  const [tab, setTab] = useState(SECTIONS[0].id)

  if (loan.status === 'Approved' && context === 'active') {
    return <DisburseForm loan={loan} me={me} onChanged={onChanged} notify={notify} />
  }

  const flags: { label: string; on?: boolean; at?: string | null }[] = [
    { label: 'Terminated', on: loan.isTerminated, at: loan.terminatedAt },
    { label: 'Legal', on: loan.isLegal, at: loan.legalAt },
    { label: 'Seizing Order', on: loan.seizingOrderIssued, at: loan.seizingOrderAt },
    { label: 'Repossessed', on: loan.isRepossessed, at: loan.repossessedAt },
  ].filter((f) => f.on)

  return (
    <div>
      <div className="row between wrap gap-2" style={{ marginBottom: 14 }}>
        <div className="row gap-2 wrap" style={{ alignItems: 'center' }}>
          <Badge tone={statusTone[loan.status]} dot>{loan.status}</Badge>
          {flags.map((f) => <Badge key={f.label} tone="red">{f.label}{f.at ? ` · ${dateOnly(f.at)}` : ''}</Badge>)}
          {loan.status === 'Rejected' && loan.rejectedReason && <span className="t-2" style={{ fontSize: 12 }}>Reason: {loan.rejectedReason}</span>}
        </div>
        <div className="row gap-2 wrap">
          <Can user={me} module="Active Loans" action="Edit">
            <Btn size="sm" variant="solid" icon="flag" onClick={() => setStatusModal(true)}>Contract Status Change</Btn>
          </Can>
          {context === 'applications' && go && ACTIVE_STAGE_STATUSES.includes(loan.status) && (
            <Btn size="sm" variant="solid" icon="coins" onClick={() => go('loan/active')}>Open in Active Loans</Btn>
          )}
        </div>
      </div>

      {/* Tabs — one section loaded at a time, matching the reference screen's button row without the scroll-everything sprawl. */}
      <div className="row gap-2" style={{ marginBottom: 16, overflowX: 'auto', paddingBottom: 4, borderBottom: '1px solid var(--line)' }}>
        {SECTIONS.map((s) => (
          <Btn key={s.id} size="sm" variant="ghost" active={tab === s.id} icon={s.icon} onClick={() => setTab(s.id)} style={{ flexShrink: 0 }}>{s.label}</Btn>
        ))}
      </div>

      {tab === 'summary' && <ContractSummary loan={loan} />}
      {tab === 'due-types' && <DueTypeBreakdown loan={loan} />}
      {tab === 'contract-status' && <ArrearsPanel loan={loan} />}
      {tab === 'recovery' && <RecoveryDetails loan={loan} me={me} onChanged={onChanged} notify={notify} />}
      {tab === 'schedule' && <ScheduleTable loan={loan} />}
      {tab === 'receipts' && <ReceiptsPanel loan={loan} me={me} onChanged={onChanged} notify={notify} />}
      {tab === 'security' && <SecurityPanel loan={loan} />}
      {tab === 'cheques' && <ChequesPanel loan={loan} me={me} onChanged={onChanged} notify={notify} />}
      {tab === 'ledger' && <LedgerCard loan={loan} />}
      {tab === 'diary' && <DiaryPanel loan={loan} me={me} onChanged={onChanged} notify={notify} />}
      {tab === 'waivers' && <WaiversPanel loan={loan} me={me} onChanged={onChanged} notify={notify} />}
      {tab === 'images' && <ImagesPanel loan={loan} me={me} />}

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

function ContractSummary({ loan }: { loan: Loan }) {
  const schedules = loan.schedules || []
  const expiry = schedules.length ? schedules[schedules.length - 1].dueDate : null
  return (
    <>
      <div className="eyebrow" style={{ marginBottom: 10 }}>Contract Summary</div>
      <KpiGrid items={[
        { label: 'Contract No', value: loan.code, icon: 'doc', tone: 'blue' },
        { label: 'Member', value: <>{loan.customer?.fullName}<div className="t-2" style={{ fontSize: 11, fontWeight: 400 }}>{loan.customer?.code}</div></>, icon: 'users', tone: 'blue' },
        { label: 'Branch', value: loan.branch?.name || '—', icon: 'building', tone: 'neutral' },
        { label: 'Product', value: loan.loanType?.name || '—', icon: 'tag', tone: 'blue' },
        { label: 'Contract Amount', value: money(loan.principalAmount), icon: 'coins', tone: 'green' },
        { label: 'Down Payment', value: money(loan.downPayment), icon: 'wallet', tone: 'neutral' },
        { label: 'Period', value: `${loan.termMonths} mo`, icon: 'calendar', tone: 'neutral' },
        { label: 'Interest Rate', value: `${loan.interestRatePercent}%`, icon: 'chart', tone: 'amber' },
        { label: 'Activated', value: dateOnly(loan.disbursedAt), icon: 'calendar', tone: 'green' },
        { label: 'Expiry', value: expiry ? dateOnly(expiry) : '—', icon: 'clock', tone: 'red' },
      ]} />
    </>
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
        </>} />
      {rows.length > 0 && (
        <div className="row between" style={{ padding: '10px 16px', borderTop: '1px solid var(--line)', fontWeight: 700, fontSize: 13 }}>
          <span>Totals</span>
          <span className="row gap-4">
            <span style={{ fontFamily: 'JetBrains Mono' }}>{money(totals.due)}</span>
            <span style={{ fontFamily: 'JetBrains Mono' }}>{money(totals.paid)}</span>
            <span style={{ fontFamily: 'JetBrains Mono' }}>{money(totals.balance)}</span>
          </span>
        </div>
      )}
    </Card>
  )
}

/* ---------- Arrears / Contract Status ---------- */
function ArrearsPanel({ loan }: { loan: Loan }) {
  const a = loan.summary?.arrears
  if (!a) return null
  return (
    <>
      <div className="eyebrow" style={{ marginBottom: 10 }}>Contract Status</div>
      <KpiGrid items={[
        { label: 'Rental Arrears', value: money(a.rentalArrears), icon: 'alert', tone: a.rentalArrears > 0 ? 'red' : 'green' },
        { label: 'Other Charge Arrears', value: money(a.otherChargeArrears), icon: 'alert', tone: a.otherChargeArrears > 0 ? 'red' : 'green' },
        { label: 'Default Charges', value: money(a.defaultCharges), icon: 'alert', tone: a.defaultCharges > 0 ? 'red' : 'green' },
        { label: 'Total Arrears', value: money(a.totalArrears), icon: 'target', tone: a.totalArrears > 0 ? 'red' : 'green' },
        { label: 'Over Payment', value: money(a.overPayment), icon: 'refund', tone: a.overPayment > 0 ? 'blue' : 'neutral' },
        { label: 'Future Capital', value: money(a.futureCapital), icon: 'coins', tone: 'blue' },
        { label: 'Future Interest', value: money(a.futureInterest), icon: 'chart', tone: 'blue' },
        { label: 'Balance Pre-Payment', value: money(a.balancePrePayment), icon: 'wallet', tone: a.balancePrePayment > 0 ? 'amber' : 'green' },
        { label: 'Amount To Be Collected', value: money(a.amountToBeCollected), icon: 'target', tone: 'amber' },
      ]} />
    </>
  )
}

/* ---------- Recovery Details ---------- */
function RecoveryDetails({ loan, me, onChanged, notify }: { loan: Loan; me?: AuthUser | null; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const r = loan.summary?.recovery
  const [editing, setEditing] = useState(false)
  const [officers, setOfficers] = useState<{ id: number; name: string }[]>([])
  const [loanOfficerId, setLoanOfficerId] = useState<number | ''>(loan.loanOfficerId || '')
  const [recoveryOfficerId, setRecoveryOfficerId] = useState<number | ''>(loan.recoveryOfficerId || '')
  const [busy, setBusy] = useState(false)

  const openEdit = () => {
    api.users.list().then((u: any) => setOfficers(u)).catch(() => setOfficers([]))
    setLoanOfficerId(loan.loanOfficerId || ''); setRecoveryOfficerId(loan.recoveryOfficerId || '')
    setEditing(true)
  }
  const save = async () => {
    setBusy(true)
    try {
      await loansApi.update(loan.id, { loanOfficerId: loanOfficerId || null, recoveryOfficerId: recoveryOfficerId || null } as any)
      setEditing(false); onChanged(); notify.success('Officers updated')
    } catch (ex: any) { notify.error('Update failed', ex?.response?.data?.message) } finally { setBusy(false) }
  }

  return (
    <Card>
      <div className="row between" style={{ marginBottom: 12 }}>
        <div className="eyebrow">Recovery Details</div>
        <Can user={me} module="Active Loans" action="Edit"><Btn size="sm" variant="solid" icon="edit" onClick={openEdit}>Assign Officers</Btn></Can>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '10px 20px', fontSize: 12.5 }}>
        <div><span className="t-2">Follow Up Officer: </span>{loan.recoveryOfficer?.name || '—'}</div>
        <div><span className="t-2">Marketing / Loan Officer: </span>{loan.loanOfficer?.name || '—'}</div>
        <div><span className="t-2">Last Payment: </span>{r?.lastPaymentDate ? `${dateOnly(r.lastPaymentDate)} · ${money(r.lastPaymentAmount)}` : '—'}</div>
        <div><span className="t-2">Fallen Due Rentals: </span>{r?.fallenDueRentals ?? 0}</div>
        <div><span className="t-2">Settled Rentals: </span>{r?.settledRentals ?? 0}</div>
        <div><span className="t-2">Outstanding Rentals: </span>{r?.outstandingRentals ?? 0}</div>
        <div><span className="t-2">Future Rentals: </span>{r?.futureRentals ?? 0}</div>
      </div>

      <Modal open={editing} onClose={() => setEditing(false)} title="Assign Officers" footer={<>
        <Btn variant="solid" onClick={() => setEditing(false)}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save'}</Btn>
      </>}>
        <div className="col gap-3">
          <Field label="Marketing / Loan Officer"><Select value={loanOfficerId} onChange={(e) => setLoanOfficerId(Number(e.target.value) || '')}>
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

/* ---------- Contract Receipts / Cancelled Receipts ---------- */
function ReceiptsPanel({ loan, me, onChanged, notify }: { loan: Loan; me?: AuthUser | null; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const all = loan.payments || []
  const pending = all.filter((p) => p.approvalStatus === 'PendingApproval')
  const pendingCheque = all.filter((p) => p.paymentMethod === 'Cheque' && p.clearanceStatus === 'Pending' && p.approvalStatus !== 'PendingApproval')
  const active = all.filter((p) => p.status !== 'Voided' && p.approvalStatus !== 'PendingApproval' && !(p.paymentMethod === 'Cheque' && p.clearanceStatus === 'Pending'))
  const voided = all.filter((p) => p.status === 'Voided')

  const voidPayment = async (p: LoanPayment) => {
    const reason = await notify.prompt({ title: `Void receipt ${p.code}?`, msg: 'Give a reason — this reverses its allocation against the schedule.', placeholder: 'Reason for voiding', danger: true, okText: 'Void' })
    if (reason === null) return
    try { await loanPaymentsApi.remove(p.id, reason || undefined); notify.success('Receipt voided', p.code); onChanged() }
    catch (ex: any) { notify.error('Void failed', ex?.response?.data?.message) }
  }

  const clearCheque = async (p: LoanPayment, status: 'Realized' | 'Bounced') => {
    try {
      await loanPaymentsApi.clearCheque(p.id, status)
      notify.success(status === 'Realized' ? 'Cheque realized' : 'Cheque rejected')
      onChanged()
    } catch (ex: any) { notify.error('Update failed', ex?.response?.data?.message) }
  }

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
                  <span className="t-2" style={{ fontSize: 12 }}>{dateOnly(p.paidAt)} · {p.bankName}{p.receiptNo ? ` · #${p.receiptNo}` : ''} · awaiting clearance, not yet applied to the schedule</span>
                </div>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(p.amount)}</span>
                  <Can user={me} module="Loan Payments" action="Edit">
                    {(p.chequeDate?.slice(0, 10) || '') <= todayIso() ? <>
                      <Btn size="sm" variant="ok" onClick={() => clearCheque(p, 'Realized')}>Realize</Btn>
                      <Btn size="sm" variant="danger" onClick={() => clearCheque(p, 'Bounced')}>Reject</Btn>
                    </> : <span className="t-2" style={{ fontSize: 11.5 }}>Not due yet ({dateOnly(p.chequeDate)})</span>}
                  </Can>
                </div>
              </div>
            ))}
          </div>
        )}
        {active.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No receipts recorded yet.</div> :
          <div className="col gap-2">
            {active.map((p) => (
              <div key={p.id} className="row between wrap" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <Icon n="receipt" s={15} />
                  <span style={{ fontSize: 12.5, fontWeight: 600 }}>{p.code}</span>
                  <span className="t-2" style={{ fontSize: 12 }}>{dateOnly(p.paidAt)} · {p.paymentMethod}{p.receiptNo ? ` · #${p.receiptNo}` : ''} · {p.receiver?.name}{p.clearanceStatus === 'Realized' ? ' · cheque realized' : ''}</span>
                </div>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(p.amount)}</span>
                  <Can user={me} module="Loan Payments" action="Delete">
                    <button className="mms-act" title="Void" onClick={() => voidPayment(p)}><Icon n="trash" s={14} /></button>
                  </Can>
                </div>
              </div>
            ))}
          </div>}
      </Card>

      <Card>
        <div className="eyebrow" style={{ marginBottom: 12 }}>Cancelled Receipts</div>
        {voided.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No cancelled receipts.</div> :
          <div className="col gap-2">
            {voided.map((p) => (
              <div key={p.id} className="row between wrap" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', opacity: 0.7 }}>
                <div className="row gap-2" style={{ alignItems: 'center' }}>
                  <Icon n="x" s={15} c="var(--bad)" />
                  <span style={{ fontSize: 12.5, fontWeight: 600, textDecoration: 'line-through' }}>{p.code}</span>
                  <span className="t-2" style={{ fontSize: 12 }}>{dateOnly(p.paidAt)} · voided {dateOnly(p.voidedAt)} by {p.voider?.name}{p.voidReason ? ` · ${p.voidReason}` : ''}</span>
                </div>
                <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(p.amount)}</span>
              </div>
            ))}
          </div>}
      </Card>
    </>
  )
}

/* ---------- Security & Collateral ---------- */
function SecurityPanel({ loan }: { loan: Loan }) {
  return (
    <Card>
      <div className="eyebrow" style={{ marginBottom: 12 }}>Security, Collateral & Equipment Details</div>
      {!loan.guarantors?.length && !loan.collateral?.length && <div className="t-2" style={{ fontSize: 12.5 }}>No guarantors or collateral recorded.</div>}
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
        {loan.collateral.map((c, i) => (
          <div key={i} className="row between" style={{ padding: '6px 0', borderBottom: '1px solid var(--line-soft)', fontSize: 12.5 }}>
            <span>{c.type} — {c.description}</span><span className="t-2">{c.estimatedValue ? money(c.estimatedValue) : '—'}</span>
          </div>
        ))}
      </>)}
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
    catch (ex: any) { notify.error('Delete failed', ex?.response?.data?.message) }
  }

  return (
    <Card pad={0}>
      <div className="row between" style={{ padding: '14px 16px 0' }}>
        <div className="eyebrow">Post Dated Cheques</div>
        <span className="t-2" style={{ fontSize: 11.5 }}>Record a cheque on the Payment Receipt screen · realize or reject on Cheque Management.</span>
      </div>
      <Table cols={[{ label: 'Cheque No' }, { label: 'Bank' }, { label: 'Date' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 40 }]}
        rows={cheques} empty="No post dated cheques"
        render={(c: LoanPostDatedCheque) => <>
          <Td mono>{c.chequeNo}</Td>
          <Td>{c.bankName}</Td>
          <Td>{dateOnly(c.chequeDate)}</Td>
          <Td mono align="right">{money(c.amount)}</Td>
          <Td align="center"><Badge tone={chequeTone[c.status]} dot>{c.status}</Badge></Td>
          <Td align="right">
            <Can user={me} module="Loan Payments" action="Delete">
              <button className="mms-act" title="Delete" onClick={() => remove(c)}><Icon n="trash" s={14} /></button>
            </Can>
          </Td>
        </>} />
    </Card>
  )
}

/* ---------- Ledger Card (computed, client-side) ---------- */
function LedgerCard({ loan }: { loan: Loan }) {
  type Row = { date: string; particulars: string; debit: number; credit: number }
  const rows: Row[] = []
  for (const s of loan.schedules || []) rows.push({ date: s.dueDate, particulars: `Installment #${s.installmentNo} due`, debit: num(s.totalDue), credit: 0 })
  for (const p of loan.payments || []) {
    if (p.status === 'Voided' || p.approvalStatus === 'PendingApproval') continue
    rows.push({ date: dateOnly(p.paidAt), particulars: `Receipt ${p.code} (${p.paymentMethod})`, debit: 0, credit: num(p.amount) })
  }
  for (const c of loan.charges || []) {
    rows.push({ date: c.chargedAt, particulars: `${c.chargeType} charge`, debit: num(c.dueAmount), credit: 0 })
    if (num(c.paidAmount) > 0) rows.push({ date: c.chargedAt, particulars: `${c.chargeType} charge paid`, debit: 0, credit: num(c.paidAmount) })
  }
  for (const w of loan.waivers || []) rows.push({ date: w.waivedAt, particulars: `${w.waiverType} waived${w.reason ? ` — ${w.reason}` : ''}`, debit: 0, credit: num(w.amount) })

  rows.sort((a, b) => a.date.localeCompare(b.date))
  let balance = 0
  const withBalance = rows.map((r) => { balance += r.debit - r.credit; return { ...r, balance } })

  return (
    <Card pad={0}>
      <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Ledger Card</div>
      <Table cols={[{ label: 'Date' }, { label: 'Particulars' }, { label: 'Debit', align: 'right' }, { label: 'Credit', align: 'right' }, { label: 'Balance', align: 'right' }]}
        rows={withBalance} empty="No ledger entries yet"
        render={(r) => <>
          <Td>{dateOnly(r.date)}</Td>
          <Td>{r.particulars}</Td>
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
    } catch (ex: any) { notify.error('Add failed', ex?.response?.data?.message) } finally { setBusy(false) }
  }
  const remove = async (n: LoanDiaryNote) => {
    const ok = await notify.confirm({ title: 'Delete this note?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await loanDiaryNotesApi.remove(n.id); notify.success('Note deleted'); onChanged() }
    catch (ex: any) { notify.error('Delete failed', ex?.response?.data?.message) }
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

/* ---------- Waivers (W-Off Set Plan / Waved Off Details) ---------- */
function WaiversPanel({ loan, me, onChanged, notify }: { loan: Loan; me?: AuthUser | null; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const waivers = loan.waivers || []
  const [form, setForm] = useState<{ waiverType: WaiverType; amount: string; reason: string }>({ waiverType: 'Interest', amount: '', reason: '' })
  const [busy, setBusy] = useState(false)

  const add = async () => {
    if (!form.amount) return
    setBusy(true)
    try {
      await loanWaiversApi.create(loan.id, { waiverType: form.waiverType, amount: form.amount, reason: form.reason || undefined })
      setForm({ waiverType: 'Interest', amount: '', reason: '' }); notify.success('Waiver recorded'); onChanged()
    } catch (ex: any) { notify.error('Add failed', ex?.response?.data?.message) } finally { setBusy(false) }
  }
  const remove = async (w: LoanWaiver) => {
    const ok = await notify.confirm({ title: 'Delete this waiver?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await loanWaiversApi.remove(w.id); notify.success('Waiver deleted'); onChanged() }
    catch (ex: any) { notify.error('Delete failed', ex?.response?.data?.message) }
  }

  return (
    <Card>
      <div className="eyebrow" style={{ marginBottom: 12 }}>Waivers (W-Off Set Plan / Waved Off Details)</div>
      <Can user={me} module="Active Loans" action="Approve">
        <div className="row gap-2 wrap" style={{ marginBottom: 14 }}>
          <Select value={form.waiverType} onChange={(e) => setForm((s) => ({ ...s, waiverType: e.target.value as WaiverType }))} style={{ maxWidth: 140 }}>
            {WAIVER_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
          </Select>
          <Input type="number" placeholder="Amount" value={form.amount} onChange={(e) => setForm((s) => ({ ...s, amount: e.target.value }))} style={{ maxWidth: 130 }} />
          <Input placeholder="Reason" value={form.reason} onChange={(e) => setForm((s) => ({ ...s, reason: e.target.value }))} style={{ flex: 1, minWidth: 160 }} />
          <Btn variant="primary" size="sm" icon="plus" onClick={add} disabled={busy}>Add</Btn>
        </div>
      </Can>
      {waivers.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No waivers recorded.</div> :
        <div className="col gap-2">
          {waivers.map((w) => (
            <div key={w.id} className="row between wrap" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
              <div>
                <span style={{ fontSize: 12.5, fontWeight: 600 }}>{w.waiverType}</span>
                <span className="t-2" style={{ fontSize: 12 }}> — {w.reason || 'no reason given'} · {dateOnly(w.waivedAt)} · approved by {w.approver?.name}</span>
              </div>
              <div className="row gap-2" style={{ alignItems: 'center' }}>
                <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(w.amount)}</span>
                <Can user={me} module="Active Loans" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(w)}><Icon n="trash" s={14} /></button></Can>
              </div>
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
  const [docType, setDocType] = useState<LoanDocType>('ContractImage')
  const [uploading, setUploading] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)

  useEffect(() => setDocs(loan.documents || []), [loan])

  const refresh = () => loanDocumentsApi.list(loan.id).then(setDocs).catch(() => {})

  const pick = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setUploading(true)
    try { await loanDocumentsApi.upload(loan.id, file, docType); refresh(); notify.success('Image uploaded') }
    catch (ex: any) { notify.error('Upload failed', ex?.response?.data?.message) }
    finally { setUploading(false) }
  }
  const view = async (d: LoanDocument) => {
    try {
      const blob = await loanDocumentsApi.download(loan.id, d.id)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch { notify.error('Could not open document') }
  }
  const remove = async (d: LoanDocument) => {
    const ok = await notify.confirm({ title: 'Delete this document?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await loanDocumentsApi.remove(loan.id, d.id); refresh() }
    catch (ex: any) { notify.error('Delete failed', ex?.response?.data?.message) }
  }

  return (
    <Card>
      <div className="eyebrow" style={{ marginBottom: 12 }}>Contract Images</div>
      <Can user={me} module="Active Loans" action="Create">
        <div className="row gap-2" style={{ marginBottom: 12 }}>
          <Select value={docType} onChange={(e) => setDocType(e.target.value as LoanDocType)} style={{ maxWidth: 180 }}>
            {DOC_TYPES.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
          <input ref={fileInput} type="file" accept="image/png,image/jpeg,image/webp,application/pdf" onChange={pick} style={{ display: 'none' }} />
          <Btn variant="solid" icon="upload" size="sm" onClick={() => fileInput.current?.click()} disabled={uploading}>{uploading ? 'Uploading…' : 'Upload'}</Btn>
        </div>
      </Can>
      {docs.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No images uploaded yet.</div> :
        <div className="col gap-2">
          {docs.map((d) => (
            <div key={d.id} className="row between" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
              <div className="row gap-2" style={{ alignItems: 'center' }}>
                <Icon n="doc" s={16} />
                <span style={{ fontSize: 12.5 }}>{DOC_TYPES.find((o) => o.value === d.docType)?.label || d.docType}</span>
              </div>
              <div className="row gap-1">
                <button className="mms-act" title="View" onClick={() => view(d)}><Icon n="eye" s={15} /></button>
                <Can user={me} module="Active Loans" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(d)}><Icon n="trash" s={15} /></button></Can>
              </div>
            </div>
          ))}
        </div>}
    </Card>
  )
}

/* ---------- Contract Status Change ---------- */
function StatusFlagsModal({ open, onClose, loan, onChanged, notify }: {
  open: boolean; onClose: () => void; loan: Loan; onChanged: () => void; notify: ReturnType<typeof useNotify>
}) {
  const [isTerminated, setIsTerminated] = useState(!!loan.isTerminated)
  const [isLegal, setIsLegal] = useState(!!loan.isLegal)
  const [seizingOrderIssued, setSeizingOrderIssued] = useState(!!loan.seizingOrderIssued)
  const [isRepossessed, setIsRepossessed] = useState(!!loan.isRepossessed)
  const [freezeDate, setFreezeDate] = useState(loan.freezeDate || '')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!open) return
    setIsTerminated(!!loan.isTerminated); setIsLegal(!!loan.isLegal)
    setSeizingOrderIssued(!!loan.seizingOrderIssued); setIsRepossessed(!!loan.isRepossessed)
    setFreezeDate(loan.freezeDate || '')
  }, [open, loan])

  const save = async () => {
    setBusy(true)
    try {
      await loansApi.updateStatusFlags(loan.id, { isTerminated, isLegal, seizingOrderIssued, isRepossessed, freezeDate: freezeDate || undefined })
      notify.success('Contract status updated'); onChanged(); onClose()
    } catch (ex: any) { notify.error('Update failed', ex?.response?.data?.message) } finally { setBusy(false) }
  }

  const row = (label: string, checked: boolean, set: (v: boolean) => void) => (
    <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer' }}>
      <input type="checkbox" checked={checked} onChange={(e) => set(e.target.checked)} />
      <span style={{ fontSize: 13 }}>{label}</span>
    </label>
  )

  return (
    <Modal open={open} onClose={onClose} title="Contract Status Change" sub={loan.code} footer={<>
      <Btn variant="solid" onClick={onClose}>Cancel</Btn>
      <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save'}</Btn>
    </>}>
      <div className="col gap-3">
        {row('Terminated', isTerminated, setIsTerminated)}
        {row('Legal', isLegal, setIsLegal)}
        {row('Seizing Order Issued', seizingOrderIssued, setSeizingOrderIssued)}
        {row('Repossessed', isRepossessed, setIsRepossessed)}
        <Field label="Freeze Date"><Input type="date" value={freezeDate} onChange={(e) => setFreezeDate(e.target.value)} /></Field>
      </div>
    </Modal>
  )
}

/* ---------- Disburse (Approved -> Active) ---------- */
function DisburseForm({ loan, me, onChanged, notify }: { loan: Loan; me?: AuthUser | null; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const financedAmount = num(loan.principalAmount) - num(loan.downPayment)
  const [amount, setAmount] = useState<string>(String(financedAmount))
  const [disbursedAt, setDisbursedAt] = useState(todayIso())
  const [firstDate, setFirstDate] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const disburse = async () => {
    if (!firstDate) { setErr('Pick the first installment date.'); return }
    setBusy(true); setErr('')
    try {
      await loansApi.disburse(loan.id, { disbursedAmount: amount, disbursedAt, firstInstallmentDate: firstDate })
      notify.success('Loan disbursed', loan.code)
      onChanged()
    } catch (ex: any) {
      setErr(ex?.response?.data?.message || 'Disbursement failed')
    } finally { setBusy(false) }
  }

  return (
    <Card>
      <div className="eyebrow" style={{ marginBottom: 12 }}>Disburse this loan to generate its repayment schedule</div>
      {err && <div style={{ background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', padding: '8px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5, marginBottom: 14 }}>{err}</div>}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16, marginBottom: 16 }}>
        <Field label="Disbursed Amount"><Input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        <Field label="Disbursed Date"><Input type="date" value={disbursedAt} onChange={(e) => setDisbursedAt(e.target.value)} /></Field>
        <Field label="First Installment Date"><Input type="date" value={firstDate} onChange={(e) => setFirstDate(e.target.value)} /></Field>
      </div>
      <div className="t-2" style={{ fontSize: 12, marginBottom: 16 }}>
        {loan.termMonths} months · {loan.installmentFrequency} · {loan.interestMethod === 'reducing_balance' ? 'Reducing balance' : 'Flat'} interest at {loan.interestRatePercent}%
        {num(loan.downPayment) > 0 && <> · Down payment {money(loan.downPayment)} already settled — schedule covers the remaining {money(financedAmount)}</>}
      </div>
      <Can user={me} module="Active Loans" action="Approve">
        <Btn variant="primary" icon="check" onClick={disburse} disabled={busy}>{busy ? 'Disbursing…' : 'Disburse Loan'}</Btn>
      </Can>
    </Card>
  )
}

export default LoanDetail
