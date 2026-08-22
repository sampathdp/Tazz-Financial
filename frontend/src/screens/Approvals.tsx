/* Tazz Investment & Credit — Approvals inbox: admin-only consolidated view of
   every loan-cancel / payment-void request awaiting a second person's sign-off.
   Requester and approvers are always different people (enforced server-side —
   see LoanController::approveCancelRequest() / LoanPaymentController::approveVoidRequest()),
   so this page hides the Approve/Reject buttons for anyone who submitted or
   already approved a given request, rather than letting the click round-trip
   just to bounce off the 422. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Table, Td } from '../components/ui'
import { approvalsApi, loansApi, loanPaymentsApi, type ApprovalsInbox, type AuthUser } from '../api'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')

function progressLabel(requiredApprovals: number, firstApprover?: { name: string } | null) {
  const remaining = requiredApprovals - (firstApprover ? 1 : 0)
  return firstApprover
    ? `Approved by ${firstApprover.name} · needs ${remaining} more`
    : `Needs ${remaining} approval${remaining === 1 ? '' : 's'}`
}

const EMPTY_INBOX: ApprovalsInbox = { loanApplicationRequests: [], speedDraftApplicationRequests: [], loanCancelRequests: [], paymentVoidRequests: [], allocationRequests: [] }

export function ApprovalsScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [data, setData] = useState<ApprovalsInbox>(EMPTY_INBOX)
  const [loading, setLoading] = useState(true)

  const refresh = () => {
    setLoading(true)
    approvalsApi.list().then(setData).catch(() => setData(EMPTY_INBOX)).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [])

  const canAct = (r: { requester?: { id: number } | null; firstApprover?: { id: number } | null }) =>
    me?.id !== r.requester?.id && me?.id !== r.firstApprover?.id

  const approveLoan = async (id: number, code: string) => {
    try { await loansApi.approveCancelRequest(id); notify.success('Approved', code); refresh() }
    catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
  }
  const rejectLoan = async (id: number, code: string) => {
    const reason = await notify.prompt({ title: `Reject cancel request for ${code}?`, msg: 'Give a reason — the requester can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try { await loansApi.rejectCancelRequest(id, reason || undefined); notify.success('Rejected', code); refresh() }
    catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }
  const approvePayment = async (id: number, code: string) => {
    try { await loanPaymentsApi.approveVoidRequest(id); notify.success('Approved', code); refresh() }
    catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
  }
  const rejectPayment = async (id: number, code: string) => {
    const reason = await notify.prompt({ title: `Reject cancel request for ${code}?`, msg: 'Give a reason — the requester can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try { await loanPaymentsApi.rejectVoidRequest(id, reason || undefined); notify.success('Rejected', code); refresh() }
    catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }

  const approveAllocation = async (id: number, code: string) => {
    try { await loanPaymentsApi.approveAllocationRequest(id); notify.success('Approved', code); refresh() }
    catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
  }
  const rejectAllocation = async (id: number, code: string) => {
    const reason = await notify.prompt({ title: `Reject allocation request for ${code}?`, msg: 'Give a reason — the cashier can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try { await loanPaymentsApi.rejectAllocationRequest(id, reason || undefined); notify.success('Rejected', code); refresh() }
    catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }

  const total = data.loanApplicationRequests.length + data.speedDraftApplicationRequests.length
    + data.loanCancelRequests.length + data.paymentVoidRequests.length + data.allocationRequests.length

  return (
    <div>
      <PageHead crumbs="Administration" title="Approvals" icon="shield" sub={`${total} request${total === 1 ? '' : 's'} awaiting approval`} />

      <Card pad={0} style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Loan Applications</div>
        <Table cols={[{ label: 'Application' }, { label: 'Customer' }, { label: 'Product' }, { label: 'Amount', align: 'right' }, { label: 'Applied' }]}
          rows={data.loanApplicationRequests} empty={loading ? 'Loading…' : 'No loan applications pending.'}
          onRow={(r) => r.loan && go(`loan/apps/${r.loan.id}`)}
          render={(r) => <>
            <Td mono style={{ fontWeight: 600 }}>{r.loan?.code || '—'}</Td>
            <Td>{r.loan?.customer?.fullName || '—'}</Td>
            <Td>{r.loan?.loanDetail?.loanType?.name || '—'}</Td>
            <Td mono align="right">{r.loan ? money(r.loan.principalAmount) : '—'}</Td>
            <Td>{dateOnly(r.requestedAt)}</Td>
          </>} />
      </Card>

      <Card pad={0} style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Speed Drafts</div>
        <Table cols={[{ label: 'Draft' }, { label: 'Customer' }, { label: 'Type' }, { label: 'Capital', align: 'right' }, { label: 'Applied' }]}
          rows={data.speedDraftApplicationRequests} empty={loading ? 'Loading…' : 'No Speed Drafts pending.'}
          onRow={(r) => r.speedDraft && go(`sd/drafts/${r.speedDraft.id}`)}
          render={(r) => <>
            <Td mono style={{ fontWeight: 600 }}>{r.speedDraft?.code || '—'}</Td>
            <Td>{r.speedDraft?.customer?.fullName || '—'}</Td>
            <Td>{r.speedDraft?.speedDraftDetail?.speedDraftType?.name || '—'}</Td>
            <Td mono align="right">{r.speedDraft ? money(r.speedDraft.principalAmount) : '—'}</Td>
            <Td>{dateOnly(r.requestedAt)}</Td>
          </>} />
      </Card>

      <Card pad={0} style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Loan Cancellations</div>
        <Table cols={[{ label: 'Loan' }, { label: 'Customer' }, { label: 'Amount', align: 'right' }, { label: 'Requested By' }, { label: 'Reason' }, { label: 'Progress' }, { label: '', align: 'right', w: 180 }]}
          rows={data.loanCancelRequests} empty={loading ? 'Loading…' : 'No loan cancel requests pending.'}
          render={(r) => <>
            <Td mono style={{ fontWeight: 600 }}>{r.loan?.code || '—'}</Td>
            <Td>{r.loan?.customer?.fullName || '—'}</Td>
            <Td mono align="right">{r.loan ? money(r.loan.principalAmount) : '—'}</Td>
            <Td>{r.requester?.name || '—'}<div className="t-2" style={{ fontSize: 11 }}>{dateOnly(r.requestedAt)}</div></Td>
            <Td>{r.reason}</Td>
            <Td><Badge tone="amber" dot>{progressLabel(r.requiredApprovals, r.firstApprover)}</Badge></Td>
            <Td align="right">
              {canAct(r) ? (
                <div className="row gap-2" style={{ justifyContent: 'flex-end' }}>
                  <Btn size="sm" variant="primary" icon="check" onClick={() => approveLoan(r.id, r.loan?.code || '')}>Approve</Btn>
                  <Btn size="sm" variant="danger" icon="x" onClick={() => rejectLoan(r.id, r.loan?.code || '')}>Reject</Btn>
                </div>
              ) : <span className="t-2" style={{ fontSize: 11.5 }}>Awaiting another approver</span>}
            </Td>
          </>} />
      </Card>

      <Card pad={0}>
        <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Payment Receipt Cancellations</div>
        <Table cols={[{ label: 'Receipt' }, { label: 'Loan' }, { label: 'Customer' }, { label: 'Amount', align: 'right' }, { label: 'Requested By' }, { label: 'Reason' }, { label: 'Progress' }, { label: '', align: 'right', w: 180 }]}
          rows={data.paymentVoidRequests} empty={loading ? 'Loading…' : 'No payment cancel requests pending.'}
          render={(r) => <>
            <Td mono style={{ fontWeight: 600 }}>{r.payment?.code || '—'}</Td>
            <Td mono>{r.payment?.facility?.code || '—'}</Td>
            <Td>{r.payment?.facility?.customer?.fullName || '—'}</Td>
            <Td mono align="right">{r.payment ? money(r.payment.amount) : '—'}</Td>
            <Td>{r.requester?.name || '—'}<div className="t-2" style={{ fontSize: 11 }}>{dateOnly(r.requestedAt)}</div></Td>
            <Td>{r.reason || '—'}</Td>
            <Td><Badge tone="amber" dot>{progressLabel(r.requiredApprovals, r.firstApprover)}</Badge></Td>
            <Td align="right">
              {canAct(r) ? (
                <div className="row gap-2" style={{ justifyContent: 'flex-end' }}>
                  <Btn size="sm" variant="primary" icon="check" onClick={() => approvePayment(r.id, r.payment?.code || '')}>Approve</Btn>
                  <Btn size="sm" variant="danger" icon="x" onClick={() => rejectPayment(r.id, r.payment?.code || '')}>Reject</Btn>
                </div>
              ) : <span className="t-2" style={{ fontSize: 11.5 }}>Awaiting another approver</span>}
            </Td>
          </>} />
      </Card>

      <Card pad={0} style={{ marginTop: 16 }}>
        <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Payment Manual Allocations</div>
        <Table cols={[{ label: 'Receipt' }, { label: 'Loan' }, { label: 'Customer' }, { label: 'Amount', align: 'right' }, { label: 'Requested Split' }, { label: 'Requested By' }, { label: 'Progress' }, { label: '', align: 'right', w: 180 }]}
          rows={data.allocationRequests} empty={loading ? 'Loading…' : 'No allocation requests pending.'}
          render={(r) => <>
            <Td mono style={{ fontWeight: 600 }}>{r.payment?.code || '—'}</Td>
            <Td mono>{r.payment?.facility?.code || '—'}</Td>
            <Td>{r.payment?.facility?.customer?.fullName || '—'}</Td>
            <Td mono align="right">{r.payment ? money(r.payment.amount) : '—'}</Td>
            <Td style={{ fontSize: 11.5 }}>
              {r.payment?.manualAllocation
                ? `Int ${money(r.payment.manualAllocation.interest)} · Cap ${money(r.payment.manualAllocation.capital)} · OD ${money(r.payment.manualAllocation.odCharge)} · Oth ${money(r.payment.manualAllocation.otherCharges)}`
                : '—'}
            </Td>
            <Td>{r.requester?.name || '—'}<div className="t-2" style={{ fontSize: 11 }}>{dateOnly(r.requestedAt)}</div></Td>
            <Td><Badge tone="amber" dot>{progressLabel(r.requiredApprovals, r.firstApprover)}</Badge></Td>
            <Td align="right">
              {canAct(r) ? (
                <div className="row gap-2" style={{ justifyContent: 'flex-end' }}>
                  <Btn size="sm" variant="primary" icon="check" onClick={() => approveAllocation(r.id, r.payment?.code || '')}>Approve</Btn>
                  <Btn size="sm" variant="danger" icon="x" onClick={() => rejectAllocation(r.id, r.payment?.code || '')}>Reject</Btn>
                </div>
              ) : <span className="t-2" style={{ fontSize: 11.5 }}>Awaiting another approver</span>}
            </Td>
          </>} />
      </Card>
    </div>
  )
}
