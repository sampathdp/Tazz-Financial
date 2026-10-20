/* Funding / Investment — Branch Funding: one branch lends cash to another, always routed through Head Office.
   The borrower branch requests; Head Office approves (checks the lender's cash and the borrower's limit) and the
   money moves lender -> Head Office -> borrower. Interest is simple, at a rate set per agreement, and is booked
   either every month-end or only when the borrower repays (chosen per agreement). Company-wide the entries net to
   zero; each branch's own books show only a balance against Head Office. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Table, Td, Modal, ErrorBanner, LoadingBlock } from '../components/ui'
import {
  branchFundingApi,
  type BranchFunding, type BranchFundingStatus, type BranchFundingAccrual, type BranchFundingLookups, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { AdminEditButton } from '../components/AdminEditButton'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const STATUS_TONE: Record<BranchFundingStatus, 'green' | 'amber' | 'neutral' | 'red'> = { Requested: 'amber', Active: 'green', Closed: 'neutral', Rejected: 'red' }
const MODE_LABEL: Record<BranchFundingAccrual, string> = { monthly: 'Monthly (month-end)', on_repayment: 'On repayment only' }

interface Form {
  lenderBranchId?: number; borrowerBranchId?: number; lenderAccountId?: number; borrowerAccountId?: number
  principalAmount: string; interestRatePercent: string; accrualMode: BranchFundingAccrual; requestDate: string; purpose: string
}
const blank = (): Form => ({ principalAmount: '', interestRatePercent: '', accrualMode: 'monthly', requestDate: todayIso(), purpose: '' })

export function BranchFundingScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<BranchFunding[]>([])
  const [lookups, setLookups] = useState<BranchFundingLookups | null>(null)
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(false)
  const [form, setForm] = useState<Form>(blank())
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [detail, setDetail] = useState<BranchFunding | null>(null)
  const [repayAmt, setRepayAmt] = useState('')
  const [actDate, setActDate] = useState(todayIso())

  const refresh = () => {
    setLoading(true)
    branchFundingApi.list().then(setRows).catch((e) => notify.error('Could not load', getErrorText(e))).finally(() => setLoading(false))
  }
  useEffect(() => { refresh(); branchFundingApi.lookups().then(setLookups).catch(() => {}) }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const branchById = (id?: number) => lookups?.branches.find((b) => b.id === id)
  const lender = branchById(form.lenderBranchId)
  const borrower = branchById(form.borrowerBranchId)

  const openCreate = () => { setForm(blank()); setErr(''); setModal(true) }
  const close = () => { setModal(false); setErr('') }

  const save = async () => {
    if (!form.lenderBranchId || !form.borrowerBranchId || !form.lenderAccountId || !form.borrowerAccountId) { setErr('Choose both branches and an account for each.'); return }
    if (!(Number(form.principalAmount) > 0)) { setErr('Enter the amount.'); return }
    if (form.interestRatePercent === '') { setErr('Enter the interest rate (0 for none).'); return }
    setBusy(true); setErr('')
    try {
      const created = await branchFundingApi.create({
        lenderBranchId: form.lenderBranchId, borrowerBranchId: form.borrowerBranchId,
        lenderAccountId: form.lenderAccountId, borrowerAccountId: form.borrowerAccountId,
        principalAmount: form.principalAmount, interestRatePercent: form.interestRatePercent,
        accrualMode: form.accrualMode, requestDate: form.requestDate, purpose: form.purpose || undefined,
      })
      notify.success('Funding requested', created.code)
      close(); refresh()
    } catch (ex: any) { setErr(getErrorText(ex)) } finally { setBusy(false) }
  }

  const act = async (label: string, fn: () => Promise<BranchFunding>) => {
    if (!detail) return
    setBusy(true)
    try {
      const updated = await fn()
      setDetail(updated); setRepayAmt(''); refresh()
      notify.success(label, updated.code)
    } catch (ex: any) { notify.error(`${label} failed`, getErrorText(ex)) } finally { setBusy(false) }
  }

  const openDetail = (a: BranchFunding) => { setRepayAmt(''); setActDate(todayIso()); branchFundingApi.get(a.id).then(setDetail).catch((e) => notify.error('Could not load', getErrorText(e))) }
  const due = detail ? Number(detail.outstandingPrincipal) + Number(detail.accruedInterestUnpaid) : 0

  return (
    <div className="page">
      <PageHead crumbs="Funding / Investment" title="Branch Funding" icon="coins" sub={`${rows.length} agreements · routed through Head Office`}
        actions={<Can user={me} module="Branch Funding" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Request Funding</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> : (
          <Table cols={[{ label: 'No.' }, { label: 'Lender' }, { label: 'Borrower' }, { label: 'Requested', align: 'right' }, { label: 'Outstanding', align: 'right' }, { label: 'Unpaid Interest', align: 'right' }, { label: 'Rate %', align: 'right' }, { label: 'Status', align: 'center' }]}
            rows={rows} empty="No branch funding agreements" onRow={openDetail}
            render={(a) => <>
              <Td mono>{a.code}</Td>
              <Td>{a.lenderBranch?.name}</Td>
              <Td>{a.borrowerBranch?.name}</Td>
              <Td align="right" mono>{money(a.principalAmount)}</Td>
              <Td align="right" mono>{money(a.outstandingPrincipal)}</Td>
              <Td align="right" mono>{money(a.accruedInterestUnpaid)}</Td>
              <Td align="right" mono>{Number(a.interestRatePercent)}</Td>
              <Td align="center"><Badge tone={STATUS_TONE[a.status]} dot>{a.status}</Badge></Td>
            </>} />
        )}
      </Card>

      <Modal open={modal} onClose={close} width={640} title="Request Branch Funding" sub="Cash moves lender → Head Office → borrower once Head Office approves."
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Submit Request'}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Borrowing Branch">
            <Select value={form.borrowerBranchId ?? ''} onChange={(e) => setForm((s) => ({ ...s, borrowerBranchId: Number(e.target.value) || undefined, borrowerAccountId: undefined }))}>
              <option value="">—</option>
              {lookups?.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </Select>
          </Field>
          <Field label="Receives Into">
            <Select value={form.borrowerAccountId ?? ''} onChange={(e) => setForm((s) => ({ ...s, borrowerAccountId: Number(e.target.value) || undefined }))}>
              <option value="">—</option>
              {borrower?.accounts.map((a) => <option key={a.chartAccountId} value={a.chartAccountId}>{a.name}</option>)}
            </Select>
          </Field>
          <Field label="Lending Branch">
            <Select value={form.lenderBranchId ?? ''} onChange={(e) => setForm((s) => ({ ...s, lenderBranchId: Number(e.target.value) || undefined, lenderAccountId: undefined }))}>
              <option value="">—</option>
              {lookups?.branches.filter((b) => b.id !== form.borrowerBranchId).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </Select>
          </Field>
          <Field label="Paid From">
            <Select value={form.lenderAccountId ?? ''} onChange={(e) => setForm((s) => ({ ...s, lenderAccountId: Number(e.target.value) || undefined }))}>
              <option value="">—</option>
              {lender?.accounts.map((a) => <option key={a.chartAccountId} value={a.chartAccountId}>{a.name}</option>)}
            </Select>
          </Field>
          <Field label="Amount"><Input type="number" step="0.01" value={form.principalAmount} onChange={(e) => setForm((s) => ({ ...s, principalAmount: e.target.value }))} /></Field>
          <Field label="Interest Rate % (p.a.)"><Input type="number" step="0.001" value={form.interestRatePercent} onChange={(e) => setForm((s) => ({ ...s, interestRatePercent: e.target.value }))} /></Field>
          <Field label="Interest is Booked">
            <Select value={form.accrualMode} onChange={(e) => setForm((s) => ({ ...s, accrualMode: e.target.value as BranchFundingAccrual }))}>
              {(lookups?.accrualModes ?? ['monthly', 'on_repayment']).map((m) => <option key={m} value={m}>{MODE_LABEL[m]}</option>)}
            </Select>
          </Field>
          <Field label="Request Date"><DateInput value={form.requestDate} onChange={(iso) => setForm((s) => ({ ...s, requestDate: iso }))} /></Field>
          <Field label="Purpose" full><Input value={form.purpose} onChange={(e) => setForm((s) => ({ ...s, purpose: e.target.value }))} /></Field>
          {borrower && (
            <Field label="" full>
              <div className="t-2" style={{ fontSize: 12 }}>
                {borrower.name} currently owes {money(borrower.owed)} through branch funding
                {borrower.fundingBorrowLimit != null ? `; its limit is ${money(borrower.fundingBorrowLimit)}.` : '; it has no funding limit set.'}
              </div>
            </Field>
          )}
        </div>
      </Modal>

      <Modal open={!!detail} onClose={() => setDetail(null)} width={720} title={detail ? `Funding ${detail.code}` : ''}
        sub={detail ? `${detail.lenderBranch?.name} lends to ${detail.borrowerBranch?.name} via Head Office` : ''}>
        {detail && <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14, marginBottom: 16 }}>
            <div><div className="t-2" style={{ fontSize: 11.5 }}>Principal</div><div className="mono">{money(detail.principalAmount)}</div></div>
            <div><div className="t-2" style={{ fontSize: 11.5 }}>Outstanding</div><div className="mono">{money(detail.outstandingPrincipal)}</div></div>
            <div><div className="t-2" style={{ fontSize: 11.5 }}>Unpaid interest</div><div className="mono">{money(detail.accruedInterestUnpaid)}</div></div>
            <div><div className="t-2" style={{ fontSize: 11.5 }}>Rate</div><div>{Number(detail.interestRatePercent)}% p.a.</div></div>
            <div><div className="t-2" style={{ fontSize: 11.5 }}>Interest booked</div><div>{MODE_LABEL[detail.accrualMode]}</div></div>
            <div><div className="t-2" style={{ fontSize: 11.5 }}>Status</div><Badge tone={STATUS_TONE[detail.status]} dot>{detail.status}</Badge></div>
          </div>

          {detail.status === 'Requested' && (
            <div className="row gap-2" style={{ marginBottom: 12 }}>
              <AdminEditButton user={me} title={`Edit ${detail.code}`} values={{ ...detail }}
                hint="Branches are fixed; amount and terms can change until the agreement is approved."
                fields={[
                  { key: 'principalAmount', label: 'Principal', type: 'number', step: '0.01' },
                  { key: 'interestRatePercent', label: 'Rate % p.a.', type: 'number', step: '0.001' },
                  { key: 'accrualMode', label: 'Interest Booked', type: 'select', options: Object.entries(MODE_LABEL).map(([value, label]) => ({ value, label: String(label) })) },
                  { key: 'requestDate', label: 'Request Date', type: 'date' },
                  { key: 'purpose', label: 'Purpose', full: true },
                  { key: 'notes', label: 'Notes', type: 'textarea' },
                ]}
                onSave={(v) => branchFundingApi.adminUpdate(detail.id, { ...v })}
                onSaved={(r) => { setDetail(r); refresh() }} />
            </div>
          )}
          {detail.status === 'Requested' && (
            <Can user={me} module="Branch Funding" action="Approve">
              <div className="row gap-2" style={{ marginBottom: 16 }}>
                <DateInput value={actDate} onChange={setActDate} />
                <Btn variant="primary" icon="check" disabled={busy} onClick={() => act('Funding approved', () => branchFundingApi.approve(detail.id, actDate))}>Approve &amp; Pay Out</Btn>
                <Btn variant="plain" disabled={busy} onClick={() => act('Funding rejected', () => branchFundingApi.reject(detail.id))}>Reject</Btn>
              </div>
            </Can>
          )}
          {detail.status === 'Active' && (
            <Can user={me} module="Branch Funding" action="Edit">
              <div className="row gap-2" style={{ marginBottom: 16, flexWrap: 'wrap' }}>
                <DateInput value={actDate} onChange={setActDate} />
                <Btn variant="plain" disabled={busy} onClick={() => act('Interest booked', () => branchFundingApi.accrue(detail.id, actDate))}>Book Interest to Date</Btn>
                <Input type="number" step="0.01" placeholder={`Repay (max ${money(due)} + interest to date)`} value={repayAmt} onChange={(e) => setRepayAmt(e.target.value)} style={{ maxWidth: 220 }} />
                <Btn variant="primary" disabled={busy || !(Number(repayAmt) > 0)} onClick={() => act('Repayment recorded', () => branchFundingApi.repay(detail.id, repayAmt, actDate))}>Record Repayment</Btn>
              </div>
            </Can>
          )}

          <Table cols={[{ label: 'Date' }, { label: 'Type' }, { label: 'Principal', align: 'right' }, { label: 'Interest', align: 'right' }]}
            rows={detail.transactions ?? []} empty="No movements yet"
            render={(t) => <>
              <Td>{dateOnly(t.txnDate)}</Td>
              <Td>{t.kind}</Td>
              <Td align="right" mono>{money(t.principalAmount)}</Td>
              <Td align="right" mono>{money(t.interestAmount)}</Td>
            </>} />
        </>}
      </Modal>
    </div>
  )
}
