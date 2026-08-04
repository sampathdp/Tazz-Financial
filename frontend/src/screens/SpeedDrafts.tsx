/* Tazz Investment & Credit — Speed Drafts (Loans / Mortgages / Gold Pawning / Leases,
   interest-only repayment). One screen covers the full lifecycle — intake, approve/
   reject, disburse, and ongoing view — status filter chips replace separate
   Applications/Active screens since the volume here doesn't need them split out yet. */
import { useEffect, useMemo, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, Table, Td, ErrorBanner } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  speedDraftsApi, speedDraftTypesApi, customersApi,
  type SpeedDraft, type SpeedDraftStatus, type SpeedDraftType, type SpeedDraftCollateralInput, type Customer, type AuthUser,
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
  ratePeriod?: 'monthly' | 'annual'
  termMonths?: number
  collateral: SpeedDraftCollateralInput[]
}
const blankForm = (): FormState => ({ ratePeriod: 'monthly', collateral: [] })

export function SpeedDraftsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<SpeedDraft[]>([])
  const [types, setTypes] = useState<SpeedDraftType[]>([])
  const [customers, setCustomers] = useState<Customer[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
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
    speedDraftsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => {
    refresh()
    speedDraftTypesApi.list().then((all) => setTypes(all.filter((t) => (t.status || 'Active') === 'Active'))).catch(() => setTypes([]))
    customersApi.list().then(setCustomers).catch(() => setCustomers([]))
  }, [])

  const selectedType = types.find((t) => t.id === form.speedDraftTypeId)

  const openCreate = () => { setEditing(null); setForm(blankForm()); setErr(''); setMode('form') }
  const openRow = async (row: SpeedDraft) => {
    if (row.status === 'Pending') {
      // Awaited fully before switching modes (no loading state needed here) so the
      // form never flashes blank/stale content — mirrors LoanApplications' openRow.
      const full = await speedDraftsApi.get(row.id).catch(() => row)
      setEditing(full)
      setForm({
        customerId: full.customerId, speedDraftTypeId: full.speedDraftDetail?.speedDraftTypeId,
        principalAmount: full.principalAmount, interestRatePercent: full.interestRatePercent,
        ratePeriod: full.ratePeriod || 'monthly', termMonths: full.termMonths,
        collateral: full.collateral || [],
      })
      setErr(''); setMode('form')
      return
    }
    setMode('detail'); setDetailLoading(true)
    try { setDetail(await speedDraftsApi.get(row.id)) } catch { setDetail(row) } finally { setDetailLoading(false) }
  }
  const back = () => { setMode('list'); setEditing(null); setDetail(null); refresh() }
  const reloadDetail = async () => { if (detail) setDetail(await speedDraftsApi.get(detail.id)) }

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
    const customer = customers.find((c) => c.id === form.customerId)
    const body = {
      customerId: form.customerId,
      speedDraftTypeId: form.speedDraftTypeId,
      branchId: customer?.branchId,
      principalAmount: form.principalAmount,
      interestRatePercent: form.interestRatePercent,
      ratePeriod: form.ratePeriod,
      termMonths: form.termMonths,
      collateral: form.collateral.filter((c) => c.description),
    }
    setBusy(true); setErr('')
    try {
      if (editing) await speedDraftsApi.update(editing.id, body as any)
      else await speedDraftsApi.create(body as any)
      back()
      notify.success(editing ? 'Speed Draft updated' : 'Speed Draft application submitted', customer?.fullName)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (d: SpeedDraft) => {
    const ok = await notify.confirm({ title: 'Withdraw this Speed Draft application?', msg: 'This action cannot be undone.', danger: true, okText: 'Withdraw' })
    if (!ok) return
    try { await speedDraftsApi.remove(d.id); refresh(); notify.success('Application withdrawn', d.code) }
    catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }

  const approve = async (d: SpeedDraft) => {
    const ok = await notify.confirm({ title: `Approve Speed Draft ${d.code}?`, msg: `${d.customer?.fullName} — ${money(d.principalAmount)}`, okText: 'Approve' })
    if (!ok) return
    try { await speedDraftsApi.approve(d.id); refresh(); notify.success('Speed Draft approved', d.code) }
    catch (ex: any) { notify.error('Approve failed', getErrorText(ex)) }
  }

  const reject = async (d: SpeedDraft) => {
    const reason = await notify.prompt({ title: `Reject Speed Draft ${d.code}?`, msg: 'Give a reason — this is shown to branch staff.', placeholder: 'Reason for rejection', required: true, danger: true, okText: 'Reject' })
    if (!reason) return
    try { await speedDraftsApi.reject(d.id, reason); refresh(); notify.success('Speed Draft rejected', d.code) }
    catch (ex: any) { notify.error('Reject failed', getErrorText(ex)) }
  }

  const q = search.trim().toLowerCase()
  const filtered = rows.filter((d) => {
    if (statusFilter !== 'All' && d.status !== statusFilter) return false
    if (!q) return true
    return [d.code, d.customer?.fullName, d.customer?.code, d.speedDraftDetail?.speedDraftType?.name].some((v) => (v || '').toLowerCase().includes(q))
  })
  const counts = useMemo(() => {
    const c: Record<string, number> = { All: rows.length }
    for (const d of rows) c[d.status] = (c[d.status] || 0) + 1
    return c
  }, [rows])

  if (mode === 'detail') {
    return (
      <div>
        <PageHead crumbs="Speed Drafts" title={detailLoading || !detail ? 'Loading…' : `Speed Draft ${detail.code}`} icon="coins"
          sub={detail ? `${detail.customer?.fullName} — ${detail.speedDraftDetail?.speedDraftType?.name}` : undefined}
          actions={<Btn variant="solid" onClick={back}>← Back to Speed Drafts</Btn>} />
        {detailLoading || !detail ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div>
          : <SpeedDraftDetail speedDraft={detail} me={me} onChanged={reloadDetail} />}
      </div>
    )
  }

  if (mode === 'form') {
    return (
      <div>
        <PageHead crumbs="Speed Drafts" title={editing ? `Edit ${editing.code}` : 'New Speed Draft'} icon="coins"
          actions={<Btn variant="solid" onClick={back}>← Back to Speed Drafts</Btn>} />
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
                <option value="monthly">Monthly</option><option value="annual">Annual</option>
              </Select></Field>
              <Field label="Period (Months)"><Input type="number" value={form.termMonths ?? ''} onChange={(e) => setForm((s) => ({ ...s, termMonths: Number(e.target.value) }))}
                placeholder={selectedType ? `${selectedType.minPeriodMonths ?? 1}–${selectedType.maxPeriodMonths ?? ''}` : undefined} /></Field>
            </div>
            <div className="t-2" style={{ fontSize: 11.5 }}>
              Interest is due monthly on the outstanding capital only — the full capital falls due at maturity, and can also be reduced early at any time via a capital payment.
            </div>

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
                    <Field label="Weight (g)" style={{ width: 100 }}><Input type="number" step="0.001" value={c.weightGrams ?? ''} onChange={(e) => setCollateral(i, { weightGrams: e.target.value })} /></Field>
                    <Field label="Purity" style={{ width: 90 }}><Input value={c.purity || ''} onChange={(e) => setCollateral(i, { purity: e.target.value })} placeholder="22K" /></Field>
                    <Field label="Appraised Value" style={{ width: 130 }}><Input type="number" value={c.appraisedValue ?? ''} onChange={(e) => setCollateral(i, { appraisedValue: e.target.value })} /></Field>
                    <button className="mms-act" title="Remove" onClick={() => removeCollateral(i)}><Icon n="trash" s={14} /></button>
                  </div>
                ))}
              </div>
            )}

            <div className="row gap-2">
              <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : (editing ? 'Save Changes' : 'Submit Application')}</Btn>
              <Btn variant="solid" onClick={back}>Cancel</Btn>
            </div>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Speed Drafts" title="Speed Drafts" icon="coins" sub={`${rows.length} drafts`}
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
          {q && <span className="t-2" style={{ fontSize: 12 }}>{filtered.length} of {rows.length}</span>}
        </div>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Draft' }, { label: 'Customer' }, { label: 'Type' }, { label: 'Capital' }, { label: 'Outstanding Capital' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 110 }]}
          rows={filtered}
          empty="No Speed Drafts yet"
          onRow={openRow}
          render={(d) => <>
            <Td c="var(--tx-0)" mono style={{ fontWeight: 600 }}>{d.code}</Td>
            <Td><div style={{ fontWeight: 600 }}>{d.customer?.fullName}</div><div className="t-2" style={{ fontSize: 11.5 }}>{d.customer?.code}</div></Td>
            <Td>{d.speedDraftDetail?.speedDraftType?.name} <span className="t-2">({CATEGORY_LABEL[d.speedDraftDetail?.speedDraftType?.category || ''] || d.speedDraftDetail?.speedDraftType?.category})</span></Td>
            <Td mono>{money(d.principalAmount)}</Td>
            <Td mono>{d.status === 'Active' || d.status === 'Closed' ? money(d.outstandingBalanceCache) : '—'}</Td>
            <Td align="center"><Badge tone={statusTone[d.status]} dot>{d.status}</Badge></Td>
            <Td align="right">
              {d.status === 'Pending' && (
                <div className="row gap-1" style={{ justifyContent: 'flex-end' }} onClick={(e) => e.stopPropagation()}>
                  <Can user={me} module="Speed Drafts" action="Approve">
                    <button className="mms-act" title="Approve" onClick={() => approve(d)}><Icon n="check" s={14} c="var(--ok)" /></button>
                    <button className="mms-act" title="Reject" onClick={() => reject(d)}><Icon n="x" s={14} c="var(--bad)" /></button>
                  </Can>
                  <Can user={me} module="Speed Drafts" action="Delete">
                    <button className="mms-act" title="Withdraw" onClick={() => remove(d)}><Icon n="trash" s={14} /></button>
                  </Can>
                </div>
              )}
            </Td>
          </>} />}
      </Card>
    </div>
  )
}

export default SpeedDraftsScreen
