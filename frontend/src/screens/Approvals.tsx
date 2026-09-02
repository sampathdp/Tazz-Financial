/* Tazz Investment & Credit — Approvals inbox: admin-only consolidated view of
   every request awaiting a second person's sign-off, flattened into one queue
   ordered oldest-first (FIFO — the order approvers should work through them).

   The backend (ApprovalController::index) still returns eight separate buckets;
   this screen merges them into a single list, tags each row with its `kind`,
   and dispatches Approve/Reject to the right endpoint per kind.

   Requester and approvers are always different people (enforced server-side —
   see each controller's approve* method), so canAct() hides the Approve/Reject
   buttons for anyone who submitted or already approved a given request, rather
   than letting the click round-trip just to bounce off the 422.

   The "Mine to approve" filter (default) shows only rows this user can act on;
   "All pending" shows everything, so a requester can still watch their own
   request's progress. Loan Applications / Speed Drafts have no approve action
   here (they're approved from their own detail screens) so they only appear
   under "All pending", with an Open link. */
import { useEffect, useMemo, useState } from 'react'
import { Card, PageHead, Btn, Badge, Table, Td } from '../components/ui'
import { approvalsApi, loansApi, loanPaymentsApi, expensesApi, type ApprovalsInbox, type AuthUser } from '../api'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const when = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 16).replace('T', ' ') : '—')

function progressLabel(requiredApprovals: number, firstApprover?: { name: string } | null) {
  const remaining = requiredApprovals - (firstApprover ? 1 : 0)
  return firstApprover
    ? `Approved by ${firstApprover.name} · needs ${remaining} more`
    : `Needs ${remaining} approval${remaining === 1 ? '' : 's'}`
}

const EMPTY_INBOX: ApprovalsInbox = { loanApplicationRequests: [], speedDraftApplicationRequests: [], loanCancelRequests: [], loanWriteOffRequests: [], paymentVoidRequests: [], allocationRequests: [], settlementRequests: [], expenseRequests: [] }

type Kind =
  | 'loan_application' | 'speed_draft' | 'loan_cancel' | 'loan_write_off'
  | 'payment_void' | 'payment_manual_allocation' | 'payment_settlement' | 'expense_approval'

type Tone = 'neutral' | 'blue' | 'green' | 'amber' | 'red'

/** The subset of the shared approval_requests row shape every bucket carries. */
type Req = {
  id: number
  requestedAt: string
  reason?: string | null
  requiredApprovals: number
  requester?: { id: number; name: string } | null
  firstApprover?: { id: number; name: string } | null
}

type Item = {
  kind: Kind
  key: string
  id: number
  /** The id the approve/reject endpoint for this kind is bound to — usually the
   *  approval_requests row (`id`), but the settlement and expense endpoints are
   *  route-bound to the payment / expense itself. */
  actionId: number
  typeLabel: string
  tone: Tone
  requestedAt: string
  requester?: { id: number; name: string } | null
  firstApprover?: { id: number; name: string } | null
  requiredApprovals: number
  ref: string
  who: string
  amount: number | null
  reason: string | null
  open?: () => void
}

/** Per-kind approve/reject endpoints. Empty = no action on this screen. */
type Handler = { approve?: (id: number) => Promise<unknown>; reject?: (id: number, reason?: string) => Promise<unknown> }

export function ApprovalsScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [data, setData] = useState<ApprovalsInbox>(EMPTY_INBOX)
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState<'mine' | 'all'>('mine')

  const refresh = () => {
    setLoading(true)
    approvalsApi.list().then(setData).catch(() => setData(EMPTY_INBOX)).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [])

  const HANDLERS: Record<Kind, Handler> = {
    loan_application: {},
    speed_draft: {},
    loan_cancel: { approve: loansApi.approveCancelRequest, reject: loansApi.rejectCancelRequest },
    loan_write_off: { approve: loansApi.approveWriteOffRequest, reject: loansApi.rejectWriteOffRequest },
    payment_void: { approve: loanPaymentsApi.approveVoidRequest, reject: loanPaymentsApi.rejectVoidRequest },
    payment_manual_allocation: { approve: loanPaymentsApi.approveAllocationRequest, reject: loanPaymentsApi.rejectAllocationRequest },
    payment_settlement: { approve: loanPaymentsApi.approveSettlement, reject: loanPaymentsApi.rejectSettlement },
    expense_approval: { approve: expensesApi.approve, reject: expensesApi.reject },
  }

  const canAct = (it: { requester?: { id: number } | null; firstApprover?: { id: number } | null }) =>
    me?.id !== it.requester?.id && me?.id !== it.firstApprover?.id

  const mk = (
    kind: Kind, tone: Tone, typeLabel: string, r: Req,
    ref: string, who: string, amount: number | null,
    opts: { open?: () => void; actionId?: number } = {},
  ): Item => ({
    kind, tone, typeLabel, key: `${kind}-${r.id}`, id: r.id, actionId: opts.actionId ?? r.id,
    requestedAt: r.requestedAt, requester: r.requester, firstApprover: r.firstApprover,
    requiredApprovals: r.requiredApprovals, ref, who, amount, reason: r.reason ?? null, open: opts.open,
  })

  const items = useMemo<Item[]>(() => [
    ...data.loanApplicationRequests.map((r) => mk('loan_application', 'blue', 'Loan Application', r,
      r.loan?.code || '—', r.loan?.customer?.fullName || '—', r.loan ? num(r.loan.principalAmount) : null,
      { open: r.loan ? () => go(`loan/apps/${r.loan!.id}`) : undefined })),
    ...data.speedDraftApplicationRequests.map((r) => mk('speed_draft', 'blue', 'Speed Draft', r,
      r.speedDraft?.code || '—', r.speedDraft?.customer?.fullName || '—', r.speedDraft ? num(r.speedDraft.principalAmount) : null,
      { open: r.speedDraft ? () => go(`sd/drafts/${r.speedDraft!.id}`) : undefined })),
    ...data.loanCancelRequests.map((r) => mk('loan_cancel', 'amber', 'Loan Cancellation', r,
      r.loan?.code || '—', r.loan?.customer?.fullName || '—', r.loan ? num(r.loan.principalAmount) : null)),
    ...data.loanWriteOffRequests.map((r) => mk('loan_write_off', 'red', 'Loan Write-off', r,
      r.loan?.code || '—', r.loan?.customer?.fullName || '—',
      r.loan ? num(r.loan.outstandingBalanceCache ?? r.loan.principalAmount) : null)),
    ...data.paymentVoidRequests.map((r) => mk('payment_void', 'amber', 'Payment Cancellation', r,
      r.payment?.code || '—', r.payment?.facility?.customer?.fullName || '—', r.payment ? num(r.payment.amount) : null)),
    ...data.allocationRequests.map((r) => mk('payment_manual_allocation', 'blue', 'Manual Allocation', r,
      r.payment?.code || '—', r.payment?.facility?.customer?.fullName || '—', r.payment ? num(r.payment.amount) : null)),
    // approve/reject-settlement is route-bound to the payment, not the approval request.
    ...data.settlementRequests.map((r) => mk('payment_settlement', 'green', 'Payment Settlement', r,
      r.payment?.code || '—', r.payment?.facility?.customer?.fullName || '—', r.payment ? num(r.payment.amount) : null,
      { actionId: r.payment?.id })),
    // approve/reject expense is route-bound to the expense, not the approval request.
    ...data.expenseRequests.map((r) => mk('expense_approval', 'amber', 'Expense', r,
      r.expense?.code || '—', r.expense?.branch?.name || '—', r.expense ? num(r.expense.amount) : null,
      { actionId: r.expense?.id })),
  ].sort((a, b) => (a.requestedAt || '').localeCompare(b.requestedAt || '')), [data, go])

  const shown = filter === 'all'
    ? items
    : items.filter((it) => HANDLERS[it.kind].approve && canAct(it))

  const doApprove = async (it: Item) => {
    const h = HANDLERS[it.kind]
    if (!h.approve) return
    try { await h.approve(it.actionId); notify.success('Approved', it.ref); refresh() }
    catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
  }
  const doReject = async (it: Item) => {
    const h = HANDLERS[it.kind]
    if (!h.reject) return
    const reason = await notify.prompt({
      title: `Reject ${it.typeLabel.toLowerCase()} for ${it.ref}?`,
      msg: 'Give a reason — the requester can resubmit afterwards.',
      placeholder: 'Reason for rejecting', danger: true, okText: 'Reject',
    })
    if (reason === null) return
    try { await h.reject(it.actionId, reason || undefined); notify.success('Rejected', it.ref); refresh() }
    catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }

  const actionCell = (it: Item) => {
    if (HANDLERS[it.kind].approve) {
      return canAct(it) ? (
        <div className="row gap-2" style={{ justifyContent: 'flex-end' }}>
          <Btn size="sm" variant="primary" icon="check" onClick={() => doApprove(it)}>Approve</Btn>
          <Btn size="sm" variant="danger" icon="x" onClick={() => doReject(it)}>Reject</Btn>
        </div>
      ) : <span className="t-2" style={{ fontSize: 11.5 }}>Awaiting another approver</span>
    }
    return it.open ? <Btn size="sm" icon="eye" onClick={it.open}>Open</Btn> : null
  }

  return (
    <div>
      <PageHead
        crumbs="Administration" title="Approvals" icon="shield"
        sub={loading ? 'Loading…' : `${shown.length} of ${items.length} request${items.length === 1 ? '' : 's'} pending`}
        actions={
          <div className="row gap-2">
            <Btn size="sm" active={filter === 'mine'} onClick={() => setFilter('mine')}>Mine to approve</Btn>
            <Btn size="sm" active={filter === 'all'} onClick={() => setFilter('all')}>All pending</Btn>
          </div>
        }
      />

      <Card pad={0}>
        <Table
          cols={[
            { label: 'Type' }, { label: 'Reference' }, { label: 'Customer / Branch' },
            { label: 'Amount', align: 'right' }, { label: 'Requested By' },
            { label: 'Progress' }, { label: '', align: 'right', w: 200 },
          ]}
          rows={shown}
          empty={loading ? 'Loading…' : filter === 'mine' ? 'Nothing awaiting your approval.' : 'No requests pending.'}
          render={(it) => <>
            <Td><Badge tone={it.tone} dot>{it.typeLabel}</Badge></Td>
            <Td mono style={{ fontWeight: 600 }}>
              {it.open
                ? <a onClick={it.open} style={{ cursor: 'pointer', color: 'var(--ac)' }}>{it.ref}</a>
                : it.ref}
            </Td>
            <Td>
              {it.who}
              {it.reason ? <div className="t-2" style={{ fontSize: 11, whiteSpace: 'normal', maxWidth: 320 }}>{it.reason}</div> : null}
            </Td>
            <Td mono align="right">{it.amount == null ? '—' : money(it.amount)}</Td>
            <Td>{it.requester?.name || '—'}<div className="t-2" style={{ fontSize: 11 }}>{when(it.requestedAt)}</div></Td>
            <Td><Badge tone="amber" dot>{progressLabel(it.requiredApprovals, it.firstApprover)}</Badge></Td>
            <Td align="right">{actionCell(it)}</Td>
          </>}
        />
      </Card>
    </div>
  )
}
