/* Funding Management — Pre-maturity Withdrawal: take money out of an active
   Fixed Deposit before it matures, at the funding product's reduced
   premature-withdrawal rate. Two routes:
     - Close the whole FD early (fixedDepositsApi.prematureWithdraw), or
     - Withdraw part of the principal and keep the FD open on the rest
       (fixedDepositsApi.partPayment, via the shared PartPaymentModal).
   Matured FDs are withdrawn from Deposit Payment instead. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, SearchSelect, Table, Td, Modal, inputStyle, ErrorBanner, BankAccountSelect } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  fixedDepositsApi, company,
  type FixedDeposit, type FdPartPayment, type FdPrematurePreview, type FdPaymentMethod, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import { CustomerBankDetailPicker } from '../components/CustomerBankDetailPicker'
import { PartPaymentModal, PrintView, type PrintDoc } from './DepositPayment'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const METHODS: { value: FdPaymentMethod; label: string }[] = [
  { value: 'Cash', label: 'Cash' },
  { value: 'Cheque', label: 'Cheque' },
  { value: 'Bank', label: 'Bank Transfer' },
]

function FullClosureModal({ fd, open, onClose, onClosed }: { fd: FixedDeposit | null; open: boolean; onClose: () => void; onClosed: (w: Awaited<ReturnType<typeof fixedDepositsApi.prematureWithdraw>>) => void }) {
  const notify = useNotify()
  const [method, setMethod] = useState<FdPaymentMethod>('Cash')
  const [bankAccountId, setBankAccountId] = useState<number | undefined>(undefined)
  const [refNo, setRefNo] = useState('')
  const [notes, setNotes] = useState('')
  const [closedAt, setClosedAt] = useState(todayIso())
  const [backDated, setBackDated] = useState(false)
  const [rateInput, setRateInput] = useState('')
  const [preview, setPreview] = useState<FdPrematurePreview | null>(null)
  const [payoutId, setPayoutId] = useState<number | undefined>(undefined)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    if (open) {
      setPayoutId(fd?.customerBankDetailId ?? undefined)
      setMethod('Cash'); setBankAccountId(undefined); setRefNo(''); setNotes(''); setClosedAt(todayIso()); setBackDated(false); setErr('')
      const r = fd?.fundingProduct?.prematureWithdrawalRatePercent
      setRateInput(r != null ? String(Number(r)) : '')
    }
  }, [open])
  useEffect(() => {
    if (!open || !fd) return
    setPreview(null)
    const rate = rateInput.trim() === '' ? undefined : rateInput
    // Debounced so typing a rate doesn't fire a request per keystroke.
    const t = setTimeout(() => {
      fixedDepositsApi.prematurePreview(fd.id, { closedAt, prematureRatePercent: rate }).then((p) => { setErr(''); setPreview(p) }).catch((ex) => setErr(getErrorText(ex, 'Could not work out the payout.')))
    }, 300)
    return () => clearTimeout(t)
  }, [open, fd?.id, closedAt, rateInput])
  if (!fd) return null

  const save = async () => {
    if (method !== 'Cash' && !bankAccountId) { setErr('Choose the company bank account this payment is made from.'); return }
    if (!payoutId) { setErr("Select or add the customer's payout bank account."); return }
    setBusy(true); setErr('')
    try {
      if (payoutId !== fd.customerBankDetailId) await fixedDepositsApi.setPayoutAccount(fd.id, payoutId)
      const result = await fixedDepositsApi.prematureWithdraw(fd.id, { paymentMethod: method, bankAccountId: method !== 'Cash' ? bankAccountId : undefined, referenceNo: refNo || undefined, notes: notes || undefined, closedAt, prematureRatePercent: rateInput.trim() === '' ? undefined : rateInput })
      onClosed(result)
      notify.success('Fixed Deposit closed early', fd.fdNumber)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not close this Fixed Deposit.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={540} title={`Close ${fd.fdNumber} Early`} sub="The whole FD is paid out and closed. Interest is recalculated at the premature rate."
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy || !preview}>{busy ? 'Processing...' : 'Close FD & Print Voucher'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <div className="col gap-3">
        <div style={{ background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: 14, fontSize: 12.5 }}>
          {preview ? (
            <div className="col gap-2">
              <div className="row between"><span className="t-2">Principal</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.principal)}</b></div>
              <div className="row between"><span className="t-2">Held</span><b>{preview.heldDays} days @ {preview.rate.toFixed(2)}% premature rate</b></div>
              <div className="row between"><span className="t-2">Interest Entitlement</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.entitlement)}</b></div>
              {preview.paidSoFar > 0 && <div className="row between"><span className="t-2">Interest Already Paid (monthly)</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.paidSoFar)}</b></div>}
              {preview.interestPaid > 0 && <div className="row between"><span className="t-2">Interest Payable Now</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.interestPaid)}</b></div>}
              {preview.clawback > 0 && <div className="row between"><span className="t-2">Clawback (paid more than entitled)</span><b style={{ fontFamily: 'JetBrains Mono', color: 'var(--bad)' }}>({money(preview.clawback)})</b></div>}
              <div className="row between" style={{ fontWeight: 700, paddingTop: 6, borderTop: '1px solid var(--line)' }}><span>Total Payable</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.total)}</b></div>
            </div>
          ) : <span className="t-2">Calculating…</span>}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as FdPaymentMethod)}>
            {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </Select></Field>
          {method !== 'Cash' && <Field label="Paid from — company bank account"><BankAccountSelect value={bankAccountId} onChange={setBankAccountId} /></Field>}
          <Field label="Customer payout bank account (add or update if needed)" full>
            <CustomerBankDetailPicker customerId={fd.customerId} value={payoutId} onChange={setPayoutId} />
          </Field>
          <Field label="Closing Date">
            <DateInput value={closedAt} disabled={!backDated} onChange={setClosedAt} />
            <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 6 }}>
              <input type="checkbox" checked={backDated} onChange={(e) => { setBackDated(e.target.checked); if (!e.target.checked) setClosedAt(todayIso()) }} />
              <span className="t-2" style={{ fontSize: 11.5 }}>Back Dated Entry</span>
            </label>
          </Field>
          <Field label="Premature Rate (%)"><Input type="number" step="0.01" min="0" value={rateInput} onChange={(e) => setRateInput(e.target.value)} /></Field>
          <Field label="Reference No"><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>
        </div>
        <Field label="Notes" full><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>
    </Modal>
  )
}

export function PrematureWithdrawalScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [fds, setFds] = useState<FixedDeposit[]>([])
  const [loading, setLoading] = useState(true)
  const [brand, setBrand] = useState({ name: '', tagline: '' })
  const [selected, setSelected] = useState<FixedDeposit | null>(null)
  const [loadingFd, setLoadingFd] = useState(false)
  const [closeOpen, setCloseOpen] = useState(false)
  const [partOpen, setPartOpen] = useState(false)
  const [doc, setDoc] = useState<PrintDoc | null>(null)

  const refresh = () => {
    setLoading(true)
    // Only FDs that haven't matured can be withdrawn early.
    fixedDepositsApi.list({ status: 'Active' }).then((l) => setFds(l.filter((f) => !f.isMatured))).catch(() => setFds([])).finally(() => setLoading(false))
  }
  useEffect(() => {
    refresh()
    company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {})
  }, [])

  const pickFd = async (_n: string, opt?: FixedDeposit) => {
    setCloseOpen(false); setPartOpen(false)
    if (!opt) { setSelected(null); return }
    setLoadingFd(true)
    try { setSelected(await fixedDepositsApi.get(opt.id)) } catch { setSelected(opt) } finally { setLoadingFd(false) }
  }
  const reloadSelected = async () => {
    if (!selected) return
    try { setSelected(await fixedDepositsApi.get(selected.id)) } catch { /* keep stale copy */ }
  }
  const voidPartPayment = async (pp: FdPartPayment) => {
    const ok = await notify.confirm({ title: `Void part payment ${pp.voucherNo}?`, msg: 'Reverses its GL/Cashbook entries and restores the FD principal and interest schedule.', danger: true, okText: 'Void' })
    if (!ok) return
    try { await fixedDepositsApi.voidPartPayment(pp.id); notify.success('Part payment voided'); refresh(); await reloadSelected() }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  const rate = selected?.fundingProduct?.prematureWithdrawalRatePercent
  const noRate = !!selected && (rate === null || rate === undefined)
  const pledged = !!selected?.isPledged
  const partPayments = selected?.partPayments || []

  if (doc) return <PrintView doc={doc} brand={brand} onClose={() => setDoc(null)} />

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Pre-maturity Withdrawal" icon="flag"
        sub="Withdraw from a Fixed Deposit before it matures — close it early or take out part of the principal. Interest is paid at the product's premature rate." />

      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Find Fixed Deposit</div>
        <SearchSelect
          value={selected ? `${selected.fdNumber} — ${selected.customer?.fullName}` : ''}
          onChange={(n, opt) => pickFd(n, opt)}
          options={fds}
          getLabel={(f: FixedDeposit) => `${f.fdNumber} — ${f.customer?.fullName}`}
          getSub={(f: FixedDeposit) => [f.customer?.nicNo, f.fundingProduct?.name, `matures ${dateOnly(f.maturityDate)}`].filter(Boolean).join(' · ')}
          placeholder={loading ? 'Loading Fixed Deposits…' : 'Search by FD No, NIC, or customer name…'}
          disabled={loading}
        />
        <div className="t-2" style={{ fontSize: 11.5, marginTop: 8 }}>Only active, not-yet-matured FDs are listed. For a matured FD use Deposit Payment.</div>
      </Card>

      {loadingFd && <div style={{ padding: 30, textAlign: 'center', color: 'var(--tx-2)' }}>Loading Fixed Deposit…</div>}

      {selected && !loadingFd && (
        <>
          <Card style={{ marginBottom: 16 }}>
            <div className="row between wrap gap-2" style={{ marginBottom: 14 }}>
              <div className="eyebrow">Fixed Deposit Summary</div>
              <Can user={me} module="Fixed Deposits" action="Edit">
                <div className="row gap-2">
                  <Btn variant="solid" size="sm" icon="download" disabled={noRate || pledged} onClick={() => setPartOpen(true)}>Part Withdrawal</Btn>
                  <Btn variant="primary" size="sm" icon="flag" disabled={noRate || pledged} onClick={() => setCloseOpen(true)}>Close FD Early</Btn>
                </div>
              </Can>
            </div>
            {noRate && (
              <div style={{ background: 'var(--warn-dim)', color: 'var(--warn)', border: '1px solid var(--warn)', padding: '10px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5, marginBottom: 14 }}>
                This funding product has no premature withdrawal rate configured — set one on Funding Products before pre-maturity withdrawals can be made.
              </div>
            )}
            {pledged && (
              <div style={{ background: 'var(--warn-dim)', color: 'var(--warn)', border: '1px solid var(--warn)', padding: '10px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5, marginBottom: 14 }}>
                This FD is pledged as security for a loan and cannot be withdrawn until the lien is released.
              </div>
            )}
            <div className="row wrap gap-3" style={{ alignItems: 'center', paddingBottom: 14, marginBottom: 14, borderBottom: '1px solid var(--line)' }}>
              <div>
                <div style={{ fontSize: 15, fontWeight: 700 }}>{selected.customer?.fullName}</div>
                <div className="t-2" style={{ fontSize: 11.5 }}>NIC {selected.customer?.nicNo || '—'}</div>
              </div>
              <div style={{ width: 1, height: 30, background: 'var(--line)' }} />
              <div>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em' }}>FD No</div>
                <div style={{ fontFamily: 'JetBrains Mono', fontWeight: 600, fontSize: 13 }}>{selected.fdNumber}</div>
              </div>
              <div style={{ width: 1, height: 30, background: 'var(--line)' }} />
              <div>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em' }}>Product</div>
                <div style={{ fontWeight: 600, fontSize: 13 }}>{selected.fundingProduct?.name || '—'}</div>
              </div>
              <div style={{ width: 1, height: 30, background: 'var(--line)' }} />
              <Badge tone="green" dot>{selected.status}</Badge>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12 }}>
              {[
                ['Principal', money(selected.principalAmount), true],
                ['Contract Rate', `${Number(selected.interestRatePercent).toFixed(2)}%`, false],
                ['Premature Rate', rate != null ? `${Number(rate).toFixed(2)}%` : 'Not set', false],
                ['Interest Method', selected.interestPaymentMethod === 'monthly' ? 'Monthly' : 'At Maturity', false],
                ['Start Date', dateOnly(selected.startDate), false],
                ['Maturity Date', dateOnly(selected.maturityDate), false],
              ].map(([label, value, mono]) => (
                <div key={label as string}><div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 3 }}>{label}</div><div style={{ fontFamily: mono ? 'JetBrains Mono' : undefined, fontWeight: 700, fontSize: 15 }}>{value}</div></div>
              ))}
            </div>
          </Card>

          <Card pad={0} style={{ marginBottom: 16 }}>
            <div className="eyebrow" style={{ padding: '12px 14px', borderBottom: '1px solid var(--line)' }}>Part Withdrawals on this FD {partPayments.length ? `(${partPayments.length})` : ''}</div>
            <Table cols={[{ label: 'Voucher' }, { label: 'Date' }, { label: 'Principal', align: 'right' }, { label: 'Net Paid', align: 'right' }, { label: 'Method' }, { label: '', align: 'right', w: 90 }]}
              rows={partPayments} empty="No part withdrawals recorded"
              render={(pp: FdPartPayment) => <>
                <Td mono>{pp.voucherNo}</Td>
                <Td>{dateOnly(pp.paidAt)}</Td>
                <Td align="right" mono>{money(pp.principalAmount)}</Td>
                <Td align="right" mono>{money(pp.netAmountPaid)}</Td>
                <Td>{pp.paymentMethod}</Td>
                <Td align="right">
                  <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                    <button className="mms-act" title="Print" onClick={() => setDoc({ kind: 'partpayment', partPayment: pp, fd: selected })}><Icon n="print" s={14} /></button>
                    {pp.id === partPayments[0]?.id && (
                      <Can user={me} module="Fixed Deposits" action="Delete">
                        <button className="mms-act" title="Void" onClick={() => voidPartPayment(pp)}><Icon n="trash" s={14} /></button>
                      </Can>
                    )}
                  </div>
                </Td>
              </>} />
          </Card>
        </>
      )}

      <FullClosureModal fd={selected} open={closeOpen} onClose={() => setCloseOpen(false)}
        onClosed={(w) => { setCloseOpen(false); setSelected(null); setDoc({ kind: 'withdrawal', withdrawal: w, fd: w.fixedDeposit }); refresh() }} />
      <PartPaymentModal fd={selected} open={partOpen} onClose={() => setPartOpen(false)}
        onPaid={(pp, fd) => { setPartOpen(false); setDoc({ kind: 'partpayment', partPayment: pp, fd }); refresh(); reloadSelected() }} />

      {!selected && !loadingFd && !loading && fds.length === 0 && (
        <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>No active, unmatured Fixed Deposits found.</div>
      )}
    </div>
  )
}

export default PrematureWithdrawalScreen
