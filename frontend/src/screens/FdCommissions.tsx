/* Tazz Investment & Credit — Fixed Deposit Commissions report */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Select, Input, Table, Td, LoadingBlock } from '../components/ui'
import {
  staffApi, fdCommissionsApi,
  type AuthUser, type FdCommission, type FdCommissionRecipient, type StaffOption,
} from '../api'
import { useNotify } from '../components/Notify'
import { ExportButtons } from '../components/ExportButtons'
import { PayFdCommissionModal } from '../components/FdExtras'
import { exportFlatTableToExcel } from '../lib/export'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const day = (v?: string | null) => v?.slice(0, 10) || ''
const today = () => new Date().toISOString().slice(0, 10)

const rateText = (c: FdCommission) => c.commissionType === 'percentage' ? `${Number(c.commissionValue)}%` : 'Fixed'

export function FdCommissionsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<FdCommission[]>([])
  const [loading, setLoading] = useState(true)
  const [staff, setStaff] = useState<StaffOption[]>([])

  const [recipientType, setRecipientType] = useState<FdCommissionRecipient | ''>('')
  const [staffId, setStaffId] = useState<number | ''>('')
  const [status, setStatus] = useState<'Pending' | 'Paid' | ''>('')
  const [month, setMonth] = useState('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [term, setTerm] = useState<number | ''>('')
  const [payTarget, setPayTarget] = useState<FdCommission | null>(null)
  const [voidingId, setVoidingId] = useState<number | null>(null)

  useEffect(() => { staffApi.options().then(setStaff).catch(() => setStaff([])) }, [])

  const refresh = () => {
    setLoading(true)
    fdCommissionsApi.list({
      status: status || undefined, recipientType: recipientType || undefined,
      ...(staffId ? { staffId: Number(staffId) } : {}),
      fromDate: fromDate || undefined, toDate: toDate || undefined,
      ...(term ? { termMonths: Number(term) } : {}),
    } as any).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [recipientType, staffId, status, fromDate, toDate, term])

  const pickMonth = (m: string) => {
    setMonth(m)
    if (!m) { setFromDate(''); setToDate(''); return }
    const [y, mo] = m.split('-').map(Number)
    setFromDate(`${m}-01`)
    setToDate(`${m}-${String(new Date(y, mo, 0).getDate()).padStart(2, '0')}`)
  }
  const periodText = (c: FdCommission) => {
    const n = c.fixedDeposit?.termMonths
    return n ? (n % 12 === 0 ? `${n / 12} yr` : `${n} mo`) : '—'
  }
  const rangeText = fromDate || toDate ? ` · Placed ${fromDate || '…'} to ${toDate || '…'}` : '' // eslint-disable-line react-hooks/exhaustive-deps

  const voidPayment = async (c: FdCommission) => {
    const ok = await notify.confirm({ title: 'Void this commission payment?', msg: `Reverses ${money(c.paymentAmount ?? c.commissionAmount)} paid to ${c.recipientName} and restores the till/bank balance.`, danger: true, okText: 'Void' })
    if (!ok || voidingId !== null) return
    setVoidingId(c.id)
    try { await fdCommissionsApi.void(c.id); refresh(); notify.success('Payment voided') }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
    finally { setVoidingId(null) }
  }

  const recipient = (c: FdCommission) => c.recipientName || c.staff?.name || '—'

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'FD Commissions', meta: `Generated ${today()}${rangeText}`,
      columns: [
        { label: 'Recipient', key: 'recipient' }, { label: 'Type', key: 'rtype' }, { label: 'FD Number', key: 'fd' }, { label: 'Customer', key: 'customer' },
        { label: 'FD Amount', key: 'amount', align: 'right', format: 'money' },
        { label: 'Period', key: 'period', align: 'center' }, { label: 'Start Date', key: 'start', align: 'center' }, { label: 'Maturity Date', key: 'maturity', align: 'center' }, { label: 'Closed', key: 'closed', align: 'center' },
        { label: 'Rate', key: 'rate', align: 'center' }, { label: 'Commission', key: 'commission', align: 'right', format: 'money' },
        { label: 'Status', key: 'status', align: 'center' }, { label: 'Paid On', key: 'paidAt', align: 'center' },
        { label: 'Paid Amount', key: 'paid', align: 'right', format: 'money' }, { label: 'Method', key: 'method', align: 'center' },
      ],
      rows: rows.map((c) => ({
        recipient: recipient(c), rtype: c.recipientType === 'staff' ? 'Staff' : 'Outsider', fd: c.fixedDeposit?.fdNumber || '', customer: c.fixedDeposit?.customer?.fullName || '',
        amount: Number(c.fixedDeposit?.principalAmount || 0), period: periodText(c), start: day(c.fixedDeposit?.startDate), maturity: day(c.fixedDeposit?.maturityDate), closed: day(c.fixedDeposit?.closedAt),
        rate: rateText(c), commission: Number(c.commissionAmount || 0), status: c.commissionStatus, paidAt: day(c.paidAt),
        paid: Number(c.paymentAmount || 0), method: c.paymentMethod || '',
      })),
      totalRow: {
        recipient: 'Total', amount: rows.reduce((a, c) => a + Number(c.fixedDeposit?.principalAmount || 0), 0),
        commission: rows.reduce((a, c) => a + Number(c.commissionAmount || 0), 0), paid: rows.reduce((a, c) => a + Number(c.paymentAmount || 0), 0),
      },
      emptyText: 'No commissions',
    }, `fd-commissions-${today()}.xlsx`)
  }

  // No PDF library / server template for this report: print the table from a clean window, user picks "Save as PDF".
  const exportPdf = async () => {
    const esc = (v: unknown) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    const head = ['Recipient', 'Type', 'FD Number', 'Customer', 'FD Amount', 'Period', 'Start', 'Maturity', 'Closed', 'Rate', 'Commission', 'Status', 'Paid On']
    const body = rows.map((c) => [
      recipient(c), c.recipientType === 'staff' ? 'Staff' : 'Outsider', c.fixedDeposit?.fdNumber, c.fixedDeposit?.customer?.fullName,
      money(c.fixedDeposit?.principalAmount), periodText(c), day(c.fixedDeposit?.startDate) || '—', day(c.fixedDeposit?.maturityDate) || '—', day(c.fixedDeposit?.closedAt) || '—',
      rateText(c), money(c.commissionAmount), c.commissionStatus, day(c.paidAt) || '—',
    ])
    const w = window.open('', '_blank')
    if (!w) throw new Error('Allow pop-ups to export the PDF.')
    w.document.write(`<!doctype html><html><head><title>FD Commissions</title><style>
      @page{size:A4 landscape;margin:12mm}body{font:11px Arial,sans-serif;color:#111}h1{font-size:16px;margin:0 0 4px}
      p{margin:0 0 10px;color:#666}table{width:100%;border-collapse:collapse}th{background:#1F3A5F;color:#fff;text-align:left}
      th,td{border:1px solid #d5dce5;padding:4px 6px}tr:nth-child(even) td{background:#f8fafc}</style></head><body>
      <h1>FD Commissions</h1><p>Generated ${today()}${esc(rangeText)} · ${rows.length} record(s)</p>
      <table><thead><tr>${head.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead>
      <tbody>${body.map((r) => `<tr>${r.map((v) => `<td>${esc(v)}</td>`).join('')}</tr>`).join('')}</tbody></table>
      </body></html>`)
    w.document.close()
    w.focus()
    w.print()
  }

  return (
    <div>
      <PageHead crumbs="Reports / Investor Reports" title="FD Commissions" icon="target"
        sub={`${rows.length} commission${rows.length === 1 ? '' : 's'}`}
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />

      <Card style={{ marginBottom: 16 }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
          <Field label="Paid To"><Select value={recipientType} onChange={(e) => setRecipientType(e.target.value as FdCommissionRecipient | '')}>
            <option value="">All recipients</option>
            <option value="staff">Staff</option>
            <option value="outsider">Outsider</option>
          </Select></Field>
          <Field label="Staff Member"><Select value={staffId} onChange={(e) => setStaffId(Number(e.target.value) || '')}>
            <option value="">All staff</option>
            {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </Select></Field>
          <Field label="Commission Status"><Select value={status} onChange={(e) => setStatus(e.target.value as 'Pending' | 'Paid' | '')}>
            <option value="">All statuses</option>
            <option value="Pending">Pending</option>
            <option value="Paid">Paid</option>
          </Select></Field>
          <Field label="Month (FD placed)"><Input type="month" value={month} onChange={(e) => pickMonth(e.target.value)} /></Field>
          <Field label="From Date"><Input type="date" value={fromDate} onChange={(e) => { setMonth(''); setFromDate(e.target.value) }} /></Field>
          <Field label="To Date"><Input type="date" value={toDate} onChange={(e) => { setMonth(''); setToDate(e.target.value) }} /></Field>
          <Field label="FD Period"><Select value={term} onChange={(e) => setTerm(Number(e.target.value) || '')}>
            <option value="">All periods</option>
            {[1, 3, 6, 9, 12, 18, 24, 36, 48, 60].map((n) => <option key={n} value={n}>{n} month{n === 1 ? '' : 's'}</option>)}
          </Select></Field>
        </div>
      </Card>

      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table
          cols={[
            { label: 'Recipient' }, { label: 'FD Number' }, { label: 'Customer' }, { label: 'FD Amount', align: 'right' },
            { label: 'Period', align: 'center' }, { label: 'Start Date' }, { label: 'Maturity' }, { label: 'Closed' }, { label: 'Rate', align: 'center' },
            { label: 'Commission', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 130 },
          ]}
          rows={rows} empty="No FD commissions recorded yet."
          render={(c) => <>
            <Td>{recipient(c)}<span className="t-2" style={{ marginLeft: 6, fontSize: 12 }}>{c.recipientType === 'staff' ? (c.staff?.staffCode || 'Staff') : 'Outsider'}</span></Td>
            <Td>{c.fixedDeposit?.fdNumber || '—'}</Td>
            <Td>{c.fixedDeposit?.customer?.fullName || '—'}</Td>
            <Td align="right" mono>{money(c.fixedDeposit?.principalAmount)}</Td>
            <Td align="center">{periodText(c)}</Td>
            <Td>{day(c.fixedDeposit?.startDate) || '—'}</Td>
            <Td>{day(c.fixedDeposit?.maturityDate) || '—'}</Td>
            <Td>{day(c.fixedDeposit?.closedAt) || '—'}</Td>
            <Td align="center">{rateText(c)}</Td>
            <Td align="right" mono>{money(c.commissionAmount)}</Td>
            <Td align="center"><Badge tone={c.commissionStatus === 'Paid' ? 'green' : 'amber'} dot>{c.commissionStatus}</Badge></Td>
            <Td align="right">
              {c.commissionStatus === 'Pending' && (
                <Btn variant="primary" size="sm" icon="check" onClick={() => setPayTarget(c)}>Pay Commission</Btn>
              )}
              {c.commissionStatus === 'Paid' && (
                <div className="row gap-2" style={{ justifyContent: 'flex-end', alignItems: 'center' }}>
                  <span className="t-2" style={{ fontSize: 12 }}>{day(c.paidAt)}</span>
                  {me?.role === 'admin' && <Btn variant="danger" size="sm" icon="x" disabled={voidingId !== null} onClick={() => voidPayment(c)}>Void</Btn>}
                </div>
              )}
            </Td>
          </>} />}
      </Card>

      <PayFdCommissionModal commission={payTarget} open={!!payTarget} onClose={() => setPayTarget(null)} onPaid={() => { setPayTarget(null); refresh() }} />
    </div>
  )
}
