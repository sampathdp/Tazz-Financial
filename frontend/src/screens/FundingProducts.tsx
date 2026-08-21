/* Funding Management — Funding Products (Fixed Deposit product catalog) */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, inputStyle, ErrorBanner , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { fundingProductsApi, type FundingProduct, type FdInterestMethod, type AuthUser } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const STANDARD_TERMS = [3, 6, 9, 12]

interface ProductForm extends Partial<FundingProduct> {
  allowedTermsMonthsText?: string
}
const blankForm = (): ProductForm => ({
  interestMethod: 'monthly', allowMethodChoice: false, allowedTermsMonths: [3, 6, 12], allowedTermsMonthsText: '3, 6, 12',
  allowCustomTerm: false, rateIsManual: false, status: 'Active', sortOrder: 0,
})

export function FundingProductsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<FundingProduct[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<FundingProduct | null>(null)
  const [form, setForm] = useState<ProductForm>(blankForm())

  const refresh = () => {
    setLoading(true)
    fundingProductsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [])

  const openCreate = () => { setEditing(null); setForm(blankForm()); setErr(''); setModal(true) }
  const openEdit = (p: FundingProduct) => {
    setEditing(p)
    setForm({ ...p, allowedTermsMonthsText: (p.allowedTermsMonths || []).join(', ') })
    setErr(''); setModal(true)
  }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const set = (patch: Partial<ProductForm>) => setForm((s) => ({ ...s, ...patch }))

  const save = async () => {
    if (!form.name || !form.code) { setErr('Name and code are required.'); return }
    const terms = (form.allowedTermsMonthsText || '').split(',').map((t) => parseInt(t.trim(), 10)).filter((n) => Number.isFinite(n) && n > 0)
    if (!terms.length) { setErr('Enter at least one term in months, e.g. "3, 6, 9, 12".'); return }
    if (!form.annualRatePercent || Number(form.annualRatePercent) <= 0) { setErr('Annual rate must be greater than zero.'); return }
    if (!form.minDepositAmount || Number(form.minDepositAmount) < 0) { setErr('Minimum deposit amount is required.'); return }

    setBusy(true); setErr('')
    try {
      const body: Partial<FundingProduct> = { ...form, allowedTermsMonths: terms }
      delete (body as any).allowedTermsMonthsText
      if (editing) await fundingProductsApi.update(editing.id, body)
      else await fundingProductsApi.create(body)
      close(); refresh()
      notify.success(editing ? 'Funding product updated' : 'Funding product created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (p: FundingProduct) => {
    const ok = await notify.confirm({ title: `Delete product "${p.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await fundingProductsApi.remove(p.id); refresh(); notify.success('Funding product deleted', p.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const methods: { value: FdInterestMethod; label: string }[] = [
    { value: 'monthly', label: 'Monthly Interest' },
    { value: 'maturity', label: 'Interest at Maturity' },
  ]

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Funding Products" icon="layers" sub={`${rows.length} products`}
        actions={<Can user={me} module="Funding Products" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Product</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Product' }, { label: 'Interest Method' }, { label: 'Annual Rate', align: 'right' }, { label: 'Terms' }, { label: 'Deposit Range', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          rows={rows}
          render={(p) => <>
            <Td c="var(--tx-0)"><div style={{ fontWeight: 600 }}>{p.name}</div><div className="t-2" style={{ fontSize: 11.5 }}>{p.code}</div></Td>
            <Td><Badge tone="blue">{p.interestMethod === 'monthly' ? 'Monthly Interest' : 'Interest at Maturity'}</Badge>{p.allowMethodChoice && <span className="t-2" style={{ fontSize: 11, marginLeft: 6 }}>(customer's choice)</span>}</Td>
            <Td align="right" mono>{Number(p.annualRatePercent).toFixed(2)}%</Td>
            <Td>{(p.allowedTermsMonths || []).join(', ')} mo{p.allowCustomTerm ? ' (+custom)' : ''}</Td>
            <Td align="right" mono>{Number(p.minDepositAmount).toLocaleString()} – {p.maxDepositAmount ? Number(p.maxDepositAmount).toLocaleString() : '∞'}</Td>
            <Td align="center"><Badge tone={(p.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{p.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Funding Products" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(p)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Funding Products" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(p)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={620} title={editing ? 'Edit Funding Product' : 'Add Funding Product'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create Product')}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Product Name" full><Input value={form.name || ''} onChange={(e) => set({ name: e.target.value })} /></Field>
          <Field label="Code"><Input value={form.code || ''} onChange={(e) => set({ code: e.target.value.toUpperCase() })} disabled={!!editing} /></Field>
          <Field label="Interest Calculation Method"><Select value={form.interestMethod || 'monthly'} onChange={(e) => set({ interestMethod: e.target.value as FdInterestMethod })}>
            {methods.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </Select></Field>
          <Field label="">
            <label className="row gap-2" style={{ fontSize: 12.5, marginTop: 22 }}>
              <input type="checkbox" checked={!!form.allowMethodChoice} onChange={(e) => set({ allowMethodChoice: e.target.checked })} /> Let the customer choose the interest method at FD creation
            </label>
          </Field>
          <Field label="Annual Rate (%)"><Input type="number" step="0.01" value={form.annualRatePercent ?? ''} onChange={(e) => set({ annualRatePercent: e.target.value })} /></Field>
          <Field label="Monthly Rate (%)">
            <Input type="number" step="0.001" value={form.rateIsManual ? (form.monthlyRatePercent ?? '') : (form.annualRatePercent ? (Number(form.annualRatePercent) / 12).toFixed(3) : '')}
              disabled={!form.rateIsManual} onChange={(e) => set({ monthlyRatePercent: e.target.value })} placeholder="Auto = Annual ÷ 12" />
          </Field>
          <Field label="">
            <label className="row gap-2" style={{ fontSize: 12.5, marginTop: 22 }}>
              <input type="checkbox" checked={!!form.rateIsManual} onChange={(e) => set({ rateIsManual: e.target.checked })} /> Configure monthly rate manually
            </label>
          </Field>
          <Field label="Available Terms (months, comma-separated)" full>
            <Input value={form.allowedTermsMonthsText ?? ''} onChange={(e) => set({ allowedTermsMonthsText: e.target.value })} placeholder="e.g. 3, 6, 9, 12" />
            <div className="row gap-1" style={{ marginTop: 6 }}>
              {STANDARD_TERMS.map((t) => (
                <Btn key={t} size="sm" variant="ghost" onClick={() => {
                  const cur = (form.allowedTermsMonthsText || '').split(',').map((x) => x.trim()).filter(Boolean)
                  if (!cur.includes(String(t))) set({ allowedTermsMonthsText: [...cur, String(t)].join(', ') })
                }}>+{t}mo</Btn>
              ))}
            </div>
          </Field>
          <Field label="">
            <label className="row gap-2" style={{ fontSize: 12.5, marginTop: 22 }}>
              <input type="checkbox" checked={!!form.allowCustomTerm} onChange={(e) => set({ allowCustomTerm: e.target.checked })} /> Allow custom terms at FD creation
            </label>
          </Field>
          <Field label="Minimum Deposit Amount"><Input type="number" step="0.01" value={form.minDepositAmount ?? ''} onChange={(e) => set({ minDepositAmount: e.target.value })} /></Field>
          <Field label="Maximum Deposit Amount (optional)"><Input type="number" step="0.01" value={form.maxDepositAmount ?? ''} onChange={(e) => set({ maxDepositAmount: e.target.value || null })} /></Field>
          <Field label="Reference Format (optional)"><Input value={form.referenceFormat || ''} onChange={(e) => set({ referenceFormat: e.target.value })} placeholder="{BRANCH}-{CODE}-{YEAR:2}-{MONTH:2}-{SEQ:6}" /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => set({ status: e.target.value as any })}><option>Active</option><option>Inactive</option></Select></Field>
        </div>
      </Modal>
    </div>
  )
}

export default FundingProductsScreen
