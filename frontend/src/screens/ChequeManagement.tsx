/* Tazz Investment & Credit — Cheque Management: a cross-loan view of every
   post-dated cheque, so the counter/back office doesn't have to hunt through
   individual loans to find what's due for realization today. */
import { useEffect, useMemo, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Select, Table, Td } from '../components/ui'
import { Icon } from '../components/Icon'
import { loanPostDatedChequesApi, loanPaymentsApi, type AuthUser, type LoanPostDatedCheque, type ChequeStatus } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import type { Go } from './types'

const money = (v: unknown) => Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const todayIso = () => new Date().toISOString().slice(0, 10)
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')

const chequeTone: Record<ChequeStatus, 'green' | 'red' | 'amber' | 'neutral' | 'blue'> = {
  Pending: 'neutral', Handed: 'blue', Realized: 'green', Bounced: 'red', Returned: 'amber',
}
const STATUSES: ChequeStatus[] = ['Pending', 'Handed', 'Realized', 'Bounced', 'Returned']
/** Cheques still awaiting a bank outcome — the only ones that can be realized or rejected.
 *  A PostDated cheque can be Pending or Handed; a Cheque-method payment only ever has
 *  Pending as its open state (it has no separate "handed over" step). */
const isOpen = (c: LoanPostDatedCheque) => c.status === 'Pending' || c.status === 'Handed'
/** A cheque can only be presented at the bank on or after its own date — hide the
 *  Realize/Reject actions until then so no one clears a cheque ahead of its date. */
const isDue = (c: LoanPostDatedCheque, today: string) => (c.chequeDate?.slice(0, 10) || '') <= today

export function ChequeManagementScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<LoanPostDatedCheque[]>([])
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState<ChequeStatus | ''>('')
  const [dueTodayOnly, setDueTodayOnly] = useState(false)
  const [busyId, setBusyId] = useState<number | null>(null)

  const refresh = () => {
    setLoading(true)
    loanPostDatedChequesApi.listAll({ status: status || undefined, dueToday: dueTodayOnly })
      .then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [status, dueTodayOnly]) // eslint-disable-line react-hooks/exhaustive-deps

  const today = todayIso()
  const dueTodayCount = useMemo(() => rows.filter((c) => isOpen(c) && c.chequeDate?.slice(0, 10) === today).length, [rows, today])
  const overdueCount = useMemo(() => rows.filter((c) => isOpen(c) && c.chequeDate?.slice(0, 10) < today).length, [rows, today])

  const act = async (c: LoanPostDatedCheque, next: 'Realized' | 'Bounced') => {
    setBusyId(c.id)
    try {
      if (c.source === 'Payment') await loanPaymentsApi.clearCheque(c.id, next)
      else await loanPostDatedChequesApi.updateStatus(c.id, next)
      notify.success(next === 'Realized' ? 'Cheque realized' : 'Cheque rejected')
      refresh()
    } catch (ex: any) {
      notify.error('Update failed', ex?.response?.data?.message)
    } finally { setBusyId(null) }
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Cheque Management" icon="cheque" sub={`${rows.length} cheques${dueTodayCount ? ` — ${dueTodayCount} due today` : ''}`}
        actions={<>
          {dueTodayCount > 0 && <Badge tone="amber" dot>{dueTodayCount} due today</Badge>}
          {overdueCount > 0 && <Badge tone="red" dot>{overdueCount} overdue</Badge>}
        </>} />

      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap" style={{ alignItems: 'flex-end' }}>
          <Field label="Status"><Select value={status} onChange={(e) => setStatus(e.target.value as ChequeStatus | '')} style={{ minWidth: 160 }}>
            <option value="">All statuses</option>
            {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
          </Select></Field>
          <Btn variant={dueTodayOnly ? 'primary' : 'solid'} icon="calendar" onClick={() => setDueTodayOnly((v) => !v)}>Due Today Only</Btn>
        </div>
      </Card>

      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Cheque No' }, { label: 'Loan' }, { label: 'Customer' }, { label: 'Branch' }, { label: 'Bank' }, { label: 'Cheque Date' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 190 }]}
          rows={rows} empty="No cheques found"
          render={(c) => {
            const d = c.chequeDate?.slice(0, 10)
            const dueBadge = isOpen(c) && d === today ? <Badge tone="amber" dot>Today</Badge> : isOpen(c) && d < today ? <Badge tone="red" dot>Overdue</Badge> : null
            return <>
              <Td mono>{c.chequeNo}</Td>
              <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{c.loan?.code || '—'}</Td>
              <Td>{c.loan?.customer?.fullName || '—'}</Td>
              <Td>{c.loan?.branch?.name || '—'}</Td>
              <Td>{c.bankName}</Td>
              <Td><div className="row gap-2" style={{ alignItems: 'center' }}>{dateOnly(c.chequeDate)}{dueBadge}</div></Td>
              <Td align="right" mono>{money(c.amount)}</Td>
              <Td align="center"><div className="row gap-2" style={{ justifyContent: 'center' }}><Badge tone={chequeTone[c.status]} dot>{c.status}</Badge>{c.source === 'Payment' && <span className="t-2" style={{ fontSize: 10.5 }}>(payment)</span>}</div></Td>
              <Td align="right">
                <Can user={me} module="Loan Payments" action="Edit">
                  {isOpen(c) && (isDue(c, today) ? (
                    <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                      <Btn size="sm" variant="ok" icon="check" disabled={busyId === c.id} onClick={() => act(c, 'Realized')}>Realize</Btn>
                      <Btn size="sm" variant="danger" icon="x" disabled={busyId === c.id} onClick={() => act(c, 'Bounced')}>Reject</Btn>
                    </div>
                  ) : <span className="t-2" style={{ fontSize: 11.5 }}>Not due yet</span>)}
                </Can>
              </Td>
            </>
          }} />}
      </Card>
    </div>
  )
}
