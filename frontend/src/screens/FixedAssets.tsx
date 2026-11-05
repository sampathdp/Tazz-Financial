/* Fixed Assets — register, monthly straight-line depreciation, disposals and categories.
   One screen / one "Fixed Assets" permission, tabbed, same shape as Petty Cash. */
import { useEffect, useMemo, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Modal, Table, Td, ErrorBanner, LoadingBlock } from '../components/ui'
import {
  branchesApi, cashAccountsApi, bankAccountsApi, vendorsApi, staffApi, chartOfAccountsApi,
  fixedAssetsApi, assetCategoriesApi,
  type Branch, type CashAccount, type BankAccount, type Vendor, type StaffOption, type ChartOfAccount,
  type FixedAsset, type FixedAssetBody, type FixedAssetStatus, type AssetCategory, type DepreciationRunResult, type FixedAssetDepreciationRow,
} from '../api'
import { Can, isAdminRole } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { ScreenProps } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)
const thisMonth = () => todayIso().slice(0, 7)
const statusTone = (s: FixedAssetStatus) => s === 'Active' ? 'green' : s === 'FullyDepreciated' ? 'amber' : 'neutral'
const statusLabel = (s: FixedAssetStatus) => s === 'FullyDepreciated' ? 'Fully Depreciated' : s

type Tab = 'register' | 'depreciation' | 'categories'

interface AssetForm {
  name: string; assetCategoryId: number | ''; branchId: number | ''; description: string; serialNo: string; location: string
  custodianStaffId: number | ''; vendorId: number | ''; invoiceNo: string; purchaseDate: string; inServiceDate: string
  cost: string; residualValue: string; usefulLifeMonths: string; acquisitionType: 'Purchase' | 'Opening'
  paymentMethod: 'Cash' | 'Bank' | 'Cheque'; cashAccountId: number | ''; bankAccountId: number | ''
  openingAccumulatedDepreciation: string; openingDepreciatedThrough: string
}
const blankAsset = (): AssetForm => ({
  name: '', assetCategoryId: '', branchId: '', description: '', serialNo: '', location: '', custodianStaffId: '', vendorId: '',
  invoiceNo: '', purchaseDate: todayIso(), inServiceDate: '', cost: '', residualValue: '', usefulLifeMonths: '',
  acquisitionType: 'Purchase', paymentMethod: 'Cash', cashAccountId: '', bankAccountId: '',
  openingAccumulatedDepreciation: '', openingDepreciatedThrough: '',
})

interface CategoryForm { name: string; assetAccountId: number | ''; accumulatedDepreciationAccountId: number | ''; expenseAccountId: number | ''; defaultUsefulLifeMonths: string; defaultResidualPercent: string; status: 'Active' | 'Inactive' }
const blankCategory = (): CategoryForm => ({ name: '', assetAccountId: '', accumulatedDepreciationAccountId: '', expenseAccountId: '', defaultUsefulLifeMonths: '60', defaultResidualPercent: '0', status: 'Active' })

interface DisposeForm { disposalDate: string; proceeds: string; paymentMethod: 'Cash' | 'Bank' | 'Cheque'; cashAccountId: number | ''; bankAccountId: number | ''; notes: string }
const blankDispose = (): DisposeForm => ({ disposalDate: todayIso(), proceeds: '0', paymentMethod: 'Cash', cashAccountId: '', bankAccountId: '', notes: '' })

export function FixedAssetsScreen({ user: me }: ScreenProps) {
  const notify = useNotify()
  const isAdmin = isAdminRole(me?.role)
  const [tab, setTab] = useState<Tab>('register')

  const [assets, setAssets] = useState<FixedAsset[]>([])
  const [categories, setCategories] = useState<AssetCategory[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [cashAccounts, setCashAccounts] = useState<CashAccount[]>([])
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])
  const [vendors, setVendors] = useState<Vendor[]>([])
  const [staff, setStaff] = useState<StaffOption[]>([])
  const [accounts, setAccounts] = useState<ChartOfAccount[]>([])
  const [loading, setLoading] = useState(true)

  const [statusFilter, setStatusFilter] = useState('')
  const [categoryFilter, setCategoryFilter] = useState<number | ''>('')
  const [search, setSearch] = useState('')

  const [assetModal, setAssetModal] = useState(false)
  const [form, setForm] = useState<AssetForm>(blankAsset())
  const [detail, setDetail] = useState<FixedAsset | null>(null)
  const [disposeTarget, setDisposeTarget] = useState<FixedAsset | null>(null)
  const [disposeForm, setDisposeForm] = useState<DisposeForm>(blankDispose())

  const [categoryModal, setCategoryModal] = useState(false)
  const [categoryEditing, setCategoryEditing] = useState<AssetCategory | null>(null)
  const [categoryForm, setCategoryForm] = useState<CategoryForm>(blankCategory())

  const [through, setThrough] = useState(thisMonth())
  const [preview, setPreview] = useState<DepreciationRunResult | null>(null)
  const [history, setHistory] = useState<FixedAssetDepreciationRow[]>([])

  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const loadAssets = () => {
    setLoading(true)
    fixedAssetsApi.list({ status: statusFilter || undefined, assetCategoryId: categoryFilter || undefined, search: search || undefined })
      .then(setAssets).catch(() => setAssets([])).finally(() => setLoading(false))
  }
  const loadCategories = () => assetCategoriesApi.list().then(setCategories).catch(() => setCategories([]))
  const loadHistory = () => fixedAssetsApi.depreciations().then(setHistory).catch(() => setHistory([]))

  useEffect(() => {
    loadCategories()
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    cashAccountsApi.list().then(setCashAccounts).catch(() => setCashAccounts([]))
    bankAccountsApi.list().then(setBankAccounts).catch(() => setBankAccounts([]))
    vendorsApi.list().then(setVendors).catch(() => setVendors([]))
    staffApi.options().then(setStaff).catch(() => setStaff([]))
    chartOfAccountsApi.list().then(setAccounts).catch(() => setAccounts([]))
  }, [])
  useEffect(loadAssets, [statusFilter, categoryFilter]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (tab === 'depreciation') loadHistory() }, [tab])

  const totals = useMemo(() => assets.filter((a) => a.status !== 'Disposed').reduce(
    (t, a) => ({ cost: t.cost + Number(a.cost), acc: t.acc + Number(a.accumulatedDepreciation), nbv: t.nbv + Number(a.netBookValue) }),
    { cost: 0, acc: 0, nbv: 0 }), [assets])

  const postable = (type: string) => accounts.filter((a) => a.accountType === type && a.isPostable !== false && a.status !== 'Inactive')

  /* ---------- add asset ---------- */
  const openAdd = () => { setForm(blankAsset()); setErr(''); setAssetModal(true) }
  const pickCategory = (id: number | '') => {
    const c = categories.find((x) => x.id === id)
    setForm((s) => ({
      ...s, assetCategoryId: id,
      usefulLifeMonths: c && !s.usefulLifeMonths ? String(c.defaultUsefulLifeMonths) : s.usefulLifeMonths,
    }))
  }
  const saveAsset = async () => {
    if (!form.name || !form.assetCategoryId || !form.branchId) { setErr('Name, category and branch are required.'); return }
    if (!form.cost || Number(form.cost) <= 0) { setErr('Cost must be greater than zero.'); return }
    const opening = form.acquisitionType === 'Opening'
    if (!opening && form.paymentMethod === 'Cash' && !form.cashAccountId) { setErr('Pick the till the purchase was paid from.'); return }
    if (!opening && form.paymentMethod !== 'Cash' && !form.bankAccountId) { setErr('Pick the bank account the purchase was paid from.'); return }
    if (opening && Number(form.openingAccumulatedDepreciation) > 0 && !form.openingDepreciatedThrough) { setErr('Enter the date up to which depreciation was already charged.'); return }
    const body: FixedAssetBody = {
      name: form.name, assetCategoryId: Number(form.assetCategoryId), branchId: Number(form.branchId),
      description: form.description || undefined, serialNo: form.serialNo || undefined, location: form.location || undefined,
      custodianStaffId: form.custodianStaffId ? Number(form.custodianStaffId) : undefined,
      vendorId: form.vendorId ? Number(form.vendorId) : undefined, invoiceNo: form.invoiceNo || undefined,
      purchaseDate: form.purchaseDate, inServiceDate: form.inServiceDate || undefined,
      cost: form.cost, residualValue: form.residualValue === '' ? undefined : form.residualValue,
      usefulLifeMonths: form.usefulLifeMonths || undefined, acquisitionType: form.acquisitionType,
      ...(opening
        ? { openingAccumulatedDepreciation: form.openingAccumulatedDepreciation || undefined, openingDepreciatedThrough: form.openingDepreciatedThrough || undefined }
        : { paymentMethod: form.paymentMethod, cashAccountId: form.cashAccountId ? Number(form.cashAccountId) : undefined, bankAccountId: form.bankAccountId ? Number(form.bankAccountId) : undefined }),
    }
    setBusy(true); setErr('')
    try {
      const created = await fixedAssetsApi.create(body)
      setAssetModal(false); loadAssets(); loadCategories()
      notify.success('Asset added', created.code)
    } catch (ex: any) { setErr(getErrorText(ex, 'Save failed')) } finally { setBusy(false) }
  }

  const openDetail = async (a: FixedAsset) => {
    try { setDetail(await fixedAssetsApi.get(a.id)) } catch (ex: any) { notify.error('Could not load asset', getErrorText(ex)) }
  }

  const removeAsset = async (a: FixedAsset) => {
    const ok = await notify.confirm({ title: `Delete ${a.code}?`, msg: 'Its purchase posting is reversed and the money returned to the account it was paid from.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await fixedAssetsApi.remove(a.id); loadAssets(); notify.success('Asset deleted', a.code) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  /* ---------- dispose ---------- */
  const openDispose = (a: FixedAsset) => { setDisposeTarget(a); setDisposeForm(blankDispose()); setErr('') }
  const proceeds = Number(disposeForm.proceeds || 0)
  const gainLoss = disposeTarget ? proceeds - Number(disposeTarget.netBookValue) : 0
  const saveDispose = async () => {
    if (!disposeTarget) return
    if (proceeds < 0) { setErr('Proceeds cannot be negative.'); return }
    if (proceeds > 0 && disposeForm.paymentMethod === 'Cash' && !disposeForm.cashAccountId) { setErr('Pick the till the proceeds went into.'); return }
    if (proceeds > 0 && disposeForm.paymentMethod !== 'Cash' && !disposeForm.bankAccountId) { setErr('Pick the bank account the proceeds went into.'); return }
    setBusy(true); setErr('')
    try {
      await fixedAssetsApi.dispose(disposeTarget.id, {
        disposalDate: disposeForm.disposalDate, proceeds: disposeForm.proceeds || 0, notes: disposeForm.notes || undefined,
        ...(proceeds > 0 ? {
          paymentMethod: disposeForm.paymentMethod,
          cashAccountId: disposeForm.cashAccountId ? Number(disposeForm.cashAccountId) : undefined,
          bankAccountId: disposeForm.bankAccountId ? Number(disposeForm.bankAccountId) : undefined,
        } : {}),
      })
      notify.success('Asset disposed', disposeTarget.code)
      setDisposeTarget(null); loadAssets()
    } catch (ex: any) { setErr(getErrorText(ex, 'Disposal failed')) } finally { setBusy(false) }
  }

  /* ---------- depreciation ---------- */
  const runPreview = async () => {
    setBusy(true); setErr('')
    try { setPreview(await fixedAssetsApi.depreciationPreview(through)) }
    catch (ex: any) { setErr(getErrorText(ex, 'Preview failed')); setPreview(null) } finally { setBusy(false) }
  }
  const runDepreciation = async () => {
    if (!preview || !preview.rows.length) return
    const ok = await notify.confirm({ title: `Post depreciation through ${through}?`, msg: `${preview.rows.length} entries totalling ${money(preview.total)} will be posted to the ledger.`, okText: 'Post' })
    if (!ok) return
    setBusy(true); setErr('')
    try {
      const res = await fixedAssetsApi.depreciationRun(through)
      if (res.errors.length) notify.error('Some assets were skipped', res.errors.slice(0, 3).join(' | '))
      else notify.success('Depreciation posted', money(res.total))
      setPreview(null); loadAssets(); loadHistory()
    } catch (ex: any) { setErr(getErrorText(ex, 'Run failed')) } finally { setBusy(false) }
  }

  /* ---------- categories ---------- */
  const openCategory = (c?: AssetCategory) => {
    setCategoryEditing(c ?? null); setErr('')
    setCategoryForm(c ? {
      name: c.name, assetAccountId: c.assetAccountId, accumulatedDepreciationAccountId: c.accumulatedDepreciationAccountId, expenseAccountId: c.expenseAccountId,
      defaultUsefulLifeMonths: String(c.defaultUsefulLifeMonths), defaultResidualPercent: String(c.defaultResidualPercent), status: c.status,
    } : blankCategory())
    setCategoryModal(true)
  }
  const saveCategory = async () => {
    const f = categoryForm
    if (!f.name || !f.assetAccountId || !f.accumulatedDepreciationAccountId || !f.expenseAccountId) { setErr('Name and all three ledger accounts are required.'); return }
    const body = {
      name: f.name, assetAccountId: Number(f.assetAccountId), accumulatedDepreciationAccountId: Number(f.accumulatedDepreciationAccountId),
      expenseAccountId: Number(f.expenseAccountId), defaultUsefulLifeMonths: Number(f.defaultUsefulLifeMonths), defaultResidualPercent: f.defaultResidualPercent, status: f.status,
    }
    setBusy(true); setErr('')
    try {
      if (categoryEditing) await assetCategoriesApi.update(categoryEditing.id, body as any)
      else await assetCategoriesApi.create(body as any)
      setCategoryModal(false); loadCategories(); notify.success(categoryEditing ? 'Category updated' : 'Category created', f.name)
    } catch (ex: any) { setErr(getErrorText(ex, 'Save failed')) } finally { setBusy(false) }
  }

  const accountOptions = (type: string) => postable(type).map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)
  const grid2 = { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 } as const

  return (
    <div>
      <PageHead crumbs="Expense Management" title="Fixed Assets" icon="building"
        sub={`${assets.length} assets · Cost ${money(totals.cost)} · Net book value ${money(totals.nbv)}`}
        actions={<Can user={me} module="Fixed Assets" action="Create"><Btn variant="primary" icon="plus" onClick={openAdd}>Add Asset</Btn></Can>} />

      <div className="row gap-2" style={{ marginBottom: 16 }}>
        <Btn variant={tab === 'register' ? 'solid' : 'plain'} onClick={() => setTab('register')}>Register</Btn>
        <Btn variant={tab === 'depreciation' ? 'solid' : 'plain'} onClick={() => setTab('depreciation')}>Depreciation</Btn>
        <Btn variant={tab === 'categories' ? 'solid' : 'plain'} onClick={() => setTab('categories')}>Categories</Btn>
      </div>

      {tab === 'register' && <>
        <Card style={{ marginBottom: 16 }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16 }}>
            <Field label="Search"><Input placeholder="Code, name or serial no" value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && loadAssets()} /></Field>
            <Field label="Status"><Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
              <option value="">All</option><option value="Active">Active</option><option value="FullyDepreciated">Fully Depreciated</option><option value="Disposed">Disposed</option>
            </Select></Field>
            <Field label="Category"><Select value={categoryFilter} onChange={(e) => setCategoryFilter(Number(e.target.value) || '')}>
              <option value="">All</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select></Field>
          </div>
        </Card>
        <Card pad={0}>
          {loading ? <LoadingBlock /> :
          <Table
            cols={[{ label: 'Code' }, { label: 'Asset' }, { label: 'Category' }, { label: 'Branch' }, { label: 'Purchased' }, { label: 'Cost', align: 'right' }, { label: 'Acc. Depreciation', align: 'right' }, { label: 'Net Book Value', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 190 }]}
            empty="No fixed assets yet" rows={assets}
            render={(a) => <>
              <Td mono>{a.code}</Td>
              <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{a.name}</Td>
              <Td>{a.category?.name || '—'}</Td>
              <Td>{a.branch?.name || '—'}</Td>
              <Td>{dateOnly(a.purchaseDate)}</Td>
              <Td align="right" mono>{money(a.cost)}</Td>
              <Td align="right" mono>{money(a.accumulatedDepreciation)}</Td>
              <Td align="right" mono>{money(a.netBookValue)}</Td>
              <Td align="center"><Badge tone={statusTone(a.status)} dot>{statusLabel(a.status)}</Badge></Td>
              <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                <Btn variant="ghost" size="sm" onClick={() => openDetail(a)}>View</Btn>
                {a.status !== 'Disposed' && <Can user={me} module="Fixed Assets" action="Edit"><Btn variant="ghost" size="sm" onClick={() => openDispose(a)}>Dispose</Btn></Can>}
                {isAdmin && a.status === 'Active' && Number(a.accumulatedDepreciation) === 0 && a.acquisitionType === 'Purchase' &&
                  <Btn variant="ghost" size="sm" onClick={() => removeAsset(a)}>Delete</Btn>}
              </div></Td>
            </>} />}
        </Card>
      </>}

      {tab === 'depreciation' && <>
        <Card style={{ marginBottom: 16 }}>
          <ErrorBanner error={err} />
          <div className="row gap-3 wrap" style={{ alignItems: 'flex-end' }}>
            <Field label="Depreciate through"><Input type="month" value={through} max={thisMonth()} onChange={(e) => { setThrough(e.target.value); setPreview(null) }} /></Field>
            <Btn variant="plain" icon="eye" onClick={runPreview} disabled={busy || !through}>Preview</Btn>
            <Can user={me} module="Fixed Assets" action="Edit">
              <Btn variant="primary" icon="check" onClick={runDepreciation} disabled={busy || !preview || !preview.rows.length}>Post Depreciation</Btn>
            </Can>
          </div>
          <div className="t-2" style={{ fontSize: 12, marginTop: 10 }}>
            Straight-line, a full month from the month an asset enters service. Every month up to the one chosen is caught up; months already posted are skipped.
          </div>
        </Card>

        {preview && <Card pad={0} style={{ marginBottom: 16 }}>
          <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)', fontWeight: 700, fontSize: 13.5 }}>Preview — {preview.rows.length} entries, total {money(preview.total)}</div>
          <Table
            cols={[{ label: 'Asset' }, { label: 'Period' }, { label: 'Charge', align: 'right' }, { label: 'Acc. After', align: 'right' }, { label: 'NBV After', align: 'right' }]}
            empty="Nothing to depreciate for this month" rows={preview.rows}
            render={(r) => <>
              <Td><span className="mono">{r.code}</span> · {r.name}</Td>
              <Td>{r.period}</Td>
              <Td align="right" mono>{money(r.amount)}</Td>
              <Td align="right" mono>{money(r.accumulatedAfter)}</Td>
              <Td align="right" mono>{money(r.netBookValueAfter)}</Td>
            </>} />
        </Card>}

        <Card pad={0}>
          <div style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)', fontWeight: 700, fontSize: 13.5 }}>Posted depreciation</div>
          <Table
            cols={[{ label: 'Period' }, { label: 'Asset' }, { label: 'Period End' }, { label: 'Amount', align: 'right' }]}
            empty="No depreciation posted yet" rows={history}
            render={(h) => <>
              <Td>{h.period}</Td>
              <Td><span className="mono">{h.asset?.code}</span> · {h.asset?.name}</Td>
              <Td>{dateOnly(h.periodEnd)}</Td>
              <Td align="right" mono>{money(h.amount)}</Td>
            </>} />
        </Card>
      </>}

      {tab === 'categories' && (
        <Card pad={0}>
          <div className="row between" style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)' }}>
            <span style={{ fontWeight: 700, fontSize: 13.5 }}>Asset categories</span>
            <Can user={me} module="Fixed Assets" action="Create"><Btn variant="primary" size="sm" icon="plus" onClick={() => openCategory()}>Add Category</Btn></Can>
          </div>
          <Table
            cols={[{ label: 'Name' }, { label: 'Cost Account' }, { label: 'Expense Account' }, { label: 'Life (months)', align: 'right' }, { label: 'Residual %', align: 'right' }, { label: 'Assets', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 80 }]}
            empty="No categories yet" rows={categories}
            render={(c) => <>
              <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{c.name}</Td>
              <Td>{c.assetAccount ? `${c.assetAccount.code} — ${c.assetAccount.name}` : '—'}</Td>
              <Td>{c.expenseAccount ? `${c.expenseAccount.code} — ${c.expenseAccount.name}` : '—'}</Td>
              <Td align="right" mono>{c.defaultUsefulLifeMonths}</Td>
              <Td align="right" mono>{money(c.defaultResidualPercent)}</Td>
              <Td align="right" mono>{c.assetsCount ?? 0}</Td>
              <Td align="center"><Badge tone={c.status === 'Active' ? 'green' : 'red'} dot>{c.status}</Badge></Td>
              <Td align="right"><Can user={me} module="Fixed Assets" action="Edit"><Btn variant="ghost" size="sm" onClick={() => openCategory(c)}>Edit</Btn></Can></Td>
            </>} />
        </Card>
      )}

      {/* ---------- Add asset ---------- */}
      <Modal open={assetModal} onClose={() => setAssetModal(false)} width={720} title="Add Fixed Asset"
        footer={<>
          <Btn variant="plain" onClick={() => setAssetModal(false)}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveAsset} disabled={busy}>{busy ? 'Saving...' : 'Add Asset'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={grid2}>
          <Field label="Asset Name" full><Input value={form.name} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Category"><Select value={form.assetCategoryId} onChange={(e) => pickCategory(Number(e.target.value) || '')}>
            <option value="">Select category…</option>
            {categories.filter((c) => c.status === 'Active').map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Select></Field>
          <Field label="Branch"><Select value={form.branchId} onChange={(e) => setForm((s) => ({ ...s, branchId: Number(e.target.value) || '' }))}>
            <option value="">Select branch…</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Acquisition"><Select value={form.acquisitionType} onChange={(e) => setForm((s) => ({ ...s, acquisitionType: e.target.value as any }))}>
            <option value="Purchase">New purchase (paid now)</option>
            <option value="Opening">Already owned (opening balance)</option>
          </Select></Field>
          <Field label="Purchase Date"><DateInput value={form.purchaseDate} onChange={(iso) => setForm((s) => ({ ...s, purchaseDate: iso }))} /></Field>
          <Field label="In Service Date (blank = purchase date)"><DateInput value={form.inServiceDate} onChange={(iso) => setForm((s) => ({ ...s, inServiceDate: iso }))} /></Field>
          <Field label="Cost"><Input type="number" step="0.01" value={form.cost} onChange={(e) => setForm((s) => ({ ...s, cost: e.target.value }))} /></Field>
          <Field label="Residual Value (blank = category %)"><Input type="number" step="0.01" value={form.residualValue} onChange={(e) => setForm((s) => ({ ...s, residualValue: e.target.value }))} /></Field>
          <Field label="Useful Life (months)"><Input type="number" value={form.usefulLifeMonths} onChange={(e) => setForm((s) => ({ ...s, usefulLifeMonths: e.target.value }))} /></Field>
          <Field label="Serial No"><Input value={form.serialNo} onChange={(e) => setForm((s) => ({ ...s, serialNo: e.target.value }))} /></Field>
          <Field label="Location"><Input value={form.location} onChange={(e) => setForm((s) => ({ ...s, location: e.target.value }))} /></Field>
          <Field label="Custodian"><Select value={form.custodianStaffId} onChange={(e) => setForm((s) => ({ ...s, custodianStaffId: Number(e.target.value) || '' }))}>
            <option value="">None</option>
            {staff.map((p) => <option key={p.id} value={p.id}>{p.staffCode} · {p.name}</option>)}
          </Select></Field>
          <Field label="Vendor"><Select value={form.vendorId} onChange={(e) => setForm((s) => ({ ...s, vendorId: Number(e.target.value) || '' }))}>
            <option value="">None</option>
            {vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
          </Select></Field>
          <Field label="Invoice No"><Input value={form.invoiceNo} onChange={(e) => setForm((s) => ({ ...s, invoiceNo: e.target.value }))} /></Field>
          <Field label="Description" full><Input value={form.description} onChange={(e) => setForm((s) => ({ ...s, description: e.target.value }))} /></Field>

          {form.acquisitionType === 'Purchase' ? <>
            <Field label="Paid By"><Select value={form.paymentMethod} onChange={(e) => setForm((s) => ({ ...s, paymentMethod: e.target.value as any }))}>
              <option value="Cash">Cash</option><option value="Bank">Bank</option><option value="Cheque">Cheque</option>
            </Select></Field>
            {form.paymentMethod === 'Cash' ? (
              <Field label="Cash Till"><Select value={form.cashAccountId} onChange={(e) => setForm((s) => ({ ...s, cashAccountId: Number(e.target.value) || '' }))}>
                <option value="">Select till…</option>
                {cashAccounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </Select></Field>
            ) : (
              <Field label="Bank Account"><Select value={form.bankAccountId} onChange={(e) => setForm((s) => ({ ...s, bankAccountId: Number(e.target.value) || '' }))}>
                <option value="">Select account…</option>
                {bankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.bank?.name || ''}</option>)}
              </Select></Field>
            )}
          </> : <>
            <Field label="Depreciation Already Charged"><Input type="number" step="0.01" value={form.openingAccumulatedDepreciation} onChange={(e) => setForm((s) => ({ ...s, openingAccumulatedDepreciation: e.target.value }))} /></Field>
            <Field label="Charged Up To (month end)"><DateInput value={form.openingDepreciatedThrough} onChange={(iso) => setForm((s) => ({ ...s, openingDepreciatedThrough: iso }))} /></Field>
            <Field label="" full><div className="t-2" style={{ fontSize: 12 }}>No money moves. Cost is booked against the category account, depreciation already charged against Accumulated Depreciation, and the balance against Opening Balance Equity.</div></Field>
          </>}
        </div>
      </Modal>

      {/* ---------- Asset detail ---------- */}
      <Modal open={!!detail} onClose={() => setDetail(null)} width={680} title={detail ? `${detail.code} — ${detail.name}` : ''}
        sub={detail ? `${detail.category?.name || ''} · ${detail.branch?.name || ''}` : undefined}
        footer={<Btn variant="plain" onClick={() => setDetail(null)}>Close</Btn>}>
        {detail && <>
          <div style={{ ...grid2, marginBottom: 16 }}>
            <Field label="Cost"><div className="mono">{money(detail.cost)}</div></Field>
            <Field label="Residual Value"><div className="mono">{money(detail.residualValue)}</div></Field>
            <Field label="Accumulated Depreciation"><div className="mono">{money(detail.accumulatedDepreciation)}</div></Field>
            <Field label="Net Book Value"><div className="mono">{money(detail.netBookValue)}</div></Field>
            <Field label="Useful Life"><div>{detail.usefulLifeMonths} months</div></Field>
            <Field label="Depreciated Through"><div>{dateOnly(detail.depreciatedThrough)}</div></Field>
            <Field label="Purchased"><div>{dateOnly(detail.purchaseDate)}{detail.invoiceNo ? ` · ${detail.invoiceNo}` : ''}</div></Field>
            <Field label="In Service"><div>{dateOnly(detail.inServiceDate)}</div></Field>
            <Field label="Serial No"><div>{detail.serialNo || '—'}</div></Field>
            <Field label="Custodian"><div>{detail.custodian ? `${detail.custodian.name} (${detail.custodian.staffCode})` : '—'}</div></Field>
            {detail.disposal && <Field label="Disposal" full>
              <div>{dateOnly(detail.disposal.disposalDate)} · proceeds {money(detail.disposal.proceeds)} · {Number(detail.disposal.gainLoss) >= 0 ? 'gain' : 'loss'} {money(Math.abs(Number(detail.disposal.gainLoss)))}</div>
            </Field>}
          </div>
          <Table
            cols={[{ label: 'Period' }, { label: 'Period End' }, { label: 'Depreciation', align: 'right' }]}
            empty="No depreciation posted yet" rows={detail.depreciations || []}
            render={(d) => <><Td>{d.period}</Td><Td>{dateOnly(d.periodEnd)}</Td><Td align="right" mono>{money(d.amount)}</Td></>} />
        </>}
      </Modal>

      {/* ---------- Dispose ---------- */}
      <Modal open={!!disposeTarget} onClose={() => setDisposeTarget(null)} width={560}
        title="Dispose Asset" sub={disposeTarget ? `${disposeTarget.code} — ${disposeTarget.name} · Net book value ${money(disposeTarget.netBookValue)}` : undefined}
        footer={<>
          <Btn variant="plain" onClick={() => setDisposeTarget(null)}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveDispose} disabled={busy}>{busy ? 'Saving...' : 'Dispose'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={grid2}>
          <Field label="Disposal Date"><DateInput value={disposeForm.disposalDate} onChange={(iso) => setDisposeForm((s) => ({ ...s, disposalDate: iso }))} /></Field>
          <Field label="Sale Proceeds (0 if scrapped)"><Input type="number" step="0.01" min="0" value={disposeForm.proceeds} onChange={(e) => setDisposeForm((s) => ({ ...s, proceeds: e.target.value }))} /></Field>
          {proceeds > 0 && <>
            <Field label="Received Into"><Select value={disposeForm.paymentMethod} onChange={(e) => setDisposeForm((s) => ({ ...s, paymentMethod: e.target.value as any }))}>
              <option value="Cash">Cash</option><option value="Bank">Bank</option><option value="Cheque">Cheque</option>
            </Select></Field>
            {disposeForm.paymentMethod === 'Cash' ? (
              <Field label="Cash Till"><Select value={disposeForm.cashAccountId} onChange={(e) => setDisposeForm((s) => ({ ...s, cashAccountId: Number(e.target.value) || '' }))}>
                <option value="">Select till…</option>
                {cashAccounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </Select></Field>
            ) : (
              <Field label="Bank Account"><Select value={disposeForm.bankAccountId} onChange={(e) => setDisposeForm((s) => ({ ...s, bankAccountId: Number(e.target.value) || '' }))}>
                <option value="">Select account…</option>
                {bankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.bank?.name || ''}</option>)}
              </Select></Field>
            )}
          </>}
          <Field label="Notes" full><Input value={disposeForm.notes} onChange={(e) => setDisposeForm((s) => ({ ...s, notes: e.target.value }))} /></Field>
          <Field label="" full>
            <div style={{ fontWeight: 600, color: gainLoss >= 0 ? 'var(--ok)' : 'var(--bad)' }}>
              {gainLoss >= 0 ? 'Gain' : 'Loss'} on disposal: {money(Math.abs(gainLoss))}
            </div>
          </Field>
        </div>
      </Modal>

      {/* ---------- Category ---------- */}
      <Modal open={categoryModal} onClose={() => setCategoryModal(false)} width={600} title={categoryEditing ? 'Edit Category' : 'Add Category'}
        footer={<>
          <Btn variant="plain" onClick={() => setCategoryModal(false)}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveCategory} disabled={busy}>{busy ? 'Saving...' : 'Save'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={grid2}>
          <Field label="Name" full><Input value={categoryForm.name} onChange={(e) => setCategoryForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Cost Account"><Select value={categoryForm.assetAccountId} disabled={!!categoryEditing?.assetsCount} onChange={(e) => setCategoryForm((s) => ({ ...s, assetAccountId: Number(e.target.value) || '' }))}>
            <option value="">Select…</option>{accountOptions('Asset')}
          </Select></Field>
          <Field label="Accumulated Depreciation Account"><Select value={categoryForm.accumulatedDepreciationAccountId} disabled={!!categoryEditing?.assetsCount} onChange={(e) => setCategoryForm((s) => ({ ...s, accumulatedDepreciationAccountId: Number(e.target.value) || '' }))}>
            <option value="">Select…</option>{accountOptions('Asset')}
          </Select></Field>
          <Field label="Depreciation Expense Account"><Select value={categoryForm.expenseAccountId} disabled={!!categoryEditing?.assetsCount} onChange={(e) => setCategoryForm((s) => ({ ...s, expenseAccountId: Number(e.target.value) || '' }))}>
            <option value="">Select…</option>{accountOptions('Expense')}
          </Select></Field>
          <Field label="Status"><Select value={categoryForm.status} onChange={(e) => setCategoryForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
          <Field label="Default Life (months)"><Input type="number" value={categoryForm.defaultUsefulLifeMonths} onChange={(e) => setCategoryForm((s) => ({ ...s, defaultUsefulLifeMonths: e.target.value }))} /></Field>
          <Field label="Default Residual %"><Input type="number" step="0.01" value={categoryForm.defaultResidualPercent} onChange={(e) => setCategoryForm((s) => ({ ...s, defaultResidualPercent: e.target.value }))} /></Field>
          {!!categoryEditing?.assetsCount && <Field label="" full><div className="t-2" style={{ fontSize: 12 }}>Ledger accounts are locked because this category already holds assets.</div></Field>}
        </div>
      </Modal>
    </div>
  )
}
