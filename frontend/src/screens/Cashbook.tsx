/* Tazz Investment & Credit — Cashbook Management (API-backed) */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Modal, Table, Td, inputStyle, ErrorBanner , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  branchesApi, cashAccountsApi, cashbookEntriesApi, cashTransfersApi, cashReconciliationsApi, chartOfAccountsApi,
  type Branch, type AuthUser, type CashAccount, type CashbookEntry, type CashEntryType, type CashEntryCategory,
  type CashTransfer, type CashReconciliation, type CashReconciliationSummary, type Denominations, DENOMINATION_VALUES,
  type ChartOfAccount,
} from '../api'
import { Can, canApprove } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/* ============================== Cashbook ============================== */

interface AccountForm extends Partial<CashAccount> {}
const blankAccountForm = (): AccountForm => ({ status: 'Active', openingBalance: 0 })

interface EntryForm extends Partial<CashbookEntry> { offsetAccountId?: number }
const blankEntryForm = (accountId?: number): EntryForm =>
  ({ cashAccountId: accountId, direction: 'In', category: 'Other', entryDate: new Date().toISOString().slice(0, 10) })

export function CashbookScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [accounts, setAccounts] = useState<CashAccount[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [accountId, setAccountId] = useState<number | ''>('')
  const [rows, setRows] = useState<CashbookEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const [entryModal, setEntryModal] = useState(false)
  const [entryForm, setEntryForm] = useState<EntryForm>(blankEntryForm())
  const [ledgerAccounts, setLedgerAccounts] = useState<ChartOfAccount[]>([])

  const [acctModal, setAcctModal] = useState(false)
  const [acctView, setAcctView] = useState<'list' | 'form'>('list')
  const [acctEditing, setAcctEditing] = useState<CashAccount | null>(null)
  const [acctForm, setAcctForm] = useState<AccountForm>(blankAccountForm())
  const [acctErr, setAcctErr] = useState('')
  const [acctBusy, setAcctBusy] = useState(false)

  const refreshAccounts = () => cashAccountsApi.list().then((d) => { setAccounts(d); setAccountId((cur) => cur || d[0]?.id || '') }).catch(() => setAccounts([]))
  useEffect(() => {
    refreshAccounts()
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    chartOfAccountsApi.list().then(setLedgerAccounts).catch(() => setLedgerAccounts([]))
  }, [])

  const refresh = () => {
    setLoading(true)
    cashbookEntriesApi.list(accountId || undefined).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [accountId]) // eslint-disable-line react-hooks/exhaustive-deps

  const selectedAccount = accounts.find((a) => a.id === accountId) || null

  const openCreateEntry = () => { setEntryForm(blankEntryForm(accountId || undefined)); setErr(''); setEntryModal(true) }
  const closeEntry = () => { setEntryModal(false); setErr('') }

  const saveEntry = async () => {
    if (!entryForm.cashAccountId) { setErr('Cash account is required.'); return }
    if (!entryForm.amount || Number(entryForm.amount) <= 0) { setErr('Amount must be greater than zero.'); return }
    if (!entryForm.offsetAccountId) { setErr('Pick the GL account for the other side of this entry.'); return }
    setBusy(true); setErr('')
    try {
      await cashbookEntriesApi.create(entryForm)
      closeEntry(); refresh(); refreshAccounts()
      notify.success('Cashbook entry recorded')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const removeEntry = async (e: CashbookEntry) => {
    const ok = await notify.confirm({ title: 'Reverse this entry?', msg: 'A reversing entry is posted that cancels its effect on the balance and the ledger. Both entries stay on the cashbook.', danger: true, okText: 'Reverse' })
    if (!ok) return
    try { await cashbookEntriesApi.remove(e.id); refresh(); refreshAccounts(); notify.success('Entry reversed') }
    catch (ex: any) { notify.error('Reverse failed', getErrorText(ex)) }
  }

  const openAccounts = () => { setAcctView('list'); setAcctModal(true) }
  const closeAccounts = () => { setAcctModal(false); setAcctEditing(null); setAcctErr('') }
  const openCreateAccount = () => { setAcctEditing(null); setAcctForm(blankAccountForm()); setAcctErr(''); setAcctView('form') }
  const openEditAccount = (a: CashAccount) => { setAcctEditing(a); setAcctForm({ ...a }); setAcctErr(''); setAcctView('form') }

  const saveAccount = async () => {
    if (!acctForm.name || !acctForm.branchId) { setAcctErr('Account name and branch are required.'); return }
    setAcctBusy(true); setAcctErr('')
    try {
      if (acctEditing) await cashAccountsApi.update(acctEditing.id, acctForm)
      else await cashAccountsApi.create(acctForm)
      setAcctView('list'); refreshAccounts()
      notify.success(acctEditing ? 'Cash account updated' : 'Cash account created', acctForm.name)
    } catch (ex: any) {
      setAcctErr(getErrorText(ex, 'Save failed'))
    } finally { setAcctBusy(false) }
  }

  const removeAccount = async (a: CashAccount) => {
    const ok = await notify.confirm({ title: `Delete account "${a.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await cashAccountsApi.remove(a.id); refreshAccounts(); notify.success('Cash account deleted', a.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const types: CashEntryType[] = ['In', 'Out']
  const categories: CashEntryCategory[] = ['Loan Collection', 'Expense', 'Transfer', 'Deposit', 'Other']

  return (
    <div>
      <PageHead crumbs="Cashbook Management" title="Cashbook" icon="wallet"
        sub={selectedAccount ? `${selectedAccount.name} — Balance: ${money(selectedAccount.currentBalance)}` : `${rows.length} entries`}
        actions={<>
          <Can user={me} module="Cashbook" action="Edit"><Btn variant="plain" icon="building" onClick={openAccounts}>Manage Accounts</Btn></Can>
          <Can user={me} module="Cashbook" action="Create"><Btn variant="primary" icon="plus" onClick={openCreateEntry} disabled={!accounts.length}>Add Entry</Btn></Can>
        </>} />

      <Card style={{ marginBottom: 16 }}>
        <Field label="Cash Account"><Select value={accountId} onChange={(e) => setAccountId(Number(e.target.value) || '')}>
          <option value="">All accounts</option>
          {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </Select></Field>
      </Card>

      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Date' }, { label: 'Account' }, { label: 'Type' }, { label: 'Category' }, { label: 'Description' }, { label: 'Amount', align: 'right' }, { label: 'Balance', align: 'right' }, { label: '', align: 'right', w: 60 }]}
          rows={rows}
          render={(e) => <>
            <Td>{e.entryDate}</Td>
            <Td>{e.cashAccount?.name || '—'}</Td>
            <Td><Badge tone={e.direction === 'In' ? 'green' : 'red'}>{e.direction}</Badge></Td>
            <Td><Badge tone="neutral">{e.category}</Badge></Td>
            <Td c="var(--tx-2)" style={{ fontSize: 12.5, whiteSpace: 'normal' }}>{e.description || '—'}</Td>
            <Td align="right" mono c={e.direction === 'In' ? 'var(--ok)' : 'var(--bad)'}>{e.direction === 'In' ? '+' : '-'}{money(e.amount)}</Td>
            <Td align="right" mono>{money(e.balanceAfter)}</Td>
            <Td align="right"><Can user={me} module="Cashbook" action="Delete"><button className="mms-act" title="Delete" onClick={() => removeEntry(e)}><Icon n="trash" s={15} /></button></Can></Td>
          </>} />}
      </Card>

      <Modal open={entryModal} onClose={closeEntry} width={520} title="Add Cashbook Entry"
        footer={<>
          <Btn variant="plain" onClick={closeEntry}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveEntry} disabled={busy}>{busy ? 'Saving...' : 'Record Entry'}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Cash Account" full><Select value={entryForm.cashAccountId ?? ''} onChange={(e) => setEntryForm((s) => ({ ...s, cashAccountId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </Select></Field>
          <Field label="Date"><DateInput value={entryForm.entryDate} onChange={(iso) => setEntryForm((s) => ({ ...s, entryDate: iso }))} /></Field>
          <Field label="Type"><Select value={entryForm.direction || 'In'} onChange={(e) => setEntryForm((s) => ({ ...s, direction: e.target.value as any }))}>
            {types.map((t) => <option key={t}>{t}</option>)}
          </Select></Field>
          <Field label="Category"><Select value={entryForm.category || 'Other'} onChange={(e) => setEntryForm((s) => ({ ...s, category: e.target.value as any }))}>
            {categories.map((c) => <option key={c}>{c}</option>)}
          </Select></Field>
          <Field label="Amount"><Input type="number" step="0.01" value={entryForm.amount ?? ''} onChange={(e) => setEntryForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label={entryForm.direction === 'Out' ? 'Debit (other side)' : 'Credit (other side)'} full>
            <Select value={entryForm.offsetAccountId ?? ''} onChange={(e) => setEntryForm((s) => ({ ...s, offsetAccountId: Number(e.target.value) || undefined }))}>
              <option value="">— pick a GL account —</option>
              {ledgerAccounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
            </Select>
          </Field>
          <Field label="Description" full><Input value={entryForm.description || ''} onChange={(e) => setEntryForm((s) => ({ ...s, description: e.target.value }))} /></Field>
        </div>
      </Modal>

      <Modal open={acctModal} onClose={closeAccounts} width={acctView === 'list' ? 640 : 520}
        title={acctView === 'list' ? 'Cash Accounts' : (acctEditing ? 'Edit Cash Account' : 'Add Cash Account')}
        footer={acctView === 'list'
          ? <><div /><Can user={me} module="Cashbook" action="Create"><Btn variant="primary" icon="plus" onClick={openCreateAccount}>Add Account</Btn></Can></>
          : <>
              <Btn variant="plain" onClick={() => setAcctView('list')}>Back</Btn>
              <Btn variant="primary" icon="check" onClick={saveAccount} disabled={acctBusy}>{acctBusy ? 'Saving...' : (acctEditing ? 'Save Changes' : 'Create Account')}</Btn>
            </>}>
        {acctView === 'list' ? (
          <Table cols={[{ label: 'Account' }, { label: 'Branch' }, { label: 'Balance', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 80 }]}
            rows={accounts} empty="No cash accounts yet."
            render={(a) => <>
              <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{a.name}</Td>
              <Td>{a.branch?.name || '—'}</Td>
              <Td align="right" mono>{money(a.currentBalance)}</Td>
              <Td align="center"><Badge tone={(a.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{a.status || 'Active'}</Badge></Td>
              <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                <Can user={me} module="Cashbook" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEditAccount(a)}><Icon n="edit" s={15} /></button></Can>
                <Can user={me} module="Cashbook" action="Delete"><button className="mms-act" title="Delete" onClick={() => removeAccount(a)}><Icon n="trash" s={15} /></button></Can>
              </div></Td>
            </>} />
        ) : (
          <>
            <ErrorBanner error={acctErr} style={{ marginBottom: 14 }} />
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <Field label="Account Name" full><Input value={acctForm.name || ''} onChange={(e) => setAcctForm((s) => ({ ...s, name: e.target.value }))} /></Field>
              <Field label="Branch"><Select value={acctForm.branchId ?? ''} onChange={(e) => setAcctForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
                <option value="">—</option>
                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
              </Select></Field>
              <Field label="Opening Balance"><Input type="number" step="0.01" value={acctForm.openingBalance ?? 0} disabled={!!acctEditing} onChange={(e) => setAcctForm((s) => ({ ...s, openingBalance: e.target.value }))} /></Field>
              <Field label="Status"><Select value={acctForm.status || 'Active'} onChange={(e) => setAcctForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
            </div>
          </>
        )}
      </Modal>
    </div>
  )
}

/* ============================ Cash Transfers ============================ */

interface TransferForm extends Partial<CashTransfer> {}
const blankTransferForm = (): TransferForm => ({ transferDate: new Date().toISOString().slice(0, 10) })

export function CashTransfersScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [accounts, setAccounts] = useState<CashAccount[]>([])
  const [accountId, setAccountId] = useState<number | ''>('')
  const [rows, setRows] = useState<CashTransfer[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [form, setForm] = useState<TransferForm>(blankTransferForm())

  useEffect(() => { cashAccountsApi.list().then(setAccounts).catch(() => setAccounts([])) }, [])

  const refresh = () => {
    setLoading(true)
    cashTransfersApi.list(accountId || undefined).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [accountId]) // eslint-disable-line react-hooks/exhaustive-deps

  const openCreate = () => { setForm(blankTransferForm()); setErr(''); setModal(true) }
  const close = () => { setModal(false); setErr('') }

  const save = async () => {
    if (!form.fromCashAccountId || !form.toCashAccountId) { setErr('Both accounts are required.'); return }
    if (form.fromCashAccountId === form.toCashAccountId) { setErr('From and To accounts must be different.'); return }
    if (!form.amount || Number(form.amount) <= 0) { setErr('Amount must be greater than zero.'); return }
    setBusy(true); setErr('')
    try {
      await cashTransfersApi.create(form)
      close(); refresh()
      notify.success('Transfer requested')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const approve = async (t: CashTransfer) => {
    const ok = await notify.confirm({ title: 'Approve this transfer?', msg: `Move ${money(t.amount)} from ${t.fromAccount?.name} to ${t.toAccount?.name}.`, okText: 'Approve' })
    if (!ok) return
    try { await cashTransfersApi.approve(t.id); refresh(); notify.success('Transfer approved') }
    catch (ex: any) { notify.error('Approve failed', getErrorText(ex)) }
  }

  const reject = async (t: CashTransfer) => {
    const reason = await notify.prompt({ title: 'Reject this transfer?', msg: 'Give a reason — the requester can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try { await cashTransfersApi.reject(t.id, reason || undefined); refresh(); notify.success('Transfer rejected') }
    catch (ex: any) { notify.error('Reject failed', getErrorText(ex)) }
  }

  // Approve/reject is maker-checker (approval_types code 'cash_transfer') — the
  // requester and anyone who already approved can't sign off (backend also 422s).
  const canApproveTransfer = (t: CashTransfer) =>
    canApprove(me, 'cash_transfer')
    && me?.id !== t.pendingApprovalRequest?.requester?.id
    && me?.id !== t.pendingApprovalRequest?.firstApprover?.id

  const remove = async (t: CashTransfer) => {
    const ok = await notify.confirm({ title: 'Delete this transfer request?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await cashTransfersApi.remove(t.id); refresh(); notify.success('Transfer deleted') }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const voidTransfer = async (t: CashTransfer) => {
    const reason = await notify.prompt({ title: 'Void this completed transfer?', msg: `Moves ${money(t.amount)} back from ${t.toAccount?.name} to ${t.fromAccount?.name} and reverses its GL entry.`, placeholder: 'Reason (optional)', danger: true, okText: 'Void' })
    if (reason === null) return
    try { await cashTransfersApi.void(t.id, reason || undefined); refresh(); notify.success('Transfer voided') }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  const statusTone = (s?: string) => s === 'Completed' ? 'green' : s === 'Rejected' || s === 'Voided' ? 'red' : 'amber'

  return (
    <div>
      <PageHead crumbs="Cashbook Management" title="Cash Transfers" icon="swap" sub={`${rows.length} transfers`}
        actions={<Can user={me} module="Cash Transfers" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate} disabled={accounts.length < 2}>Add Transfer</Btn></Can>} />

      <Card style={{ marginBottom: 16 }}>
        <Field label="Cash Account"><Select value={accountId} onChange={(e) => setAccountId(Number(e.target.value) || '')}>
          <option value="">All accounts</option>
          {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </Select></Field>
      </Card>

      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Date' }, { label: 'From' }, { label: 'To' }, { label: 'Amount', align: 'right' }, { label: 'Description' }, { label: 'Status', align: 'center' }, { label: 'Requested By' }, { label: '', align: 'right', w: 110 }]}
          rows={rows}
          render={(t) => <>
            <Td>{t.transferDate}</Td>
            <Td>{t.fromAccount?.name || '—'}</Td>
            <Td>{t.toAccount?.name || '—'}</Td>
            <Td align="right" mono>{money(t.amount)}</Td>
            <Td c="var(--tx-2)" style={{ fontSize: 12.5, whiteSpace: 'normal' }}>{t.description || '—'}</Td>
            <Td align="center">
              <Badge tone={statusTone(t.status)} dot>{t.status || 'Pending'}</Badge>
              {t.status === 'Pending' && t.pendingApprovalRequest && (t.pendingApprovalRequest.requiredApprovals - (t.pendingApprovalRequest.firstApprover ? 1 : 0)) > 0 && (
                <div className="t-2" style={{ fontSize: 10.5, marginTop: 2 }}>
                  needs {t.pendingApprovalRequest.requiredApprovals - (t.pendingApprovalRequest.firstApprover ? 1 : 0)} approval{t.pendingApprovalRequest.requiredApprovals - (t.pendingApprovalRequest.firstApprover ? 1 : 0) === 1 ? '' : 's'}
                </div>
              )}
            </Td>
            <Td>{t.requester?.name || '—'}</Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              {t.status === 'Pending' && canApproveTransfer(t) && <>
                <button className="mms-act" title="Approve" onClick={() => approve(t)}><Icon n="check" s={15} /></button>
                <button className="mms-act" title="Reject" onClick={() => reject(t)}><Icon n="x" s={15} /></button>
              </>}
              {t.status === 'Pending' && <Can user={me} module="Cash Transfers" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(t)}><Icon n="trash" s={15} /></button></Can>}
              {t.status === 'Completed' && <Can user={me} module="Cash Transfers" action="Delete"><button className="mms-act" title="Void" onClick={() => voidTransfer(t)}><Icon n="x" s={15} c="var(--bad)" /></button></Can>}
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={520} title="Add Cash Transfer"
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : 'Request Transfer'}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="From Account">
            <Select value={form.fromCashAccountId ?? ''} onChange={(e) => setForm((s) => ({ ...s, fromCashAccountId: Number(e.target.value) || undefined }))}>
              <option value="">—</option>
              {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </Select>
            <span style={{ fontSize: 12, color: 'var(--tx-2)' }}>
              Available: {money(accounts.find((a) => a.id === form.fromCashAccountId)?.currentBalance)}
            </span>
          </Field>
          <Field label="To Account">
            <Select value={form.toCashAccountId ?? ''} onChange={(e) => setForm((s) => ({ ...s, toCashAccountId: Number(e.target.value) || undefined }))}>
              <option value="">—</option>
              {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </Select>
            <span style={{ fontSize: 12, color: 'var(--tx-2)' }}>
              Available: {money(accounts.find((a) => a.id === form.toCashAccountId)?.currentBalance)}
            </span>
          </Field>
          <Field label="Date"><DateInput value={form.transferDate} onChange={(iso) => setForm((s) => ({ ...s, transferDate: iso }))} /></Field>
          <Field label="Amount"><Input type="number" step="0.01" value={form.amount ?? ''} onChange={(e) => setForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label="Description" full><Input value={form.description || ''} onChange={(e) => setForm((s) => ({ ...s, description: e.target.value }))} /></Field>
        </div>
      </Modal>
    </div>
  )
}

/* ========================= Cash Reconciliation ========================== */
/* "Daily Cash Entry" — a branch-level cash-in-hand count for a given date,
   modeled on the legacy system's Cash in Hand Entry screen: system cash
   balance (from Cash Accounts) + manual adjustments = Total Cash, checked
   against a physical note-denomination count. One record per branch/day
   (re-saving the same date overwrites it — see CashReconciliationController::store). */

interface ReconForm {
  receiptsToBeCancelled: number | string; encashment: number | string; cashFloat: number | string; pettyCash: number | string
  otherBranchCash: number | string; otherBranchId: number | ''
  otherBranchCheque: number | string; otherBranchChequeBranchId: number | ''
  denominations: Denominations; coinsAmount: number | string
  notes: string
}
const blankReconForm = (): ReconForm => ({
  receiptsToBeCancelled: 0, encashment: 0, cashFloat: 0, pettyCash: 0,
  otherBranchCash: 0, otherBranchId: '', otherBranchCheque: 0, otherBranchChequeBranchId: '',
  denominations: {}, coinsAmount: 0, notes: '',
})
const NOTE_COLS: number[][] = [[5000, 2000, 1000, 500, 200, 100], [50, 20, 10]]

export function CashReconciliationScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [branches, setBranches] = useState<Branch[]>([])
  const [branchId, setBranchId] = useState<number | ''>('')
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10))
  const [summary, setSummary] = useState<CashReconciliationSummary | null>(null)
  const [history, setHistory] = useState<CashReconciliation[]>([])
  const [form, setForm] = useState<ReconForm>(blankReconForm())
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { branchesApi.list().then((d) => { setBranches(d); setBranchId((cur) => cur || d[0]?.id || '') }).catch(() => setBranches([])) }, [])

  const loadSummary = () => {
    if (!branchId || !date) return
    setLoading(true)
    cashReconciliationsApi.summary(branchId, date)
      .then((s) => {
        setSummary(s)
        const e = s.existing
        setForm({
          receiptsToBeCancelled: e?.receiptsToBeCancelled ?? 0,
          encashment: e?.encashment ?? 0,
          cashFloat: e?.cashFloat ?? 0,
          pettyCash: e?.pettyCash ?? 0,
          otherBranchCash: e?.otherBranchCash ?? 0,
          otherBranchId: e?.otherBranchId ?? '',
          otherBranchCheque: e?.otherBranchCheque ?? 0,
          otherBranchChequeBranchId: e?.otherBranchChequeBranchId ?? '',
          denominations: e?.denominations ?? {},
          coinsAmount: e?.coinsAmount ?? 0,
          notes: e?.notes ?? '',
        })
      })
      .catch(() => setSummary(null))
      .finally(() => setLoading(false))
  }
  useEffect(() => { loadSummary() }, [branchId, date]) // eslint-disable-line react-hooks/exhaustive-deps

  const loadHistory = () => { cashReconciliationsApi.list(branchId || undefined).then(setHistory).catch(() => setHistory([])) }
  useEffect(() => { loadHistory() }, [branchId]) // eslint-disable-line react-hooks/exhaustive-deps

  const setDeno = (v: number, qty: string) => setForm((s) => ({ ...s, denominations: { ...s.denominations, [v]: Math.max(0, Math.trunc(Number(qty) || 0)) } }))

  const systemCashTotal = Number(summary?.systemCashTotal || 0)
  const chequeInHandTotal = Number(summary?.chequeInHandTotal || 0)
  const postdatedChequeTotal = Number(summary?.postdatedChequeTotal || 0)
  const todayCashReceipts = Number(summary?.todayCashReceipts || 0)
  const todayChequeReceipts = Number(summary?.todayChequeReceipts || 0)
  const chequeRealizedTotal = Number(summary?.chequeRealizedTotal || 0)
  const adjustments = Number(form.receiptsToBeCancelled || 0) + Number(form.encashment || 0) + Number(form.cashFloat || 0) + Number(form.pettyCash || 0)
  const totalCash = systemCashTotal + adjustments + Number(form.otherBranchCash || 0)
  const totalCheque = chequeInHandTotal + Number(form.otherBranchCheque || 0)
  const notesTotal = DENOMINATION_VALUES.reduce((sum, v) => sum + v * Number(form.denominations[v] || 0), 0)
  const countedTotal = notesTotal + Number(form.coinsAmount || 0)
  const variance = countedTotal - totalCash

  const save = async () => {
    if (!branchId) { setErr('Branch is required.'); return }
    setBusy(true); setErr('')
    try {
      await cashReconciliationsApi.save({
        branchId, asAtDate: date, ...form,
        otherBranchId: form.otherBranchId || null,
        otherBranchChequeBranchId: form.otherBranchChequeBranchId || null,
      })
      loadSummary(); loadHistory()
      notify.success('Cash reconciliation saved')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (r: CashReconciliation) => {
    const ok = await notify.confirm({ title: `Delete the reconciliation for ${r.asAtDate}?`, danger: true, okText: 'Delete' })
    if (!ok) return
    try {
      await cashReconciliationsApi.remove(r.id)
      loadHistory()
      if (r.asAtDate === date) loadSummary()
      notify.success('Reconciliation deleted')
    } catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const verify = async (r: CashReconciliation) => {
    const ok = await notify.confirm({ title: `Verify and lock ${r.asAtDate}?`, msg: 'The count is frozen once verified — no more edits for this day.', okText: 'Verify' })
    if (!ok) return
    try { await cashReconciliationsApi.verify(r.id); loadHistory(); notify.success('Reconciliation verified') }
    catch (ex: any) { notify.error('Verify failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Cashbook Management" title="Cash Reconciliation" icon="adjust" sub="Daily cash-in-hand entry" />

      <Card style={{ marginBottom: 16 }}>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr auto', gap: 16, alignItems: 'end' }}>
          <Field label="Branch"><Select value={branchId} onChange={(e) => setBranchId(Number(e.target.value) || '')}>
            <option value="">—</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
          </Select></Field>
          <Field label="As at Date"><DateInput value={date} onChange={(iso) => setDate(iso || date)} /></Field>
          <Btn variant="solid" icon="refund" onClick={loadSummary} disabled={loading || !branchId}>Refresh</Btn>
        </div>
      </Card>

      <ErrorBanner error={err} style={{ marginBottom: 14 }} />

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>
        <Card>
          <h4 style={{ marginBottom: 14 }}>Cash in Hand</h4>
          <div className="col gap-3">
            {(summary?.cashAccounts || []).map((a) => (
              <Field label={a.name} key={a.id}><Input value={money(a.currentBalance)} disabled /></Field>
            ))}
            {!loading && summary && summary.cashAccounts.length === 0 && <div className="t-2" style={{ fontSize: 12.5 }}>No cash accounts for this branch.</div>}
            <Field label="Total"><Input value={money(systemCashTotal)} disabled style={{ fontWeight: 700 }} /></Field>
            <Field label="Receipts to be Cancelled"><Input type="number" step="0.01" value={form.receiptsToBeCancelled} onChange={(e) => setForm((s) => ({ ...s, receiptsToBeCancelled: e.target.value }))} /></Field>
            <Field label="Encashment"><Input type="number" step="0.01" value={form.encashment} onChange={(e) => setForm((s) => ({ ...s, encashment: e.target.value }))} /></Field>
            <Field label="Cash Float"><Input type="number" step="0.01" value={form.cashFloat} onChange={(e) => setForm((s) => ({ ...s, cashFloat: e.target.value }))} /></Field>
            <Field label="Petty Cash"><Input type="number" step="0.01" value={form.pettyCash} onChange={(e) => setForm((s) => ({ ...s, pettyCash: e.target.value }))} /></Field>
            <div className="row gap-2">
              <Field label="Other Branch (Cash)" style={{ flex: 1 }}><Input type="number" step="0.01" value={form.otherBranchCash} onChange={(e) => setForm((s) => ({ ...s, otherBranchCash: e.target.value }))} /></Field>
              <Field label="Branch" style={{ width: 160 }}>
                <Select value={form.otherBranchId} onChange={(e) => setForm((s) => ({ ...s, otherBranchId: Number(e.target.value) || '' }))}>
                  <option value="">—</option>
                  {branches.filter((b) => b.id !== branchId).map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
                </Select>
              </Field>
            </div>
            <Field label="Total Cash"><Input value={money(totalCash)} disabled style={{ fontWeight: 700, color: 'var(--tx-0)' }} /></Field>
          </div>
        </Card>

        <Card>
          <h4 style={{ marginBottom: 14 }}>Cheque in Hand</h4>
          <div className="col gap-3">
            <Field label="Cheque in Hand (undeposited)"><Input value={money(chequeInHandTotal)} disabled /></Field>
            <div className="row gap-2">
              <Field label="Other Branch (Cheque)" style={{ flex: 1 }}><Input type="number" step="0.01" value={form.otherBranchCheque} onChange={(e) => setForm((s) => ({ ...s, otherBranchCheque: e.target.value }))} /></Field>
              <Field label="Branch" style={{ width: 160 }}>
                <Select value={form.otherBranchChequeBranchId} onChange={(e) => setForm((s) => ({ ...s, otherBranchChequeBranchId: Number(e.target.value) || '' }))}>
                  <option value="">—</option>
                  {branches.filter((b) => b.id !== branchId).map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
                </Select>
              </Field>
            </div>
            <Field label="Total Cheque"><Input value={money(totalCheque)} disabled style={{ fontWeight: 700, color: 'var(--tx-0)' }} /></Field>
            <Field label="Postdated Cheque"><Input value={money(postdatedChequeTotal)} disabled /></Field>
          </div>
        </Card>
      </div>

      <Card style={{ marginBottom: 16 }}>
        <h4 style={{ marginBottom: 4 }}>Today's System Activity</h4>
        <div className="t-2" style={{ fontSize: 12, marginBottom: 14 }}>Live from Loan Payments for {date} — informational only, not included in Total Cash/Cheque above.</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
          <Field label="Cash Receipts Today"><Input value={money(todayCashReceipts)} disabled /></Field>
          <Field label="Cheque Receipts Today"><Input value={money(todayChequeReceipts)} disabled /></Field>
          <Field label="Cheques Realized Today"><Input value={money(chequeRealizedTotal)} disabled /></Field>
        </div>
      </Card>

      <Card style={{ marginBottom: 16 }}>
        <h4 style={{ marginBottom: 14 }}>Denomination</h4>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 32px' }}>
          {NOTE_COLS.map((col, i) => (
            <div className="col gap-2" key={i}>
              {col.map((v) => (
                <div key={v} className="row gap-2" style={{ alignItems: 'center' }}>
                  <div style={{ width: 84, fontSize: 13, color: 'var(--tx-1)' }}>Rs. {v.toLocaleString()}</div>
                  <Input type="number" min={0} step={1} style={{ width: 80 }} value={form.denominations[v] ?? ''} onChange={(e) => setDeno(v, e.target.value)} />
                  <span className="t-2">=</span>
                  <Input value={money(v * Number(form.denominations[v] || 0))} disabled style={{ flex: 1 }} />
                </div>
              ))}
              {i === 1 && <>
                <Field label="Coins"><Input type="number" step="0.01" value={form.coinsAmount} onChange={(e) => setForm((s) => ({ ...s, coinsAmount: e.target.value }))} /></Field>
                <Field label="Total Amount"><Input value={money(countedTotal)} disabled style={{ fontWeight: 700, color: 'var(--tx-0)' }} /></Field>
              </>}
            </div>
          ))}
        </div>
      </Card>

      <Card style={{ marginBottom: 16 }}>
        <div className="row between" style={{ alignItems: 'flex-end', flexWrap: 'wrap', gap: 16 }}>
          <div>
            <div className="t-2" style={{ fontSize: 11.5, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 4 }}>Variance (Counted − Total Cash)</div>
            <div style={{ fontSize: 22, fontWeight: 700, color: variance === 0 ? 'var(--ok)' : 'var(--bad)' }}>{money(variance)}</div>
          </div>
          <Field label="Notes" style={{ flex: 1, minWidth: 220 }}><Input value={form.notes} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} /></Field>
          <Can user={me} module="Cash Reconciliation" action="Create">
            <Btn variant="primary" icon="check" onClick={save} disabled={busy || !branchId}>{busy ? 'Saving...' : 'Save'}</Btn>
          </Can>
        </div>
      </Card>

      <Card pad={0}>
        <Table cols={[{ label: 'Date' }, { label: 'Total Cash', align: 'right' }, { label: 'Counted', align: 'right' }, { label: 'Variance', align: 'right' }, { label: 'Reconciled By' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 120 }]}
          rows={history} empty="No reconciliations recorded yet."
          render={(r) => <>
            <Td><button className="mms-act" style={{ padding: '2px 6px', fontWeight: r.asAtDate === date ? 700 : 400 }} onClick={() => setDate(r.asAtDate)}>{r.asAtDate}</button></Td>
            <Td align="right" mono>{money(r.totalCash)}</Td>
            <Td align="right" mono>{money(r.countedTotal)}</Td>
            <Td align="right" mono c={Number(r.variance || 0) === 0 ? 'var(--ok)' : 'var(--bad)'}>{money(r.variance)}</Td>
            <Td>{r.reconciler?.name || '—'}</Td>
            <Td align="center">{r.status === 'Verified'
              ? <span title={`by ${r.verifier?.name || '—'}`} style={{ fontSize: 11.5, color: 'var(--ok)', fontWeight: 600 }}>Verified</span>
              : <span style={{ fontSize: 11.5, color: 'var(--tx-3)' }}>Draft</span>}</Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              {r.status !== 'Verified' && r.reconciler?.id !== me?.id && (
                <Can user={me} module="Cash Reconciliation" action="Edit"><Btn size="sm" variant="primary" icon="check" onClick={() => verify(r)}>Verify</Btn></Can>
              )}
              {r.status !== 'Verified' && (
                <Can user={me} module="Cash Reconciliation" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(r)}><Icon n="trash" s={15} /></button></Can>
              )}
            </div></Td>
          </>} />
      </Card>
    </div>
  )
}
