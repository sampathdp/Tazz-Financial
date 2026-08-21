/* Tazz Investment & Credit — Pawn Tickets. A ticket is issued and disbursed
   in one step (see backend PawningController's docblock) — the appraisal
   form below IS the approval, so there's no separate Pending/Approve step
   like Loan/SpeedDraft: fill in the articles, submit, cash goes out. */
import { useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, Table, Td, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  pawningsApi, pawningTypesApi, pawningArticleTypesApi, pawningGoldRatesApi, customersApi,
  type Pawning, type PawningStatus, type PawningType, type PawningArticleType, type PawningArticleInput, type Customer, type AuthUser,
} from '../api'
import { PawningDetail } from './pawning/PawningDetail'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const STATUSES: PawningStatus[] = ['Active', 'Closed', 'Cancelled', 'WrittenOff']
const statusTone: Record<PawningStatus, 'green' | 'red' | 'amber' | 'blue' | 'neutral'> = {
  Active: 'green', Closed: 'neutral', Cancelled: 'red', WrittenOff: 'red',
}
const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const ROUTE_BASE = '/pawn/tickets'

const blankArticle = (): PawningArticleInput => ({ articleTypeId: 0, testedKarat: 22, grossWeightGrams: '', netWeightGrams: '', ratePerGram: '' })

interface FormState {
  customerId?: number
  pawningTypeId?: number
  principalAmount?: number | string
  purpose?: string
  articles: PawningArticleInput[]
}
const blankForm = (): FormState => ({ articles: [blankArticle()] })

export function PawningsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const navigate = useNavigate()
  const params = useParams<{ id?: string }>()
  const location = useLocation()
  const [rows, setRows] = useState<Pawning[]>([])
  const [types, setTypes] = useState<PawningType[]>([])
  const [articleTypes, setArticleTypes] = useState<PawningArticleType[]>([])
  const [customers, setCustomers] = useState<Customer[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<PawningStatus | 'All'>('Active')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const [mode, setMode] = useState<'list' | 'form' | 'detail'>('list')
  const [detail, setDetail] = useState<Pawning | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [form, setForm] = useState<FormState>(blankForm())

  const refresh = () => { setLoading(true); pawningsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false)) }
  useEffect(() => {
    pawningTypesApi.list().then((all) => setTypes(all.filter((t) => (t.status || 'Active') === 'Active'))).catch(() => setTypes([]))
    pawningArticleTypesApi.list().then((all) => setArticleTypes(all.filter((t) => (t.status || 'Active') === 'Active'))).catch(() => setArticleTypes([]))
    customersApi.list().then(setCustomers).catch(() => setCustomers([]))
  }, [])

  const selectedType = types.find((t) => t.id === form.pawningTypeId)
  const selectedCustomer = customers.find((c) => c.id === form.customerId)

  const openCreate = () => navigate(`${ROUTE_BASE}/new`)
  const openRow = (row: Pawning) => navigate(`${ROUTE_BASE}/${row.id}`)
  const back = () => navigate(ROUTE_BASE)
  const reloadDetail = async () => { if (detail) setDetail(await pawningsApi.get(detail.id)) }

  const loadCreate = () => { setForm(blankForm()); setErr(''); setMode('form') }
  const loadRow = async (id: number) => {
    try { setDetail(await pawningsApi.get(id)); setMode('detail') }
    catch { notify.error('Pawning ticket not found'); navigate(ROUTE_BASE, { replace: true }) }
  }

  useEffect(() => {
    if (location.pathname === `${ROUTE_BASE}/new`) { loadCreate(); return }
    if (params.id) { setDetailLoading(true); loadRow(Number(params.id)).finally(() => setDetailLoading(false)); return }
    setMode('list'); setDetail(null); refresh()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname, params.id])

  const addArticle = () => setForm((s) => ({ ...s, articles: [...s.articles, blankArticle()] }))
  const setArticle = (i: number, patch: Partial<PawningArticleInput>) =>
    setForm((s) => ({ ...s, articles: s.articles.map((a, ai) => (ai === i ? { ...a, ...patch } : a)) }))
  const removeArticle = (i: number) => setForm((s) => ({ ...s, articles: s.articles.length > 1 ? s.articles.filter((_, ai) => ai !== i) : s.articles }))

  const fetchRate = async (i: number, karat: number) => {
    if (!karat) return
    try {
      const rate = await pawningGoldRatesApi.latest(karat, selectedCustomer?.branchId)
      if (rate) setArticle(i, { ratePerGram: rate.ratePerGram })
    } catch { /* no rate on file for this karat yet — leave for manual entry */ }
  }

  // Live, client-side preview only — the backend recomputes and validates
  // every figure authoritatively on submit (see PawningController::validated()).
  const articleTotals = useMemo(() => form.articles.map((a) => {
    const net = num(a.netWeightGrams)
    const appraisedValue = net * num(a.ratePerGram)
    const maxLoanValue = selectedType ? appraisedValue * num(selectedType.ltvPercent) / 100 : 0
    return { appraisedValue, maxLoanValue }
  }), [form.articles, selectedType])
  const totalGross = form.articles.reduce((s, a) => s + num(a.grossWeightGrams), 0)
  const totalNet = form.articles.reduce((s, a) => s + num(a.netWeightGrams), 0)
  const totalAppraised = articleTotals.reduce((s, t) => s + t.appraisedValue, 0)
  const totalMaxLoan = articleTotals.reduce((s, t) => s + t.maxLoanValue, 0)

  const save = async () => {
    if (!form.customerId) { setErr('Select a customer.'); return }
    if (!form.pawningTypeId) { setErr('Select a pawning scheme.'); return }
    if (!form.principalAmount || Number(form.principalAmount) <= 0) { setErr('Enter the amount to advance.'); return }
    if (form.articles.some((a) => !a.articleTypeId || !a.testedKarat || !a.grossWeightGrams || !a.netWeightGrams || !a.ratePerGram)) {
      setErr('Every article needs a type, tested karat, gross/net weight and rate per gram.'); return
    }
    if (Number(form.principalAmount) > totalMaxLoan) { setErr(`Amount exceeds the maximum advance for these articles (${money(totalMaxLoan)}).`); return }
    const customer = customers.find((c) => c.id === form.customerId)
    const body = {
      branchId: customer!.branchId,
      customerId: form.customerId,
      pawningTypeId: form.pawningTypeId,
      principalAmount: form.principalAmount,
      purpose: form.purpose,
      articles: form.articles,
    }
    setBusy(true); setErr('')
    try {
      const created = await pawningsApi.create(body)
      notify.success('Pawning ticket issued', created.code)
      navigate(`${ROUTE_BASE}/${created.id}`)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not issue this ticket'))
    } finally { setBusy(false) }
  }

  const q = search.trim().toLowerCase()
  const filtered = rows.filter((d) => {
    if (statusFilter !== 'All' && d.status !== statusFilter) return false
    if (!q) return true
    return [d.code, d.customer?.fullName, d.customer?.code, d.pawningDetail?.pawningType?.name].some((v) => (v || '').toLowerCase().includes(q))
  })
  const counts = useMemo(() => {
    const c: Record<string, number> = { All: rows.length }
    for (const d of rows) c[d.status] = (c[d.status] || 0) + 1
    return c
  }, [rows])

  if (mode === 'detail') {
    return (
      <div>
        <PageHead crumbs="Pawning Management" title={detailLoading || !detail ? 'Loading…' : `Pawn Ticket ${detail.code}`} icon="tag"
          sub={detail ? `${detail.customer?.fullName} — ${detail.pawningDetail?.pawningType?.name}` : undefined}
          actions={<Btn variant="solid" onClick={back}>← Back to Pawn Tickets</Btn>} />
        {detailLoading || !detail ? <LoadingBlock /> : <PawningDetail pawning={detail} me={me} onChanged={reloadDetail} />}
      </div>
    )
  }

  if (mode === 'form') {
    return (
      <div>
        <PageHead crumbs="Pawning Management" title="New Pawn Ticket" icon="tag"
          actions={<Btn variant="solid" onClick={back}>← Back to Pawn Tickets</Btn>} />
        <Card>
          <ErrorBanner error={err} />
          <div className="col gap-4">
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 16 }}>
              <Field label="Customer">
                <SearchSelect
                  value={selectedCustomer?.fullName || ''}
                  onChange={(_n, opt) => setForm((s) => ({ ...s, customerId: opt?.id }))}
                  options={customers}
                  getLabel={(c: Customer) => c.fullName}
                  getSub={(c: Customer) => [c.code, c.nicNo, c.phone].filter(Boolean).join(' · ')}
                  placeholder="Search by name, code, NIC…"
                />
              </Field>
              <Field label="Pawning Scheme"><Select value={form.pawningTypeId || ''} onChange={(e) => setForm((s) => ({ ...s, pawningTypeId: Number(e.target.value) }))}>
                <option value="">Select scheme…</option>
                {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </Select></Field>
              <Field label="Purpose (optional)"><Input value={form.purpose || ''} onChange={(e) => setForm((s) => ({ ...s, purpose: e.target.value }))} /></Field>
            </div>
            {selectedType && (
              <div className="t-2" style={{ fontSize: 11.5 }}>
                {selectedType.cycleDays}-day cycle, {Number(selectedType.normalRatePercent).toFixed(3)}% per cycle
                {selectedType.specialRatePercent != null && ` (${Number(selectedType.specialRatePercent).toFixed(3)}% if settled within ${selectedType.specialRateDays} days)`}
                , up to {Number(selectedType.ltvPercent).toFixed(0)}% of appraised value.
              </div>
            )}

            <div>
              <div className="row between" style={{ marginBottom: 8 }}>
                <span className="t-2" style={{ fontSize: 11.5, fontWeight: 600, textTransform: 'uppercase' }}>Articles</span>
                <Btn size="sm" variant="solid" icon="plus" onClick={addArticle}>Add Article</Btn>
              </div>
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
                  <thead><tr>
                    {['Article', 'Declared Kt', 'Tested Kt', 'Gross (g)', 'Net (g)', 'Rate/g', 'Appraised', 'Max Loan', ''].map((h) => (
                      <th key={h} style={{ textAlign: 'left', padding: '6px 8px', fontSize: 11, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.05em' }}>{h}</th>
                    ))}
                  </tr></thead>
                  <tbody>
                    {form.articles.map((a, i) => (
                      <tr key={i}>
                        <td style={{ padding: 4, minWidth: 140 }}><Select value={a.articleTypeId || ''} onChange={(e) => setArticle(i, { articleTypeId: Number(e.target.value) })}>
                          <option value="">Select…</option>
                          {articleTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </Select></td>
                        <td style={{ padding: 4, width: 90 }}><Input type="number" step="0.01" value={a.declaredKarat ?? ''} onChange={(e) => setArticle(i, { declaredKarat: e.target.value })} /></td>
                        <td style={{ padding: 4, width: 90 }}><Input type="number" step="0.01" value={a.testedKarat ?? ''} onChange={(e) => setArticle(i, { testedKarat: e.target.value })} onBlur={() => fetchRate(i, Number(a.testedKarat))} /></td>
                        <td style={{ padding: 4, width: 100 }}><Input type="number" step="0.001" value={a.grossWeightGrams ?? ''} onChange={(e) => setArticle(i, { grossWeightGrams: e.target.value })} /></td>
                        <td style={{ padding: 4, width: 100 }}><Input type="number" step="0.001" value={a.netWeightGrams ?? ''} onChange={(e) => setArticle(i, { netWeightGrams: e.target.value })} /></td>
                        <td style={{ padding: 4, width: 110 }}><Input type="number" step="0.01" value={a.ratePerGram ?? ''} onChange={(e) => setArticle(i, { ratePerGram: e.target.value })} /></td>
                        <td style={{ padding: 4, fontFamily: 'JetBrains Mono', whiteSpace: 'nowrap' }}>{money(articleTotals[i]?.appraisedValue)}</td>
                        <td style={{ padding: 4, fontFamily: 'JetBrains Mono', whiteSpace: 'nowrap', fontWeight: 600 }}>{money(articleTotals[i]?.maxLoanValue)}</td>
                        <td style={{ padding: 4 }}>{form.articles.length > 1 && <button className="mms-act" title="Remove" onClick={() => removeArticle(i)}><Icon n="trash" s={14} /></button>}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot><tr style={{ borderTop: '1px solid var(--line)' }}>
                    <td colSpan={3} style={{ padding: '8px', fontWeight: 600 }}>Total</td>
                    <td style={{ padding: '8px', fontFamily: 'JetBrains Mono' }}>{totalGross.toFixed(3)}</td>
                    <td style={{ padding: '8px', fontFamily: 'JetBrains Mono' }}>{totalNet.toFixed(3)}</td>
                    <td />
                    <td style={{ padding: '8px', fontFamily: 'JetBrains Mono' }}>{money(totalAppraised)}</td>
                    <td style={{ padding: '8px', fontFamily: 'JetBrains Mono', fontWeight: 700, color: 'var(--ac-bright)' }}>{money(totalMaxLoan)}</td>
                    <td />
                  </tr></tfoot>
                </table>
              </div>
            </div>

            <Field label={`Amount to Advance (max ${money(totalMaxLoan)})`} style={{ maxWidth: 260 }}>
              <Input type="number" value={form.principalAmount ?? ''} onChange={(e) => setForm((s) => ({ ...s, principalAmount: e.target.value }))} />
            </Field>

            <div className="row gap-2">
              <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Issuing…' : 'Issue Ticket & Disburse'}</Btn>
              <Btn variant="solid" onClick={back}>Cancel</Btn>
            </div>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Pawning Management" title="Pawn Tickets" icon="tag" sub={`${rows.length} tickets`}
        actions={<Can user={me} module="Pawnings" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>New Pawn Ticket</Btn></Can>} />

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
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search code, customer, scheme…" style={{ paddingLeft: 32 }} />
          </div>
          {q && <span className="t-2" style={{ fontSize: 12 }}>{filtered.length} of {rows.length}</span>}
        </div>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Ticket' }, { label: 'Customer' }, { label: 'Scheme' }, { label: 'Advanced' }, { label: 'Outstanding' }, { label: 'Repay Due' }, { label: 'Status', align: 'center' }]}
          rows={filtered}
          empty="No pawn tickets yet"
          onRow={openRow}
          render={(d) => <>
            <Td c="var(--tx-0)" mono style={{ fontWeight: 600 }}>{d.code}</Td>
            <Td><div style={{ fontWeight: 600 }}>{d.customer?.fullName}</div><div className="t-2" style={{ fontSize: 11.5 }}>{d.customer?.code}</div></Td>
            <Td>{d.pawningDetail?.pawningType?.name}</Td>
            <Td mono>{money(d.principalAmount)}</Td>
            <Td mono>{money(d.outstandingBalanceCache)}</Td>
            <Td>{dateOnly(d.pawningDetail?.repayDueDate)}</Td>
            <Td align="center"><Badge tone={statusTone[d.status]} dot>{d.status}</Badge></Td>
          </>} />}
      </Card>
    </div>
  )
}

export default PawningsScreen
