/* Funding Management — FD interest payout voucher: the slip raised for every
   monthly interest payment (and sent to the bank with the transfer). Shows who
   is being paid, the account the money goes to, the company account it is paid
   from and the reference. Shared by Fixed Deposits, Deposit Payment and Interest Due. */
import { Card, Btn } from '../components/ui'
import type { FdInterestPayment } from '../api'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return <div className="row between"><span className="t-2">{label}</span><b>{value}</b></div>
}

/** The voucher card itself — `payment` must come from fdInterestPaymentsApi.pay() / .voucher() so it carries the payee + bank details. */
export function InterestVoucherCard({ payment, brand }: { payment: FdInterestPayment; brand: { name: string; tagline: string } }) {
  const fd = payment.fixedDeposit
  const bd = fd?.bankDetail
  const from = payment.bankAccount
  const method = payment.paymentMethod === 'Bank' ? 'Bank Transfer' : payment.paymentMethod || '—'
  const section = { fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' } as const

  return (
    <Card style={{ maxWidth: 560, margin: '0 auto', fontFamily: 'Outfit' }}>
      <div className="col gap-1" style={{ textAlign: 'center', marginBottom: 18, paddingBottom: 18, borderBottom: '1px dashed var(--line)' }}>
        <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 20 }}>{brand.name || 'Company'}</div>
        {brand.tagline && <div className="t-2" style={{ fontSize: 12 }}>{brand.tagline}</div>}
        <div style={{ fontWeight: 700, fontSize: 14, letterSpacing: '.08em', marginTop: 8 }}>INTEREST PAYMENT VOUCHER</div>
      </div>

      <div className="col gap-2" style={section}>
        <Row label="Voucher No" value={payment.voucherNo || '—'} />
        <Row label="Paid Date" value={dateOnly(payment.paidAt)} />
        <Row label="FD No" value={fd?.fdNumber || '—'} />
        <Row label="Installment #" value={payment.installmentNo} />
        <Row label="Due Date" value={dateOnly(payment.dueDate)} />
      </div>

      <div className="col gap-2" style={section}>
        <div className="eyebrow" style={{ marginBottom: 4 }}>Paid To</div>
        <Row label="Customer" value={fd?.customer?.fullName || '—'} />
        <Row label="NIC No" value={fd?.customer?.nicNo || '—'} />
        {bd && <>
          <Row label="Account Holder" value={bd.accountHolder || '—'} />
          <Row label="Bank" value={`${bd.bank?.name || '—'}${bd.bankBranch ? ` · ${bd.bankBranch}` : ''}`} />
          <Row label="Account No" value={<span style={{ fontFamily: 'JetBrains Mono' }}>{bd.accountNumber || '—'}</span>} />
        </>}
      </div>

      <div className="col gap-2" style={section}>
        <div className="eyebrow" style={{ marginBottom: 4 }}>Payment</div>
        <Row label="Method" value={method} />
        {from && <Row label="Paid From" value={`${from.name}${from.bank?.name ? ` (${from.bank.name})` : ''}`} />}
        {payment.referenceNo && <Row label="Reference No" value={payment.referenceNo} />}
        <Row label="Paid By" value={payment.payer?.name || '—'} />
      </div>

      <div className="row between" style={{ fontSize: 15, fontWeight: 700 }}>
        <span>Amount Paid</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(payment.paidAmount)}</span>
      </div>

      <div className="row between" style={{ fontSize: 12, marginTop: 30 }}>
        <div>Prepared By: ______________</div>
        <div>Approved By: ______________</div>
        <div>Received By: ______________</div>
      </div>
    </Card>
  )
}

/** Full-screen voucher with Print / Close, for screens that don't already host a PrintView. */
export function InterestVoucherPage({ payment, brand, onClose }: { payment: FdInterestPayment; brand: { name: string; tagline: string }; onClose: () => void }) {
  return (
    <div>
      <div className="row gap-2 no-print" style={{ marginBottom: 16 }}>
        <Btn variant="primary" icon="print" onClick={() => window.print()}>Print</Btn>
        <Btn variant="solid" icon="x" onClick={onClose}>Close</Btn>
      </div>
      <InterestVoucherCard payment={payment} brand={brand} />
    </div>
  )
}
