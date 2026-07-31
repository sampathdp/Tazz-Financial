/* Tazz Investment & Credit — Bank Management (API-backed) */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, BankSelect, Modal, Table, Td, inputStyle, ErrorBanner } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  branchesApi, bankAccountsApi, bankTransactionsApi, bankReconciliationsApi,
  type Branch, type AuthUser, type BankAccount, type BankAccountType,
  type BankTransaction, type BankTxnType, type BankReconciliation,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/* ============================ Bank Accounts ============================ */

interface AccountForm extends Partial<BankAccount> {}
const blankAccountForm = (): AccountForm => ({ status: 'Active', accountType: 'Savings', currency: 'LKR', openingBalance: 0 })

export function BankAccountsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<BankAccount[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<BankAccount | null>(null)
  const [form, setForm] = useState<AccountForm>(blankAccountForm())

  const refresh = () => {
    setLoading(true)
    bankAccountsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh(); branchesApi.list().then(setBranches).catch(() => setBranches([])) }, [])

  const openCreate = () => { setEditing(null); setForm(blankAccountForm()); setErr(''); setModal(true) }
  const openEdit = (a: BankAccount) => { setEditing(a); setForm({ ...a }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name || !form.bankName || !form.accountNumber) { setErr('Account name, bank name and account number are required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await bankAccountsApi.update(editing.id, form)
      else await bankAccountsApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Bank account updated' : 'Bank account created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (a: BankAccount) => {
    const ok = await notify.confirm({ title: `Delete account "${a.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await bankAccountsApi.remove(a.id); refresh(); notify.success('Bank account deleted', a.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const types: BankAccountType[] = ['Savings', 'Current', 'Fixed Deposit']

  return (
    <div>
      <PageHead crumbs="Bank Management" title="Bank Accounts" icon="building" sub={`${rows.length} accounts`}
        actions={<Can user={me} module="Bank Accounts" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Account</Btn></Can>} />
      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Account' }, { label: 'Bank' }, { label: 'Type' }, { label: 'Branch' }, { label: 'Balance', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          rows={rows}
          render={(a) => <>
            <Td c="var(--tx-0)"><div style={{ fontWeight: 600 }}>{a.name}</div><div className="t-2" style={{ fontSize: 11.5 }} >{a.accountNumber}</div></Td>
            <Td>{a.bankName}{a.branchName && <div className="t-2" style={{ fontSize: 11 }}>{a.branchName}</div>}</Td>
            <Td><Badge tone="neutral">{a.accountType || 'Savings'}</Badge></Td>
            <Td>{a.branch?.name || '—'}</Td>
            <Td align="right" mono>{a.currency || 'LKR'} {money(a.currentBalance)}</Td>
            <Td align="center"><Badge tone={(a.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{a.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Bank Accounts" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(a)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Bank Accounts" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(a)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={560} title={editing ? 'Edit Bank Account' : 'Add Bank Account'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create Account')}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Account Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Bank Name"><BankSelect value={form.bankName || ''} onChange={(v) => setForm((s) => ({ ...s, bankName: v }))} /></Field>
          <Field label="Bank Branch"><Input value={form.branchName || ''} onChange={(e) => setForm((s) => ({ ...s, branchName: e.target.value }))} /></Field>
          <Field label="Account Number"><Input value={form.accountNumber || ''} onChange={(e) => setForm((s) => ({ ...s, accountNumber: e.target.value }))} disabled={!!editing} /></Field>
          <Field label="Account Type"><Select value={form.accountType || 'Savings'} onChange={(e) => setForm((s) => ({ ...s, accountType: e.target.value as any }))}>
            {types.map((t) => <option key={t}>{t}</option>)}
          </Select></Field>
          <Field label="Our Branch"><Select value={form.branchId ?? ''} onChange={(e) => setForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Currency"><Input value={form.currency || 'LKR'} onChange={(e) => setForm((s) => ({ ...s, currency: e.target.value.toUpperCase() }))} /></Field>
          <Field label="Opening Balance"><Input type="number" step="0.01" value={form.openingBalance ?? 0} disabled={!!editing} onChange={(e) => setForm((s) => ({ ...s, openingBalance: e.target.value }))} /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
          <Field label="Notes" full><textarea value={form.notes || ''} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}

/* ========================== Bank Transactions =========================== */

interface TxnForm extends Partial<BankTransaction> {}
const blankTxnForm = (accountId?: number): TxnForm => ({ bankAccountId: accountId, type: 'Deposit', status: 'Cleared', txnDate: new Date().toISOString().slice(0, 10) })

export function BankTransactionsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [accounts, setAccounts] = useState<BankAccount[]>([])
  const [accountId, setAccountId] = useState<number | ''>('')
  const [rows, setRows] = useState<BankTransaction[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [form, setForm] = useState<TxnForm>(blankTxnForm())

  useEffect(() => { bankAccountsApi.list().then((d) => { setAccounts(d); setAccountId((cur) => cur || d[0]?.id || '') }).catch(() => setAccounts([])) }, [])

  const refresh = () => {
    setLoading(true)
    bankTransactionsApi.list(accountId || undefined).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [accountId]) // eslint-disable-line react-hooks/exhaustive-deps

  const selectedAccount = accounts.find((a) => a.id === accountId) || null

  const openCreate = () => { setForm(blankTxnForm(accountId || undefined)); setErr(''); setModal(true) }
  const close = () => { setModal(false); setErr('') }

  const save = async () => {
    if (!form.bankAccountId) { setErr('Bank account is required.'); return }
    if (!form.amount || Number(form.amount) <= 0) { setErr('Amount must be greater than zero.'); return }
    setBusy(true); setErr('')
    try {
      await bankTransactionsApi.create(form)
      close(); refresh()
      notify.success('Transaction recorded')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (t: BankTransaction) => {
    const ok = await notify.confirm({ title: 'Delete this transaction?', msg: 'This reverses its effect on the account balance. This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await bankTransactionsApi.remove(t.id); refresh(); notify.success('Transaction deleted') }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const types: BankTxnType[] = ['Deposit', 'Withdrawal', 'TransferIn', 'TransferOut']
  const isCredit = (t: BankTxnType) => t === 'Deposit' || t === 'TransferIn'

  return (
    <div>
      <PageHead crumbs="Bank Management" title="Bank Transactions" icon="receipt"
        sub={selectedAccount ? `${selectedAccount.name} — Balance: ${selectedAccount.currency || 'LKR'} ${money(selectedAccount.currentBalance)}` : `${rows.length} transactions`}
        actions={<Can user={me} module="Bank Transactions" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate} disabled={!accounts.length}>Add Transaction</Btn></Can>} />

      <Card style={{ marginBottom: 16 }}>
        <Field label="Bank Account"><Select value={accountId} onChange={(e) => setAccountId(Number(e.target.value) || '')}>
          <option value="">All accounts</option>
          {accounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.accountNumber}</option>)}
        </Select></Field>
      </Card>

      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Date' }, { label: 'Account' }, { label: 'Type' }, { label: 'Reference' }, { label: 'Description' }, { label: 'Amount', align: 'right' }, { label: 'Balance', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 60 }]}
          rows={rows}
          render={(t) => <>
            <Td>{t.txnDate}</Td>
            <Td>{t.bankAccount?.name || '—'}</Td>
            <Td><Badge tone={isCredit(t.type) ? 'green' : 'red'}>{t.type}</Badge></Td>
            <Td mono style={{ fontSize: 12 }}>{t.referenceNo || '—'}</Td>
            <Td c="var(--tx-2)" style={{ fontSize: 12.5, whiteSpace: 'normal' }}>{t.description || '—'}</Td>
            <Td align="right" mono c={isCredit(t.type) ? 'var(--ok)' : 'var(--bad)'}>{isCredit(t.type) ? '+' : '-'}{money(t.amount)}</Td>
            <Td align="right" mono>{money(t.balanceAfter)}</Td>
            <Td align="center"><Badge tone={t.status === 'Pending' ? 'amber' : 'green'} dot>{t.status || 'Cleared'}</Badge></Td>
            <Td align="right"><Can user={me} module="Bank Transactions" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(t)}><Icon n="trash" s={15} /></button></Can></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={520} title="Add Transaction"
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : 'Record Transaction'}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Bank Account" full><Select value={form.bankAccountId ?? ''} onChange={(e) => setForm((s) => ({ ...s, bankAccountId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.accountNumber}</option>)}
          </Select></Field>
          <Field label="Date"><DateInput value={form.txnDate} onChange={(iso) => setForm((s) => ({ ...s, txnDate: iso }))} /></Field>
          <Field label="Type"><Select value={form.type || 'Deposit'} onChange={(e) => setForm((s) => ({ ...s, type: e.target.value as any }))}>
            {types.map((t) => <option key={t}>{t}</option>)}
          </Select></Field>
          <Field label="Amount"><Input type="number" step="0.01" value={form.amount ?? ''} onChange={(e) => setForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label="Status"><Select value={form.status || 'Cleared'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Cleared</option><option>Pending</option></Select></Field>
          <Field label="Reference No"><Input value={form.referenceNo || ''} onChange={(e) => setForm((s) => ({ ...s, referenceNo: e.target.value }))} /></Field>
          <Field label="Description" full><Input value={form.description || ''} onChange={(e) => setForm((s) => ({ ...s, description: e.target.value }))} /></Field>
        </div>
      </Modal>
    </div>
  )
}

/* ========================= Bank Reconciliation ========================== */

interface ReconForm extends Partial<BankReconciliation> {}
const blankReconForm = (accountId?: number): ReconForm => ({ bankAccountId: accountId, statementDate: new Date().toISOString().slice(0, 10) })

export function BankReconciliationScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [accounts, setAccounts] = useState<BankAccount[]>([])
  const [accountId, setAccountId] = useState<number | ''>('')
  const [rows, setRows] = useState<BankReconciliation[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<BankReconciliation | null>(null)
  const [form, setForm] = useState<ReconForm>(blankReconForm())

  useEffect(() => { bankAccountsApi.list().then((d) => { setAccounts(d); setAccountId((cur) => cur || d[0]?.id || '') }).catch(() => setAccounts([])) }, [])

  const refresh = () => {
    setLoading(true)
    bankReconciliationsApi.list(accountId || undefined).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [accountId]) // eslint-disable-line react-hooks/exhaustive-deps

  const selectedAccount = accounts.find((a) => a.id === accountId) || null

  const openCreate = () => { setEditing(null); setForm(blankReconForm(accountId || undefined)); setErr(''); setModal(true) }
  const openEdit = (r: BankReconciliation) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.bankAccountId) { setErr('Bank account is required.'); return }
    if (form.statementBalance === undefined || form.statementBalance === null || form.statementBalance === '') { setErr('Statement balance is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await bankReconciliationsApi.update(editing.id, form)
      else await bankReconciliationsApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Reconciliation updated' : 'Reconciliation recorded')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (r: BankReconciliation) => {
    const ok = await notify.confirm({ title: 'Delete this reconciliation record?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await bankReconciliationsApi.remove(r.id); refresh(); notify.success('Reconciliation deleted') }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Bank Management" title="Bank Reconciliation" icon="adjust"
        sub={selectedAccount ? `${selectedAccount.name} — Book Balance: ${selectedAccount.currency || 'LKR'} ${money(selectedAccount.currentBalance)}` : `${rows.length} records`}
        actions={<Can user={me} module="Bank Reconciliation" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate} disabled={!accounts.length}>Add Reconciliation</Btn></Can>} />

      <Card style={{ marginBottom: 16 }}>
        <Field label="Bank Account"><Select value={accountId} onChange={(e) => setAccountId(Number(e.target.value) || '')}>
          <option value="">All accounts</option>
          {accounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.accountNumber}</option>)}
        </Select></Field>
      </Card>

      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Statement Date' }, { label: 'Account' }, { label: 'Statement Bal.', align: 'right' }, { label: 'Book Bal.', align: 'right' }, { label: 'Difference', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          rows={rows}
          render={(r) => <>
            <Td>{r.statementDate}</Td>
            <Td>{r.bankAccount?.name || '—'}</Td>
            <Td align="right" mono>{money(r.statementBalance)}</Td>
            <Td align="right" mono>{money(r.bookBalance)}</Td>
            <Td align="right" mono c={Number(r.difference || 0) === 0 ? 'var(--ok)' : 'var(--bad)'}>{money(r.difference)}</Td>
            <Td align="center"><Badge tone={r.status === 'Reconciled' ? 'green' : 'amber'} dot>{r.status || 'Pending'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Bank Reconciliation" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(r)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Bank Reconciliation" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(r)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={520} title={editing ? 'Edit Reconciliation' : 'Add Reconciliation'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Record Reconciliation')}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Bank Account" full><Select value={form.bankAccountId ?? ''} disabled={!!editing} onChange={(e) => setForm((s) => ({ ...s, bankAccountId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.accountNumber}</option>)}
          </Select></Field>
          <Field label="Statement Date"><DateInput value={form.statementDate} onChange={(iso) => setForm((s) => ({ ...s, statementDate: iso }))} /></Field>
          <Field label="Statement Balance"><Input type="number" step="0.01" value={form.statementBalance ?? ''} onChange={(e) => setForm((s) => ({ ...s, statementBalance: e.target.value }))} /></Field>
          <Field label="Notes" full><textarea value={form.notes || ''} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}
