/* Tazz Investment & Credit — Pawn Ticket Detail. No Approve/Disburse step
   here (see Pawnings.tsx / backend PawningController) — issuing the ticket
   already disbursed it. What happens here instead is the pawning-specific
   lifecycle: renew (settle this cycle's flat interest, roll the due date
   forward) and redeem (full or partial — release specific articles by
   paying their proportional share of the balance). See
   backend PawningInterestCalculator for the rate/split math this UI
   previews client-side and the server always recomputes authoritatively. */
import { useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Card, Btn, Badge, Field, Input, Table, Td, Modal, ErrorBanner } from '../../components/ui'
import { Icon } from '../../components/Icon'
import { pawningsApi, type Pawning, type PawningCollateralItem, type PawningPayment, type PaymentMethod, type AuthUser } from '../../api'
import { Can } from '../../lib/permissions'
import { useNotify } from '../../components/Notify'
import { getErrorText } from '../../lib/apiError'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const ITEM_STATUS_TONE: Record<string, 'green' | 'red' | 'amber' | 'neutral'> = {
  Pledged: 'green', Released: 'neutral', Auctioned: 'amber', Forfeited: 'red',
}
const PAYMENT_TYPE_LABEL: Record<string, string> = { Regular: 'Renewal (Interest + Capital)', Interest: 'Renewal (Interest)', CapitalReduction: 'Capital Reduction', Settlement: 'Redemption' }

function Kpi({ label, value, tone }: { label: string; value: ReactNode; tone?: string }) {
  return (
    <div style={{ padding: 14, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)' }}>
      <div className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em' }}>{label}</div>
      <div style={{ fontFamily: 'JetBrains Mono', fontSize: 18, fontWeight: 700, marginTop: 4, color: tone || 'var(--tx-0)' }}>{value}</div>
    </div>
  )
}
function KpiGrid({ children }: { children: ReactNode }) {
  return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 12, marginBottom: 20 }}>{children}</div>
}

const SECTIONS = [
  { id: 'overview', label: 'Overview', icon: 'chart' },
  { id: 'articles', label: 'Articles', icon: 'tag' },
  { id: 'payments', label: 'Payment History', icon: 'receipt' },
] as const
type SectionId = typeof SECTIONS[number]['id']

export function PawningDetail({ pawning, me, onChanged }: { pawning: Pawning; me?: AuthUser | null; onChanged: () => void }) {
  const [section, setSection] = useState<SectionId>('overview')

  return (
    <div>
      <div className="row between wrap gap-2" style={{ marginBottom: 16 }}>
        <div className="row gap-2" style={{ borderBottom: '1px solid var(--line)', paddingBottom: 4 }}>
          {SECTIONS.map((s) => (
            <Btn key={s.id} size="sm" variant="ghost" icon={s.icon} active={section === s.id} onClick={() => setSection(s.id)}>{s.label}</Btn>
          ))}
        </div>
        {pawning.status === 'Active' && <TicketActions pawning={pawning} me={me} onChanged={onChanged} />}
      </div>

      {section === 'overview' && <OverviewPanel pawning={pawning} />}
      {section === 'articles' && <ArticlesPanel pawning={pawning} />}
      {section === 'payments' && <HistoryPanel pawning={pawning} me={me} onChanged={onChanged} />}
    </div>
  )
}

function OverviewPanel({ pawning: d }: { pawning: Pawning }) {
  const detail = d.pawningDetail
  const s = d.summary
  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '10px 20px', fontSize: 12.5, marginBottom: 20 }}>
        <div><span className="t-2">Customer: </span><b>{d.customer?.fullName}</b></div>
        <div><span className="t-2">Scheme: </span><b>{detail?.pawningType?.name}</b></div>
        <div><span className="t-2">Applied Rate: </span><b>{Number(detail?.appliedRatePercent).toFixed(3)}% {detail?.isSpecialRate ? '(special)' : '(normal)'}</b></div>
        <div><span className="t-2">Created On: </span><b>{d.createdAt ? dateOnly(d.createdAt) : '—'}</b></div>
        <div><span className="t-2">Issued / Disbursed: </span><b>{dateOnly(d.disbursedAt || d.startDate)}</b></div>
        <div><span className="t-2">Repay Due: </span><b>{dateOnly(detail?.repayDueDate)}</b></div>
        <div><span className="t-2">Closed On: </span><b>{d.closedAt ? dateOnly(d.closedAt) : '—'}</b></div>
        <div><span className="t-2">Renewals: </span><b>{detail?.renewalCount ?? 0}</b></div>
        {detail?.previousFacility && <div><span className="t-2">Renewed From: </span><b>{detail.previousFacility.code}</b></div>}
        {detail?.purpose && <div><span className="t-2">Purpose: </span><b>{detail.purpose}</b></div>}
      </div>

      <KpiGrid>
        <Kpi label="Amount Advanced" value={money(d.principalAmount)} />
        <Kpi label="Outstanding Capital" value={money(d.outstandingBalanceCache)} tone={num(d.outstandingBalanceCache) > 0 ? 'var(--bad)' : 'var(--ok)'} />
        <Kpi label="Total Gross Weight" value={`${Number(detail?.totalGrossWeightGrams ?? 0).toFixed(3)} g`} />
        <Kpi label="Total Net Weight" value={`${Number(detail?.totalNetWeightGrams ?? 0).toFixed(3)} g`} />
        <Kpi label="Total Appraised Value" value={money(detail?.totalAppraisedValue)} />
        {s && d.status === 'Active' && (
          <Kpi label="Interest if Settled Today" value={money(s.interest)} tone="var(--bad)" />
        )}
      </KpiGrid>
      {s && d.status === 'Active' && (
        <div className="t-2" style={{ fontSize: 11.5 }}>
          {s.isSpecial ? `Within the special-rate window (${s.daysElapsed} days into this cycle) — ${s.rate.toFixed(3)}% applies.` : `Special-rate window has passed (${s.daysElapsed} days into this cycle) — the normal ${s.rate.toFixed(3)}% rate applies.`}
        </div>
      )}
    </div>
  )
}

function ArticlesPanel({ pawning: d }: { pawning: Pawning }) {
  const items = d.collateral || []
  return (
    <Card pad={0}>
      <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Pledged Articles</div>
      <Table cols={[{ label: '#' }, { label: 'Article' }, { label: 'Declared Kt', align: 'right' }, { label: 'Tested Kt', align: 'right' }, { label: 'Gross (g)', align: 'right' }, { label: 'Net (g)', align: 'right' }, { label: 'Appraised', align: 'right' }, { label: 'Max Loan', align: 'right' }, { label: 'Status', align: 'center' }]}
        rows={items} empty="No articles recorded."
        render={(c: PawningCollateralItem) => <>
          <Td>{c.pawnDetail?.itemNo}</Td>
          <Td>{c.pawnDetail?.articleType?.name || c.description}</Td>
          <Td mono align="right">{c.pawnDetail?.declaredKarat != null ? Number(c.pawnDetail.declaredKarat).toFixed(2) : '—'}</Td>
          <Td mono align="right">{Number(c.pawnDetail?.testedKarat ?? 0).toFixed(2)}</Td>
          <Td mono align="right">{Number(c.pawnDetail?.grossWeightGrams ?? 0).toFixed(3)}</Td>
          <Td mono align="right">{Number(c.pawnDetail?.netWeightGrams ?? 0).toFixed(3)}</Td>
          <Td mono align="right">{money(c.pawnDetail?.appraisedValue)}</Td>
          <Td mono align="right">{money(c.pawnDetail?.maxLoanValue)}</Td>
          <Td align="center"><Badge tone={ITEM_STATUS_TONE[c.pawnDetail?.itemStatus || ''] || 'neutral'} dot>{c.pawnDetail?.itemStatus}</Badge></Td>
        </>} />
    </Card>
  )
}

function HistoryPanel({ pawning: d, me, onChanged }: { pawning: Pawning; me?: AuthUser | null; onChanged: () => void }) {
  const notify = useNotify()
  const payments = (d.payments || []).slice().sort((a, b) => new Date(b.paidAt).getTime() - new Date(a.paidAt).getTime())
  // Only the most recent Active payment can be voided — every later renewal/
  // redemption's math assumes this one already happened (see backend
  // PawningController::voidPayment's guard, which this mirrors).
  const latestActiveId = payments.find((p) => p.status !== 'Voided')?.id

  const voidPayment = async (p: PawningPayment) => {
    const reason = await notify.prompt({ title: `Void payment ${p.code}?`, msg: 'This reverses its GL entries and unwinds the renewal/redemption it settled.', placeholder: 'Reason (optional)', danger: true, okText: 'Void' })
    if (reason === null) return
    try {
      await pawningsApi.voidPayment(p.id, reason || undefined)
      onChanged()
      notify.success('Payment voided', p.code)
    } catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  return (
    <div className="col gap-4">
      <Card pad={0}>
        <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Payments</div>
        <Table cols={[{ label: 'Receipt No' }, { label: 'Date' }, { label: 'Type' }, { label: 'Method' }, { label: 'Amount', align: 'right' }, { label: 'Received By' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 70 }]}
          rows={payments} empty="No payments recorded yet."
          render={(p: PawningPayment) => <>
            <Td mono style={{ fontWeight: 600 }}>{p.code}</Td>
            <Td>{dateOnly(p.paidAt)}</Td>
            <Td>{PAYMENT_TYPE_LABEL[p.paymentType] || p.paymentType}</Td>
            <Td>{p.paymentMethod}</Td>
            <Td mono align="right">{money(p.amount)}</Td>
            <Td>{p.receiver?.name || '—'}</Td>
            <Td align="center">{p.status === 'Voided' ? <Badge tone="red" dot>Voided</Badge> : <Badge tone="green" dot>Recorded</Badge>}</Td>
            <Td align="right">
              {p.id === latestActiveId && (
                <Can user={me} module="Pawning Payments" action="Delete">
                  <button className="mms-act" title="Void" onClick={() => voidPayment(p)}><Icon n="trash" s={14} /></button>
                </Can>
              )}
            </Td>
          </>} />
      </Card>
      <Card pad={0}>
        <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Renewals</div>
        <Table cols={[{ label: '#' }, { label: 'Old Due' }, { label: 'New Due' }, { label: 'Interest Settled', align: 'right' }, { label: 'Capital Before', align: 'right' }, { label: 'Capital After', align: 'right' }, { label: 'By' }]}
          rows={d.renewals || []} empty="No renewals yet."
          render={(r) => <>
            <Td>{r.renewalNo}</Td>
            <Td>{dateOnly(r.oldDueDate)}</Td>
            <Td>{dateOnly(r.newDueDate)}</Td>
            <Td mono align="right">{money(r.interestSettled)}</Td>
            <Td mono align="right">{money(r.capitalBefore)}</Td>
            <Td mono align="right">{money(r.capitalAfter)}</Td>
            <Td>{r.renewedBy?.name || '—'}</Td>
          </>} />
      </Card>
      <Card pad={0}>
        <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Redemptions</div>
        <Table cols={[{ label: 'Type' }, { label: 'Date' }, { label: 'Capital', align: 'right' }, { label: 'Interest', align: 'right' }, { label: 'Total', align: 'right' }, { label: 'Articles Released' }, { label: 'By' }]}
          rows={d.redemptions || []} empty="No redemptions yet."
          render={(r) => <>
            <Td>{r.redemptionType}</Td>
            <Td>{dateOnly(r.settledAt)}</Td>
            <Td mono align="right">{money(r.capitalSettled)}</Td>
            <Td mono align="right">{money(r.interestSettled)}</Td>
            <Td mono align="right">{money(r.totalSettled)}</Td>
            <Td>{(r.items || []).length}</Td>
            <Td>{r.settledBy?.name || '—'}</Td>
          </>} />
      </Card>
    </div>
  )
}

function TicketActions({ pawning: d, me, onChanged }: { pawning: Pawning; me?: AuthUser | null; onChanged: () => void }) {
  const notify = useNotify()
  const [renewOpen, setRenewOpen] = useState(false)
  const [redeemOpen, setRedeemOpen] = useState(false)

  return (
    <div className="row gap-2">
      <Can user={me} module="Pawning Payments" action="Create">
        <Btn variant="solid" size="sm" icon="clock" onClick={() => setRenewOpen(true)}>Renew</Btn>
        <Btn variant="primary" size="sm" icon="check" onClick={() => setRedeemOpen(true)}>Redeem</Btn>
      </Can>
      <RenewModal pawning={d} open={renewOpen} onClose={() => setRenewOpen(false)} onChanged={onChanged} notify={notify} />
      <RedeemModal pawning={d} open={redeemOpen} onClose={() => setRedeemOpen(false)} onChanged={onChanged} notify={notify} />
    </div>
  )
}

function RenewModal({ pawning: d, open, onClose, onChanged, notify }: { pawning: Pawning; open: boolean; onClose: () => void; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const [capitalPayment, setCapitalPayment] = useState('')
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('Cash')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const s = d.summary
  const interest = s?.interest ?? 0
  const total = interest + num(capitalPayment)

  const submit = async () => {
    if (num(capitalPayment) >= num(d.outstandingBalanceCache)) { setErr('A capital payment that clears the full balance is a redemption, not a renewal — use Redeem instead.'); return }
    setBusy(true); setErr('')
    try {
      await pawningsApi.renew(d.id, { capitalPayment: capitalPayment || 0, paymentMethod })
      onClose(); setCapitalPayment(''); onChanged()
      notify.success('Ticket renewed', d.code)
    } catch (ex: any) { setErr(getErrorText(ex, 'Renewal failed')) }
    finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} title="Renew Pawn Ticket" sub={d.code}
      footer={<>
        <Btn variant="solid" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={submit} disabled={busy}>{busy ? 'Processing…' : `Collect ${money(total)} & Renew`}</Btn>
      </>}>
      <div className="col gap-3">
        <ErrorBanner error={err} style={{ marginBottom: 0 }} />
        <div className="t-2" style={{ fontSize: 12.5 }}>
          Interest due to settle this cycle: <b style={{ color: 'var(--tx-0)' }}>{money(interest)}</b>{s?.isSpecial ? ' (special rate)' : ''}. Optionally pay down some capital too — the rest rolls forward under a new cycle.
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Capital Payment (optional)"><Input type="number" value={capitalPayment} onChange={(e) => setCapitalPayment(e.target.value)} placeholder="0.00" /></Field>
          <Field label="Payment Method"><select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value as PaymentMethod)} style={{ padding: '9px 12px', borderRadius: 'var(--r-s)', border: '1px solid var(--line)', background: 'var(--bg-3)', color: 'var(--tx-0)' }}>
            <option value="Cash">Cash</option><option value="Bank">Bank</option><option value="Cheque">Cheque</option>
          </select></Field>
        </div>
        <div style={{ fontSize: 13, fontWeight: 600 }}>Total to collect: {money(total)}</div>
      </div>
    </Modal>
  )
}

function RedeemModal({ pawning: d, open, onClose, onChanged, notify }: { pawning: Pawning; open: boolean; onClose: () => void; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const [full, setFull] = useState(true)
  const [selected, setSelected] = useState<number[]>([])
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('Cash')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const pledged = (d.collateral || []).filter((c) => c.pawnDetail?.itemStatus === 'Pledged')
  const s = d.summary

  const toggle = (id: number) => setSelected((sel) => (sel.includes(id) ? sel.filter((x) => x !== id) : [...sel, id]))

  // Client-side preview only — same math as backend PawningInterestCalculator::quoteForArticles,
  // the server always recomputes and is authoritative.
  const preview = useMemo(() => {
    const targets = full ? pledged : pledged.filter((c) => selected.includes(c.id))
    const totalMaxLoan = pledged.reduce((sum, c) => sum + num(c.pawnDetail?.maxLoanValue), 0)
    const targetMaxLoan = targets.reduce((sum, c) => sum + num(c.pawnDetail?.maxLoanValue), 0)
    const capital = totalMaxLoan > 0 ? num(d.outstandingBalanceCache) * (targetMaxLoan / totalMaxLoan) : 0
    const rate = s?.rate ?? 0
    const interest = capital * rate / 100
    return { capital, interest, total: capital + interest }
  }, [full, selected, pledged, d.outstandingBalanceCache, s])

  const submit = async () => {
    if (!full && selected.length === 0) { setErr('Select at least one article to redeem.'); return }
    setBusy(true); setErr('')
    try {
      await pawningsApi.redeem(d.id, { redemptionType: full ? 'Full' : 'Partial', facilityCollateralIds: full ? undefined : selected, paymentMethod })
      onClose(); setSelected([]); setFull(true); onChanged()
      notify.success(full ? 'Ticket fully redeemed' : 'Articles redeemed', d.code)
    } catch (ex: any) { setErr(getErrorText(ex, 'Redemption failed')) }
    finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} title="Redeem Pawn Ticket" sub={d.code}
      footer={<>
        <Btn variant="solid" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={submit} disabled={busy}>{busy ? 'Processing…' : `Collect ~${money(preview.total)} & Redeem`}</Btn>
      </>}>
      <div className="col gap-3">
        <ErrorBanner error={err} style={{ marginBottom: 0 }} />
        <div className="row gap-2">
          <Btn size="sm" variant={full ? 'primary' : 'solid'} onClick={() => setFull(true)}>Full Redemption</Btn>
          <Btn size="sm" variant={!full ? 'primary' : 'solid'} onClick={() => setFull(false)}>Partial — Select Articles</Btn>
        </div>
        {!full && (
          <div className="col gap-1" style={{ maxHeight: 220, overflowY: 'auto' }}>
            {pledged.map((c) => (
              <label key={c.id} className="row gap-2" style={{ alignItems: 'center', padding: '6px 8px', border: '1px solid var(--line-soft)', borderRadius: 'var(--r-s)', cursor: 'pointer' }}>
                <input type="checkbox" checked={selected.includes(c.id)} onChange={() => toggle(c.id)} />
                <span style={{ flex: 1 }}>{c.pawnDetail?.articleType?.name || c.description}</span>
                <span className="t-2" style={{ fontSize: 11.5 }}>max {money(c.pawnDetail?.maxLoanValue)}</span>
              </label>
            ))}
          </div>
        )}
        <Field label="Payment Method"><select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value as PaymentMethod)} style={{ padding: '9px 12px', borderRadius: 'var(--r-s)', border: '1px solid var(--line)', background: 'var(--bg-3)', color: 'var(--tx-0)', maxWidth: 200 }}>
          <option value="Cash">Cash</option><option value="Bank">Bank</option><option value="Cheque">Cheque</option>
        </select></Field>
        <div className="t-2" style={{ fontSize: 12.5 }}>
          Estimated capital {money(preview.capital)} + interest {money(preview.interest)} = <b style={{ color: 'var(--tx-0)' }}>{money(preview.total)}</b>. The server confirms the exact figure.
        </div>
      </div>
    </Modal>
  )
}
