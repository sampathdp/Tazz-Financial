/* Tazz Investment & Credit — Loan Types (self-referencing tree: category or product) */
import { useEffect, useMemo, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal } from '../components/ui'
import { Icon } from '../components/Icon'
import { loanTypesApi, type LoanType, type LoanFrequency, type InterestMethod, type AuthUser } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { DEFAULT_REFERENCE_FORMAT, previewReferenceNumber } from '../lib/referenceNumber'
import type { Go } from './types'

const FREQ_OPTIONS: { value: LoanFrequency; label: string }[] = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
]
const INTEREST_METHOD_OPTIONS: { value: InterestMethod; label: string }[] = [
  { value: 'flat', label: 'Flat' },
  { value: 'reducing_balance', label: 'Reducing Balance' },
]

const blankForm = (parentId: number | null): Partial<LoanType> => ({
  parentId: parentId ?? undefined,
  isProduct: true,
  ratePeriod: 'monthly',
  penaltyMethod: 'flat',
  collectionDays: 'all_days',
  status: 'Active',
  referenceFormat: DEFAULT_REFERENCE_FORMAT,
})

interface Node extends LoanType { children: Node[] }

// Keyed by String(id) rather than the raw id — some hosts serialize integer
// columns as JSON strings (PDO emulated-prepares quirk) while others return
// real numbers, so comparing id === parentId directly can silently fail on
// one environment and not another. Normalizing both sides to string sidesteps it.
function buildTree(rows: LoanType[]): Node[] {
  const byId = new Map<string, Node>(rows.map((r) => [String(r.id), { ...r, children: [] }]))
  const roots: Node[] = []
  for (const n of byId.values()) {
    const parentKey = n.parentId != null ? String(n.parentId) : null
    if (parentKey && byId.has(parentKey)) byId.get(parentKey)!.children.push(n)
    else roots.push(n)
  }
  return roots
}

function nameChain(rows: LoanType[], id: number | null | undefined): string {
  if (!id) return ''
  const byId = new Map(rows.map((r) => [String(r.id), r]))
  const chain: string[] = []
  let cur = byId.get(String(id))
  while (cur) { chain.unshift(cur.name); cur = cur.parentId ? byId.get(String(cur.parentId)) : undefined }
  return chain.join(' › ')
}

function Row({ node, depth, me, onAddChild, onEdit, onDelete }: {
  node: Node; depth: number; me?: AuthUser | null
  onAddChild: (parentId: number) => void
  onEdit: (n: LoanType) => void
  onDelete: (n: LoanType) => void
}) {
  const [open, setOpen] = useState(true)
  const hasChildren = node.children.length > 0
  return (
    <>
      <div className="row between" style={{ padding: '10px 16px', paddingLeft: 16 + depth * 26, borderBottom: '1px solid var(--line-soft)', alignItems: 'center' }}>
        <div className="row gap-2" style={{ alignItems: 'center', minWidth: 0 }}>
          {hasChildren
            ? <button onClick={() => setOpen((o) => !o)} className="mms-act" style={{ padding: 2 }}><Icon n={open ? 'chevd' : 'chev'} s={14} /></button>
            : <span style={{ width: 18, display: 'inline-block' }} />}
          <Icon n={node.isProduct ? 'coins' : 'layers'} s={15} c="var(--tx-2)" />
          <span style={{ fontWeight: 600, fontSize: 13.5 }}>{node.name}</span>
          <span className="t-2" style={{ fontSize: 11 }}>{node.code}</span>
          <Badge tone={node.isProduct ? 'blue' : 'neutral'}>{node.isProduct ? 'Product' : 'Category'}</Badge>
          {node.frequency && <Badge tone="neutral">{FREQ_OPTIONS.find((f) => f.value === node.frequency)?.label}</Badge>}
          {node.frequency === 'daily' && node.collectionDays === 'week_days' && <Badge tone="amber">Week Days Only</Badge>}
          {node.status === 'Inactive' && <Badge tone="red" dot>Inactive</Badge>}
        </div>
        <div className="row gap-3" style={{ alignItems: 'center', flexShrink: 0 }}>
          {node.isProduct && (
            <span className="t-2" style={{ fontSize: 12 }}>
              {node.interestRatePercent ?? '—'}% {node.ratePeriod === 'annual' ? 'p.a.' : 'p.m.'} · {Number(node.minAmount ?? 0).toLocaleString()}–{Number(node.maxAmount ?? 0).toLocaleString()} · {node.minTermMonths}–{node.maxTermMonths}mo
            </span>
          )}
          <div className="row gap-1">
            <Can user={me} module="Loan Types" action="Create">
              <button className="mms-act" title="Add sub-item" onClick={() => onAddChild(node.id)}><Icon n="plus" s={14} /></button>
            </Can>
            <Can user={me} module="Loan Types" action="Edit">
              <button className="mms-act" title="Edit" onClick={() => onEdit(node)}><Icon n="edit" s={14} /></button>
            </Can>
            <Can user={me} module="Loan Types" action="Delete">
              <button className="mms-act" title="Delete" onClick={() => onDelete(node)}><Icon n="trash" s={14} /></button>
            </Can>
          </div>
        </div>
      </div>
      {open && node.children.map((c) => (
        <Row key={c.id} node={c} depth={depth + 1} me={me} onAddChild={onAddChild} onEdit={onEdit} onDelete={onDelete} />
      ))}
    </>
  )
}

export function LoanTypesScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<LoanType[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<LoanType | null>(null)
  const [form, setForm] = useState<Partial<LoanType>>(blankForm(null))
  const set = <K extends keyof LoanType>(k: K, v: LoanType[K]) => setForm((s) => ({ ...s, [k]: v }))

  const refresh = () => {
    setLoading(true)
    loanTypesApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])

  const tree = useMemo(() => buildTree(rows), [rows])

  const openCreate = (parentId: number | null) => { setEditing(null); setForm(blankForm(parentId)); setErr(''); setModal(true) }
  const openEdit = (n: LoanType) => { setEditing(n); setForm({ ...n }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name) { setErr('Name is required.'); return }
    if (!form.code) { setErr('Code is required.'); return }
    if (form.isProduct) {
      if (!form.interestMethod) { setErr('Interest method is required for a loan product.'); return }
      if (form.interestRatePercent == null || form.interestRatePercent === '') { setErr('Interest rate is required for a loan product.'); return }
      if (form.minAmount == null || form.maxAmount == null || form.minAmount === '' || form.maxAmount === '') { setErr('Min/Max amount is required for a loan product.'); return }
      if (!form.minTermMonths || !form.maxTermMonths) { setErr('Min/Max term (months) is required for a loan product.'); return }
      if (form.referenceFormat && !form.referenceFormat.includes('{SEQ')) { setErr('Reference number format must include a sequence placeholder, e.g. {SEQ:6}.'); return }
    }
    setBusy(true); setErr('')
    try {
      if (editing) await loanTypesApi.update(editing.id, form)
      else await loanTypesApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Loan type updated' : 'Loan type created', form.name)
    } catch (ex: any) {
      setErr(ex?.response?.data?.message || 'Save failed')
    } finally { setBusy(false) }
  }

  const remove = async (n: LoanType) => {
    const ok = await notify.confirm({ title: `Delete "${n.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await loanTypesApi.remove(n.id); refresh(); notify.success('Loan type deleted', n.name) }
    catch (ex: any) { notify.error('Delete failed', ex?.response?.data?.message) }
  }

  const parentLabel = form.parentId ? nameChain(rows, form.parentId) : null

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Loan Types" icon="layers"
        sub="Organize loan products into categories — e.g. SME → Daily / Micro → Weekly / Monthly."
        actions={<Can user={me} module="Loan Types" action="Create"><Btn variant="primary" icon="plus" onClick={() => openCreate(null)}>Add Loan Type</Btn></Can>} />
      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
         tree.length === 0 ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-3)' }}>No loan types yet</div> :
         tree.map((n) => <Row key={n.id} node={n} depth={0} me={me} onAddChild={openCreate} onEdit={openEdit} onDelete={remove} />)}
      </Card>

      <Modal open={modal} onClose={close} width={640}
        title={editing ? 'Edit Loan Type' : (parentLabel ? 'Add Sub-Item' : 'Add Loan Type')}
        sub={editing ? (nameChain(rows, editing.parentId) || 'Top-level') : (parentLabel ? `Under: ${parentLabel}` : 'Top-level loan type')}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        {err && <div style={{ background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', padding: '8px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5, marginBottom: 14 }}>{err}</div>}

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>
          <Field label="Name"><Input value={form.name || ''} onChange={(e) => set('name', e.target.value)} /></Field>
          <Field label="Code"><Input value={form.code || ''} onChange={(e) => set('code', e.target.value.toUpperCase())} placeholder="e.g. SME-DAILY" /></Field>
        </div>

        <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginBottom: 18 }}>
          <input type="checkbox" checked={!!form.isProduct} onChange={(e) => set('isProduct', e.target.checked)} />
          <span style={{ fontSize: 13 }}>This is a loan product <span className="t-2">— customers can be issued a loan directly under it. Leave unchecked if it's just a grouping for sub-items (e.g. "SME").</span></span>
        </label>

        {form.isProduct && (
          <div style={{ padding: 14, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)', marginBottom: 16 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <Field label="Repayment Frequency"><Select value={form.frequency || ''} onChange={(e) => set('frequency', (e.target.value || null) as any)}>
                <option value="">—</option>{FREQ_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </Select></Field>
              {form.frequency === 'daily' && (
                <Field label="Collection Days">
                  <Select value={form.collectionDays || 'all_days'} onChange={(e) => set('collectionDays', e.target.value as any)}>
                    <option value="all_days">All Days</option>
                    <option value="week_days">Week Days Only (Mon–Fri)</option>
                  </Select>
                </Field>
              )}
              <Field label="Interest Method"><Select value={form.interestMethod || ''} onChange={(e) => set('interestMethod', e.target.value as any)}>
                <option value="">—</option>{INTEREST_METHOD_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </Select></Field>
              <Field label="Interest Rate %"><Input type="number" step="0.001" value={form.interestRatePercent ?? ''} onChange={(e) => set('interestRatePercent', e.target.value as any)} /></Field>
              <Field label="Rate Period"><Select value={form.ratePeriod || 'monthly'} onChange={(e) => set('ratePeriod', e.target.value as any)}>
                <option value="monthly">Monthly</option><option value="annual">Annual</option>
              </Select></Field>
              <Field label="Min Amount"><Input type="number" value={form.minAmount ?? ''} onChange={(e) => set('minAmount', e.target.value as any)} /></Field>
              <Field label="Max Amount"><Input type="number" value={form.maxAmount ?? ''} onChange={(e) => set('maxAmount', e.target.value as any)} /></Field>
              <Field label="Min Term (months)"><Input type="number" value={form.minTermMonths ?? ''} onChange={(e) => set('minTermMonths', Number(e.target.value) as any)} /></Field>
              <Field label="Max Term (months)"><Input type="number" value={form.maxTermMonths ?? ''} onChange={(e) => set('maxTermMonths', Number(e.target.value) as any)} /></Field>
              <Field label="Penalty Rate %"><Input type="number" step="0.001" value={form.penaltyRatePercent ?? ''} onChange={(e) => set('penaltyRatePercent', e.target.value as any)} /></Field>
              <Field label="Penalty Method"><Select value={form.penaltyMethod || 'flat'} onChange={(e) => set('penaltyMethod', e.target.value as any)}>
                <option value="flat">Flat</option><option value="daily">Daily</option>
              </Select></Field>
              <Field label="Processing Fee %"><Input type="number" step="0.001" value={form.processingFeePercent ?? ''} onChange={(e) => set('processingFeePercent', e.target.value as any)} /></Field>
            </div>

            <div style={{ marginTop: 16 }}>
              <Field label="Reference Number Format">
                <Input value={form.referenceFormat ?? ''} onChange={(e) => set('referenceFormat', e.target.value.toUpperCase() as any)} placeholder={DEFAULT_REFERENCE_FORMAT} />
              </Field>
              <div className="t-2" style={{ fontSize: 11.5, marginTop: 4 }}>
                Placeholders: <code>{'{BRANCH}'}</code> branch code · <code>{'{CODE}'}</code> this loan type's code · <code>{'{YEAR}'}</code> 4-digit year · <code>{'{SEQ:6}'}</code> sequence (digit count adjustable). Leave blank to use the system default.
              </div>
              <div className="t-2" style={{ fontSize: 12, marginTop: 6 }}>
                Preview: <strong>{previewReferenceNumber(form.referenceFormat || DEFAULT_REFERENCE_FORMAT, 'HO', form.code || '')}</strong>
              </div>
            </div>
          </div>
        )}

        <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => set('status', e.target.value as any)} style={{ maxWidth: 200 }}>
          <option>Active</option><option>Inactive</option>
        </Select></Field>
      </Modal>
    </div>
  )
}
