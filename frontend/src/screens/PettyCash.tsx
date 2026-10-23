/* Tazz Investment & Credit — Petty Cash (imprest float management).
   Bundles Funds + Replenishments + Vouchers into one screen/permission
   ("Petty Cash"), the same "related sub-resources, one module" pattern
   Cashbook Management uses for Cash Accounts + Cashbook Entries. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Modal, Table, Td, ErrorBanner , LoadingBlock } from '../components/ui'
import {
  api, branchesApi, cashAccountsApi, bankAccountsApi, expenseCategoriesApi, vendorsApi, staffApi,
  pettyCashFundsApi, pettyCashReplenishmentsApi, pettyCashVouchersApi,
  type Branch, type CashAccount, type BankAccount, type ExpenseCategory, type Vendor, type StaffOption, type AuthUser,
  type PettyCashFund, type PettyCashReplenishment, type PettyCashVoucher, type VoucherStatus,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)
const voucherTone = (s: VoucherStatus) => s === 'Voided' ? 'red' : 'green'

interface FundForm extends Partial<PettyCashFund> {}
const blankFundForm = (): FundForm => ({ status: 'Active' })

interface ReplenishForm { amount: number | string; fundingMethod: 'Cash' | 'Bank'; cashAccountId: number | ''; bankAccountId: number | ''; paidAt: string }
const blankReplenishForm = (): ReplenishForm => ({ amount: '', fundingMethod: 'Cash', cashAccountId: '', bankAccountId: '', paidAt: todayIso() })

interface SpendForm { expenseCategoryId: number | ''; vendorId: number | ''; staffId: number | ''; amount: number | string; description: string; spentAt: string }
const blankSpendForm = (): SpendForm => ({ expenseCategoryId: '', vendorId: '', staffId: '', amount: '', description: '', spentAt: todayIso() })

export function PettyCashScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [funds, setFunds] = useState<PettyCashFund[]>([])
  const [fundId, setFundId] = useState<number | ''>('')
  const [branches, setBranches] = useState<Branch[]>([])
  const [cashAccounts, setCashAccounts] = useState<CashAccount[]>([])
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])
  const [categories, setCategories] = useState<ExpenseCategory[]>([])
  const [vendors, setVendors] = useState<Vendor[]>([])
  const [staff, setStaff] = useState<StaffOption[]>([])
  const [activeUsers, setActiveUsers] = useState<{ id: number; name: string }[]>([])

  const [vouchers, setVouchers] = useState<PettyCashVoucher[]>([])
  const [replenishments, setReplenishments] = useState<PettyCashReplenishment[]>([])
  const [loading, setLoading] = useState(true)

  const [fundModal, setFundModal] = useState(false)
  const [fundView, setFundView] = useState<'list' | 'form'>('list')
  const [fundEditing, setFundEditing] = useState<PettyCashFund | null>(null)
  const [fundForm, setFundForm] = useState<FundForm>(blankFundForm())
  const [fundErr, setFundErr] = useState('')
  const [fundBusy, setFundBusy] = useState(false)

  const [replenishModal, setReplenishModal] = useState(false)
  const [replenishForm, setReplenishForm] = useState<ReplenishForm>(blankReplenishForm())

  const [spendModal, setSpendModal] = useState(false)
  const [spendForm, setSpendForm] = useState<SpendForm>(blankSpendForm())

  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const refreshFunds = () => pettyCashFundsApi.list().then((d) => { setFunds(d); setFundId((cur) => cur || d[0]?.id || '') }).catch(() => setFunds([]))
  useEffect(() => {
    refreshFunds()
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    cashAccountsApi.list().then(setCashAccounts).catch(() => setCashAccounts([]))
    bankAccountsApi.list().then(setBankAccounts).catch(() => setBankAccounts([]))
    expenseCategoriesApi.list().then(setCategories).catch(() => setCategories([]))
    vendorsApi.list().then(setVendors).catch(() => setVendors([]))
    staffApi.options().then(setStaff).catch(() => setStaff([]))
    api.users.active().then(setActiveUsers).catch(() => setActiveUsers([]))
  }, [])

  const refresh = () => {
    if (!fundId) { setVouchers([]); setReplenishments([]); setLoading(false); return }
    setLoading(true)
    Promise.all([
      pettyCashVouchersApi.list({ pettyCashFundId: fundId }),
      pettyCashReplenishmentsApi.list({ pettyCashFundId: fundId }),
    ]).then(([v, r]) => { setVouchers(v); setReplenishments(r) })
      .catch(() => { setVouchers([]); setReplenishments([]) })
      .finally(() => setLoading(false))
  }
  useEffect(refresh, [fundId]) // eslint-disable-line react-hooks/exhaustive-deps

  const selectedFund = funds.find((f) => f.id === fundId) || null

  /* ---------- fund management ---------- */
  const openFunds = () => { setFundView('list'); setFundModal(true) }
  const closeFunds = () => { setFundModal(false); setFundEditing(null); setFundErr('') }
  const openCreateFund = () => { setFundEditing(null); setFundForm(blankFundForm()); setFundErr(''); setFundView('form') }
  const openEditFund = (f: PettyCashFund) => { setFundEditing(f); setFundForm({ ...f }); setFundErr(''); setFundView('form') }

  const saveFund = async () => {
    if (!fundForm.name || !fundForm.branchId) { setFundErr('Fund name and branch are required.'); return }
    if (!fundForm.floatLimit || Number(fundForm.floatLimit) <= 0) { setFundErr('Float limit must be greater than zero.'); return }
    setFundBusy(true); setFundErr('')
    try {
      if (fundEditing) await pettyCashFundsApi.update(fundEditing.id, fundForm)
      else await pettyCashFundsApi.create(fundForm as any)
      setFundView('list'); refreshFunds()
      notify.success(fundEditing ? 'Fund updated' : 'Fund created', fundForm.name)
    } catch (ex: any) {
      setFundErr(getErrorText(ex, 'Save failed'))
    } finally { setFundBusy(false) }
  }

  const removeFund = async (f: PettyCashFund) => {
    const ok = await notify.confirm({ title: `Delete "${f.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await pettyCashFundsApi.remove(f.id); refreshFunds(); notify.success('Fund deleted', f.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  /* ---------- replenish (top-up) ---------- */
  const openReplenish = () => { setReplenishForm(blankReplenishForm()); setErr(''); setReplenishModal(true) }
  const closeReplenish = () => { setReplenishModal(false); setErr('') }

  const saveReplenish = async () => {
    if (!fundId) return
    if (!replenishForm.amount || Number(replenishForm.amount) <= 0) { setErr('Amount must be greater than zero.'); return }
    if (replenishForm.fundingMethod === 'Cash' && !replenishForm.cashAccountId) { setErr('Pick which till this came from.'); return }
    if (replenishForm.fundingMethod === 'Bank' && !replenishForm.bankAccountId) { setErr('Pick which bank account this came from.'); return }
    setBusy(true); setErr('')
    try {
      await pettyCashReplenishmentsApi.create({
        pettyCashFundId: fundId, amount: replenishForm.amount, fundingMethod: replenishForm.fundingMethod,
        cashAccountId: replenishForm.cashAccountId ? Number(replenishForm.cashAccountId) : undefined,
        bankAccountId: replenishForm.bankAccountId ? Number(replenishForm.bankAccountId) : undefined,
        paidAt: replenishForm.paidAt,
      })
      closeReplenish(); refresh(); refreshFunds()
      notify.success('Float replenished')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Replenishment failed'))
    } finally { setBusy(false) }
  }

  const voidReplenishment = async (r: PettyCashReplenishment) => {
    const reason = await notify.prompt({
      title: `Void top-up ${r.code}?`, msg: `This reverses its GL posting and returns ${money(r.amount)} to the source account.`,
      placeholder: 'Reason for voiding', danger: true, okText: 'Void',
    })
    if (reason === null) return
    try { await pettyCashReplenishmentsApi.void(r.id, reason || undefined); refresh(); refreshFunds(); notify.success('Top-up voided', r.code) }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  /* ---------- spend (voucher) ---------- */
  const openSpend = () => { setSpendForm(blankSpendForm()); setErr(''); setSpendModal(true) }
  const closeSpend = () => { setSpendModal(false); setErr('') }

  const saveSpend = async () => {
    if (!fundId) return
    if (!spendForm.expenseCategoryId) { setErr('Expense category is required.'); return }
    if (!spendForm.amount || Number(spendForm.amount) <= 0) { setErr('Amount must be greater than zero.'); return }
    setBusy(true); setErr('')
    try {
      await pettyCashVouchersApi.create({
        pettyCashFundId: fundId, expenseCategoryId: Number(spendForm.expenseCategoryId),
        vendorId: spendForm.vendorId ? Number(spendForm.vendorId) : undefined,
        staffId: spendForm.staffId ? Number(spendForm.staffId) : undefined,
        amount: spendForm.amount, description: spendForm.description || undefined, spentAt: spendForm.spentAt,
      })
      closeSpend(); refresh(); refreshFunds()
      notify.success('Petty cash spend recorded')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const voidVoucher = async (v: PettyCashVoucher) => {
    const reason = await notify.prompt({
      title: `Void voucher ${v.code}?`, msg: `This reverses its GL posting and returns ${money(v.amount)} to the float.`,
      placeholder: 'Reason for voiding', danger: true, okText: 'Void',
    })
    if (reason === null) return
    try { await pettyCashVouchersApi.void(v.id, reason || undefined); refresh(); refreshFunds(); notify.success('Voucher voided', v.code) }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Expense Management" title="Petty Cash" icon="wallet"
        sub={selectedFund ? `${selectedFund.name} — Balance: ${money(selectedFund.currentBalance)} / Limit: ${money(selectedFund.floatLimit)}` : `${funds.length} floats`}
        actions={<>
          <Can user={me} module="Petty Cash" action="Edit"><Btn variant="plain" icon="building" onClick={openFunds}>Manage Funds</Btn></Can>
          <Can user={me} module="Petty Cash" action="Create"><Btn variant="solid" icon="plus" onClick={openReplenish} disabled={!fundId}>Replenish</Btn></Can>
          <Can user={me} module="Petty Cash" action="Create"><Btn variant="primary" icon="wallet" onClick={openSpend} disabled={!fundId}>Record Spend</Btn></Can>
        </>} />

      <Card style={{ marginBottom: 16 }}>
        <Field label="Petty Cash Fund"><Select value={fundId} onChange={(e) => setFundId(Number(e.target.value) || '')}>
          <option value="">Select a float…</option>
          {funds.map((f) => <option key={f.id} value={f.id}>{f.code} — {f.name} ({f.branch?.code})</option>)}
        </Select></Field>
      </Card>

      <Card pad={0} style={{ marginBottom: 16 }}>
        <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)', fontWeight: 700, fontSize: 13.5 }}>Spend</div>
        {loading ? <LoadingBlock /> :
        <Table
          cols={[{ label: 'Code' }, { label: 'Category' }, { label: 'Payee' }, { label: 'Date' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 90 }]}
          empty="No spend recorded against this float yet" rows={vouchers}
          render={(v) => <>
            <Td mono>{v.code}</Td>
            <Td>{v.category?.name || '—'}</Td>
            <Td>{v.staff ? `${v.staff.name} (${v.staff.staffCode})` : (v.vendor?.name || '—')}</Td>
            <Td>{dateOnly(v.spentAt)}</Td>
            <Td align="right" mono>{money(v.amount)}</Td>
            <Td align="center"><Badge tone={voucherTone(v.status)} dot>{v.status}</Badge></Td>
            <Td align="right">{v.status === 'Issued' &&
              <Can user={me} module="Petty Cash" action="Delete"><Btn variant="ghost" size="sm" onClick={() => voidVoucher(v)}>Void</Btn></Can>}
            </Td>
          </>} />}
      </Card>

      <Card pad={0}>
        <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)', fontWeight: 700, fontSize: 13.5 }}>Top-ups</div>
        {loading ? <LoadingBlock /> :
        <Table
          cols={[{ label: 'Code' }, { label: 'Source' }, { label: 'Date' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 90 }]}
          empty="No top-ups recorded for this float yet" rows={replenishments}
          render={(r) => <>
            <Td mono>{r.code}</Td>
            <Td>{r.fundingMethod === 'Cash' ? (r.cashAccount?.name || '—') : (r.bankAccount?.name || '—')}</Td>
            <Td>{dateOnly(r.paidAt)}</Td>
            <Td align="right" mono>{money(r.amount)}</Td>
            <Td align="center"><Badge tone={voucherTone(r.status)} dot>{r.status}</Badge></Td>
            <Td align="right">{r.status === 'Issued' &&
              <Can user={me} module="Petty Cash" action="Delete"><Btn variant="ghost" size="sm" onClick={() => voidReplenishment(r)}>Void</Btn></Can>}
            </Td>
          </>} />}
      </Card>

      {/* ---------- Manage Funds modal ---------- */}
      <Modal open={fundModal} onClose={closeFunds} width={640} title={fundView === 'list' ? 'Petty Cash Funds' : (fundEditing ? 'Edit Fund' : 'Add Fund')}
        footer={fundView === 'list' ? <>
          <Can user={me} module="Petty Cash" action="Create"><Btn variant="primary" icon="plus" onClick={openCreateFund}>Add Fund</Btn></Can>
          <Btn variant="plain" onClick={closeFunds}>Close</Btn>
        </> : <>
          <Btn variant="plain" onClick={() => setFundView('list')}>Back</Btn>
          <Btn variant="primary" icon="check" onClick={saveFund} disabled={fundBusy}>{fundBusy ? 'Saving...' : (fundEditing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        {fundView === 'list' ? (
          <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'Branch' }, { label: 'Custodian' }, { label: 'Balance / Limit', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 90 }]}
            empty="No petty cash funds yet" rows={funds}
            render={(f) => <>
              <Td mono>{f.code}</Td>
              <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{f.name}</Td>
              <Td>{f.branch?.name || '—'}</Td>
              <Td>{f.custodian?.name || '—'}</Td>
              <Td align="right" mono>{money(f.currentBalance)} / {money(f.floatLimit)}</Td>
              <Td align="center"><Badge tone={f.status === 'Active' ? 'green' : 'red'} dot>{f.status}</Badge></Td>
              <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                <Btn variant="ghost" size="sm" onClick={() => openEditFund(f)}>Edit</Btn>
                <Btn variant="ghost" size="sm" onClick={() => removeFund(f)}>Delete</Btn>
              </div></Td>
            </>} />
        ) : (
          <>
            <ErrorBanner error={fundErr} />
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <Field label="Fund Name" full><Input value={fundForm.name || ''} onChange={(e) => setFundForm((s) => ({ ...s, name: e.target.value }))} /></Field>
              <Field label="Branch"><Select value={fundForm.branchId || ''} onChange={(e) => setFundForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
                <option value="">Select branch…</option>
                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
              </Select></Field>
              <Field label="Custodian"><Select value={fundForm.custodianId || ''} onChange={(e) => setFundForm((s) => ({ ...s, custodianId: Number(e.target.value) || undefined }))}>
                <option value="">No custodian</option>
                {activeUsers.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
              </Select></Field>
              <Field label="Float Limit"><Input type="number" step="0.01" value={fundForm.floatLimit ?? ''} onChange={(e) => setFundForm((s) => ({ ...s, floatLimit: e.target.value }))} /></Field>
              <Field label="Status"><Select value={fundForm.status || 'Active'} onChange={(e) => setFundForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
            </div>
          </>
        )}
      </Modal>

      {/* ---------- Replenish modal ---------- */}
      <Modal open={replenishModal} onClose={closeReplenish} width={520} title="Replenish Float" sub={selectedFund ? `${selectedFund.code} — ${selectedFund.name}` : undefined}
        footer={<>
          <Btn variant="plain" onClick={closeReplenish}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveReplenish} disabled={busy}>{busy ? 'Saving...' : 'Replenish'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Amount"><Input type="number" step="0.01" value={replenishForm.amount} onChange={(e) => setReplenishForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label="Paid At"><DateInput value={replenishForm.paidAt} onChange={(iso) => setReplenishForm((s) => ({ ...s, paidAt: iso }))} /></Field>
          <Field label="Funding Method"><Select value={replenishForm.fundingMethod} onChange={(e) => setReplenishForm((s) => ({ ...s, fundingMethod: e.target.value as 'Cash' | 'Bank' }))}>
            <option value="Cash">Cash</option><option value="Bank">Bank</option>
          </Select></Field>
          {replenishForm.fundingMethod === 'Cash' ? (
            <Field label="Cash Till"><Select value={replenishForm.cashAccountId} onChange={(e) => setReplenishForm((s) => ({ ...s, cashAccountId: Number(e.target.value) || '' }))}>
              <option value="">Select till…</option>
              {cashAccounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </Select></Field>
          ) : (
            <Field label="Bank Account"><Select value={replenishForm.bankAccountId} onChange={(e) => setReplenishForm((s) => ({ ...s, bankAccountId: Number(e.target.value) || '' }))}>
              <option value="">Select account…</option>
              {bankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.bank?.name || ''}</option>)}
            </Select></Field>
          )}
        </div>
      </Modal>

      {/* ---------- Record Spend modal ---------- */}
      <Modal open={spendModal} onClose={closeSpend} width={560} title="Record Petty Cash Spend" sub={selectedFund ? `${selectedFund.code} — Balance ${money(selectedFund.currentBalance)}` : undefined}
        footer={<>
          <Btn variant="plain" onClick={closeSpend}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveSpend} disabled={busy}>{busy ? 'Saving...' : 'Record'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Expense Category"><Select value={spendForm.expenseCategoryId} onChange={(e) => setSpendForm((s) => ({ ...s, expenseCategoryId: Number(e.target.value) || '' }))}>
            <option value="">Select category…</option>
            {categories.map((c) => <option key={c.id} value={c.id} disabled={!c.chartOfAccountId}>{c.parent?.name ? `${c.parent.name} › ` : ''}{c.name}{!c.chartOfAccountId ? ' (not linked to GL)' : ''}</option>)}
          </Select></Field>
          <Field label="Vendor (optional)"><Select value={spendForm.vendorId} onChange={(e) => setSpendForm((s) => ({ ...s, vendorId: Number(e.target.value) || '', staffId: '' }))}>
            <option value="">No vendor</option>
            {vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
          </Select></Field>
          <Field label="Staff (optional)"><Select value={spendForm.staffId} onChange={(e) => setSpendForm((s) => ({ ...s, staffId: Number(e.target.value) || '', vendorId: '' }))}>
            <option value="">No staff</option>
            {staff.map((p) => <option key={p.id} value={p.id}>{p.staffCode} · {p.name}{p.phone ? ` · ${p.phone}` : ''}</option>)}
          </Select></Field>
          <Field label="Amount"><Input type="number" step="0.01" value={spendForm.amount} onChange={(e) => setSpendForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label="Spent At"><DateInput value={spendForm.spentAt} onChange={(iso) => setSpendForm((s) => ({ ...s, spentAt: iso }))} /></Field>
          <Field label="Description" full><Input value={spendForm.description} onChange={(e) => setSpendForm((s) => ({ ...s, description: e.target.value }))} /></Field>
        </div>
      </Modal>
    </div>
  )
}
