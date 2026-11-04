/* Tazz Investment & Credit — Customer Management (API-backed) */
import { useFormDraft, DraftBanner } from '../lib/draft'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Table, Td, Pager, inputStyle, ErrorBanner , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { useFilePreview } from '../components/FilePreview'
import { customersApi, branchesApi, customerDocumentsApi, customerLoansApi, customerFundingApi, customerCribApi, loansApi, type Customer, type Branch, type OccupationType, type CustomerDocType, type CustomerDocument, type CustomerTitle, type Loan, type LoanStatus, type FdDeposit, type FixedDeposit, type CribReport, type AuthUser } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportFlatTableToExcel } from '../lib/export'
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
const statusTone: Record<string, 'green' | 'red' | 'amber'> = { Active: 'green', Inactive: 'amber', Blacklisted: 'red' }
const TITLE_OPTIONS: CustomerTitle[] = ['Mr.', 'Mrs.', 'Miss', 'Ms.', 'Dr.', 'Rev.']

type CustomerTypeFilter = '' | 'Loan' | 'FD' | 'Guarantor'
/** Types a customer holds, derived server-side — one customer can be several at once. */
const customerTypes = (c: Customer): string[] => [
  ...(c.isLoanCustomer ? ['Loan'] : []),
  ...(c.isFdCustomer ? ['FD'] : []),
  ...(c.isGuarantorCustomer ? ['Guarantor'] : []),
]
const typeTone: Record<string, 'green' | 'blue' | 'amber'> = { Loan: 'green', FD: 'amber', Guarantor: 'blue' }

const blankForm = (): Partial<Customer> => ({ status: 'Active' })

/** Sri Lanka NIC: old = 9 digits + V/X (birth year 1900+YY), new = 12 digits (birth year YYYY).
 *  Digits 3-5 (old) / 5-7 (new) are day-of-year, +500 for female. */
function nicToDob(nic: string): string | undefined {
  const s = nic.trim().toUpperCase()
  let year: number, dayOfYear: number
  if (/^\d{9}[VX]$/.test(s)) { year = 1900 + parseInt(s.slice(0, 2), 10); dayOfYear = parseInt(s.slice(2, 5), 10) }
  else if (/^\d{12}$/.test(s)) { year = parseInt(s.slice(0, 4), 10); dayOfYear = parseInt(s.slice(4, 7), 10) }
  else return undefined
  if (dayOfYear > 500) dayOfYear -= 500
  if (dayOfYear < 1 || dayOfYear > 366) return undefined
  // NIC day-of-year is always encoded against a 366-day calendar (Feb treated as 29 days),
  // so in non-leap years anything from Mar 1 onward (encoded day > 59) needs -1.
  const isLeap = (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0
  if (!isLeap && dayOfYear > 59) dayOfYear -= 1
  const d = new Date(year, 0, dayOfYear)
  if (d.getFullYear() !== year) return undefined
  return `${year}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const validateNic = (v: string): string | undefined => {
  if (!v) return undefined
  const s = v.trim().toUpperCase()
  return /^\d{9}[VX]$/.test(s) || /^\d{12}$/.test(s) ? undefined : 'Enter valid NIC (9 digits + V/X, or 12 digits)'
}
const validatePhone = (v: string): string | undefined => {
  if (!v) return undefined
  const s = v.replace(/[\s-]/g, '')
  return /^(0\d{9}|\+94\d{9})$/.test(s) ? undefined : 'Enter valid phone number (e.g. 0771234567)'
}
const validateEmail = (v: string): string | undefined => {
  if (!v) return undefined
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) ? undefined : 'Enter valid email address'
}
const FieldError = ({ msg }: { msg?: string }) => msg ? <span style={{ fontSize: 11, color: 'var(--bad)' }}>{msg}</span> : null

/** Customer profile photo with a fallback icon. The image route is auth-protected,
 *  so it's fetched as a blob rather than set as a plain <img src>. */
function CustomerAvatar({ customerId, hasPhoto, size = 96 }: { customerId: number; hasPhoto?: boolean; size?: number }) {
  const [url, setUrl] = useState('')
  useEffect(() => {
    if (!hasPhoto) { setUrl(''); return }
    let objUrl = ''
    customersApi.photo(customerId)
      .then((blob) => { objUrl = URL.createObjectURL(blob); setUrl(objUrl) })
      .catch(() => setUrl(''))
    return () => { if (objUrl) URL.revokeObjectURL(objUrl) }
  }, [customerId, hasPhoto])

  return (
    <div style={{ width: size, height: size, borderRadius: 'var(--r-m)', overflow: 'hidden', flexShrink: 0,
      background: 'var(--bg-0)', border: '1px solid var(--line)', display: 'grid', placeItems: 'center' }}>
      {url
        ? <img src={url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
        : <Icon n="users" s={Math.round(size * 0.4)} />}
    </div>
  )
}

/** Upload / replace / remove one customer's profile photo. Edit-mode only —
 *  needs a saved customer id. */
function PhotoPanel({ customer, me }: { customer: Customer; me?: AuthUser | null }) {
  const notify = useNotify()
  const [hasPhoto, setHasPhoto] = useState(!!customer.hasPhoto)
  const [busy, setBusy] = useState(false)
  const [bump, setBump] = useState(0)
  const fileInput = useRef<HTMLInputElement>(null)

  const pick = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setBusy(true)
    try {
      await customersApi.uploadPhoto(customer.id, file)
      setHasPhoto(true); setBump((n) => n + 1)
      notify.success('Photo updated')
    } catch (ex: any) { notify.error('Upload failed', getErrorText(ex)) }
    finally { setBusy(false) }
  }

  const remove = async () => {
    const ok = await notify.confirm({ title: 'Remove profile photo?', msg: 'This action cannot be undone.', danger: true, okText: 'Remove' })
    if (!ok) return
    try { await customersApi.removePhoto(customer.id); setHasPhoto(false); setBump((n) => n + 1) }
    catch (ex: any) { notify.error('Remove failed', getErrorText(ex)) }
  }

  return (
    <div className="row gap-3" style={{ alignItems: 'center' }}>
      <CustomerAvatar key={bump} customerId={customer.id} hasPhoto={hasPhoto} size={96} />
      <Can user={me} module="Customer Management" action="Edit">
        <div className="col gap-2" style={{ alignItems: 'flex-start' }}>
          <input ref={fileInput} type="file" accept="image/png,image/jpeg,image/webp" onChange={pick} style={{ display: 'none' }} />
          <Btn variant="solid" icon="upload" size="sm" onClick={() => fileInput.current?.click()} disabled={busy}>
            {busy ? 'Uploading…' : hasPhoto ? 'Replace Photo' : 'Upload Photo'}
          </Btn>
          {hasPhoto && <Btn variant="ghost" icon="trash" size="sm" onClick={remove}>Remove</Btn>}
          <span className="t-2" style={{ fontSize: 11 }}>JPG, PNG or WebP · max 5 MB</span>
        </div>
      </Can>
    </div>
  )
}

/** Upload/view/delete KYC documents for one customer. Its own panel since it
 *  needs a customer id to exist first — only shown once editing a saved record. */
function DocumentsPanel({ customerId, me }: { customerId: number; me?: AuthUser | null }) {
  const notify = useNotify()
  const [docs, setDocs] = useState<CustomerDocument[]>([])
  const [loading, setLoading] = useState(true)
  const [docType, setDocType] = useState<CustomerDocType>('NIC')
  const [uploading, setUploading] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)
  const preview = useFilePreview()

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
    try { preview.open(await customerDocumentsApi.download(customerId, d.id), d.filePath, DOC_TYPE_OPTIONS.find((o) => o.value === d.docType)?.label || d.docType) }
    catch (ex: any) { notify.error('Could not open document', getErrorText(ex)) }
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
      {preview.node}
    </div>
  )
}

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const loanStatusTone: Record<LoanStatus, 'green' | 'red' | 'amber' | 'blue' | 'neutral'> = {
  Pending: 'amber', Approved: 'blue', Rejected: 'red', Cancelled: 'red', Active: 'green', Closed: 'neutral', WrittenOff: 'red', Restructured: 'amber',
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

/* ---- Internal CRIB-style credit report ---- */

const gradeTone: Record<string, 'green' | 'amber' | 'red'> = { A: 'green', B: 'green', C: 'amber', D: 'red', E: 'red' }

/** Colour band for a Number-of-Days-in-Arrears value. */
function ndiaCell(n: number | null): { bg: string; fg: string; text: string } {
  if (n === null) return { bg: 'var(--bg-2)', fg: 'var(--tx-3)', text: '–' }
  if (n === 0) return { bg: 'var(--ok-dim)', fg: 'var(--ok)', text: '0' }
  if (n <= 30) return { bg: 'var(--warn-dim)', fg: 'var(--warn)', text: String(n) }
  if (n <= 90) return { bg: 'rgba(224,123,26,0.18)', fg: '#e07b1a', text: String(n) }
  return { bg: 'var(--bad-dim)', fg: 'var(--bad)', text: String(n) }
}

/** Segmented 250–900 score band with the customer's score marked. */
function ScoreBand({ score, grade }: { score: number; grade: string }) {
  const MIN = 250, MAX = 900
  const zones = [
    { g: 'E', span: 210, c: 'var(--bad)' },
    { g: 'D', span: 100, c: '#e07b1a' },
    { g: 'C', span: 100, c: 'var(--warn)' },
    { g: 'B', span: 100, c: '#4a9e5c' },
    { g: 'A', span: 140, c: 'var(--ok)' },
  ]
  const pos = Math.max(0, Math.min(100, ((score - MIN) / (MAX - MIN)) * 100))
  return (
    <div style={{ flex: 1, minWidth: 240, maxWidth: 440 }}>
      <div style={{ position: 'relative', paddingTop: 18 }}>
        <div style={{ position: 'absolute', top: 0, left: `${pos}%`, transform: 'translateX(-50%)', textAlign: 'center', transition: 'left .9s var(--ease)' }}>
          <div className="num" style={{ fontSize: 11, fontWeight: 700, fontFamily: 'Saira', lineHeight: 1 }}>{score}</div>
          <div style={{ width: 0, height: 0, margin: '2px auto 0', borderLeft: '5px solid transparent', borderRight: '5px solid transparent', borderTop: '6px solid var(--tx-1)' }} />
        </div>
        <div className="row" style={{ height: 9, borderRadius: 5, overflow: 'hidden', gap: 2 }}>
          {zones.map((z) => <div key={z.g} style={{ flexGrow: z.span, background: z.c, opacity: z.g === grade ? 1 : 0.28 }} />)}
        </div>
        <div className="row" style={{ gap: 2, marginTop: 3 }}>
          {zones.map((z) => <div key={z.g} style={{ flexGrow: z.span, textAlign: 'center', fontSize: 9.5, fontWeight: z.g === grade ? 700 : 400, color: z.g === grade ? 'var(--tx-1)' : 'var(--tx-3)' }}>{z.g}</div>)}
        </div>
      </div>
    </div>
  )
}

function RStat({ icon, label, value, tone }: { icon: string; label: string; value: string; tone?: 'red' | 'amber' | 'green' }) {
  const c = tone === 'red' ? 'var(--bad)' : tone === 'amber' ? 'var(--warn)' : tone === 'green' ? 'var(--ok)' : 'var(--tx-0)'
  return (
    <div style={{ padding: '9px 11px', background: 'var(--bg-1)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
      <div className="row gap-1" style={{ alignItems: 'center', color: 'var(--tx-3)', marginBottom: 5 }}>
        <Icon n={icon} s={12} /><span style={{ fontSize: 9.5, textTransform: 'uppercase', letterSpacing: '.04em', fontWeight: 600 }}>{label}</span>
      </div>
      <div className="num" style={{ fontSize: 16, fontWeight: 700, fontFamily: 'Saira', color: c }}>{value}</div>
    </div>
  )
}

function RSection({ icon, title, children }: { icon: string; title: string; children: ReactNode }) {
  return (
    <div>
      <div className="row gap-2" style={{ alignItems: 'center', marginBottom: 9 }}>
        <Icon n={icon} s={13} /><span className="eyebrow">{title}</span>
      </div>
      {children}
    </div>
  )
}

const LEGEND: [string, string, string][] = [
  ['0 · on time', 'var(--ok-dim)', 'var(--ok)'],
  ['1–30', 'var(--warn-dim)', 'var(--warn)'],
  ['31–90', 'rgba(224,123,26,0.18)', '#e07b1a'],
  ['90+', 'var(--bad-dim)', 'var(--bad)'],
  ['no data', 'var(--bg-2)', 'var(--tx-3)'],
]

/** Read-only internal credit report for one customer — CRIB-report layout, this
 *  company's own facilities only. Fetched lazily; PDF export mirrors the screen. */
export function CreditReportPanel({ customerId, me }: { customerId: number; me?: AuthUser | null }) {
  const notify = useNotify()
  const [data, setData] = useState<CribReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [downloading, setDownloading] = useState(false)

  useEffect(() => {
    setLoading(true)
    customerCribApi.get(customerId).then(setData).catch(() => setData(null)).finally(() => setLoading(false))
  }, [customerId])

  const downloadPdf = async () => {
    setDownloading(true)
    try { downloadBlob(await customerCribApi.pdf(customerId), `credit-report-${data?.customer.code || customerId}.pdf`) }
    catch (ex: any) { notify.error('Export failed', getErrorText(ex)) }
    finally { setDownloading(false) }
  }

  if (loading) return <div className="t-2" style={{ fontSize: 12.5 }}>Loading credit report…</div>
  if (!data) return <div className="t-2" style={{ fontSize: 12.5 }}>Credit report unavailable.</div>

  const s = data.paymentProfileStatus
  const sc = data.scoring
  const gTone = gradeTone[sc.riskGrade]
  const gColor = gTone === 'green' ? 'var(--ok)' : gTone === 'amber' ? 'var(--warn)' : 'var(--bad)'
  const anyHistory = data.paymentProfileGrid.rows.some((r) => r.cells.some((c) => c.ndia !== null))

  return (
    <div className="col gap-4">
      {/* Hero — score + grade band */}
      <div style={{ padding: 16, borderRadius: 'var(--r-l)', border: '1px solid var(--line)', background: 'linear-gradient(180deg, var(--bg-1), var(--bg-0))' }}>
        <div className="row between wrap" style={{ gap: 16, alignItems: 'center' }}>
          <div className="row gap-3" style={{ alignItems: 'center' }}>
            <div style={{ minWidth: 84, textAlign: 'center', padding: '6px 10px', borderRadius: 'var(--r-m)', background: 'var(--bg-2)', borderLeft: `3px solid ${gColor}` }}>
              <div className="num" style={{ fontSize: 32, fontWeight: 800, fontFamily: 'Saira', lineHeight: 1, color: gColor }}>{sc.score}</div>
              <div className="t-3" style={{ fontSize: 9.5, marginTop: 2 }}>of {sc.scale.min}–{sc.scale.max}</div>
            </div>
            <div className="col gap-1">
              <div className="row gap-2" style={{ alignItems: 'center' }}>
                <span style={{ fontSize: 22, fontWeight: 800, fontFamily: 'Saira', color: gColor }}>{sc.riskGrade}</span>
                <Badge tone={gTone} dot>{sc.riskLabel}</Badge>
              </div>
              <span className="t-3" style={{ fontSize: 10.5 }}>Internal indicative score</span>
            </div>
          </div>
          <ScoreBand score={sc.score} grade={sc.riskGrade} />
          <Btn variant="solid" icon="download" size="sm" onClick={downloadPdf} disabled={downloading}>{downloading ? 'Preparing…' : 'Download PDF'}</Btn>
        </div>
        <div className="t-3" style={{ fontSize: 10, marginTop: 12, lineHeight: 1.5, borderTop: '1px solid var(--line-soft)', paddingTop: 10 }}>{data.disclaimer}</div>
      </div>

      {/* Payment profile status tiles */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(148px, 1fr))', gap: 10 }}>
        <RStat icon="layers" label="Open Facilities" value={String(s.openCreditFacilities)} />
        <RStat icon="check" label="Closed Facilities" value={String(s.closedCreditFacilities)} />
        <RStat icon="flag" label="Written-off" value={String(s.writtenOffCreditFacilities)} tone={s.writtenOffCreditFacilities > 0 ? 'red' : undefined} />
        <RStat icon="coins" label="Granted (open)" value={money(s.totalAmountGrantedOpen)} />
        <RStat icon="wallet" label="Balance (open)" value={money(s.totalCurrentBalanceOpen)} />
        <RStat icon="calendar" label="Installment / mo" value={money(s.totalInstallmentAmountOpen)} />
        <RStat icon="alert" label="In Arrears" value={money(s.totalAmountInArrears)} tone={s.totalAmountInArrears > 0 ? 'red' : 'green'} />
        <RStat icon="chart" label="Highest NDIA 12m" value={`${s.highestNdia12Months}d`} tone={s.highestNdia12Months > 90 ? 'red' : s.highestNdia12Months > 0 ? 'amber' : 'green'} />
      </div>
      <div className="t-3" style={{ fontSize: 11, marginTop: -6 }}>
        Highest current NDIA <b style={{ color: 'var(--tx-1)' }}>{s.highestCurrentNdia} days</b>
        {'  ·  '}Inquiries <b style={{ color: 'var(--tx-1)' }}>{data.inquiries.last6Months}</b> in 6 mo, <b style={{ color: 'var(--tx-1)' }}>{data.inquiries.last12Months}</b> in 12 mo
      </div>

      {/* Payment profile heatmap */}
      {data.paymentProfileGrid.rows.length > 0 && (
        <RSection icon="grid" title="Payment Profile — Days in Arrears by Month">
          <div style={{ overflowX: 'auto', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
            <table style={{ borderCollapse: 'separate', borderSpacing: 0, fontSize: 11, width: '100%' }}>
              <thead>
                <tr>
                  <th style={{ textAlign: 'left', padding: '7px 10px', position: 'sticky', left: 0, background: 'var(--bg-2)', color: 'var(--tx-2)', fontWeight: 600, zIndex: 1 }}>Facility</th>
                  {data.paymentProfileGrid.months.map((m) => (
                    <th key={m} style={{ padding: '7px 6px', background: 'var(--bg-2)', color: 'var(--tx-2)', fontWeight: 600, whiteSpace: 'nowrap', textAlign: 'center' }}>{m}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.paymentProfileGrid.rows.map((r, ri) => (
                  <tr key={r.code}>
                    <td style={{ padding: '6px 10px', whiteSpace: 'nowrap', position: 'sticky', left: 0, background: ri % 2 ? 'var(--bg-1)' : 'var(--bg-0)', borderTop: '1px solid var(--line-soft)' }}>
                      <b>{r.code}</b> <span className="t-3" style={{ fontSize: 10 }}>{r.product}</span>
                    </td>
                    {r.cells.map((c, i) => {
                      const cc = ndiaCell(c.ndia)
                      return (
                        <td key={i} style={{ padding: '4px 5px', textAlign: 'center', borderTop: '1px solid var(--line-soft)' }}>
                          <span style={{ display: 'inline-block', minWidth: 26, padding: '3px 5px', borderRadius: 5, background: cc.bg, color: cc.fg, fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>{cc.text}</span>
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="row gap-3 wrap" style={{ marginTop: 8 }}>
            {LEGEND.map(([lbl, bg, fg]) => (
              <span key={lbl} className="row gap-1" style={{ alignItems: 'center', fontSize: 10, color: 'var(--tx-3)' }}>
                <span style={{ width: 12, height: 12, borderRadius: 3, background: bg, border: '1px solid var(--line)', display: 'inline-block' }} />
                <span style={{ color: fg }}>{lbl}</span>
              </span>
            ))}
          </div>
          {!anyHistory && <div className="t-3" style={{ fontSize: 10.5, marginTop: 6 }}>No repayment history yet for the months shown.</div>}
        </RSection>
      )}

      {/* Credit facilities */}
      <RSection icon="ledger" title="Credit Facilities">
        <Table cols={[
          { label: 'Code' }, { label: 'Product' }, { label: 'Status', align: 'center' as const },
          { label: 'Granted', align: 'right' as const }, { label: 'Balance', align: 'right' as const }, { label: 'Arrears', align: 'right' as const },
          { label: 'Installment', align: 'right' as const }, { label: 'NDIA', align: 'right' as const }, { label: 'Disbursed' }, { label: 'Maturity' },
        ]} rows={data.creditFacilities} empty="No credit facilities on record"
          render={(f) => <>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{f.code}</Td>
            <Td>{f.product}</Td>
            <Td align="center"><Badge tone={f.status === 'Active' ? 'green' : f.status === 'WrittenOff' ? 'red' : 'neutral'} dot>{f.status}</Badge></Td>
            <Td align="right" mono>{money(f.amountGranted)}</Td>
            <Td align="right" mono>{money(f.currentBalance)}</Td>
            <Td align="right" mono c={f.amountInArrears > 0 ? 'var(--bad)' : undefined}>{money(f.amountInArrears)}</Td>
            <Td align="right" mono>{money(f.installmentAmount)}</Td>
            <Td align="right" mono>{f.currentNdia === null ? '–' : <span style={{ color: ndiaCell(f.currentNdia).fg, fontWeight: 700 }}>{f.currentNdia}</span>}</Td>
            <Td>{dateOnly(f.firstDisbursementDate)}</Td>
            <Td>{dateOnly(f.maturityDate)}</Td>
          </>} />
      </RSection>

      {data.guarantorFacilities.length > 0 && (
        <RSection icon="shield" title="Facilities Guaranteed by this Customer">
          <Table cols={[
            { label: 'Code' }, { label: 'Borrower' }, { label: 'Status', align: 'center' as const },
            { label: 'Granted', align: 'right' as const }, { label: 'Balance', align: 'right' as const }, { label: 'Guaranteed', align: 'right' as const },
          ]} rows={data.guarantorFacilities} empty=""
            render={(g) => <>
              <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{g.code}</Td>
              <Td>{g.borrowerName || '—'}</Td>
              <Td align="center"><Badge tone={g.status === 'Active' ? 'green' : 'neutral'} dot>{g.status}</Badge></Td>
              <Td align="right" mono>{money(g.amountGranted)}</Td>
              <Td align="right" mono>{money(g.currentBalance)}</Td>
              <Td align="right" mono>{g.guaranteedAmount == null ? '—' : money(g.guaranteedAmount)}</Td>
            </>} />
        </RSection>
      )}
    </div>
  )
}

function CustomerView({ customer, me, onEdit, onOpenLoan, onOpenCredit }: { customer: Customer; me?: AuthUser | null; onEdit: () => void; onOpenLoan: (loanId: number) => void; onOpenCredit: () => void }) {
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
      {customer.status === 'Blacklisted' && (
        <div style={{ marginBottom: 16, padding: '10px 14px', borderRadius: 'var(--r-m)', background: 'var(--bad-dim)', color: 'var(--bad)', fontSize: 13, fontWeight: 600 }}>
          BLACKLISTED — cannot be given any facility in any branch.{customer.blacklistReason ? ` Reason: ${customer.blacklistReason}` : ''}{customer.blacklistNote ? ` — ${customer.blacklistNote}` : ''}
        </div>
      )}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>
        <Card>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Personal Details</div>
          <div className="row gap-3" style={{ alignItems: 'flex-start', marginBottom: 12 }}>
            <CustomerAvatar customerId={customer.id} hasPhoto={customer.hasPhoto} size={84} />
            <div>
              <div style={{ fontSize: 14, fontWeight: 600 }}>{customer.fullName}</div>
              <div className="t-2" style={{ fontSize: 12 }}>{customer.code}</div>
            </div>
          </div>
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

      <Card style={{ marginBottom: 16 }}>
        <div className="row between wrap" style={{ gap: 12, alignItems: 'center' }}>
          <div>
            <div className="eyebrow" style={{ marginBottom: 4 }}>Credit Report (Internal — CRIB-style)</div>
            <div className="t-2" style={{ fontSize: 12.5 }}>Payment profile, credit facilities and internal indicative score for this customer.</div>
          </div>
          <Btn variant="primary" icon="chart" onClick={onOpenCredit}>Open Credit Report</Btn>
        </div>
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

const ROUTE_BASE = '/customers'

export function CustomersScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const navigate = useNavigate()
  const params = useParams<{ id?: string; loanId?: string }>()
  const location = useLocation()
  const [rows, setRows] = useState<Customer[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [branches, setBranches] = useState<Branch[]>([])
  const [search, setSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState<CustomerTypeFilter>('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [branchFilter, setBranchFilter] = useState('')
  const [exporting, setExporting] = useState(false)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [mode, setMode] = useState<'list' | 'view' | 'form' | 'loan' | 'credit'>('list')
  const [editing, setEditing] = useState<Customer | null>(null)
  const [viewing, setViewing] = useState<Customer | null>(null)
  const [viewLoading, setViewLoading] = useState(false)
  const [viewingLoan, setViewingLoan] = useState<Loan | null>(null)
  const [loanLoading, setLoanLoading] = useState(false)
  const [form, setForm] = useState<Partial<Customer>>(blankForm())
  const set = <K extends keyof Customer>(k: K, v: Customer[K]) => setForm((s) => ({ ...s, [k]: v }))
  const [formErrors, setFormErrors] = useState<{ nic?: string; phone?: string; altPhone?: string; email?: string }>({})
  const customerDraft = useFormDraft('customer:new', mode === 'form' && !editing, form, blankForm())

  const onNicChange = (v: string) => {
    set('nicNo', v as any)
    setFormErrors((s) => ({ ...s, nic: validateNic(v) }))
    const dob = nicToDob(v)
    if (dob) set('dob', dob as any)
  }
  const onPhoneChange = (v: string) => { set('phone', v as any); setFormErrors((s) => ({ ...s, phone: validatePhone(v) })) }
  const onAltPhoneChange = (v: string) => { set('altPhone', v as any); setFormErrors((s) => ({ ...s, altPhone: validatePhone(v) })) }
  const onEmailChange = (v: string) => { set('email', v as any); setFormErrors((s) => ({ ...s, email: validateEmail(v) })) }

  const listFilters = () => ({
    search: debouncedSearch || undefined,
    customerType: typeFilter || undefined,
    from: fromDate || undefined,
    to: toDate || undefined,
    branchId: branchFilter ? Number(branchFilter) : undefined,
  })

  const refresh = () => {
    setLoading(true)
    customersApi.browse({ ...listFilters(), page })
      .then((r) => { setRows(r.data); setLastPage(r.lastPage); setTotal(r.total) })
      .catch(() => { setRows([]); setLastPage(1); setTotal(0) })
      .finally(() => setLoading(false))
  }
  useEffect(() => {
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
  }, [])

  // Search text is debounced so a keystroke doesn't fire a request per
  // character — see LoanCalculator.tsx for the same debounce-ref shape.
  const debounceRef = useRef<ReturnType<typeof setTimeout>>()
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current) }
  }, [search])

  // A search change always jumps back to page 1 — staying on page 5 of a
  // now-different, shorter result set would just show an empty page.
  useEffect(() => { setPage(1) }, [debouncedSearch, typeFilter, fromDate, toDate, branchFilter])

  useEffect(() => {
    if (mode !== 'list') return
    refresh()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch, page, mode, typeFilter, fromDate, toDate, branchFilter])

  // Which customer/sub-view is open lives in the URL — see the effect below —
  // so a hard refresh on a view/edit/loan URL reopens the same screen instead
  // of bouncing back to the list.
  const openCreate = () => navigate(`${ROUTE_BASE}/new`)
  const openEdit = (c: Customer) => navigate(`${ROUTE_BASE}/${c.id}/edit`)
  const openView = (c: Customer) => navigate(`${ROUTE_BASE}/${c.id}`)
  const back = () => navigate(ROUTE_BASE)

  const openLoan = (loanId: number) => navigate(`${ROUTE_BASE}/${params.id}/loan/${loanId}`)
  const openCredit = (c: Customer) => navigate(`${ROUTE_BASE}/${c.id}/credit-report`)
  const backToCustomer = () => navigate(`${ROUTE_BASE}/${params.id}`)
  const reloadLoan = async () => { if (viewingLoan) setViewingLoan(await loansApi.get(viewingLoan.id)) }

  const isNew = location.pathname === `${ROUTE_BASE}/new`
  const isEdit = !!params.id && location.pathname === `${ROUTE_BASE}/${params.id}/edit`
  const isCredit = !!params.id && location.pathname === `${ROUTE_BASE}/${params.id}/credit-report`
  const isLoan = !!params.loanId

  useEffect(() => {
    if (isNew) { setEditing(null); setForm(blankForm()); setFormErrors({}); setErr(''); setMode('form'); return }
    if (!params.id) { setMode('list'); setEditing(null); setViewing(null); setViewingLoan(null); return }

    let cancelled = false
    setViewLoading(true)
    customersApi.get(Number(params.id)).then((c) => {
      if (cancelled) return
      setViewing(c)
      if (isEdit) {
        setEditing(c)
        // employmentDetail/businessDetail are read-only nested relations — flatten
        // them back onto the form's flat fields, which is what the save API accepts.
        setForm({ ...c, ...(c.employmentDetail || {}), ...(c.businessDetail || {}) })
        setFormErrors({}); setErr(''); setMode('form')
      } else if (isLoan) {
        setMode('loan'); setLoanLoading(true)
        loansApi.get(Number(params.loanId)).then((l) => { if (!cancelled) setViewingLoan(l) }).catch(() => { if (!cancelled) setViewingLoan(null) }).finally(() => { if (!cancelled) setLoanLoading(false) })
      } else if (isCredit) {
        setMode('credit')
      } else {
        setMode('view')
      }
    }).catch(() => {
      if (cancelled) return
      notify.error('Customer not found')
      navigate(ROUTE_BASE, { replace: true })
    }).finally(() => { if (!cancelled) setViewLoading(false) })
    return () => { cancelled = true }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id, params.loanId, isNew, isEdit, isCredit])

  const save = async () => {
    if (!form.title || !form.surname || !form.initials) { setErr('Title, Surname and Initial are required.'); return }
    if (!form.branchId) { setErr('Branch is required.'); return }
    if (formErrors.nic || formErrors.phone || formErrors.altPhone || formErrors.email) { setErr('Fix the validation errors before saving.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await customersApi.update(editing.id, form)
      else await customersApi.create(form)
      customerDraft.clear()
      back(); refresh()
      notify.success(editing ? 'Customer updated' : 'Customer created', [form.title, form.initials, form.surname].filter(Boolean).join(' '))
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  /** Excel of every customer matching the current filters (all pages), with a Customer Type column. */
  const exportExcel = async () => {
    setExporting(true)
    try {
      const all: Customer[] = []
      for (let p = 1; ; p++) {
        const r = await customersApi.browse({ ...listFilters(), page: p, perPage: 500 })
        all.push(...r.data)
        if (p >= r.lastPage) break
      }
      const rangeTxt = fromDate || toDate ? `${fromDate || '…'} to ${toDate || '…'}` : 'All dates'
      const count = (t: string) => all.filter((c) => customerTypes(c).includes(t)).length
      await exportFlatTableToExcel({
        reportTitle: 'Customer Report',
        subtitle: `Created: ${rangeTxt} · Type: ${typeFilter || 'All'} · Branch: ${branches.find((b) => String(b.id) === branchFilter)?.name || 'All'}`,
        meta: `Total ${all.length} · Loan ${count('Loan')} · FD ${count('FD')} · Guarantor ${count('Guarantor')}`,
        columns: [
          { label: 'Code', key: 'code', align: 'left' }, { label: 'Customer', key: 'name', align: 'left', width: 30 },
          { label: 'Customer Type', key: 'type', align: 'left' }, { label: 'NIC / ID', key: 'nic', align: 'left' },
          { label: 'Phone', key: 'phone', align: 'left' }, { label: 'Branch', key: 'branch', align: 'left' },
          { label: 'Status', key: 'status', align: 'left' }, { label: 'Created Date', key: 'created', align: 'left' },
        ],
        rows: all.map((c) => ({
          code: c.code, name: c.fullName, type: customerTypes(c).join(', ') || 'Customer only',
          nic: c.nicNo || c.passportNo || c.drivingLicenseNo || '', phone: c.phone || '', branch: c.branch?.name || '',
          status: c.status || 'Active', created: dateOnly(c.createdAt) === '—' ? '' : dateOnly(c.createdAt),
        })),
        sheetName: 'Customers',
      }, `customers-${new Date().toISOString().slice(0, 10)}.xlsx`)
    } catch (ex: any) { notify.error('Export failed', getErrorText(ex)) }
    finally { setExporting(false) }
  }

  const remove = async (c: Customer) => {
    const ok = await notify.confirm({ title: `Delete customer "${c.fullName}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await customersApi.remove(c.id); refresh(); notify.success('Customer deleted', c.fullName) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const isEmployed = form.occupationType === 'Employed'
  const isBusiness = form.occupationType === 'SelfEmployed' || form.occupationType === 'BusinessOwner'

  if (mode === 'view') {
    return (
      <div>
        <PageHead crumbs="Customers" title={viewLoading || !viewing ? 'Loading…' : viewing.fullName} icon="users"
          sub={viewing ? `${viewing.code} — ${viewing.branch?.name || ''}` : undefined}
          actions={<Btn variant="solid" onClick={back}>← Back to Customers</Btn>} />
        {viewLoading || !viewing ? <LoadingBlock />
          : <CustomerView customer={viewing} me={me} onEdit={() => openEdit(viewing)} onOpenLoan={openLoan} onOpenCredit={() => openCredit(viewing)} />}
      </div>
    )
  }

  if (mode === 'credit') {
    return (
      <div>
        <PageHead crumbs={`Customers / ${viewing?.fullName || ''}`} title="Credit Report" icon="chart"
          sub={viewing ? `${viewing.code} — Internal, CRIB-style` : 'Internal, CRIB-style'}
          actions={<Btn variant="solid" onClick={backToCustomer}>← Back to Customer</Btn>} />
        {viewLoading || !viewing ? <LoadingBlock />
          : <Card><CreditReportPanel customerId={viewing.id} me={me} /></Card>}
      </div>
    )
  }

  if (mode === 'loan') {
    return (
      <div>
        <div className="no-print">
          <PageHead crumbs={`Customers / ${viewing?.fullName || ''}`} title={loanLoading || !viewingLoan ? 'Loading…' : `Loan ${viewingLoan.code}`} icon="coins"
            sub={viewingLoan ? `${viewingLoan.customer?.fullName} — ${viewingLoan.loanDetail?.loanType?.name}` : undefined}
            actions={<Btn variant="solid" onClick={backToCustomer}>← Back to Customer</Btn>} />
        </div>
        {loanLoading || !viewingLoan ? <LoadingBlock />
          : <LoanDetail loan={viewingLoan} me={me} onChanged={reloadLoan} go={go} context="active" />}
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

        {customerDraft.draft && (
          <DraftBanner savedAt={customerDraft.draft.ts}
            onRestore={() => { setForm(customerDraft.draft!.value); customerDraft.accept() }}
            onDiscard={customerDraft.clear} />
        )}

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
            <Field label="Status">
              {form.status === 'Blacklisted'
                ? <Input value="Blacklisted (change in Sanction / Blacklist)" disabled />
                : <Select value={form.status || 'Active'} onChange={(e) => set('status', e.target.value as any)}><option>Active</option><option>Inactive</option></Select>}
            </Field>
            <Field label="NIC Number">
              <Input value={form.nicNo || ''} onChange={(e) => onNicChange(e.target.value)} />
              <FieldError msg={formErrors.nic} />
            </Field>
            <Field label="Passport Number"><Input value={form.passportNo || ''} onChange={(e) => set('passportNo', e.target.value)} /></Field>
            <Field label="Driving License Number"><Input value={form.drivingLicenseNo || ''} onChange={(e) => set('drivingLicenseNo', e.target.value)} /></Field>
            <Field label="Date of Birth"><Input type="date" value={form.dob || ''} onChange={(e) => set('dob', e.target.value)} /></Field>
            <Field label="Gender"><Select value={form.gender || ''} onChange={(e) => set('gender', e.target.value)}>
              <option value="">—</option><option>Male</option><option>Female</option><option>Other</option>
            </Select></Field>
            <Field label="Phone">
              <Input value={form.phone || ''} onChange={(e) => onPhoneChange(e.target.value)} />
              <FieldError msg={formErrors.phone} />
            </Field>
            <Field label="Alternate Phone">
              <Input value={form.altPhone || ''} onChange={(e) => onAltPhoneChange(e.target.value)} />
              <FieldError msg={formErrors.altPhone} />
            </Field>
            <Field label="Email">
              <Input type="email" value={form.email || ''} onChange={(e) => onEmailChange(e.target.value)} />
              <FieldError msg={formErrors.email} />
            </Field>
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

        <Card style={{ marginBottom: 16 }}>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Profile Photo</div>
          {editing
            ? <PhotoPanel customer={editing} me={me} />
            : <div className="t-2" style={{ fontSize: 12.5, fontStyle: 'italic' }}>Save the customer first to attach a photo.</div>}
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
      <PageHead crumbs="Customers" title="Customer Management" icon="users" sub={`${total} customers`}
        actions={<Can user={me} module="Customer Management" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Customer</Btn></Can>} />
      <Card pad={0}>
        <div className="row gap-2" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line)', alignItems: 'center' }}>
          <div style={{ position: 'relative', maxWidth: 340, flex: 1 }}>
            <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--tx-2)' }}><Icon n="search" s={15} /></span>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name, NIC, phone, branch…" style={{ paddingLeft: 32 }} />
          </div>
          {(search.trim() || typeFilter || fromDate || toDate || branchFilter) && <span className="t-2" style={{ fontSize: 12 }}>{total} match{total === 1 ? '' : 'es'}</span>}
        </div>
        <div className="row gap-2 wrap" style={{ padding: '10px 16px', borderBottom: '1px solid var(--line)', alignItems: 'flex-end' }}>
          <Field label="Customer Type"><Select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value as CustomerTypeFilter)} style={{ minWidth: 150 }}>
            <option value="">All</option><option value="Loan">Loan</option><option value="FD">FD</option><option value="Guarantor">Guarantor</option>
          </Select></Field>
          <Field label="Created From"><Input type="date" value={fromDate} max={toDate || undefined} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="Created To"><Input type="date" value={toDate} min={fromDate || undefined} onChange={(e) => setToDate(e.target.value)} /></Field>
          <Field label="Branch"><Select value={branchFilter} onChange={(e) => setBranchFilter(e.target.value)} style={{ minWidth: 150 }}>
            <option value="">All</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          {(typeFilter || fromDate || toDate || branchFilter) && <Btn variant="ghost" size="sm" onClick={() => { setTypeFilter(''); setFromDate(''); setToDate(''); setBranchFilter('') }}>Clear</Btn>}
          <div style={{ marginLeft: 'auto' }}><Btn variant="solid" icon="download" size="sm" onClick={exportExcel} disabled={exporting}>{exporting ? 'Preparing…' : 'Export Excel'}</Btn></div>
        </div>
        {loading ? <LoadingBlock /> : <>
        <Table cols={[{ label: 'Customer' }, { label: 'NIC / ID' }, { label: 'Customer Type' }, { label: 'Phone' }, { label: 'Branch' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          rows={rows}
          empty={search.trim() ? 'No customers match your search' : 'No records'}
          onRow={openView}
          render={(c) => <>
            <Td c="var(--tx-0)"><div className="row gap-2" style={{ alignItems: 'center' }}><span style={{ fontWeight: 600 }}>{c.fullName}</span></div><div className="t-2" style={{ fontSize: 11.5 }}>{c.code}</div></Td>
            <Td mono style={{ fontSize: 12 }}>{c.nicNo || c.passportNo || c.drivingLicenseNo || '—'}</Td>
            <Td>{customerTypes(c).length
              ? <div className="row gap-1 wrap">{customerTypes(c).map((t) => <Badge key={t} tone={typeTone[t]}>{t}</Badge>)}</div>
              : '—'}</Td>
            <Td>{c.phone || '—'}</Td>
            <Td>{c.branch?.name || '—'}</Td>
            <Td align="center"><Badge tone={statusTone[c.status || 'Active']} dot>{c.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }} onClick={(e) => e.stopPropagation()}>
              <Can user={me} module="Customer Management" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(c)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Customer Management" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(c)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />
        <Pager page={page} lastPage={lastPage} total={total} onChange={setPage} />
        </>}
      </Card>
    </div>
  )
}
