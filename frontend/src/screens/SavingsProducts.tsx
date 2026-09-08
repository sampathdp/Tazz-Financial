/* Daily Savings — Savings Products (running-balance passbook product catalog) */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { savingsProductsApi, type SavingsProduct, type SavingsCalcMethod, type SavingsPostingFrequency, type AuthUser } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const CALC_METHODS: { value: SavingsCalcMethod; label: string }[] = [
  { value: 'min_monthly_balance', label: 'Minimum Monthly Balance' },
  { value: 'daily_balance', label: 'Average Daily Balance' },
  { value: 'average_balance', label: 'Average (Open + Close) Balance' },
]
const FREQUENCIES: { value: SavingsPostingFrequency; label: string }[] = [
  { value: 'monthly', label: 'Monthly' }, { value: 'quarterly', label: 'Quarterly' }, { value: 'annually', label: 'Annually' },
]
const calcLabel = (m: SavingsCalcMethod) => CALC_METHODS.find((x) => x.value === m)?.label || m

type ProductForm = Partial<SavingsProduct>
const blankForm = (): ProductForm => ({
  interestCalcMethod: 'min_monthly_balance', interestPostingFrequency: 'monthly',
  minOpeningDeposit: '', minBalance: '', withdrawalFee: '0', ledgerFee: '0', dormancyDays: 0,
  status: 'Active', sortOrder: 0,
})

export function SavingsProductsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<SavingsProduct[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<SavingsProduct | null>(null)
  const [form, setForm] = useState<ProductForm>(blankForm())

  const refresh = () => {
    setLoading(true)
    savingsProductsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [])

  const openCreate = () => { setEditing(null); setForm(blankForm()); setErr(''); setModal(true) }
  const openEdit = (p: SavingsProduct) => { setEditing(p); setForm({ ...p }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }
  const set = (patch: Partial<ProductForm>) => setForm((s) => ({ ...s, ...patch }))

  const save = async () => {
    if (!form.name || !form.code) { setErr('Name and code are required.'); return }
    if (form.interestRatePercent == null || Number(form.interestRatePercent) < 0) { setErr('Interest rate is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await savingsProductsApi.update(editing.id, form)
      else await savingsProductsApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Savings product updated' : 'Savings product created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (p: SavingsProduct) => {
    const ok = await notify.confirm({ title: `Delete product "${p.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await savingsProductsApi.remove(p.id); refresh(); notify.success('Savings product deleted', p.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Savings Products" icon="layers" sub={`${rows.length} products`}
        actions={<Can user={me} module="Savings Products" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Product</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Product' }, { label: 'Interest Basis' }, { label: 'Rate', align: 'right' }, { label: 'Posting' }, { label: 'Min Balance', align: 'right' }, { label: 'Accounts', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          rows={rows}
          render={(p) => <>
            <Td c="var(--tx-0)"><div style={{ fontWeight: 600 }}>{p.name}</div><div className="t-2" style={{ fontSize: 11.5 }}>{p.code}</div></Td>
            <Td><Badge tone="blue">{calcLabel(p.interestCalcMethod)}</Badge></Td>
            <Td align="right" mono>{Number(p.interestRatePercent).toFixed(2)}%</Td>
            <Td style={{ textTransform: 'capitalize' }}>{p.interestPostingFrequency}</Td>
            <Td align="right" mono>{Number(p.minBalance || 0).toLocaleString()}</Td>
            <Td align="right" mono>{p.accountsCount ?? 0}</Td>
            <Td align="center"><Badge tone={(p.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{p.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Savings Products" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(p)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Savings Products" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(p)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={620} title={editing ? 'Edit Savings Product' : 'Add Savings Product'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create Product')}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Product Name" full><Input value={form.name || ''} onChange={(e) => set({ name: e.target.value })} /></Field>
          <Field label="Code"><Input value={form.code || ''} onChange={(e) => set({ code: e.target.value.toUpperCase() })} disabled={!!editing} /></Field>
          <Field label="Annual Interest Rate (%)"><Input type="number" step="0.01" value={form.interestRatePercent ?? ''} onChange={(e) => set({ interestRatePercent: e.target.value })} /></Field>
          <Field label="Interest Basis"><Select value={form.interestCalcMethod} onChange={(e) => set({ interestCalcMethod: e.target.value as SavingsCalcMethod })}>
            {CALC_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </Select></Field>
          <Field label="Posting Frequency"><Select value={form.interestPostingFrequency} onChange={(e) => set({ interestPostingFrequency: e.target.value as SavingsPostingFrequency })}>
            {FREQUENCIES.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </Select></Field>
          <Field label="Minimum Opening Deposit"><Input type="number" step="0.01" value={form.minOpeningDeposit ?? ''} onChange={(e) => set({ minOpeningDeposit: e.target.value })} /></Field>
          <Field label="Minimum Balance"><Input type="number" step="0.01" value={form.minBalance ?? ''} onChange={(e) => set({ minBalance: e.target.value })} /></Field>
          <Field label="Withdrawal Fee"><Input type="number" step="0.01" value={form.withdrawalFee ?? ''} onChange={(e) => set({ withdrawalFee: e.target.value })} /></Field>
          <Field label="Ledger Fee (charged once at open)"><Input type="number" step="0.01" value={form.ledgerFee ?? ''} onChange={(e) => set({ ledgerFee: e.target.value })} /></Field>
          <Field label="Dormancy Days (0 = never)"><Input type="number" value={form.dormancyDays ?? 0} onChange={(e) => set({ dormancyDays: Number(e.target.value) || 0 })} /></Field>
          <Field label="Reference Format (optional)"><Input value={form.referenceFormat || ''} onChange={(e) => set({ referenceFormat: e.target.value })} placeholder="{BRANCH}-SAV-{SEQ:6}" /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => set({ status: e.target.value as any })}><option>Active</option><option>Inactive</option></Select></Field>
        </div>
      </Modal>
    </div>
  )
}

export default SavingsProductsScreen
