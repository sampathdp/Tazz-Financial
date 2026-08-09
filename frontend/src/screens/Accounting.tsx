/* Tazz Investment & Credit — Accounting: Chart of Accounts, Journal Entries,
   General Ledger, Trial Balance, Profit & Loss, Balance Sheet.
   None of these report screens has its own data model — every figure comes
   straight from journal_lines (see ChartOfAccount::balancesAsOf on the
   backend), so what you see here can never disagree with the ledger itself. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, ErrorBanner } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  chartOfAccountsApi, journalEntriesApi, accountingReportsApi,
  type ChartOfAccount, type AccountType, type JournalEntry, type GeneralLedgerReport,
  type TrialBalanceRow, type ProfitLossReport, type BalanceSheetReport, type LedgerDriftReport, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const typeTone = (t: AccountType) => t === 'Asset' ? 'blue' : t === 'Liability' ? 'amber' : t === 'Equity' ? 'neutral' : t === 'Income' ? 'green' : 'red'

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
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
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
  const [loading, setLoading] = useState(true)
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [status, setStatus] = useState<'' | 'Posted' | 'Reversed'>('')
  const [viewing, setViewing] = useState<JournalEntry | null>(null)

  const refresh = () => {
    setLoading(true)
    journalEntriesApi.list({ from: fromDate || undefined, to: toDate || undefined, status: status || undefined })
      .then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [fromDate, toDate, status]) // eslint-disable-line react-hooks/exhaustive-deps

  const total = (e: JournalEntry) => (e.lines || []).reduce((s, l) => s + Number(l.debit || 0), 0)

  return (
    <div>
      <PageHead crumbs="Accounting" title="Journal Entries" icon="doc" sub={`${rows.length} entries`} />
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
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Date' }, { label: 'Code' }, { label: 'Description' }, { label: 'Reference' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }]}
          empty="No journal entries yet" rows={rows} onRow={(e) => setViewing(e)}
          render={(e) => <>
            <Td>{dateOnly(e.entryDate)}</Td>
            <Td mono>{e.code}</Td>
            <Td>{e.description || '—'}</Td>
            <Td c="var(--tx-2)" style={{ fontSize: 12 }}>{e.sourceType ? e.sourceType + ' #' + e.sourceId : '—'}</Td>
            <Td align="right" mono>{money(total(e))}</Td>
            <Td align="center"><Badge tone={e.status === 'Posted' ? 'green' : 'neutral'} dot>{e.status}</Badge></Td>
          </>} />}
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

  return (
    <div>
      <PageHead crumbs="Accounting" title="General Ledger" icon="layers" />
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
         loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
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

  return (
    <div>
      <PageHead crumbs="Accounting" title="Trial Balance" icon="chart" />
      <Card style={{ marginBottom: 16 }}><Field label="As Of"><Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} style={{ maxWidth: 200 }} /></Field></Card>
      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
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

export function ProfitLossScreen({ user: _me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [basis, setBasis] = useState<'cash' | 'accrual'>('cash')
  const [report, setReport] = useState<ProfitLossReport | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = () => {
    setLoading(true)
    accountingReportsApi.profitLoss({ from: fromDate || undefined, to: toDate || undefined, basis }).then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }
  useEffect(refresh, [fromDate, toDate, basis]) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div>
      <PageHead crumbs="Accounting" title="Profit & Loss" icon="pie" />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap" style={{ alignItems: 'flex-end' }}>
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          <Field label="Basis">
            <div className="row gap-1">
              <Btn size="sm" variant="ghost" active={basis === 'cash'} onClick={() => setBasis('cash')}>Cash Basis</Btn>
              <Btn size="sm" variant="ghost" active={basis === 'accrual'} onClick={() => setBasis('accrual')}>Accrual Basis</Btn>
            </div>
          </Field>
        </div>
        <div className="t-2" style={{ fontSize: 11.5, marginTop: 10 }}>
          {basis === 'cash'
            ? 'Cash Basis: income counted when actually collected — today\'s figures.'
            : 'Accrual Basis: interest income counted as it\'s scheduled to be earned, not when collected. Only Interest Income changes below — everything else (charges, penalty, expenses) stays the same either way.'}
        </div>
      </Card>
      {loading ? <Card><div style={{ padding: 20, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div></Card> : report && (
        <div className="col gap-4">
          <Card pad={0}>
            <div className="eyebrow" style={{ padding: '14px 20px 0' }}>Income</div>
            <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Amount', align: 'right' }]} rows={report.income} empty="No income posted in this range"
              render={(r) => <><Td mono>{r.code}</Td><Td>{r.name}</Td><Td align="right" mono>{money(r.balance)}</Td></>} />
            <div className="row between" style={{ padding: '10px 20px', borderTop: '1px solid var(--line)', fontWeight: 700 }}>
              <span>Total Income</span><span style={{ fontFamily: 'monospace' }}>{money(report.totalIncome)}</span>
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
            <div className="row between" style={{ fontSize: 16, fontWeight: 700 }}>
              <span>Net Profit</span>
              <span style={{ fontFamily: 'monospace', color: Number(report.netProfit) >= 0 ? 'var(--good)' : 'var(--bad)' }}>{money(report.netProfit)}</span>
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

  return (
    <div>
      <PageHead crumbs="Accounting" title="Balance Sheet" icon="grid"
        actions={report && <Badge tone={report.balanced ? 'green' : 'red'} dot>{report.balanced ? 'Balanced' : 'Out of balance'}</Badge>} />
      <Card style={{ marginBottom: 16 }}><Field label="As Of"><Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} style={{ maxWidth: 200 }} /></Field></Card>
      {loading ? <Card><div style={{ padding: 20, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div></Card> : report && (
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

  return (
    <div>
      <PageHead crumbs="Accounting" title="Ledger Drift" icon="adjust"
        sub="Book balance (cash/bank account) vs. the general ledger's own derived balance for the same account"
        actions={report && <Badge tone={report.hasDrift ? 'red' : 'green'} dot>{report.hasDrift ? 'Drift found' : 'In agreement'}</Badge>} />
      <Card style={{ marginBottom: 16 }}><Field label="As Of"><Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} style={{ maxWidth: 200 }} /></Field></Card>
      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
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
