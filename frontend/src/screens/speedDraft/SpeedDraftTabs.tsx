/* Tazz Investment & Credit — Speed Draft detail tabs that mirror the Loan detail tabs
   (Customer, Contract Status, Recovery, Ledger Card, Post Dated Cheques, Diarizing,
   Contract Images, Print Documents). Same look and behaviour as LoanDetail's panels,
   fed from a SpeedDraft instead of a Loan; the Speed Draft concept itself (interest-only
   schedule, capital reductions, settlement) is untouched. */
import { useEffect, useRef, useState } from 'react'
import { Card, Btn, Badge, Field, Input, Select, BankSelect, Table, Td, Modal, inputStyle } from '../../components/ui'
import { Icon } from '../../components/Icon'
import { useFilePreview } from '../../components/FilePreview'
import { useNotify } from '../../components/Notify'
import { Can, isAdmin } from '../../lib/permissions'
import { getErrorText } from '../../lib/apiError'
import { KpiGrid, CustomerTab } from '../loan/LoanDetail'
import { PrintDocumentsPanel } from '../loan/PrintDocuments'
import {
  api, customersApi, documentCategoriesApi, speedDraftsApi, speedDraftPostDatedChequesApi, speedDraftDiaryNotesApi, speedDraftDocumentsApi,
  type SpeedDraft, type AuthUser, type Loan, type Customer, type LoanPostDatedCheque, type LoanDiaryNote, type LoanDocument, type DocumentCategory, type ChequeStatus,
} from '../../api'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const chequeTone: Record<ChequeStatus, 'green' | 'red' | 'amber' | 'neutral' | 'blue'> = {
  Pending: 'neutral', Handed: 'blue', Realized: 'green', Bounced: 'red', Returned: 'amber',
}

/** Overdue is display-only on a Speed Draft (never persisted): an unpaid installment past its type's grace period. */
const isOverdue = (d: SpeedDraft, r: { status: string; dueDate: string }) => {
  if (r.status === 'Paid') return false
  const cutoff = new Date(); cutoff.setDate(cutoff.getDate() - num(d.speedDraftDetail?.speedDraftType?.gracePeriodDays ?? 5))
  return r.dueDate.slice(0, 10) < cutoff.toISOString().slice(0, 10)
}

/** LoanDetail's panels take a Loan; the customer panel only needs the customer id. */
const asLoan = (d: SpeedDraft) => ({ customerId: d.customerId }) as unknown as Loan

/* ---------- Customer Details ---------- */
export function SpeedDraftCustomerTab({ speedDraft }: { speedDraft: SpeedDraft }) {
  return <CustomerTab loan={asLoan(speedDraft)} />
}

/* ---------- Contract Status (arrears) + Due Types ---------- */
export function ContractStatusPanel({ speedDraft: d }: { speedDraft: SpeedDraft }) {
  const s = d.summary
  if (!s) return null
  const penaltyDue = num(s.penaltyDue)
  const chargesDue = num(s.outstandingCharges)
  const interestDue = num(s.outstandingInterest)
  const rentalArrears = (d.schedules || [])
    .filter((r) => isOverdue(d, r))
    .reduce((a, r) => a + Math.max(0, num(r.interestDue) - num(r.paidAmount)), 0)
  const totalArrears = rentalArrears + penaltyDue + chargesDue
  const items = [
    { type: 'Interest', due: num(s.totalInterestDue), paid: num(s.totalInterestPaid) + num(s.totalInterestWaived), balance: interestDue },
    { type: 'Capital', due: num(s.principalAmount), paid: num(s.totalCapitalPaid), balance: num(s.outstandingCapital) },
    { type: 'Penalty', due: penaltyDue, paid: 0, balance: penaltyDue },
    { type: 'Charges', due: (d.charges || []).reduce((a, c) => a + num(c.dueAmount), 0), paid: (d.charges || []).reduce((a, c) => a + num(c.paidAmount), 0), balance: chargesDue },
  ].map((r) => ({ ...r, recoveryPct: r.due > 0 ? Math.min(100, (r.paid / r.due) * 100) : 100 }))
  const totals = items.reduce((a, r) => ({ due: a.due + r.due, paid: a.paid + r.paid, balance: a.balance + r.balance }), { due: 0, paid: 0, balance: 0 })

  return (
    <div className="col gap-4">
      <div>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Contract Status</div>
        <KpiGrid items={[
          { label: 'Total OD', value: <>{s.daysOverdue} days<div className="t-2" style={{ fontSize: 11, fontWeight: 400 }}>{s.overdueInstallments} installments</div></>, icon: 'clock', tone: s.daysOverdue > 0 ? 'red' : 'green' },
          { label: 'Interest Arrears', value: money(rentalArrears), icon: 'alert', tone: rentalArrears > 0 ? 'red' : 'green' },
          { label: 'Charge Arrears', value: money(chargesDue), icon: 'alert', tone: chargesDue > 0 ? 'red' : 'green' },
          { label: 'Default Charges', value: money(penaltyDue), icon: 'alert', tone: penaltyDue > 0 ? 'red' : 'green' },
          { label: 'Total Arrears', value: money(totalArrears), icon: 'target', tone: totalArrears > 0 ? 'red' : 'green' },
          { label: 'Outstanding Capital', value: money(s.outstandingCapital), icon: 'coins', tone: 'blue' },
          { label: 'Outstanding Interest', value: money(s.outstandingInterest), icon: 'chart', tone: interestDue > 0 ? 'amber' : 'green' },
          { label: 'Total Waived', value: money(s.totalInterestWaived ?? 0), icon: 'refund', tone: num(s.totalInterestWaived) > 0 ? 'blue' : 'neutral' },
          { label: 'Amount To Settle Today', value: money(s.amountToSettleToday), icon: 'wallet', tone: 'amber' },
          { label: 'Next Due', value: s.nextDueDate ? <>{money(s.nextDueAmount)}<div className="t-2" style={{ fontSize: 11, fontWeight: 400 }}>{dateOnly(s.nextDueDate)}</div></> : '—', icon: 'calendar', tone: 'neutral' },
        ]} />
      </div>

      <Card pad={0}>
        <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Due Type Breakdown</div>
        <Table cols={[{ label: 'Due Type' }, { label: 'Due Amount', align: 'right' }, { label: 'Paid Amount', align: 'right' }, { label: 'Balance', align: 'right' }, { label: 'Recovery %', align: 'center' }]}
          rows={items} empty="Nothing due"
          render={(r) => <>
            <Td style={{ fontWeight: 600 }}>{r.type}</Td>
            <Td mono align="right">{money(r.due)}</Td>
            <Td mono align="right" c="var(--ok)">{money(r.paid)}</Td>
            <Td mono align="right" c={r.balance > 0 ? 'var(--bad)' : 'var(--tx-1)'}>{money(r.balance)}</Td>
            <Td align="center"><Badge tone={r.recoveryPct >= 100 ? 'green' : r.recoveryPct > 0 ? 'amber' : 'red'} dot>{r.recoveryPct.toFixed(2)}%</Badge></Td>
          </>}
          footer={
            <tr style={{ fontWeight: 700, borderTop: '2px solid var(--line)' }}>
              <Td>Totals</Td>
              <Td mono align="right">{money(totals.due)}</Td>
              <Td mono align="right">{money(totals.paid)}</Td>
              <Td mono align="right">{money(totals.balance)}</Td>
              <Td></Td>
            </tr>
          } />
      </Card>
    </div>
  )
}

/* ---------- Recovery ---------- */
export function RecoveryPanel({ speedDraft: d, me, onChanged }: { speedDraft: SpeedDraft; me?: AuthUser | null; onChanged: () => void }) {
  const notify = useNotify()
  const detail = d.speedDraftDetail
  const [editing, setEditing] = useState(false)
  const [officers, setOfficers] = useState<{ id: number; name: string }[]>([])
  const [loanOfficerId, setLoanOfficerId] = useState<number | ''>(detail?.loanOfficerId || '')
  const [recoveryOfficerId, setRecoveryOfficerId] = useState<number | ''>(detail?.recoveryOfficerId || '')
  const [busy, setBusy] = useState(false)

  const live = (d.payments || []).filter((p) => p.status !== 'Voided')
  const last = live.slice().sort((a, b) => new Date(b.paidAt).getTime() - new Date(a.paidAt).getTime())[0]
  const schedules = d.schedules || []
  const fallenDue = schedules.filter((r) => isOverdue(d, r)).length
  const settled = schedules.filter((r) => r.status === 'Paid').length
  const outstanding = schedules.filter((r) => r.status !== 'Paid' && r.dueDate.slice(0, 10) <= todayIso()).length
  const future = schedules.filter((r) => r.status !== 'Paid' && r.dueDate.slice(0, 10) > todayIso()).length

  const openEdit = () => {
    api.users.marketingExecutives().then(setOfficers).catch(() => setOfficers([]))
    setLoanOfficerId(detail?.loanOfficerId || ''); setRecoveryOfficerId(detail?.recoveryOfficerId || '')
    setEditing(true)
  }
  const save = async () => {
    setBusy(true)
    try {
      await speedDraftsApi.updateOfficers(d.id, { loanOfficerId: loanOfficerId || null, recoveryOfficerId: recoveryOfficerId || null })
      setEditing(false); onChanged(); notify.success('Officers updated')
    } catch (ex: any) { notify.error('Update failed', getErrorText(ex)) } finally { setBusy(false) }
  }

  return (
    <Card>
      <div className="row between" style={{ marginBottom: 12 }}>
        <div className="eyebrow">Recovery Details</div>
        <Can user={me} module="Speed Drafts" action="Edit"><Btn size="sm" variant="solid" icon="edit" onClick={openEdit}>Assign Officers</Btn></Can>
      </div>
      <KpiGrid items={[
        { label: 'Follow Up Officer', value: detail?.recoveryOfficer?.name || '—', icon: 'users', tone: 'blue' },
        { label: 'Marketing Executive', value: detail?.loanOfficer?.name || '—', icon: 'users', tone: 'blue' },
        { label: 'Last Payment', icon: 'receipt', tone: 'green', value: last ? <>{money(last.amount)}<div className="t-2" style={{ fontSize: 11, fontWeight: 400 }}>{dateOnly(last.paidAt)}</div></> : '—' },
        { label: 'Fallen Due Installments', value: fallenDue.toFixed(2), icon: 'alert', tone: fallenDue > 0 ? 'red' : 'green' },
        { label: 'Settled Installments', value: settled.toFixed(2), icon: 'check', tone: 'green' },
        { label: 'Outstanding Installments', value: outstanding.toFixed(2), icon: 'clock', tone: outstanding > 0 ? 'amber' : 'green' },
        { label: 'Future Installments', value: future.toFixed(2), icon: 'calendar', tone: 'neutral' },
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

/* ---------- Ledger Card (computed, client-side) ---------- */
export function SpeedDraftLedgerCard({ speedDraft: d }: { speedDraft: SpeedDraft }) {
  type Row = { date: string; particulars: string; debit: number; credit: number; typeOrder: number; tone?: 'bad' | 'warn' }
  const rows: Row[] = []

  // The capital handed over is the opening debit; interest is then billed per installment.
  // Capital repayments arrive as receipts (credits), so principalDue is never debited a second time.
  if (d.disbursedAt) rows.push({ date: d.disbursedAt, particulars: 'Capital disbursed', debit: num(d.disbursedAmount ?? d.principalAmount), credit: 0, typeOrder: 0 })
  for (const c of d.charges || []) rows.push({ date: c.chargedAt, particulars: `${c.category?.name || 'Category'} charge`, debit: num(c.dueAmount), credit: 0, typeOrder: 1 })
  for (const s of d.schedules || []) rows.push({ date: s.dueDate, particulars: `Installment #${s.installmentNo} interest due`, debit: num(s.interestDue), credit: 0, typeOrder: 2 })
  for (const w of d.waivers || []) rows.push({ date: w.waivedAt, particulars: `${w.waiverType} waived${w.reason ? ` — ${w.reason}` : ''}`, debit: 0, credit: num(w.amount), typeOrder: 3 })

  for (const p of d.payments || []) {
    const label = `Receipt ${p.code} (${p.paymentMethod}${p.paymentType ? ` · ${p.paymentType}` : ''})`
    if (p.status === 'Voided') {
      rows.push({ date: p.paidAt, particulars: label, debit: 0, credit: num(p.amount), typeOrder: 4, tone: 'bad' })
      rows.push({ date: p.voidedAt || p.paidAt, particulars: `Receipt CANCELLED — ${p.code}${p.voidReason ? ` — ${p.voidReason}` : ''}`, debit: num(p.amount), credit: 0, typeOrder: 5, tone: 'bad' })
      continue
    }
    rows.push({ date: p.paidAt, particulars: label, debit: 0, credit: num(p.amount), typeOrder: 4 })
  }

  for (const c of d.postDatedCheques || []) {
    if (c.status !== 'Bounced' && c.status !== 'Returned') continue
    rows.push({ date: c.chequeDate, particulars: `Cheque RETURNED — #${c.chequeNo}${c.bank?.name ? ` (${c.bank.name})` : ''} — ${money(c.amount)} not applied`, debit: 0, credit: 0, typeOrder: 5, tone: 'bad' })
  }

  rows.sort((a, b) => {
    const dCmp = a.date.substring(0, 10).localeCompare(b.date.substring(0, 10))
    return dCmp !== 0 ? dCmp : a.typeOrder - b.typeOrder
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

/* ---------- Post Dated Cheques ---------- */
type RealizeType = 'Interest' | 'CapitalReduction' | 'Charges'

export function SpeedDraftChequesPanel({ speedDraft: d, me, onChanged }: { speedDraft: SpeedDraft; me?: AuthUser | null; onChanged: () => void }) {
  const notify = useNotify()
  const cheques = d.postDatedCheques || []
  const admin = isAdmin(me)
  const [addOpen, setAddOpen] = useState(false)
  const [chequeNo, setChequeNo] = useState('')
  const [bankId, setBankId] = useState<number | ''>('')
  const [chequeDate, setChequeDate] = useState(todayIso())
  const [amount, setAmount] = useState('')
  const [busy, setBusy] = useState(false)
  const [realizing, setRealizing] = useState<LoanPostDatedCheque | null>(null)
  const [realizedAt, setRealizedAt] = useState(todayIso())
  const [payType, setPayType] = useState<RealizeType>('Interest')

  const record = async () => {
    if (!chequeNo.trim() || !bankId || !chequeDate || num(amount) <= 0) { notify.error('Fill in cheque no, bank, date and amount'); return }
    setBusy(true)
    try {
      await speedDraftPostDatedChequesApi.create(d.id, { chequeNo: chequeNo.trim(), bankId: Number(bankId), chequeDate, amount })
      setAddOpen(false); setChequeNo(''); setBankId(''); setAmount(''); notify.success('Cheque recorded'); onChanged()
    } catch (ex: any) { notify.error('Record failed', getErrorText(ex)) } finally { setBusy(false) }
  }
  const setStatus = async (c: LoanPostDatedCheque, status: ChequeStatus) => {
    try { await speedDraftPostDatedChequesApi.updateStatus(c.id, status); notify.success(`Cheque marked ${status}`); onChanged() }
    catch (ex: any) { notify.error('Update failed', getErrorText(ex)) }
  }
  const realize = async () => {
    if (!realizing) return
    setBusy(true)
    try {
      await speedDraftPostDatedChequesApi.updateStatus(realizing.id, 'Realized', { realizedAt, paymentType: payType })
      setRealizing(null); notify.success('Cheque realized and applied'); onChanged()
    } catch (ex: any) { notify.error('Realize failed', getErrorText(ex)) } finally { setBusy(false) }
  }
  const remove = async (c: LoanPostDatedCheque) => {
    const ok = await notify.confirm({ title: `Delete cheque ${c.chequeNo}?`, danger: true, okText: 'Delete' })
    if (!ok) return
    try { await speedDraftPostDatedChequesApi.remove(c.id); notify.success('Cheque deleted'); onChanged() }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <Card pad={0}>
      <div className="row between wrap gap-2" style={{ padding: '14px 16px 0' }}>
        <div className="eyebrow">Post Dated Cheques</div>
        <div className="row gap-2" style={{ alignItems: 'center' }}>
          <span className="t-2" style={{ fontSize: 11.5 }}>Realize / return is admin only.</span>
          <Can user={me} module="Speed Draft Payments" action="Create"><Btn size="sm" variant="solid" icon="plus" onClick={() => setAddOpen(true)}>Record Cheque</Btn></Can>
        </div>
      </div>
      <Table cols={[{ label: 'Cheque No' }, { label: 'Bank' }, { label: 'Cheque Date' }, { label: 'Realized' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 150 }]}
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
              <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                {admin && d.status === 'Active' && <button className="mms-act" title="Realize" onClick={() => { setRealizing(c); setRealizedAt(todayIso()); setPayType('Interest') }}><Icon n="check" s={14} /></button>}
                {admin && c.status === 'Pending' && <button className="mms-act" title="Mark handed" onClick={() => setStatus(c, 'Handed')}><Icon n="upload" s={14} /></button>}
                {admin && c.status !== 'Bounced' && <button className="mms-act" title="Mark returned" onClick={() => setStatus(c, 'Bounced')}><Icon n="refund" s={14} /></button>}
                <Can user={me} module="Speed Draft Payments" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(c)}><Icon n="trash" s={14} /></button></Can>
              </div>
            )}
          </Td>
        </>} />

      <Modal open={addOpen} onClose={() => setAddOpen(false)} title="Record Post Dated Cheque" footer={<>
        <Btn variant="solid" onClick={() => setAddOpen(false)}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={record} disabled={busy}>{busy ? 'Saving…' : 'Record'}</Btn>
      </>}>
        <div className="col gap-3">
          <Field label="Cheque No"><Input value={chequeNo} maxLength={20} onChange={(e) => setChequeNo(e.target.value)} /></Field>
          <Field label="Bank"><BankSelect value={bankId} onChange={(v) => setBankId(v ?? '')} /></Field>
          <Field label="Cheque Date"><Input type="date" value={chequeDate} onChange={(e) => setChequeDate(e.target.value)} /></Field>
          <Field label="Amount"><Input type="number" min="0" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        </div>
      </Modal>

      <Modal open={!!realizing} onClose={() => setRealizing(null)} title={`Realize cheque ${realizing?.chequeNo || ''}`} footer={<>
        <Btn variant="solid" onClick={() => setRealizing(null)}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={realize} disabled={busy}>{busy ? 'Applying…' : 'Realize'}</Btn>
      </>}>
        <div className="col gap-3">
          <div className="t-2" style={{ fontSize: 12.5 }}>Amount {money(realizing?.amount)} will be applied to this Speed Draft as a payment.</div>
          <Field label="Cleared On"><Input type="date" value={realizedAt} max={todayIso()} onChange={(e) => setRealizedAt(e.target.value)} /></Field>
          <Field label="Apply To">
            <Select value={payType} onChange={(e) => setPayType(e.target.value as RealizeType)}>
              <option value="Interest">Interest &amp; penalty</option>
              <option value="CapitalReduction">Capital reduction</option>
              <option value="Charges">Charges</option>
            </Select>
          </Field>
        </div>
      </Modal>
    </Card>
  )
}

/* ---------- Diarizing ---------- */
export function SpeedDraftDiaryPanel({ speedDraft: d, me, onChanged }: { speedDraft: SpeedDraft; me?: AuthUser | null; onChanged: () => void }) {
  const notify = useNotify()
  const notes = d.diaryNotes || []
  const [note, setNote] = useState('')
  const [followUpDate, setFollowUpDate] = useState('')
  const [busy, setBusy] = useState(false)

  const add = async () => {
    if (!note.trim()) return
    setBusy(true)
    try {
      await speedDraftDiaryNotesApi.create(d.id, { note: note.trim(), followUpDate: followUpDate || undefined })
      setNote(''); setFollowUpDate(''); notify.success('Note added'); onChanged()
    } catch (ex: any) { notify.error('Add failed', getErrorText(ex)) } finally { setBusy(false) }
  }
  const remove = async (n: LoanDiaryNote) => {
    const ok = await notify.confirm({ title: 'Delete this note?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await speedDraftDiaryNotesApi.remove(n.id); notify.success('Note deleted'); onChanged() }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <Card>
      <div className="eyebrow" style={{ marginBottom: 12 }}>Diarizing</div>
      <Can user={me} module="Speed Drafts" action="Create">
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
              <Can user={me} module="Speed Drafts" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(n)}><Icon n="trash" s={14} /></button></Can>
            </div>
          ))}
        </div>}
    </Card>
  )
}

/* ---------- Contract Images ---------- */
export function SpeedDraftImagesPanel({ speedDraft: d, me }: { speedDraft: SpeedDraft; me?: AuthUser | null }) {
  const notify = useNotify()
  const [docs, setDocs] = useState<LoanDocument[]>(d.documents || [])
  const [categories, setCategories] = useState<DocumentCategory[]>([])
  const [docCategoryId, setDocCategoryId] = useState<number | ''>('')
  const [uploading, setUploading] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)
  const preview = useFilePreview()

  useEffect(() => setDocs(d.documents || []), [d])
  useEffect(() => { documentCategoriesApi.list().then((res) => setCategories(res.filter((c) => c.status === 'Active'))) }, [])

  const refresh = () => speedDraftDocumentsApi.list(d.id).then(setDocs).catch(() => {})

  const pick = async (e: React.ChangeEvent<HTMLInputElement>) => {
    if (!docCategoryId) { notify.error('Please select a document category'); e.target.value = ''; return }
    const files = Array.from(e.target.files || [])
    e.target.value = ''
    if (!files.length) return
    setUploading(true)
    let ok = 0
    const failed: string[] = []
    for (const file of files) {
      try { await speedDraftDocumentsApi.upload(d.id, file, docCategoryId); ok++ }
      catch (ex: any) { failed.push(`${file.name}: ${getErrorText(ex)}`) }
    }
    refresh()
    if (ok) notify.success(ok === 1 ? 'Image uploaded' : `${ok} images uploaded`)
    if (failed.length) notify.error(`${failed.length} upload(s) failed`, failed.join('\n'))
    setUploading(false)
  }
  const view = async (doc: LoanDocument) => {
    try { preview.open(await speedDraftDocumentsApi.download(d.id, doc.id), doc.filePath, doc.category?.name || 'Document') }
    catch (ex: any) { notify.error('Could not open document', getErrorText(ex)) }
  }
  const remove = async (doc: LoanDocument) => {
    const ok = await notify.confirm({ title: 'Delete this document?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await speedDraftDocumentsApi.remove(d.id, doc.id); refresh() }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <Card>
      <div className="eyebrow" style={{ marginBottom: 12 }}>Contract Images</div>
      <Can user={me} module="Speed Drafts" action="Edit">
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
          {docs.map((doc) => (
            <div key={doc.id} className="row between" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
              <div className="row gap-2" style={{ alignItems: 'center' }}>
                <Icon n="doc" s={16} />
                <span style={{ fontSize: 12.5 }}>{doc.category?.name || 'Document'}</span>
              </div>
              <div className="row gap-1">
                <button className="mms-act" title="View" onClick={() => view(doc)}><Icon n="eye" s={15} /></button>
                <Can user={me} module="Speed Drafts" action="Edit"><button className="mms-act" title="Delete" onClick={() => remove(doc)}><Icon n="trash" s={15} /></button></Can>
              </div>
            </div>
          ))}
        </div>}
      {preview.node}
    </Card>
  )
}

/* ---------- Print Documents ----------
   The Loan document templates read a Loan; a Speed Draft carries the same facility
   fields, so it's presented to them as a loan-shaped object (with the full customer
   record, which the draft payload only carries a few columns of). */
export function SpeedDraftPrintPanel({ speedDraft: d, me }: { speedDraft: SpeedDraft; me?: AuthUser | null }) {
  const [customer, setCustomer] = useState<Customer | null>(null)
  useEffect(() => { customersApi.get(d.customerId).then(setCustomer).catch(() => setCustomer(null)) }, [d.customerId])

  const det = d.speedDraftDetail
  const loanLike = {
    ...d,
    customer: customer || d.customer,
    approvedAt: d.approvedAt,
    loanDetail: {
      loanType: det?.speedDraftType ? { name: det.speedDraftType.name, category: det.speedDraftType.category } : undefined,
      installmentFrequency: det?.paymentFrequency,
      vehicleNo: det?.vehicleNo,
      loanOfficer: det?.loanOfficer,
      applicationDate: d.createdAt,
    },
  } as unknown as Loan
  return <PrintDocumentsPanel loan={loanLike} me={me} />
}
