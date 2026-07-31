/* Tazz Investment & Credit — Cheque Clearance. Cross-loan cheque realize/reject
   worklist, split out of Loan Adjustments into its own nav item since checking
   and clearing cheques is a routine daily task, not an occasional adjustment.
   Still admin-only on the backend (see routes/api.php) — kept off
   lib/permissions.ts's ROUTE_MODULE so canAccessRoute() falls back to its
   unmapped-route rule (admin role only), same as Loan Adjustments. */
import { useEffect, useMemo, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Select, Table, Td, DateInput, Modal } from '../components/ui'
import {
  loanPostDatedChequesApi, loanPaymentsApi,
  type AuthUser, type LoanPostDatedCheque, type ChequeStatus,
} from '../api'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: unknown) => Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const chequeTone: Record<ChequeStatus, 'green' | 'red' | 'amber' | 'neutral' | 'blue'> = {
  Pending: 'neutral', Handed: 'blue', Realized: 'green', Bounced: 'red', Returned: 'amber',
}
const CHEQUE_STATUSES: ChequeStatus[] = ['Pending', 'Handed', 'Realized', 'Bounced', 'Returned']
/** Cheques still awaiting a bank outcome — the only ones that can be realized or rejected. */
const isOpenCheque = (c: LoanPostDatedCheque) => c.status === 'Pending' || c.status === 'Handed'
/** A cheque can only be presented at the bank on or after its own date. */
const isDueCheque = (c: LoanPostDatedCheque, today: string) => (c.chequeDate?.slice(0, 10) || '') <= today

function ChequeSection({ notify }: { notify: ReturnType<typeof useNotify> }) {
  const [rows, setRows] = useState<LoanPostDatedCheque[]>([])
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState<ChequeStatus | ''>('')
  const [dueTodayOnly, setDueTodayOnly] = useState(false)
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [busyId, setBusyId] = useState<number | null>(null)
  // Realizing needs the actual bank check-in/clearing date, not just a click —
  // see act(). Bouncing doesn't touch the loan, so it skips this dialog.
  const [realizeTarget, setRealizeTarget] = useState<LoanPostDatedCheque | null>(null)
  const [realizeDate, setRealizeDate] = useState('')

  const refresh = () => {
    setLoading(true)
    loanPostDatedChequesApi.listAll({ status: status || undefined, dueToday: dueTodayOnly, fromDate, toDate })
      .then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [status, dueTodayOnly, fromDate, toDate]) // eslint-disable-line react-hooks/exhaustive-deps

  const today = todayIso()
  const dueTodayCount = useMemo(() => rows.filter((c) => isOpenCheque(c) && c.chequeDate?.slice(0, 10) === today).length, [rows, today])
  const overdueCount = useMemo(() => rows.filter((c) => isOpenCheque(c) && c.chequeDate?.slice(0, 10) < today).length, [rows, today])

  const act = async (c: LoanPostDatedCheque, next: 'Realized' | 'Bounced', realizedAt?: string) => {
    setBusyId(c.id)
    try {
      if (c.source === 'Payment') await loanPaymentsApi.clearCheque(c.id, next, realizedAt)
      else await loanPostDatedChequesApi.updateStatus(c.id, next, undefined, realizedAt)
      notify.success(next === 'Realized' ? 'Cheque realized' : 'Cheque rejected')
      refresh()
    } catch (ex: any) {
      notify.error('Update failed', getErrorText(ex))
    } finally { setBusyId(null) }
  }

  const openRealize = (c: LoanPostDatedCheque) => { setRealizeTarget(c); setRealizeDate(today) }
  const confirmRealize = async () => {
    if (!realizeTarget || !realizeDate) return
    const c = realizeTarget
    setRealizeTarget(null)
    await act(c, 'Realized', realizeDate)
  }

  return (
    <div>
      <div className="row between wrap gap-2" style={{ marginBottom: 12 }}>
        <div className="t-2" style={{ fontSize: 12.5 }}>{rows.length} cheques{dueTodayCount ? ` — ${dueTodayCount} due today` : ''}</div>
        <div className="row gap-2">
          {dueTodayCount > 0 && <Badge tone="amber" dot>{dueTodayCount} due today</Badge>}
          {overdueCount > 0 && <Badge tone="red" dot>{overdueCount} overdue</Badge>}
        </div>
      </div>

      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap" style={{ alignItems: 'flex-end' }}>
          <Field label="Status"><Select value={status} onChange={(e) => setStatus(e.target.value as ChequeStatus | '')} style={{ minWidth: 160 }}>
            <option value="">All statuses</option>
            {CHEQUE_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </Select></Field>
          <Field label="From"><DateInput value={fromDate} onChange={(iso) => { setFromDate(iso); if (iso) setDueTodayOnly(false) }} /></Field>
          <Field label="To"><DateInput value={toDate} onChange={(iso) => { setToDate(iso); if (iso) setDueTodayOnly(false) }} /></Field>
          <Btn variant={dueTodayOnly ? 'primary' : 'solid'} icon="calendar" onClick={() => { setDueTodayOnly((v) => !v); setFromDate(''); setToDate('') }}>Due Today Only</Btn>
          {(fromDate || toDate || dueTodayOnly || status) && (
            <Btn variant="ghost" icon="x" onClick={() => { setFromDate(''); setToDate(''); setDueTodayOnly(false); setStatus('') }}>Clear</Btn>
          )}
        </div>
      </Card>

      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Cheque No' }, { label: 'Loan' }, { label: 'Customer' }, { label: 'Branch' }, { label: 'Bank' }, { label: 'Cheque Date' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 190 }]}
          rows={rows} empty="No cheques found"
          render={(c) => {
            const d = c.chequeDate?.slice(0, 10)
            const dueBadge = isOpenCheque(c) && d === today ? <Badge tone="amber" dot>Today</Badge> : isOpenCheque(c) && d < today ? <Badge tone="red" dot>Overdue</Badge> : null
            const clearedOnOtherDate = c.status === 'Realized' && c.realizedAt && c.realizedAt.slice(0, 10) !== d
            return <>
              <Td mono>{c.chequeNo}</Td>
              <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{c.loan?.code || '—'}</Td>
              <Td>{c.loan?.customer?.fullName || '—'}</Td>
              <Td>{c.loan?.branch?.name || '—'}</Td>
              <Td>{c.bankName}</Td>
              <Td>
                <div className="row gap-2" style={{ alignItems: 'center' }}>{dateOnly(c.chequeDate)}{dueBadge}</div>
                {clearedOnOtherDate && <div className="t-2" style={{ fontSize: 10.5 }}>cleared {dateOnly(c.realizedAt)}</div>}
              </Td>
              <Td align="right" mono>{money(c.amount)}</Td>
              <Td align="center"><div className="row gap-2" style={{ justifyContent: 'center' }}><Badge tone={chequeTone[c.status]} dot>{c.status}</Badge>{c.source === 'Payment' && <span className="t-2" style={{ fontSize: 10.5 }}>(payment)</span>}</div></Td>
              <Td align="right">
                {isOpenCheque(c) && (isDueCheque(c, today) ? (
                  <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                    <Btn size="sm" variant="ok" icon="check" disabled={busyId === c.id} onClick={() => openRealize(c)}>Realize</Btn>
                    <Btn size="sm" variant="danger" icon="x" disabled={busyId === c.id} onClick={() => act(c, 'Bounced')}>Reject</Btn>
                  </div>
                ) : <span className="t-2" style={{ fontSize: 11.5 }}>Not due yet</span>)}
              </Td>
            </>
          }} />}
      </Card>

      <Modal open={!!realizeTarget} onClose={() => setRealizeTarget(null)} width={400}
        title={realizeTarget ? `Realize cheque ${realizeTarget.chequeNo}` : ''}
        footer={<>
          <Btn variant="plain" onClick={() => setRealizeTarget(null)}>Cancel</Btn>
          <Btn variant="primary" icon="check" disabled={!realizeDate || busyId === realizeTarget?.id} onClick={confirmRealize}>Realize</Btn>
        </>}>
        <div className="t-2" style={{ fontSize: 13, lineHeight: 1.5, marginBottom: 14 }}>
          Enter the date the cheque actually cleared at the bank (check-in date) — the amount is deducted
          from the loan as of this date, not today's date.
        </div>
        <Field label="Check-in / Realization Date">
          <DateInput value={realizeDate} onChange={setRealizeDate} />
        </Field>
      </Modal>
    </div>
  )
}

export function ChequeClearanceScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()

  // Belt-and-suspenders — the route itself is only reachable by admins (see
  // lib/permissions.ts canAccessRoute()), but a stale session shouldn't render this.
  if (me?.role !== 'admin') {
    return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>Admin privileges required.</div>
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Cheque Clearance" icon="cheque" sub="Realize or reject post-dated and payment cheques across all loans — admin only." />
      <ChequeSection notify={notify} />
    </div>
  )
}
