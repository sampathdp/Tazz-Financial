/* Tazz Investment & Credit — Loan > Print Documents tab.
   A small, growing library of printable loan documents (currently just the
   Credit Facility Application form). Picking one flips this panel into a
   full-bleed printable page, following the same window.print()/.no-print
   pattern as PaymentReceipt.tsx and the other print screens — there's no
   PDF library in this app, the browser's own print/"Save as PDF" is the
   printer. Fields are pre-filled from whatever the system already holds
   (customer, employment/business detail, guarantors); anything the data
   model doesn't capture (previous address, assets, income/expenditure
   breakdown, bank facilities, legal proceedings) is left as a blank cell
   for the interviewing officer to fill in by hand, same as the paper form. */
import { useEffect, useState, type CSSProperties, type ReactNode } from 'react'
import { Card, Btn } from '../../components/ui'
import { Icon } from '../../components/Icon'
import { company, type Loan, type AuthUser } from '../../api'

const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '')
const money = (v: unknown) => (v == null || v === '' ? '' : Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }))

/** Years elapsed since a start date, rounded down — for "No of years with present Employer". */
const yearsSince = (v?: string | null) => {
  if (!v) return ''
  const start = new Date(v)
  if (isNaN(start.getTime())) return ''
  const years = (Date.now() - start.getTime()) / (365.25 * 24 * 3600 * 1000)
  return years >= 0 ? Math.floor(years).toString() : ''
}

const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
  'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen']
const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety']

/** Whole rupees as English words, standard international grouping (Thousand/Million/Billion) — for the promissory note's "Rupees ... only" line. */
function amountInWords(v: unknown): string {
  let n = Math.floor(Number(v ?? 0))
  if (!isFinite(n) || n <= 0) return ''
  const words: string[] = []
  const scales: [number, string][] = [[1_000_000_000, 'Billion'], [1_000_000, 'Million'], [1_000, 'Thousand'], [1, '']]
  for (const [scale, label] of scales) {
    if (n >= scale) {
      const count = Math.floor(n / scale)
      n -= count * scale
      let c = ''
      let num = count
      if (num >= 100) { c += ONES[Math.floor(num / 100)] + ' Hundred '; num %= 100 }
      if (num >= 20) { c += TENS[Math.floor(num / 10)] + (num % 10 ? '-' + ONES[num % 10] : '') + ' ' }
      else if (num > 0) { c += ONES[num] + ' ' }
      if (c.trim()) words.push(c.trim() + (label ? ' ' + label : ''))
    }
  }
  return words.join(' ').replace(/\s+/g, ' ').trim()
}

type DocKey = 'credit_application' | 'loan_agreement' | 'personal_guarantee' | 'promissory_note' | 'leasing_offer' | 'leasing_acceptance'

const DOCS: { key: DocKey; label: string; desc: string; icon: string; show?: (loan: Loan) => boolean }[] = [
  { key: 'credit_application', label: 'Credit Facility Application', desc: 'Personal/Business application form — applicant, guarantor, employment and income sections pre-filled from system data.', icon: 'doc' },
  { key: 'loan_agreement', label: 'Loan Agreement', desc: 'Standard loan agreement terms and conditions — member details and repayment terms pre-filled; signature and witness lines left blank.', icon: 'doc' },
  { key: 'personal_guarantee', label: 'Personal Guarantee', desc: 'Guarantor undertaking for this loan — borrower and guarantor names/NIC/address pre-filled; signature, witness and date lines left blank.', icon: 'doc',
    show: (loan) => !!loan.guarantors?.length },
  { key: 'promissory_note', label: 'Promissory Note', desc: 'Amount (in figures and words), borrower and date pre-filled; monthly interest rate filled when the loan is priced per month; signatures and witnesses left blank.', icon: 'doc' },
  { key: 'leasing_offer', label: 'Leasing Facility Offer Letter', desc: 'Approved facility terms — vehicle, amount, rate and rental pre-filled from the loan; offer validity date left blank.', icon: 'doc',
    show: (loan) => loan.loanDetail?.loanType?.category === 'Leasing' },
  { key: 'leasing_acceptance', label: 'Leasing Facility Acceptance Letter', desc: 'Customer\'s acceptance of the offer letter terms — name, NIC and address pre-filled; witness and signature lines left blank.', icon: 'doc',
    show: (loan) => loan.loanDetail?.loanType?.category === 'Leasing' },
]

export function PrintDocumentsPanel({ loan, me }: { loan: Loan; me?: AuthUser | null }) {
  const [open, setOpen] = useState<DocKey | null>(null)
  const [brand, setBrand] = useState<{ name: string; tagline: string; hasLogo: boolean }>({ name: '', tagline: '', hasLogo: false })

  useEffect(() => { company.branding().then(setBrand).catch(() => {}) }, [])

  if (open) {
    const docLabel = DOCS.find((d) => d.key === open)?.label || 'Document'
    return (
      <div>
        <div className="row gap-2 no-print" style={{ marginBottom: 16 }}>
          <Btn variant="ghost" icon="list" onClick={() => setOpen(null)}>Back to Documents</Btn>
          <Btn variant="primary" icon="print" onClick={() => window.print()}>Print {docLabel}</Btn>
        </div>
        {open === 'credit_application' && <CreditFacilityApplication loan={loan} me={me} brand={brand} />}
        {open === 'loan_agreement' && <LoanAgreement loan={loan} brand={brand} />}
        {open === 'personal_guarantee' && <PersonalGuarantee loan={loan} brand={brand} />}
        {open === 'promissory_note' && <PromissoryNote loan={loan} brand={brand} />}
        {open === 'leasing_offer' && <LeasingOfferLetter loan={loan} brand={brand} />}
        {open === 'leasing_acceptance' && <LeasingAcceptanceLetter loan={loan} brand={brand} />}
      </div>
    )
  }

  const docs = DOCS.filter((d) => !d.show || d.show(loan))
  return (
    <Card>
      <div className="eyebrow" style={{ marginBottom: 12 }}>Print Documents</div>
      <div className="col gap-2">
        {docs.map((d) => (
          <div key={d.key} className="row between wrap gap-2" style={{ padding: '12px 14px', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
            <div className="row gap-2" style={{ alignItems: 'flex-start' }}>
              <Icon n={d.icon} />
              <div>
                <div style={{ fontWeight: 600, fontSize: 13.5 }}>{d.label}</div>
                <div className="t-2" style={{ fontSize: 12, maxWidth: 480 }}>{d.desc}</div>
              </div>
            </div>
            <Btn size="sm" variant="solid" icon="print" onClick={() => setOpen(d.key)}>Print</Btn>
          </div>
        ))}
      </div>
    </Card>
  )
}

/* ======================= Credit Facility Application ======================= */

const cell: CSSProperties = { border: '1px solid #000', padding: '4px 6px', verticalAlign: 'top' }
const label: CSSProperties = { ...cell, fontWeight: 700, background: '#f2f2f2' }
const blank = <span style={{ color: 'transparent' }}>.</span>

/* No minHeight here — forcing each sheet to a full 297mm tall, on top of the
   browser's own print margin, overflowed one page's worth of content onto a
   second, near-blank physical page (and could slice a table's border across
   that split). Sheets size to their actual content; breakBefore forces the
   next sheet onto a fresh page regardless of how short the previous one is. */
function Sheet({ children, breakBefore }: { children: ReactNode; breakBefore?: boolean }) {
  return (
    <div className="credit-app-sheet" style={{
      width: '210mm', margin: '0 auto 24px', padding: '12mm',
      background: '#fff', color: '#000', boxSizing: 'border-box',
      fontFamily: 'Arial, Helvetica, sans-serif', fontSize: 10.5, lineHeight: 1.35,
      pageBreakBefore: breakBefore ? 'always' : undefined,
    }}>
      {children}
    </div>
  )
}

function CreditFacilityApplication({ loan, me, brand }: { loan: Loan; me?: AuthUser | null; brand: { name: string; hasLogo: boolean } }) {
  const c = loan.customer
  const spouse = loan.coApplicants?.[0]?.coApplicant
  const g1 = loan.guarantors?.[0]?.guarantor
  const g2 = loan.guarantors?.[1]?.guarantor
  const emp = c?.employmentDetail
  const biz = c?.businessDetail
  const selfEmployed = c?.occupationType === 'SelfEmployed' || c?.occupationType === 'BusinessOwner'
  const officialAddress = emp?.employerAddress || biz?.businessAddress || ''
  const officialPhone = emp?.employerPhone || biz?.businessPhone || ''
  const presentEmployerLine = selfEmployed
    ? [biz?.businessName, biz?.businessAddress].filter(Boolean).join(', ')
    : [emp?.employerName, emp?.employerAddress].filter(Boolean).join(', ')

  return (
    <>
      <Sheet>
        <div style={{ textAlign: 'center', marginBottom: 10 }}>
          {brand.hasLogo && <img src={company.logoUrl} alt="" style={{ maxHeight: 40, marginBottom: 4 }} />}
          <div style={{ fontWeight: 700, fontSize: 15, textDecoration: 'underline' }}>{(brand.name || 'Company Name').toUpperCase()}</div>
          <div style={{ fontWeight: 700, fontSize: 12.5, textDecoration: 'underline', marginTop: 6 }}>APPLICATION FOR CREDIT FACILITIES</div>
          <div style={{ fontSize: 11 }}>(Personal / Business)</div>
        </div>

        <div style={{ border: '1px solid #000', padding: '4px 6px', fontSize: 9.5, fontStyle: 'italic', marginBottom: 6 }}>
          Note: To consider your application expeditiously please ensure that all cages are filled. If cage is not relevant, please state Nil / NONE or INAPPLICABLE
        </div>

        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 4, pageBreakInside: 'avoid' }}><tbody>
          <tr>
            <td style={{ ...label, width: '50%' }}>1. MAIN APPLICANT'S NAME IN FULL &amp; NIC:<br /><span style={{ fontWeight: 400 }}>Mr. / Ms. / Miss</span></td>
            <td style={{ ...label, width: '50%' }}>2. NAME &amp; NIC OF SPOUSE/ APPLICANT 2:</td>
          </tr>
          <tr>
            <td style={{ ...cell, height: 40 }}>{c?.fullName}{c?.nicNo ? ` — ${c.nicNo}` : ''}</td>
            <td style={cell}>{spouse ? `${spouse.fullName}${spouse.nicNo ? ` — ${spouse.nicNo}` : ''}` : blank}</td>
          </tr>
          <tr>
            <td style={label}>3. HOME ADDRESS:</td>
            <td style={label}>4. OFFICIAL ADDRESS:</td>
          </tr>
          <tr>
            <td style={{ ...cell, height: 50 }}>
              {c?.address || blank}
              <div style={{ marginTop: 10 }}>Telephone No: <b>{c?.phone || blank}</b></div>
              <div>E-mail: <b>{c?.email || blank}</b></div>
            </td>
            <td style={cell}>
              {officialAddress || blank}
              <div style={{ marginTop: 10 }}>Telephone No: <b>{officialPhone || blank}</b></div>
              <div>E-mail: <b>{blank}</b></div>
            </td>
          </tr>
          <tr>
            <td style={label}>5. PREVIOUS ADDRESS:<br /><span style={{ fontWeight: 400, fontSize: 9 }}>(If you not in the present address during the past 5 years)</span></td>
            <td style={label}>6. DATE OF BIRTH: <span style={{ fontWeight: 400 }}>{dateOnly(c?.dob) || blank}</span>
              <div style={{ fontWeight: 400, marginTop: 6 }}>
                a) Civil Status &nbsp;: Married / Single<br />
                b) Nationality &nbsp;&nbsp;&nbsp;: {blank}<br />
                c) National ID. Card No : {c?.nicNo || blank}
              </div>
            </td>
          </tr>
          <tr><td style={{ ...cell, height: 30 }}>{blank}</td><td style={cell}></td></tr>
          <tr><td style={label} colSpan={2}>7. FACILITIES SOUGHT: (Attach a copy of BOQ/ Invoice/ Quotation/ Receipt)</td></tr>
        </tbody></table>

        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 4, pageBreakInside: 'avoid' }}><tbody>
          <tr><td style={{ ...label, width: '25%' }}>Type</td><td style={{ ...label, width: '20%' }}>Amount</td><td style={label}>Purpose</td></tr>
          <tr>
            <td style={{ ...cell, height: 26 }}>{loan.loanDetail?.loanType?.name || blank}</td>
            <td style={cell}>{money(loan.principalAmount)}</td>
            <td style={cell}>{loan.loanDetail?.purpose || blank}</td>
          </tr>
        </tbody></table>

        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 4, pageBreakInside: 'avoid' }}><tbody>
          <tr><td style={label} colSpan={2}>8. SECURITY OFFER: Personal Guarantors (Copies of NIC, Address proof, Bank/Income statements/Pay slips attached)</td></tr>
          <tr>
            <td style={{ ...cell, width: 90, fontWeight: 700 }}>a. Guarantor 01 :</td>
            <td style={cell}>
              <div>Name &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {g1?.fullName || blank}</div>
              <div>Address &nbsp;&nbsp;&nbsp;: {g1?.address || blank}</div>
              <div>Relationship : {blank}</div>
              <div>Mobile &amp; NIC : {[g1?.phone, g1?.nicNo].filter(Boolean).join(' / ') || blank}</div>
            </td>
          </tr>
          <tr>
            <td style={{ ...cell, fontWeight: 700 }}>b. Guarantor 02 :</td>
            <td style={cell}>
              <div>Name &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {g2?.fullName || blank}</div>
              <div>Address &nbsp;&nbsp;&nbsp;: {g2?.address || blank}</div>
              <div>Relationship : {blank}</div>
              <div>Mobile &amp; NIC : {[g2?.phone, g2?.nicNo].filter(Boolean).join(' / ') || blank}</div>
            </td>
          </tr>
        </tbody></table>

        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 4, pageBreakInside: 'avoid' }}><tbody>
          <tr><td style={label} colSpan={2}>9. EMPLOYMENT / VOCATION</td></tr>
          <tr>
            <td style={{ ...cell, width: '55%' }}>
              9.1 Name &amp; address of present employer:<br />{presentEmployerLine || blank}
            </td>
            <td style={cell}>
              9.5 No of years with present Employer: {yearsSince(emp?.employmentStartDate) || blank}
            </td>
          </tr>
          <tr>
            <td style={cell}>9.2 Business of Employer: {biz?.businessType || blank}</td>
            <td style={cell}>
              9.6 If you were not with the present Employer for the last 3 years<br />
              &nbsp;&nbsp;a. Name of previous Employer: {blank}<br />
              &nbsp;&nbsp;b. Period of Employment: {blank}
            </td>
          </tr>
          <tr><td style={cell} colSpan={2}>9.3 Your Position: {emp?.jobTitle || blank}</td></tr>
          <tr><td style={cell} colSpan={2}>9.4 Are you confirmed in service? YES / NO</td></tr>
          <tr><td style={{ ...cell, height: 32 }} colSpan={2}>9.7 If self-employed, nature of Business &amp; Address (give details): {selfEmployed ? [biz?.businessType, biz?.businessAddress].filter(Boolean).join(', ') || blank : blank}</td></tr>
        </tbody></table>

        <table style={{ width: '100%', borderCollapse: 'collapse', pageBreakInside: 'avoid' }}><tbody>
          <tr><td style={{ ...cell, height: 40 }}>
            10. IS YOUR SPOUSE EMPLOYED? YES / NO<br />
            If employed, indicate Position, Name &amp; Address of Employer:
          </td></tr>
        </tbody></table>
      </Sheet>

      <Sheet breakBefore>
        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 4, pageBreakInside: 'avoid' }}><tbody>
          <tr><td style={label} colSpan={5}>11. BANK ACCOUNT/ CREDIT FACILITIES/ CARDS MAINTAINED BY THE APPLICANT / SPOUSE</td></tr>
          <tr>
            <td style={label}>Name of Bank /Financial Institution</td>
            <td style={label}>Branch</td>
            <td style={label}>Type of Account/ Facility</td>
            <td style={label}>Year Opened / Facility Granted date &amp; amount</td>
            <td style={label}>Account No / Facility Out Standing</td>
          </tr>
          {[0, 1, 2].map((i) => (
            <tr key={i}>
              <td style={{ ...cell, height: 22 }}>{blank}</td><td style={cell}></td><td style={cell}></td><td style={cell}></td><td style={cell}></td>
            </tr>
          ))}
        </tbody></table>

        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 4, pageBreakInside: 'avoid' }}><tbody>
          <tr><td style={label} colSpan={4}>12. MONTHLY INCOME &amp; EXPENDITURE:</td></tr>
          <tr><td style={label}>INCOME</td><td style={label}>Your Income</td><td style={label}>Spouse's Income (Rs)</td><td style={label}>Total</td></tr>
          <tr>
            <td style={cell}>Basic Salary (attach certificate from employer)</td>
            <td style={cell}>{money(c?.monthlyIncome)}</td><td style={cell}></td><td style={cell}>{money(c?.monthlyIncome)}</td>
          </tr>
          {['Total Allowances', 'Less: Total Deduction', 'Net Salary'].map((r) => (
            <tr key={r}><td style={cell}>{r}</td><td style={cell}></td><td style={cell}></td><td style={cell}></td></tr>
          ))}
          <tr><td style={label} colSpan={4}>INCOME FROM OTHER SOURCES</td></tr>
          {['Business', 'Rents', 'From other investments / Sources (Specify)'].map((r) => (
            <tr key={r}><td style={cell}>{r}</td><td style={cell}></td><td style={cell}></td><td style={cell}></td>
            </tr>
          ))}
          <tr><td style={label}>TOTAL INCOME</td><td style={cell}></td><td style={cell}></td><td style={cell}></td></tr>
          <tr><td style={label} colSpan={4}>EXPENDITURE</td></tr>
          {['Living Expenses', 'Loan Installments / interest', 'Any other Expenditure (Specify)'].map((r) => (
            <tr key={r}><td style={cell}>{r}</td><td style={cell}></td><td style={cell}></td><td style={cell}></td></tr>
          ))}
          <tr><td style={label}>TOTAL EXPENDITURE</td><td style={cell}></td><td style={cell}></td><td style={cell}></td></tr>
        </tbody></table>

        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 4, pageBreakInside: 'avoid' }}><tbody>
          <tr><td style={label} colSpan={6}>13. DO YOU OR YOUR SPOUSE OWN ANY LANDS / BUILDINGS / INSURANCE / ASSETS / MOTOR VEHICLES / ANY OTHER ASSETS? YES / NO</td></tr>
          <tr>
            <td style={label}>Type of Asset</td><td style={label}>Address of the Asset</td><td style={label}>Extent / Vehicle No / Policy No</td>
            <td style={label}>Present Markey Value</td><td style={label}>Mortgaged or Not (If mortgaged provide details)</td><td style={label}>Owned by</td>
          </tr>
          {[0, 1].map((i) => (
            <tr key={i}>
              <td style={{ ...cell, height: 22 }}>{blank}</td><td style={cell}></td><td style={cell}></td><td style={cell}></td><td style={cell}></td><td style={cell}></td>
            </tr>
          ))}
        </tbody></table>

        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 8, pageBreakInside: 'avoid' }}><tbody>
          <tr><td style={{ ...cell, height: 32 }}>14. HAVE YOU HAD ANY JUDGEMENT, WRITS OR LEGAL PROCEEDINGS AGAINST YOU? YES / NO (GIVE DETAILS):</td></tr>
        </tbody></table>

        <div style={{ border: '1px solid #000', padding: '6px 8px', fontSize: 9.5, marginBottom: 20, pageBreakInside: 'avoid' }}>
          I/We certify that the above information is true and correct and that I/We have knowingly withheld and information that might affect the credit decisions.
          I/We undertake to notify {brand.name || 'the Company'} in writing any material changes in my / our financial position in the future. The undersigned expressly
          authorize {brand.name || 'the Company'} to obtain from his employer of from and other sources such information as may be desired in connection with this application
          and each such source is hereby authorized to provide {brand.name || 'the Company'} with such information as may be requested and further agree that this application
          shall be and remain the property of {brand.name || 'the Company'} whether or not any facility is granted to the undersigned.
        </div>

        <div className="row between" style={{ marginBottom: 24, fontSize: 10.5 }}>
          <div>Signature: Applicant 1……………………</div>
          <div>Applicant 2…..……………………</div>
        </div>
        <div style={{ marginBottom: 24, fontSize: 10.5 }}>Date:</div>

        <div style={{ border: '1px solid #000', padding: '6px 8px', fontSize: 10, pageBreakInside: 'avoid' }}>
          <b>Notes by Interviewing Officer:</b> Credit Officer's / Branch Manager's declaration regarding the verification of all documents declared by the applicant/s
          <div className="row between" style={{ marginTop: 26 }}>
            <div>Signature ……………………………………..</div>
            <div>Name: {loan.loanDetail?.loanOfficer?.name || me?.name || ''}</div>
          </div>
        </div>
      </Sheet>
    </>
  )
}

/* ======================= Leasing Facility Acceptance Letter ======================= */

/** An underlined fill-in blank — pre-filled with `value` when known, otherwise
 *  just the blank line itself for the customer/witness to complete by hand. */
function Blank({ value, width = 160 }: { value?: string | null; width?: number }) {
  return (
    <span style={{ display: 'inline-block', minWidth: width, borderBottom: '1px solid #000', padding: '0 4px 1px', fontWeight: value ? 700 : 400 }}>
      {value || ' '}
    </span>
  )
}

const OCCUPATION_LABEL: Record<string, string> = {
  Employed: 'Employed', SelfEmployed: 'Self-Employed', BusinessOwner: 'Business Owner',
  Unemployed: 'Unemployed', Retired: 'Retired', Student: 'Student', Other: 'Other',
}

function LoanAgreement({ loan, brand }: { loan: Loan; brand: { name: string } }) {
  const c = loan.customer
  const coApplicant = loan.coApplicants?.[0]?.coApplicant
  const companyName = brand.name || 'the Company'
  const biz = c?.businessDetail
  const isBusiness = c?.occupationType === 'SelfEmployed' || c?.occupationType === 'BusinessOwner'
  const occupation = isBusiness ? (biz?.businessType || OCCUPATION_LABEL[c?.occupationType || ''] || '') : (c?.occupationType ? OCCUPATION_LABEL[c.occupationType] || c.occupationType : '')
  const fullNames = [c?.fullName, coApplicant?.fullName].filter(Boolean).join(' / ')
  const nicNos = [c?.nicNo || biz?.businessRegNo, coApplicant?.nicNo].filter(Boolean).join(' / ')
  const frequency = loan.loanDetail?.installmentFrequency
  const frequencyLabel = frequency ? frequency.charAt(0).toUpperCase() + frequency.slice(1) : ''
  const instalmentCount = loan.schedules?.length
  const firstSchedule = loan.schedules?.slice().sort((a, b) => a.installmentNo - b.installmentNo)[0]

  const p: CSSProperties = { textAlign: 'justify', marginBottom: 8 }
  const li: CSSProperties = { textAlign: 'justify', marginBottom: 8 }

  return (
    <>
      <Sheet>
        <div style={{ border: '1px solid #000', padding: '14px 18px', fontSize: 9.5, lineHeight: 1.4 }}>
          <div style={{ textAlign: 'center', fontWeight: 700, fontSize: 13, textDecoration: 'underline', marginBottom: 14 }}>LOAN AGREEMENT</div>

          <div style={{ lineHeight: 1.8, marginBottom: 12 }}>
            <div>Full name of the Member/Members : <Blank value={fullNames} width={320} /></div>
            <div>NIC No/s / Business Registration No. : <Blank value={nicNos} width={320} /></div>
            <div>Address of the Member : <Blank value={c?.address} width={320} /></div>
            <div>Occupation or Business : <Blank value={occupation} width={320} /></div>
            <div>Loan Type : <Blank value={loan.loanDetail?.loanType?.name} width={320} /></div>
            <div>Loan Amount : LKR <Blank value={money(loan.principalAmount)} width={280} /></div>
            <div>Repayment Period : In <Blank value={instalmentCount ? String(instalmentCount) : ''} width={60} /> {frequencyLabel || '........'} equated Instalments of LKR <Blank value={money(firstSchedule?.totalDue)} width={140} /></div>
            <div style={{ paddingLeft: 90 }}>(Within <Blank width={60} /> days from the date of loan disbursement)</div>
          </div>

          <div style={p}>
            In consideration of the {companyName} (hereafter called as "{companyName}"), granting to the member/members (hereafter called as
            Customer/Customers) named herein the loan facility / housing loan's facility/ies ("loan") mentioned herein, the customer/customers
            hereby agree(s) and consent(s) to the following terms and conditions which shall be strictly binding on the customer/customers.
          </div>

          <ol style={{ margin: 0, paddingLeft: 18 }}>
            <li value={1} style={li}>{companyName} shall have the right to review, suspend, withhold, withdraw, cancel and/or recall the loan from time to time. Any variation to the terms and conditions relating to the facility will be notified to the customer.</li>
            <li value={2} style={li}>The proceeds of the loan shall be credited to Member's account No. ................. of the customer/customers at {companyName}.</li>
            <li value={3} style={li}>The loan shall be exclusively utilized for the purpose/s for which it has been granted.</li>
            <li value={4} style={li}>Interest shall be payable by the customer/customers at the rate of ........% p.a. or such other or higher rate as may from time to time be fixed or charged by {companyName} with notice to the customer/customers, provided that if the instalments in repayment of principal and the monthly interest on the loan are not paid punctually on the due dates {companyName} shall be entitled to charge with notice to the customer/customers a higher rate of interest as liquidated damages until such time the repayments are regularized. Any tax leviable on the amount of the loan or on any interest paid or payable shall be paid by the customer/customers.</li>
            <li value={5} style={li}>Subject to the operation of Clause 4 above and any change of interest as provided for in Clause 4 above, customer/customers shall repay the loan by instalments of (…………). The first instalment to be paid on the following day after the date on which the loan is granted, and the succeeding instalments shall to be paid on a daily basis for the agreed number of instalments and the tenor of the loan. The interest and the capital of loan facility limit shall be paid on a daily basis same as term loans stated above.</li>
            <li value={6} style={li}>In any case where any other loan/s or credit facility/facilities has/have been or is/are granted to the customer/customers by {companyName}, and default is made in the repayment of principal or interest due upon any such loan/s or credit facility/facilities, {companyName} shall notify the customer/customers that the repayment of the above loan shall be deemed to be in default even though in fact default may not have been made.</li>
            <li value={7} style={li}>All information regarding the customer's/customers' trade, business or occupation and financial condition shall be furnished by the customer/customers to {companyName} as and when required by {companyName}.</li>
            <li value={9} style={li}>Any material change expected or experienced in the customer's/customers' trade, business or occupation shall be forthwith notified by the customer/customers to {companyName}.</li>
            <li value={10} style={li}>The customer agrees that {companyName} may collect, process, and store personal data for the purpose of Assessing the loan application, managing the loan account and fulfilling its obligations under this agreement.</li>
            <li value={11} style={li}>Particulars of any litigation which may tend to affect the customer/customers financial capacity and in which the customer/customers may be involved shall be disclosed by the customer/customers to {companyName} whenever such eventuality occurs.</li>
          </ol>
        </div>
      </Sheet>

      <Sheet breakBefore>
        <div style={{ border: '1px solid #000', padding: '14px 18px', fontSize: 9.5, lineHeight: 1.4 }}>
          <ol style={{ margin: '0 0 14px', paddingLeft: 18 }}>
            <li value={12} style={li}>Customer/customers hereby consent/s and authorize/s {companyName}, to set off and appropriate any and all monies lying to the customer's/customers' credit with subscription money or credits in member's account or any other investments with {companyName} on account of all credit facilities.</li>
            <li value={13} style={li}>The production in any Court of Law or before any Tribunal or body or statutory officer of any statement, extract, writing or other document showing monies owing by the customer/customers as a result of {companyName} granting the above loan and made out of the books or documents of {companyName} and signed and certified by the Authorized officer of {companyName} shall be deemed to be conclusive proof of the amount of the customer's/customers' liability to {companyName} without any farther documents or vouchers being produced by {companyName}.</li>
            <li value={14} style={li}>If this document is signed by or on behalf of two or more persons not being on behalf of a company but as partners of a firm or otherwise, such persons shall be jointly and severally liable to {companyName} for the amount of the above loan and interest and each of such persons shall be liable as sole or principal debtor so long as any monies are due to {companyName} on account of the above loan and interest.</li>
            <li value={15} style={li}>All representations and statements made above and elsewhere and otherwise to {companyName} or any of its officers by the customer/customers or the customer's/customers' agents, employees or officers in writing or otherwise on the customer's/customers' behalf or purporting to be on the customer's/customers' behalf are hereby warranted true and correct and intended to be acted upon by {companyName} and shall form the basis of the contract resulting from the grant to the customer/customers of the above loan.</li>
          </ol>
          <div style={{ marginBottom: 4 }}>{companyName} may waive any of the above terms or conditions either unconditionally or on terms. Every such waiver by {companyName} be without prejudice to the rights of {companyName} hereunder.</div>

          <div style={{ marginTop: 16, marginBottom: 14 }}>Signed by customer/customers on <Blank width={100} /> date of <Blank width={100} /> in <Blank width={120} />.</div>

          <div style={{ lineHeight: 2, marginBottom: 14 }}>
            <div>Signature : <Blank width={220} /></div>
            <div>Name : <Blank value={c?.fullName} width={220} /></div>
            <div>NIC No : <Blank value={c?.nicNo} width={220} /></div>
          </div>

          <div style={{ lineHeight: 2, marginBottom: 14 }}>
            <div>Signature : <Blank width={220} /></div>
            <div>Name : <Blank value={coApplicant?.fullName} width={220} /></div>
            <div>NIC No : <Blank value={coApplicant?.nicNo} width={220} /></div>
          </div>

          <div style={{ fontWeight: 700, marginBottom: 4 }}>Witness 1</div>
          <div style={{ lineHeight: 2, marginBottom: 14 }}>
            <div>Signature : <Blank width={220} /></div>
            <div>Name : <Blank width={220} /></div>
            <div>NIC No : <Blank width={220} /></div>
            <div>Address : <Blank width={280} /></div>
          </div>

          <div style={{ fontWeight: 700, marginBottom: 4 }}>Witness 2</div>
          <div style={{ lineHeight: 2 }}>
            <div>Signature : <Blank width={220} /></div>
            <div>Name : <Blank width={220} /></div>
            <div>NIC No : <Blank width={220} /></div>
            <div>Address : <Blank width={280} /></div>
          </div>
        </div>
      </Sheet>
    </>
  )
}

/* ======================= Personal Guarantee ======================= */

function PersonalGuarantee({ loan, brand }: { loan: Loan; brand: { name: string } }) {
  const companyName = brand.name || 'the Company'
  const borrower = loan.customer
  const g1 = loan.guarantors?.[0]?.guarantor
  const g2 = loan.guarantors?.[1]?.guarantor

  const li: CSSProperties = { textAlign: 'justify', marginBottom: 9 }
  const pageNum = (n: number) => <div style={{ textAlign: 'center', marginTop: 16, fontSize: 9 }}>{n}</div>

  return (
    <>
      <Sheet>
        <div style={{ fontSize: 9.5, lineHeight: 1.4 }}>
          <div style={{ textAlign: 'center', fontWeight: 700, fontSize: 14, marginBottom: 14 }}>PERSONAL GUARANTEE</div>

          <div style={{ marginBottom: 14 }}>
            <div style={{ display: 'flex', gap: 8 }}><span>To :</span><b>{companyName.toUpperCase()}</b></div>
            <div style={{ paddingLeft: 22 }}><Blank width={360} /></div>
          </div>

          <div style={{ textAlign: 'justify', marginBottom: 8 }}>
            WHEREAS you have agreed to grant to <Blank value={borrower?.fullName} width={260} /> (Borrower /s Name) of{' '}
            <Blank value={borrower?.address} width={300} /> (Borrower /s' Address) bearing NIC No <Blank value={borrower?.nicNo} width={200} />{' '}
            (hereinafter referred to as "the Obligor") which term or expression shall where the context so requires or admits mean and include the said{' '}
            {borrower?.fullName || '...........................'} and assigns a loan of RUPEES <Blank value={money(loan.principalAmount)} width={220} /> ONLY
            (RS <Blank width={140} />/-) subject to the terms and conditions as may be agreed by the Obligor from time to time (hereinafter referred to as the said loan).
          </div>

          <div style={{ textAlign: 'justify', marginBottom: 8 }}>
            AND WHEREAS the Obligor has further agreed to offer and you have agreed to accept undertaking from me/us, and I/WE,{' '}
            <Blank value={g1?.fullName} width={260} /> (1st Guarantor's name) OF <Blank value={g1?.address} width={280} /> (Address) Bearing NIC No{' '}
            <Blank value={g1?.nicNo} width={180} /> AND <Blank value={g2?.fullName} width={260} /> (2nd Guarantor's name) OF{' '}
            <Blank value={g2?.address} width={280} /> (Address) bearing NIC No <Blank value={g2?.nicNo} width={180} /> do hereby agree to furnish to you and
            undertaking for the payment of the said loan jointly and severally.
          </div>

          <div style={{ textAlign: 'justify', marginBottom: 10 }}>
            NOW in consideration of the premises aforesaid I/We agree and undertake and bind our self my heirs administrators and assigns as follows:
          </div>

          <ol style={{ margin: 0, paddingLeft: 18 }}>
            <li style={li}>To repay to you jointly and severally in <Blank width={140} /> on demand the said Loan together with interest thereon at such rate or rates as may be charged by you and all legal and other charges and expenses whether taxable or not occasioned by or incidental to the enforcement of this or any other security for the said Loan or the recovery thereof (all of which are hereinafter collectively referred to as 'the moneys aforesaid')</li>
            <li style={li}>My/Our liability hereunder shall not in any way be affected or reduced nor shall I/We be released or be deemed to be released from my/our obligation/s hereunder if you have already lent or may from time to time lend to the said Obligor any moneys in excess of the said Loan</li>
            <li style={li}>The said Loan together with interest at such rate or rates as may be charged by you and all legal and other charges and expenses aforesaid shall be deemed to be owing by the said Obligor notwithstanding any incapacity of or limitation upon the said Obligor or any person acting or purporting to act on behalf of in the name of the said Obligor with respect to borrowing or if you are any person acting or purporting to act on your behalf or in your name with respect to lending or any defect or insufficiency in the borrowing powers of the said Obligor or of any such person acting or in your lending powers or any such person aforesaid or in the exercise thereof respectively which might be a defense between the said Obligor and you nor shall this guarantee be or become in any way prejudiced affected or unenforceable whether wholly or in part by reason of any fact matter or circumstance concerning the said Obligor or any other person or concerning the said loan or any transaction of or with the said Obligor or any other person whether or not such fact matter or circumstance be known to you or at any time come to your knowledge and whether or not the same be disclosed by you to me/us</li>
            <li style={li}>This guarantee shall not be considered as satisfied and intermediate or part payment or satisfaction by the said Obligor or any other person of any part of the moneys aforesaid</li>
            <li style={li}>In all matters concerning the Loan you shall be entitled to act on the instructions of the said Obligor and in the absence of such instructions, to act at your discretion and my/our liability/ies hereunder shall not in any way be affected or reduced by your acting in accordance with such instructions at your discretion for the purposes of this agreement any instructions given by any person being or purporting to be or acting or purporting to act as a manager or officer or representative or heirs of the said Obligor or by any person or persons expressly or impliedly authorizes by the said Obligor shall be deemed to be the instructions of the said Obligor</li>
          </ol>
        </div>
        {pageNum(1)}
      </Sheet>

      <Sheet breakBefore>
        <div style={{ fontSize: 9.5, lineHeight: 1.4 }}>
          <ol style={{ margin: 0, paddingLeft: 18 }} start={6}>
            <li style={li}>This guarantee shall be binding on and remain in force and be enforceable at all times against and shall continue to be a security against me/us and my/our estate notwithstanding my/our death or disability until the whole of the moneys aforesaid shall have been fully liquidated and paid to you irrevocable by me/us or any of my/our heirs executors or administrators</li>
            <li style={li}>Any acknowledgement in writing by the said Obligor or by any person being or appearing to be or acting or purporting to act as a manager or officer or representative or heirs of the said Obligor of the amount of the moneys lent by you to the said Obligor or owing by the said Obligor to you and any judgment obtained by you against the said Obligor in respect thereof shall be binding and conclusive on and against me/us or my heirs executors or administrators in all courts of law and elsewhere</li>
            <li style={li}>You are at liberty (without the consent of or notice to me/us or my/our heirs executors or administrators) without thereby affecting your rights hereunder at any time and from time to time to accept from any other person or persons one or more guarantees for the moneys aforesaid to vary exchange abstain from perfecting or release in whole or in part any guarantees or security received taken or held or to be received taken or held by you from or on behalf or on account of the said Obligor or me/us or any other person or persons in respect of the moneys aforesaid or any part thereof and to compound with give time for payment or other indulgence to accept compositions from and make any other arrangement with or accept payment by installments from the said Obligor or me/us or my/our afore written or any persons or persons liable for the moneys aforesaid or liable on any guarantee or security received taken or held or to be received taken or held by you</li>
            <li style={li}>Any dividend composition and payment which you may receive from the said Obligor or me/us or from any other person or persons or in winding-up of the said Obligor or a death of a said Obligor or from my/us estates of any person or persons whether in or in consequence of liquidation bankruptcy liquidation by arrangement or composition with creditors or otherwise howsoever shall be taken and applied as payments in gross without any right my part or my afore written to stand in your place in respect of or to claim the benefit of any such dividend composition or payment or claim or any security held by you until you shall have received the full amount of all the moneys aforesaid and shall not prejudice your right to recover jointly and severally from me/us or my/our afore written to the full extent of this guarantee</li>
            <li style={li}>This guarantee shall be construed and take effect as a guarantee of the whole and every part of the moneys aforesaid and I/We shall not be entitled as against you to any right of proof in a death of a said Obligor or in the winding-up of the said Obligor or other right of a surety discharged his liability in respect of the principal debt unless and until the whole of the moneys aforesaid shall have first been completely discharged and satisfied and further for the purpose of enabling you to sue the said Obligor or prove against the assets of the said Obligor for the whole of the moneys aforesaid or to preserve intact the liability of the said Obligor or of any other person you may at any time and from time to time place and keep for such time as you may think prudent any moneys received recovered or realized hereunder to and in a separate or suspense account to my credit or of such other person or transaction if any as you shall think fit without any intermediate obligation on your part to apply the same or any part thereof in or towards the discharge of the moneys aforesaid or any intermediate right on my/our part or my afore written to sue the said Obligor or prove against its assets in competition with or so as to diminish any dividend or other advantage that would or might come to you or to treat the liability of the said Obligor as diminished</li>
            <li style={li}>No assurance or security given to you which may be avoided and no payment made to you which may be recoverable under any law for the time being in force relating to limited liability companies and no release settlement or discharge which may have been given or made on the faith of any such assurance security or payment shall prejudice or affect your right of recovery from me/us to the full extent of this guarantee</li>
            <li style={li}>I/We have not taken in respect of the liability hereby undertaken by me/us and I/We will not take from the said Obligor either directly or indirectly without your consent any sum of money or promissory note bill of exchange mortgage charge or other security whether merely personal or involving charge on any property whatsoever of the said Obligor whereby I/We or any person or persons claiming through me/us by endorsement assignment or otherwise would or might on the winding-up of the said Obligor and to your prejudice increase the proof in such winding-up or diminish the property distributable amongst the creditors of the said Obligor and as regards any such security as aforesaid which I/We may have taken or may take</li>
          </ol>
        </div>
        {pageNum(2)}
      </Sheet>

      <Sheet breakBefore>
        <div style={{ fontSize: 9.5, lineHeight: 1.4 }}>
          <div style={{ textAlign: 'justify', marginBottom: 9 }}>
            with such consent as aforesaid the same shall be security to you for the fulfillment of my/our obligations hereunder and shall be forthwith deposited with you for the purpose by me/us
          </div>
          <ol style={{ margin: 0, paddingLeft: 18 }} start={13}>
            <li style={li}>My/Our liability or the liability of my/our afore written hereunder shall not be affected by any failure on your part to take any security or by any invalidity or insufficiency of any security taken or by any existing or future arrangement as to the application by the said Obligor of the moneys lent by you or in the event of this guarantee proving not to be binding on me/us or my/our afore written for any reason whatsoever and this guarantee shall be in addition to an shall not in any way be prejudiced or affected by any collateral or other security now or hereafter held by you for all or any part of the moneys aforesaid nor shall such collateral or other security or any lien to which you may be otherwise entitled or the liability of any person not party hereto for all or any part of the moneys aforesaid be in anywise prejudiced or affected by this present guarantee</li>
            <li style={li}>I/We specifically agree that you shall be at liberty either in one action to sue the said Obligor and me/us or my/our afore written and also any other person or persons all jointly and severally or to proceed in the first instance against me/us only or my afore written only and further that I/We hereby renounce the right to claim that the said Obligor should be excused or proceeded against by action in the first instance and the right to claim that you should divide your claim and bring actions against me/us or any other person or persons whomsoever each for his portion pro rata and the right to claim in any action brought against me/us or my/our afore written with or without all or any other person that you should only recover from me/us or my afore written a pro rata share of the amount claimed and all other rights and benefits to which sureties are or may be by law entitled IT BEING AGREED that I/We am/are liable jointly and severally in all respects hereunder as principal debtor to the extent aforementioned including the liability to be sued before recourse is had against the said Obligor</li>
            <li style={li}>I/We hereby renounce the beneficium ordinis sue excussion is the beneficium division is and all other privileges to which sureties are by law entitled (the effect of which renunciation has been explained to and is understood by me)</li>
          </ol>

          <div style={{ marginTop: 14, marginBottom: 14 }}>Signed by the within named</div>

          <div style={{ fontWeight: 700, marginBottom: 4 }}>Guarantor 1</div>
          <div style={{ lineHeight: 1.9, marginBottom: 14 }}>
            <div>Signature : <Blank width={240} /></div>
            <div>Name: <Blank value={g1?.fullName} width={260} /></div>
            <div>on this <Blank width={60} /> day of <Blank width={80} /> 20<Blank width={40} /></div>
            <div>in the presence of ;</div>
            <div style={{ textDecoration: 'underline', marginTop: 8 }}>Witnesses :</div>
            <div>1)</div>
            <div>2)</div>
          </div>

          <div style={{ fontWeight: 700, marginBottom: 4 }}>Guarantor II</div>
          <div style={{ lineHeight: 1.9 }}>
            <div>Signature : <Blank width={240} /></div>
            <div>Name: <Blank value={g2?.fullName} width={260} /></div>
            <div>on this <Blank width={60} /> day of <Blank width={80} /> 20<Blank width={40} /></div>
            <div>in the presence of ;</div>
            <div style={{ textDecoration: 'underline', marginTop: 8 }}>Witnesses :</div>
            <div>1)</div>
            <div>2)</div>
          </div>
        </div>
        {pageNum(3)}
      </Sheet>
    </>
  )
}

/* ======================= Promissory Note ======================= */

const prettyDate = (v: unknown) => {
  if (typeof v !== 'string' || !v) return ''
  const d = new Date(v)
  return isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' })
}

function PromissoryNote({ loan, brand }: { loan: Loan; brand: { name: string } }) {
  const companyName = brand.name || 'the Company'
  const c = loan.customer
  const noteDate = loan.approvedAt || loan.loanDetail?.applicationDate
  const monthlyRate = loan.ratePeriod === 'monthly' ? loan.interestRatePercent : undefined
  const words = amountInWords(loan.principalAmount)

  return (
    <Sheet>
      <div style={{ fontSize: 10.5, lineHeight: 1.6 }}>
        <div style={{ textAlign: 'center', fontWeight: 700, fontSize: 15, textDecoration: 'underline', marginBottom: 20 }}>PROMISSORY NOTE</div>

        <div style={{ marginBottom: 14 }}>Date: <Blank value={prettyDate(noteDate)} width={200} /></div>

        <div style={{ textAlign: 'justify', marginBottom: 20 }}>
          Rs. <Blank value={money(loan.principalAmount)} width={160} /> (Sri Lankan Rupees <Blank value={words} width={320} /> only) On demand, I / We,{' '}
          <Blank value={c?.fullName} width={220} /> (<Blank value={c?.nicNo} width={140} />) of <Blank value={c?.address} width={280} /> promise to pay to{' '}
          {companyName}, at their Branch Office at No.<Blank width={260} /> Or to their order the sum of Rupees. <Blank value={money(loan.principalAmount)} width={160} />{' '}
          only for value received, together with interest thereon at the rate of <Blank value={monthlyRate != null ? String(monthlyRate) : ''} width={60} />%
          per month from the date hereof <Blank value={prettyDate(noteDate)} width={160} />.
        </div>

        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4, marginTop: 30 }}>
          <div style={{ borderBottom: '1px solid #000', width: 220 }} />
          <div style={{ borderBottom: '1px solid #000', width: 220 }} />
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 26 }}>
          <div>Signature 01</div>
          <div>Signature 02</div>
        </div>

        <div style={{ fontWeight: 700, marginBottom: 10 }}>WITNESSES:</div>
        <div style={{ marginBottom: 6 }}>1. Signature: <Blank width={260} /></div>
        <div style={{ marginBottom: 18 }}>&nbsp;&nbsp;&nbsp;Name &nbsp; : <Blank width={260} /></div>
        <div style={{ marginBottom: 6 }}>2. Signature: <Blank width={260} /></div>
        <div style={{ marginBottom: 24 }}>&nbsp;&nbsp;&nbsp;Name &nbsp; : <Blank width={260} /></div>

        <div style={{ marginBottom: 4 }}>1. Capital sum borrowed &ndash; Rs. <Blank value={money(loan.principalAmount)} width={160} />/-</div>
        <div style={{ marginBottom: 4 }}>2. Interest premium or charges deducted or paid in advance - Rs. <Blank width={160} />/-</div>
        <div style={{ marginBottom: 24 }}>3. Rate of interest per month &ndash; <Blank value={monthlyRate != null ? String(monthlyRate) : ''} width={60} />%</div>

        <div style={{ marginBottom: 30 }}>
          "It is hereby certified that a sum of Rs.1000/- payable stamp duty in respect of (promissory note) has been collected and remitted in terms of
          section 7 of the Stamp Duty (Special Provisions) Act, No.12 of 2006"
        </div>

        <div style={{ borderBottom: '1px solid #000', width: 220, marginBottom: 4 }} />
        <div>Authorized Signatory</div>
      </div>
    </Sheet>
  )
}

function LeasingOfferLetter({ loan, brand }: { loan: Loan; brand: { name: string } }) {
  const c = loan.customer
  const companyName = brand.name || 'the Company'
  const vehicle = loan.collateral?.find((col) => col.vehicleDetail)?.vehicleDetail
  const regChassis = [loan.loanDetail?.vehicleNo, vehicle?.chassisNo].filter(Boolean).join(' / ')
  const vehicleValue = loan.collateral?.find((col) => col.vehicleDetail)?.estimatedValue
  const firstSchedule = loan.schedules?.slice().sort((a, b) => a.installmentNo - b.installmentNo)[0]
  const monthlyRental = loan.loanDetail?.installmentFrequency === 'monthly' ? firstSchedule?.totalDue : undefined
  const firstDueDate = loan.loanDetail?.firstInstallmentDate || firstSchedule?.dueDate

  return (
    <Sheet>
      <div style={{ textAlign: 'center', fontWeight: 700, fontSize: 14, textDecoration: 'underline', marginBottom: 16 }}>
        LEASING FACILITY OFFER LETTER
      </div>

      <div style={{ lineHeight: 1.9, marginBottom: 10 }}>
        <div>Offer Letter No.: <Blank value={loan.code} width={200} /></div>
        <div>Date: <Blank value={dateOnly(loan.approvedAt)} width={200} /></div>
        <div>To:</div>
        <div>Customer Name: <Blank value={c?.fullName} width={260} /></div>
        <div>Address: <Blank value={c?.address} width={280} /></div>
      </div>

      <div style={{ marginBottom: 4 }}>Subject: Offer of Leasing Facility</div>
      <div style={{ marginBottom: 4 }}>Dear Sir/Madam,</div>
      <div style={{ marginBottom: 8 }}>
        We are pleased to inform you that your application for a leasing facility has been approved, subject to the terms and conditions stated below.
      </div>
      <div style={{ fontWeight: 700, marginBottom: 6 }}>Facility Details</div>

      <div style={{ lineHeight: 1.9, marginBottom: 8 }}>
        <div>Lease Account No.: <Blank value={loan.code} width={220} /></div>
        <div>Vehicle Make &amp; Model: <Blank value={vehicle?.makeModel} width={240} /></div>
        <div>Registration / Chassis No.: <Blank value={regChassis} width={240} /></div>
        <div>Supplier / Dealer: <Blank width={220} /></div>
        <div>Vehicle Value (LKR): <Blank value={money(vehicleValue)} width={200} /></div>
        <div>Down Payment (LKR): <Blank value={money(loan.loanDetail?.downPayment)} width={200} /></div>
        <div>Lease Amount (LKR): <Blank value={money(loan.principalAmount)} width={200} /></div>
        <div>Lease Period: <Blank value={loan.termMonths ? String(loan.termMonths) : ''} width={80} /> Months</div>
        <div>Interest Rate: <Blank value={loan.interestRatePercent != null ? String(loan.interestRatePercent) : ''} width={80} /> %</div>
        <div>Monthly Rental: LKR <Blank value={money(monthlyRental)} width={200} /></div>
        <div>First Rental Due Date: <Blank value={dateOnly(firstDueDate)} width={200} /></div>
      </div>

      <div style={{ fontWeight: 700, textDecoration: 'underline', marginBottom: 6 }}>Conditions</div>
      <ol style={{ margin: '0 0 10px', paddingLeft: 18, lineHeight: 1.7 }}>
        <li>This offer is subject to the satisfactory completion of all legal documentation.</li>
        <li>The vehicle shall remain under the ownership/security of {companyName} until all obligations are fully settled.</li>
        <li>Comprehensive insurance must be maintained throughout the lease period.</li>
        <li>Any guarantor and security requirements must be fulfilled before disbursement.</li>
        <li>This offer is valid until <Blank width={180} />. If not accepted before this date, it may be withdrawn without notice.</li>
      </ol>

      <div style={{ marginBottom: 4 }}>If you agree with the above terms and conditions, kindly sign the attached Acceptance Letter and return it together with the required documents.</div>
      <div style={{ marginBottom: 10 }}>We look forward to serving you.</div>
      <div style={{ fontWeight: 700, marginBottom: 40 }}>For {companyName}</div>

      <div style={{ borderBottom: '1px solid #000', width: 220, marginBottom: 4 }} />
      <div>Authorized Officer</div>
      <div>Company Seal</div>
    </Sheet>
  )
}

function LeasingAcceptanceLetter({ loan, brand }: { loan: Loan; brand: { name: string } }) {
  const c = loan.customer
  const companyName = brand.name || 'the Company'
  return (
    <Sheet>
      <div style={{ textAlign: 'center', fontWeight: 700, fontSize: 14, textDecoration: 'underline', marginBottom: 26 }}>
        LEASING FACILITY ACCEPTANCE LETTER
      </div>

      <div style={{ marginBottom: 18 }}>Date: <Blank width={180} /></div>

      <div style={{ marginBottom: 4 }}>To:</div>
      <div style={{ fontWeight: 700, marginBottom: 18 }}>{companyName}</div>

      <div style={{ marginBottom: 18 }}>Re: Acceptance of Leasing Facility</div>

      <div style={{ marginBottom: 18, lineHeight: 2 }}>
        I/We, <Blank value={c?.fullName} width={280} /> (Customer Name), refer to your Offer Letter No.{' '}
        <Blank width={160} /> dated <Blank width={140} />.
      </div>

      <div style={{ marginBottom: 18 }}>
        I/We hereby confirm that I/we have carefully read and understood all the terms and conditions
        contained in the Offer Letter and agree to accept the leasing facility on those terms.
      </div>

      <div style={{ marginBottom: 8 }}>I/We further confirm that:</div>
      <ol style={{ margin: '0 0 24px', paddingLeft: 20, lineHeight: 1.9 }}>
        <li>The information and documents provided by me/us are true and accurate.</li>
        <li>I/We agree to execute the Lease Agreement and all related security documents required by {companyName}.</li>
        <li>I/We undertake to pay all lease rentals and other charges on or before their due dates.</li>
        <li>I/We agree to comply with all applicable terms and conditions throughout the lease period.</li>
      </ol>

      <div style={{ fontWeight: 700, marginBottom: 10 }}>Customer Details</div>
      <div style={{ lineHeight: 2.3, marginBottom: 26 }}>
        <div>Name: <Blank value={c?.fullName} width={320} /></div>
        <div>NIC / Passport No.: <Blank value={c?.nicNo || c?.passportNo} width={260} /></div>
        <div>Address: <Blank value={c?.address} width={400} /></div>
        <div>Telephone: <Blank value={c?.phone} width={220} /></div>
        <div>Customer Signature: <Blank width={260} /></div>
        <div>Date: <Blank width={180} /></div>
      </div>

      <div style={{ lineHeight: 2.3, marginBottom: 10 }}>
        <div>01 Witness Name: <Blank width={280} /></div>
        <div>Witness Signature: <Blank width={260} /></div>
        <div>Date: <Blank width={180} /></div>
      </div>
      <div style={{ lineHeight: 2.3 }}>
        <div>02. Witness Name: <Blank width={280} /></div>
        <div>Witness Signature: <Blank width={260} /></div>
        <div>Date: <Blank width={180} /></div>
      </div>
    </Sheet>
  )
}
