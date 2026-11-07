/* How a loan receipt was allocated — capital / interest / default (OD) / other charges,
   with an installment-wise table. Shared by the Receipt Inquiry modal, the printed
   receipt and the loan's Receipts tab. */
import { useEffect, useState } from 'react'
import { loanPaymentsApi, type PaymentBreakdown as Breakdown } from '../api'

const money = (v: unknown) => (Number(v) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const mono = { fontFamily: 'JetBrains Mono', fontWeight: 600 } as const

export function PaymentBreakdown({ paymentId }: { paymentId: number }) {
  const [data, setData] = useState<Breakdown | null>(null)
  const [err, setErr] = useState(false)

  useEffect(() => {
    let live = true
    setData(null); setErr(false)
    loanPaymentsApi.breakdown(paymentId).then((d) => { if (live) setData(d) }).catch(() => { if (live) setErr(true) })
    return () => { live = false }
  }, [paymentId])

  if (err) return <div className="t-2" style={{ fontSize: 12.5 }}>Could not load the allocation breakdown.</div>
  if (!data) return <div className="t-2" style={{ fontSize: 12.5 }}>Loading allocation…</div>

  const lines: [string, number][] = [
    ['Capital', data.capital], ['Interest', data.interest], ['Default Charge (OD)', data.odCharge], ['Other Charges', data.otherCharges],
  ]
  return (
    <div className="col gap-2" style={{ fontSize: 13 }}>
      <div className="eyebrow">Payment Allocation</div>
      {lines.map(([label, v]) => (
        <div key={label} className="row between"><span className="t-2">{label}</span><span style={mono}>{money(v)}</span></div>
      ))}
      <div className="row between" style={{ paddingTop: 8, borderTop: '1px solid var(--line)' }}>
        <b>Total Applied</b><span style={{ ...mono, fontWeight: 700 }}>{money(data.appliedTotal)}</span>
      </div>
      {Math.abs(data.unapplied) > 0.005 && (
        <div className="row between" style={{ color: 'var(--warn)' }}><span>Not applied to the balance</span><span style={mono}>{money(data.unapplied)}</span></div>
      )}

      {data.installments.length > 0 && (
        <table style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse', marginTop: 6 }}>
          <thead>
            <tr className="t-2" style={{ textAlign: 'right' }}>
              <th style={{ textAlign: 'left', fontWeight: 600, padding: '4px 0' }}>Installment</th>
              <th style={{ fontWeight: 600 }}>Capital</th><th style={{ fontWeight: 600 }}>Interest</th><th style={{ fontWeight: 600 }}>OD</th>
            </tr>
          </thead>
          <tbody>
            {data.installments.map((r, i) => (
              <tr key={i} style={{ textAlign: 'right', borderTop: '1px solid var(--line)' }}>
                <td style={{ textAlign: 'left', padding: '4px 0' }}>#{r.installmentNo ?? '—'}{r.dueDate ? <span className="t-2"> · {r.dueDate.slice(0, 10)}</span> : null}</td>
                <td style={mono}>{money(r.principal)}</td><td style={mono}>{money(r.interest)}</td><td style={mono}>{money(r.penalty)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {data.charges.length > 0 && (
        <div className="col gap-1" style={{ marginTop: 6 }}>
          {data.charges.map((c, i) => (
            <div key={i} className="row between" style={{ fontSize: 12 }}><span className="t-2">{c.name}</span><span style={mono}>{money(c.amount)}</span></div>
          ))}
        </div>
      )}
    </div>
  )
}
