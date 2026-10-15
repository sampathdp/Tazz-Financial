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
import { BranchFilter, useBranchFilter } from '../components/BranchFilter'
import {
  chartOfAccountsApi, journalEntriesApi, accountingReportsApi, manualJournalEntriesApi,
  type ChartOfAccount, type AccountType, type JournalEntry, type GeneralLedgerReport,
  type TrialBalanceReport, type InterBranchReport, type ProfitLossReport, type PLMonthlyRow, type BalanceSheetReport, type CashFlowReport, type LedgerDriftReport, type AuthUser,
  type ManualJournalEntry, type JournalAccountOption,
} from '../api'
import { Can, canApprove } from '../lib/permissions'
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
                {r.name}{r.isSystem && <Icon n="shield" s={12} c="var(--tx-3)" />}{r.isPostable === false && <Badge tone="blue">Header</Badge>}
              </span>
            </Td>
            <Td align="center"><Badge tone={typeTone(r.accountType)} dot>{r.accountType}</Badge></Td>
            <Td>{r.parent ? `${r.parent.code} ${r.parent.name}` : '—'}</Td>
            <Td align="right" mono>{r.isPostable === false ? <span title="Total of its child accounts (a header holds no postings of its own)">{money(r.rollupBalance)} <span className="t-2">group</span></span> : money(r.balance)}</Td>
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

/* ---------------------- Manual Journal Entries ------------------------ */

type MjeLine = { accountId: number | ''; debit: string; credit: string; description: string }
const blankMjeLine = (): MjeLine => ({ accountId: '', debit: '', credit: '', description: '' })
const mjeStatusTone = (s: ManualJournalEntry['status']) => s === 'Approved' ? 'green' : s === 'Rejected' ? 'red' : 'amber'

export function ManualJournalEntriesScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [entries, setEntries] = useState<ManualJournalEntry[]>([])
  const [accounts, setAccounts] = useState<JournalAccountOption[]>([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [view, setView] = useState<ManualJournalEntry | null>(null)
  const [entryDate, setEntryDate] = useState(today())
  const [description, setDescription] = useState('')
  const [reason, setReason] = useState('')
  const [lines, setLines] = useState<MjeLine[]>([blankMjeLine(), blankMjeLine()])
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  const refresh = () => {
    setLoading(true)
    manualJournalEntriesApi.list().then(setEntries).catch(() => setEntries([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])
  useEffect(() => { manualJournalEntriesApi.accountOptions().then(setAccounts).catch(() => setAccounts([])) }, [])

  const totalDebit = lines.reduce((s, l) => s + Number(l.debit || 0), 0)
  const totalCredit = lines.reduce((s, l) => s + Number(l.credit || 0), 0)
  const balanced = totalDebit > 0 && Math.abs(totalDebit - totalCredit) < 0.005

  const resetForm = () => { setEntryDate(today()); setDescription(''); setReason(''); setLines([blankMjeLine(), blankMjeLine()]); setErr('') }
  const setLine = (i: number, patch: Partial<MjeLine>) => setLines((ls) => ls.map((l, j) => j === i ? { ...l, ...patch } : l))

  const submit = async () => {
    const clean = lines
      .filter((l) => l.accountId && (Number(l.debit) > 0 || Number(l.credit) > 0))
      .map((l) => ({ accountId: Number(l.accountId), debit: Number(l.debit || 0), credit: Number(l.credit || 0), description: l.description || undefined }))
    if (clean.length < 2) { setErr('Add at least two lines with an account and an amount.'); return }
    if (clean.some((l) => l.debit > 0 && l.credit > 0)) { setErr('Each line is a debit or a credit, not both.'); return }
    if (!balanced) { setErr(`Entry does not balance — debit ${money(totalDebit)} vs credit ${money(totalCredit)}.`); return }
    setBusy(true); setErr('')
    try {
      await manualJournalEntriesApi.create({ entryDate, description: description || undefined, reason: reason || undefined, lines: clean })
      notify.success('Journal entry submitted', 'Awaiting approval')
      setOpen(false); resetForm(); refresh()
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not submit the entry'))
    } finally { setBusy(false) }
  }

  const approve = async (e: ManualJournalEntry) => {
    try { await manualJournalEntriesApi.approve(e.id); notify.success('Entry approved & posted'); setView(null); refresh() }
    catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
  }
  const reject = async (e: ManualJournalEntry) => {
    const r = await notify.prompt({ title: `Reject entry ${e.code}?`, msg: 'Give a reason — the maker can correct and resubmit.', placeholder: 'Reason', danger: true, okText: 'Reject' })
    if (r === null) return
    try { await manualJournalEntriesApi.reject(e.id, r || undefined); notify.success('Entry rejected'); setView(null); refresh() }
    catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }

  const canReview = (e: ManualJournalEntry) => e.status === 'PendingApproval' && canApprove(me, 'manual_journal_entry') && e.requestedBy !== me?.id

  return (
    <div>
      <PageHead crumbs="Accounting" title="Manual Journal Entries" icon="edit"
        actions={<Can user={me} module="Manual Journal Entries" action="Create"><Btn variant="primary" icon="plus" onClick={() => { resetForm(); setOpen(true) }}>New Entry</Btn></Can>} />

      <Card style={{ marginBottom: 16 }}>
        <div className="t-2" style={{ fontSize: 12.5 }}>
          Adjusting, opening-balance and correcting entries. Every entry needs a second authorised person to approve it before it posts to the general ledger — it is posted through the same writer as every automatic entry, so it can never be unbalanced.
        </div>
      </Card>

      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Code' }, { label: 'Date' }, { label: 'Description' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: 'Posted As' }]}
          empty="No manual journal entries yet" rows={entries} onRow={(e) => setView(e)}
          render={(e) => {
            const amt = e.lines.reduce((s, l) => s + Number(l.debit || 0), 0)
            return <>
              <Td mono>{e.code}</Td>
              <Td>{dateOnly(e.entryDate)}</Td>
              <Td>{e.description || <span className="t-3">—</span>}</Td>
              <Td align="right" mono>{money(amt)}</Td>
              <Td align="center"><Badge tone={mjeStatusTone(e.status)} dot>{e.status === 'PendingApproval' ? 'Pending' : e.status}</Badge></Td>
              <Td mono>{e.journalEntry?.code || '—'}</Td>
            </>
          }} />}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="New Manual Journal Entry" width={760}
        footer={<>
          <Btn variant="solid" onClick={() => setOpen(false)}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={submit} disabled={busy || !balanced}>{busy ? 'Submitting…' : 'Submit for Approval'}</Btn>
        </>}>
        <div className="col gap-3">
          <ErrorBanner error={err} style={{ marginBottom: 0 }} />
          <div className="row gap-3 wrap">
            <Field label="Entry Date"><Input type="date" value={entryDate} onChange={(e) => setEntryDate(e.target.value)} /></Field>
            <Field label="Description" style={{ flex: 1, minWidth: 220 }}><Input value={description} onChange={(e) => setDescription(e.target.value)} placeholder="e.g. Opening balance – loan book" /></Field>
          </div>
          <Field label="Reason / Note (optional)"><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why this entry is being made" /></Field>

          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead><tr>
                {['Account', 'Debit', 'Credit', 'Line note', ''].map((h, i) => <th key={i} style={{ textAlign: i === 1 || i === 2 ? 'right' : 'left', padding: '6px 8px', fontSize: 11, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.05em' }}>{h}</th>)}
              </tr></thead>
              <tbody>
                {lines.map((l, i) => (
                  <tr key={i}>
                    <td style={{ padding: '4px 8px' }}>
                      <Select value={l.accountId} onChange={(e) => setLine(i, { accountId: Number(e.target.value) || '' })} style={{ minWidth: 220 }}>
                        <option value="">Select account…</option>
                        {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
                      </Select>
                    </td>
                    <td style={{ padding: '4px 8px', textAlign: 'right' }}>
                      <Input type="number" value={l.debit} onChange={(e) => setLine(i, { debit: e.target.value, credit: e.target.value ? '' : l.credit })} style={{ maxWidth: 130, textAlign: 'right' }} />
                    </td>
                    <td style={{ padding: '4px 8px', textAlign: 'right' }}>
                      <Input type="number" value={l.credit} onChange={(e) => setLine(i, { credit: e.target.value, debit: e.target.value ? '' : l.debit })} style={{ maxWidth: 130, textAlign: 'right' }} />
                    </td>
                    <td style={{ padding: '4px 8px' }}>
                      <Input value={l.description} onChange={(e) => setLine(i, { description: e.target.value })} style={{ minWidth: 160 }} />
                    </td>
                    <td style={{ padding: '4px 8px' }}>
                      {lines.length > 2 && <Btn size="sm" variant="solid" icon="trash" onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))} />}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr style={{ fontWeight: 700, borderTop: '1px solid var(--line)' }}>
                  <td style={{ padding: '8px' }}>
                    <Btn size="sm" variant="solid" icon="plus" onClick={() => setLines((ls) => [...ls, blankMjeLine()])}>Add line</Btn>
                  </td>
                  <td style={{ padding: '8px', textAlign: 'right', fontFamily: 'monospace' }}>{money(totalDebit)}</td>
                  <td style={{ padding: '8px', textAlign: 'right', fontFamily: 'monospace' }}>{money(totalCredit)}</td>
                  <td colSpan={2} style={{ padding: '8px' }}>
                    <Badge tone={balanced ? 'green' : 'red'} dot>{balanced ? 'balanced' : `off by ${money(Math.abs(totalDebit - totalCredit))}`}</Badge>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </Modal>

      <Modal open={!!view} onClose={() => setView(null)} width={760} title={view ? `Journal Entry ${view.code}` : ''}
        footer={view && canReview(view) ? <>
          <Btn variant="danger" icon="x" onClick={() => reject(view)}>Reject</Btn>
          <Btn variant="primary" icon="check" onClick={() => approve(view)}>Approve &amp; Post</Btn>
        </> : <Btn variant="solid" onClick={() => setView(null)}>Close</Btn>}>
        {view && (
          <div className="col gap-3">
            <div className="row gap-4 wrap t-2" style={{ fontSize: 12.5 }}>
              <span>Date: <b style={{ color: 'var(--tx-1)' }}>{dateOnly(view.entryDate)}</b></span>
              <span>Status: <Badge tone={mjeStatusTone(view.status)} dot>{view.status}</Badge></span>
              <span>Requested by: <b style={{ color: 'var(--tx-1)' }}>{view.requester?.name || '—'}</b></span>
              {view.journalEntry?.code && <span>Posted as: <b style={{ color: 'var(--tx-1)' }}>{view.journalEntry.code}</b></span>}
            </div>
            {view.description && <div style={{ fontSize: 13 }}>{view.description}</div>}
            {view.reason && <div className="t-2" style={{ fontSize: 12.5 }}>Note: {view.reason}</div>}
            {view.rejectReason && <div style={{ fontSize: 12.5, color: 'var(--bad)' }}>Rejected: {view.rejectReason}</div>}
            <Table cols={[{ label: 'Account' }, { label: 'Debit', align: 'right' }, { label: 'Credit', align: 'right' }, { label: 'Note' }]}
              empty="No lines" rows={view.lines}
              render={(l) => <>
                <Td mono>{l.account ? `${l.account.code} — ${l.account.name}` : l.accountId}</Td>
                <Td align="right" mono>{Number(l.debit) > 0 ? money(l.debit) : '—'}</Td>
                <Td align="right" mono>{Number(l.credit) > 0 ? money(l.credit) : '—'}</Td>
                <Td>{l.description || '—'}</Td>
              </>} />
            {canReview(view) && view.requestedBy === me?.id && <div className="t-3" style={{ fontSize: 12 }}>You cannot approve your own entry.</div>}
          </div>
        )}
      </Modal>
    </div>
  )
}

/* ---------------------------- Trial Balance ---------------------------- */

export function TrialBalanceScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const { branches, branchId, setBranchId } = useBranchFilter(_me)
  const [asOf, setAsOf] = useState(today())
  const [report, setReport] = useState<TrialBalanceReport | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.trialBalance(asOf || undefined, branchId).then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }
  useEffect(refresh, [asOf, branchId]) // eslint-disable-line react-hooks/exhaustive-deps

  const rows = report?.rows ?? []
  const totals = report?.totals
  // A trial balance foots when the debit-balance and credit-balance columns are equal;
  // that equality — not the always-equal debit/credit turnover — is the integrity check.
  const totalDebit = Number(totals?.debit ?? 0)
  const totalCredit = Number(totals?.credit ?? 0)
  const balanced = totals?.balanced ?? true
  const branchView = totals?.balanced === null
  const abnormal = rows.filter((r) => r.isAbnormal)

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Trial Balance', meta: `As of ${report?.asOf ?? asOf}`,
      columns: [
        { label: 'Code', key: 'code' }, { label: 'Name', key: 'name' }, { label: 'Type', key: 'type', align: 'center' },
        { label: 'Debit', key: 'debit', align: 'right', format: 'money' }, { label: 'Credit', key: 'credit', align: 'right', format: 'money' },
      ],
      rows: rows.map((r) => ({
        code: r.code, name: r.name + (r.isAbnormal ? ' (abnormal balance)' : ''), type: r.accountType,
        debit: Number(r.debitBalance) || '', credit: Number(r.creditBalance) || '',
      })),
      totalRow: { name: balanced ? 'Totals (balanced)' : `Totals — OUT OF BALANCE by ${money(totals?.difference ?? 0)}`, debit: totalDebit, credit: totalCredit },
      emptyText: 'No accounts yet',
    }, `trial-balance-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await accountingReportsApi.pdf('trial-balance', { ...(asOf ? { as_of: asOf } : {}), ...(branchId ? { branch_id: branchId } : {}) }), `trial-balance-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Accounting" title="Trial Balance" icon="chart"
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}><div className="row gap-3 wrap"><Field label="As Of"><Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} style={{ maxWidth: 200 }} /></Field><BranchFilter user={_me} branches={branches} branchId={branchId} onChange={setBranchId} /></div></Card>

      {!loading && !balanced && !branchView && (
        <Card style={{ marginBottom: 16, borderColor: 'var(--bad)', background: 'var(--bad-dim)' }}>
          <div className="row gap-2" style={{ alignItems: 'center', fontWeight: 600, color: 'var(--bad)' }}>
            <Icon n="alert" /> Ledger is out of balance by {money(totals?.difference ?? 0)} — debit balances {money(totalDebit)} vs credit balances {money(totalCredit)}.
          </div>
          <div className="t-2" style={{ fontSize: 12, marginTop: 6 }}>Every posting is internally balanced, so a nonzero difference here means data was changed outside the ledger writer. Check the Ledger Drift report.</div>
        </Card>
      )}
      {!loading && balanced && abnormal.length > 0 && (
        <Card style={{ marginBottom: 16, borderColor: 'var(--warn)', background: 'var(--warn-dim)' }}>
          <div className="row gap-2" style={{ alignItems: 'center', fontWeight: 600 }}>
            <Icon n="alert" /> {abnormal.length} account{abnormal.length > 1 ? 's' : ''} sitting on the wrong side of its normal balance
          </div>
          <div className="t-2" style={{ fontSize: 12, marginTop: 6 }}>
            {abnormal.map((r) => `${r.code} ${r.name}`).join(' · ')}. Usually a missing opening balance or an unposted counter-entry (e.g. a bank/cheque disbursement).
          </div>
        </Card>
      )}

      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Type', align: 'center' }, { label: 'Debit', align: 'right' }, { label: 'Credit', align: 'right' }]}
          empty="No accounts yet" rows={rows}
          render={(r) => <>
            <Td mono>{r.code}</Td>
            <Td>{r.name}{r.isAbnormal && <Badge tone="red" dot>abnormal</Badge>}</Td>
            <Td align="center"><Badge tone={typeTone(r.accountType)} dot>{r.accountType}</Badge></Td>
            <Td align="right" mono c={r.isAbnormal && Number(r.debitBalance) > 0 ? 'var(--danger)' : undefined}>{Number(r.debitBalance) > 0 ? money(r.debitBalance) : '—'}</Td>
            <Td align="right" mono c={r.isAbnormal && Number(r.creditBalance) > 0 ? 'var(--danger)' : undefined}>{Number(r.creditBalance) > 0 ? money(r.creditBalance) : '—'}</Td>
          </>} />}
        {!loading && rows.length > 0 && (
          <div className="row between" style={{ padding: '12px 20px', borderTop: '1px solid var(--line)', fontWeight: 700 }}>
            <span className="row gap-2" style={{ alignItems: 'center' }}>
              Totals
              {branchView ? <Badge tone="blue" dot>branch view</Badge> : <Badge tone={balanced ? 'green' : 'red'} dot>{balanced ? 'balanced' : `off by ${money(totals?.difference ?? 0)}`}</Badge>}
            </span>
            <div className="row gap-4">
              <span style={{ fontFamily: 'monospace' }}>{money(totalDebit)}</span>
              <span style={{ fontFamily: 'monospace' }}>{money(totalCredit)}</span>
            </div>
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
  { key: 'miscellaneousIncome', label: 'Miscellaneous Income (Other Income, Cash Overage, other)' },
  { key: 'netIncome', label: 'Net Income/(Expense)', bold: true },
  { key: 'staffCost.salary', label: 'Salary/EPF/ETF', indent: true },
  { key: 'staffCost.allowances', label: 'Allowances', indent: true },
  { key: 'staffCost.total', label: 'Staff Cost' },
  { key: 'otherOperatingExpenses', label: 'Other Operating Expenses' },
  { key: 'profitBeforeHoAbsorption', label: 'Profit/(Loss) Before Absorption of HO Exp', bold: true },
  { key: 'hoExpAbsorption', label: 'HO Exp Absorption' },
  { key: 'netProfitBeforeTaxImpairment', label: 'Net Profit Before Tax & Impairment', bold: true },
  { key: 'provisioningForImpairment', label: 'Provisioning for Impairment' },
  { key: 'badDebtRecovery', label: 'Bad Debt Recovery Income' },
  { key: 'netProfitAfterImpairment', label: 'Net Profit After Provision for Impairment', bold: true },
  { key: 'vatNbt', label: 'VAT & NBT on Finance Services' },
  { key: 'incomeTax', label: 'Income Tax' },
  { key: 'netProfitAfterTax', label: 'Net Profit After Tax', bold: true },
  { key: 'netProfitBeforeInterZonal', label: 'Net Profit Before Inter Zonal Interest' },
]

export function ProfitLossScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const { branches, branchId, setBranchId } = useBranchFilter(_me)
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [report, setReport] = useState<ProfitLossReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [exportingPdf, setExportingPdf] = useState(false)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.profitLoss({ from: fromDate || undefined, to: toDate || undefined, branchId }).then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }
  useEffect(refresh, [fromDate, toDate, branchId]) // eslint-disable-line react-hooks/exhaustive-deps

  const [exportingExcel, setExportingExcel] = useState(false)
  const exportExcel = async () => {
    if (!report) return
    setExportingExcel(true)
    try {
      let monthly: PLMonthlyRow[] = []
      try {
        if (fromDate && toDate) monthly = (await accountingReportsApi.profitLossMonthly({ from: fromDate, to: toDate, branchId })).months
      } catch (ex: any) {
        notify.error('Monthly breakdown failed', getErrorText(ex, 'Could not load month-by-month totals — exporting without them'))
      }

      await exportReportToExcel({
        sheetName: 'Profit & Loss',
        reportTitle: 'Profit & Loss Statement',
        subtitle: 'Cash and Accrual Basis',
        meta: `Period: ${fromDate || 'Inception'} to ${toDate || 'Today'}  •  Generated: ${new Date().toLocaleString()}`,
        sections: [
          ...(monthly.length > 0 ? [{
            title: 'Monthly Summary',
            columns: [
              { label: 'Month', key: 'label' }, { label: 'Income (Cash)', key: 'incomeCash', align: 'right' as const, format: 'money' as const },
              { label: 'Income (Accrual)', key: 'incomeAccrual', align: 'right' as const, format: 'money' as const },
              { label: 'Expense (Cash)', key: 'expenseCash', align: 'right' as const, format: 'money' as const },
              { label: 'Expense (Accrual)', key: 'expenseAccrual', align: 'right' as const, format: 'money' as const },
              { label: 'Net Profit (Cash)', key: 'netCash', align: 'right' as const, format: 'money' as const },
              { label: 'Net Profit (Accrual)', key: 'netAccrual', align: 'right' as const, format: 'money' as const },
            ],
            rows: monthly.map((m) => ({
              label: m.label, incomeCash: Number(m.totalIncomeCash), incomeAccrual: Number(m.totalIncomeAccrual),
              expenseCash: Number(m.totalExpenseCash), expenseAccrual: Number(m.totalExpenseAccrual),
              netCash: Number(m.netProfitCash), netAccrual: Number(m.netProfitAccrual),
            })),
          }] : []),
          {
            title: 'P&L Waterfall',
            columns: [{ label: 'Line', key: 'label' }, { label: 'Cash Basis', key: 'cash', align: 'right', format: 'money' }, { label: 'Accrual Basis', key: 'accrual', align: 'right', format: 'money' }, { label: 'Accrued, Not Yet Settled (Accrual − Cash)', key: 'difference', align: 'right', format: 'money' }],
            rows: WATERFALL_ROWS.map((r) => {
              const cash = Number(getPath(report.waterfallCash, r.key))
              const accrual = Number(getPath(report.waterfallAccrual, r.key))
              return { label: (r.indent ? '    ' : '') + r.label, cash, accrual, difference: accrual - cash }
            }),
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
            columns: [{ label: 'Code', key: 'code' }, { label: 'Name', key: 'name' }, { label: 'Cash Basis', key: 'cash', align: 'right', format: 'money' }, { label: 'Accrual Basis', key: 'accrual', align: 'right', format: 'money' }],
            rows: report.expense.map((r) => ({ code: r.code, name: r.name, cash: Number(r.cashBalance), accrual: Number(r.accrualBalance) })),
            totalRow: { code: '', name: 'Total Expense', cash: Number(report.totalExpenseCash), accrual: Number(report.totalExpenseAccrual) },
            emptyText: 'No expense posted in this range',
          },
          {
            title: 'Net Profit',
            columns: [{ label: 'Basis', key: 'basis' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' }],
            rows: [{ basis: 'Cash Basis', amount: Number(report.netProfitCash) }, { basis: 'Accrual Basis', amount: Number(report.netProfitAccrual) }],
          },
        ],
      }, `profit-loss-${new Date().toISOString().slice(0, 10)}.xlsx`)
    } finally {
      setExportingExcel(false)
    }
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
          <Btn variant="solid" size="sm" icon="download" onClick={exportExcel} disabled={exportingExcel}>{exportingExcel ? 'Exporting…' : 'Export Excel'}</Btn>
          <Btn variant="solid" size="sm" icon="download" onClick={exportPdf} disabled={exportingPdf}>{exportingPdf ? 'Exporting…' : 'Export PDF'}</Btn>
        </div>} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap" style={{ alignItems: 'flex-end' }}>
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
          <BranchFilter user={_me} branches={branches} branchId={branchId} onChange={setBranchId} />
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
        </div>
        <div className="t-2" style={{ fontSize: 11.5, marginTop: 10 }}>
          Cash Basis: income counted when collected, interest cost when paid. Accrual Basis: loan &amp; speed-draft interest income counted as it's scheduled to be earned, and FD / savings-deposit interest cost as it's scheduled to be incurred — not when the cash moves. All other lines are the same on both bases.
        </div>
      </Card>
      {loading ? <Card><LoadingBlock /></Card> : report && (
        <div className="col gap-4">
          <Card pad={0}>
            <div className="eyebrow" style={{ padding: '14px 20px 0' }}>P&L Waterfall</div>
            <Table cols={[{ label: 'Line' }, { label: 'Cash Basis', align: 'right' }, { label: 'Accrual Basis', align: 'right' }, { label: 'Accrued, Not Yet Settled (Accrual − Cash)', align: 'right' }]} rows={WATERFALL_ROWS} empty=""
              render={(r) => <>
                <Td style={{ paddingLeft: r.indent ? 36 : 20, fontWeight: r.bold ? 700 : 400 }}>{r.label}</Td>
                <Td align="right" mono style={{ fontWeight: r.bold ? 700 : 400 }}>{money(getPath(report.waterfallCash, r.key))}</Td>
                <Td align="right" mono style={{ fontWeight: r.bold ? 700 : 400 }}>{money(getPath(report.waterfallAccrual, r.key))}</Td>
                <Td align="right" mono style={{ fontWeight: r.bold ? 700 : 400 }}>{money(Number(getPath(report.waterfallAccrual, r.key)) - Number(getPath(report.waterfallCash, r.key)))}</Td>
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
            <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Cash Basis', align: 'right' }, { label: 'Accrual Basis', align: 'right' }]} rows={report.expense} empty="No expense posted in this range"
              render={(r) => <><Td mono>{r.code}</Td><Td>{r.name}</Td><Td align="right" mono>{money(r.cashBalance)}</Td><Td align="right" mono>{money(r.accrualBalance)}</Td></>} />
            <div className="row between" style={{ padding: '10px 20px', borderTop: '1px solid var(--line)', fontWeight: 700 }}>
              <span>Total Expense</span>
              <span className="row gap-4">
                <span style={{ fontFamily: 'monospace' }} title="Cash Basis">{money(report.totalExpenseCash)}</span>
                <span style={{ fontFamily: 'monospace' }} title="Accrual Basis">{money(report.totalExpenseAccrual)}</span>
              </span>
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
  const { branches, branchId, setBranchId } = useBranchFilter(_me)
  const [asOf, setAsOf] = useState('')
  const [report, setReport] = useState<BalanceSheetReport | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.balanceSheet(asOf || undefined, branchId).then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }
  useEffect(refresh, [asOf, branchId]) // eslint-disable-line react-hooks/exhaustive-deps

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
  const exportPdf = async () => downloadBlob(await accountingReportsApi.pdf('balance-sheet', { ...(asOf ? { as_of: asOf } : {}), ...(branchId ? { branch_id: branchId } : {}) }), `balance-sheet-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Accounting" title="Balance Sheet" icon="grid"
        actions={report && <div className="row gap-3" style={{ alignItems: 'center' }}>
          {report.balanced === null ? <Badge tone="blue" dot>Branch view</Badge> : <Badge tone={report.balanced ? 'green' : 'red'} dot>{report.balanced ? 'Balanced' : 'Out of balance'}</Badge>}
          <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />
        </div>} />
      <Card style={{ marginBottom: 16 }}><div className="row gap-3 wrap"><Field label="As Of"><Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} style={{ maxWidth: 200 }} /></Field><BranchFilter user={_me} branches={branches} branchId={branchId} onChange={setBranchId} /></div></Card>
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
  const { branches, branchId, setBranchId } = useBranchFilter(_me)
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [report, setReport] = useState<CashFlowReport | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.cashFlow({ from: fromDate || undefined, to: toDate || undefined, branchId }).then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }
  useEffect(refresh, [fromDate, toDate, branchId]) // eslint-disable-line react-hooks/exhaustive-deps

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
  const exportPdf = async () => downloadBlob(await accountingReportsApi.pdf('cash-flow', { from: fromDate || undefined, to: toDate || undefined, branch_id: branchId }), `cash-flow-${today()}.pdf`)

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
          <BranchFilter user={_me} branches={branches} branchId={branchId} onChange={setBranchId} />
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

/* ---------------------------- Inter-Branch Balances ---------------------------- */
/* When one branch's cash pays or receives money for another branch's transaction, each branch keeps its own
   balanced entry and the two settle through "due from" (1400) and "due to" (2400) accounts. This lists who
   owes whom as of a date; across the whole company the net is zero. */

export function InterBranchScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const { branches, branchId, setBranchId } = useBranchFilter(_me)
  const [asOf, setAsOf] = useState(today())
  const [report, setReport] = useState<InterBranchReport | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.interBranch(asOf || undefined, branchId).then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }
  useEffect(refresh, [asOf, branchId]) // eslint-disable-line react-hooks/exhaustive-deps

  const rows = report?.rows ?? []
  return (
    <div>
      <PageHead crumbs="Accounting" title="Inter-Branch Balances" icon="swap"
        actions={report && !report.branchFiltered && <Badge tone={Number(report.netTotal) === 0 ? 'green' : 'red'} dot>{Number(report.netTotal) === 0 ? 'Nets to zero' : `Off by ${money(report.netTotal)}`}</Badge>} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="As Of"><Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} style={{ maxWidth: 200 }} /></Field>
          <BranchFilter user={_me} branches={branches} branchId={branchId} onChange={setBranchId} />
        </div>
      </Card>
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Branch' }, { label: 'With branch' }, { label: 'Owed to it', align: 'right' }, { label: 'It owes', align: 'right' }, { label: 'Net', align: 'right' }]}
          empty="No inter-branch balances" rows={rows}
          render={(r) => <><Td>{r.branchName}</Td><Td>{r.counterpartyName}</Td><Td align="right" mono>{money(r.receivable)}</Td><Td align="right" mono>{money(r.payable)}</Td>
            <Td align="right" mono c={Number(r.net) < 0 ? 'var(--danger)' : undefined}>{money(r.net)}</Td></>} />}
      </Card>
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
