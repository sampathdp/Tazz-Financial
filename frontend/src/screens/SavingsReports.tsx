/* Daily Savings — Reports: one screen, tab-switched over the report types. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Table, Td, LoadingBlock } from '../components/ui'
import { ExportButtons } from '../components/ExportButtons'
import { BranchFilter, useBranchFilter } from '../components/BranchFilter'
import { DateRangeSelect } from '../components/DateRangeSelect'
import { savingsReportsApi, type SavingsReportType, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportFlatTableToExcel, type ExcelColumn } from '../lib/export'
import type { Go } from './types'

const today = () => new Date().toISOString().slice(0, 10)
const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')

const TABS: { id: SavingsReportType; label: string; dated?: boolean }[] = [
  { id: 'active-accounts', label: 'Active Accounts' },
  { id: 'dormant-accounts', label: 'Dormant Accounts' },
  { id: 'below-minimum', label: 'Below Minimum' },
  { id: 'balance-summary', label: 'Balance Summary' },
  { id: 'transaction-history', label: 'Transactions', dated: true },
  { id: 'collection-sheets', label: 'Collection Sheets', dated: true },
  { id: 'interest-posted', label: 'Interest Posted', dated: true },
  { id: 'new-accounts', label: 'New Accounts', dated: true },
  { id: 'closed-accounts', label: 'Closed Accounts', dated: true },
]

type Shape = 'account' | 'balance' | 'txn' | 'collection' | 'interest'
const shapeOf = (t: SavingsReportType): Shape =>
  t === 'balance-summary' ? 'balance'
  : t === 'transaction-history' ? 'txn'
  : t === 'collection-sheets' ? 'collection'
  : t === 'interest-posted' ? 'interest'
  : 'account'

export function SavingsReportsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const [tab, setTab] = useState<SavingsReportType>('active-accounts')
  const [loading, setLoading] = useState(true)
  const [data, setData] = useState<any[]>([])
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')

  const meta = TABS.find((t) => t.id === tab)!
  const params = (): Record<string, any> => ({ branchId, ...(meta.dated ? { from: fromDate || undefined, to: toDate || undefined } : {}) })

  const load = () => {
    setErr(''); setLoading(true)
    savingsReportsApi.get(tab, params())
      .then((d) => setData(Array.isArray(d) ? d : (d?.transactions || [])))
      .catch((ex) => { setErr(getErrorText(ex, 'Failed to load report')); setData([]) })
      .finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [tab, branchId, fromDate, toDate]) // eslint-disable-line react-hooks/exhaustive-deps

  const shape = shapeOf(tab)
  const cols: ExcelColumn[] =
    shape === 'balance' ? [
      { label: 'Product', key: 'product' }, { label: 'Code', key: 'code' },
      { label: 'Accounts', key: 'accountCount', align: 'right' }, { label: 'Open', key: 'openCount', align: 'right' },
      { label: 'Total Balance', key: 'totalBalance', align: 'right', format: 'money' },
    ] : shape === 'txn' ? [
      { label: 'Txn No', key: 'txnNumber' }, { label: 'Account', key: 'account' }, { label: 'Date', key: 'date' }, { label: 'Type', key: 'type' },
      { label: 'In', key: 'inAmt', align: 'right', format: 'money' }, { label: 'Out', key: 'outAmt', align: 'right', format: 'money' }, { label: 'Balance', key: 'balance', align: 'right', format: 'money' },
    ] : shape === 'collection' ? [
      { label: 'Sheet No', key: 'sheetNumber' }, { label: 'Date', key: 'date' }, { label: 'Collector', key: 'collector' },
      { label: 'Lines', key: 'lines', align: 'right' }, { label: 'Total', key: 'total', align: 'right', format: 'money' }, { label: 'Status', key: 'status' },
    ] : shape === 'interest' ? [
      { label: 'Run No', key: 'runNumber' }, { label: 'From', key: 'from' }, { label: 'To', key: 'to' },
      { label: 'Accounts', key: 'accountCount', align: 'right' }, { label: 'Interest', key: 'interest', align: 'right', format: 'money' },
    ] : [
      { label: 'Account No', key: 'accountNumber' }, { label: 'Customer', key: 'customer' }, { label: 'Product', key: 'product' },
      { label: 'Opened', key: 'opened' }, { label: 'Balance', key: 'balance', align: 'right', format: 'money' }, { label: 'Status', key: 'status' },
    ]

  const flatRows = () => data.map((r: any) =>
    shape === 'balance' ? { product: r.product, code: r.code, accountCount: r.accountCount, openCount: r.openCount, totalBalance: Number(r.totalBalance || 0) }
    : shape === 'txn' ? { txnNumber: r.txnNumber, account: r.savingsAccount?.accountNumber || '—', date: dateOnly(r.txnDate), type: r.type, inAmt: r.direction === 'In' ? Number(r.amount) : 0, outAmt: r.direction === 'Out' ? Number(r.amount) : 0, balance: Number(r.runningBalance || 0) }
    : shape === 'collection' ? { sheetNumber: r.sheetNumber, date: dateOnly(r.collectionDate), collector: r.collector?.name || '—', lines: r.linesCount ?? r.lineCount, total: Number(r.totalAmount || 0), status: r.status }
    : shape === 'interest' ? { runNumber: r.runNumber, from: dateOnly(r.periodFrom), to: dateOnly(r.periodTo), accountCount: r.accountCount, interest: Number(r.totalInterest || 0) }
    : { accountNumber: r.accountNumber, customer: r.customer?.fullName || '—', product: r.savingsProduct?.name || '—', opened: dateOnly(r.openDate), balance: Number(r.currentBalance || 0), status: r.status })

  const exportExcel = () => void exportFlatTableToExcel({ reportTitle: meta.label, columns: cols, rows: flatRows(), emptyText: 'No records' }, `savings-${tab}-${today()}.xlsx`)
  const exportPdf = async () => downloadBlob(await savingsReportsApi.pdf(tab, params()), `savings-${tab}-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Reports" title="Savings Reports" icon="chart"
        actions={data.length > 0 && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card pad={0} style={{ marginBottom: 16 }}>
        <div className="row gap-1 wrap" style={{ padding: 14 }}>
          {TABS.map((t) => <Btn key={t.id} size="sm" variant="ghost" active={tab === t.id} onClick={() => setTab(t.id)}>{t.label}</Btn>)}
        </div>
      </Card>
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
          {meta.dated && <>
            <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
            <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
            <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          </>}
        </div>
      </Card>

      <Card pad={0}>
        {loading ? <LoadingBlock /> :
         err ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)', whiteSpace: 'pre-line' }}>{err}</div> :
         <Table cols={cols.map((c) => ({ label: c.label, align: c.align as any }))} rows={data} empty="No records"
           render={(r: any) => {
             if (shape === 'balance') return <><Td>{r.product}</Td><Td mono>{r.code}</Td><Td align="right" mono>{r.accountCount}</Td><Td align="right" mono>{r.openCount}</Td><Td align="right" mono>{money(r.totalBalance)}</Td></>
             if (shape === 'txn') return <><Td mono>{r.txnNumber}</Td><Td mono>{r.savingsAccount?.accountNumber || '—'}</Td><Td>{dateOnly(r.txnDate)}</Td><Td>{r.type}</Td><Td align="right" mono>{r.direction === 'In' ? money(r.amount) : '—'}</Td><Td align="right" mono>{r.direction === 'Out' ? money(r.amount) : '—'}</Td><Td align="right" mono>{money(r.runningBalance)}</Td></>
             if (shape === 'collection') return <><Td mono>{r.sheetNumber}</Td><Td>{dateOnly(r.collectionDate)}</Td><Td>{r.collector?.name || '—'}</Td><Td align="right" mono>{r.linesCount ?? r.lineCount}</Td><Td align="right" mono>{money(r.totalAmount)}</Td><Td><Badge tone="neutral">{r.status}</Badge></Td></>
             if (shape === 'interest') return <><Td mono>{r.runNumber}</Td><Td>{dateOnly(r.periodFrom)}</Td><Td>{dateOnly(r.periodTo)}</Td><Td align="right" mono>{r.accountCount}</Td><Td align="right" mono>{money(r.totalInterest)}</Td></>
             return <><Td mono>{r.accountNumber}</Td><Td>{r.customer?.fullName || '—'}</Td><Td>{r.savingsProduct?.name || '—'}</Td><Td>{dateOnly(r.openDate)}</Td><Td align="right" mono>{money(r.currentBalance)}</Td><Td><Badge tone="neutral">{r.status}</Badge></Td></>
           }} />}
      </Card>
    </div>
  )
}

export default SavingsReportsScreen
