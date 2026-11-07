import { useEffect, useState } from 'react'
import { Badge, Btn, Modal, Table, Td } from './ui'
import { customerLoansApi, customerBankDetailsApi, type Customer, type CustomerBankDetail, type Loan } from '../api'
import { CreditReportPanel } from '../screens/Customers'

const custStatusTone: Record<string, 'green' | 'red' | 'amber'> = { Active: 'green', Inactive: 'amber', Blacklisted: 'red' }

/** Read-only customer summary — profile, active loans and a Credit Report modal. Shared by the loan application form and the loan detail screen. */
export function CustomerDetailCard({ c }: { c: Customer }) {
  const idNo = c.nicNo || c.passportNo || c.drivingLicenseNo || '—'
  const occupation = c.occupationType === 'Employed' ? [c.employmentDetail?.jobTitle, c.employmentDetail?.employerName].filter(Boolean).join(' at ')
    : (c.occupationType === 'SelfEmployed' || c.occupationType === 'BusinessOwner') ? c.businessDetail?.businessName
    : c.occupationType

  const [activeOwn, setActiveOwn] = useState<Loan[]>([])
  const [activeGuaranteed, setActiveGuaranteed] = useState<Loan[]>([])
  const [loansLoading, setLoansLoading] = useState(true)
  const [loansModal, setLoansModal] = useState(false)
  const [cribModal, setCribModal] = useState(false)
  useEffect(() => {
    setLoansLoading(true)
    customerLoansApi.list(c.id)
      .then((d) => { setActiveOwn(d.borrower.filter((l) => l.status === 'Active')); setActiveGuaranteed(d.guarantor.filter((l) => l.status === 'Active')) })
      .catch(() => { setActiveOwn([]); setActiveGuaranteed([]) })
      .finally(() => setLoansLoading(false))
  }, [c.id])

  const [bankDetails, setBankDetails] = useState<CustomerBankDetail[]>([])
  useEffect(() => {
    customerBankDetailsApi.list(c.id).then(setBankDetails).catch(() => setBankDetails([]))
  }, [c.id])

  const outstandingOf = (l: Loan) => Number(l.totalDueSum ?? 0) - Number(l.paidSum ?? 0)

  return (
    <div style={{ marginTop: 10, padding: 14, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
      <div className="row between" style={{ marginBottom: 10 }}>
        <div className="row gap-2" style={{ alignItems: 'center' }}>
          <span style={{ fontWeight: 700, fontSize: 14 }}>{c.fullName}</span>
          <span className="t-2" style={{ fontSize: 12 }}>{c.code}</span>
          {c.registeredAs && c.registeredAs !== 'Customer' ? <Badge tone="blue">{c.registeredAs}</Badge> : c.isGuarantor && <Badge tone="blue">Guarantor</Badge>}
        </div>
        <div className="row gap-2" style={{ alignItems: 'center' }}>
          <Btn size="sm" variant="solid" icon="chart" onClick={() => setCribModal(true)}>Credit Report</Btn>
          <Badge tone={custStatusTone[c.status || 'Active']} dot>{c.status || 'Active'}</Badge>
        </div>
      </div>
      {c.status === 'Blacklisted' && (
        <div style={{ marginBottom: 10, padding: '8px 12px', borderRadius: 'var(--r-m)', background: 'var(--bad-dim)', color: 'var(--bad)', fontSize: 12.5, fontWeight: 600 }}>
          BLACKLISTED — cannot be given any facility in any branch.{c.blacklistReason ? ` Reason: ${c.blacklistReason}` : ''}{c.blacklistNote ? ` — ${c.blacklistNote}` : ''}
        </div>
      )}
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
        <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 6 }}>Bank Details</div>
        {bankDetails.length === 0 ? (
          <div className="t-2" style={{ fontSize: 12 }}>No bank accounts on file.</div>
        ) : (
          <div className="col gap-1">
            {bankDetails.map((b) => (
              <div key={b.id} className="row between" style={{ fontSize: 12 }}>
                <span><b>{b.bank?.name || '—'}</b>{b.bankBranch ? ` · ${b.bankBranch}` : ''} · <span className="t-2">{b.accountHolder}</span></span>
                <span style={{ fontFamily: 'JetBrains Mono' }}>{b.accountNumberMasked}</span>
              </div>
            ))}
          </div>
        )}
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

      <Modal open={cribModal} onClose={() => setCribModal(false)} width={960} title="Customer Credit Report" sub={`${c.fullName} — ${c.code}`}>
        {cribModal && <CreditReportPanel customerId={c.id} />}
      </Modal>
    </div>
  )
}

