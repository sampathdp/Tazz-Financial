/* Tazz Investment & Credit — Finance module.
   The central cash-control layer: a Finance Dashboard (cash position at a
   glance) and the Finance Requests inbox — the single queue for every
   outbound payment and transfer. A request records intent; money moves only
   when Finance executes it through FinancePostingService. */
import { useEffect, useMemo, useState } from 'react'
import {
  Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, ErrorBanner, LoadingBlock, Pager,
} from '../components/ui'
import {
  financeRequestsApi, financeDashboardApi, cashAccountsApi, bankAccountsApi, chartOfAccountsApi, branchesApi,
  cashFloatsApi, cashSessionsApi, financeSettingsApi, api,
  type FinanceRequest, type FinanceRequestType, type FinanceRequestStatus, type FinanceDashboard,
  type CashAccount, type BankAccount, type ChartOfAccount, type Branch, type AuthUser,
  type CashFloat, type CashSession, type FinanceSettings,
} from '../api'
import { Can, canApprove, userCan } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const ago = (v?: string | null) => {
  if (!v) return '—'
  const d = Math.floor((Date.now() - new Date(v).getTime()) / 86_400_000)
  return d <= 0 ? 'today' : d === 1 ? '1 day' : `${d} days`
}

const REQUEST_TYPES: FinanceRequestType[] = [
  'LoanDisbursement', 'ExpensePayment', 'SupplierPayment', 'Refund', 'Advance',
  'CashFloat', 'BranchFunding', 'CommissionPayout', 'FdPayout', 'CashTransfer', 'Other',
]
const TRANSFER_TYPES: FinanceRequestType[] = ['CashTransfer', 'BranchFunding', 'CashFloat']

/** request_type -> approval_types code (mirrors FinanceRequest::APPROVAL_CODES on the backend). */
const approvalCodeFor = (t: FinanceRequestType): string =>
  t === 'CashFloat' ? 'finance_float_issue'
    : t === 'CashTransfer' || t === 'BranchFunding' ? 'finance_transfer'
      : 'finance_request_payment'

const statusTone = (s: FinanceRequestStatus) => {
  if (s === 'Paid' || s === 'Completed') return 'green'
  if (s === 'Rejected' || s === 'Cancelled' || s === 'Reversed') return 'red'
  if (s === 'ReadyForPayment' || s === 'Approved') return 'blue'
  return 'amber'
}

/* =========================== Finance Dashboard =========================== */

function Kpi({ label, value, sub, tone }: { label: string; value: string; sub?: string; tone?: 'green' | 'amber' | 'red' | 'blue' }) {
  const c = tone === 'green' ? 'var(--ok)' : tone === 'red' ? 'var(--bad)' : tone === 'amber' ? 'var(--warn)' : tone === 'blue' ? 'var(--ac-bright)' : 'var(--tx-0)'
  return (
    <Card pad={16} style={{ minWidth: 0 }}>
      <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 6 }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 700, fontFamily: 'JetBrains Mono', color: c, fontVariantNumeric: 'tabular-nums' }}>{value}</div>
      {sub && <div className="t-2" style={{ fontSize: 11.5, marginTop: 4 }}>{sub}</div>}
    </Card>
  )
}

export function FinanceDashboardScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<FinanceDashboard | null>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  useEffect(() => {
    setLoading(true)
    financeDashboardApi.get()
      .then(setData).catch((ex) => setErr(getErrorText(ex, 'Could not load the dashboard')))
      .finally(() => setLoading(false))
  }, [])

  const allBranches = !!me?.canViewAllBranches

  return (
    <div>
      <PageHead crumbs="Finance" title="Finance Dashboard" icon="wallet"
        sub="Live cash position, pending requests and today's movement" />
      <ErrorBanner error={err} />
      {loading || !data ? <LoadingBlock /> : <>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 12, marginBottom: 16 }}>
          <Kpi label="Total cash on hand" value={money(data.cash.total)} sub={`${data.accounts.length} account${data.accounts.length === 1 ? '' : 's'}`} />
          <Kpi label="Main tills" value={money(data.cash.main)} />
          <Kpi label="Cashier floats" value={money(data.cash.floats)} />
          <Kpi label="Petty cash" value={money(data.cash.petty)} />
          <Kpi label="Cash in transit" value={money(data.cash.inTransit)} tone={data.cash.inTransit > 0 ? 'amber' : undefined} />
          <Kpi label="Bank balances" value={money(data.bankTotal)} />
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 12, marginBottom: 16 }}>
          <Kpi label="Pending requests" value={String(data.requests.pendingCount)} sub={money(data.requests.pendingAmount)} tone={data.requests.pendingCount ? 'amber' : undefined} />
          <Kpi label="Approved, not paid" value={String(data.requests.readyCount)} sub={money(data.requests.readyAmount)} tone={data.requests.readyCount ? 'blue' : undefined} />
          <Kpi label="Unreconciled accounts" value={String(data.unreconciledAccounts)} tone={data.unreconciledAccounts ? 'amber' : 'green'} />
          <Kpi label="Today — inflow" value={money(data.today.inflow)} tone="green" />
          <Kpi label="Today — outflow" value={money(data.today.outflow)} tone="red" />
          <Kpi label="Today — disbursed" value={money(data.today.disbursements)} />
        </div>

        <Card pad={0} style={{ marginBottom: 16 }}>
          <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)', fontWeight: 600 }}>Cash accounts</div>
          <Table
            cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Type' }, { label: 'Balance', align: 'right' }, { label: 'Last reconciled', align: 'right' }]}
            empty="No cash accounts" rows={data.accounts}
            render={(a) => <>
              <Td mono>{a.code || '—'}</Td>
              <Td c="var(--tx-0)">{a.name}</Td>
              <Td><Badge tone="neutral">{a.accountType}</Badge></Td>
              <Td align="right" mono c={Number(a.currentBalance) < 0 ? 'var(--bad)' : undefined}>{money(a.currentBalance)}</Td>
              <Td align="right" c={a.lastReconciledAt ? undefined : 'var(--warn)'}>{a.lastReconciledAt ? dateOnly(a.lastReconciledAt) : 'never'}</Td>
            </>} />
        </Card>

        {allBranches && data.byBranch.length > 0 && (
          <Card pad={0}>
            <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)', fontWeight: 600 }}>By branch</div>
            <Table
              cols={[{ label: 'Branch' }, { label: 'Cash', align: 'right' }, { label: 'Pending requests', align: 'right' }, { label: 'Pending amount', align: 'right' }]}
              empty="—" rows={data.byBranch}
              render={(b) => <>
                <Td c="var(--tx-0)">{b.branch}</Td>
                <Td align="right" mono>{money(b.cash)}</Td>
                <Td align="right" mono>{b.pendingCount || '—'}</Td>
                <Td align="right" mono>{b.pendingAmount ? money(b.pendingAmount) : '—'}</Td>
              </>} />
          </Card>
        )}
      </>}
    </div>
  )
}

/* =========================== Finance Requests =========================== */

interface CreateForm {
  requestType: FinanceRequestType; amount: string; branchId: number | '';
  payeeName: string; purpose: string; preferredMethod: '' | 'Cash' | 'Bank' | 'Cheque';
  priority: 'Normal' | 'Urgent'; notes: string;
}
const blankCreate = (): CreateForm => ({
  requestType: 'SupplierPayment', amount: '', branchId: '', payeeName: '', purpose: '',
  preferredMethod: '', priority: 'Normal', notes: '',
})

interface ExecForm {
  method: 'Cash' | 'Bank'; cashAccountId: number | ''; bankAccountId: number | '';
  offsetAccountId: number | ''; destCashAccountId: number | ''; destBankAccountId: number | '';
}
const blankExec = (): ExecForm => ({ method: 'Cash', cashAccountId: '', bankAccountId: '', offsetAccountId: '', destCashAccountId: '', destBankAccountId: '' })

export function FinanceRequestsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<FinanceRequest[]>([])
  const [pageInfo, setPageInfo] = useState({ lastPage: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState<FinanceRequestStatus | ''>('')
  const [type, setType] = useState<FinanceRequestType | ''>('')

  const [branches, setBranches] = useState<Branch[]>([])
  const [cashAccounts, setCashAccounts] = useState<CashAccount[]>([])
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])
  const [coa, setCoa] = useState<ChartOfAccount[]>([])

  const [createOpen, setCreateOpen] = useState(false)
  const [createForm, setCreateForm] = useState<CreateForm>(blankCreate())
  const [execTarget, setExecTarget] = useState<FinanceRequest | null>(null)
  const [execForm, setExecForm] = useState<ExecForm>(blankExec())
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    cashAccountsApi.list().then(setCashAccounts).catch(() => setCashAccounts([]))
    bankAccountsApi.list().then(setBankAccounts).catch(() => setBankAccounts([]))
    chartOfAccountsApi.list().then(setCoa).catch(() => setCoa([]))
  }, [])

  const refresh = () => {
    setLoading(true)
    financeRequestsApi.list({ status: status || undefined, requestType: type || undefined, page })
      .then((p) => { setRows(p.data); setPageInfo({ lastPage: p.lastPage, total: p.total }) })
      .catch(() => setRows([]))
      .finally(() => setLoading(false))
  }
  useEffect(refresh, [status, type, page]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { setPage(1) }, [status, type])

  const postable = useMemo(() => coa.filter((a) => a.status !== 'Inactive'), [coa])

  const openCreate = () => { setCreateForm(blankCreate()); setErr(''); setCreateOpen(true) }
  const saveCreate = async () => {
    if (!createForm.amount || Number(createForm.amount) <= 0) { setErr('Amount must be greater than zero.'); return }
    setBusy(true); setErr('')
    try {
      await financeRequestsApi.create({
        requestType: createForm.requestType,
        amount: createForm.amount,
        branchId: createForm.branchId ? Number(createForm.branchId) : undefined,
        payeeName: createForm.payeeName || undefined,
        purpose: createForm.purpose || undefined,
        preferredMethod: createForm.preferredMethod || undefined,
        priority: createForm.priority,
        notes: createForm.notes || undefined,
        submit: true,
      })
      setCreateOpen(false); refresh()
      notify.success('Request submitted', 'Routed for finance approval')
    } catch (ex: any) { setErr(getErrorText(ex, 'Could not create the request')) }
    finally { setBusy(false) }
  }

  const canApproveReq = (r: FinanceRequest) =>
    r.status === 'PendingApproval'
    && canApprove(me, approvalCodeFor(r.requestType))
    && me?.id !== r.requester?.id
    && me?.id !== r.approvalRequest?.requester?.id
    && me?.id !== r.approvalRequest?.firstApprover?.id
    && me?.id !== r.approvalRequest?.secondApprover?.id
    && me?.id !== r.approvalRequest?.thirdApprover?.id

  const approvalsLeft = (r: FinanceRequest) => {
    const ar = r.approvalRequest
    if (!ar) return 0
    const done = [ar.firstApprover, ar.secondApprover, ar.thirdApprover, ar.fourthApprover].filter(Boolean).length
    return Math.max(0, ar.requiredApprovals - done)
  }

  const approve = async (r: FinanceRequest) => {
    try { await financeRequestsApi.approve(r.id); refresh(); notify.success('Approved', r.code) }
    catch (ex: any) { notify.error('Approve failed', getErrorText(ex)) }
  }
  const reject = async (r: FinanceRequest) => {
    const reason = await notify.prompt({ title: `Reject ${r.code}?`, msg: 'The requester can raise a new request afterwards.', placeholder: 'Reason', danger: true, okText: 'Reject' })
    if (reason === null) return
    try { await financeRequestsApi.reject(r.id, reason || undefined); refresh(); notify.success('Rejected', r.code) }
    catch (ex: any) { notify.error('Reject failed', getErrorText(ex)) }
  }
  const cancel = async (r: FinanceRequest) => {
    const ok = await notify.confirm({ title: `Cancel ${r.code}?`, msg: 'This withdraws the request. It cannot be undone.', danger: true, okText: 'Cancel request' })
    if (!ok) return
    try { await financeRequestsApi.cancel(r.id); refresh(); notify.success('Cancelled', r.code) }
    catch (ex: any) { notify.error('Cancel failed', getErrorText(ex)) }
  }

  const openExec = (r: FinanceRequest) => {
    setExecTarget(r)
    setExecForm({ ...blankExec(), method: (r.preferredMethod === 'Bank' || r.preferredMethod === 'Cheque') ? 'Bank' : 'Cash' })
    setErr('')
  }
  const isTransfer = execTarget ? TRANSFER_TYPES.includes(execTarget.requestType) : false
  const saveExec = async () => {
    if (!execTarget) return
    if (execForm.method === 'Cash' && !execForm.cashAccountId) { setErr('Choose the source cash account.'); return }
    if (execForm.method === 'Bank' && !execForm.bankAccountId) { setErr('Choose the source bank account.'); return }
    if (isTransfer && !execForm.destCashAccountId && !execForm.destBankAccountId) { setErr('Choose the destination account.'); return }
    if (!isTransfer && !execForm.offsetAccountId) { setErr('Choose the account this payment posts against.'); return }
    setBusy(true); setErr('')
    try {
      await financeRequestsApi.execute(execTarget.id, {
        method: execForm.method,
        cashAccountId: execForm.cashAccountId ? Number(execForm.cashAccountId) : undefined,
        bankAccountId: execForm.bankAccountId ? Number(execForm.bankAccountId) : undefined,
        offsetAccountId: execForm.offsetAccountId ? Number(execForm.offsetAccountId) : undefined,
        destCashAccountId: execForm.destCashAccountId ? Number(execForm.destCashAccountId) : undefined,
        destBankAccountId: execForm.destBankAccountId ? Number(execForm.destBankAccountId) : undefined,
      })
      setExecTarget(null); refresh()
      notify.success('Payment released', execTarget.code)
    } catch (ex: any) { setErr(getErrorText(ex, 'Could not release the payment')) }
    finally { setBusy(false) }
  }

  return (
    <div>
      <PageHead crumbs="Finance" title="Finance Requests" icon="receipt"
        sub={`${pageInfo.total} request${pageInfo.total === 1 ? '' : 's'}`}
        actions={<Can user={me} module="Finance Requests" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>New Request</Btn></Can>} />

      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="Status"><Select value={status} onChange={(e) => setStatus(e.target.value as any)} style={{ minWidth: 170 }}>
            <option value="">All statuses</option>
            {['PendingApproval', 'ReadyForPayment', 'Paid', 'Completed', 'Rejected', 'Cancelled', 'Reversed'].map((s) => <option key={s} value={s}>{s}</option>)}
          </Select></Field>
          <Field label="Type"><Select value={type} onChange={(e) => setType(e.target.value as any)} style={{ minWidth: 170 }}>
            <option value="">All types</option>
            {REQUEST_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
          </Select></Field>
        </div>
      </Card>

      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table
          cols={[{ label: 'Code' }, { label: 'Type' }, { label: 'Payee' }, { label: 'Branch' }, { label: 'Amount', align: 'right' }, { label: 'Age' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 210 }]}
          empty="No finance requests" rows={rows}
          render={(r) => <>
            <Td mono>{r.code}</Td>
            <Td>{r.requestType}{r.priority === 'Urgent' && <Badge tone="red">Urgent</Badge>}</Td>
            <Td>{r.payeeName || '—'}</Td>
            <Td>{r.branch?.name || '—'}</Td>
            <Td align="right" mono>{money(r.amount)}</Td>
            <Td c="var(--tx-2)">{ago(r.requestedAt)}</Td>
            <Td align="center">
              <Badge tone={statusTone(r.status)} dot>{r.status}</Badge>
              {r.status === 'PendingApproval' && approvalsLeft(r) > 0 && (
                <div className="t-2" style={{ fontSize: 10.5, marginTop: 2 }}>needs {approvalsLeft(r)} approval{approvalsLeft(r) === 1 ? '' : 's'}</div>
              )}
              {r.status === 'Rejected' && r.rejectReason && (
                <div className="t-2" style={{ fontSize: 10.5, marginTop: 2 }} title={r.rejectReason}>reason ⓘ</div>
              )}
            </Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              {canApproveReq(r) && <>
                <Btn variant="ghost" size="sm" onClick={() => reject(r)}>Reject</Btn>
                <Btn variant="primary" size="sm" icon="check" onClick={() => approve(r)}>Approve</Btn>
              </>}
              {r.status === 'ReadyForPayment' && <Can user={me} module="Finance Requests" action="Edit">
                <Btn variant="primary" size="sm" icon="wallet" onClick={() => openExec(r)}>Execute</Btn>
              </Can>}
              {(r.status === 'Draft' || r.status === 'Submitted' || r.status === 'PendingApproval') && me?.id === r.requester?.id &&
                <Btn variant="ghost" size="sm" onClick={() => cancel(r)}>Cancel</Btn>}
              {r.status === 'Paid' && r.financeTransaction && <span className="t-2" style={{ fontSize: 11.5 }} title={r.financeTransaction.code}>{r.financeTransaction.code}</span>}
            </div></Td>
          </>} />}
        <Pager page={page} lastPage={pageInfo.lastPage} total={pageInfo.total} onChange={setPage} />
      </Card>

      {/* create */}
      <Modal open={createOpen} onClose={() => setCreateOpen(false)} width={560} title="New Finance Request"
        footer={<>
          <Btn variant="plain" onClick={() => setCreateOpen(false)}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveCreate} disabled={busy}>{busy ? 'Submitting…' : 'Submit'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Type"><Select value={createForm.requestType} onChange={(e) => setCreateForm((s) => ({ ...s, requestType: e.target.value as FinanceRequestType }))}>
            {REQUEST_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
          </Select></Field>
          <Field label="Amount"><Input type="number" step="0.01" value={createForm.amount} onChange={(e) => setCreateForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label="Branch"><Select value={createForm.branchId} onChange={(e) => setCreateForm((s) => ({ ...s, branchId: Number(e.target.value) || '' }))}>
            <option value="">My branch</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Preferred method"><Select value={createForm.preferredMethod} onChange={(e) => setCreateForm((s) => ({ ...s, preferredMethod: e.target.value as any }))}>
            <option value="">No preference</option><option>Cash</option><option>Bank</option><option>Cheque</option>
          </Select></Field>
          <Field label="Payee"><Input value={createForm.payeeName} onChange={(e) => setCreateForm((s) => ({ ...s, payeeName: e.target.value }))} placeholder="Who is being paid" /></Field>
          <Field label="Priority"><Select value={createForm.priority} onChange={(e) => setCreateForm((s) => ({ ...s, priority: e.target.value as any }))}>
            <option>Normal</option><option>Urgent</option>
          </Select></Field>
          <Field label="Purpose" full><Input value={createForm.purpose} onChange={(e) => setCreateForm((s) => ({ ...s, purpose: e.target.value }))} /></Field>
          <Field label="Notes" full><textarea value={createForm.notes} onChange={(e) => setCreateForm((s) => ({ ...s, notes: e.target.value }))} rows={2} /></Field>
        </div>
      </Modal>

      {/* execute */}
      <Modal open={!!execTarget} onClose={() => setExecTarget(null)} width={540}
        title="Execute Payment" sub={execTarget ? `${execTarget.code} — ${money(execTarget.amount)}` : undefined}
        footer={<>
          <Btn variant="plain" onClick={() => setExecTarget(null)}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveExec} disabled={busy}>{busy ? 'Releasing…' : 'Release Money'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Source"><Select value={execForm.method} onChange={(e) => setExecForm((s) => ({ ...s, method: e.target.value as any }))}>
            <option value="Cash">Cash</option><option value="Bank">Bank</option>
          </Select></Field>
          <div />
          {execForm.method === 'Cash'
            ? <Field label="From cash account" full><Select value={execForm.cashAccountId} onChange={(e) => setExecForm((s) => ({ ...s, cashAccountId: Number(e.target.value) || '' }))}>
              <option value="">Select…</option>
              {cashAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {money(a.currentBalance)}</option>)}
            </Select></Field>
            : <Field label="From bank account" full><Select value={execForm.bankAccountId} onChange={(e) => setExecForm((s) => ({ ...s, bankAccountId: Number(e.target.value) || '' }))}>
              <option value="">Select…</option>
              {bankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {money(a.currentBalance)}</option>)}
            </Select></Field>}

          {isTransfer ? (
            <Field label="To account" full><Select
              value={execForm.destCashAccountId || (execForm.destBankAccountId ? `b${execForm.destBankAccountId}` : '')}
              onChange={(e) => {
                const v = e.target.value
                if (v.startsWith('b')) setExecForm((s) => ({ ...s, destBankAccountId: Number(v.slice(1)), destCashAccountId: '' }))
                else setExecForm((s) => ({ ...s, destCashAccountId: Number(v) || '', destBankAccountId: '' }))
              }}>
              <option value="">Select…</option>
              <optgroup label="Cash accounts">{cashAccounts.map((a) => <option key={`c${a.id}`} value={a.id}>{a.name}</option>)}</optgroup>
              <optgroup label="Bank accounts">{bankAccounts.map((a) => <option key={`b${a.id}`} value={`b${a.id}`}>{a.name}</option>)}</optgroup>
            </Select></Field>
          ) : (
            <Field label="Post against (GL account)" full><Select value={execForm.offsetAccountId} onChange={(e) => setExecForm((s) => ({ ...s, offsetAccountId: Number(e.target.value) || '' }))}>
              <option value="">Select account…</option>
              {postable.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
            </Select></Field>
          )}
        </div>
        <p className="t-2" style={{ fontSize: 11.5, marginTop: 12 }}>
          Releasing posts the general-ledger entry and moves the source account's balance in one step. It cannot be edited afterwards — only reversed.
        </p>
      </Modal>
    </div>
  )
}

/* =========================== Finance Transfers =========================== */

const TRANSFER_LIST_TYPES = 'CashTransfer,BranchFunding'

export function FinanceTransfersScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<FinanceRequest[]>([])
  const [loading, setLoading] = useState(true)
  const [cash, setCash] = useState<CashAccount[]>([])
  const [bank, setBank] = useState<BankAccount[]>([])
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [form, setForm] = useState({ src: '', dst: '', amount: '', twoStep: false, purpose: '' })

  useEffect(() => {
    cashAccountsApi.list().then(setCash).catch(() => setCash([]))
    bankAccountsApi.list().then(setBank).catch(() => setBank([]))
  }, [])
  const refresh = () => {
    setLoading(true)
    financeRequestsApi.list({ requestType: TRANSFER_LIST_TYPES, perPage: 100 })
      .then((p) => setRows(p.data)).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])

  const acctOpt = (v: string) => {
    if (v.startsWith('b')) { const a = bank.find((x) => x.id === Number(v.slice(1))); return a ? { bankId: a.id } : {} }
    const a = cash.find((x) => x.id === Number(v)); return a ? { cashId: a.id } : {}
  }
  const create = async () => {
    if (!form.src || !form.dst) { setErr('Pick the source and destination.'); return }
    if (form.src === form.dst) { setErr('Source and destination must differ.'); return }
    if (!form.amount || Number(form.amount) <= 0) { setErr('Enter an amount.'); return }
    const s = acctOpt(form.src), d = acctOpt(form.dst)
    setBusy(true); setErr('')
    try {
      await financeRequestsApi.create({
        requestType: form.twoStep ? 'BranchFunding' : 'CashTransfer',
        amount: form.amount, purpose: form.purpose || undefined, submit: true,
        meta: {
          source_cash_account_id: s.cashId, source_bank_account_id: s.bankId,
          dest_cash_account_id: d.cashId, dest_bank_account_id: d.bankId,
          two_step: form.twoStep,
        } as Record<string, unknown>,
      })
      setOpen(false); setForm({ src: '', dst: '', amount: '', twoStep: false, purpose: '' }); refresh()
      notify.success('Transfer submitted', 'Routed for approval')
    } catch (ex: any) { setErr(getErrorText(ex, 'Could not create the transfer')) }
    finally { setBusy(false) }
  }

  const canApproveT = (r: FinanceRequest) => r.status === 'PendingApproval' && canApprove(me, 'finance_transfer') && me?.id !== r.requester?.id
  const act = (fn: () => Promise<unknown>, ok: string) => async () => {
    try { await fn(); refresh(); notify.success(ok) } catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }
  const recall = async (r: FinanceRequest) => {
    const reason = await notify.prompt({ title: `Recall ${r.code}?`, msg: 'Cash returns to the source account.', placeholder: 'Reason', danger: true, okText: 'Recall' })
    if (reason === null) return
    try { await financeRequestsApi.recall(r.id, reason || 'recalled'); refresh(); notify.success('Recalled') } catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }

  const twoStepMeta = (r: FinanceRequest) => !!(r.meta as Record<string, unknown> | null | undefined)?.two_step
  const acctName = (r: FinanceRequest, side: 'source' | 'dest') => {
    const m = (r.meta || {}) as Record<string, number | undefined>
    const cid = m[`${side}_cash_account_id`], bid = m[`${side}_bank_account_id`]
    if (cid) return cash.find((a) => a.id === cid)?.name || `Cash #${cid}`
    if (bid) return bank.find((a) => a.id === bid)?.name || `Bank #${bid}`
    return '—'
  }

  return (
    <div>
      <PageHead crumbs="Finance" title="Transfers" icon="swap" sub={`${rows.length} transfer${rows.length === 1 ? '' : 's'}`}
        actions={<Can user={me} module="Finance Transfers" action="Create"><Btn variant="primary" icon="plus" onClick={() => { setErr(''); setOpen(true) }}>New Transfer</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Code' }, { label: 'From' }, { label: 'To' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 240 }]}
          empty="No transfers" rows={rows}
          render={(r) => <>
            <Td mono>{r.code}</Td>
            <Td>{acctName(r, 'source')}</Td>
            <Td>{acctName(r, 'dest')}</Td>
            <Td align="right" mono>{money(r.amount)}</Td>
            <Td align="center"><Badge tone={statusTone(r.status)} dot>{r.status}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              {canApproveT(r) && <>
                <Btn variant="ghost" size="sm" onClick={act(() => financeRequestsApi.reject(r.id), 'Rejected')}>Reject</Btn>
                <Btn variant="primary" size="sm" icon="check" onClick={act(() => financeRequestsApi.approve(r.id), 'Approved')}>Approve</Btn>
              </>}
              {r.status === 'ReadyForPayment' && !twoStepMeta(r) && <Can user={me} module="Finance Transfers" action="Edit">
                <Btn variant="primary" size="sm" icon="check" onClick={act(() => financeRequestsApi.execute(r.id, { method: 'Cash' }), 'Transferred')}>Execute</Btn>
              </Can>}
              {r.status === 'ReadyForPayment' && twoStepMeta(r) && <Can user={me} module="Finance Transfers" action="Edit">
                <Btn variant="primary" size="sm" icon="upload" onClick={act(() => financeRequestsApi.release(r.id), 'Released')}>Release</Btn>
              </Can>}
              {r.status === 'InTransit' && <Can user={me} module="Finance Transfers" action="Edit">
                <Btn variant="ghost" size="sm" onClick={() => recall(r)}>Recall</Btn>
                <Btn variant="primary" size="sm" icon="check" onClick={act(() => financeRequestsApi.receive(r.id), 'Received')}>Confirm Receipt</Btn>
              </Can>}
            </div></Td>
          </>} />}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} width={520} title="New Transfer"
        footer={<><Btn variant="plain" onClick={() => setOpen(false)}>Cancel</Btn><Btn variant="primary" icon="check" onClick={create} disabled={busy}>{busy ? 'Submitting…' : 'Submit'}</Btn></>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="From" full><Select value={form.src} onChange={(e) => setForm((s) => ({ ...s, src: e.target.value }))}>
            <option value="">Select…</option>
            <optgroup label="Cash">{cash.map((a) => <option key={`c${a.id}`} value={a.id}>{a.name} — {money(a.currentBalance)}</option>)}</optgroup>
            <optgroup label="Bank">{bank.map((a) => <option key={`b${a.id}`} value={`b${a.id}`}>{a.name}</option>)}</optgroup>
          </Select></Field>
          <Field label="To" full><Select value={form.dst} onChange={(e) => setForm((s) => ({ ...s, dst: e.target.value }))}>
            <option value="">Select…</option>
            <optgroup label="Cash">{cash.map((a) => <option key={`c${a.id}`} value={a.id}>{a.name}</option>)}</optgroup>
            <optgroup label="Bank">{bank.map((a) => <option key={`b${a.id}`} value={`b${a.id}`}>{a.name}</option>)}</optgroup>
          </Select></Field>
          <Field label="Amount"><Input type="number" step="0.01" value={form.amount} onChange={(e) => setForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label="Purpose"><Input value={form.purpose} onChange={(e) => setForm((s) => ({ ...s, purpose: e.target.value }))} /></Field>
          <Field full><label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer' }}>
            <input type="checkbox" checked={form.twoStep} onChange={(e) => setForm((s) => ({ ...s, twoStep: e.target.checked }))} />
            <span style={{ fontSize: 13 }}>Cash physically travels between branches (release → receive)</span>
          </label></Field>
        </div>
      </Modal>
    </div>
  )
}

/* =========================== Cash Floats =========================== */

export function CashFloatsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<CashFloat[]>([])
  const [loading, setLoading] = useState(true)
  const [tills, setTills] = useState<CashAccount[]>([])
  const [users, setUsers] = useState<{ id: number; name: string }[]>([])
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ home: '', custodian: '', amount: '', notes: '' })
  const [settleFor, setSettleFor] = useState<CashFloat | null>(null)
  const [counted, setCounted] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    cashAccountsApi.list().then((a) => setTills(a.filter((x) => x.accountType === 'BranchMain' || x.accountType === 'HeadOfficeMain'))).catch(() => setTills([]))
    api.users.active().then(setUsers).catch(() => setUsers([]))
  }, [])
  const refresh = () => { setLoading(true); cashFloatsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false)) }
  useEffect(refresh, [])

  const issue = async () => {
    if (!form.home || !form.custodian || !form.amount) { setErr('Fill in every field.'); return }
    setBusy(true); setErr('')
    try {
      await cashFloatsApi.issue({ homeCashAccountId: Number(form.home), custodianId: Number(form.custodian), amount: form.amount, notes: form.notes || undefined })
      setOpen(false); setForm({ home: '', custodian: '', amount: '', notes: '' }); refresh()
      notify.success('Float request sent', 'Finance will approve and fund it')
    } catch (ex: any) { setErr(getErrorText(ex, 'Could not issue the float')) }
    finally { setBusy(false) }
  }
  const askSettle = async (f: CashFloat) => {
    try { await cashFloatsApi.requestSettlement(f.id); refresh(); notify.success('Settlement requested') }
    catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }
  const doSettle = async () => {
    if (!settleFor?.settlement) return
    if (counted === '' || Number(counted) < 0) { setErr('Enter the counted amount.'); return }
    setBusy(true); setErr('')
    try {
      await cashFloatsApi.settle(settleFor.settlement.id, counted)
      setSettleFor(null); setCounted(''); refresh()
      notify.success('Float settled', settleFor.name)
    } catch (ex: any) { setErr(getErrorText(ex, 'Settlement failed')) }
    finally { setBusy(false) }
  }
  const canSettle = (f: CashFloat) =>
    f.settlement?.status === 'SettlementRequested' && canApprove(me, 'finance_float_settlement')
    && me?.id !== f.holder?.id && me?.id !== f.settlement?.approvalRequest?.requester?.id

  return (
    <div>
      <PageHead crumbs="Finance" title="Cash Floats" icon="coins" sub={`${rows.length} active float${rows.length === 1 ? '' : 's'}`}
        actions={<Can user={me} module="Cash Floats" action="Create"><Btn variant="primary" icon="plus" onClick={() => { setErr(''); setOpen(true) }}>Issue Float</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Float' }, { label: 'Holder' }, { label: 'Home' }, { label: 'Balance', align: 'right' }, { label: 'Spent', align: 'right' }, { label: 'Settlement', align: 'center' }, { label: '', align: 'right', w: 210 }]}
          empty="No active floats" rows={rows}
          render={(f) => <>
            <Td c="var(--tx-0)">{f.name}<div className="t-2" style={{ fontSize: 11 }}>{f.code}</div></Td>
            <Td>{f.holder?.name || '—'}</Td>
            <Td>{f.home?.name || '—'}</Td>
            <Td align="right" mono>{money(f.currentBalance)}</Td>
            <Td align="right" mono>{money(f.spentTotal)}</Td>
            <Td align="center">{f.settlement ? <Badge tone={f.settlement.status === 'SettlementRequested' ? 'amber' : 'neutral'}>{f.settlement.status}</Badge> : '—'}</Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              {f.settlement?.status === 'Open' && <Can user={me} module="Cash Floats" action="Edit">
                <Btn variant="ghost" size="sm" onClick={() => askSettle(f)}>Request Settlement</Btn>
              </Can>}
              {canSettle(f) && <Btn variant="primary" size="sm" icon="check" onClick={() => { setSettleFor(f); setCounted(String(f.currentBalance)); setErr('') }}>Settle</Btn>}
            </div></Td>
          </>} />}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} width={480} title="Issue Cash Float"
        footer={<><Btn variant="plain" onClick={() => setOpen(false)}>Cancel</Btn><Btn variant="primary" icon="check" onClick={issue} disabled={busy}>{busy ? 'Sending…' : 'Send Request'}</Btn></>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="From Till" full><Select value={form.home} onChange={(e) => setForm((s) => ({ ...s, home: e.target.value }))}>
            <option value="">Select…</option>{tills.map((a) => <option key={a.id} value={a.id}>{a.name} — {money(a.currentBalance)}</option>)}
          </Select></Field>
          <Field label="Custodian" full><Select value={form.custodian} onChange={(e) => setForm((s) => ({ ...s, custodian: e.target.value }))}>
            <option value="">Select…</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </Select></Field>
          <Field label="Amount"><Input type="number" step="0.01" value={form.amount} onChange={(e) => setForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label="Notes"><Input value={form.notes} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} /></Field>
        </div>
      </Modal>

      <Modal open={!!settleFor} onClose={() => setSettleFor(null)} width={440} title="Settle Float"
        sub={settleFor ? `${settleFor.name} — books say ${money(settleFor.currentBalance)}` : undefined}
        footer={<><Btn variant="plain" onClick={() => setSettleFor(null)}>Cancel</Btn><Btn variant="primary" icon="check" onClick={doSettle} disabled={busy}>{busy ? 'Settling…' : 'Settle'}</Btn></>}>
        <ErrorBanner error={err} />
        <Field label="Cash counted"><Input type="number" step="0.01" value={counted} onChange={(e) => setCounted(e.target.value)} /></Field>
        {settleFor && counted !== '' && (
          <p className="t-2" style={{ fontSize: 12, marginTop: 10 }}>
            Variance {money(Number(counted) - Number(settleFor.currentBalance))} — {Number(counted) < Number(settleFor.currentBalance) ? 'shortage posted to 5310' : Number(counted) > Number(settleFor.currentBalance) ? 'overage posted to 4905' : 'exact'}. The counted cash returns to {settleFor.home?.name}.
          </p>
        )}
      </Modal>
    </div>
  )
}

/* =========================== Daily Cash Sessions =========================== */

export function CashSessionsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<CashSession[]>([])
  const [loading, setLoading] = useState(true)
  const [tills, setTills] = useState<CashAccount[]>([])
  const [openForm, setOpenForm] = useState(false)
  const [oForm, setOForm] = useState({ account: '', opening: '' })
  const [closeFor, setCloseFor] = useState<CashSession | null>(null)
  const [counted, setCounted] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    cashAccountsApi.list().then((a) => setTills(a.filter((x) => x.status !== 'Inactive' && x.accountType !== 'InTransit'))).catch(() => setTills([]))
  }, [])
  const refresh = () => { setLoading(true); cashSessionsApi.list({ page: 1 }).then((p) => setRows(p.data)).catch(() => setRows([])).finally(() => setLoading(false)) }
  useEffect(refresh, [])

  const doOpen = async () => {
    if (!oForm.account || oForm.opening === '') { setErr('Pick the account and enter the opening count.'); return }
    setBusy(true); setErr('')
    try {
      await cashSessionsApi.open({ cashAccountId: Number(oForm.account), openingAmount: oForm.opening })
      setOpenForm(false); setOForm({ account: '', opening: '' }); refresh()
      notify.success('Session opened')
    } catch (ex: any) { setErr(getErrorText(ex, 'Could not open the session')) }
    finally { setBusy(false) }
  }
  const doClose = async () => {
    if (!closeFor) return
    if (counted === '') { setErr('Enter the counted amount.'); return }
    setBusy(true); setErr('')
    try {
      await cashSessionsApi.close(closeFor.id, { countedClosing: counted })
      setCloseFor(null); setCounted(''); refresh()
      notify.success('Session closed', 'Awaiting a manager to verify')
    } catch (ex: any) { setErr(getErrorText(ex, 'Could not close the session')) }
    finally { setBusy(false) }
  }
  const verify = async (s: CashSession) => {
    try { await cashSessionsApi.verify(s.id); refresh(); notify.success('Session verified') }
    catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }

  const tone = (s: CashSession['status']) => s === 'Verified' ? 'green' : s === 'Closed' ? 'amber' : 'blue'

  return (
    <div>
      <PageHead crumbs="Finance" title="Daily Cash Sessions" icon="clock" sub="Open, count and verify each cash account by day"
        actions={<Can user={me} module="Cash Sessions" action="Create"><Btn variant="primary" icon="plus" onClick={() => { setErr(''); setOpenForm(true) }}>Open Session</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Date' }, { label: 'Account' }, { label: 'Opening', align: 'right' }, { label: 'Expected', align: 'right' }, { label: 'Counted', align: 'right' }, { label: 'Variance', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 170 }]}
          empty="No sessions" rows={rows}
          render={(s) => <>
            <Td>{dateOnly(s.businessDate)}</Td>
            <Td c="var(--tx-0)">{s.cashAccount?.name || '—'}</Td>
            <Td align="right" mono>{money(s.openingAmount)}</Td>
            <Td align="right" mono>{s.expectedClosing == null ? '—' : money(s.expectedClosing)}</Td>
            <Td align="right" mono>{s.countedClosing == null ? '—' : money(s.countedClosing)}</Td>
            <Td align="right" mono c={Number(s.variance) < 0 ? 'var(--bad)' : Number(s.variance) > 0 ? 'var(--warn)' : undefined}>{s.status === 'Open' ? '—' : money(s.variance)}</Td>
            <Td align="center"><Badge tone={tone(s.status)} dot>{s.status}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              {s.status === 'Open' && me?.id === s.opener?.id && <Btn variant="primary" size="sm" onClick={() => { setCloseFor(s); setCounted(String(s.openingAmount)); setErr('') }}>Close</Btn>}
              {s.status === 'Closed' && me?.id !== s.closer?.id && <Can user={me} module="Cash Sessions" action="Edit"><Btn variant="primary" size="sm" icon="check" onClick={() => verify(s)}>Verify</Btn></Can>}
            </div></Td>
          </>} />}
      </Card>

      <Modal open={openForm} onClose={() => setOpenForm(false)} width={420} title="Open Cash Session"
        footer={<><Btn variant="plain" onClick={() => setOpenForm(false)}>Cancel</Btn><Btn variant="primary" icon="check" onClick={doOpen} disabled={busy}>{busy ? 'Opening…' : 'Open'}</Btn></>}>
        <ErrorBanner error={err} />
        <div className="col gap-3">
          <Field label="Cash account"><Select value={oForm.account} onChange={(e) => setOForm((s) => ({ ...s, account: e.target.value }))}>
            <option value="">Select…</option>{tills.map((a) => <option key={a.id} value={a.id}>{a.name} — books {money(a.currentBalance)}</option>)}
          </Select></Field>
          <Field label="Opening cash counted"><Input type="number" step="0.01" value={oForm.opening} onChange={(e) => setOForm((s) => ({ ...s, opening: e.target.value }))} /></Field>
        </div>
      </Modal>

      <Modal open={!!closeFor} onClose={() => setCloseFor(null)} width={420} title="Close Cash Session"
        sub={closeFor ? `${closeFor.cashAccount?.name} — ${dateOnly(closeFor.businessDate)}` : undefined}
        footer={<><Btn variant="plain" onClick={() => setCloseFor(null)}>Cancel</Btn><Btn variant="primary" icon="check" onClick={doClose} disabled={busy}>{busy ? 'Closing…' : 'Close'}</Btn></>}>
        <ErrorBanner error={err} />
        <Field label="Closing cash counted"><Input type="number" step="0.01" value={counted} onChange={(e) => setCounted(e.target.value)} /></Field>
      </Modal>
    </div>
  )
}

/* =========================== Finance Settings =========================== */

const BOOL_SETTINGS: { key: string; label: string; help: string }[] = [
  { key: 'require_cash_session', label: 'Require an open cash session for cash collections', help: 'A cashier must open the day’s session before recording cash loan payments.' },
  { key: 'reversal_requires_approval', label: 'Reversing a completed finance transaction needs approval', help: 'Routes every reversal through the finance_reversal maker-checker.' },
]

export function FinanceSettingsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [settings, setSettings] = useState<FinanceSettings>({})
  const [loading, setLoading] = useState(true)
  const canEdit = userCan(me, 'Finance Settings', 'Edit')

  useEffect(() => { financeSettingsApi.get().then(setSettings).catch(() => setSettings({})).finally(() => setLoading(false)) }, [])

  const toggle = async (key: string, on: boolean) => {
    setSettings((s) => ({ ...s, [key]: on ? '1' : '0' }))
    try { setSettings(await financeSettingsApi.update({ [key]: on })); notify.success('Setting saved') }
    catch (ex: any) { notify.error('Save failed', getErrorText(ex)); financeSettingsApi.get().then(setSettings) }
  }

  return (
    <div>
      <PageHead crumbs="Finance" title="Finance Settings" icon="shield" sub="Module-wide controls" />
      {loading ? <LoadingBlock /> :
      <Card>
        <div className="col gap-4">
          {BOOL_SETTINGS.map((s) => (
            <label key={s.key} className="row gap-3" style={{ alignItems: 'flex-start', cursor: canEdit ? 'pointer' : 'default' }}>
              <input type="checkbox" disabled={!canEdit} checked={(settings[s.key] ?? '0') === '1'} onChange={(e) => toggle(s.key, e.target.checked)} style={{ marginTop: 3 }} />
              <div>
                <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--tx-0)' }}>{s.label}</div>
                <div className="t-2" style={{ fontSize: 12 }}>{s.help}</div>
              </div>
            </label>
          ))}
        </div>
      </Card>}
    </div>
  )
}
