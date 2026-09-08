/* Daily Savings — Batch Interest Runs: preview, draft, post to the ledger, void. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, inputStyle, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  savingsInterestApi, branchesApi,
  type SavingsInterestRun, type SavingsInterestPreviewRow, type Branch, type AuthUser,
} from '../api'
import { Can, userCan } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const firstOfMonth = () => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10) }
const lastOfMonth = () => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().slice(0, 10) }

export function SavingsInterestScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<SavingsInterestRun[]>([])
  const [loading, setLoading] = useState(true)
  const [branches, setBranches] = useState<Branch[]>([])

  const [modal, setModal] = useState(false)
  const [form, setForm] = useState({ periodFrom: firstOfMonth(), periodTo: lastOfMonth(), branchId: undefined as number | undefined, notes: '' })
  const [preview, setPreview] = useState<{ rows: SavingsInterestPreviewRow[]; totalInterest: number | string; accountCount: number } | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const [detail, setDetail] = useState<Awaited<ReturnType<typeof savingsInterestApi.get>> | null>(null)

  const refresh = () => {
    setLoading(true)
    savingsInterestApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [])
  useEffect(() => { branchesApi.list().then(setBranches).catch(() => setBranches([])) }, [])

  const runPreview = async () => {
    setBusy(true); setErr(''); setPreview(null)
    try {
      setPreview(await savingsInterestApi.preview({ periodFrom: form.periodFrom, periodTo: form.periodTo, branchId: form.branchId }))
    } catch (ex: any) { setErr(getErrorText(ex, 'Preview failed')) } finally { setBusy(false) }
  }
  const createDraft = async () => {
    setBusy(true); setErr('')
    try {
      const run = await savingsInterestApi.create({ periodFrom: form.periodFrom, periodTo: form.periodTo, branchId: form.branchId, notes: form.notes || undefined })
      setModal(false); refresh(); notify.success('Draft run created', run.runNumber)
    } catch (ex: any) { setErr(getErrorText(ex, 'Failed')) } finally { setBusy(false) }
  }

  const post = async (r: SavingsInterestRun) => {
    const ok = await notify.confirm({ title: `Post interest run ${r.runNumber}?`, msg: 'Capitalises interest to every eligible account and posts Dr 5120 / Cr 2050. Needs an authorised approver.', okText: 'Post' })
    if (!ok) return
    try { await savingsInterestApi.post(r.id); refresh(); notify.success('Interest posted', r.runNumber) }
    catch (ex: any) { notify.error('Post failed', getErrorText(ex)) }
  }
  const voidRun = async (r: SavingsInterestRun) => {
    const ok = await notify.confirm({ title: `Void interest run ${r.runNumber}?`, danger: true, msg: 'Reverses the ledger entry and removes every interest posting it made.', okText: 'Void' })
    if (!ok) return
    try { await savingsInterestApi.void(r.id); refresh(); notify.success('Run voided', r.runNumber) }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }
  const removeDraft = async (r: SavingsInterestRun) => {
    const ok = await notify.confirm({ title: `Delete draft ${r.runNumber}?`, danger: true, okText: 'Delete' })
    if (!ok) return
    try { await savingsInterestApi.remove(r.id); refresh() } catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }

  const canEdit = userCan(me, 'Savings Interest', 'Edit')

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Savings Interest" icon="wallet" sub={`${rows.length} runs`}
        actions={<Can user={me} module="Savings Interest" action="Create"><Btn variant="primary" icon="plus" onClick={() => { setForm({ periodFrom: firstOfMonth(), periodTo: lastOfMonth(), branchId: undefined, notes: '' }); setPreview(null); setErr(''); setModal(true) }}>New Run</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Run No' }, { label: 'Period' }, { label: 'Branch' }, { label: 'Accounts', align: 'right' }, { label: 'Interest', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 150 }]}
          rows={rows}
          render={(r) => <>
            <Td mono><button className="mms-linkbtn" style={{ background: 'none', border: 'none', color: 'var(--ac-bright)', cursor: 'pointer', padding: 0 }} onClick={() => savingsInterestApi.get(r.id).then(setDetail)}>{r.runNumber}</button></Td>
            <Td>{dateOnly(r.periodFrom)} → {dateOnly(r.periodTo)}</Td>
            <Td>{r.branch?.name || 'All'}</Td>
            <Td align="right" mono>{r.accountCount || '—'}</Td>
            <Td align="right" mono>{Number(r.totalInterest) > 0 ? money(r.totalInterest) : '—'}</Td>
            <Td align="center"><Badge tone={r.status === 'Draft' ? 'amber' : r.status === 'Posted' ? 'green' : 'neutral'} dot>{r.status}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              {r.status === 'Draft' && canEdit && <button className="mms-act" title="Post" onClick={() => post(r)}><Icon n="check" s={15} /></button>}
              {r.status === 'Draft' && <Can user={me} module="Savings Interest" action="Create"><button className="mms-act" title="Delete draft" onClick={() => removeDraft(r)}><Icon n="trash" s={15} /></button></Can>}
              {r.status === 'Posted' && <Can user={me} module="Savings Interest" action="Delete"><button className="mms-act" title="Void" onClick={() => voidRun(r)}><Icon n="x" s={15} /></button></Can>}
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={() => setModal(false)} width={680} title="New Interest Run"
        footer={<>
          <Btn variant="plain" onClick={() => setModal(false)}>Cancel</Btn>
          <Btn variant="solid" icon="search" onClick={runPreview} disabled={busy}>Preview</Btn>
          <Btn variant="primary" icon="check" onClick={createDraft} disabled={busy}>{busy ? 'Working…' : 'Create Draft'}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14 }}>
          <Field label="Period From"><Input type="date" value={form.periodFrom} onChange={(e) => setForm((s) => ({ ...s, periodFrom: e.target.value }))} /></Field>
          <Field label="Period To"><Input type="date" value={form.periodTo} onChange={(e) => setForm((s) => ({ ...s, periodTo: e.target.value }))} /></Field>
          <Field label="Branch"><Select value={form.branchId ?? ''} onChange={(e) => setForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
            <option value="">All branches</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
        </div>
        <Field label="Notes" full><textarea value={form.notes} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical', marginTop: 10 }} /></Field>
        {preview && <div style={{ marginTop: 14 }}>
          <div className="row between" style={{ marginBottom: 8 }}>
            <div className="eyebrow">Preview — {preview.accountCount} accounts</div>
            <div style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(preview.totalInterest)}</div>
          </div>
          <Table cols={[{ label: 'Account' }, { label: 'Customer' }, { label: 'Basis Balance', align: 'right' }, { label: 'Interest', align: 'right' }]}
            rows={preview.rows} empty="No accounts have interest to post for this period"
            render={(row) => <>
              <Td mono>{row.accountNumber}</Td>
              <Td>{row.customer || '—'}</Td>
              <Td align="right" mono>{money(row.basisBalance)}</Td>
              <Td align="right" mono>{money(row.interest)}</Td>
            </>} />
        </div>}
      </Modal>

      <Modal open={!!detail} onClose={() => setDetail(null)} width={640} title={detail?.run.runNumber}>
        {detail && <>
          <div className="row gap-4 wrap t-2" style={{ fontSize: 12.5, marginBottom: 12 }}>
            <span>Period: <b>{dateOnly(detail.run.periodFrom)} → {dateOnly(detail.run.periodTo)}</b></span>
            <span>Status: <b>{detail.run.status}</b></span>
            <span>Total: <b>{money(detail.run.totalInterest || detail.totalInterest)}</b></span>
          </div>
          <Table cols={[{ label: 'Account' }, { label: 'Interest', align: 'right' }]}
            rows={(detail.preview || detail.postings || []) as any[]} empty="Nothing to show"
            render={(r: any) => <>
              <Td mono>{r.accountNumber || r.savingsAccount?.accountNumber || '—'}</Td>
              <Td align="right" mono>{money(r.interest ?? r.amount)}</Td>
            </>} />
        </>}
      </Modal>
    </div>
  )
}

export default SavingsInterestScreen
