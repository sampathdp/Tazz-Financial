/* Tazz Investment & Credit — Accounting: Chart of Accounts, Journal Entries,
   General Ledger, Trial Balance, Profit & Loss, Balance Sheet.
   None of these report screens has its own data model — every figure comes
   straight from journal_lines (see ChartOfAccount::balancesAsOf on the
   backend), so what you see here can never disagree with the ledger itself. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, Pager, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { ExportButtons } from '../components/ExportButtons'
import { DateRangeSelect } from '../components/DateRangeSelect'
import {
  chartOfAccountsApi, journalEntriesApi, accountingReportsApi,
  type ChartOfAccount, type AccountType, type JournalEntry, type GeneralLedgerReport,
  type TrialBalanceRow, type ProfitLossReport, type BalanceSheetReport, type CashFlowReport, type LedgerDriftReport, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportFlatTableToExcel, exportReportToExcel } from '../lib/export'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const typeTone = (t: AccountType) => t === 'Asset' ? 'blue' : t === 'Liability' ? 'amber' : t === 'Equity' ? 'neutral' : t === 'Income' ? 'green' : 'red'
const today = () => new Date().toISOString().slice(0, 10)

/* ---------------------------- Chart of Accounts ---------------------------- */

const TYPES: AccountType[] = ['Asset', 'Liability', 'Equity', 'Income', 'Expense']
const defaultNormalBalance = (t: AccountType) => (t === 'Asset' || t === 'Expense' ? 'Debit' : 'Credit')
const blankAccountForm = (): Partial<ChartOfAccount> => ({ accountType: 'Expense', normalBalance: 'Debit', status: 'Active' })

export function ChartOfAccountsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<ChartOfAccount[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<ChartOfAccount | null>(null)
  const [form, setForm] = useState<Partial<ChartOfAccount>>(blankAccountForm())

  const refresh = () => {
    setLoading(true)
    chartOfAccountsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])

  const openCreate = () => { setEditing(null); setForm(blankAccountForm()); setErr(''); setModal(true) }
  const openEdit = (r: ChartOfAccount) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!editing && !form.code) { setErr('Code is required.'); return }
    if (!form.name) { setErr('Name is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await chartOfAccountsApi.update(editing.id, editing.isSystem ? { name: form.name, status: form.status } : form)
      else await chartOfAccountsApi.create({ code: form.code!, name: form.name!, accountType: form.accountType!, normalBalance: form.normalBalance!, parentId: form.parentId || null, status: form.status })
      close(); refresh()
      notify.success(editing ? 'Account updated' : 'Account created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  return (
    <div>
      <PageHead crumbs="Accounting" title="Chart of Accounts" icon="list" sub={`${rows.length} accounts`}
        actions={<Can user={me} module="Chart of Accounts" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Account</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Type', align: 'center' }, { label: 'Parent' }, { label: 'Balance', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 70 }]}
          empty="No accounts yet"
          rows={rows}
          render={(r) => <>
            <Td mono>{r.code}</Td>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>
              <span className="row gap-1" style={{ alignItems: 'center', display: 'inline-flex' }}>
                {r.name}{r.isSystem && <Icon n="shield" s={12} c="var(--tx-3)" />}
              </span>
            </Td>
            <Td align="center"><Badge tone={typeTone(r.accountType)} dot>{r.accountType}</Badge></Td>
            <Td>{r.parent ? `${r.parent.code} ${r.parent.name}` : '—'}</Td>
            <Td align="right" mono>{money(r.balance)}</Td>
            <Td align="center"><Badge tone={(r.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{r.status || 'Active'}</Badge></Td>
            <Td align="right"><Can user={me} module="Chart of Accounts" action="Edit">
              <Btn variant="ghost" size="sm" onClick={() => openEdit(r)}>Edit</Btn>
            </Can></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={480} title={editing ? `Edit ${editing.code}` : 'Add Account'}
        sub={editing?.isSystem ? 'System account — only name/status can change' : undefined}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          {!editing?.isSystem && <>
            <Field label="Code"><Input value={form.code || ''} onChange={(e) => setForm((s) => ({ ...s, code: e.target.value }))} disabled={!!editing} /></Field>
            <Field label="Type"><Select value={form.accountType} onChange={(e) => { const t = e.target.value as AccountType; setForm((s) => ({ ...s, accountType: t, normalBalance: defaultNormalBalance(t) })) }}>
              {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
            </Select></Field>
            <Field label="Normal Balance"><Select value={form.normalBalance} onChange={(e) => setForm((s) => ({ ...s, normalBalance: e.target.value as any }))}>
              <option value="Debit">Debit</option><option value="Credit">Credit</option>
            </Select></Field>
            <Field label="Parent Account"><Select value={form.parentId ?? ''} onChange={(e) => setForm((s) => ({ ...s, parentId: Number(e.target.value) || undefined }))}>
              <option value="">None (top-level)</option>
              {rows.filter((a) => a.id !== editing?.id).map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
            </Select></Field>
          </>}
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
        </div>
      </Modal>
    </div>
  )
}

/* ---------------------------- Journal Entries ---------------------------- */

export function JournalEntriesScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<JournalEntry[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [totalCount, setTotalCount] = useState(0)
  const [loading, setLoading] = useState(true)
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [status, setStatus] = useState<'' | 'Posted' | 'Reversed'>('')
  const [viewing, setViewing] = useState<JournalEntry | null>(null)

  // Date/status changes always jump back to page 1.
  useEffect(() => { setPage(1) }, [fromDate, toDate, status])

  useEffect(() => {
    setLoading(true)
    journalEntriesApi.browse({ from: fromDate || undefined, to: toDate || undefined, status: status || undefined, page })
      .then((r) => { setRows(r.data); setLastPage(r.lastPage); setTotalCount(r.total) })
      .catch(() => { setRows([]); setLastPage(1); setTotalCount(0) })
      .finally(() => setLoading(false))
  }, [fromDate, toDate, status, page])

  const total = (e: JournalEntry) => (e.lines || []).reduce((s, l) => s + Number(l.debit || 0), 0)

  return (
    <div>
      <PageHead crumbs="Accounting" title="Journal Entries" icon="doc" sub={`${totalCount} entries`} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          <Field label="Status"><Select value={status} onChange={(e) => setStatus(e.target.value as any)} style={{ maxWidth: 160 }}>
            <option value="">All</option><option value="Posted">Posted</option><option value="Reversed">Reversed</option>
          </Select></Field>
        </div>
      </Card>
      <Card pad={0}>
        {loading ? <LoadingBlock /> : <>
        <Table cols={[{ label: 'Date' }, { label: 'Code' }, { label: 'Description' }, { label: 'Reference' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }]}
          empty="No journal entries yet" rows={rows} onRow={(e) => setViewing(e)}
          render={(e) => <>
            <Td>{dateOnly(e.entryDate)}</Td>
            <Td mono>{e.code}</Td>
            <Td>{e.description || '—'}</Td>
            <Td c="var(--tx-2)" style={{ fontSize: 12 }}>{e.sourceType ? e.sourceType + ' #' + e.sourceId : '—'}</Td>
            <Td align="right" mono>{money(total(e))}</Td>
            <Td align="center"><Badge tone={e.status === 'Posted' ? 'green' : 'neutral'} dot>{e.status}</Badge></Td>
          </>} />
        <Pager page={page} lastPage={lastPage} total={totalCount} onChange={setPage} />
        </>}
      </Card>

      <Modal open={!!viewing} onClose={() => setViewing(null)} width={620} title={viewing?.code} sub={viewing?.description || undefined}>
        {viewing && (
          <Table cols={[{ label: 'Account' }, { label: 'Debit', align: 'right' }, { label: 'Credit', align: 'right' }]}
            rows={viewing.lines || []} empty="No lines"
            render={(l) => <>
              <Td>{l.account ? `${l.account.code} ${l.account.name}` : '—'}</Td>
              <Td align="right" mono>{Number(l.debit) > 0 ? money(l.debit) : '—'}</Td>
              <Td align="right" mono>{Number(l.credit) > 0 ? money(l.credit) : '—'}</Td>
            </>} />
        )}
      </Modal>
    </div>
  )
}

/* ---------------------------- General Ledger ---------------------------- */

export function GeneralLedgerScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const [accounts, setAccounts] = useState<ChartOfAccount[]>([])
  const [accountId, setAccountId] = useState<number | ''>('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [report, setReport] = useState<GeneralLedgerReport | null>(null)
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { chartOfAccountsApi.list().then(setAccounts).catch(() => setAccounts([])) }, [])

  useEffect(() => {
    if (!accountId) { setReport(null); return }
    setLoading(true); setErr('')
    accountingReportsApi.generalLedger(accountId, { from: fromDate || undefined, to: toDate || undefined })
      .then(setReport).catch((ex) => { setErr(getErrorText(ex, 'Failed to load')); setReport(null) }).finally(() => setLoading(false))
  }, [accountId, fromDate, toDate])

  const exportExcel = () => {
    if (!report) return
    void exportFlatTableToExcel({
      reportTitle: 'General Ledger', subtitle: `${report.account.code} — ${report.account.name}`, meta: `Period: ${fromDate || 'Inception'} to ${toDate || 'Today'}`,
      columns: [
        { label: 'Date', key: 'date' }, { label: 'Entry', key: 'entry' }, { label: 'Description', key: 'description' },
        { label: 'Debit', key: 'debit', align: 'right', format: 'money' }, { label: 'Credit', key: 'credit', align: 'right', format: 'money' }, { label: 'Balance', key: 'balance', align: 'right', format: 'money' },
      ],
      rows: report.lines.map((l) => ({
        date: dateOnly(l.entryDate), entry: l.journalEntryCode, description: l.description || '—',
        debit: Number(l.debit) > 0 ? Number(l.debit) : null, credit: Number(l.credit) > 0 ? Number(l.credit) : null, balance: Number(l.runningBalance),
      })),
      emptyText: 'No activity in this range',
    }, `general-ledger-${report.account.code}-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await accountingReportsApi.pdf('general-ledger', { accountId, from: fromDate || undefined, to: toDate || undefined }), `general-ledger-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Accounting" title="General Ledger" icon="layers"
        actions={report && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="Account"><Select value={accountId} onChange={(e) => setAccountId(Number(e.target.value) || '')} style={{ minWidth: 260 }}>
            <option value="">Select an account…</option>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
          </Select></Field>
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
        </div>
      </Card>
      <Card pad={0}>
        {!accountId ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Pick an account to view its ledger.</div> :
         loading ? <LoadingBlock /> :
         err ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>{err}</div> :
        <Table cols={[{ label: 'Date' }, { label: 'Entry' }, { label: 'Description' }, { label: 'Debit', align: 'right' }, { label: 'Credit', align: 'right' }, { label: 'Balance', align: 'right' }]}
          empty="No activity in this range" rows={report?.lines || []}
          render={(l) => <>
            <Td>{dateOnly(l.entryDate)}</Td>
            <Td mono>{l.journalEntryCode}</Td>
            <Td>{l.description || '—'}</Td>
            <Td align="right" mono>{Number(l.debit) > 0 ? money(l.debit) : '—'}</Td>
            <Td align="right" mono>{Number(l.credit) > 0 ? money(l.credit) : '—'}</Td>
            <Td align="right" mono style={{ fontWeight: 600 }}>{money(l.runningBalance)}</Td>
          </>} />}
      </Card>
    </div>
  )
}

/* ---------------------------- Trial Balance ---------------------------- */

export function TrialBalanceScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const [asOf, setAsOf] = useState('')
  const [rows, setRows] = useState<TrialBalanceRow[]>([])
  const [loading, setLoading] = useState(true)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.trialBalance(asOf || undefined).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [asOf]) // eslint-disable-line react-hooks/exhaustive-deps

  const totalDebit = rows.reduce((s, r) => s + Number(r.totalDebit || 0), 0)
  const totalCredit = rows.reduce((s, r) => s + Number(r.totalCredit || 0), 0)

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Trial Balance', meta: asOf ? `As of ${asOf}` : undefined,
      columns: [
        { label: 'Code', key: 'code' }, { label: 'Name', key: 'name' }, { label: 'Type', key: 'type', align: 'center' },
        { label: 'Debit', key: 'debit', align: 'right', format: 'money' }, { label: 'Credit', key: 'credit', align: 'right', format: 'money' }, { label: 'Balance', key: 'balance', align: 'right', format: 'money' },
      ],
      rows: rows.map((r) => ({ code: r.code, name: r.name, type: r.accountType, debit: Number(r.totalDebit || 0), credit: Number(r.totalCredit || 0), balance: Number(r.balance) })),
      totalRow: { name: 'Totals', debit: totalDebit, credit: totalCredit },
      emptyText: 'No accounts yet',
    }, `trial-balance-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await accountingReportsApi.pdf('trial-balance', asOf ? { as_of: asOf } : {}), `trial-balance-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Accounting" title="Trial Balance" icon="chart"
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}><Field label="As Of"><Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} style={{ maxWidth: 200 }} /></Field></Card>
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Type', align: 'center' }, { label: 'Debit', align: 'right' }, { label: 'Credit', align: 'right' }, { label: 'Balance', align: 'right' }]}
          empty="No accounts yet" rows={rows}
          render={(r) => <>
            <Td mono>{r.code}</Td>
            <Td>{r.name}</Td>
            <Td align="center"><Badge tone={typeTone(r.accountType)} dot>{r.accountType}</Badge></Td>
            <Td align="right" mono>{Number(r.totalDebit) > 0 ? money(r.totalDebit) : '—'}</Td>
            <Td align="right" mono>{Number(r.totalCredit) > 0 ? money(r.totalCredit) : '—'}</Td>
            <Td align="right" mono style={{ fontWeight: 600 }}>{money(r.balance)}</Td>
          </>} />}
        {!loading && rows.length > 0 && (
          <div className="row between" style={{ padding: '12px 20px', borderTop: '1px solid var(--line)', fontWeight: 700 }}>
            <span>Totals</span>
            <div className="row gap-4"><span style={{ fontFamily: 'monospace' }}>{money(totalDebit)}</span><span style={{ fontFamily: 'monospace' }}>{money(totalCredit)}</span></div>
          </div>
        )}
      </Card>
    </div>
  )
}

/* ---------------------------- Profit & Loss ---------------------------- */

const getPath = (o: any, path: string) => path.split('.').reduce((v, k) => (v == null ? v : v[k]), o)

const WATERFALL_ROWS: { key: string; label: string; indent?: boolean; bold?: boolean }[] = [
  { key: 'lendingIncome', label: 'Income' },
  { key: 'interZonalInterest', label: 'Inter Zonal Interest Income/(Exp)' },
  { key: 'totalIncome', label: 'Total Income', bold: true },
  { key: 'interestCost', label: 'Interest Cost' },
  { key: 'netIncomeExOther', label: 'Net Income/(Expense) Without Other Income', bold: true },
  { key: 'documentationIncome', label: 'Documentation Income (Other Income)' },
  { key: 'realEstateProfit', label: 'Real Estate Profit' },
  { key: 'refundsOnWriteOffs', label: 'Refunds on Write-offs from General Treasury' },
  { key: 'netIncome', label: 'Net Income/(Expense)', bold: true },
  { key: 'staffCost.salary', label: 'Salary/EPF/ETF', indent: true },
  { key: 'staffCost.allowances', label: 'Allowances', indent: true },
  { key: 'staffCost.total', label: 'Staff Cost' },
  { key: 'otherOperatingExpenses', label: 'Other Operating Expenses' },
  { key: 'profitBeforeHoAbsorption', label: 'Profit/(Loss) Before Absorption of HO Exp', bold: true },
  { key: 'hoExpAbsorption', label: 'HO Exp Absorption' },
  { key: 'netProfitBeforeTaxImpairment', label: 'Net Profit Before Tax & Impairment', bold: true },
  { key: 'provisioningForImpairment', label: 'Provisioning for Impairment' },
  { key: 'netProfitAfterImpairment', label: 'Net Profit After Provision for Impairment', bold: true },
  { key: 'vatNbt', label: 'VAT & NBT on Finance Services' },
  { key: 'incomeTax', label: 'Income Tax' },
  { key: 'netProfitAfterTax', label: 'Net Profit After Tax', bold: true },
  { key: 'netProfitBeforeInterZonal', label: 'Net Profit Before Inter Zonal Interest' },
]

export function ProfitLossScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [report, setReport] = useState<ProfitLossReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [exportingPdf, setExportingPdf] = useState(false)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.profitLoss({ from: fromDate || undefined, to: toDate || undefined }).then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }
  useEffect(refresh, [fromDate, toDate]) // eslint-disable-line react-hooks/exhaustive-deps

  const exportExcel = () => {
    if (!report) return
    void exportReportToExcel({
      sheetName: 'Profit & Loss',
      reportTitle: 'Profit & Loss Statement',
      subtitle: 'Cash and Accrual Basis',
      meta: `Period: ${fromDate || 'Inception'} to ${toDate || 'Today'}  •  Generated: ${new Date().toLocaleString()}`,
      sections: [
        {
          title: 'P&L Waterfall',
          columns: [{ label: 'Line', key: 'label' }, { label: 'Cash Basis', key: 'cash', align: 'right', format: 'money' }, { label: 'Accrual Basis', key: 'accrual', align: 'right', format: 'money' }],
          rows: WATERFALL_ROWS.map((r) => ({ label: (r.indent ? '    ' : '') + r.label, cash: Number(getPath(report.waterfallCash, r.key)), accrual: Number(getPath(report.waterfallAccrual, r.key)) })),
        },
        {
          title: 'Income by Loan Product',
          columns: [
            { label: 'Product', key: 'loanType' }, { label: 'Interest (Cash)', key: 'interestCash', align: 'right', format: 'money' },
            { label: 'Interest (Accrual)', key: 'interestAccrual', align: 'right', format: 'money' }, { label: 'Charges', key: 'charges', align: 'right', format: 'money' },
            { label: 'Penalty', key: 'penalty', align: 'right', format: 'money' }, { label: 'Total (Cash)', key: 'totalCash', align: 'right', format: 'money' },
            { label: 'Total (Accrual)', key: 'totalAccrual', align: 'right', format: 'money' },
          ],
          rows: report.incomeByProduct.map((r) => ({
            loanType: r.loanType, interestCash: Number(r.interestCash), interestAccrual: Number(r.interestAccrual),
            charges: Number(r.charges), penalty: Number(r.penalty), totalCash: Number(r.totalCash), totalAccrual: Number(r.totalAccrual),
          })),
          emptyText: 'No loan payment income posted in this range',
        },
        {
          title: 'Income by Chart of Account',
          columns: [{ label: 'Code', key: 'code' }, { label: 'Name', key: 'name' }, { label: 'Cash Basis', key: 'cash', align: 'right', format: 'money' }, { label: 'Accrual Basis', key: 'accrual', align: 'right', format: 'money' }],
          rows: report.income.map((r) => ({ code: r.code, name: r.name, cash: Number(r.cashBalance), accrual: Number(r.accrualBalance) })),
          totalRow: { code: '', name: 'Total Income', cash: Number(report.totalIncomeCash), accrual: Number(report.totalIncomeAccrual) },
          emptyText: 'No income posted in this range',
        },
        {
          title: 'Expense',
          columns: [{ label: 'Code', key: 'code' }, { label: 'Name', key: 'name' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' }],
          rows: report.expense.map((r) => ({ code: r.code, name: r.name, amount: Number(r.balance) })),
          totalRow: { code: '', name: 'Total Expense', amount: Number(report.totalExpense) },
          emptyText: 'No expense posted in this range',
        },
        {
          title: 'Net Profit',
          columns: [{ label: 'Basis', key: 'basis' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' }],
          rows: [{ basis: 'Cash Basis', amount: Number(report.netProfitCash) }, { basis: 'Accrual Basis', amount: Number(report.netProfitAccrual) }],
        },
      ],
    }, `profit-loss-${new Date().toISOString().slice(0, 10)}.xlsx`)
  }

  const exportPdf = async () => {
    setExportingPdf(true)
    try {
      const blob = await accountingReportsApi.pdf('profit-loss', { from: fromDate || undefined, to: toDate || undefined })
      downloadBlob(blob, `profit-loss-${new Date().toISOString().slice(0, 10)}.pdf`)
    } catch (ex: any) {
      notify.error('Export failed', getErrorText(ex, 'Could not generate PDF'))
    } finally { setExportingPdf(false) }
  }

  return (
    <div>
      <PageHead crumbs="Accounting" title="Profit & Loss" icon="pie"
        actions={report && <div className="row gap-2">
          <Btn variant="solid" size="sm" icon="download" onClick={exportExcel}>Export Excel</Btn>
          <Btn variant="solid" size="sm" icon="download" onClick={exportPdf} disabled={exportingPdf}>{exportingPdf ? 'Exporting…' : 'Export PDF'}</Btn>
        </div>} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap" style={{ alignItems: 'flex-end' }}>
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
        </div>
        <div className="t-2" style={{ fontSize: 11.5, marginTop: 10 }}>
          Cash Basis: income counted when actually collected. Accrual Basis: interest income counted as it's scheduled to be earned, not when collected. Only Interest Income differs between the two — expenses stay the same either way.
        </div>
      </Card>
      {loading ? <Card><LoadingBlock /></Card> : report && (
        <div className="col gap-4">
          <Card pad={0}>
            <div className="eyebrow" style={{ padding: '14px 20px 0' }}>P&L Waterfall</div>
            <Table cols={[{ label: 'Line' }, { label: 'Cash Basis', align: 'right' }, { label: 'Accrual Basis', align: 'right' }]} rows={WATERFALL_ROWS} empty=""
              render={(r) => <>
                <Td style={{ paddingLeft: r.indent ? 36 : 20, fontWeight: r.bold ? 700 : 400 }}>{r.label}</Td>
                <Td align="right" mono style={{ fontWeight: r.bold ? 700 : 400 }}>{money(getPath(report.waterfallCash, r.key))}</Td>
                <Td align="right" mono style={{ fontWeight: r.bold ? 700 : 400 }}>{money(getPath(report.waterfallAccrual, r.key))}</Td>
              </>} />
          </Card>
          <Card pad={0}>
            <div className="eyebrow" style={{ padding: '14px 20px 0' }}>Income by Loan Product</div>
            <Table
              cols={[{ label: 'Product' }, { label: 'Interest (Cash)', align: 'right' }, { label: 'Interest (Accrual)', align: 'right' }, { label: 'Charges', align: 'right' }, { label: 'Penalty', align: 'right' }, { label: 'Total (Cash)', align: 'right' }, { label: 'Total (Accrual)', align: 'right' }]}
              rows={report.incomeByProduct} empty="No loan payment income posted in this range"
              render={(r) => <>
                <Td>{r.loanType}</Td>
                <Td align="right" mono>{money(r.interestCash)}</Td>
                <Td align="right" mono>{money(r.interestAccrual)}</Td>
                <Td align="right" mono>{money(r.charges)}</Td>
                <Td align="right" mono>{money(r.penalty)}</Td>
                <Td align="right" mono style={{ fontWeight: 600 }}>{money(r.totalCash)}</Td>
                <Td align="right" mono style={{ fontWeight: 600 }}>{money(r.totalAccrual)}</Td>
              </>} />
          </Card>
          <Card pad={0}>
            <div className="eyebrow" style={{ padding: '14px 20px 0' }}>Income by Chart of Account</div>
            <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Cash Basis', align: 'right' }, { label: 'Accrual Basis', align: 'right' }]} rows={report.income} empty="No income posted in this range"
              render={(r) => <><Td mono>{r.code}</Td><Td>{r.name}</Td><Td align="right" mono>{money(r.cashBalance)}</Td><Td align="right" mono>{money(r.accrualBalance)}</Td></>} />
            <div className="row between" style={{ padding: '10px 20px', borderTop: '1px solid var(--line)', fontWeight: 700 }}>
              <span>Total Income</span>
              <span className="row gap-4">
                <span style={{ fontFamily: 'monospace' }} title="Cash Basis">{money(report.totalIncomeCash)}</span>
                <span style={{ fontFamily: 'monospace' }} title="Accrual Basis">{money(report.totalIncomeAccrual)}</span>
              </span>
            </div>
          </Card>
          <Card pad={0}>
            <div className="eyebrow" style={{ padding: '14px 20px 0' }}>Expense</div>
            <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Amount', align: 'right' }]} rows={report.expense} empty="No expense posted in this range"
              render={(r) => <><Td mono>{r.code}</Td><Td>{r.name}</Td><Td align="right" mono>{money(r.balance)}</Td></>} />
            <div className="row between" style={{ padding: '10px 20px', borderTop: '1px solid var(--line)', fontWeight: 700 }}>
              <span>Total Expense</span><span style={{ fontFamily: 'monospace' }}>{money(report.totalExpense)}</span>
            </div>
          </Card>
          <Card>
            <div className="col gap-2">
              <div className="row between" style={{ fontSize: 16, fontWeight: 700 }}>
                <span>Net Profit (Cash Basis)</span>
                <span style={{ fontFamily: 'monospace', color: Number(report.netProfitCash) >= 0 ? 'var(--good)' : 'var(--bad)' }}>{money(report.netProfitCash)}</span>
              </div>
              <div className="row between" style={{ fontSize: 16, fontWeight: 700 }}>
                <span>Net Profit (Accrual Basis)</span>
                <span style={{ fontFamily: 'monospace', color: Number(report.netProfitAccrual) >= 0 ? 'var(--good)' : 'var(--bad)' }}>{money(report.netProfitAccrual)}</span>
              </div>
            </div>
          </Card>
        </div>
      )}
    </div>
  )
}

/* ---------------------------- Balance Sheet ---------------------------- */

function BsSection({ title, rows, total }: { title: string; rows: { code: string; name: string; balance: string }[]; total: string }) {
  return (
    <Card pad={0}>
      <div className="eyebrow" style={{ padding: '14px 20px 0' }}>{title}</div>
      <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Amount', align: 'right' }]} rows={rows} empty="Nothing here yet"
        render={(r) => <><Td mono>{r.code}</Td><Td>{r.name}</Td><Td align="right" mono>{money(r.balance)}</Td></>} />
      <div className="row between" style={{ padding: '10px 20px', borderTop: '1px solid var(--line)', fontWeight: 700 }}>
        <span>Total {title}</span><span style={{ fontFamily: 'monospace' }}>{money(total)}</span>
      </div>
    </Card>
  )
}

export function BalanceSheetScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const [asOf, setAsOf] = useState('')
  const [report, setReport] = useState<BalanceSheetReport | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.balanceSheet(asOf || undefined).then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }
  useEffect(refresh, [asOf]) // eslint-disable-line react-hooks/exhaustive-deps

  const exportExcel = () => {
    if (!report) return
    const section = (title: string, rows: { code: string; name: string; balance: string }[], total: string) => ({
      title, columns: [{ label: 'Code', key: 'code' }, { label: 'Name', key: 'name' }, { label: 'Amount', key: 'balance', align: 'right' as const, format: 'money' as const }],
      rows: rows.map((r) => ({ code: r.code, name: r.name, balance: Number(r.balance) })),
      totalRow: { name: `Total ${title}`, balance: Number(total) }, emptyText: 'Nothing here yet',
    })
    void exportReportToExcel({
      sheetName: 'Balance Sheet', reportTitle: 'Balance Sheet', meta: `As of ${report.asOf}`,
      sections: [
        section('Assets', report.assets, report.totalAssets), section('Liabilities', report.liabilities, report.totalLiabilities), section('Equity', report.equity, report.totalEquity),
        { columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right', format: 'money' }], rows: [{ metric: 'Total Liabilities + Equity', value: Number(report.totalLiabilitiesAndEquity) }] },
      ],
    }, `balance-sheet-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await accountingReportsApi.pdf('balance-sheet', asOf ? { as_of: asOf } : {}), `balance-sheet-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Accounting" title="Balance Sheet" icon="grid"
        actions={report && <div className="row gap-3" style={{ alignItems: 'center' }}>
          <Badge tone={report.balanced ? 'green' : 'red'} dot>{report.balanced ? 'Balanced' : 'Out of balance'}</Badge>
          <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />
        </div>} />
      <Card style={{ marginBottom: 16 }}><Field label="As Of"><Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} style={{ maxWidth: 200 }} /></Field></Card>
      {loading ? <Card><LoadingBlock /></Card> : report && (
        <div className="col gap-4">
          <BsSection title="Assets" rows={report.assets} total={report.totalAssets} />
          <BsSection title="Liabilities" rows={report.liabilities} total={report.totalLiabilities} />
          <BsSection title="Equity" rows={report.equity} total={report.totalEquity} />
          <Card>
            <div className="row between" style={{ fontSize: 16, fontWeight: 700 }}>
              <span>Total Liabilities + Equity</span>
              <span style={{ fontFamily: 'monospace' }}>{money(report.totalLiabilitiesAndEquity)}</span>
            </div>
          </Card>
        </div>
      )}
    </div>
  )
}

/* ---------------------------- Cash Flow Statement ---------------------------- */
/* Direct method: for every posted entry touching Cash in Hand/Bank Accounts
   in the period, groups the *other* side of that entry by account and
   buckets Operating/Investing/Financing (see AccountingReportController::
   cashFlow's docblock). Nothing here is persisted or configurable — the
   Investing/Financing account list is a fixed backend const (Fixed Assets,
   Share Capital); everything else nets into Operating. */

function CfSection({ title, rows, total, tone }: { title: string; rows: { code: string; name: string; amount: number }[]; total: string; tone?: 'good' | 'bad' }) {
  return (
    <Card pad={0}>
      <div className="eyebrow" style={{ padding: '14px 20px 0' }}>{title}</div>
      <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Amount', align: 'right' }]} rows={rows} empty="No cash movement in this range"
        render={(r) => <><Td mono>{r.code}</Td><Td>{r.name}</Td><Td align="right" mono>{money(r.amount)}</Td></>} />
      <div className="row between" style={{ padding: '10px 20px', borderTop: '1px solid var(--line)', fontWeight: 700 }}>
        <span>Net Cash from {title}</span>
        <span style={{ fontFamily: 'monospace', color: tone === 'good' ? 'var(--good)' : tone === 'bad' ? 'var(--bad)' : undefined }}>{money(total)}</span>
      </div>
    </Card>
  )
}

export function CashFlowScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [report, setReport] = useState<CashFlowReport | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.cashFlow({ from: fromDate || undefined, to: toDate || undefined }).then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }
  useEffect(refresh, [fromDate, toDate]) // eslint-disable-line react-hooks/exhaustive-deps

  const exportExcel = () => {
    if (!report) return
    const section = (title: string, rows: { code: string; name: string; amount: number }[], total: string) => ({
      title, columns: [{ label: 'Code', key: 'code' }, { label: 'Name', key: 'name' }, { label: 'Amount', key: 'amount', align: 'right' as const, format: 'money' as const }],
      rows, totalRow: { name: `Net Cash from ${title}`, amount: Number(total) }, emptyText: 'No cash movement in this range',
    })
    void exportReportToExcel({
      sheetName: 'Cash Flow', reportTitle: 'Cash Flow Statement', meta: `Period: ${report.from} to ${report.to}`,
      sections: [
        section('Operating Activities', report.operatingActivities, report.netCashFromOperating),
        section('Investing Activities', report.investingActivities, report.netCashFromInvesting),
        section('Financing Activities', report.financingActivities, report.netCashFromFinancing),
        {
          columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right', format: 'money' }],
          rows: [{ metric: 'Opening Cash', value: Number(report.openingCash) }, { metric: 'Net Change in Cash', value: Number(report.netChangeInCash) }, { metric: 'Closing Cash', value: Number(report.closingCash) }],
        },
      ],
    }, `cash-flow-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await accountingReportsApi.pdf('cash-flow', { from: fromDate || undefined, to: toDate || undefined }), `cash-flow-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Accounting" title="Cash Flow Statement" icon="swap"
        actions={report && <div className="row gap-3" style={{ alignItems: 'center' }}>
          <Badge tone={report.reconciled ? 'green' : 'red'} dot>{report.reconciled ? 'Reconciled' : 'Out of balance'}</Badge>
          <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />
        </div>} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
        </div>
      </Card>
      {loading ? <Card><LoadingBlock /></Card> : report && (
        <div className="col gap-4">
          <CfSection title="Operating Activities" rows={report.operatingActivities} total={report.netCashFromOperating} />
          <CfSection title="Investing Activities" rows={report.investingActivities} total={report.netCashFromInvesting} />
          <CfSection title="Financing Activities" rows={report.financingActivities} total={report.netCashFromFinancing} />
          <Card>
            <div className="col gap-2">
              <div className="row between"><span>Opening Cash</span><span style={{ fontFamily: 'monospace' }}>{money(report.openingCash)}</span></div>
              <div className="row between" style={{ fontSize: 16, fontWeight: 700 }}>
                <span>Net Change in Cash</span>
                <span style={{ fontFamily: 'monospace', color: Number(report.netChangeInCash) >= 0 ? 'var(--good)' : 'var(--bad)' }}>{money(report.netChangeInCash)}</span>
              </div>
              <div className="row between" style={{ borderTop: '1px solid var(--line)', paddingTop: 8, fontWeight: 700 }}>
                <span>Closing Cash</span><span style={{ fontFamily: 'monospace' }}>{money(report.closingCash)}</span>
              </div>
            </div>
          </Card>
        </div>
      )}
    </div>
  )
}

/* ---------------------------- Ledger Drift ---------------------------- */
/* Cash/bank tills keep their own current_balance ("book") alongside a fully
   derived chart_of_accounts/journal_lines balance for the same account (see
   the ledger-account-link migration) — two independent ledgers for the same
   money, with nothing enforcing they agree. This checks that they actually
   do, instead of assuming it. */

export function LedgerDriftScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const [asOf, setAsOf] = useState('')
  const [report, setReport] = useState<LedgerDriftReport | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.ledgerDrift(asOf || undefined).then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }
  useEffect(refresh, [asOf]) // eslint-disable-line react-hooks/exhaustive-deps

  const exportExcel = () => {
    if (!report) return
    void exportFlatTableToExcel({
      reportTitle: 'Ledger Drift', meta: `As of ${report.asOf}`,
      columns: [
        { label: 'Account', key: 'name' }, { label: 'Kind', key: 'kind', align: 'center' }, { label: 'GL Account', key: 'glAccount' },
        { label: 'Book Balance', key: 'book', align: 'right', format: 'money' }, { label: 'GL Balance', key: 'gl', align: 'right', format: 'money' }, { label: 'Variance', key: 'variance', align: 'right', format: 'money' },
      ],
      rows: report.rows.map((r) => ({ name: r.name, kind: r.kind, glAccount: r.glAccountCode, book: Number(r.bookBalance), gl: Number(r.glBalance), variance: Number(r.variance) })),
      emptyText: 'No cash tills or bank accounts linked to the ledger yet',
    }, `ledger-drift-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await accountingReportsApi.pdf('ledger-drift', asOf ? { as_of: asOf } : {}), `ledger-drift-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Accounting" title="Ledger Drift" icon="adjust"
        sub="Book balance (cash/bank account) vs. the general ledger's own derived balance for the same account"
        actions={report && <div className="row gap-3" style={{ alignItems: 'center' }}>
          <Badge tone={report.hasDrift ? 'red' : 'green'} dot>{report.hasDrift ? 'Drift found' : 'In agreement'}</Badge>
          <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />
        </div>} />
      <Card style={{ marginBottom: 16 }}><Field label="As Of"><Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} style={{ maxWidth: 200 }} /></Field></Card>
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Account' }, { label: 'Kind', align: 'center' }, { label: 'GL Account' }, { label: 'Book Balance', align: 'right' }, { label: 'GL Balance', align: 'right' }, { label: 'Variance', align: 'right' }]}
          empty="No cash tills or bank accounts linked to the ledger yet" rows={report?.rows || []}
          render={(r) => {
            const drifted = Number(r.variance) !== 0
            return <>
              <Td style={{ fontWeight: 600 }}>{r.name}</Td>
              <Td align="center"><Badge tone={r.kind === 'CashAccount' ? 'blue' : 'amber'} dot>{r.kind === 'CashAccount' ? 'Cash' : 'Bank'}</Badge></Td>
              <Td mono c="var(--tx-2)">{r.glAccountCode}</Td>
              <Td align="right" mono>{money(r.bookBalance)}</Td>
              <Td align="right" mono>{money(r.glBalance)}</Td>
              <Td align="right" mono style={{ fontWeight: 700, color: drifted ? 'var(--bad)' : undefined }}>{money(r.variance)}</Td>
            </>
          }} />}
      </Card>
    </div>
  )
}
