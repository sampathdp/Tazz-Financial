/* Joint holders, nominees and manual commission for Fixed Deposits — shared by the Create
   and Renew modals, plus the pay-commission modal used on the FD detail page. */
import { useEffect, useState } from 'react'
import { Btn, Field, Input, DateInput, Select, SearchSelect, Modal, ErrorBanner } from './ui'
import {
  customersApi, staffApi, cashAccountsApi, fdCommissionsApi,
  type Customer, type StaffOption, type CashAccount, type PaymentMethod,
  type FdCommission, type FdCommissionInput, type FdCommissionRecipient, type FdCommissionType,
  type FdJointHolderInput, type FdNomineeInput, type FdOperatingInstruction,
} from '../api'
import { BankAccountSelect } from './ui'
import { useNotify } from './Notify'
import { getErrorText } from '../lib/apiError'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const todayIso = () => new Date().toISOString().slice(0, 10)
const box = { background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: 14 } as const

/* ------------------------------ Commission ------------------------------ */

export type CommissionState = {
  on: boolean; recipientType: FdCommissionRecipient; staffId: number | ''; name: string; phone: string
  type: FdCommissionType; value: string; notes: string
}
export const noCommission: CommissionState = { on: false, recipientType: 'staff', staffId: '', name: '', phone: '', type: 'percentage', value: '', notes: '' }

/** Returns the API payload, or an error message when the commission block is ticked but incomplete. */
export function commissionBody(c: CommissionState): { body?: FdCommissionInput; error?: string } {
  if (!c.on) return {}
  if (c.recipientType === 'staff' && !c.staffId) return { error: 'Select the executive who receives the commission.' }
  if (c.recipientType === 'outsider' && !c.name.trim()) return { error: "Enter the outsider's name for the commission." }
  if (!c.value || Number(c.value) <= 0) return { error: 'Enter the commission rate or amount.' }
  return {
    body: {
      recipientType: c.recipientType,
      staffId: c.recipientType === 'staff' ? Number(c.staffId) : undefined,
      recipientName: c.recipientType === 'outsider' ? c.name.trim() : undefined,
      recipientPhone: c.recipientType === 'outsider' && c.phone.trim() ? c.phone.trim() : undefined,
      commissionType: c.type, commissionValue: c.value, notes: c.notes.trim() || undefined,
    },
  }
}

export function CommissionFields({ value, onChange, principal }: { value: CommissionState; onChange: (v: CommissionState) => void; principal?: number }) {
  const [staff, setStaff] = useState<StaffOption[]>([])
  useEffect(() => { if (value.on && staff.length === 0) staffApi.options().then(setStaff).catch(() => setStaff([])) }, [value.on]) // eslint-disable-line react-hooks/exhaustive-deps
  const set = (patch: Partial<CommissionState>) => onChange({ ...value, ...patch })
  const amount = principal && value.value ? (value.type === 'percentage' ? principal * Number(value.value) / 100 : Number(value.value)) : null

  return (
    <div className="col gap-2">
      <label className="row gap-2" style={{ fontSize: 12.5 }}>
        <input type="checkbox" checked={value.on} onChange={(e) => set({ on: e.target.checked })} /> Give a commission on this FD (paid manually later)
      </label>
      {value.on && (
        <div style={box} className="col gap-3">
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Paid To"><Select value={value.recipientType} onChange={(e) => set({ recipientType: e.target.value as FdCommissionRecipient })}>
              <option value="staff">Executive (staff)</option>
              <option value="outsider">Outsider</option>
            </Select></Field>
            {value.recipientType === 'staff' ? (
              <Field label="Executive"><Select value={value.staffId} onChange={(e) => set({ staffId: Number(e.target.value) || '' })}>
                <option value="">—</option>
                {staff.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.staffCode})</option>)}
              </Select></Field>
            ) : (
              <Field label="Name"><Input value={value.name} onChange={(e) => set({ name: e.target.value })} /></Field>
            )}
            {value.recipientType === 'outsider' && <Field label="Phone"><Input value={value.phone} onChange={(e) => set({ phone: e.target.value })} /></Field>}
            <Field label="Commission Type"><Select value={value.type} onChange={(e) => set({ type: e.target.value as FdCommissionType })}>
              <option value="percentage">Percentage of principal</option>
              <option value="fixed">Fixed amount</option>
            </Select></Field>
            <Field label={value.type === 'percentage' ? 'Rate %' : 'Amount'}><Input type="number" step="0.01" value={value.value} onChange={(e) => set({ value: e.target.value })} /></Field>
            <Field label="Note" full><Input value={value.notes} onChange={(e) => set({ notes: e.target.value })} /></Field>
          </div>
          {amount !== null && <div className="row between" style={{ fontSize: 12.5 }}><span className="t-2">Commission amount</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(amount)}</b></div>}
        </div>
      )}
    </div>
  )
}

/* ----------------------------- Joint holders ---------------------------- */

export type JointHolderRow = { customerId: number; label: string; relationship: string; instruction: FdOperatingInstruction }

export const jointHoldersBody = (rows: JointHolderRow[]): FdJointHolderInput[] | undefined =>
  rows.length ? rows.map((r) => ({ customerId: r.customerId, relationship: r.relationship.trim() || undefined, operatingInstruction: r.instruction })) : undefined

export function JointHolderFields({ rows, onChange, primaryCustomerId }: { rows: JointHolderRow[]; onChange: (r: JointHolderRow[]) => void; primaryCustomerId?: number }) {
  const [customers, setCustomers] = useState<Customer[]>([])
  const [adding, setAdding] = useState(false)
  const [search, setSearch] = useState('')
  useEffect(() => { if (adding && customers.length === 0) customersApi.list().then(setCustomers).catch(() => setCustomers([])) }, [adding]) // eslint-disable-line react-hooks/exhaustive-deps

  const options = customers.filter((c) => c.id !== primaryCustomerId && !rows.some((r) => r.customerId === c.id))
  const patch = (i: number, p: Partial<JointHolderRow>) => onChange(rows.map((r, k) => (k === i ? { ...r, ...p } : r)))

  return (
    <div className="col gap-2">
      <div className="row between">
        <span className="eyebrow">Joint Holders</span>
        {!adding && rows.length < 5 && <Btn size="sm" variant="ghost" icon="plus" onClick={() => setAdding(true)}>Add joint holder</Btn>}
      </div>
      {rows.map((r, i) => (
        <div key={r.customerId} style={box} className="col gap-2">
          <div className="row between"><b style={{ fontSize: 13 }}>{r.label}</b><Btn size="sm" variant="plain" icon="x" onClick={() => onChange(rows.filter((_, k) => k !== i))}>Remove</Btn></div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Relationship"><Input value={r.relationship} onChange={(e) => patch(i, { relationship: e.target.value })} placeholder="Spouse, Son…" /></Field>
            <Field label="Operating Instruction"><Select value={r.instruction} onChange={(e) => patch(i, { instruction: e.target.value as FdOperatingInstruction })}>
              <option value="either_or_survivor">Either or Survivor</option>
              <option value="both_to_sign">Both to sign</option>
            </Select></Field>
          </div>
        </div>
      ))}
      {adding && (
        <SearchSelect value={search} autoFocus
          onChange={(name, opt: Customer | undefined) => {
            if (opt) { onChange([...rows, { customerId: opt.id, label: `${opt.fullName} (${opt.code})`, relationship: '', instruction: 'either_or_survivor' }]); setAdding(false); setSearch('') }
            else setSearch(name)
          }}
          options={options} getLabel={(c: Customer) => `${c.fullName} — ${c.code}`} getSub={(c: Customer) => c.nicNo || ''}
          placeholder="Search customer to add as joint holder…" />
      )}
    </div>
  )
}

/* -------------------------------- Nominees ------------------------------ */

export type NomineeRow = {
  fullName: string; relationship: string; nicNo: string; dateOfBirth: string; address: string; phone: string
  sharePercent: string; guardianName: string; guardianNicNo: string
}
const blankNominee = (share = ''): NomineeRow => ({ fullName: '', relationship: '', nicNo: '', dateOfBirth: '', address: '', phone: '', sharePercent: share, guardianName: '', guardianNicNo: '' })
const isMinor = (dob: string) => {
  if (!dob) return false
  const d = new Date(dob); const t = new Date()
  const age = t.getFullYear() - d.getFullYear() - (t < new Date(t.getFullYear(), d.getMonth(), d.getDate()) ? 1 : 0)
  return age < 18
}

/** Returns the API payload, or an error message when the nominee rows are incomplete. */
export function nomineesBody(rows: NomineeRow[]): { body?: FdNomineeInput[]; error?: string } {
  if (!rows.length) return {}
  for (const r of rows) {
    if (!r.fullName.trim()) return { error: 'Enter the name of every nominee.' }
    if (isMinor(r.dateOfBirth) && !r.guardianName.trim()) return { error: `${r.fullName} is under 18 — enter the guardian's name.` }
  }
  if (rows.length > 1) {
    const total = rows.reduce((a, r) => a + Number(r.sharePercent || 0), 0)
    if (Math.abs(total - 100) > 0.01) return { error: 'Nominee shares must add up to 100%.' }
  }
  const clean = (v: string) => v.trim() || undefined
  return {
    body: rows.map((r) => ({
      fullName: r.fullName.trim(), relationship: clean(r.relationship), nicNo: clean(r.nicNo), dateOfBirth: r.dateOfBirth || undefined,
      address: clean(r.address), phone: clean(r.phone), sharePercent: rows.length > 1 ? r.sharePercent : undefined,
      guardianName: clean(r.guardianName), guardianNicNo: clean(r.guardianNicNo),
    })),
  }
}

export function NomineeFields({ rows, onChange }: { rows: NomineeRow[]; onChange: (r: NomineeRow[]) => void }) {
  const patch = (i: number, p: Partial<NomineeRow>) => onChange(rows.map((r, k) => (k === i ? { ...r, ...p } : r)))
  return (
    <div className="col gap-2">
      <div className="row between">
        <span className="eyebrow">Nominees</span>
        {rows.length < 5 && <Btn size="sm" variant="ghost" icon="plus" onClick={() => onChange([...rows, blankNominee(rows.length ? '' : '100')])}>Add nominee</Btn>}
      </div>
      {rows.map((r, i) => (
        <div key={i} style={box} className="col gap-3">
          <div className="row between"><b style={{ fontSize: 13 }}>Nominee {i + 1}</b><Btn size="sm" variant="plain" icon="x" onClick={() => onChange(rows.filter((_, k) => k !== i))}>Remove</Btn></div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Full Name"><Input value={r.fullName} onChange={(e) => patch(i, { fullName: e.target.value })} /></Field>
            <Field label="Relationship"><Input value={r.relationship} onChange={(e) => patch(i, { relationship: e.target.value })} /></Field>
            <Field label="NIC / ID No"><Input value={r.nicNo} onChange={(e) => patch(i, { nicNo: e.target.value })} /></Field>
            <Field label="Date of Birth"><DateInput value={r.dateOfBirth} onChange={(v) => patch(i, { dateOfBirth: v })} /></Field>
            <Field label="Phone"><Input value={r.phone} onChange={(e) => patch(i, { phone: e.target.value })} /></Field>
            {rows.length > 1 && <Field label="Share %"><Input type="number" step="0.01" value={r.sharePercent} onChange={(e) => patch(i, { sharePercent: e.target.value })} /></Field>}
            <Field label="Address" full><Input value={r.address} onChange={(e) => patch(i, { address: e.target.value })} /></Field>
            {isMinor(r.dateOfBirth) && <>
              <Field label="Guardian Name"><Input value={r.guardianName} onChange={(e) => patch(i, { guardianName: e.target.value })} /></Field>
              <Field label="Guardian NIC"><Input value={r.guardianNicNo} onChange={(e) => patch(i, { guardianNicNo: e.target.value })} /></Field>
            </>}
          </div>
        </div>
      ))}
    </div>
  )
}

/* --------------------------- Pay commission modal ------------------------ */

export function PayFdCommissionModal({ commission, open, onClose, onPaid }: { commission: FdCommission | null; open: boolean; onClose: () => void; onPaid: () => void }) {
  const notify = useNotify()
  const [date, setDate] = useState(todayIso())
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<PaymentMethod>('Cash')
  const [cashAccountId, setCashAccountId] = useState<number | ''>('')
  const [bankAccountId, setBankAccountId] = useState<number | undefined>(undefined)
  const [refNo, setRefNo] = useState('')
  const [notes, setNotes] = useState('')
  const [tills, setTills] = useState<CashAccount[]>([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open || !commission) return
    setDate(todayIso()); setAmount(String(commission.commissionAmount)); setMethod('Cash'); setCashAccountId(''); setBankAccountId(undefined); setRefNo(''); setNotes(''); setErr('')
    cashAccountsApi.list().then(setTills).catch(() => setTills([]))
  }, [open, commission])
  if (!commission) return null

  const save = async () => {
    if (!date) { setErr('Payment date is required.'); return }
    if (!amount || Number(amount) <= 0) { setErr('Payment amount must be greater than zero.'); return }
    if (method === 'Cash' && !cashAccountId) { setErr('Pick which till this was paid from.'); return }
    if (method !== 'Cash' && !bankAccountId) { setErr('Pick which bank account this was paid from.'); return }
    if (method === 'Cheque' && !refNo.trim()) { setErr('Cheque number is required.'); return }
    setBusy(true); setErr('')
    try {
      await fdCommissionsApi.pay(commission.id, {
        paymentDate: date, paymentAmount: amount, paymentMethod: method,
        cashAccountId: method === 'Cash' ? Number(cashAccountId) : undefined, bankAccountId: method !== 'Cash' ? bankAccountId : undefined,
        referenceNo: refNo.trim() || undefined, notes: notes.trim() || undefined,
      })
      notify.success('Commission paid', commission.recipientName)
      onPaid()
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Payment failed'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={480} title={`Pay Commission — ${commission.recipientName}`} sub={`Commission ${money(commission.commissionAmount)}`}
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Paying...' : 'Pay Commission'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <Field label="Payment Date"><DateInput value={date} onChange={setDate} /></Field>
        <Field label="Amount"><Input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        <Field label="Method"><Select value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
          <option value="Cash">Cash</option><option value="Bank">Bank Transfer</option><option value="Cheque">Cheque</option>
        </Select></Field>
        {method === 'Cash'
          ? <Field label="Cash Till"><Select value={cashAccountId} onChange={(e) => setCashAccountId(Number(e.target.value) || '')}>
              <option value="">—</option>
              {tills.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </Select></Field>
          : <Field label="Paid from — company bank account"><BankAccountSelect value={bankAccountId} onChange={setBankAccountId} /></Field>}
        {method !== 'Cash' && <Field label={method === 'Cheque' ? 'Cheque No' : 'Reference No'}><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>}
        <Field label="Notes" full><Input value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
      </div>
    </Modal>
  )
}
