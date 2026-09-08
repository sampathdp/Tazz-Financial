/* Daily Savings — Field Collection Sheets: build a daily deposit list, then post it. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, SearchSelect, Modal, Table, Td, inputStyle, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  savingsCollectionApi, savingsAccountsApi, branchesApi, api,
  type SavingsCollectionSheet, type SavingsAccount, type Branch, type AuthUser,
} from '../api'
import { Can, userCan } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

interface DraftLine { savingsAccountId: number; label: string; amount: string; referenceNo: string }

export function SavingsCollectionScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<SavingsCollectionSheet[]>([])
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState<'all' | 'Open' | 'Posted' | 'Cancelled'>('Open')
  const [branches, setBranches] = useState<Branch[]>([])
  const [collectors, setCollectors] = useState<{ id: number; name: string }[]>([])
  const [accounts, setAccounts] = useState<SavingsAccount[]>([])

  const [editor, setEditor] = useState(false)
  const [sheetId, setSheetId] = useState<number | null>(null)
  const [head, setHead] = useState({ branchId: undefined as number | undefined, collectorId: undefined as number | undefined, collectionDate: todayIso(), notes: '' })
  const [lines, setLines] = useState<DraftLine[]>([])
  const [pick, setPick] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const [view, setView] = useState<SavingsCollectionSheet | null>(null)

  const refresh = () => {
    setLoading(true)
    savingsCollectionApi.list(tab === 'all' ? undefined : { status: tab }).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [tab]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    api.users.active().then(setCollectors).catch(() => setCollectors([]))
    savingsAccountsApi.list({ status: 'Active' }).then(setAccounts).catch(() => setAccounts([]))
  }, [])

  const startNew = () => {
    setSheetId(null); setHead({ branchId: branches[0]?.id, collectorId: collectors[0]?.id, collectionDate: todayIso(), notes: '' })
    setLines([]); setPick(''); setErr(''); setEditor(true)
  }
  const startEdit = async (s: SavingsCollectionSheet) => {
    const full = await savingsCollectionApi.get(s.id)
    setSheetId(full.id)
    setHead({ branchId: full.branchId, collectorId: full.collectorId, collectionDate: dateOnly(full.collectionDate), notes: full.notes || '' })
    setLines((full.lines || []).map((l) => ({ savingsAccountId: l.savingsAccountId, label: l.savingsAccount ? `${l.savingsAccount.accountNumber} — ${l.savingsAccount.customer?.fullName || ''}` : `#${l.savingsAccountId}`, amount: String(l.amount), referenceNo: l.referenceNo || '' })))
    setPick(''); setErr(''); setEditor(true)
  }

  const addLine = (acct?: SavingsAccount) => {
    if (!acct) return
    if (lines.some((l) => l.savingsAccountId === acct.id)) { notify.error('Already added', acct.accountNumber); return }
    setLines((s) => [...s, { savingsAccountId: acct.id, label: `${acct.accountNumber} — ${acct.customer?.fullName || ''}`, amount: '', referenceNo: '' }])
    setPick('')
  }
  const total = lines.reduce((a, l) => a + (Number(l.amount) || 0), 0)

  const save = async (thenPost: boolean) => {
    if (!head.branchId || !head.collectorId) { setErr('Branch and collector are required.'); return }
    if (!lines.length) { setErr('Add at least one account line.'); return }
    if (lines.some((l) => !l.amount || Number(l.amount) <= 0)) { setErr('Every line needs an amount greater than zero.'); return }
    setBusy(true); setErr('')
    try {
      const body = {
        branchId: head.branchId, collectorId: head.collectorId, collectionDate: head.collectionDate, notes: head.notes || undefined,
        lines: lines.map((l) => ({ savingsAccountId: l.savingsAccountId, amount: l.amount, referenceNo: l.referenceNo || undefined })),
      }
      const sheet = sheetId ? await savingsCollectionApi.update(sheetId, body) : await savingsCollectionApi.create(body)
      if (thenPost) { await savingsCollectionApi.post(sheet.id); notify.success('Sheet posted', sheet.sheetNumber) }
      else notify.success(sheetId ? 'Sheet saved' : 'Sheet created', sheet.sheetNumber)
      setEditor(false); refresh()
    } catch (ex: any) { setErr(getErrorText(ex, 'Save failed')) } finally { setBusy(false) }
  }

  const postSheet = async (s: SavingsCollectionSheet) => {
    const ok = await notify.confirm({ title: `Post sheet ${s.sheetNumber}?`, msg: 'Every line becomes a cash deposit on its account and the cash lands in the branch till. This cannot be undone.', okText: 'Post' })
    if (!ok) return
    try { await savingsCollectionApi.post(s.id); refresh(); notify.success('Sheet posted', s.sheetNumber) }
    catch (ex: any) { notify.error('Post failed', getErrorText(ex)) }
  }
  const cancelSheet = async (s: SavingsCollectionSheet) => {
    const ok = await notify.confirm({ title: `Cancel sheet ${s.sheetNumber}?`, danger: true, okText: 'Cancel Sheet' })
    if (!ok) return
    try { await savingsCollectionApi.cancel(s.id); refresh(); notify.success('Sheet cancelled', s.sheetNumber) }
    catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }

  const canEdit = userCan(me, 'Savings Collection', 'Edit')

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Savings Collection" icon="receipt" sub={`${rows.length} sheets`}
        actions={<Can user={me} module="Savings Collection" action="Create"><Btn variant="primary" icon="plus" onClick={startNew}>New Sheet</Btn></Can>} />
      <Card pad={0}>
        <div className="row gap-1 wrap" style={{ padding: '14px 16px 0' }}>
          {(['Open', 'Posted', 'Cancelled', 'all'] as const).map((id) => (
            <Btn key={id} size="sm" variant="ghost" active={tab === id} onClick={() => setTab(id)}>{id === 'all' ? 'All' : id}</Btn>
          ))}
        </div>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Sheet No' }, { label: 'Date' }, { label: 'Collector' }, { label: 'Lines', align: 'right' }, { label: 'Total', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 130 }]}
          rows={rows}
          render={(s) => <>
            <Td mono><button className="mms-linkbtn" style={{ background: 'none', border: 'none', color: 'var(--ac-bright)', cursor: 'pointer', padding: 0 }} onClick={() => savingsCollectionApi.get(s.id).then(setView)}>{s.sheetNumber}</button></Td>
            <Td>{dateOnly(s.collectionDate)}</Td>
            <Td>{s.collector?.name || '—'}</Td>
            <Td align="right" mono>{s.linesCount ?? s.lineCount}</Td>
            <Td align="right" mono>{money(s.totalAmount)}</Td>
            <Td align="center"><Badge tone={s.status === 'Open' ? 'amber' : s.status === 'Posted' ? 'green' : 'neutral'} dot>{s.status}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              {s.status === 'Open' && canEdit && <>
                <button className="mms-act" title="Edit" onClick={() => startEdit(s)}><Icon n="edit" s={15} /></button>
                <button className="mms-act" title="Post" onClick={() => postSheet(s)}><Icon n="check" s={15} /></button>
                <Can user={me} module="Savings Collection" action="Delete"><button className="mms-act" title="Cancel" onClick={() => cancelSheet(s)}><Icon n="x" s={15} /></button></Can>
              </>}
            </div></Td>
          </>} />}
      </Card>

      {/* editor */}
      <Modal open={editor} onClose={() => setEditor(false)} width={720} title={sheetId ? 'Edit Collection Sheet' : 'New Collection Sheet'}
        footer={<>
          <Btn variant="plain" onClick={() => setEditor(false)}>Cancel</Btn>
          <Btn variant="solid" icon="check" onClick={() => save(false)} disabled={busy}>Save Draft</Btn>
          <Btn variant="primary" icon="check" onClick={() => save(true)} disabled={busy}>{busy ? 'Working…' : 'Save & Post'}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14, marginBottom: 14 }}>
          <Field label="Branch"><Select value={head.branchId ?? ''} onChange={(e) => setHead((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Collector"><Select value={head.collectorId ?? ''} onChange={(e) => setHead((s) => ({ ...s, collectorId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>{collectors.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Select></Field>
          <Field label="Date"><DateInput value={head.collectionDate} onChange={(iso) => setHead((s) => ({ ...s, collectionDate: iso }))} /></Field>
        </div>
        <Field label="Add Account">
          <SearchSelect value={pick} onChange={(name, opt: SavingsAccount | undefined) => { if (opt) addLine(opt); else setPick(name) }}
            options={accounts} getLabel={(a: SavingsAccount) => `${a.accountNumber} — ${a.customer?.fullName || ''}`} getSub={(a: SavingsAccount) => `Balance ${money(a.currentBalance)}`} placeholder="Search account…" />
        </Field>
        <Table cols={[{ label: 'Account' }, { label: 'Amount', align: 'right' }, { label: 'Reference' }, { label: '', align: 'right', w: 50 }]}
          rows={lines} empty="No lines yet"
          render={(l, i) => <>
            <Td>{l.label}</Td>
            <Td align="right"><Input type="number" step="0.01" value={l.amount} onChange={(e) => setLines((s) => s.map((x, j) => j === i ? { ...x, amount: e.target.value } : x))} style={{ maxWidth: 120, textAlign: 'right' }} /></Td>
            <Td><Input value={l.referenceNo} onChange={(e) => setLines((s) => s.map((x, j) => j === i ? { ...x, referenceNo: e.target.value } : x))} style={{ maxWidth: 140 }} /></Td>
            <Td align="right"><button className="mms-act" onClick={() => setLines((s) => s.filter((_, j) => j !== i))}><Icon n="trash" s={14} /></button></Td>
          </>} />
        <div className="row between" style={{ marginTop: 12, fontWeight: 700 }}><span>Total</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(total)}</span></div>
        <Field label="Notes" full><textarea value={head.notes} onChange={(e) => setHead((s) => ({ ...s, notes: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical', marginTop: 10 }} /></Field>
      </Modal>

      {/* read-only view */}
      <Modal open={!!view} onClose={() => setView(null)} width={640} title={view?.sheetNumber}>
        {view && <>
          <div className="row gap-4 wrap t-2" style={{ fontSize: 12.5, marginBottom: 12 }}>
            <span>Date: <b>{dateOnly(view.collectionDate)}</b></span>
            <span>Collector: <b>{view.collector?.name || '—'}</b></span>
            <span>Status: <b>{view.status}</b></span>
          </div>
          <Table cols={[{ label: 'Account' }, { label: 'Customer' }, { label: 'Amount', align: 'right' }]}
            rows={view.lines || []} empty="No lines"
            render={(l) => <>
              <Td mono>{l.savingsAccount?.accountNumber || `#${l.savingsAccountId}`}</Td>
              <Td>{l.savingsAccount?.customer?.fullName || '—'}</Td>
              <Td align="right" mono>{money(l.amount)}</Td>
            </>} />
          <div className="row between" style={{ marginTop: 12, fontWeight: 700 }}><span>Total</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(view.totalAmount)}</span></div>
        </>}
      </Modal>
    </div>
  )
}

export default SavingsCollectionScreen
