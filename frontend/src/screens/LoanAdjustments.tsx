/* Tazz Investment & Credit — Loan Adjustments (admin-only). These actions
   directly change a loan's schedule/balance or a receipt's outcome after the
   fact, so they're kept off the regular Loan Detail / Payment Receipt screens
   (which everyone with Loan Payments access can reach) and consolidated here
   behind the 'admin' route middleware — see routes/api.php. Document/Legal/
   Valuation/Other charges are permission-gated on the backend (Active Loans),
   not admin-only, but this is the only place a charge can actually be added. */
import { useEffect, useMemo, useState } from 'react'
import { isAdminRole } from '../lib/permissions'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, Table, Td , LoadingBlock } from '../components/ui'
import {
  loansApi, loanSchedulesApi, loanWaiversApi, loanChargesApi, loanTypesApi, chargeCategoriesApi,
  loanPaymentsApi, financeCalculatorApi,
  type Loan, type LoanSchedule, type LoanWaiver, type WaiverType, type LoanCharge, type ChargeCategory,
  type AuthUser, type LoanType, type SkipDateOption,
  type InterestMethod, type LoanFrequency, type PaymentAllocationSplit, type FinanceCalcResult, type RescheduleInput,
} from '../api'
import { Icon } from '../components/Icon'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)
const scheduleTone: Record<string, 'green' | 'red' | 'amber' | 'neutral'> = { Paid: 'green', Partial: 'amber', Overdue: 'red', Pending: 'neutral' }
const balanceOf = (r: LoanSchedule) => Math.max(0, num(r.totalDue) - num(r.paidAmount))
const WAIVER_TYPES: WaiverType[] = ['Interest', 'Penalty', 'Charge', 'Principal']

/* ============================ Installment Skip =========================== */
function SkipSection({ notify }: { notify: ReturnType<typeof useNotify> }) {
  const [mode, setMode] = useState<'loan' | 'bulk'>('loan')
  return (
    <div>
      <div className="row gap-2" style={{ marginBottom: 16 }}>
        <Btn size="sm" variant={mode === 'loan' ? 'primary' : 'solid'} onClick={() => setMode('loan')}>By Loan</Btn>
        <Btn size="sm" variant={mode === 'bulk' ? 'primary' : 'solid'} onClick={() => setMode('bulk')}>By Loan Type (Bulk)</Btn>
      </div>
      {mode === 'loan' ? <SkipByLoan notify={notify} /> : <SkipByLoanType notify={notify} />}
    </div>
  )
}

function SkipByLoan({ notify }: { notify: ReturnType<typeof useNotify> }) {
  const [loans, setLoans] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [selected, setSelected] = useState<Loan | null>(null)
  const [loadingLoan, setLoadingLoan] = useState(false)
  const [skipBusy, setSkipBusy] = useState<number | null>(null)

  useEffect(() => {
    loansApi.list().then((all) => setLoans(all.filter((l) => l.status === 'Active'))).catch(() => setLoans([])).finally(() => setLoading(false))
  }, [])

  const pickLoan = async (_n: string, opt?: Loan) => {
    if (!opt) { setSelected(null); return }
    setLoadingLoan(true)
    try { setSelected(await loansApi.get(opt.id)) } catch { setSelected(opt) } finally { setLoadingLoan(false) }
  }

  const skip = async (row: LoanSchedule) => {
    if (!selected) return
    const ok = await notify.confirm({
      title: `Skip installment #${row.installmentNo}?`,
      msg: `Due ${dateOnly(row.dueDate)} for ${money(balanceOf(row))} — it will be moved to the back of the schedule and the loan will run one period longer.`,
      okText: 'Skip',
    })
    if (!ok) return
    setSkipBusy(row.id)
    try {
      const fresh = await loanSchedulesApi.skip(selected.id, row.id)
      setSelected(fresh)
      notify.success('Installment skipped', 'Moved to the end of the schedule')
    } catch (ex: any) {
      notify.error('Could not skip installment', getErrorText(ex))
    } finally { setSkipBusy(null) }
  }

  const rows = (selected?.schedules || []).slice().sort((a, b) => a.installmentNo - b.installmentNo).filter((s) => s.status !== 'Paid')

  return (
    <div>
      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Find Loan</div>
        <SearchSelect
          value={selected ? `${selected.code} — ${selected.customer?.fullName}` : ''}
          onChange={(_n, opt) => pickLoan(_n, opt)}
          options={loans}
          getLabel={(l: Loan) => `${l.code} — ${l.customer?.fullName}`}
          getSub={(l: Loan) => [l.customer?.nicNo, l.customer?.phone].filter(Boolean).join(' · ')}
          placeholder={loading ? 'Loading loans…' : 'Search by NIC, Loan ID, or customer name…'}
          disabled={loading}
        />
      </Card>

      {loadingLoan && <div style={{ padding: 30, textAlign: 'center', color: 'var(--tx-2)' }}>Loading loan…</div>}

      {selected && !loadingLoan && (
        <Card pad={0}>
          <div className="row between" style={{ padding: '14px 16px' }}>
            <div className="eyebrow">Unpaid Installments — {selected.code}</div>
          </div>
          <Table cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Total Due', align: 'right' }, { label: 'Balance', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
            rows={rows} empty="No unpaid installments — this loan is fully paid"
            render={(r: LoanSchedule) => <>
              <Td>{r.installmentNo}</Td>
              <Td>{dateOnly(r.dueDate)}</Td>
              <Td mono align="right">{money(r.totalDue)}</Td>
              <Td mono align="right">{money(balanceOf(r))}</Td>
              <Td align="center"><Badge tone={scheduleTone[r.status]} dot>{r.status}</Badge></Td>
              <Td align="right"><Btn size="sm" variant="solid" onClick={() => skip(r)} disabled={skipBusy === r.id}>{skipBusy === r.id ? 'Skipping…' : 'Skip'}</Btn></Td>
            </>} />
        </Card>
      )}
    </div>
  )
}

function SkipByLoanType({ notify }: { notify: ReturnType<typeof useNotify> }) {
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [loadingTypes, setLoadingTypes] = useState(true)
  const [typeId, setTypeId] = useState<number | ''>('')
  const [dates, setDates] = useState<SkipDateOption[]>([])
  const [loadingDates, setLoadingDates] = useState(false)
  const [selectedDates, setSelectedDates] = useState<Set<string>>(new Set())
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    loanTypesApi.list().then((all) => setLoanTypes(all.filter((t) => t.isProduct))).catch(() => setLoanTypes([])).finally(() => setLoadingTypes(false))
  }, [])

  const loadDates = (id: number) => {
    setLoadingDates(true)
    loanSchedulesApi.skipDates(id).then(setDates).catch(() => setDates([])).finally(() => setLoadingDates(false))
  }
  useEffect(() => {
    setSelectedDates(new Set())
    if (typeId === '') { setDates([]); return }
    loadDates(typeId)
  }, [typeId])

  const toggleDate = (d: string) => {
    setSelectedDates((cur) => {
      const next = new Set(cur)
      if (next.has(d)) next.delete(d); else next.add(d)
      return next
    })
  }
  const toggleAll = () => setSelectedDates((cur) => (cur.size === dates.length ? new Set() : new Set(dates.map((d) => d.dueDate))))

  const selectedSummary = useMemo(() => {
    const picked = dates.filter((d) => selectedDates.has(d.dueDate))
    return { installments: picked.reduce((s, d) => s + d.installmentCount, 0), amount: picked.reduce((s, d) => s + d.totalAmount, 0) }
  }, [dates, selectedDates])

  const runBulkSkip = async () => {
    if (typeId === '' || selectedDates.size === 0) return
    const typeName = loanTypes.find((t) => t.id === typeId)?.name || 'this loan type'
    const ok = await notify.confirm({
      title: `Skip ${selectedSummary.installments} installment(s) across ${typeName}?`,
      msg: `Every active loan of this type with an unpaid installment on the selected date(s) will have it pushed to the back of its own schedule — ${money(selectedSummary.amount)} total, running each loan one period longer.`,
      okText: 'Skip All',
    })
    if (!ok) return
    setBusy(true)
    try {
      const res = await loanSchedulesApi.bulkSkip(typeId, Array.from(selectedDates))
      if (res.errors.length > 0) {
        notify.warning('Some loans could not be skipped', `${res.loansAffected} loan(s) / ${res.installmentsSkipped} installment(s) skipped; ${res.errors.length} failed.`)
      } else {
        notify.success('Installments skipped', `${res.loansAffected} loan(s), ${res.installmentsSkipped} installment(s)`)
      }
      loadDates(typeId)
    } catch (ex: any) {
      notify.error('Bulk skip failed', getErrorText(ex))
    } finally { setBusy(false) }
  }

  return (
    <div>
      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Loan Type</div>
        <Select value={typeId} onChange={(e) => setTypeId(e.target.value ? Number(e.target.value) : '')} disabled={loadingTypes} style={{ maxWidth: 320 }}>
          <option value="">{loadingTypes ? 'Loading…' : 'Select a loan type…'}</option>
          {loanTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
        </Select>
      </Card>

      {typeId !== '' && (
        <Card pad={0}>
          <div className="row between wrap gap-2" style={{ padding: '14px 16px' }}>
            <div className="eyebrow">Skippable Due Dates</div>
            {dates.length > 0 && (
              <div className="row gap-3" style={{ alignItems: 'center' }}>
                {selectedDates.size > 0 && <span className="t-2" style={{ fontSize: 12 }}>{selectedSummary.installments} installment(s) selected · {money(selectedSummary.amount)}</span>}
                <button className="mms-act" style={{ fontSize: 11.5, color: 'var(--ac-bright)', background: 'transparent', border: 'none', cursor: 'pointer' }} onClick={toggleAll}>
                  {selectedDates.size === dates.length ? 'Clear all' : 'Select all'}
                </button>
              </div>
            )}
          </div>
          {loadingDates ? <LoadingBlock /> :
          <Table cols={[{ label: '', w: 34 }, { label: 'Due Date' }, { label: 'Loans', align: 'right' }, { label: 'Installments', align: 'right' }, { label: 'Amount', align: 'right' }]}
            rows={dates} empty="No skippable installments for this loan type"
            render={(d: SkipDateOption) => <>
              <Td><input type="checkbox" checked={selectedDates.has(d.dueDate)} onChange={() => toggleDate(d.dueDate)} /></Td>
              <Td>{dateOnly(d.dueDate)}</Td>
              <Td align="right">{d.loanCount}</Td>
              <Td align="right">{d.installmentCount}</Td>
              <Td mono align="right">{money(d.totalAmount)}</Td>
            </>} />}
          {dates.length > 0 && (
            <div className="row" style={{ padding: '14px 16px', borderTop: '1px solid var(--line)', justifyContent: 'flex-end' }}>
              <Btn variant="primary" icon="check" disabled={selectedDates.size === 0 || busy} onClick={runBulkSkip}>
                {busy ? 'Skipping…' : `Skip Selected (${selectedDates.size})`}
              </Btn>
            </div>
          )}
        </Card>
      )}
    </div>
  )
}

/* ================================ Waivers ================================ */
function WaiversSection({ notify }: { notify: ReturnType<typeof useNotify> }) {
  const [loans, setLoans] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [selected, setSelected] = useState<Loan | null>(null)
  const [loadingLoan, setLoadingLoan] = useState(false)
  const [form, setForm] = useState<{ waiverType: WaiverType; amount: string; reason: string }>({ waiverType: 'Interest', amount: '', reason: '' })
  const [busy, setBusy] = useState(false)

  useEffect(() => { loansApi.list().then(setLoans).catch(() => setLoans([])).finally(() => setLoading(false)) }, [])

  const pickLoan = async (_n: string, opt?: Loan) => {
    if (!opt) { setSelected(null); return }
    setLoadingLoan(true)
    try { setSelected(await loansApi.get(opt.id)) } catch { setSelected(opt) } finally { setLoadingLoan(false) }
  }
  const reload = async () => { if (selected) setSelected(await loansApi.get(selected.id)) }

  const add = async () => {
    if (!selected || !form.amount) return
    setBusy(true)
    try {
      await loanWaiversApi.create(selected.id, { waiverType: form.waiverType, amount: form.amount, reason: form.reason || undefined })
      setForm({ waiverType: 'Interest', amount: '', reason: '' }); notify.success('Waiver recorded'); await reload()
    } catch (ex: any) {
      notify.error('Add failed', getErrorText(ex))
    } finally { setBusy(false) }
  }
  const remove = async (w: LoanWaiver) => {
    const ok = await notify.confirm({ title: 'Delete this waiver?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await loanWaiversApi.remove(w.id); notify.success('Waiver deleted'); await reload() }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const waivers = selected?.waivers || []
  const arrears = selected?.summary?.arrears

  return (
    <div>
      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Find Loan</div>
        <SearchSelect
          value={selected ? `${selected.code} — ${selected.customer?.fullName}` : ''}
          onChange={(_n, opt) => pickLoan(_n, opt)}
          options={loans}
          getLabel={(l: Loan) => `${l.code} — ${l.customer?.fullName}`}
          getSub={(l: Loan) => [l.customer?.nicNo, l.customer?.phone].filter(Boolean).join(' · ')}
          placeholder={loading ? 'Loading loans…' : 'Search by NIC, Loan ID, or customer name…'}
          disabled={loading}
        />
      </Card>

      {loadingLoan && <div style={{ padding: 30, textAlign: 'center', color: 'var(--tx-2)' }}>Loading loan…</div>}

      {selected && !loadingLoan && arrears && (
        <Card style={{ marginBottom: 16 }}>
          <div className="eyebrow" style={{ marginBottom: 12 }}>Outstanding Balance — {selected.code}</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 12 }}>
            {[
              { label: 'Future Capital', value: arrears.futureCapital },
              { label: 'Total Interest (Remaining)', value: arrears.totalInterestRemaining },
              { label: 'OD / Default Charges', value: arrears.defaultCharges },
              { label: 'Other Charges', value: arrears.otherChargeArrears },
            ].map((it) => (
              <div key={it.label} style={{ padding: '10px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 4 }}>{it.label}</div>
                <div style={{ fontFamily: 'JetBrains Mono', fontWeight: 700, fontSize: 14 }}>{money(it.value)}</div>
              </div>
            ))}
            <div style={{ padding: '10px 12px', background: 'var(--ac-dim)', border: '1px solid var(--ac-bright)', borderRadius: 'var(--r-s)' }}>
              <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 4 }}>Total Balance</div>
              <div style={{ fontFamily: 'JetBrains Mono', fontWeight: 700, fontSize: 14, color: 'var(--ac-bright)' }}>{money(arrears.amountToBeCollected)}</div>
            </div>
          </div>
          <div className="t-2" style={{ fontSize: 11.5, marginTop: 10 }}>
            Already waived off this loan: <strong>{money(arrears.totalWaived)}</strong> — netted out of the figures above.
          </div>
        </Card>
      )}

      {selected && !loadingLoan && (
        <Card>
          <div className="eyebrow" style={{ marginBottom: 12 }}>Waivers (W-Off Set Plan / Waved Off Details) — {selected.code}</div>
          <div className="row gap-2 wrap" style={{ marginBottom: 14 }}>
            <Select value={form.waiverType} onChange={(e) => setForm((s) => ({ ...s, waiverType: e.target.value as WaiverType }))} style={{ maxWidth: 140 }}>
              {WAIVER_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
            </Select>
            <Input type="number" placeholder="Amount" value={form.amount} onChange={(e) => setForm((s) => ({ ...s, amount: e.target.value }))} style={{ maxWidth: 130 }} />
            <Input placeholder="Reason" value={form.reason} onChange={(e) => setForm((s) => ({ ...s, reason: e.target.value }))} style={{ flex: 1, minWidth: 160 }} />
            <Btn variant="primary" size="sm" icon="plus" onClick={add} disabled={busy}>Add</Btn>
          </div>
          {waivers.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No waivers recorded.</div> :
            <div className="col gap-2">
              {waivers.map((w) => (
                <div key={w.id} className="row between wrap" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
                  <div>
                    <span style={{ fontSize: 12.5, fontWeight: 600 }}>{w.waiverType}</span>
                    <span className="t-2" style={{ fontSize: 12 }}> — {w.reason || 'no reason given'} · {dateOnly(w.waivedAt)} · approved by {w.approver?.name}</span>
                  </div>
                  <div className="row gap-2" style={{ alignItems: 'center' }}>
                    <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(w.amount)}</span>
                    <button className="mms-act" title="Delete" onClick={() => remove(w)}><Icon n="trash" s={14} /></button>
                  </div>
                </div>
              ))}
            </div>}
        </Card>
      )}
    </div>
  )
}

/* ================================ Charges ================================= */
function ChargesSection({ notify }: { notify: ReturnType<typeof useNotify> }) {
  const [loans, setLoans] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [selected, setSelected] = useState<Loan | null>(null)
  const [loadingLoan, setLoadingLoan] = useState(false)
  const [categories, setCategories] = useState<ChargeCategory[]>([])

  // Only the amount owed is entered here — it's paid down automatically when the
  // customer pays (Payment Receipt), same as interest/principal, never typed in directly.
  const [form, setForm] = useState<{ chargeCategoryId: number | ''; dueAmount: string; chargedAt: string; notes: string }>(
    { chargeCategoryId: '', dueAmount: '', chargedAt: todayIso(), notes: '' },
  )
  const [busy, setBusy] = useState(false)
  const [charges, setCharges] = useState<LoanCharge[]>([])
  const [chargesLoading, setChargesLoading] = useState(false)

  useEffect(() => { loansApi.list().then(setLoans).catch(() => setLoans([])).finally(() => setLoading(false)) }, [])
  useEffect(() => { chargeCategoriesApi.list().then((res) => setCategories(res.filter(c => c.status === 'Active'))) }, [])

  const loadCharges = (loanId: number) => {
    setChargesLoading(true)
    loanChargesApi.list(loanId).then(setCharges).catch(() => setCharges([])).finally(() => setChargesLoading(false))
  }

  const pickLoan = async (_n: string, opt?: Loan) => {
    if (!opt) { setSelected(null); setCharges([]); return }
    setLoadingLoan(true)
    try { setSelected(await loansApi.get(opt.id)) } catch { setSelected(opt) } finally { setLoadingLoan(false) }
    loadCharges(opt.id)
  }

  const add = async () => {
    if (!selected || !form.dueAmount || !form.chargeCategoryId) return
    setBusy(true)
    try {
      await loanChargesApi.create(selected.id, {
        chargeCategoryId: form.chargeCategoryId as number, dueAmount: form.dueAmount,
        chargedAt: form.chargedAt || undefined, notes: form.notes || undefined,
      })
      setForm({ chargeCategoryId: '', dueAmount: '', chargedAt: todayIso(), notes: '' })
      notify.success('Charge recorded')
      loadCharges(selected.id)
    } catch (ex: any) {
      notify.error('Add failed', getErrorText(ex))
    } finally { setBusy(false) }
  }
  const remove = async (c: LoanCharge) => {
    const categoryName = c.category?.name || 'unknown'
    const ok = await notify.confirm({ title: `Delete this ${categoryName.toLowerCase()} charge?`, danger: true, okText: 'Delete' })
    if (!ok || !selected) return
    try { await loanChargesApi.remove(c.id); notify.success('Charge deleted'); loadCharges(selected.id) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Find Loan</div>
        <SearchSelect
          value={selected ? `${selected.code} — ${selected.customer?.fullName}` : ''}
          onChange={(_n, opt) => pickLoan(_n, opt)}
          options={loans}
          getLabel={(l: Loan) => `${l.code} — ${l.customer?.fullName}`}
          getSub={(l: Loan) => [l.customer?.nicNo, l.customer?.phone].filter(Boolean).join(' · ')}
          placeholder={loading ? 'Loading loans…' : 'Search by NIC, Loan ID, or customer name…'}
          disabled={loading}
        />
      </Card>

      {loadingLoan && <div style={{ padding: 30, textAlign: 'center', color: 'var(--tx-2)' }}>Loading loan…</div>}

      {selected && !loadingLoan && (
        <Card>
          <div className="eyebrow" style={{ marginBottom: 12 }}>Charges ({categories.map(c => c.name).join(' / ')}) — {selected.code}</div>
          <div className="row gap-2 wrap" style={{ marginBottom: 14 }}>
            <Select value={form.chargeCategoryId} onChange={(e) => setForm((s) => ({ ...s, chargeCategoryId: e.target.value ? Number(e.target.value) : '' }))} style={{ maxWidth: 140 }}>
              <option value="" disabled>Select Type...</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
            <Input type="number" placeholder="Amount" value={form.dueAmount} onChange={(e) => setForm((s) => ({ ...s, dueAmount: e.target.value }))} style={{ maxWidth: 130 }} />
            <Input type="date" value={form.chargedAt} onChange={(e) => setForm((s) => ({ ...s, chargedAt: e.target.value }))} style={{ maxWidth: 160 }} />
            <Input placeholder="Notes" value={form.notes} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} style={{ flex: 1, minWidth: 160 }} />
            <Btn variant="primary" size="sm" icon="plus" onClick={add} disabled={busy || !form.chargeCategoryId}>Add</Btn>
          </div>
          {chargesLoading ? <div className="t-2" style={{ fontSize: 12.5 }}>Loading…</div> :
          charges.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No charges recorded.</div> :
            <div className="col gap-2">
              {charges.map((c) => (
                <div key={c.id} className="row between wrap" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
                  <div>
                    <span style={{ fontSize: 12.5, fontWeight: 600 }}>{c.category?.name || `Category #${c.chargeCategoryId}`}</span>
                    <span className="t-2" style={{ fontSize: 12 }}> — {c.notes || 'no notes'} · {dateOnly(c.chargedAt)} · by {c.creator?.name}</span>
                  </div>
                  <div className="row gap-2" style={{ alignItems: 'center' }}>
                    <span className="t-2" style={{ fontSize: 12 }}>Due {money(c.dueAmount)} · Balance</span>
                    <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 600 }}>{money(num(c.dueAmount) - num(c.paidAmount))}</span>
                    <button className="mms-act" title="Delete" onClick={() => remove(c)}><Icon n="trash" s={14} /></button>
                  </div>
                </div>
              ))}
            </div>}
        </Card>
      )}
    </div>
  )
}

/* ============================== Reschedule ============================== */
type AddedCharge = { chargeCategoryId: number | ''; amount: string; notes: string }

function RescheduleSection({ notify, go }: { notify: ReturnType<typeof useNotify>; go: Go }) {
  const [loans, setLoans] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [selected, setSelected] = useState<Loan | null>(null)
  const [loadingLoan, setLoadingLoan] = useState(false)
  const [bucket, setBucket] = useState<PaymentAllocationSplit | null>(null)
  const [categories, setCategories] = useState<ChargeCategory[]>([])
  const [busy, setBusy] = useState(false)

  const [form, setForm] = useState({
    asOfDate: todayIso(),
    interestDeductionType: 'percent' as 'percent' | 'amount',
    interestDeductionValue: '0',
    defaultChargesAction: 'waive' as 'retain' | 'waive',
    interestRatePercent: '',
    interestMethod: 'flat' as InterestMethod,
    termMonths: '',
    installmentFrequency: 'monthly' as LoanFrequency,
    firstInstallmentDate: todayIso(),
    reason: '',
  })
  const [added, setAdded] = useState<AddedCharge[]>([])
  const [preview, setPreview] = useState<FinanceCalcResult | null>(null)

  useEffect(() => {
    loansApi.list().then((all) => setLoans(all.filter((l) => l.status === 'Active'))).catch(() => setLoans([])).finally(() => setLoading(false))
    chargeCategoriesApi.list().then((res) => setCategories(res.filter((c) => c.status === 'Active'))).catch(() => setCategories([]))
  }, [])

  const pickLoan = async (_n: string, opt?: Loan) => {
    setPreview(null); setBucket(null); setAdded([])
    if (!opt) { setSelected(null); return }
    setLoadingLoan(true)
    try {
      const full = await loansApi.get(opt.id)
      setSelected(full)
      setForm((s) => ({
        ...s,
        interestRatePercent: String(full.interestRatePercent ?? full.loanDetail?.loanType?.interestRatePercent ?? ''),
        interestMethod: (full.loanDetail?.interestMethod as InterestMethod) || 'flat',
        installmentFrequency: full.loanDetail?.installmentFrequency || 'monthly',
        termMonths: String(full.termMonths ?? ''),
      }))
      const b = await loanPaymentsApi.outstandingByBucket(opt.id, form.asOfDate)
      setBucket(b)
    } catch {
      setSelected(opt)
    } finally { setLoadingLoan(false) }
  }

  const addedTotal = useMemo(() => added.reduce((s, c) => s + num(c.amount), 0), [added])
  // Unpaid interest splits into earned (on installments already overdue) and
  // unearned future interest. Only the earned slice is carried + recognised;
  // future interest is always written off. The deduction waives extra earned
  // interest on top of that. Mirrors LoanController::reschedule().
  const unpaidInterest = num(bucket?.interest)
  const futureInterest = num(selected?.summary?.arrears?.futureInterest)
  const earnedInterest = Math.max(0, unpaidInterest - futureInterest)
  const deduction = Math.min(
    Math.max(0, form.interestDeductionType === 'percent' ? unpaidInterest * num(form.interestDeductionValue) / 100 : num(form.interestDeductionValue)),
    unpaidInterest,
  )
  const capitalisedInterest = Math.max(0, Math.min(unpaidInterest - deduction, earnedInterest))
  const interestWrittenOff = unpaidInterest - capitalisedInterest
  const retainedOd = form.defaultChargesAction === 'retain' ? num(bucket?.odCharge) : 0
  const newPrincipal = bucket
    ? num(bucket.capital) + capitalisedInterest + retainedOd + num(bucket.otherCharges) + addedTotal
    : 0

  // Server-side amortization preview — the app has no client-side schedule math.
  useEffect(() => {
    if (!bucket || newPrincipal <= 0 || !num(form.termMonths) || form.interestRatePercent === '') { setPreview(null); return }
    const t = setTimeout(() => {
      financeCalculatorApi.calculate({
        loanAmount: newPrincipal,
        termMonths: num(form.termMonths),
        interestMethod: form.interestMethod,
        interestRatePercent: num(form.interestRatePercent),
        ratePeriod: selected?.ratePeriod || 'monthly',
        installmentFrequency: form.installmentFrequency,
        firstInstallmentDate: form.firstInstallmentDate || undefined,
      }).then(setPreview).catch(() => setPreview(null))
    }, 400)
    return () => clearTimeout(t)
  }, [bucket, newPrincipal, form.termMonths, form.interestRatePercent, form.interestMethod, form.installmentFrequency, form.firstInstallmentDate, selected])

  const setF = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((s) => ({ ...s, [k]: v }))

  const submit = async () => {
    if (!selected || !bucket) return
    if (newPrincipal <= 0) { notify.error('Nothing to reschedule', 'The rescheduled amount works out to zero or less.'); return }
    if (!num(form.termMonths) || form.interestRatePercent === '' || !form.firstInstallmentDate) {
      notify.error('Missing details', 'Enter the new rate, term and first installment date.'); return
    }
    const badCharge = added.some((c) => !c.chargeCategoryId || num(c.amount) <= 0)
    if (badCharge) { notify.error('Check the added charges', 'Each line needs a category and an amount.'); return }

    const base = selected.code.replace(/-R\d+$/, '')
    const m = selected.code.match(/-R(\d+)$/)
    const newCode = `${base}-R${m ? Number(m[1]) + 1 : 1}`

    const ok = await notify.confirm({
      title: `Reschedule ${selected.code}?`,
      msg: `This closes ${selected.code} as Restructured and creates ${newCode} for ${money(newPrincipal)} over ${form.termMonths} month(s) at ${form.interestRatePercent}%. This cannot be undone.`,
      okText: 'Reschedule',
    })
    if (!ok) return

    setBusy(true)
    try {
      const body: RescheduleInput = {
        asOfDate: form.asOfDate || undefined,
        interestDeductionType: form.interestDeductionType,
        interestDeductionValue: form.interestDeductionValue || 0,
        defaultChargesAction: form.defaultChargesAction,
        charges: added.map((c) => ({ chargeCategoryId: c.chargeCategoryId as number, amount: c.amount, notes: c.notes || undefined })),
        interestRatePercent: form.interestRatePercent,
        interestMethod: form.interestMethod,
        termMonths: num(form.termMonths),
        installmentFrequency: form.installmentFrequency,
        firstInstallmentDate: form.firstInstallmentDate,
        reason: form.reason || undefined,
      }
      const created = await loansApi.reschedule(selected.id, body)
      notify.success('Loan rescheduled', `${selected.code} → ${created.code}`)
      go(`loan/active/${created.id}`)
    } catch (ex: any) {
      notify.error('Reschedule failed', getErrorText(ex))
    } finally { setBusy(false) }
  }

  const cell = (label: string, value: unknown, strong?: boolean) => (
    <div style={{ padding: '10px 12px', background: strong ? 'var(--ac-dim)' : 'var(--bg-0)', border: `1px solid ${strong ? 'var(--ac-bright)' : 'var(--line)'}`, borderRadius: 'var(--r-s)' }}>
      <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 4 }}>{label}</div>
      <div style={{ fontFamily: 'JetBrains Mono', fontWeight: 700, fontSize: 14, color: strong ? 'var(--ac-bright)' : undefined }}>{money(value)}</div>
    </div>
  )

  return (
    <div>
      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Find Loan</div>
        <SearchSelect
          value={selected ? `${selected.code} — ${selected.customer?.fullName}` : ''}
          onChange={(_n, opt) => pickLoan(_n, opt)}
          options={loans}
          getLabel={(l: Loan) => `${l.code} — ${l.customer?.fullName}`}
          getSub={(l: Loan) => [l.customer?.nicNo, l.customer?.phone].filter(Boolean).join(' · ')}
          placeholder={loading ? 'Loading loans…' : 'Search by NIC, Loan ID, or customer name…'}
          disabled={loading}
        />
      </Card>

      {loadingLoan && <div style={{ padding: 30, textAlign: 'center', color: 'var(--tx-2)' }}>Loading loan…</div>}

      {selected && !loadingLoan && bucket && (
        <>
          <Card style={{ marginBottom: 16 }}>
            <div className="eyebrow" style={{ marginBottom: 12 }}>Outstanding — {selected.code} (as of {form.asOfDate})</div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 12 }}>
              {cell('Capital', bucket.capital)}
              {cell('Overdue Interest (earned)', earnedInterest)}
              {cell('Future Interest (unearned)', futureInterest)}
              {cell('Default Charges (OD)', bucket.odCharge)}
              {cell('Other Charges', bucket.otherCharges)}
            </div>
          </Card>

          <Card style={{ marginBottom: 16 }}>
            <div className="eyebrow" style={{ marginBottom: 12 }}>Deductions</div>
            <div className="row gap-2 wrap" style={{ alignItems: 'flex-end' }}>
              <Field label="As-of date"><Input type="date" value={form.asOfDate} onChange={(e) => setF('asOfDate', e.target.value)} /></Field>
              <Field label="Reduce interest by">
                <Select value={form.interestDeductionType} onChange={(e) => setF('interestDeductionType', e.target.value as 'percent' | 'amount')}>
                  <option value="percent">Percentage</option>
                  <option value="amount">Fixed amount</option>
                </Select>
              </Field>
              <Field label={form.interestDeductionType === 'percent' ? 'Percent (%)' : 'Amount'}>
                <Input type="number" value={form.interestDeductionValue} onChange={(e) => setF('interestDeductionValue', e.target.value)} style={{ maxWidth: 130 }} />
              </Field>
              <Field label="Default charges (OD)">
                <Select value={form.defaultChargesAction} onChange={(e) => setF('defaultChargesAction', e.target.value as 'retain' | 'waive')}>
                  <option value="waive">Waive</option>
                  <option value="retain">Retain (roll in)</option>
                </Select>
              </Field>
            </div>
            <div className="t-2" style={{ fontSize: 11.5, marginTop: 8 }}>
              Future interest ({money(futureInterest)}) is always written off. This reduction waives a further <strong>{money(Math.max(0, deduction - futureInterest))}</strong> of earned interest.
              Carried forward: <strong>{money(capitalisedInterest)}</strong> · written off: <strong>{money(interestWrittenOff)}</strong>.
            </div>
          </Card>

          <Card style={{ marginBottom: 16 }}>
            <div className="row between" style={{ marginBottom: 12 }}>
              <div className="eyebrow">Add Charges</div>
              <Btn size="sm" variant="solid" icon="plus" onClick={() => setAdded((a) => [...a, { chargeCategoryId: '', amount: '', notes: '' }])}>Add line</Btn>
            </div>
            {added.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No charges added.</div> :
              <div className="col gap-2">
                {added.map((c, i) => (
                  <div key={i} className="row gap-2 wrap" style={{ alignItems: 'center' }}>
                    <Select value={c.chargeCategoryId} onChange={(e) => setAdded((a) => a.map((x, j) => j === i ? { ...x, chargeCategoryId: e.target.value ? Number(e.target.value) : '' } : x))} style={{ maxWidth: 150 }}>
                      <option value="">Type…</option>
                      {categories.map((cat) => <option key={cat.id} value={cat.id}>{cat.name}</option>)}
                    </Select>
                    <Input type="number" placeholder="Amount" value={c.amount} onChange={(e) => setAdded((a) => a.map((x, j) => j === i ? { ...x, amount: e.target.value } : x))} style={{ maxWidth: 120 }} />
                    <Input placeholder="Notes" value={c.notes} onChange={(e) => setAdded((a) => a.map((x, j) => j === i ? { ...x, notes: e.target.value } : x))} style={{ flex: 1, minWidth: 140 }} />
                    <button className="mms-act" title="Remove" onClick={() => setAdded((a) => a.filter((_, j) => j !== i))}><Icon n="trash" s={14} /></button>
                  </div>
                ))}
              </div>}
          </Card>

          <Card style={{ marginBottom: 16 }}>
            <div className="eyebrow" style={{ marginBottom: 12 }}>New Contract</div>
            <div className="row gap-2 wrap" style={{ alignItems: 'flex-end' }}>
              <Field label="Interest rate (%)"><Input type="number" value={form.interestRatePercent} onChange={(e) => setF('interestRatePercent', e.target.value)} style={{ maxWidth: 120 }} /></Field>
              <Field label="Interest method">
                <Select value={form.interestMethod} onChange={(e) => setF('interestMethod', e.target.value as InterestMethod)}>
                  <option value="flat">Flat</option>
                  <option value="reducing_balance">Reducing balance</option>
                </Select>
              </Field>
              <Field label="Term (months)"><Input type="number" value={form.termMonths} onChange={(e) => setF('termMonths', e.target.value)} style={{ maxWidth: 110 }} /></Field>
              <Field label="Frequency">
                <Select value={form.installmentFrequency} onChange={(e) => setF('installmentFrequency', e.target.value as LoanFrequency)}>
                  <option value="monthly">Monthly</option>
                  <option value="weekly">Weekly</option>
                  <option value="daily">Daily</option>
                </Select>
              </Field>
              <Field label="First installment"><Input type="date" value={form.firstInstallmentDate} onChange={(e) => setF('firstInstallmentDate', e.target.value)} /></Field>
            </div>
            <Field label="Reason" style={{ marginTop: 10 }}><Input value={form.reason} onChange={(e) => setF('reason', e.target.value)} placeholder="Why is this loan being rescheduled?" /></Field>
          </Card>

          <Card>
            <div className="eyebrow" style={{ marginBottom: 12 }}>New Loan Preview</div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 12, marginBottom: 12 }}>
              {cell('Capital', bucket.capital)}
              {cell('+ Interest capitalised', capitalisedInterest)}
              {cell('+ Retained OD', retainedOd)}
              {cell('+ Other charges', bucket.otherCharges)}
              {cell('+ Added charges', addedTotal)}
              {cell('New Principal', newPrincipal, true)}
              {cell('Interest written off', interestWrittenOff)}
              {preview && cell('Installment', preview.monthlyRental)}
              {preview && cell('Total interest', preview.totalInterest)}
              {preview && cell('Total payable', preview.totalPayable, true)}
            </div>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <Btn variant="primary" icon="check" disabled={busy || newPrincipal <= 0} onClick={submit}>{busy ? 'Rescheduling…' : 'Reschedule Loan'}</Btn>
            </div>
          </Card>
        </>
      )}
    </div>
  )
}

/* ================================ Screen ================================= */
const TABS = [
  { id: 'skip', label: 'Installment Skip', icon: 'clock' },
  { id: 'waivers', label: 'Waivers', icon: 'refund' },
  { id: 'charges', label: 'Charges', icon: 'tag' },
  { id: 'reschedule', label: 'Loan Reschedule', icon: 'swap' },
] as const

export function LoanAdjustmentsScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [tab, setTab] = useState<(typeof TABS)[number]['id']>('skip')

  // Belt-and-suspenders — the route itself is only reachable by admins (see
  // lib/permissions.ts canAccessRoute()), but a stale session shouldn't render this.
  if (!isAdminRole(me?.role)) {
    return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>Admin privileges required.</div>
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Loan Adjustments" icon="shield" sub="Installment skip, waivers, and charges — admin only." />
      <div className="row gap-2" style={{ marginBottom: 16, overflowX: 'auto', paddingBottom: 4, borderBottom: '1px solid var(--line)' }}>
        {TABS.map((t) => (
          <Btn key={t.id} size="sm" variant="ghost" active={tab === t.id} icon={t.icon} onClick={() => setTab(t.id)} style={{ flexShrink: 0 }}>{t.label}</Btn>
        ))}
      </div>
      {tab === 'skip' && <SkipSection notify={notify} />}
      {tab === 'waivers' && <WaiversSection notify={notify} />}
      {tab === 'charges' && <ChargesSection notify={notify} />}
      {tab === 'reschedule' && <RescheduleSection notify={notify} go={go} />}
    </div>
  )
}
