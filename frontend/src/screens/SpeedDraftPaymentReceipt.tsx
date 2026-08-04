/* Tazz Investment & Credit — Speed Draft Payment Receipt: standalone payment intake.
   Look up any active Speed Draft, then record one of three distinct actions —
   Pay Interest, Make Capital Payment, or Settle Full Balance — each posted through
   speedDraftsApi/{id}/payments with a payment_type so interest and capital
   reductions stay separately trackable in history (see SpeedDraftPaymentAllocator). */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, Table, Td, Modal, ErrorBanner } from '../components/ui'
import { Icon } from '../components/Icon'
import { speedDraftsApi, speedDraftPaymentsApi, company, type SpeedDraft, type SpeedDraftPayment, type SpeedDraftSchedule, type SpeedDraftPaymentType, type PaymentMethod, type AuthUser } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const METHOD_OPTIONS: { value: PaymentMethod; label: string }[] = [
  { value: 'Cash', label: 'Cash' },
  { value: 'Bank', label: 'Bank Transfer' },
  { value: 'Cheque', label: 'Cheque' },
]
const PAYMENT_TYPE_LABEL: Record<SpeedDraftPaymentType, string> = { Interest: 'Interest', CapitalReduction: 'Capital Reduction', Settlement: 'Full Settlement' }
const scheduleTone: Record<string, 'green' | 'amber' | 'neutral'> = { Paid: 'green', Partial: 'amber', Pending: 'neutral' }

function PaymentStatusBadge({ p }: { p: SpeedDraftPayment }) {
  if (p.status === 'Voided') return <Badge tone="red" dot>Voided</Badge>
  return <Badge tone="green" dot>Recorded</Badge>
}

export function SpeedDraftPaymentReceiptScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [drafts, setDrafts] = useState<SpeedDraft[]>([])
  const [loading, setLoading] = useState(true)
  const [brand, setBrand] = useState({ name: '', tagline: '' })

  const [selected, setSelected] = useState<SpeedDraft | null>(null)
  const [loadingDraft, setLoadingDraft] = useState(false)

  // Pay Interest / Make Capital Payment share one modal, distinguished by `payType`.
  const [payOpen, setPayOpen] = useState(false)
  const [payType, setPayType] = useState<SpeedDraftPaymentType>('Interest')
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<PaymentMethod>('Cash')
  const [paidAt, setPaidAt] = useState(todayIso())
  const [receiptNo, setReceiptNo] = useState('')
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const [settleOpen, setSettleOpen] = useState(false)
  const [settleMethod, setSettleMethod] = useState<PaymentMethod>('Cash')
  const [settlePaidAt, setSettlePaidAt] = useState(todayIso())
  const [settleReceiptNo, setSettleReceiptNo] = useState('')
  const [settleNotes, setSettleNotes] = useState('')
  const [settleBusy, setSettleBusy] = useState(false)
  const [settleErr, setSettleErr] = useState('')

  const [nextReceipt, setNextReceipt] = useState('')
  const [panelTab, setPanelTab] = useState<'history' | 'schedule'>('history')
  const [scheduleTab, setScheduleTab] = useState<'all' | 'paid' | 'unpaid'>('unpaid')
  const [viewPayment, setViewPayment] = useState<SpeedDraftPayment | null>(null)
  const [receipt, setReceipt] = useState<{ payment: SpeedDraftPayment; draft: SpeedDraft } | null>(null)

  const refresh = () => {
    setLoading(true)
    speedDraftsApi.list({ status: 'Active' }).then(setDrafts).catch(() => setDrafts([])).finally(() => setLoading(false))
  }
  useEffect(() => {
    refresh()
    company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {})
  }, [])

  useEffect(() => {
    const activeType = payOpen ? payType : settleOpen ? 'Settlement' : null
    if (!selected || !activeType) { setNextReceipt(''); return }
    let cancelled = false
    speedDraftPaymentsApi.nextReceiptNo(selected.id, activeType).then((code) => { if (!cancelled) setNextReceipt(code) }).catch(() => { if (!cancelled) setNextReceipt('') })
    return () => { cancelled = true }
  }, [selected, payOpen, payType, settleOpen])

  const summary = selected?.summary
  const schedules = (selected?.schedules || []).slice().sort((a, b) => a.installmentNo - b.installmentNo)
  const paymentHistory = (selected?.payments || []).slice().sort((a, b) => new Date(b.paidAt).getTime() - new Date(a.paidAt).getTime())
  const scheduleRows = scheduleTab === 'paid' ? schedules.filter((s) => s.status === 'Paid')
    : scheduleTab === 'unpaid' ? schedules.filter((s) => s.status !== 'Paid')
    : schedules

  const resetPayForm = () => { setPayOpen(false); setAmount(''); setMethod('Cash'); setPaidAt(todayIso()); setReceiptNo(''); setNotes(''); setErr('') }
  const resetSettleForm = () => { setSettleOpen(false); setSettleMethod('Cash'); setSettlePaidAt(todayIso()); setSettleReceiptNo(''); setSettleNotes(''); setSettleErr('') }

  const pickDraft = async (_n: string, opt?: SpeedDraft) => {
    resetPayForm(); resetSettleForm(); setScheduleTab('unpaid'); setPanelTab('history')
    if (!opt) { setSelected(null); return }
    setLoadingDraft(true)
    try { setSelected(await speedDraftsApi.get(opt.id)) }
    catch { setSelected(opt) }
    finally { setLoadingDraft(false) }
  }

  const reloadSelected = async () => {
    if (!selected) return
    try { setSelected(await speedDraftsApi.get(selected.id)) } catch { /* keep stale copy */ }
  }

  const openPay = (type: SpeedDraftPaymentType) => { setPayType(type); setPayOpen(true); setAmount(''); setMethod('Cash'); setPaidAt(todayIso()); setReceiptNo(''); setNotes(''); setErr('') }

  const record = async () => {
    if (!selected) return
    if (!amount || Number(amount) <= 0) { setErr('Enter a payment amount.'); return }
    setBusy(true); setErr('')
    try {
      const payment = await speedDraftPaymentsApi.create(selected.id, {
        amount, paymentType: payType, paymentMethod: method, paidAt, receiptNo: receiptNo || undefined, notes: notes || undefined,
      })
      const fresh = await speedDraftsApi.get(selected.id)
      setReceipt({ payment, draft: fresh })
      refresh()
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Payment failed'))
    } finally { setBusy(false) }
  }

  const submitSettlement = async () => {
    if (!selected) return
    setSettleBusy(true); setSettleErr('')
    try {
      const payment = await speedDraftPaymentsApi.create(selected.id, {
        amount: summary?.amountToSettleToday || 0, paymentType: 'Settlement', paymentMethod: settleMethod,
        paidAt: settlePaidAt, receiptNo: settleReceiptNo || undefined, notes: settleNotes || undefined,
      })
      const fresh = await speedDraftsApi.get(selected.id)
      setReceipt({ payment, draft: fresh })
      refresh()
    } catch (ex: any) {
      setSettleErr(getErrorText(ex, 'Settlement failed'))
    } finally { setSettleBusy(false) }
  }

  const startOver = () => { setReceipt(null); setSelected(null); resetPayForm(); resetSettleForm() }

  if (receipt) {
    const { payment, draft } = receipt
    return (
      <div>
        <div className="row between wrap gap-2 no-print" style={{ marginBottom: 16 }}>
          <PageHead crumbs="Speed Drafts" title="Payment Receipt" icon="receipt"
            sub={payment.paymentType === 'Settlement' ? 'Settlement recorded — draft closed. Print or issue the receipt below.' : 'Payment recorded — print or issue the receipt below.'} />
        </div>
        <div className="row gap-2 no-print" style={{ marginBottom: 16 }}>
          <Btn variant="primary" icon="print" onClick={() => window.print()}>Print Receipt</Btn>
          <Btn variant="solid" icon="plus" onClick={startOver}>New Payment</Btn>
        </div>

        <Card style={{ maxWidth: 560, margin: '0 auto', fontFamily: 'Outfit' }}>
          <div className="col gap-1" style={{ textAlign: 'center', marginBottom: 18, paddingBottom: 18, borderBottom: '1px dashed var(--line)' }}>
            <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 20 }}>{brand.name || 'Company'}</div>
            {brand.tagline && <div className="t-2" style={{ fontSize: 12 }}>{brand.tagline}</div>}
            <div style={{ fontWeight: 700, fontSize: 14, letterSpacing: '.08em', marginTop: 8 }}>{PAYMENT_TYPE_LABEL[payment.paymentType].toUpperCase()} RECEIPT</div>
          </div>

          <div className="row between" style={{ marginBottom: 14, fontSize: 13 }}>
            <div><span className="t-2">Receipt No: </span><b>{payment.code}</b></div>
            <div><span className="t-2">Date: </span><b>{dateOnly(payment.paidAt)}</b></div>
          </div>

          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
            <div className="row between"><span className="t-2">Received From</span><b>{draft.customer?.fullName}</b></div>
            <div className="row between"><span className="t-2">NIC No</span><b>{draft.customer?.nicNo || '—'}</b></div>
            <div className="row between"><span className="t-2">Draft No</span><b>{draft.code}</b></div>
            <div className="row between"><span className="t-2">Product</span><b>{draft.speedDraftDetail?.speedDraftType?.name || '—'}</b></div>
            {payment.paymentType === 'Settlement' && <div className="row between"><span className="t-2">Draft Status</span><b>Closed</b></div>}
          </div>

          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
            <div className="row between"><span className="t-2">Payment Type</span><b>{PAYMENT_TYPE_LABEL[payment.paymentType]}</b></div>
            <div className="row between"><span className="t-2">Payment Method</span><b>{METHOD_OPTIONS.find((m) => m.value === payment.paymentMethod)?.label || payment.paymentMethod}</b></div>
            {payment.receiptNo && <div className="row between"><span className="t-2">Reference No</span><b>{payment.receiptNo}</b></div>}
            {payment.notes && <div className="row between"><span className="t-2">Notes</span><b>{payment.notes}</b></div>}
          </div>

          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 20 }}>
            <div className="row between"><span className="t-2">Amount Paid</span><span style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(payment.amount)}</span></div>
            <div className="row between" style={{ fontSize: 15, fontWeight: 700, paddingTop: 6, borderTop: '1px solid var(--line)' }}>
              <span>Outstanding Capital</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(draft.summary?.outstandingCapital ?? draft.outstandingBalanceCache)}</span>
            </div>
          </div>

          <div className="row between" style={{ fontSize: 12, marginTop: 30 }}>
            <div>Received By: <b>{payment.receiver?.name || me?.name || '—'}</b></div>
            <div>Signature: ______________</div>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Speed Drafts" title="Payment Receipt" icon="receipt"
        sub="Look up an active Speed Draft, then pay interest, make a capital payment, or settle the full balance." />

      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Find Speed Draft</div>
        <SearchSelect
          value={selected ? `${selected.code} — ${selected.customer?.fullName}` : ''}
          onChange={(n, opt) => pickDraft(n, opt)}
          options={drafts}
          getLabel={(d: SpeedDraft) => `${d.code} — ${d.customer?.fullName}`}
          getSub={(d: SpeedDraft) => [d.customer?.nicNo, d.customer?.phone].filter(Boolean).join(' · ')}
          placeholder={loading ? 'Loading Speed Drafts…' : 'Search by NIC, Draft No, or customer name…'}
          disabled={loading}
        />
      </Card>

      {loadingDraft && <div style={{ padding: 30, textAlign: 'center', color: 'var(--tx-2)' }}>Loading Speed Draft…</div>}

      {selected && !loadingDraft && summary && (
        <>
          <Card style={{ marginBottom: 16 }}>
            <div className="row between wrap gap-2" style={{ marginBottom: 10 }}>
              <div className="eyebrow">Speed Draft Summary</div>
              <Can user={me} module="Speed Draft Payments" action="Create">
                <div className="row gap-2">
                  {summary.outstandingInterest > 0 && <Btn variant="primary" size="sm" icon="check" onClick={() => openPay('Interest')}>Pay Interest</Btn>}
                  {summary.outstandingCapital > 0 && <Btn variant="solid" size="sm" icon="coins" onClick={() => openPay('CapitalReduction')}>Make Capital Payment</Btn>}
                  {summary.amountToSettleToday > 0 && <Btn variant="solid" size="sm" icon="flag" onClick={() => setSettleOpen(true)}>Settle Full Balance</Btn>}
                </div>
              </Can>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '10px 20px', fontSize: 12.5 }}>
              <div><span className="t-2">Customer: </span><b>{selected.customer?.fullName}</b></div>
              <div><span className="t-2">NIC: </span><b>{selected.customer?.nicNo || '—'}</b></div>
              <div><span className="t-2">Draft Code: </span><b>{selected.code}</b></div>
              <div><span className="t-2">Product: </span><b>{selected.speedDraftDetail?.speedDraftType?.name || '—'}</b></div>
              <div><span className="t-2">Outstanding Capital: </span><b style={{ fontFamily: 'JetBrains Mono', color: summary.outstandingCapital > 0 ? 'var(--bad)' : 'var(--ok)' }}>{money(summary.outstandingCapital)}</b></div>
              <div><span className="t-2">Outstanding Interest: </span><b style={{ fontFamily: 'JetBrains Mono', color: summary.outstandingInterest > 0 ? 'var(--bad)' : 'var(--ok)' }}>{money(summary.outstandingInterest)}</b></div>
              <div><span className="t-2">Next Due: </span><b>{summary.nextDueDate ? `${dateOnly(summary.nextDueDate)} — ${money(summary.nextDueAmount)}` : '—'}</b></div>
              <div><span className="t-2">Amount to Settle Today: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(summary.amountToSettleToday)}</b></div>
            </div>
          </Card>

          <div className="row gap-2" style={{ marginBottom: 16, borderBottom: '1px solid var(--line)', paddingBottom: 4 }}>
            <Btn size="sm" variant="ghost" icon="receipt" active={panelTab === 'history'} onClick={() => setPanelTab('history')}>Payment History</Btn>
            <Btn size="sm" variant="ghost" icon="calendar" active={panelTab === 'schedule'} onClick={() => setPanelTab('schedule')}>Interest Schedule</Btn>
          </div>

          {panelTab === 'history' && (
            <Card pad={0}>
              <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Payment History</div>
              <Table cols={[{ label: 'Receipt No' }, { label: 'Date' }, { label: 'Type' }, { label: 'Method' }, { label: 'Amount', align: 'right' }, { label: 'Received By' }, { label: 'Status', align: 'center' }]}
                rows={paymentHistory} empty="No payments recorded yet for this Speed Draft." onRow={(p) => setViewPayment(p)}
                render={(p: SpeedDraftPayment) => <>
                  <Td mono style={{ fontWeight: 600 }}>{p.code}</Td>
                  <Td>{dateOnly(p.paidAt)}</Td>
                  <Td>{PAYMENT_TYPE_LABEL[p.paymentType] || p.paymentType}</Td>
                  <Td>{METHOD_OPTIONS.find((m) => m.value === p.paymentMethod)?.label || p.paymentMethod}</Td>
                  <Td mono align="right">{money(p.amount)}</Td>
                  <Td>{p.receiver?.name || '—'}</Td>
                  <Td align="center"><PaymentStatusBadge p={p} /></Td>
                </>} />
            </Card>
          )}

          {panelTab === 'schedule' && (
            <Card pad={0}>
              <div className="row between wrap gap-2" style={{ padding: '14px 16px 0' }}>
                <div className="eyebrow">Interest Schedule</div>
                <div className="row gap-1">
                  {([['all', 'All'], ['paid', 'Paid'], ['unpaid', 'Not Paid']] as const).map(([id, label]) => (
                    <Btn key={id} size="sm" variant="ghost" active={scheduleTab === id} onClick={() => setScheduleTab(id)}>{label}</Btn>
                  ))}
                </div>
              </div>
              <Table cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Interest Due', align: 'right' }, { label: 'Capital Due', align: 'right' }, { label: 'Paid', align: 'right' }, { label: 'Status', align: 'center' }]}
                rows={scheduleRows} empty={scheduleTab === 'paid' ? 'No paid installments yet' : scheduleTab === 'unpaid' ? 'No unpaid installments — interest is fully paid' : 'No installments generated'}
                render={(r: SpeedDraftSchedule) => <>
                  <Td>{r.installmentNo}</Td>
                  <Td>{dateOnly(r.dueDate)}</Td>
                  <Td mono align="right">{money(r.interestDue)}</Td>
                  <Td mono align="right">{num(r.principalDue) > 0 ? money(r.principalDue) : '—'}</Td>
                  <Td mono align="right">{money(r.paidAmount)}</Td>
                  <Td align="center"><Badge tone={scheduleTone[r.status]} dot>{r.status}</Badge></Td>
                </>} />
            </Card>
          )}
        </>
      )}

      {!selected && !loading && !loadingDraft && (
        <div className="t-2" style={{ fontSize: 12.5, textAlign: 'center', padding: '20px 0' }}>
          <Icon n="search" s={16} /> Search above to find an active Speed Draft by NIC number, draft code, or customer name.
        </div>
      )}

      <Modal open={payOpen} onClose={resetPayForm} title={payType === 'Interest' ? 'Pay Interest' : 'Make Capital Payment'}
        sub={selected ? (payType === 'Interest' ? `${selected.code} — applied to the oldest unpaid interest installment(s) first.` : `${selected.code} — reduces outstanding capital and re-prices future interest.`) : undefined}
        footer={<>
          <Btn variant="solid" onClick={resetPayForm}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={record} disabled={busy}>{busy ? 'Recording…' : 'Record Payment & Print Receipt'}</Btn>
        </>}>
        <div className="col gap-3">
          <ErrorBanner error={err} style={{ marginBottom: 0 }} />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: '8px 16px', fontSize: 12.5, paddingBottom: 12, borderBottom: '1px solid var(--line)' }}>
            <div><span className="t-2">{payType === 'Interest' ? 'Outstanding Interest' : 'Outstanding Capital'}</span><br />
              <b style={{ fontFamily: 'JetBrains Mono', color: 'var(--bad)' }}>{money(payType === 'Interest' ? summary?.outstandingInterest : summary?.outstandingCapital)}</b></div>
          </div>
          <Field label="Amount"><Input type="number" value={amount} onChange={(e) => setAmount(e.target.value)}
            placeholder={`Up to ${money(payType === 'Interest' ? summary?.outstandingInterest : summary?.outstandingCapital)}`} /></Field>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16 }}>
            <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
              {METHOD_OPTIONS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select></Field>
            <Field label="Date"><Input type="date" value={paidAt} max={todayIso()} onChange={(e) => setPaidAt(e.target.value)} /></Field>
            {method === 'Cash'
              ? <Field label="Receipt No."><Input value={nextReceipt || '…'} readOnly disabled style={{ color: 'var(--tx-2)' }} /></Field>
              : <Field label="Reference No."><Input value={receiptNo} onChange={(e) => setReceiptNo(e.target.value)} /></Field>}
          </div>
          <Field label="Notes"><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} style={{ width: '100%', background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: '9px 12px', color: 'var(--tx-0)', fontSize: 13.5, fontFamily: 'Outfit', resize: 'vertical' }} /></Field>
        </div>
      </Modal>

      <Modal open={settleOpen} onClose={resetSettleForm} title="Settle Full Balance" sub={selected?.code}
        footer={<>
          <Btn variant="solid" onClick={resetSettleForm}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={submitSettlement} disabled={settleBusy}>{settleBusy ? 'Recording…' : 'Record Settlement & Print Receipt'}</Btn>
        </>}>
        <div className="col gap-3">
          <ErrorBanner error={settleErr} style={{ marginBottom: 0 }} />
          <div className="t-2" style={{ fontSize: 12.5 }}>This pays off all outstanding interest and the full remaining capital of <b style={{ fontFamily: 'JetBrains Mono' }}>{money(summary?.amountToSettleToday)}</b>, closing the Speed Draft immediately.</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16 }}>
            <Field label="Payment Method"><Select value={settleMethod} onChange={(e) => setSettleMethod(e.target.value as PaymentMethod)}>
              {METHOD_OPTIONS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select></Field>
            <Field label="Date"><Input type="date" value={settlePaidAt} max={todayIso()} onChange={(e) => setSettlePaidAt(e.target.value)} /></Field>
            {settleMethod === 'Cash'
              ? <Field label="Receipt No."><Input value={nextReceipt || '…'} readOnly disabled style={{ color: 'var(--tx-2)' }} /></Field>
              : <Field label="Reference No."><Input value={settleReceiptNo} onChange={(e) => setSettleReceiptNo(e.target.value)} /></Field>}
          </div>
          <Field label="Notes"><textarea value={settleNotes} onChange={(e) => setSettleNotes(e.target.value)} rows={2} style={{ width: '100%', background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: '9px 12px', color: 'var(--tx-0)', fontSize: 13.5, fontFamily: 'Outfit', resize: 'vertical' }} /></Field>
        </div>
      </Modal>

      <Modal open={!!viewPayment} onClose={() => setViewPayment(null)} title={viewPayment ? `Payment ${viewPayment.code}` : ''} sub={selected ? `${selected.code} — ${selected.customer?.fullName}` : undefined}
        footer={<Btn variant="solid" onClick={() => setViewPayment(null)}>Close</Btn>}>
        {viewPayment && (
          <div className="col gap-2" style={{ fontSize: 13 }}>
            <div className="row between"><span className="t-2">Date</span><b>{dateOnly(viewPayment.paidAt)}</b></div>
            <div className="row between"><span className="t-2">Status</span><PaymentStatusBadge p={viewPayment} /></div>
            <div className="row between" style={{ paddingTop: 8, borderTop: '1px solid var(--line)' }}>
              <span className="t-2">Amount</span><span style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(viewPayment.amount)}</span>
            </div>
            <div className="row between"><span className="t-2">Type</span><b>{PAYMENT_TYPE_LABEL[viewPayment.paymentType]}</b></div>
            <div className="row between"><span className="t-2">Payment Method</span><b>{METHOD_OPTIONS.find((m) => m.value === viewPayment.paymentMethod)?.label || viewPayment.paymentMethod}</b></div>
            {viewPayment.receiptNo && <div className="row between"><span className="t-2">Reference No.</span><b>{viewPayment.receiptNo}</b></div>}
            {viewPayment.notes && <div className="row between"><span className="t-2">Notes</span><b>{viewPayment.notes}</b></div>}
            <div className="row between" style={{ paddingTop: 8, borderTop: '1px solid var(--line)' }}><span className="t-2">Received By</span><b>{viewPayment.receiver?.name || '—'}</b></div>
            {viewPayment.status === 'Voided' && (
              <div className="col gap-1" style={{ marginTop: 8, padding: '8px 12px', background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
                <div>Voided {dateOnly(viewPayment.voidedAt)} by {viewPayment.voider?.name || '—'}</div>
                {viewPayment.voidReason && <div>Reason: {viewPayment.voidReason}</div>}
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}

export default SpeedDraftPaymentReceiptScreen
