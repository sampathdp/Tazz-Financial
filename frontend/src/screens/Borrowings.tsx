/* Funding / Investment — Borrowings: money the company itself borrows (the
   liability-side counterpart of the customer Fixed Deposits it takes in). No
   certificate is printed — a borrowing is just recorded as a transaction.

   Four types:
     - Party            : a term borrowing from a named lender / finance house.
     - Overdraft        : a sanctioned OD on one of our own Current bank accounts.
                          Registered here; the draw-downs happen as ordinary bank
                          withdrawals that push the account negative (guarded to
                          the overdraft limit). Utilisation shown is live.
     - RePledgedPawning : funds raised by re-pledging pawned articles the company
                          is holding to an outside financier — the specific
                          in-house pawn tickets are listed on the borrowing.
     - Other            : anything else; the free-text Source carries the detail.

   Party / RePledgedPawning / Other post to the GL + Cashbook when Cash-method,
   same convention as FD deposits. Interest and repayments are added as
   transactions from the detail view. */
import { useEffect, useMemo, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Table, Td, Modal, ErrorBanner, LoadingBlock, inputStyle } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  borrowingsApi, branchesApi, cashAccountsApi, bankAccountsApi, pawningsApi,
  type Borrowing, type BorrowingType, type BorrowingStatus, type BorrowingTxnKind,
  type BorrowingCreateInput, type FdPaymentMethod, type Branch, type CashAccount, type BankAccount, type Pawning, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const TYPES: { value: BorrowingType; label: string }[] = [
  { value: 'Party', label: 'Party / Institutional' },
  { value: 'Overdraft', label: 'Bank Overdraft' },
  { value: 'RePledgedPawning', label: 'Re-Pledged Pawning' },
  { value: 'Other', label: 'Other' },
]
const TYPE_LABEL = (t: BorrowingType) => TYPES.find((x) => x.value === t)?.label ?? t
const METHODS: { value: FdPaymentMethod; label: string }[] = [
  { value: 'Cash', label: 'Cash' },
  { value: 'Bank', label: 'Bank Transfer' },
  { value: 'Cheque', label: 'Cheque' },
]
const STATUS_TONE: Record<BorrowingStatus, 'green' | 'neutral' | 'red'> = { Active: 'green', Settled: 'neutral', Void: 'red' }

interface RepledgeRow { pawningId?: number; appraisedValueSnapshot?: string; financierName?: string; externalReference?: string }
interface Form {
  branchId?: number; type: BorrowingType;
  lenderName: string; source: string; purpose: string;
  principalAmount: string; interestRatePercent: string; borrowingDate: string;
  referenceNo: string; notes: string;
  receiptMethod: FdPaymentMethod; receiptCashAccountId?: number; receiptBankAccountId?: number;
  bankAccountId?: number; setOverdraftLimit: boolean;
  repledged: RepledgeRow[];
}
const blank = (branchId?: number): Form => ({
  branchId, type: 'Party', lenderName: '', source: '', purpose: '',
  principalAmount: '', interestRatePercent: '', borrowingDate: todayIso(),
  referenceNo: '', notes: '', receiptMethod: 'Cash', repledged: [{}], setOverdraftLimit: true,
})

export function BorrowingsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<Borrowing[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [cashAccounts, setCashAccounts] = useState<CashAccount[]>([])
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])
  const [pawnings, setPawnings] = useState<Pawning[]>([])
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState<'all' | BorrowingStatus>('all')
  const [modal, setModal] = useState(false)
  const [form, setForm] = useState<Form>(blank())
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [nextNo, setNextNo] = useState('')
  const [detail, setDetail] = useState<Borrowing | null>(null)

  const refresh = () => {
    setLoading(true)
    borrowingsApi.list(tab === 'all' ? undefined : { status: tab }).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [tab]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    cashAccountsApi.list().then(setCashAccounts).catch(() => setCashAccounts([]))
    bankAccountsApi.list().then(setBankAccounts).catch(() => setBankAccounts([]))
    pawningsApi.list('Active').then(setPawnings).catch(() => setPawnings([]))
  }, [])

  useEffect(() => {
    if (!modal || !form.branchId) { setNextNo(''); return }
    let cancelled = false
    borrowingsApi.nextNumber(form.branchId).then((c) => { if (!cancelled) setNextNo(c) }).catch(() => { if (!cancelled) setNextNo('') })
    return () => { cancelled = true }
  }, [modal, form.branchId])

  const branchCash = useMemo(() => cashAccounts.filter((a) => a.branchId === form.branchId && a.status !== 'Inactive'), [cashAccounts, form.branchId])
  const currentBankAccounts = useMemo(() => bankAccounts.filter((a) => a.accountType === 'Current'), [bankAccounts])

  const openCreate = () => { setForm(blank(branches[0]?.id)); setErr(''); setModal(true) }
  const close = () => { setModal(false); setErr('') }

  const save = async () => {
    const f = form
    if (!f.branchId) return setErr('Branch is required.')
    if (!f.lenderName.trim()) return setErr('Party / lender name is required.')
    if (!f.principalAmount || Number(f.principalAmount) <= 0) return setErr('Amount must be greater than zero.')
    const isOd = f.type === 'Overdraft'
    if (isOd && !f.bankAccountId) return setErr('Select the Current account the overdraft is drawn on.')
    if (!isOd && f.receiptMethod === 'Cash' && !f.receiptCashAccountId) return setErr('Select the cash account the money was received into.')
    if (!isOd && f.receiptMethod === 'Bank' && !f.receiptBankAccountId) return setErr('Select the bank account the money was received into.')
    const repledged = f.type === 'RePledgedPawning'
      ? f.repledged.filter((r) => r.pawningId).map((r) => ({
          pawningId: r.pawningId!, appraisedValueSnapshot: r.appraisedValueSnapshot || undefined,
          financierName: r.financierName || undefined, externalReference: r.externalReference || undefined,
        }))
      : undefined
    if (f.type === 'RePledgedPawning' && (!repledged || repledged.length === 0)) return setErr('Add at least one re-pledged pawn ticket.')

    const body: BorrowingCreateInput = {
      branchId: f.branchId, type: f.type,
      lenderName: f.lenderName.trim(), source: f.source.trim() || undefined, purpose: f.purpose.trim() || undefined,
      principalAmount: f.principalAmount, interestRatePercent: f.interestRatePercent || undefined,
      borrowingDate: f.borrowingDate, referenceNo: f.referenceNo.trim() || undefined, notes: f.notes.trim() || undefined,
      ...(isOd
        ? { bankAccountId: f.bankAccountId, setOverdraftLimit: f.setOverdraftLimit }
        : {
            receiptMethod: f.receiptMethod,
            receiptCashAccountId: f.receiptMethod === 'Cash' ? f.receiptCashAccountId : undefined,
            receiptBankAccountId: f.receiptMethod === 'Bank' ? f.receiptBankAccountId : undefined,
          }),
      repledgedItems: repledged,
    }
    setBusy(true); setErr('')
    try {
      const created = await borrowingsApi.create(body)
      close(); refresh()
      notify.success('Borrowing recorded', created.borrowingNumber)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (b: Borrowing) => {
    const ok = await notify.confirm({ title: `Delete borrowing "${b.borrowingNumber}"?`, msg: 'This reverses its ledger and cashbook postings. It cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await borrowingsApi.remove(b.id); refresh(); notify.success('Borrowing deleted', b.borrowingNumber) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const isOd = form.type === 'Overdraft'

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Borrowings" icon="wallet" sub={`${rows.length} borrowings`}
        actions={<Can user={me} module="Borrowings" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Record Borrowing</Btn></Can>} />

      <Card pad={0}>
        <div className="row gap-1" style={{ padding: '14px 16px 0' }}>
          {([['all', 'All'], ['Active', 'Active'], ['Settled', 'Settled'], ['Void', 'Void']] as const).map(([id, label]) => (
            <Btn key={id} size="sm" variant="ghost" active={tab === id} onClick={() => setTab(id)}>{label}</Btn>
          ))}
        </div>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'No.' }, { label: 'Type' }, { label: 'Party / Lender' }, { label: 'Source' }, { label: 'Date' }, { label: 'Borrowed', align: 'right' }, { label: 'Outstanding', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 90 }]}
          rows={rows}
          onRow={(b: Borrowing) => borrowingsApi.get(b.id).then(setDetail).catch(() => setDetail(b))}
          render={(b) => <>
            <Td mono>{b.borrowingNumber}</Td>
            <Td><Badge tone="neutral">{TYPE_LABEL(b.type)}</Badge></Td>
            <Td>{b.lenderName}</Td>
            <Td>{b.source || '—'}</Td>
            <Td>{dateOnly(b.borrowingDate)}</Td>
            <Td align="right" mono>{money(b.principalAmount)}</Td>
            <Td align="right" mono>{money(b.outstandingAmount)}</Td>
            <Td align="center"><Badge tone={STATUS_TONE[b.status]} dot>{b.status}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              {b.status !== 'Void' && (b.transactions?.length ?? 0) === 0 && (
                <Can user={me} module="Borrowings" action="Delete">
                  <button className="mms-act" title="Delete" onClick={(e) => { e.stopPropagation(); remove(b) }}><Icon n="trash" s={15} /></button>
                </Can>
              )}
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={620} title="Record Borrowing"
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Record Borrowing'}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Branch"><Select value={form.branchId ?? ''} onChange={(e) => setForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Borrowing No."><Input value={nextNo || '…'} readOnly disabled style={{ color: 'var(--tx-2)' }} /></Field>

          <Field label="Type"><Select value={form.type} onChange={(e) => setForm((s) => ({ ...s, type: e.target.value as BorrowingType }))}>
            {TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
          </Select></Field>
          <Field label={isOd ? 'Bank (lender)' : 'Party / Lender Name'}>
            <Input value={form.lenderName} onChange={(e) => setForm((s) => ({ ...s, lenderName: e.target.value }))} placeholder={isOd ? 'e.g. Commercial Bank' : 'e.g. ABC Finance PLC'} />
          </Field>

          <Field label="Source" full>
            <Input value={form.source} onChange={(e) => setForm((s) => ({ ...s, source: e.target.value }))}
              placeholder="Exactly where the money came from — e.g. OD on A/C 001-23456, re-pledge to XYZ Pawnbrokers" />
          </Field>
          <Field label="Purpose" full>
            <textarea value={form.purpose} onChange={(e) => setForm((s) => ({ ...s, purpose: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} placeholder="What the borrowed funds are for" />
          </Field>

          <Field label={isOd ? 'Sanctioned OD Limit' : 'Amount Borrowed'}><Input type="number" step="0.01" value={form.principalAmount} onChange={(e) => setForm((s) => ({ ...s, principalAmount: e.target.value }))} /></Field>
          <Field label="Interest Rate % (p.a.)"><Input type="number" step="0.001" value={form.interestRatePercent} onChange={(e) => setForm((s) => ({ ...s, interestRatePercent: e.target.value }))} /></Field>
          <Field label="Date"><DateInput value={form.borrowingDate} onChange={(iso) => setForm((s) => ({ ...s, borrowingDate: iso }))} /></Field>
          <Field label="Reference No."><Input value={form.referenceNo} onChange={(e) => setForm((s) => ({ ...s, referenceNo: e.target.value }))} /></Field>

          {isOd ? <>
            <Field label="Current Account (OD drawn on)" full>
              <Select value={form.bankAccountId ?? ''} onChange={(e) => setForm((s) => ({ ...s, bankAccountId: Number(e.target.value) || undefined }))}>
                <option value="">—</option>
                {currentBankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} · {a.accountNumber}</option>)}
              </Select>
              {currentBankAccounts.length === 0 && <div className="t-2" style={{ fontSize: 12, marginTop: 4 }}>No bank accounts of type “Current” exist yet — add one in Bank Management.</div>}
            </Field>
            <Field label="" full>
              <label className="row gap-1" style={{ fontSize: 13, alignItems: 'center' }}>
                <input type="checkbox" checked={form.setOverdraftLimit} onChange={(e) => setForm((s) => ({ ...s, setOverdraftLimit: e.target.checked }))} />
                Set this as the account’s overdraft limit (allows its balance to go negative up to the limit)
              </label>
            </Field>
            <Field label="" full>
              <div className="t-2" style={{ fontSize: 12 }}>
                The OD facility is registered here. Actual draw-downs and repayments are ordinary bank withdrawals/deposits on the account above — record those in Bank Management. Utilisation shown here tracks that account’s negative balance.
              </div>
            </Field>
          </> : <>
            <Field label="Received By"><Select value={form.receiptMethod} onChange={(e) => setForm((s) => ({ ...s, receiptMethod: e.target.value as FdPaymentMethod }))}>
              {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select></Field>
            {form.receiptMethod === 'Cash' && (
              <Field label="Into Cash Account"><Select value={form.receiptCashAccountId ?? ''} onChange={(e) => setForm((s) => ({ ...s, receiptCashAccountId: Number(e.target.value) || undefined }))}>
                <option value="">—</option>
                {branchCash.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </Select></Field>
            )}
            {form.receiptMethod === 'Bank' && (
              <Field label="Into Bank Account"><Select value={form.receiptBankAccountId ?? ''} onChange={(e) => setForm((s) => ({ ...s, receiptBankAccountId: Number(e.target.value) || undefined }))}>
                <option value="">—</option>
                {bankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} · {a.accountNumber}</option>)}
              </Select></Field>
            )}
            {form.receiptMethod === 'Cheque' && (
              <Field label="" full><div className="t-2" style={{ fontSize: 12 }}>Cheque receipts are recorded only — they don’t post to the ledger/cashbook until banked (same as FD deposits).</div></Field>
            )}
          </>}

          {form.type === 'RePledgedPawning' && (
            <Field label="Re-Pledged Pawn Tickets" full>
              <div className="col gap-1">
                {form.repledged.map((r, i) => (
                  <div key={i} className="row gap-1" style={{ alignItems: 'center' }}>
                    <Select value={r.pawningId ?? ''} onChange={(e) => setForm((s) => { const rp = [...s.repledged]; rp[i] = { ...rp[i], pawningId: Number(e.target.value) || undefined }; return { ...s, repledged: rp } })} style={{ flex: 2 }}>
                      <option value="">Select in-house pawn ticket…</option>
                      {pawnings.map((p) => <option key={p.id} value={p.id}>{p.code} — {money(p.principalAmount)}</option>)}
                    </Select>
                    <Input placeholder="Value" type="number" step="0.01" value={r.appraisedValueSnapshot ?? ''} onChange={(e) => setForm((s) => { const rp = [...s.repledged]; rp[i] = { ...rp[i], appraisedValueSnapshot: e.target.value }; return { ...s, repledged: rp } })} style={{ flex: 1 }} />
                    <Input placeholder="Financier" value={r.financierName ?? ''} onChange={(e) => setForm((s) => { const rp = [...s.repledged]; rp[i] = { ...rp[i], financierName: e.target.value }; return { ...s, repledged: rp } })} style={{ flex: 1 }} />
                    <button className="mms-act" title="Remove" onClick={() => setForm((s) => ({ ...s, repledged: s.repledged.filter((_, j) => j !== i) }))}><Icon n="trash" s={15} /></button>
                  </div>
                ))}
                <Btn size="sm" variant="ghost" icon="plus" onClick={() => setForm((s) => ({ ...s, repledged: [...s.repledged, {}] }))}>Add ticket</Btn>
              </div>
            </Field>
          )}

          <Field label="Notes" full><textarea value={form.notes} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>

      <BorrowingDetail borrowing={detail} me={me} cashAccounts={cashAccounts} bankAccounts={bankAccounts}
        onClose={() => setDetail(null)} onChanged={(b) => { setDetail(b); refresh() }} />
    </div>
  )
}

function BorrowingDetail({ borrowing, me, cashAccounts, bankAccounts, onClose, onChanged }: {
  borrowing: Borrowing | null; me?: AuthUser | null
  cashAccounts: CashAccount[]; bankAccounts: BankAccount[]
  onClose: () => void; onChanged: (b: Borrowing) => void
}) {
  const notify = useNotify()
  const [kind, setKind] = useState<BorrowingTxnKind>('InterestPayment')
  const [amount, setAmount] = useState('')
  const [txnDate, setTxnDate] = useState(todayIso())
  const [method, setMethod] = useState<FdPaymentMethod>('Cash')
  const [cashId, setCashId] = useState<number | undefined>()
  const [bankId, setBankId] = useState<number | undefined>()
  const [ref, setRef] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!borrowing) return
    setKind(borrowing.type === 'Overdraft' ? 'InterestPayment' : 'InterestPayment')
    setAmount(''); setTxnDate(todayIso()); setMethod('Cash'); setCashId(undefined); setBankId(undefined); setRef(''); setErr('')
  }, [borrowing?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!borrowing) return null
  const b = borrowing
  const isOd = b.type === 'Overdraft'
  const branchCash = cashAccounts.filter((a) => a.branchId === b.branchId && a.status !== 'Inactive')
  const kinds: BorrowingTxnKind[] = isOd
    ? ['InterestPayment']
    : ['InterestPayment', 'PrincipalRepayment', 'Drawdown', 'Settlement']

  const addTxn = async () => {
    if (!amount || Number(amount) <= 0) return setErr('Amount must be greater than zero.')
    if (method === 'Cash' && !cashId) return setErr('Select a cash account.')
    if (method === 'Bank' && !bankId) return setErr('Select a bank account.')
    setBusy(true); setErr('')
    try {
      const updated = await borrowingsApi.addTransaction(b.id, {
        kind, amount, txnDate, paymentMethod: method,
        cashAccountId: method === 'Cash' ? cashId : undefined,
        bankAccountId: method === 'Bank' ? bankId : undefined,
        referenceNo: ref.trim() || undefined,
      })
      onChanged(updated); setAmount(''); setRef('')
      notify.success('Transaction recorded')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Failed'))
    } finally { setBusy(false) }
  }

  const voidTxn = async (txnId: number) => {
    const ok = await notify.confirm({ title: 'Void this transaction?', msg: 'Only the most recent transaction can be voided. Its ledger and cashbook postings are reversed.', danger: true, okText: 'Void' })
    if (!ok) return
    try { onChanged(await borrowingsApi.voidTransaction(txnId)); notify.success('Transaction voided') }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  const txns = b.transactions ?? []

  return (
    <Modal open onClose={onClose} width={720} title={`${b.borrowingNumber} — ${TYPE_LABEL(b.type)}`}>
      <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
        <div className="row between"><span className="t-2">Party / Lender</span><b>{b.lenderName}</b></div>
        <div className="row between"><span className="t-2">Source</span><b>{b.source || '—'}</b></div>
        {b.purpose && <div className="row between"><span className="t-2">Purpose</span><b style={{ maxWidth: 380, textAlign: 'right' }}>{b.purpose}</b></div>}
        <div className="row between"><span className="t-2">{isOd ? 'Sanctioned Limit' : 'Amount Borrowed'}</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(b.principalAmount)}</b></div>
        <div className="row between"><span className="t-2">{isOd ? 'Overdraft Utilised (live)' : 'Outstanding'}</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(b.outstandingAmount)}</b></div>
        {b.interestRatePercent != null && <div className="row between"><span className="t-2">Interest Rate</span><b>{b.interestRatePercent}% p.a.</b></div>}
        {isOd && b.bankAccount && (
          <div className="row between"><span className="t-2">Current Account</span><b>{b.bankAccount.name} · bal {money(b.bankAccount.currentBalance)}{b.bankAccount.overdraftLimit != null ? ` · limit ${money(b.bankAccount.overdraftLimit)}` : ''}</b></div>
        )}
        <div className="row between"><span className="t-2">Status</span><Badge tone={STATUS_TONE[b.status]} dot>{b.status}</Badge></div>
      </div>

      {b.repledgedItems && b.repledgedItems.length > 0 && (
        <div style={{ marginBottom: 16 }}>
          <div className="t-2" style={{ fontSize: 12, marginBottom: 6 }}>RE-PLEDGED PAWN TICKETS</div>
          <Table cols={[{ label: 'Ticket' }, { label: 'Financier' }, { label: 'Ext. Ref' }, { label: 'Value', align: 'right' }]}
            rows={b.repledgedItems}
            render={(it) => <>
              <Td mono>{it.pawning?.code ?? `#${it.pawningId}`}</Td>
              <Td>{it.financierName || '—'}</Td>
              <Td>{it.externalReference || '—'}</Td>
              <Td align="right" mono>{it.appraisedValueSnapshot != null ? money(it.appraisedValueSnapshot) : '—'}</Td>
            </>} />
        </div>
      )}

      <div className="t-2" style={{ fontSize: 12, marginBottom: 6 }}>TRANSACTIONS</div>
      {txns.length === 0
        ? <div className="t-2" style={{ fontSize: 13, marginBottom: 14 }}>No transactions recorded yet.</div>
        : <Table cols={[{ label: 'Voucher' }, { label: 'Kind' }, { label: 'Date' }, { label: 'Method' }, { label: 'Amount', align: 'right' }, { label: '', align: 'right', w: 60 }]}
            rows={txns}
            render={(t, idx) => <>
              <Td mono>{t.voucherNo}</Td>
              <Td>{t.kind}</Td>
              <Td>{dateOnly(t.txnDate)}</Td>
              <Td><Badge tone="neutral">{t.paymentMethod}</Badge></Td>
              <Td align="right" mono>{money(t.amount)}</Td>
              <Td align="right">{idx === 0 && (
                <Can user={me} module="Borrowings" action="Delete">
                  <button className="mms-act" title="Void" onClick={() => voidTxn(t.id)}><Icon n="x" s={15} /></button>
                </Can>
              )}</Td>
            </>} />}

      {b.status !== 'Void' && (
        <Can user={me} module="Borrowings" action="Edit">
          <div style={{ marginTop: 16, paddingTop: 16, borderTop: '1px solid var(--line)' }}>
            <div className="t-2" style={{ fontSize: 12, marginBottom: 8 }}>ADD TRANSACTION</div>
            <ErrorBanner error={err} style={{ marginBottom: 10 }} />
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12 }}>
              <Field label="Kind"><Select value={kind} onChange={(e) => setKind(e.target.value as BorrowingTxnKind)}>
                {kinds.map((k) => <option key={k} value={k}>{k}</option>)}
              </Select></Field>
              <Field label="Amount"><Input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
              <Field label="Date"><DateInput value={txnDate} onChange={setTxnDate} /></Field>
              <Field label="Method"><Select value={method} onChange={(e) => setMethod(e.target.value as FdPaymentMethod)}>
                {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
              </Select></Field>
              {method === 'Cash' && (
                <Field label="Cash Account"><Select value={cashId ?? ''} onChange={(e) => setCashId(Number(e.target.value) || undefined)}>
                  <option value="">—</option>
                  {branchCash.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </Select></Field>
              )}
              {method === 'Bank' && (
                <Field label="Bank Account"><Select value={bankId ?? ''} onChange={(e) => setBankId(Number(e.target.value) || undefined)}>
                  <option value="">—</option>
                  {bankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </Select></Field>
              )}
              <Field label="Reference No."><Input value={ref} onChange={(e) => setRef(e.target.value)} /></Field>
            </div>
            {isOd && <div className="t-2" style={{ fontSize: 12, marginTop: 6 }}>On an overdraft, only interest paid to the bank is recorded here. Principal draw-downs/repayments are bank transactions on the linked account.</div>}
            <div className="row" style={{ justifyContent: 'flex-end', marginTop: 12 }}>
              <Btn variant="primary" icon="check" onClick={addTxn} disabled={busy}>{busy ? 'Saving…' : 'Add Transaction'}</Btn>
            </div>
          </div>
        </Can>
      )}
    </Modal>
  )
}

export default BorrowingsScreen
