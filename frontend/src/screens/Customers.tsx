/* Tazz Investment & Credit — Customer Management (API-backed) */
import { useEffect, useRef, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Table, Td, inputStyle, ErrorBanner } from '../components/ui'
import { Icon } from '../components/Icon'
import { customersApi, branchesApi, customerDocumentsApi, customerLoansApi, customerFundingApi, loansApi, type Customer, type Branch, type OccupationType, type CustomerDocType, type CustomerDocument, type CustomerTitle, type Loan, type LoanStatus, type FdDeposit, type FixedDeposit, type AuthUser } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import { LoanDetail } from './loan/LoanDetail'
import type { Go } from './types'

const OCCUPATION_OPTIONS: { value: OccupationType; label: string }[] = [
  { value: 'Employed', label: 'Employed' },
  { value: 'SelfEmployed', label: 'Self-Employed' },
  { value: 'BusinessOwner', label: 'Business Owner' },
  { value: 'Unemployed', label: 'Unemployed' },
  { value: 'Retired', label: 'Retired' },
  { value: 'Student', label: 'Student' },
  { value: 'Other', label: 'Other' },
]
const DOC_TYPE_OPTIONS: { value: CustomerDocType; label: string }[] = [
  { value: 'NIC', label: 'NIC' },
  { value: 'Passport', label: 'Passport' },
  { value: 'DrivingLicense', label: 'Driving License' },
  { value: 'UtilityBill', label: 'Utility Bill' },
  { value: 'Photo', label: 'Photo' },
  { value: 'Other', label: 'Other' },
]
const statusTone: Record<string, 'green' | 'red' | 'amber'> = { Active: 'green', Inactive: 'red', Blacklisted: 'amber' }
const TITLE_OPTIONS: CustomerTitle[] = ['Mr.', 'Mrs.', 'Miss', 'Ms.', 'Dr.', 'Rev.']

const blankForm = (): Partial<Customer> => ({ status: 'Active' })

/** Upload/view/delete KYC documents for one customer. Its own panel since it
 *  needs a customer id to exist first — only shown once editing a saved record. */
function DocumentsPanel({ customerId, me }: { customerId: number; me?: AuthUser | null }) {
  const notify = useNotify()
  const [docs, setDocs] = useState<CustomerDocument[]>([])
  const [loading, setLoading] = useState(true)
  const [docType, setDocType] = useState<CustomerDocType>('NIC')
  const [uploading, setUploading] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)

  const refresh = () => {
    setLoading(true)
    customerDocumentsApi.list(customerId).then(setDocs).catch(() => setDocs([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [customerId]) // eslint-disable-line react-hooks/exhaustive-deps

  const pick = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setUploading(true)
    try { await customerDocumentsApi.upload(customerId, file, docType); refresh(); notify.success('Document uploaded') }
    catch (ex: any) { notify.error('Upload failed', getErrorText(ex)) }
    finally { setUploading(false) }
  }

  const view = async (d: CustomerDocument) => {
    try {
      const blob = await customerDocumentsApi.download(customerId, d.id)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch { notify.error('Could not open document') }
  }

  const remove = async (d: CustomerDocument) => {
    const ok = await notify.confirm({ title: 'Delete this document?', msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await customerDocumentsApi.remove(customerId, d.id); refresh() }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <Can user={me} module="Customer Management" action="Create">
        <div className="row gap-2" style={{ marginBottom: 10 }}>
          <Select value={docType} onChange={(e) => setDocType(e.target.value as CustomerDocType)} style={{ maxWidth: 180 }}>
            {DOC_TYPE_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </Select>
          <input ref={fileInput} type="file" accept="image/png,image/jpeg,image/webp,application/pdf" onChange={pick} style={{ display: 'none' }} />
          <Btn variant="solid" icon="upload" size="sm" onClick={() => fileInput.current?.click()} disabled={uploading}>{uploading ? 'Uploading…' : 'Upload Document'}</Btn>
        </div>
      </Can>
      {loading ? <div className="t-2" style={{ fontSize: 12.5 }}>Loading documents…</div> :
       docs.length === 0 ? <div className="t-2" style={{ fontSize: 12.5 }}>No documents uploaded yet.</div> :
       <div className="col gap-2">
         {docs.map((d) => (
           <div key={d.id} className="row between" style={{ padding: '8px 12px', background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
             <div className="row gap-2" style={{ alignItems: 'center' }}>
               <Icon n="doc" s={16} />
               <span style={{ fontSize: 12.5 }}>{DOC_TYPE_OPTIONS.find((o) => o.value === d.docType)?.label || d.docType}</span>
             </div>
             <div className="row gap-1">
               <button className="mms-act" title="View" onClick={() => view(d)}><Icon n="eye" s={15} /></button>
               <Can user={me} module="Customer Management" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(d)}><Icon n="trash" s={15} /></button></Can>
             </div>
           </div>
         ))}
       </div>}
    </div>
  )
}

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const loanStatusTone: Record<LoanStatus, 'green' | 'red' | 'amber' | 'blue' | 'neutral'> = {
  Pending: 'amber', Approved: 'blue', Rejected: 'red', Active: 'green', Closed: 'neutral', WrittenOff: 'red', Restructured: 'amber',
}

/** Read-only profile — Personal Details, Occupation, Documents, and the customer's loans.
 *  Editing lives in the separate form (openEdit), so this stays a pure view. */
/** Shared loan-history table for both the "as borrower" and "as guarantor" sections
 *  of the customer view — same columns, plus a per-row button to open the full loan. */
function LoanHistoryTable({ loans, loading, showBorrower, onOpen, empty }: {
  loans: Loan[]; loading: boolean; showBorrower?: boolean; onOpen: (loanId: number) => void; empty: string
}) {
  if (loading) return <div style={{ padding: '0 16px 16px', fontSize: 12.5, color: 'var(--tx-2)' }}>Loading…</div>
  return (
    <Table cols={[
      { label: 'Loan Code' }, { label: 'Product' },
      ...(showBorrower ? [{ label: 'Borrower' }] : []),
      { label: 'Principal', align: 'right' as const }, { label: 'Outstanding', align: 'right' as const },
      { label: 'Status', align: 'center' as const }, { label: '', align: 'right' as const, w: 70 },
    ]}
      rows={loans} empty={empty}
      render={(l) => <>
        <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{l.code}</Td>
        <Td>{l.loanDetail?.loanType?.name || '—'}</Td>
        {showBorrower && <Td>{l.customer?.fullName || '—'}</Td>}
        <Td align="right" mono>{money(l.principalAmount)}</Td>
        <Td align="right" mono>{l.status === 'Approved' ? '—' : money(num(l.totalDueSum) - num(l.paidSum))}</Td>
        <Td align="center"><Badge tone={loanStatusTone[l.status]} dot>{l.status}</Badge></Td>
        <Td align="right"><Btn size="sm" variant="solid" icon="eye" onClick={() => onOpen(l.id)}>View</Btn></Td>
      </>} />
  )
}

/** Read-only Funding History — every deposit and Fixed Deposit this customer holds. */
function FundingHistoryPanel({ customerId }: { customerId: number }) {
  const [deposits, setDeposits] = useState<FdDeposit[]>([])
  const [fixedDeposits, setFixedDeposits] = useState<FixedDeposit[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    setLoading(true)
    customerFundingApi.list(customerId)
      .then((d) => { setDeposits(d.deposits); setFixedDeposits(d.fixedDeposits) })
      .catch(() => { setDeposits([]); setFixedDeposits([]) })
      .finally(() => setLoading(false))
  }, [customerId])

  if (loading) return <div style={{ padding: '0 16px 16px', fontSize: 12.5, color: 'var(--tx-2)' }}>Loading…</div>

  return (
    <>
      <div style={{ padding: '14px 16px 10px' }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Deposits</div>
        <Table cols={[{ label: 'Deposit No' }, { label: 'Date' }, { label: 'Method' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }]}
          rows={deposits} empty="No deposits for this customer"
          render={(d) => <>
            <Td mono>{d.depositNumber}</Td>
            <Td>{typeof d.depositDate === 'string' ? d.depositDate.slice(0, 10) : '—'}</Td>
            <Td>{d.paymentMethod}</Td>
            <Td align="right" mono>{money(d.amount)}</Td>
            <Td align="center"><Badge tone={d.status === 'Available' ? 'amber' : 'green'} dot>{d.status}</Badge></Td>
          </>} />
      </div>
      <div style={{ padding: '14px 16px 10px' }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Fixed Deposits</div>
        <Table cols={[{ label: 'FD No' }, { label: 'Product' }, { label: 'Principal', align: 'right' as const }, { label: 'Maturity Date' }, { label: 'Status', align: 'center' as const }]}
          rows={fixedDeposits} empty="No Fixed Deposits for this customer"
          render={(fd) => <>
            <Td mono>{fd.fdNumber}</Td>
            <Td>{fd.fundingProduct?.name || '—'}</Td>
            <Td align="right" mono>{money(fd.principalAmount)}</Td>
            <Td>{typeof fd.maturityDate === 'string' ? fd.maturityDate.slice(0, 10) : '—'}</Td>
            <Td align="center"><Badge tone={fd.status === 'Active' ? 'green' : 'neutral'} dot>{fd.status}</Badge></Td>
          </>} />
      </div>
    </>
  )
}

function CustomerView({ customer, me, onEdit, onOpenLoan }: { customer: Customer; me?: AuthUser | null; onEdit: () => void; onOpenLoan: (loanId: number) => void }) {
  const [borrowerLoans, setBorrowerLoans] = useState<Loan[]>([])
  const [guarantorLoans, setGuarantorLoans] = useState<Loan[]>([])
  const [loansLoading, setLoansLoading] = useState(true)

  useEffect(() => {
    setLoansLoading(true)
    customerLoansApi.list(customer.id)
      .then((d) => { setBorrowerLoans(d.borrower); setGuarantorLoans(d.guarantor) })
      .catch(() => { setBorrowerLoans([]); setGuarantorLoans([]) })
      .finally(() => setLoansLoading(false))
  }, [customer.id])

  const isEmployed = customer.occupationType === 'Employed'
  const isBusiness = customer.occupationType === 'SelfEmployed' || customer.occupationType === 'BusinessOwner'

  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>
        <Card>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Personal Details</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 20px', fontSize: 12.5 }}>
            <div><span className="t-2">Full Name: </span><b>{customer.fullName}</b></div>
            <div><span className="t-2">Branch: </span><b>{customer.branch?.name || '—'}</b></div>
            <div><span className="t-2">NIC No: </span><b>{customer.nicNo || '—'}</b></div>
            <div><span className="t-2">Passport No: </span><b>{customer.passportNo || '—'}</b></div>
            <div><span className="t-2">Driving License: </span><b>{customer.drivingLicenseNo || '—'}</b></div>
            <div><span className="t-2">Date of Birth: </span><b>{dateOnly(customer.dob)}</b></div>
            <div><span className="t-2">Gender: </span><b>{customer.gender || '—'}</b></div>
            <div><span className="t-2">Phone: </span><b>{customer.phone || '—'}</b></div>
            <div><span className="t-2">Alternate Phone: </span><b>{customer.altPhone || '—'}</b></div>
            <div><span className="t-2">Email: </span><b>{customer.email || '—'}</b></div>
            <div style={{ gridColumn: '1/-1' }}><span className="t-2">Address: </span><b>{customer.address || '—'}</b></div>
          </div>
        </Card>

        <Card>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Occupation</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 20px', fontSize: 12.5, marginBottom: (isEmployed || isBusiness) ? 14 : 0 }}>
            <div><span className="t-2">Occupation Type: </span><b>{OCCUPATION_OPTIONS.find((o) => o.value === customer.occupationType)?.label || '—'}</b></div>
            <div><span className="t-2">Monthly Income: </span><b>{customer.monthlyIncome != null ? money(customer.monthlyIncome) : '—'}</b></div>
          </div>
          {isEmployed && (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 20px', fontSize: 12.5, padding: 12, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
              <div><span className="t-2">Employer: </span><b>{customer.employmentDetail?.employerName || '—'}</b></div>
              <div><span className="t-2">Job Title: </span><b>{customer.employmentDetail?.jobTitle || '—'}</b></div>
              <div><span className="t-2">Employer Phone: </span><b>{customer.employmentDetail?.employerPhone || '—'}</b></div>
              <div><span className="t-2">Employed Since: </span><b>{dateOnly(customer.employmentDetail?.employmentStartDate)}</b></div>
              <div style={{ gridColumn: '1/-1' }}><span className="t-2">Employer Address: </span><b>{customer.employmentDetail?.employerAddress || '—'}</b></div>
            </div>
          )}
          {isBusiness && (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 20px', fontSize: 12.5, padding: 12, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
              <div><span className="t-2">Business Name: </span><b>{customer.businessDetail?.businessName || '—'}</b></div>
              <div><span className="t-2">Reg. No: </span><b>{customer.businessDetail?.businessRegNo || '—'}</b></div>
              <div><span className="t-2">Business Type: </span><b>{customer.businessDetail?.businessType || '—'}</b></div>
              <div><span className="t-2">Business Phone: </span><b>{customer.businessDetail?.businessPhone || '—'}</b></div>
              <div style={{ gridColumn: '1/-1' }}><span className="t-2">Business Address: </span><b>{customer.businessDetail?.businessAddress || '—'}</b></div>
            </div>
          )}
        </Card>
      </div>

      {customer.notes && (
        <Card style={{ marginBottom: 16 }}>
          <div className="eyebrow" style={{ marginBottom: 8 }}>Notes</div>
          <div style={{ fontSize: 13 }}>{customer.notes}</div>
        </Card>
      )}

      <Card style={{ marginBottom: 16 }} pad={0}>
        <div className="eyebrow" style={{ padding: '14px 16px 10px' }}>Loan History — as Borrower</div>
        <LoanHistoryTable loans={borrowerLoans} loading={loansLoading} onOpen={onOpenLoan} empty="No loans for this customer" />
      </Card>

      <Card style={{ marginBottom: 16 }} pad={0}>
        <div className="eyebrow" style={{ padding: '14px 16px 10px' }}>Loan History — as Guarantor</div>
        <LoanHistoryTable loans={guarantorLoans} loading={loansLoading} showBorrower onOpen={onOpenLoan} empty="Not a guarantor on any loan" />
      </Card>

      <Card style={{ marginBottom: 16 }} pad={0}>
        <FundingHistoryPanel customerId={customer.id} />
      </Card>

      <Card>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Documents</div>
        <DocumentsPanel customerId={customer.id} me={me} />
      </Card>

      <Can user={me} module="Customer Management" action="Edit">
        <div className="row" style={{ marginTop: 16, justifyContent: 'flex-end' }}>
          <Btn variant="primary" icon="edit" onClick={onEdit}>Edit Customer</Btn>
        </div>
      </Can>
    </div>
  )
}

export function CustomersScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<Customer[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [mode, setMode] = useState<'list' | 'view' | 'form' | 'loan'>('list')
  const [editing, setEditing] = useState<Customer | null>(null)
  const [viewing, setViewing] = useState<Customer | null>(null)
  const [viewLoading, setViewLoading] = useState(false)
  const [viewingLoan, setViewingLoan] = useState<Loan | null>(null)
  const [loanLoading, setLoanLoading] = useState(false)
  const [form, setForm] = useState<Partial<Customer>>(blankForm())
  const set = <K extends keyof Customer>(k: K, v: Customer[K]) => setForm((s) => ({ ...s, [k]: v }))

  const refresh = () => {
    setLoading(true)
    customersApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => {
    refresh()
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
  }, [])

  const openCreate = () => { setEditing(null); setForm(blankForm()); setErr(''); setMode('form') }
  const openEdit = (c: Customer) => {
    setEditing(c)
    // employmentDetail/businessDetail are read-only nested relations — flatten
    // them back onto the form's flat fields, which is what the save API accepts.
    setForm({ ...c, ...(c.employmentDetail || {}), ...(c.businessDetail || {}) })
    setErr(''); setMode('form')
  }
  const openView = async (c: Customer) => {
    setMode('view'); setViewLoading(true)
    try { setViewing(await customersApi.get(c.id)) } catch { setViewing(c) } finally { setViewLoading(false) }
  }
  const back = () => { setMode('list'); setEditing(null); setViewing(null); setViewingLoan(null); setErr('') }

  const openLoan = async (loanId: number) => {
    setMode('loan'); setLoanLoading(true)
    try { setViewingLoan(await loansApi.get(loanId)) } catch { setViewingLoan(null) } finally { setLoanLoading(false) }
  }
  const backToCustomer = () => { setMode('view'); setViewingLoan(null) }

  const save = async () => {
    if (!form.title || !form.surname || !form.initials) { setErr('Title, Surname and Initial are required.'); return }
    if (!form.branchId) { setErr('Branch is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await customersApi.update(editing.id, form)
      else await customersApi.create(form)
      back(); refresh()
      notify.success(editing ? 'Customer updated' : 'Customer created', [form.title, form.initials, form.surname].filter(Boolean).join(' '))
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (c: Customer) => {
    const ok = await notify.confirm({ title: `Delete customer "${c.fullName}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await customersApi.remove(c.id); refresh(); notify.success('Customer deleted', c.fullName) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const isEmployed = form.occupationType === 'Employed'
  const isBusiness = form.occupationType === 'SelfEmployed' || form.occupationType === 'BusinessOwner'

  const q = search.trim().toLowerCase()
  const filtered = q
    ? rows.filter((c) => [c.fullName, c.surname, c.initials, c.code, c.nicNo, c.passportNo, c.drivingLicenseNo, c.phone, c.email, c.branch?.name]
        .some((v) => (v || '').toLowerCase().includes(q)))
    : rows

  if (mode === 'view') {
    return (
      <div>
        <PageHead crumbs="Customers" title={viewLoading || !viewing ? 'Loading…' : viewing.fullName} icon="users"
          sub={viewing ? `${viewing.code} — ${viewing.branch?.name || ''}` : undefined}
          actions={<Btn variant="solid" onClick={back}>← Back to Customers</Btn>} />
        {viewLoading || !viewing ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div>
          : <CustomerView customer={viewing} me={me} onEdit={() => openEdit(viewing)} onOpenLoan={openLoan} />}
      </div>
    )
  }

  if (mode === 'loan') {
    return (
      <div>
        <PageHead crumbs={`Customers / ${viewing?.fullName || ''}`} title={loanLoading || !viewingLoan ? 'Loading…' : `Loan ${viewingLoan.code}`} icon="coins"
          sub={viewingLoan ? `${viewingLoan.customer?.fullName} — ${viewingLoan.loanDetail?.loanType?.name}` : undefined}
          actions={<Btn variant="solid" onClick={backToCustomer}>← Back to Customer</Btn>} />
        {loanLoading || !viewingLoan ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div>
          : <LoanDetail loan={viewingLoan} me={me} onChanged={() => openLoan(viewingLoan.id)} go={go} context="active" />}
      </div>
    )
  }

  if (mode === 'form') {
    return (
      <div>
        <PageHead crumbs="Customers" title={editing ? 'Edit Customer' : 'Add Customer'} icon="users"
          sub={editing ? editing.code : 'Guarantors are just customers used in a different capacity on a loan — no separate record needed.'}
          actions={<>
            <Btn variant="solid" onClick={back}>Cancel</Btn>
            <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create Customer')}</Btn>
          </>} />

        <ErrorBanner error={err} />

        <Card style={{ marginBottom: 16 }}>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Personal Details</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Surname"><Input value={form.surname || ''} onChange={(e) => set('surname', e.target.value)} /></Field>
            <Field label="Initial in Full without Surname"><Input value={form.nameDenotedByInitials || ''} onChange={(e) => set('nameDenotedByInitials', e.target.value)} /></Field>
            <Field label="Initial"><Input value={form.initials || ''} onChange={(e) => set('initials', e.target.value)} placeholder="e.g. J.K." /></Field>
            <Field label="Title"><Select value={form.title || ''} onChange={(e) => set('title', (e.target.value || undefined) as any)}>
              <option value="">—</option>{TITLE_OPTIONS.map((t) => <option key={t} value={t}>{t}</option>)}
            </Select></Field>
            <Field label="Branch"><Select value={form.branchId ?? ''} onChange={(e) => set('branchId', Number(e.target.value) || undefined as any)}>
              <option value="">—</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </Select></Field>
            <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => set('status', e.target.value as any)}>
              <option>Active</option><option>Blacklisted</option><option>Inactive</option>
            </Select></Field>
            <Field label="NIC Number"><Input value={form.nicNo || ''} onChange={(e) => set('nicNo', e.target.value)} /></Field>
            <Field label="Passport Number"><Input value={form.passportNo || ''} onChange={(e) => set('passportNo', e.target.value)} /></Field>
            <Field label="Driving License Number"><Input value={form.drivingLicenseNo || ''} onChange={(e) => set('drivingLicenseNo', e.target.value)} /></Field>
            <Field label="Date of Birth"><Input type="date" value={form.dob || ''} onChange={(e) => set('dob', e.target.value)} /></Field>
            <Field label="Gender"><Select value={form.gender || ''} onChange={(e) => set('gender', e.target.value)}>
              <option value="">—</option><option>Male</option><option>Female</option><option>Other</option>
            </Select></Field>
            <Field label="Phone"><Input value={form.phone || ''} onChange={(e) => set('phone', e.target.value)} /></Field>
            <Field label="Alternate Phone"><Input value={form.altPhone || ''} onChange={(e) => set('altPhone', e.target.value)} /></Field>
            <Field label="Email"><Input type="email" value={form.email || ''} onChange={(e) => set('email', e.target.value)} /></Field>
            <Field label="Address" full><textarea value={form.address || ''} onChange={(e) => set('address', e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
            <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', gridColumn: '1/-1' }}>
              <input type="checkbox" checked={!!form.isGuarantor} onChange={(e) => set('isGuarantor', e.target.checked)} />
              <span style={{ fontSize: 13 }}>Register as Guarantor <span className="t-2">— a label only; any customer can still be picked as a loan guarantor</span></span>
            </label>
          </div>
        </Card>

        <Card style={{ marginBottom: 16 }}>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Occupation</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: isEmployed || isBusiness ? 16 : 0 }}>
            <Field label="Occupation Type"><Select value={form.occupationType || ''} onChange={(e) => set('occupationType', (e.target.value || null) as any)}>
              <option value="">—</option>{OCCUPATION_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </Select></Field>
            <Field label="Monthly Income"><Input type="number" value={form.monthlyIncome ?? ''} onChange={(e) => set('monthlyIncome', e.target.value as any)} /></Field>
          </div>

          {isEmployed && (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, padding: 14, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
              <Field label="Employer Name"><Input value={form.employerName || ''} onChange={(e) => set('employerName', e.target.value)} /></Field>
              <Field label="Job Title"><Input value={form.jobTitle || ''} onChange={(e) => set('jobTitle', e.target.value)} /></Field>
              <Field label="Employer Phone"><Input value={form.employerPhone || ''} onChange={(e) => set('employerPhone', e.target.value)} /></Field>
              <Field label="Employed Since"><Input type="date" value={form.employmentStartDate || ''} onChange={(e) => set('employmentStartDate', e.target.value)} /></Field>
              <Field label="Employer Address" full><Input value={form.employerAddress || ''} onChange={(e) => set('employerAddress', e.target.value)} /></Field>
            </div>
          )}

          {isBusiness && (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, padding: 14, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
              <Field label="Business Name"><Input value={form.businessName || ''} onChange={(e) => set('businessName', e.target.value)} /></Field>
              <Field label="Business Reg. No"><Input value={form.businessRegNo || ''} onChange={(e) => set('businessRegNo', e.target.value)} /></Field>
              <Field label="Business Type"><Input value={form.businessType || ''} onChange={(e) => set('businessType', e.target.value)} /></Field>
              <Field label="Business Phone"><Input value={form.businessPhone || ''} onChange={(e) => set('businessPhone', e.target.value)} /></Field>
              <Field label="Business Address" full><Input value={form.businessAddress || ''} onChange={(e) => set('businessAddress', e.target.value)} /></Field>
            </div>
          )}
        </Card>

        <Card style={{ marginBottom: 16 }}>
          <Field label="Notes" full><textarea value={form.notes || ''} onChange={(e) => set('notes', e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </Card>

        <Card>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Documents</div>
          {editing
            ? <DocumentsPanel customerId={editing.id} me={me} />
            : <div className="t-2" style={{ fontSize: 12.5, fontStyle: 'italic' }}>Save the customer first to attach documents.</div>}
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Customers" title="Customer Management" icon="users" sub={`${rows.length} customers`}
        actions={<Can user={me} module="Customer Management" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Customer</Btn></Can>} />
      <Card pad={0}>
        <div className="row gap-2" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line)', alignItems: 'center' }}>
          <div style={{ position: 'relative', maxWidth: 340, flex: 1 }}>
            <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--tx-2)' }}><Icon n="search" s={15} /></span>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name, NIC, phone, branch…" style={{ paddingLeft: 32 }} />
          </div>
          {q && <span className="t-2" style={{ fontSize: 12 }}>{filtered.length} of {rows.length}</span>}
        </div>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Customer' }, { label: 'NIC / ID' }, { label: 'Phone' }, { label: 'Branch' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          rows={filtered}
          empty={q ? 'No customers match your search' : 'No records'}
          onRow={(c) => openView(c)}
          render={(c) => <>
            <Td c="var(--tx-0)"><div className="row gap-2" style={{ alignItems: 'center' }}><span style={{ fontWeight: 600 }}>{c.fullName}</span>{c.isGuarantor && <Badge tone="blue">Guarantor</Badge>}</div><div className="t-2" style={{ fontSize: 11.5 }}>{c.code}</div></Td>
            <Td mono style={{ fontSize: 12 }}>{c.nicNo || c.passportNo || c.drivingLicenseNo || '—'}</Td>
            <Td>{c.phone || '—'}</Td>
            <Td>{c.branch?.name || '—'}</Td>
            <Td align="center"><Badge tone={statusTone[c.status || 'Active']} dot>{c.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }} onClick={(e) => e.stopPropagation()}>
              <Can user={me} module="Customer Management" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(c)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Customer Management" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(c)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>
    </div>
  )
}
