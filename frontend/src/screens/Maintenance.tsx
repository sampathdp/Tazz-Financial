/* Tazz Investment & Credit — Data Maintenance (admin-only)

   One action: wipe every transactional record (loans, facilities, payments,
   ledger, deposits, pawning, expenses, petty cash, approvals, number
   sequences) and the files uploaded against them, while keeping all
   master/config data and the customer register. Irreversible. */
import { useState } from 'react'
import { isAdminRole } from '../lib/permissions'
import { Card, PageHead, Btn, Field, Input, ErrorBanner } from '../components/ui'
import { Icon } from '../components/Icon'
import { systemApi, CLEAN_CONFIRM_PHRASE, type CleanResult } from '../api'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'
import type { AuthUser } from '../api'

const KEPT = [
  'Customers (incl. profile photos & documents)',
  'System users, roles & permissions',
  'Branches & company profile',
  'Loan / Speed Draft / Pawning types & article types',
  'Funding products, vendors, banks',
  'Expense / document / charge categories',
  'Chart of accounts, approval types, pawning gold rates',
]

const WIPED = [
  'All loans, speed drafts, pawning tickets & their schedules / collateral / documents',
  'All loan & speed-draft payments, waivers, cheques, diary notes',
  'Fixed deposits, deposits & interest payments',
  'Journal entries & lines, bank transactions & reconciliations',
  'Cashbook entries, cash transfers & reconciliations',
  'Expenses, payment vouchers, petty cash funds / vouchers / replenishments',
  'Pending & past approval requests',
  'Subscriptions / document-expiry reminders',
  'Generated document numbers — numbering restarts from 1',
  'Uploaded loan & speed-draft files on disk',
  'Bank & cash account balances reset to their opening balance',
]

export function MaintenanceScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const isAdmin = isAdminRole(me?.role)
  const [phrase, setPhrase] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [result, setResult] = useState<CleanResult | null>(null)

  const armed = phrase === CLEAN_CONFIRM_PHRASE && password.length > 0

  const run = async () => {
    if (!armed) return
    const ok = await notify.confirm({
      title: 'Wipe all transaction data?',
      msg: 'Every loan, payment, ledger entry and uploaded document will be permanently deleted. Master data and customers are kept. This cannot be undone.',
      danger: true,
      okText: 'Wipe everything',
    })
    if (!ok) return
    setBusy(true); setErr(''); setResult(null)
    try {
      const res = await systemApi.cleanTransactions(password, phrase)
      setResult(res)
      setPhrase(''); setPassword('')
      notify.success('Transaction data cleared', `${res.rowsDeleted.toLocaleString()} rows, ${res.filesDeleted} files removed`)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Clean failed'))
    } finally { setBusy(false) }
  }

  if (!isAdmin) {
    return (
      <div>
        <PageHead crumbs="Administration" title="Data Maintenance" icon="trash" />
        <Card><div style={{ padding: 24, color: 'var(--tx-2)', fontSize: 13 }}>Administrators only.</div></Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Administration" title="Data Maintenance" icon="trash" sub="Reset the system to a clean state before go-live" />

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, alignItems: 'start' }}>
        <Card>
          <div className="eyebrow" style={{ marginBottom: 12, color: 'var(--ok)' }}>Kept</div>
          <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12.5, lineHeight: 1.7, color: 'var(--tx-1)' }}>
            {KEPT.map((k) => <li key={k}>{k}</li>)}
          </ul>
        </Card>
        <Card>
          <div className="eyebrow" style={{ marginBottom: 12, color: 'var(--bad)' }}>Permanently deleted</div>
          <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12.5, lineHeight: 1.7, color: 'var(--tx-1)' }}>
            {WIPED.map((w) => <li key={w}>{w}</li>)}
          </ul>
        </Card>
      </div>

      <Card style={{ marginTop: 16, border: '1px solid var(--bad)' }}>
        <div className="row gap-2" style={{ alignItems: 'center', marginBottom: 10 }}>
          <Icon n="alert" s={18} c="var(--bad)" />
          <div style={{ fontWeight: 700, fontSize: 14, color: 'var(--bad)' }}>Danger zone — this cannot be undone</div>
        </div>
        <div className="t-2" style={{ fontSize: 12.5, marginBottom: 16 }}>
          Take a database backup first. On a production server this action is refused unless <code>DB_CLEAN_ENABLED=true</code> is set.
        </div>

        <ErrorBanner error={err} />

        {result && (
          <div style={{ padding: '10px 14px', marginBottom: 14, borderRadius: 'var(--r-s)', fontSize: 13, background: 'var(--ok-dim)', color: 'var(--ok)', border: '1px solid var(--ok)' }}>
            Done — {result.rowsDeleted.toLocaleString()} rows across {result.tablesWiped} tables and {result.filesDeleted} files removed.
          </div>
        )}

        <div style={{ display: 'grid', gap: 14, maxWidth: 460 }}>
          <Field label={<>Type <b>{CLEAN_CONFIRM_PHRASE}</b> to confirm</>}>
            <Input value={phrase} onChange={(e) => setPhrase(e.target.value)} placeholder={CLEAN_CONFIRM_PHRASE} autoComplete="off" />
          </Field>
          <Field label="Your account password">
            <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" />
          </Field>
          <div>
            <Btn variant="danger" icon="trash" onClick={run} disabled={!armed || busy}>
              {busy ? 'Wiping…' : 'Wipe transaction data'}
            </Btn>
          </div>
        </div>
      </Card>
    </div>
  )
}
