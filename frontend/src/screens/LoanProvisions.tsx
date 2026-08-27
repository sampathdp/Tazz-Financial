/* Tazz Investment & Credit — Loan Provisioning (Impairment). Worklist of
   Active loans currently NPL (>=3 installments overdue) or with a
   required-vs-posted provision mismatch — the slab classification itself
   (npl_flag/provision_required_*) is always live, refreshed daily and after
   every payment (see backend LoanProvisioning), but the actual ledger entry
   only books when a user posts it here — same "classify automatically, book
   manually" split as a Settlement waiting for approval elsewhere in this
   app. Building up (Dr 5300/Cr 1190) and recovering (a payment dropped the
   requirement — Dr 1190/Cr 4050 Bad Debt Recovery Income, not a plain
   reversal into 5300, since it's cash collected against an already-expensed
   balance) are both "Post" from the cashier's point of view; the confirm
   dialog explains which one a given row will do. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Table, Td, Modal, LoadingBlock } from '../components/ui'
import { loanProvisionsApi, type AuthUser, type LoanProvisionRow } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const slabTone = (pct: number) => (pct >= 100 ? 'red' : pct >= 50 ? 'amber' : 'neutral') as 'red' | 'amber' | 'neutral'
const slabLabel = (row: LoanProvisionRow) => (row.provisionRequiredPct > 0 ? `${row.provisionRequiredPct}%` : row.nplFlag ? 'NPL' : '—')

export function LoanProvisionsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<LoanProvisionRow[]>([])
  const [loading, setLoading] = useState(true)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [confirmTarget, setConfirmTarget] = useState<LoanProvisionRow | null>(null)

  const refresh = () => {
    setLoading(true)
    loanProvisionsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [])

  const post = async (row: LoanProvisionRow) => {
    setConfirmTarget(null)
    setBusyId(row.id)
    try {
      const wasRecovery = row.provisionDelta < 0
      const result = await loanProvisionsApi.post(row.id)
      notify.success(result.posted ? (wasRecovery ? 'Bad debt recovery booked' : 'Provision posted') : 'Already in sync', `${row.code} — ${money(result.loan.provisionPostedAmount)} now posted`)
      refresh()
    } catch (ex: any) {
      notify.error('Post failed', getErrorText(ex))
    } finally { setBusyId(null) }
  }

  const totalRequired = rows.reduce((a, r) => a + r.provisionRequiredAmount, 0)
  const totalPosted = rows.reduce((a, r) => a + r.provisionPostedAmount, 0)

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Loan Provisioning" icon="shield"
        sub={`${rows.length} loan(s) NPL or needing a provision update — ${money(totalRequired)} required, ${money(totalPosted)} posted`} />

      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[
          { label: 'Loan' }, { label: 'Customer' }, { label: 'Product' },
          { label: 'Overdue Installments', align: 'center' }, { label: 'Slab', align: 'center' },
          { label: 'Required', align: 'right' }, { label: 'Posted', align: 'right' }, { label: 'Delta', align: 'right' },
          { label: '', align: 'right', w: 140 },
        ]}
          rows={rows} empty="No loan is NPL or awaiting a provision update right now."
          render={(r) => {
            const inSync = Math.abs(r.provisionDelta) <= 0.01
            return <>
              <Td c="var(--tx-0)" mono style={{ fontWeight: 600 }}>{r.code}</Td>
              <Td>
                <div style={{ fontWeight: 600 }}>{r.customer?.fullName}</div>
                <div className="t-2" style={{ fontSize: 11.5 }}>{r.customer?.code}</div>
              </Td>
              <Td>{r.loanType?.name || '—'}</Td>
              <Td align="center">{r.nplFlag ? <Badge tone="amber" dot>NPL</Badge> : <span className="t-2">—</span>}</Td>
              <Td align="center"><Badge tone={slabTone(r.provisionRequiredPct)} dot>{slabLabel(r)}</Badge></Td>
              <Td mono align="right">{money(r.provisionRequiredAmount)}</Td>
              <Td mono align="right">{money(r.provisionPostedAmount)}</Td>
              <Td mono align="right" c={inSync ? 'var(--tx-2)' : r.provisionDelta > 0 ? 'var(--bad)' : 'var(--ok)'} style={{ fontWeight: 600 }}>
                {inSync ? '—' : `${r.provisionDelta > 0 ? '+' : ''}${money(r.provisionDelta)}`}
              </Td>
              <Td align="right">
                <Can user={me} module="Loan Provisioning" action="Edit">
                  {!inSync && (
                    <Btn size="sm" variant={r.provisionDelta > 0 ? 'primary' : 'solid'} icon="check" disabled={busyId === r.id} onClick={() => setConfirmTarget(r)}>
                      {r.provisionDelta > 0 ? 'Post' : 'Recover'}
                    </Btn>
                  )}
                </Can>
              </Td>
            </>
          }} />}
      </Card>

      <Modal open={!!confirmTarget} onClose={() => setConfirmTarget(null)} width={420}
        title={confirmTarget ? `${confirmTarget.provisionDelta && confirmTarget.provisionDelta > 0 ? 'Post provision' : 'Bad debt recovery'} — ${confirmTarget.code}` : ''}
        footer={<>
          <Btn variant="plain" onClick={() => setConfirmTarget(null)}>Cancel</Btn>
          <Btn variant="primary" icon="check" disabled={!confirmTarget || busyId === confirmTarget.id} onClick={() => confirmTarget && post(confirmTarget)}>Confirm</Btn>
        </>}>
        {confirmTarget && (
          <div className="col gap-2" style={{ fontSize: 13 }}>
            <div className="t-2" style={{ lineHeight: 1.5 }}>
              {confirmTarget.provisionDelta > 0
                ? <>Books <b style={{ fontFamily: 'JetBrains Mono' }}>{money(confirmTarget.provisionDelta)}</b> as impairment expense — Dr Provision for Loan Losses (Expense) / Cr Provision for Loan Losses (contra-asset).</>
                : <>A payment dropped this loan's overdue installments back below its slab since it was last posted — books <b style={{ fontFamily: 'JetBrains Mono' }}>{money(Math.abs(confirmTarget.provisionDelta))}</b> as Bad Debt Recovery Income (Dr contra-asset / Cr income), not a smaller expense.</>}
            </div>
            <div className="row between" style={{ paddingTop: 8, borderTop: '1px solid var(--line)' }}>
              <span className="t-2">Currently Posted</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(confirmTarget.provisionPostedAmount)}</span>
            </div>
            <div className="row between">
              <span className="t-2">Required</span><span style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(confirmTarget.provisionRequiredAmount)}</span>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
