/* Funding Management — Interest Due: every Pending monthly interest installment
   on an Active Fixed Deposit that has to be released by the end of the current
   month, split into Overdue / Today / This Week / This Month buckets. Pay an
   installment straight from here (Overdue and Today only — the server blocks
   paying ahead of the due date); it posts through the same
   fdInterestPaymentsApi.pay endpoint Fixed Deposits' own detail screen uses,
   so schedules and balances stay in sync. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Table, Td, Modal, ErrorBanner, LoadingBlock } from '../components/ui'
import {
  fdInterestPaymentsApi,
  type FdInterestDue, type FdInterestDueBucket, type FdInterestDueItem, type FdPaymentMethod, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const METHODS: { value: FdPaymentMethod; label: string }[] = [
  { value: 'Cash', label: 'Cash' },
  { value: 'Cheque', label: 'Cheque' },
  { value: 'Bank', label: 'Bank Transfer' },
]

function PayInterestModal({ item, open, onClose, onPaid }: {
  item: FdInterestDueItem | null; open: boolean; onClose: () => void; onPaid: () => void
}) {
  const notify = useNotify()
  const [method, setMethod] = useState<FdPaymentMethod>('Cash')
  const [refNo, setRefNo] = useState('')
  const [paidAt, setPaidAt] = useState(todayIso())
  const [backDated, setBackDated] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { if (open) { setMethod('Cash'); setRefNo(''); setPaidAt(todayIso()); setBackDated(false); setErr('') } }, [open])
  if (!item) return null

  const save = async () => {
    setBusy(true); setErr('')
    try {
      await fdInterestPaymentsApi.pay(item.id, { paymentMethod: method, referenceNo: refNo || undefined, paidAt })
      onPaid()
      notify.success('Interest payment recorded')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not record this interest payment.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={460}
      title={`Pay Interest — ${item.fixedDeposit.fdNumber} #${item.installmentNo}`}
      sub={`${item.fixedDeposit.customer?.fullName || ''} — Due ${dateOnly(item.dueDate)} — Amount ${money(item.amount)}`}
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Recording...' : 'Record Payment'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as FdPaymentMethod)}>
          {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
        </Select></Field>
        <Field label="Date">
          <DateInput value={paidAt} disabled={!backDated} onChange={setPaidAt} />
          <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 6 }}>
            <input type="checkbox" checked={backDated} onChange={(e) => {
              const checked = e.target.checked
              setBackDated(checked)
              if (!checked) setPaidAt(todayIso())
            }} />
            <span className="t-2" style={{ fontSize: 11.5 }}>Back Dated Entry</span>
          </label>
        </Field>
        <Field label="Reference No" full><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>
      </div>
    </Modal>
  )
}

type BucketMeta = { key: keyof FdInterestDue['buckets']; label: string; tone: 'red' | 'amber' | 'blue' | 'neutral'; payable: boolean }
const BUCKETS: BucketMeta[] = [
  { key: 'overdue', label: 'Overdue', tone: 'red', payable: true },
  { key: 'today', label: 'Today', tone: 'amber', payable: true },
  { key: 'thisWeek', label: 'This Week', tone: 'blue', payable: false },
  { key: 'thisMonth', label: 'This Month', tone: 'neutral', payable: false },
]

function BucketCard({ meta, bucket, me, onPay }: {
  meta: BucketMeta; bucket: FdInterestDueBucket; me?: AuthUser | null; onPay: (i: FdInterestDueItem) => void
}) {
  return (
    <Card pad={0} style={{ marginBottom: 16 }}>
      <div className="row between wrap gap-2" style={{ padding: '14px 16px' }}>
        <div className="row gap-2" style={{ alignItems: 'center' }}>
          <Badge tone={meta.tone} dot>{meta.label}</Badge>
          <span className="t-2" style={{ fontSize: 12 }}>{bucket.count} installment{bucket.count === 1 ? '' : 's'}</span>
        </div>
        <div style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(bucket.total)}</div>
      </div>
      <Table
        cols={[
          { label: 'FD No' }, { label: 'Customer' }, { label: 'NIC' }, { label: '#', align: 'center' },
          { label: 'Due Date' }, { label: 'Amount', align: 'right' }, { label: '', align: 'right', w: 90 },
        ]}
        rows={bucket.items} empty="Nothing due in this window"
        render={(i: FdInterestDueItem) => <>
          <Td mono>{i.fixedDeposit.fdNumber}</Td>
          <Td>{i.fixedDeposit.customer?.fullName || '—'}</Td>
          <Td>{i.fixedDeposit.customer?.nicNo || '—'}</Td>
          <Td align="center">{i.installmentNo}</Td>
          <Td>{dateOnly(i.dueDate)}</Td>
          <Td align="right" mono>{money(i.amount)}</Td>
          <Td align="right">
            {meta.payable ? (
              <Can user={me} module="Fixed Deposits" action="Edit">
                <Btn size="sm" variant="primary" icon="check" onClick={() => onPay(i)}>Pay</Btn>
              </Can>
            ) : (
              <span className="t-2" style={{ fontSize: 11.5 }}>Not due yet</span>
            )}
          </Td>
        </>} />
    </Card>
  )
}

export function FdInterestDueScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<FdInterestDue | null>(null)
  const [loading, setLoading] = useState(true)
  const [payTarget, setPayTarget] = useState<FdInterestDueItem | null>(null)

  const refresh = () => {
    setLoading(true)
    fdInterestPaymentsApi.due().then(setData).catch(() => setData(null)).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [])

  if (loading) return <LoadingBlock />
  if (!data) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>Failed to load interest due.</div>

  const grandTotal = BUCKETS.reduce((s, b) => s + Number(data.buckets[b.key].total), 0)
  const grandCount = BUCKETS.reduce((s, b) => s + data.buckets[b.key].count, 0)

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Interest Due" icon="wallet"
        sub={`${grandCount} monthly interest installment${grandCount === 1 ? '' : 's'} to release through ${dateOnly(data.endOfMonth)} — ${money(grandTotal)}`} />

      {grandCount === 0 && (
        <Card style={{ textAlign: 'center', color: 'var(--tx-2)', padding: 40 }}>
          No FD interest payments are due this month.
        </Card>
      )}

      {BUCKETS.map((meta) => (
        data.buckets[meta.key].count > 0 &&
        <BucketCard key={meta.key} meta={meta} bucket={data.buckets[meta.key]} me={me} onPay={setPayTarget} />
      ))}

      <PayInterestModal item={payTarget} open={!!payTarget} onClose={() => setPayTarget(null)}
        onPaid={() => { setPayTarget(null); refresh() }} />
    </div>
  )
}

export default FdInterestDueScreen
