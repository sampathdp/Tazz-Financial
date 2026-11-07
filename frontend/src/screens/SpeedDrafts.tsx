/* Tazz Investment & Credit — Speed Drafts (Loans / Mortgages / Gold Pawning / Leases,
   interest-only repayment). One screen covers the full lifecycle — intake, approve/
   reject, disburse, and ongoing view — status filter chips replace separate
   Applications/Active screens since the volume here doesn't need them split out yet. */
import { useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, Table, Td, Pager, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  speedDraftsApi, speedDraftTypesApi, customersApi,
  type SpeedDraft, type SpeedDraftStatus, type SpeedDraftType, type SpeedDraftCollateralInput, type FacilityInsuranceDetail, type Customer, type AuthUser,
} from '../api'
import { SpeedDraftDetail } from './speedDraft/SpeedDraftDetail'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const STATUSES: SpeedDraftStatus[] = ['Pending', 'Approved', 'Active', 'Closed', 'Rejected']
const statusTone: Record<SpeedDraftStatus, 'green' | 'red' | 'amber' | 'blue' | 'neutral'> = {
  Pending: 'amber', Approved: 'blue', Active: 'green', Closed: 'neutral', Rejected: 'red',
}
const CATEGORY_LABEL: Record<string, string> = { Loan: 'Loan', Mortgage: 'Mortgage', GoldPawning: 'Gold Pawning', Lease: 'Lease' }
const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const todayIso = () => new Date().toISOString().slice(0, 10)

interface FormState {
  customerId?: number
  speedDraftTypeId?: number
  principalAmount?: number | string
  interestRatePercent?: number | string
  ratePeriod?: 'monthly' | 'annual' | 'daily'
  interestMethod?: 'reducing_balance' | 'flat'
  documentChargeAmount?: number | string
  termMonths?: number
  vehicleNo?: string
  insurance?: Partial<FacilityInsuranceDetail>
  collateral: SpeedDraftCollateralInput[]
}
const blankForm = (): FormState => ({ ratePeriod: 'monthly', interestMethod: 'reducing_balance', collateral: [] })
const blankInsurance = (): Partial<FacilityInsuranceDetail> => ({})
const ROUTE_BASE = '/sd/drafts'

export function SpeedDraftsScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const navigate = useNavigate()
  const params = useParams<{ id?: string }>()
  const location = useLocation()
  const [rows, setRows] = useState<SpeedDraft[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [counts, setCounts] = useState<Record<string, number>>({ All: 0 })
  const [types, setTypes] = useState<SpeedDraftType[]>([])
  const [customers, setCustomers] = useState<Customer[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<SpeedDraftStatus | 'All'>('All')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const [mode, setMode] = useState<'list' | 'form' | 'detail'>('list')
  const [editing, setEditing] = useState<SpeedDraft | null>(null)
  const [detail, setDetail] = useState<SpeedDraft | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [form, setForm] = useState<FormState>(blankForm())

  const refresh = () => {
    setLoading(true)
    speedDraftsApi.browse({ status: statusFilter === 'All' ? undefined : statusFilter, search: debouncedSearch || undefined, page })
      .then((r) => { setRows(r.data); setLastPage(r.lastPage); setTotal(r.total) })
      .catch(() => { setRows([]); setLastPage(1); setTotal(0) })
      .finally(() => setLoading(false))
  }
  const refreshCounts = () => { speedDraftsApi.statusCounts().then(setCounts).catch(() => {}) }

  useEffect(() => {
    speedDraftTypesApi.list().then((all) => setTypes(all.filter((t) => (t.status || 'Active') === 'Active'))).catch(() => setTypes([]))
    customersApi.list().then(setCustomers).catch(() => setCustomers([]))
  }, [])

  // Search text is debounced so a keystroke doesn't fire a request per
  // character; status/page changes refresh immediately.
  const debounceRef = useRef<ReturnType<typeof setTimeout>>()
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current) }
  }, [search])

  // A status/search change always jumps back to page 1.
  useEffect(() => { setPage(1) }, [statusFilter, debouncedSearch])

  useEffect(() => {
    if (mode !== 'list') return
    refresh()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter, debouncedSearch, page, mode])

  const selectedType = types.find((t) => t.id === form.speedDraftTypeId)
  const needsInsurance = selectedType?.category === 'Lease' || selectedType?.category === 'Mortgage'

  // Which draft/mode is open lives in the URL — see the effect below — so a
  // hard refresh on a form/detail URL reopens the same screen instead of
  // bouncing back to the list.
  const openCreate = () => navigate(`${ROUTE_BASE}/new`)
  const openRow = (row: SpeedDraft) => navigate(`${ROUTE_BASE}/${row.id}`)
  const back = () => navigate(ROUTE_BASE)
  // Cancelling/saving an edit returns to the record's approval page rather than
  // all the way back to the list, since that's where the Edit action was opened from.
  const backFromForm = () => navigate(editing ? `${ROUTE_BASE}/${editing.id}` : ROUTE_BASE)
  const reloadDetail = async () => { if (detail) setDetail(await speedDraftsApi.get(detail.id)) }

  const isEdit = !!params.id && location.pathname === `${ROUTE_BASE}/${params.id}/edit`

  const loadCreate = () => { setEditing(null); setForm(blankForm()); setErr(''); setMode('form') }
  const loadRow = async (id: number) => {
    try {
      const full = await speedDraftsApi.get(id)
      if (isEdit) {
        if (full.status !== 'Pending') { notify.error('Only a Pending Speed Draft can be edited'); navigate(`${ROUTE_BASE}/${id}`, { replace: true }); return }
        setEditing(full)
        setForm({
          customerId: full.customerId, speedDraftTypeId: full.speedDraftDetail?.speedDraftTypeId,
          principalAmount: full.principalAmount, interestRatePercent: full.interestRatePercent,
          ratePeriod: full.ratePeriod || 'monthly', termMonths: full.termMonths,
          interestMethod: full.speedDraftDetail?.interestMethod || 'reducing_balance',
          documentChargeAmount: full.speedDraftDetail?.documentChargeAmount ?? '',
          vehicleNo: full.speedDraftDetail?.vehicleNo || '', insurance: full.insurance || blankInsurance(),
          collateral: full.collateral || [],
        })
        setErr(''); setMode('form')
      } else {
        setDetail(full); setMode('detail')
      }
    } catch {
      notify.error('Speed Draft not found')
      navigate(ROUTE_BASE, { replace: true })
    }
  }

  useEffect(() => {
    if (location.pathname === `${ROUTE_BASE}/new`) { loadCreate(); return }
    if (params.id) { setDetailLoading(true); loadRow(Number(params.id)).finally(() => setDetailLoading(false)); return }
    setMode('list'); setEditing(null); setDetail(null); refreshCounts()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname, params.id])

  const pickType = (typeId: number) => {
    const t = types.find((x) => x.id === typeId)
    setForm((s) => ({
      ...s, speedDraftTypeId: typeId,
      interestRatePercent: s.interestRatePercent ?? t?.interestRatePercent,
      ratePeriod: t?.ratePeriod || 'monthly',
      termMonths: s.termMonths ?? t?.minPeriodMonths ?? undefined,
      collateral: t?.requiresCollateral && s.collateral.length === 0 ? [{ collateralType: 'Jewelry', description: '' }] : s.collateral,
    }))
  }

  const addCollateral = () => setForm((s) => ({ ...s, collateral: [...s.collateral, { collateralType: 'Jewelry', description: '' }] }))
  const setCollateral = (i: number, patch: Partial<SpeedDraftCollateralInput>) =>
    setForm((s) => ({ ...s, collateral: s.collateral.map((c, ci) => (ci === i ? { ...c, ...patch } : c)) }))
  const removeCollateral = (i: number) => setForm((s) => ({ ...s, collateral: s.collateral.filter((_, ci) => ci !== i) }))

  const save = async () => {
    if (!form.customerId) { setErr('Select a customer.'); return }
    if (!form.speedDraftTypeId) { setErr('Select a Speed Draft Type.'); return }
    if (!form.principalAmount || Number(form.principalAmount) <= 0) { setErr('Enter the capital amount.'); return }
    if (!form.termMonths || form.termMonths <= 0) { setErr('Enter the period in months.'); return }
    if (selectedType?.category === 'Lease' && !form.vehicleNo) { setErr('Vehicle No is required for a Lease draft.'); return }
    if (needsInsurance && (!form.insurance?.insuranceCompany || !form.insurance?.policyNo || !form.insurance?.amount || !form.insurance?.contactPerson || !form.insurance?.coverage)) {
      setErr('Insurance details are required for a Lease or Mortgage draft.'); return
    }
    const customer = customers.find((c) => c.id === form.customerId)
    const body = {
      customerId: form.customerId,
      speedDraftTypeId: form.speedDraftTypeId,
      branchId: customer?.branchId,
      principalAmount: form.principalAmount,
      interestRatePercent: form.interestRatePercent,
      ratePeriod: form.ratePeriod,
      interestMethod: form.interestMethod || 'reducing_balance',
      documentChargeAmount: form.documentChargeAmount === '' || form.documentChargeAmount == null ? null : form.documentChargeAmount,
      termMonths: form.termMonths,
      vehicleNo: selectedType?.category === 'Lease' ? (form.vehicleNo || null) : null,
      insurance: needsInsurance ? form.insurance : undefined,
      collateral: form.collateral.filter((c) => c.description),
    }
    setBusy(true); setErr('')
    try {
      if (editing) await speedDraftsApi.update(editing.id, body as any)
      else await speedDraftsApi.create(body as any)
      backFromForm()
      notify.success(editing ? 'Speed Draft updated' : 'Speed Draft application submitted', customer?.fullName)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  if (mode === 'detail') {
    return (
      <div>
        <PageHead crumbs="Speed Drafts" title={detailLoading || !detail ? 'Loading…' : `Speed Draft ${detail.code}`} icon="coins"
          sub={detail ? `${detail.customer?.fullName} — ${detail.speedDraftDetail?.speedDraftType?.name}` : undefined}
          actions={<Btn variant="solid" onClick={back}>← Back to Speed Drafts</Btn>} />
        {detailLoading || !detail ? <LoadingBlock />
          : <SpeedDraftDetail speedDraft={detail} me={me} onChanged={reloadDetail} go={go} onWithdrawn={back} />}
      </div>
    )
  }

  if (mode === 'form') {
    return (
      <div>
        <PageHead crumbs="Speed Drafts" title={editing ? `Edit ${editing.code}` : 'New Speed Draft'} icon="coins"
          actions={<Btn variant="solid" onClick={backFromForm}>{editing ? `← Back to ${editing.code}` : '← Back to Speed Drafts'}</Btn>} />
        <Card style={{ maxWidth: 720 }}>
          <ErrorBanner error={err} />
          <div className="col gap-4">
            <Field label="Customer">
              <SearchSelect
                value={form.customerId ? `${customers.find((c) => c.id === form.customerId)?.fullName || ''}` : ''}
                onChange={(_n, opt) => setForm((s) => ({ ...s, customerId: opt?.id }))}
                options={customers}
                getLabel={(c: Customer) => c.fullName}
                getSub={(c: Customer) => [c.code, c.nicNo, c.phone].filter(Boolean).join(' · ')}
                placeholder="Search by name, code, NIC…"
              />
            </Field>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <Field label="Speed Draft Type"><Select value={form.speedDraftTypeId || ''} onChange={(e) => pickType(Number(e.target.value))}>
                <option value="">Select type…</option>
                {types.map((t) => <option key={t.id} value={t.id}>{t.name} ({CATEGORY_LABEL[t.category] || t.category})</option>)}
              </Select></Field>
              <Field label="Capital Amount"><Input type="number" value={form.principalAmount ?? ''} onChange={(e) => setForm((s) => ({ ...s, principalAmount: e.target.value }))}
                placeholder={selectedType?.minAmount != null ? `${money(selectedType.minAmount)} – ${money(selectedType.maxAmount)}` : undefined} /></Field>
              <Field label="Interest Rate %"><Input type="number" step="0.001" value={form.interestRatePercent ?? ''} onChange={(e) => setForm((s) => ({ ...s, interestRatePercent: e.target.value }))} /></Field>
              <Field label="Rate Period"><Select value={form.ratePeriod || 'monthly'} onChange={(e) => setForm((s) => ({ ...s, ratePeriod: e.target.value as any }))}>
                <option value="monthly">Monthly</option><option value="annual">Annual</option><option value="daily">Daily</option>
              </Select></Field>
              <Field label="Interest Method"><Select value={form.interestMethod || 'reducing_balance'} onChange={(e) => setForm((s) => ({ ...s, interestMethod: e.target.value as 'reducing_balance' | 'flat' }))}>
                <option value="reducing_balance">Reducing balance</option><option value="flat">Flat</option>
              </Select></Field>
              <Field label="Document Charge"><Input type="number" min="0" value={form.documentChargeAmount ?? ''} onChange={(e) => setForm((s) => ({ ...s, documentChargeAmount: e.target.value }))}
                placeholder="Optional - must be paid before disbursement" /></Field>
              <Field label="Period (Months)"><Input type="number" value={form.termMonths ?? ''} onChange={(e) => setForm((s) => ({ ...s, termMonths: Number(e.target.value) }))}
                placeholder={selectedType ? `${selectedType.minPeriodMonths ?? 1}–${selectedType.maxPeriodMonths ?? ''}` : undefined} /></Field>
              {selectedType?.category === 'Lease' && (
                <Field label="Vehicle No"><Input value={form.vehicleNo || ''} onChange={(e) => setForm((s) => ({ ...s, vehicleNo: e.target.value }))} placeholder="e.g. ABC-1234" /></Field>
              )}
            </div>
            <div className="t-2" style={{ fontSize: 11.5 }}>
              Interest is due monthly and the full capital falls due at maturity (capital can also be reduced early via a capital payment).
              {' '}<b>Reducing balance</b>: interest is charged on the capital still outstanding, so it falls after a capital payment.
              {' '}<b>Flat</b>: interest is always charged on the original capital. Both give the same figures until capital is paid early.
            </div>

            {needsInsurance && (
              <div>
                <div className="t-2" style={{ fontSize: 11.5, fontWeight: 600, textTransform: 'uppercase', marginBottom: 8 }}>Insurance Policy</div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                  <Field label="Insurance Company"><Input value={form.insurance?.insuranceCompany || ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, insuranceCompany: e.target.value } }))} /></Field>
                  <Field label="Policy No"><Input value={form.insurance?.policyNo || ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, policyNo: e.target.value } }))} /></Field>
                  <Field label="Amount"><Input type="number" value={form.insurance?.amount ?? ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, amount: e.target.value } }))} /></Field>
                  <Field label="Contact Person"><Input value={form.insurance?.contactPerson || ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, contactPerson: e.target.value } }))} /></Field>
                  <Field label="Coverage"><Input value={form.insurance?.coverage || ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, coverage: e.target.value } }))} placeholder="e.g. Comprehensive" /></Field>
                  <Field label="Remark" full><Input value={form.insurance?.remarks || ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, remarks: e.target.value } }))} /></Field>
                </div>
              </div>
            )}

            {selectedType?.requiresCollateral && (
              <div>
                <div className="row between" style={{ marginBottom: 8 }}>
                  <span className="t-2" style={{ fontSize: 11.5, fontWeight: 600, textTransform: 'uppercase' }}>Collateral</span>
                  <Btn size="sm" variant="solid" icon="plus" onClick={addCollateral}>Add Item</Btn>
                </div>
                {form.collateral.map((c, i) => (
                  <div key={i} className="row gap-2 wrap" style={{ marginBottom: 8, alignItems: 'flex-end' }}>
                    <Field label="Type" style={{ minWidth: 120 }}><Select value={c.collateralType || 'Jewelry'} onChange={(e) => setCollateral(i, { collateralType: e.target.value as any })}>
                      <option value="Jewelry">Jewelry</option><option value="Vehicle">Vehicle</option><option value="Property">Property</option><option value="Other">Other</option>
                    </Select></Field>
                    <Field label="Description" style={{ flex: 1, minWidth: 160 }}><Input value={c.description} onChange={(e) => setCollateral(i, { description: e.target.value })} /></Field>
                    {c.collateralType === 'Jewelry' && (<>
                      <Field label="Weight (g)" style={{ width: 100 }}><Input type="number" step="0.001" value={c.weightGrams ?? ''} onChange={(e) => setCollateral(i, { weightGrams: e.target.value })} /></Field>
                      <Field label="Purity" style={{ width: 90 }}><Input value={c.purity || ''} onChange={(e) => setCollateral(i, { purity: e.target.value })} placeholder="22K" /></Field>
                    </>)}
                    <Field label="Appraised Value" style={{ width: 130 }}><Input type="number" value={c.appraisedValue ?? ''} onChange={(e) => setCollateral(i, { appraisedValue: e.target.value })} /></Field>
                    <button className="mms-act" title="Remove" onClick={() => removeCollateral(i)}><Icon n="trash" s={14} /></button>
                  </div>
                ))}
              </div>
            )}

            <div className="row gap-2">
              <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : (editing ? 'Save Changes' : 'Submit Application')}</Btn>
              <Btn variant="solid" onClick={backFromForm}>Cancel</Btn>
            </div>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Speed Drafts" title="Speed Drafts" icon="coins" sub={`${total} drafts`}
        actions={<Can user={me} module="Speed Drafts" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>New Speed Draft</Btn></Can>} />

      <div className="row gap-2 wrap" style={{ marginBottom: 14 }}>
        {(['All', ...STATUSES] as const).map((s) => (
          <Btn key={s} size="sm" variant={statusFilter === s ? 'primary' : 'solid'} onClick={() => setStatusFilter(s)}>
            {s} {counts[s] ? <span style={{ opacity: 0.7 }}>({counts[s]})</span> : ''}
          </Btn>
        ))}
      </div>

      <Card pad={0}>
        <div className="row gap-2" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line)', alignItems: 'center' }}>
          <div style={{ position: 'relative', maxWidth: 340, flex: 1 }}>
            <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--tx-2)' }}><Icon n="search" s={15} /></span>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search code, customer, type…" style={{ paddingLeft: 32 }} />
          </div>
          {search.trim() && <span className="t-2" style={{ fontSize: 12 }}>{total} match{total === 1 ? '' : 'es'}</span>}
        </div>
        {loading ? <LoadingBlock /> : <>
        <Table cols={[{ label: 'Draft' }, { label: 'Customer' }, { label: 'Type' }, { label: 'Capital' }, { label: 'Outstanding Capital' }, { label: 'Status', align: 'center' }]}
          rows={rows}
          empty="No Speed Drafts yet"
          onRow={openRow}
          render={(d) => <>
            <Td c="var(--tx-0)" mono style={{ fontWeight: 600 }}>{d.code}</Td>
            <Td><div style={{ fontWeight: 600 }}>{d.customer?.fullName}</div><div className="t-2" style={{ fontSize: 11.5 }}>{d.customer?.code}</div></Td>
            <Td>{d.speedDraftDetail?.speedDraftType?.name} <span className="t-2">({CATEGORY_LABEL[d.speedDraftDetail?.speedDraftType?.category || ''] || d.speedDraftDetail?.speedDraftType?.category})</span></Td>
            <Td mono>{money(d.principalAmount)}</Td>
            <Td mono>{d.status === 'Active' || d.status === 'Closed' ? money(d.outstandingBalanceCache) : '—'}</Td>
            <Td align="center"><Badge tone={statusTone[d.status]} dot>{d.status}</Badge></Td>
          </>} />
        <Pager page={page} lastPage={lastPage} total={total} onChange={setPage} />
        </>}
      </Card>
    </div>
  )
}

export default SpeedDraftsScreen
