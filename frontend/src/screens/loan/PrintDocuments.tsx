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

type DocKey = 'credit_application' | 'leasing_acceptance'

const DOCS: { key: DocKey; label: string; desc: string; icon: string; show?: (loan: Loan) => boolean }[] = [
  { key: 'credit_application', label: 'Credit Facility Application', desc: 'Personal/Business application form — applicant, guarantor, employment and income sections pre-filled from system data.', icon: 'doc' },
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
