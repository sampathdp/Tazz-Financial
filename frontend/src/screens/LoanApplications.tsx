/* Tazz Investment & Credit — Loan Applications (intake → approve/reject workflow) */
import { useEffect, useMemo, useRef, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, Table, Td, Modal, inputStyle, ErrorBanner } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  loansApi, customersApi, customerLoansApi, loanTypesApi, branchesApi, api, loanCollateralDocumentsApi,
  type Loan, type LoanStatus, type LoanType, type Customer, type LoanFrequency, type CustomerTitle,
  type LoanGuarantorInput, type LoanCollateralInput, type LoanCollateralDocument, type FacilityInsuranceDetail, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import { DEFAULT_REFERENCE_FORMAT, previewReferenceNumber } from '../lib/referenceNumber'
import { LoanDetail } from './loan/LoanDetail'
import type { Go } from './types'

const FREQ_OPTIONS: { value: LoanFrequency; label: string }[] = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
]
const COLLATERAL_TYPES = ['Vehicle', 'Jewelry', 'Property', 'Other']
const VEHICLE_TYPES = ['Car', 'Motorbike', 'Three-Wheeler', 'Van', 'Lorry', 'Bus', 'Other']
const todayIso = () => new Date().toISOString().slice(0, 10)

const statusTone: Record<LoanStatus, 'green' | 'red' | 'amber' | 'blue' | 'neutral'> = {
  Pending: 'amber', Approved: 'blue', Rejected: 'red', Active: 'green', Closed: 'neutral', WrittenOff: 'red', Restructured: 'amber',
}
const custStatusTone: Record<string, 'green' | 'red' | 'amber'> = { Active: 'green', Inactive: 'red', Blacklisted: 'amber' }
const TITLE_OPTIONS: CustomerTitle[] = ['Mr.', 'Mrs.', 'Miss', 'Ms.', 'Dr.', 'Rev.']
const blankQuickAdd = () => ({ title: '' as CustomerTitle | '', surname: '', initials: '', nameDenotedByInitials: '', branchId: undefined as number | undefined, nicNo: '', phone: '' })

function loanTypePath(rows: LoanType[], id: number | null | undefined): string {
  if (!id) return ''
  // Keyed by String(id) — some hosts return integer columns as JSON strings,
  // which breaks a direct id === parentId comparison inconsistently by
  // environment. See the identical fix/comment in LoanTypes.tsx.
  const byId = new Map(rows.map((r) => [String(r.id), r]))
  const chain: string[] = []
  let cur = byId.get(String(id))
  while (cur) { chain.unshift(cur.name); cur = cur.parentId ? byId.get(String(cur.parentId)) : undefined }
  return chain.join(' › ')
}

interface FormState {
  customerId?: number
  loanTypeId?: number
  branchId?: number
  principalAmount?: number | string
  downPayment?: number | string
  interestRatePercent?: number | string
  termMonths?: number
  installmentFrequency?: LoanFrequency
  purpose?: string
  applicationDate?: string
  referenceName?: string
  referenceIdNo?: string
  referenceAddress?: string
  loanOfficerId?: number
  vehicleNo?: string
  insurance?: Partial<FacilityInsuranceDetail>
  guarantors: LoanGuarantorInput[]
  collateral: LoanCollateralInput[]
}
const blankForm = (): FormState => ({ installmentFrequency: 'monthly', applicationDate: todayIso(), guarantors: [], collateral: [] })
const blankInsurance = (): Partial<FacilityInsuranceDetail> => ({})

export function LoanApplicationsScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<Loan[]>([])
  const [customers, setCustomers] = useState<Customer[]>([])
  const [branches, setBranches] = useState<{ id: number; name: string; code: string }[]>([])
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [officers, setOfficers] = useState<{ id: number; name: string }[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<LoanStatus | 'All'>('All')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const [mode, setMode] = useState<'list' | 'form' | 'view'>('list')
  const [editing, setEditing] = useState<Loan | null>(null)
  const [viewing, setViewing] = useState<Loan | null>(null) // read-only detail (non-pending)
  const [form, setForm] = useState<FormState>(blankForm())
  const [backDated, setBackDated] = useState(false)
  // Newly-picked, not-yet-uploaded attachments per collateral row (index-aligned with form.collateral).
  // Uploaded once the loan/collateral rows are saved and have real ids.
  const [collateralFiles, setCollateralFiles] = useState<File[][]>([])
  const collateralFileInputs = useRef<Record<number, HTMLInputElement | null>>({})

  const [quickAdd, setQuickAdd] = useState(false)
  const [quickAddForm, setQuickAddForm] = useState(blankQuickAdd())
  const [quickAddBusy, setQuickAddBusy] = useState(false)
  const [quickAddErr, setQuickAddErr] = useState('')

  const refresh = () => {
    setLoading(true)
    loansApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => {
    refresh()
    customersApi.list().then(setCustomers).catch(() => setCustomers([]))
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    loanTypesApi.list().then(setLoanTypes).catch(() => setLoanTypes([]))
    api.users.marketingExecutives().then(setOfficers).catch(() => setOfficers([]))
  }, [])

  const openQuickAdd = () => { setQuickAddForm(blankQuickAdd()); setQuickAddErr(''); setQuickAdd(true) }
  const saveQuickAdd = async () => {
    if (!quickAddForm.title || !quickAddForm.surname || !quickAddForm.initials) { setQuickAddErr('Title, Surname and Initial are required.'); return }
    if (!quickAddForm.branchId) { setQuickAddErr('Branch is required.'); return }
    setQuickAddBusy(true); setQuickAddErr('')
    try {
      const created = await customersApi.create({
        title: quickAddForm.title, surname: quickAddForm.surname, initials: quickAddForm.initials,
        nameDenotedByInitials: quickAddForm.nameDenotedByInitials || undefined, branchId: quickAddForm.branchId,
        nicNo: quickAddForm.nicNo || undefined, phone: quickAddForm.phone || undefined, status: 'Active',
      })
      setCustomers((cs) => [created, ...cs])
      setForm((s) => ({ ...s, customerId: created.id }))
      setQuickAdd(false)
      notify.success('Customer added', created.fullName)
    } catch (ex: any) {
      setQuickAddErr(getErrorText(ex, 'Save failed'))
    } finally { setQuickAddBusy(false) }
  }

  const products = useMemo(() => loanTypes.filter((t) => t.isProduct), [loanTypes])
  const selectedType = loanTypes.find((t) => t.id === form.loanTypeId)
  const selectedCustomer = customers.find((c) => c.id === form.customerId)
  const needsInsurance = selectedType?.category === 'Leasing' || selectedType?.category === 'Mortgage'

  const openCreate = () => { setEditing(null); setViewing(null); setForm(blankForm()); setCollateralFiles([]); setBackDated(false); setErr(''); setMode('form') }
  const openRow = async (row: Loan) => {
    // The list only loads summary relations — fetch full detail (guarantors,
    // collateral, officer/applier/approver names) before showing the form or view.
    const loan = await loansApi.get(row.id).catch(() => row)
    if (loan.status === 'Pending') {
      setEditing(loan); setViewing(null)
      const applicationDate = loan.loanDetail?.applicationDate || (loan.createdAt || '').slice(0, 10) || todayIso()
      setForm({
        customerId: loan.customerId, loanTypeId: loan.loanDetail?.loanTypeId, branchId: loan.branchId,
        principalAmount: loan.principalAmount, downPayment: loan.loanDetail?.downPayment ?? undefined,
        interestRatePercent: loan.interestRatePercent, termMonths: loan.termMonths,
        installmentFrequency: loan.loanDetail?.installmentFrequency, purpose: loan.loanDetail?.purpose || '',
        applicationDate,
        referenceName: loan.loanDetail?.referenceName || '', referenceIdNo: loan.loanDetail?.referenceIdNo || '', referenceAddress: loan.loanDetail?.referenceAddress || '',
        loanOfficerId: loan.loanDetail?.loanOfficerId || undefined,
        vehicleNo: loan.loanDetail?.vehicleNo || '',
        insurance: loan.insurance || blankInsurance(),
        // vehicleDetail is a read-only nested relation — flatten it back onto
        // the row's flat write fields, which is what the collateral form edits.
        guarantors: loan.guarantors || [], collateral: (loan.collateral || []).map((c) => ({ ...c, ...(c.vehicleDetail || {}) })),
      })
      setCollateralFiles((loan.collateral || []).map(() => []))
      setBackDated(applicationDate !== todayIso())
      setErr(''); setMode('form')
    } else {
      setViewing(loan); setEditing(null); setMode('view')
    }
  }
  const back = () => { setMode('list'); setEditing(null); setViewing(null) }

  const save = async () => {
    if (!form.customerId) { setErr('Select a customer.'); return }
    if (!form.loanTypeId) { setErr('Select a loan product.'); return }
    if (!form.principalAmount) { setErr('Enter the loan amount.'); return }
    if (!form.termMonths) { setErr('Enter the term (months).'); return }
    if (Number(form.downPayment || 0) >= Number(form.principalAmount)) { setErr('Down payment must be less than the loan amount.'); return }
    if (selectedType?.category === 'Leasing' && !form.vehicleNo) { setErr('Vehicle No is required for a Leasing loan.'); return }
    if (needsInsurance && (!form.insurance?.insuranceCompany || !form.insurance?.policyNo || !form.insurance?.amount || !form.insurance?.contactPerson || !form.insurance?.coverage)) {
      setErr('Insurance details are required for a Leasing or Mortgage loan.'); return
    }
    const customer = customers.find((c) => c.id === form.customerId)
    const body = {
      customerId: form.customerId,
      loanTypeId: form.loanTypeId,
      branchId: customer?.branchId,
      principalAmount: form.principalAmount,
      downPayment: form.downPayment || 0,
      interestRatePercent: form.interestRatePercent,
      termMonths: form.termMonths,
      installmentFrequency: form.installmentFrequency,
      purpose: form.purpose || null,
      applicationDate: backDated ? form.applicationDate : todayIso(),
      referenceName: form.referenceName || null,
      referenceIdNo: form.referenceIdNo || null,
      referenceAddress: form.referenceAddress || null,
      loanOfficerId: form.loanOfficerId || null,
      vehicleNo: selectedType?.category === 'Leasing' ? (form.vehicleNo || null) : null,
      insurance: needsInsurance ? form.insurance : undefined,
      guarantors: form.guarantors.map((g) => ({ guarantorCustomerId: g.guarantorCustomerId, guaranteedAmount: g.guaranteedAmount })),
      collateral: form.collateral,
    }
    setBusy(true); setErr('')
    try {
      const saved = editing ? await loansApi.update(editing.id, body as any) : await loansApi.create(body as any)
      // Collateral rows only get a real id once the loan is saved, so any attachments
      // picked in this session are staged in collateralFiles and uploaded now, matched
      // positionally against the collateral rows the save just returned.
      const savedCollateral = saved.collateral || []
      let uploadFailures = 0
      for (let i = 0; i < collateralFiles.length; i++) {
        const files = collateralFiles[i]
        const row = savedCollateral[i]
        if (!files?.length || !row?.id) continue
        for (const file of files) {
          try { await loanCollateralDocumentsApi.upload(saved.id, row.id, file) }
          catch { uploadFailures++ }
        }
      }
      back(); refresh()
      notify.success(editing ? 'Application updated' : 'Application submitted', customer?.fullName)
      if (uploadFailures) notify.error('Some attachments failed to upload', `${uploadFailures} file(s) — re-attach them from the application.`)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (loan: Loan) => {
    const ok = await notify.confirm({ title: 'Withdraw this application?', msg: 'This action cannot be undone.', danger: true, okText: 'Withdraw' })
    if (!ok) return
    try { await loansApi.remove(loan.id); refresh(); notify.success('Application withdrawn', loan.code) }
    catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }

  const approve = async (loan: Loan) => {
    const ok = await notify.confirm({ title: `Approve loan ${loan.code}?`, msg: `${loan.customer?.fullName} — ${Number(loan.principalAmount).toLocaleString()}`, okText: 'Approve' })
    if (!ok) return
    try { await loansApi.approve(loan.id); refresh(); notify.success('Loan approved', loan.code) }
    catch (ex: any) { notify.error('Approve failed', getErrorText(ex)) }
  }

  const reject = async (loan: Loan) => {
    const reason = await notify.prompt({ title: `Reject loan ${loan.code}?`, msg: 'Give a reason — this is shown to branch staff.', placeholder: 'Reason for rejection', required: true, danger: true, okText: 'Reject' })
    if (!reason) return
    try { await loansApi.reject(loan.id, reason); refresh(); notify.success('Loan rejected', loan.code) }
    catch (ex: any) { notify.error('Reject failed', getErrorText(ex)) }
  }

  const addGuarantor = () => setForm((s) => ({ ...s, guarantors: [...s.guarantors, { guarantorCustomerId: 0, guaranteedAmount: '' }] }))
  const setGuarantor = (i: number, patch: Partial<LoanGuarantorInput>) =>
    setForm((s) => ({ ...s, guarantors: s.guarantors.map((g, gi) => (gi === i ? { ...g, ...patch } : g)) }))
  const removeGuarantor = (i: number) => setForm((s) => ({ ...s, guarantors: s.guarantors.filter((_, gi) => gi !== i) }))

  const addCollateral = () => {
    setForm((s) => ({ ...s, collateral: [...s.collateral, { collateralType: 'Vehicle', description: '', estimatedValue: '' }] }))
    setCollateralFiles((s) => [...s, []])
  }
  const setCollateral = (i: number, patch: Partial<LoanCollateralInput>) =>
    setForm((s) => ({ ...s, collateral: s.collateral.map((c, ci) => (ci === i ? { ...c, ...patch } : c)) }))
  const removeCollateral = (i: number) => {
    setForm((s) => ({ ...s, collateral: s.collateral.filter((_, ci) => ci !== i) }))
    setCollateralFiles((s) => s.filter((_, ci) => ci !== i))
  }

  const stageCollateralFiles = (i: number, fileList: FileList | null) => {
    if (!fileList?.length) return
    setCollateralFiles((s) => s.map((files, ci) => (ci === i ? [...files, ...Array.from(fileList)] : files)))
  }
  const unstageCollateralFile = (i: number, fi: number) =>
    setCollateralFiles((s) => s.map((files, ci) => (ci === i ? files.filter((_, fx) => fx !== fi) : files)))

  // Deleting an already-uploaded attachment happens immediately (it has a real id).
  // Newly-picked files aren't uploaded until save() — see collateralFiles above.
  const removeCollateralDoc = async (i: number, doc: LoanCollateralDocument) => {
    const c = form.collateral[i]
    if (!editing || !c.id) return
    const ok = await notify.confirm({ title: 'Delete this attachment?', danger: true, okText: 'Delete' })
    if (!ok) return
    try {
      await loanCollateralDocumentsApi.remove(editing.id, c.id, doc.id)
      setCollateral(i, { documents: (c.documents || []).filter((d) => d.id !== doc.id) })
    } catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }
  const viewCollateralDoc = async (i: number, doc: LoanCollateralDocument) => {
    const c = form.collateral[i]
    if (!editing || !c.id) return
    try {
      const blob = await loanCollateralDocumentsApi.download(editing.id, c.id, doc.id)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch { notify.error('Could not open attachment') }
  }

  const q = search.trim().toLowerCase()
  const filtered = rows.filter((l) => {
    if (statusFilter !== 'All' && l.status !== statusFilter) return false
    if (!q) return true
    return [l.code, l.customer?.fullName, l.customer?.code, l.loanDetail?.loanType?.name, l.branch?.name].some((v) => (v || '').toLowerCase().includes(q))
  })
  const counts = useMemo(() => {
    const c: Record<string, number> = { All: rows.length }
    for (const l of rows) c[l.status] = (c[l.status] || 0) + 1
    return c
  }, [rows])

  if (mode === 'view' && viewing) {
    const reloadViewing = async () => setViewing(await loansApi.get(viewing.id))
    return (
      <div>
        <PageHead crumbs="Loan Management / Loan Applications" title={`Loan ${viewing.code}`} icon="doc"
          sub={`${viewing.customer?.fullName} — ${viewing.loanDetail?.loanType?.name}`}
          actions={<Btn variant="solid" onClick={back}>← Back to Applications</Btn>} />
        <LoanDetail loan={viewing} me={me} onChanged={reloadViewing} go={go} context="applications" />
      </div>
    )
  }

  if (mode === 'form') {
    return (
      <div>
        <PageHead crumbs="Loan Management / Loan Applications" title={editing ? 'Edit Application' : 'New Loan Application'} icon="doc"
          sub={editing ? editing.code : 'Pick a customer and product, enter terms, attach guarantors/collateral.'}
          actions={<>
            <Btn variant="solid" onClick={back}>Cancel</Btn>
            <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Submit Application')}</Btn>
          </>} />

        <ErrorBanner error={err} />

        <div className="row gap-2" style={{ alignItems: 'center', marginBottom: 16 }}>
          <span className="t-2" style={{ fontSize: 12 }}>{editing ? 'Loan ID' : 'Loan ID (preview — assigned on submit)'}</span>
          <Badge tone={editing ? 'blue' : 'neutral'}>
            {editing ? editing.code : (selectedType
              ? previewReferenceNumber(selectedType.referenceFormat || DEFAULT_REFERENCE_FORMAT, selectedCustomer?.branch?.code || 'BR', selectedType.code)
              : '—')}
          </Badge>
        </div>

        <Card style={{ marginBottom: 16 }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Customer" full>
              <div className="row gap-2">
                <div style={{ flex: 1 }}>
                  <SearchSelect
                    value={selectedCustomer ? `${selectedCustomer.fullName} (${selectedCustomer.code})` : ''}
                    onChange={(_n, opt) => setForm((s) => ({ ...s, customerId: opt?.id }))}
                    options={customers}
                    getLabel={(c) => `${c.fullName} (${c.code})`}
                    getSub={(c) => c.nicNo || c.phone || ''}
                    placeholder="Search customer by name, NIC, phone…"
                  />
                </div>
                <Btn variant="solid" icon="plus" onClick={openQuickAdd}>New Customer</Btn>
              </div>
            </Field>

            {quickAdd && (
              <div style={{ gridColumn: '1/-1', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, padding: 14, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
                <ErrorBanner error={quickAddErr} style={{ gridColumn: '1/-1', marginBottom: 0, padding: '6px 10px', fontSize: 12 }} />
                <Field label="Surname"><Input value={quickAddForm.surname} onChange={(e) => setQuickAddForm((s) => ({ ...s, surname: e.target.value }))} /></Field>
                <Field label="Initial in Full without Surname"><Input value={quickAddForm.nameDenotedByInitials} onChange={(e) => setQuickAddForm((s) => ({ ...s, nameDenotedByInitials: e.target.value }))} /></Field>
                <Field label="Initial"><Input value={quickAddForm.initials} onChange={(e) => setQuickAddForm((s) => ({ ...s, initials: e.target.value }))} placeholder="e.g. J.K." /></Field>
                <Field label="Title"><Select value={quickAddForm.title} onChange={(e) => setQuickAddForm((s) => ({ ...s, title: e.target.value as CustomerTitle }))}>
                  <option value="">—</option>{TITLE_OPTIONS.map((t) => <option key={t} value={t}>{t}</option>)}
                </Select></Field>
                <Field label="Branch"><Select value={quickAddForm.branchId ?? ''} onChange={(e) => setQuickAddForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
                  <option value="">—</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </Select></Field>
                <Field label="NIC Number"><Input value={quickAddForm.nicNo} onChange={(e) => setQuickAddForm((s) => ({ ...s, nicNo: e.target.value }))} /></Field>
                <Field label="Phone"><Input value={quickAddForm.phone} onChange={(e) => setQuickAddForm((s) => ({ ...s, phone: e.target.value }))} /></Field>
                <div className="row gap-2" style={{ gridColumn: '1/-1' }}>
                  <Btn size="sm" variant="primary" icon="check" onClick={saveQuickAdd} disabled={quickAddBusy}>{quickAddBusy ? 'Saving…' : 'Save Customer'}</Btn>
                  <Btn size="sm" variant="plain" onClick={() => setQuickAdd(false)}>Cancel</Btn>
                </div>
                <div className="t-2" style={{ gridColumn: '1/-1', fontSize: 11.5 }}>Just the essentials — open Customer Management to fill in the rest later.</div>
              </div>
            )}

            {selectedCustomer && <div style={{ gridColumn: '1/-1' }}><CustomerDetailCard c={selectedCustomer} /></div>}
            <Field label="Loan Product" full>
              <SearchSelect
                value={selectedType ? loanTypePath(loanTypes, selectedType.id) : ''}
                onChange={(_n, opt) => setForm((s) => ({
                  ...s, loanTypeId: opt?.id,
                  installmentFrequency: opt?.frequency || s.installmentFrequency,
                  interestRatePercent: opt?.interestRatePercent ?? s.interestRatePercent,
                }))}
                options={products}
                getLabel={(t) => loanTypePath(loanTypes, t.id)}
                getSub={(t) => t.code}
                placeholder="Search loan product…"
              />
              {selectedType && (
                <div className="t-2" style={{ fontSize: 11.5, marginTop: 4 }}>
                  amount {Number(selectedType.minAmount).toLocaleString()}–{Number(selectedType.maxAmount).toLocaleString()} · term {selectedType.minTermMonths}–{selectedType.maxTermMonths}mo
                </div>
              )}
            </Field>
            <Field label="Loan Amount"><Input type="number" value={form.principalAmount ?? ''} onChange={(e) => setForm((s) => ({ ...s, principalAmount: e.target.value as any }))} /></Field>
            <Field label="Down Payment">
              <Input type="number" value={form.downPayment ?? ''} onChange={(e) => setForm((s) => ({ ...s, downPayment: e.target.value as any }))} />
              {!!form.principalAmount && (
                <div className="t-2" style={{ fontSize: 11.5, marginTop: 4 }}>
                  Amount financed: {Math.max(0, Number(form.principalAmount || 0) - Number(form.downPayment || 0)).toLocaleString()}
                </div>
              )}
            </Field>
            <Field label="Interest Rate %">
              <Input type="number" step="0.001" value={form.interestRatePercent ?? ''} onChange={(e) => setForm((s) => ({ ...s, interestRatePercent: e.target.value as any }))} />
              {selectedType && (
                <div className="t-2" style={{ fontSize: 11.5, marginTop: 4 }}>
                  {selectedType.ratePeriod === 'annual' ? 'per annum' : 'per month'} · product default {selectedType.interestRatePercent}%
                </div>
              )}
            </Field>
            <Field label="Term (months)"><Input type="number" value={form.termMonths ?? ''} onChange={(e) => setForm((s) => ({ ...s, termMonths: Number(e.target.value) }))} /></Field>
            <Field label="Repayment Frequency"><Select value={form.installmentFrequency || 'monthly'} onChange={(e) => setForm((s) => ({ ...s, installmentFrequency: e.target.value as any }))}>
              {FREQ_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </Select></Field>
            {selectedType?.category === 'Leasing' && (
              <Field label="Vehicle No"><Input value={form.vehicleNo || ''} onChange={(e) => setForm((s) => ({ ...s, vehicleNo: e.target.value }))} placeholder="e.g. ABC-1234" /></Field>
            )}
            <Field label="Marketing Executive"><Select value={form.loanOfficerId ?? ''} onChange={(e) => setForm((s) => ({ ...s, loanOfficerId: Number(e.target.value) || undefined }))}>
              <option value="">—</option>{officers.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </Select></Field>
            <Field label="Application Date">
              <Input type="date" value={form.applicationDate || todayIso()} max={todayIso()} disabled={!backDated}
                onChange={(e) => setForm((s) => ({ ...s, applicationDate: e.target.value }))} />
              <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 6 }}>
                <input type="checkbox" checked={backDated} onChange={(e) => {
                  const checked = e.target.checked
                  setBackDated(checked)
                  if (!checked) setForm((s) => ({ ...s, applicationDate: todayIso() }))
                }} />
                <span className="t-2" style={{ fontSize: 11.5 }}>Back Dated Entry</span>
              </label>
            </Field>
            <Field label="Purpose" full><textarea value={form.purpose || ''} onChange={(e) => setForm((s) => ({ ...s, purpose: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
          </div>
        </Card>

        {needsInsurance && (
          <Card style={{ marginBottom: 16 }}>
            <div className="eyebrow" style={{ marginBottom: 10 }}>Insurance Policy</div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <Field label="Insurance Company"><Input value={form.insurance?.insuranceCompany || ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, insuranceCompany: e.target.value } }))} /></Field>
              <Field label="Policy No"><Input value={form.insurance?.policyNo || ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, policyNo: e.target.value } }))} /></Field>
              <Field label="Amount"><Input type="number" value={form.insurance?.amount ?? ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, amount: e.target.value } }))} /></Field>
              <Field label="Contact Person"><Input value={form.insurance?.contactPerson || ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, contactPerson: e.target.value } }))} /></Field>
              <Field label="Coverage"><Input value={form.insurance?.coverage || ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, coverage: e.target.value } }))} placeholder="e.g. Comprehensive" /></Field>
              <Field label="Remark" full><textarea value={form.insurance?.remarks || ''} onChange={(e) => setForm((s) => ({ ...s, insurance: { ...s.insurance, remarks: e.target.value } }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
            </div>
          </Card>
        )}

        <Card style={{ marginBottom: 16 }}>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Reference Person <span className="t-2" style={{ fontWeight: 400, textTransform: 'none' }}>— optional</span></div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Name"><Input value={form.referenceName || ''} onChange={(e) => setForm((s) => ({ ...s, referenceName: e.target.value }))} /></Field>
            <Field label="NIC / Other ID No"><Input value={form.referenceIdNo || ''} onChange={(e) => setForm((s) => ({ ...s, referenceIdNo: e.target.value }))} /></Field>
            <Field label="Address" full><textarea value={form.referenceAddress || ''} onChange={(e) => setForm((s) => ({ ...s, referenceAddress: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
          </div>
        </Card>

        <Card style={{ marginBottom: 16 }}>
          <div className="row between" style={{ marginBottom: 10 }}>
            <div className="eyebrow">Guarantors <span className="t-2" style={{ fontWeight: 400, textTransform: 'none' }}>— optional</span></div>
            <Btn size="sm" variant="solid" icon="plus" onClick={addGuarantor}>Add Guarantor</Btn>
          </div>
          {form.guarantors.length === 0 && <div className="t-2" style={{ fontSize: 12.5 }}>No guarantors added.</div>}
          {form.guarantors.map((g, i) => (
            <div key={i} className="row gap-2" style={{ marginBottom: 10, alignItems: 'flex-start' }}>
              <div style={{ flex: 2 }}>
                <SearchSelect
                  value={g.guarantorCustomerId ? `${customers.find((c) => c.id === g.guarantorCustomerId)?.fullName || ''}` : ''}
                  onChange={(_n, opt) => setGuarantor(i, { guarantorCustomerId: opt?.id })}
                  options={customers.filter((c) => c.id !== form.customerId)}
                  getLabel={(c) => `${c.fullName} (${c.code})`}
                  getSub={(c) => c.nicNo || c.phone || ''}
                  placeholder="Search customer…"
                />
              </div>
              <div style={{ flex: 1 }}><Input type="number" placeholder="Guaranteed amount" value={g.guaranteedAmount ?? ''} onChange={(e) => setGuarantor(i, { guaranteedAmount: e.target.value as any })} /></div>
              <button className="mms-act" title="Remove" onClick={() => removeGuarantor(i)} style={{ marginTop: 6 }}><Icon n="trash" s={15} /></button>
            </div>
          ))}
        </Card>

        <Card>
          <div className="row between" style={{ marginBottom: 10 }}>
            <div className="eyebrow">Collateral / Security <span className="t-2" style={{ fontWeight: 400, textTransform: 'none' }}>— optional</span></div>
            <Btn size="sm" variant="solid" icon="plus" onClick={addCollateral}>Add Collateral</Btn>
          </div>
          {form.collateral.length === 0 && <div className="t-2" style={{ fontSize: 12.5 }}>No collateral added.</div>}
          {form.collateral.map((c, i) => (
            <div key={i} style={{ marginBottom: 12, padding: 12, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
              <div className="row gap-2" style={{ alignItems: 'flex-start' }}>
                <div style={{ width: 140 }}><Select value={c.collateralType || ''} onChange={(e) => setCollateral(i, { collateralType: e.target.value as any })}>
                  {COLLATERAL_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                </Select></div>
                <div style={{ flex: 2 }}><Input placeholder="Description" value={c.description} onChange={(e) => setCollateral(i, { description: e.target.value })} /></div>
                <div style={{ flex: 1 }}><Input type="number" placeholder="Est. value" value={c.estimatedValue ?? ''} onChange={(e) => setCollateral(i, { estimatedValue: e.target.value as any })} /></div>
                <button className="mms-act" title="Remove" onClick={() => removeCollateral(i)} style={{ marginTop: 6 }}><Icon n="trash" s={15} /></button>
              </div>

              {c.collateralType === 'Vehicle' && (
                <div className="row gap-2 wrap" style={{ marginTop: 8 }}>
                  <div style={{ width: 140 }}><Select value={c.vehicleType || ''} onChange={(e) => setCollateral(i, { vehicleType: e.target.value })}>
                    <option value="">Vehicle type…</option>
                    {VEHICLE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </Select></div>
                  <div style={{ flex: 1, minWidth: 140 }}><Input placeholder="Make / Model" value={c.makeModel || ''} onChange={(e) => setCollateral(i, { makeModel: e.target.value })} /></div>
                  <div style={{ flex: 1, minWidth: 130 }}><Input placeholder="Registration No" value={c.registrationNo || ''} onChange={(e) => setCollateral(i, { registrationNo: e.target.value })} /></div>
                  <div style={{ flex: 1, minWidth: 130 }}><Input placeholder="Chassis No" value={c.chassisNo || ''} onChange={(e) => setCollateral(i, { chassisNo: e.target.value })} /></div>
                  <div style={{ flex: 1, minWidth: 130 }}><Input placeholder="Engine No" value={c.engineNo || ''} onChange={(e) => setCollateral(i, { engineNo: e.target.value })} /></div>
                  <div style={{ width: 110 }}><Input type="number" placeholder="Year" value={c.yearOfManufacture ?? ''} onChange={(e) => setCollateral(i, { yearOfManufacture: e.target.value as any })} /></div>
                </div>
              )}

              <div className="row gap-2 wrap" style={{ marginTop: 8, alignItems: 'center' }}>
                {(c.documents || []).map((d) => (
                  <div key={d.id} className="row gap-1" style={{ alignItems: 'center', padding: '3px 8px', background: 'var(--bg-1)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', fontSize: 11.5 }}>
                    <Icon n="doc" s={13} />
                    <span>{d.filePath.split('/').pop()}</span>
                    <button className="mms-act" title="View" onClick={() => viewCollateralDoc(i, d)} style={{ padding: 0 }}><Icon n="eye" s={13} /></button>
                    <button className="mms-act" title="Remove" onClick={() => removeCollateralDoc(i, d)} style={{ padding: 0 }}><Icon n="trash" s={13} /></button>
                  </div>
                ))}
                {(collateralFiles[i] || []).map((f, fi) => (
                  <div key={fi} className="row gap-1" style={{ alignItems: 'center', padding: '3px 8px', background: 'var(--bg-1)', border: '1px dashed var(--line)', borderRadius: 'var(--r-s)', fontSize: 11.5 }}>
                    <span>{f.name}</span>
                    <button className="mms-act" title="Remove" onClick={() => unstageCollateralFile(i, fi)} style={{ padding: 0 }}><Icon n="trash" s={13} /></button>
                  </div>
                ))}
                <input ref={(el) => { collateralFileInputs.current[i] = el }} type="file" multiple
                  accept="image/png,image/jpeg,image/webp,application/pdf"
                  onChange={(e) => { stageCollateralFiles(i, e.target.files); e.target.value = '' }} style={{ display: 'none' }} />
                <Btn size="sm" variant="solid" icon="upload" onClick={() => collateralFileInputs.current[i]?.click()}>Attach File</Btn>
              </div>
            </div>
          ))}
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Loan Applications" icon="doc"
        sub={`${rows.length} applications`}
        actions={<Can user={me} module="Loan Applications" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>New Application</Btn></Can>} />

      <div className="row gap-2 wrap" style={{ marginBottom: 14 }}>
        {(['All', 'Pending', 'Approved', 'Rejected', 'Active', 'Closed'] as const).map((s) => (
          <Btn key={s} size="sm" variant={statusFilter === s ? 'primary' : 'solid'} onClick={() => setStatusFilter(s)}>
            {s} {counts[s] ? <span style={{ opacity: 0.7 }}>({counts[s]})</span> : ''}
          </Btn>
        ))}
      </div>

      <Card pad={0}>
        <div className="row gap-2" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line)', alignItems: 'center' }}>
          <div style={{ position: 'relative', maxWidth: 340, flex: 1 }}>
            <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--tx-2)' }}><Icon n="search" s={15} /></span>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search code, customer, product…" style={{ paddingLeft: 32 }} />
          </div>
          {q && <span className="t-2" style={{ fontSize: 12 }}>{filtered.length} of {rows.length}</span>}
        </div>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Application' }, { label: 'Customer' }, { label: 'Product' }, { label: 'Amount' }, { label: 'Term' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 130 }]}
          rows={filtered}
          empty="No loan applications"
          onRow={openRow}
          render={(l) => <>
            <Td c="var(--tx-0)" mono style={{ fontWeight: 600 }}>{l.code}</Td>
            <Td><div style={{ fontWeight: 600 }}>{l.customer?.fullName}</div><div className="t-2" style={{ fontSize: 11.5 }}>{l.customer?.code}</div></Td>
            <Td>{l.loanDetail?.loanType?.name}</Td>
            <Td mono>{Number(l.principalAmount).toLocaleString()}</Td>
            <Td>{l.termMonths}mo</Td>
            <Td align="center"><Badge tone={statusTone[l.status]} dot>{l.status}</Badge></Td>
            <Td align="right">
              <div className="row gap-1" style={{ justifyContent: 'flex-end' }} onClick={(e) => e.stopPropagation()}>
                {l.status === 'Pending' && (
                  <Can user={me} module="Loan Applications" action="Approve">
                    <button className="mms-act" title="Approve" onClick={() => approve(l)}><Icon n="check" s={15} c="var(--ok)" /></button>
                    <button className="mms-act" title="Reject" onClick={() => reject(l)}><Icon n="x" s={15} c="var(--bad)" /></button>
                  </Can>
                )}
                {l.status === 'Pending' && (
                  <Can user={me} module="Loan Applications" action="Delete">
                    <button className="mms-act" title="Withdraw" onClick={() => remove(l)}><Icon n="trash" s={15} /></button>
                  </Can>
                )}
              </div>
            </Td>
          </>} />}
      </Card>
    </div>
  )
}

function CustomerDetailCard({ c }: { c: Customer }) {
  const idNo = c.nicNo || c.passportNo || c.drivingLicenseNo || '—'
  const occupation = c.occupationType === 'Employed' ? [c.employmentDetail?.jobTitle, c.employmentDetail?.employerName].filter(Boolean).join(' at ')
    : (c.occupationType === 'SelfEmployed' || c.occupationType === 'BusinessOwner') ? c.businessDetail?.businessName
    : c.occupationType

  const [activeOwn, setActiveOwn] = useState<Loan[]>([])
  const [activeGuaranteed, setActiveGuaranteed] = useState<Loan[]>([])
  const [loansLoading, setLoansLoading] = useState(true)
  const [loansModal, setLoansModal] = useState(false)
  useEffect(() => {
    setLoansLoading(true)
    customerLoansApi.list(c.id)
      .then((d) => { setActiveOwn(d.borrower.filter((l) => l.status === 'Active')); setActiveGuaranteed(d.guarantor.filter((l) => l.status === 'Active')) })
      .catch(() => { setActiveOwn([]); setActiveGuaranteed([]) })
      .finally(() => setLoansLoading(false))
  }, [c.id])

  const outstandingOf = (l: Loan) => Number(l.totalDueSum ?? 0) - Number(l.paidSum ?? 0)

  return (
    <div style={{ marginTop: 10, padding: 14, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
      <div className="row between" style={{ marginBottom: 10 }}>
        <div className="row gap-2" style={{ alignItems: 'center' }}>
          <span style={{ fontWeight: 700, fontSize: 14 }}>{c.fullName}</span>
          <span className="t-2" style={{ fontSize: 12 }}>{c.code}</span>
          {c.isGuarantor && <Badge tone="blue">Guarantor</Badge>}
        </div>
        <Badge tone={custStatusTone[c.status || 'Active']} dot>{c.status || 'Active'}</Badge>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '6px 20px', fontSize: 12.5 }}>
        <div><span className="t-2">NIC / ID: </span>{idNo}</div>
        <div><span className="t-2">Phone: </span>{c.phone || '—'}</div>
        <div><span className="t-2">Branch: </span>{c.branch?.name || '—'}</div>
        <div><span className="t-2">Occupation: </span>{occupation || '—'}</div>
        <div><span className="t-2">Monthly Income: </span>{c.monthlyIncome ? Number(c.monthlyIncome).toLocaleString() : '—'}</div>
        <div><span className="t-2">Email: </span>{c.email || '—'}</div>
        {c.address && <div style={{ gridColumn: '1/-1' }}><span className="t-2">Address: </span>{c.address}</div>}
      </div>

      <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--line)' }}>
        <div className="row between" style={{ marginBottom: 6 }}>
          <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em' }}>Active Loans</div>
          {(activeOwn.length + activeGuaranteed.length) > 0 && (
            <button onClick={() => setLoansModal(true)} style={{ background: 'none', border: 'none', color: 'var(--ac-bright)', fontSize: 11.5, fontWeight: 600, cursor: 'pointer', padding: 0 }}>View All</button>
          )}
        </div>
        {loansLoading ? <div className="t-2" style={{ fontSize: 12 }}>Loading…</div> : (activeOwn.length === 0 && activeGuaranteed.length === 0) ? (
          <div className="t-2" style={{ fontSize: 12 }}>No active loans.</div>
        ) : (
          <div className="col gap-1">
            {activeOwn.map((l) => (
              <div key={`own-${l.id}`} className="row between" style={{ fontSize: 12 }}>
                <span><b>{l.code}</b> · {l.loanDetail?.loanType?.name || '—'} · <span className="t-2">Borrower</span></span>
                <span style={{ fontFamily: 'JetBrains Mono' }}>{outstandingOf(l).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
              </div>
            ))}
            {activeGuaranteed.map((l) => (
              <div key={`grt-${l.id}`} className="row between" style={{ fontSize: 12 }}>
                <span><b>{l.code}</b> · {l.loanDetail?.loanType?.name || '—'} · <span className="t-2">Guarantor for {l.customer?.fullName}</span></span>
                <span style={{ fontFamily: 'JetBrains Mono' }}>{outstandingOf(l).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
              </div>
            ))}
          </div>
        )}
      </div>

      <Modal open={loansModal} onClose={() => setLoansModal(false)} width={720} title="Active Loans" sub={`${c.fullName} — ${activeOwn.length + activeGuaranteed.length} active loan(s)`}>
        <Table cols={[{ label: 'Loan Code' }, { label: 'Product' }, { label: 'Role' }, { label: 'Principal', align: 'right' }, { label: 'Term' }, { label: 'Outstanding', align: 'right' }, { label: 'Status', align: 'center' }]}
          rows={[...activeOwn.map((l) => ({ l, role: 'Borrower' })), ...activeGuaranteed.map((l) => ({ l, role: `Guarantor for ${l.customer?.fullName || '—'}` }))]}
          empty="No active loans"
          render={({ l, role }) => <>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{l.code}</Td>
            <Td>{l.loanDetail?.loanType?.name || '—'}</Td>
            <Td>{role}</Td>
            <Td align="right" mono>{Number(l.principalAmount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</Td>
            <Td>{l.termMonths ? `${l.termMonths}mo` : '—'}{l.loanDetail?.installmentFrequency ? ` · ${l.loanDetail.installmentFrequency}` : ''}</Td>
            <Td align="right" mono>{outstandingOf(l).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</Td>
            <Td align="center"><Badge tone="green" dot>{l.status}</Badge></Td>
          </>} />
      </Modal>
    </div>
  )
}

