/* Tazz Investment & Credit — Pawning Auctions: default handling for tickets
   whose collateral has gone unredeemed past the scheme's grace + auction-
   eligible window (see backend PawningAuctionController). Batch → add
   eligible lots → record each sale (or mark unsold) → complete the batch.
   GL posting for auction proceeds isn't wired yet (see that controller's
   docblock) — this screen only does the record-keeping side. */
import { useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Table, Td, Modal, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  pawningAuctionsApi, branchesApi,
  type PawningAuction, type PawningAuctionItem, type PawningAuctionEligiblePawning, type Branch, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)
const ROUTE_BASE = '/pawn/auctions'

const AUCTION_STATUS_TONE: Record<string, 'green' | 'amber' | 'red' | 'neutral'> = { Scheduled: 'amber', Completed: 'green', Cancelled: 'red' }
const ITEM_STATUS_TONE: Record<string, 'green' | 'amber' | 'red' | 'neutral'> = { Listed: 'amber', Sold: 'green', Unsold: 'neutral', Cancelled: 'red' }

export function PawningAuctionsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const navigate = useNavigate()
  const params = useParams<{ id?: string }>()
  const location = useLocation()
  const [rows, setRows] = useState<PawningAuction[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const [mode, setMode] = useState<'list' | 'form' | 'detail'>('list')
  const [detail, setDetail] = useState<PawningAuction | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [form, setForm] = useState<{ branchId?: number; scheduledDate: string; auctioneerName?: string; venue?: string }>({ scheduledDate: todayIso() })

  const refresh = () => { setLoading(true); pawningAuctionsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false)) }
  useEffect(() => { branchesApi.list().then(setBranches).catch(() => setBranches([])) }, [])

  const openCreate = () => navigate(`${ROUTE_BASE}/new`)
  const openRow = (row: PawningAuction) => navigate(`${ROUTE_BASE}/${row.id}`)
  const back = () => navigate(ROUTE_BASE)
  const reloadDetail = async () => { if (detail) setDetail(await pawningAuctionsApi.get(detail.id)) }

  const loadCreate = () => { setForm({ scheduledDate: todayIso() }); setErr(''); setMode('form') }
  const loadRow = async (id: number) => {
    try { setDetail(await pawningAuctionsApi.get(id)); setMode('detail') }
    catch { notify.error('Auction not found'); navigate(ROUTE_BASE, { replace: true }) }
  }

  useEffect(() => {
    if (location.pathname === `${ROUTE_BASE}/new`) { loadCreate(); return }
    if (params.id) { setDetailLoading(true); loadRow(Number(params.id)).finally(() => setDetailLoading(false)); return }
    setMode('list'); setDetail(null); refresh()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname, params.id])

  const save = async () => {
    if (!form.branchId) { setErr('Select a branch.'); return }
    if (!form.scheduledDate) { setErr('Select the auction date.'); return }
    setBusy(true); setErr('')
    try {
      const created = await pawningAuctionsApi.create({ branchId: form.branchId, scheduledDate: form.scheduledDate, auctioneerName: form.auctioneerName, venue: form.venue })
      notify.success('Auction batch created', created.auctionNo)
      navigate(`${ROUTE_BASE}/${created.id}`)
    } catch (ex: any) { setErr(getErrorText(ex, 'Could not create this auction')) }
    finally { setBusy(false) }
  }

  if (mode === 'detail') {
    return (
      <div>
        <PageHead crumbs="Pawning Management" title={detailLoading || !detail ? 'Loading…' : `Auction ${detail.auctionNo}`} icon="store"
          sub={detail ? `${detail.branch?.name} — ${dateOnly(detail.scheduledDate)}` : undefined}
          actions={<Btn variant="solid" onClick={back}>← Back to Auctions</Btn>} />
        {detailLoading || !detail ? <LoadingBlock /> : <AuctionDetail auction={detail} me={me} onChanged={reloadDetail} />}
      </div>
    )
  }

  if (mode === 'form') {
    return (
      <div>
        <PageHead crumbs="Pawning Management" title="New Auction Batch" icon="store"
          actions={<Btn variant="solid" onClick={back}>← Back to Auctions</Btn>} />
        <Card style={{ maxWidth: 560 }}>
          <ErrorBanner error={err} />
          <div className="col gap-4">
            <Field label="Branch"><Select value={form.branchId || ''} onChange={(e) => setForm((s) => ({ ...s, branchId: Number(e.target.value) }))}>
              <option value="">Select branch…</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </Select></Field>
            <Field label="Scheduled Date"><Input type="date" value={form.scheduledDate} onChange={(e) => setForm((s) => ({ ...s, scheduledDate: e.target.value }))} /></Field>
            <Field label="Auctioneer (optional)"><Input value={form.auctioneerName || ''} onChange={(e) => setForm((s) => ({ ...s, auctioneerName: e.target.value }))} /></Field>
            <Field label="Venue (optional)"><Input value={form.venue || ''} onChange={(e) => setForm((s) => ({ ...s, venue: e.target.value }))} /></Field>
            <div className="row gap-2">
              <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Creating…' : 'Create Batch'}</Btn>
              <Btn variant="solid" onClick={back}>Cancel</Btn>
            </div>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Pawning Management" title="Auctions" icon="store" sub={`${rows.length} batches`}
        actions={<Can user={me} module="Pawning Auctions" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>New Auction Batch</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Auction No' }, { label: 'Branch' }, { label: 'Date' }, { label: 'Auctioneer' }, { label: 'Lots', align: 'right' }, { label: 'Status', align: 'center' }]}
          rows={rows} empty="No auction batches yet" onRow={openRow}
          render={(a) => <>
            <Td mono style={{ fontWeight: 600 }}>{a.auctionNo}</Td>
            <Td>{a.branch?.name}</Td>
            <Td>{dateOnly(a.scheduledDate)}</Td>
            <Td>{a.auctioneerName || '—'}</Td>
            <Td mono align="right">{a.itemsCount ?? 0}</Td>
            <Td align="center"><Badge tone={AUCTION_STATUS_TONE[a.status] || 'neutral'} dot>{a.status}</Badge></Td>
          </>} />}
      </Card>
    </div>
  )
}

function AuctionDetail({ auction, me, onChanged }: { auction: PawningAuction; me?: AuthUser | null; onChanged: () => void }) {
  const notify = useNotify()
  const [addOpen, setAddOpen] = useState(false)
  const [saleItem, setSaleItem] = useState<PawningAuctionItem | null>(null)
  const [busy, setBusy] = useState(false)
  const items = auction.items || []

  const markUnsold = async (item: PawningAuctionItem) => {
    const ok = await notify.confirm({ title: 'Mark this lot unsold?', msg: 'It stays pledged and can be relisted in a future auction.', okText: 'Mark Unsold' })
    if (!ok) return
    try { await pawningAuctionsApi.markUnsold(item.id); onChanged(); notify.success('Lot marked unsold') }
    catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }

  const settleSurplus = async (item: PawningAuctionItem) => {
    const ok = await notify.confirm({ title: 'Refund surplus to the pledger?', msg: `${money(item.surplusAmount)} will be recorded as paid out.`, okText: 'Refund' })
    if (!ok) return
    try { await pawningAuctionsApi.settleSurplus(item.id); onChanged(); notify.success('Surplus refunded') }
    catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }

  const forfeitSurplus = async (item: PawningAuctionItem) => {
    const ok = await notify.confirm({ title: 'Forfeit this surplus?', msg: `${money(item.surplusAmount)} becomes company income — use only after the claim window has passed.`, danger: true, okText: 'Forfeit' })
    if (!ok) return
    try { await pawningAuctionsApi.forfeitSurplus(item.id); onChanged(); notify.success('Surplus forfeited') }
    catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
  }

  const complete = async () => {
    const ok = await notify.confirm({ title: `Complete auction ${auction.auctionNo}?`, msg: 'Every lot must already be sold or marked unsold.', okText: 'Complete' })
    if (!ok) return
    setBusy(true)
    try { await pawningAuctionsApi.complete(auction.id); onChanged(); notify.success('Auction completed') }
    catch (ex: any) { notify.error('Failed', getErrorText(ex)) }
    finally { setBusy(false) }
  }

  return (
    <div>
      <div className="row between wrap gap-2" style={{ marginBottom: 16 }}>
        <div style={{ fontSize: 12.5 }} className="t-2">
          {auction.venue && <>Venue: <b style={{ color: 'var(--tx-0)' }}>{auction.venue}</b> · </>}
          Status: <Badge tone={AUCTION_STATUS_TONE[auction.status] || 'neutral'} dot>{auction.status}</Badge>
        </div>
        {auction.status === 'Scheduled' && (
          <div className="row gap-2">
            <Can user={me} module="Pawning Auctions" action="Edit">
              <Btn size="sm" variant="solid" icon="plus" onClick={() => setAddOpen(true)}>Add Eligible Articles</Btn>
              <Btn size="sm" variant="primary" icon="check" onClick={complete} disabled={busy}>Complete Auction</Btn>
            </Can>
          </div>
        )}
      </div>

      <Card pad={0}>
        <Table cols={[{ label: 'Ticket' }, { label: 'Customer' }, { label: 'Article' }, { label: 'Outstanding', align: 'right' }, { label: 'Hammer Price', align: 'right' }, { label: 'Surplus', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 180 }]}
          rows={items} empty="No lots added to this batch yet."
          render={(it: PawningAuctionItem) => <>
            <Td mono>{it.facility?.code}</Td>
            <Td>{it.facility?.customer?.fullName}</Td>
            <Td>{it.collateral?.pawnDetail?.articleType?.name || it.collateral?.description}</Td>
            <Td mono align="right">{money(num(it.outstandingCapital) + num(it.outstandingInterest))}</Td>
            <Td mono align="right">{it.hammerPrice != null ? money(it.hammerPrice) : '—'}</Td>
            <Td mono align="right">
              {it.status === 'Sold' ? <>
                {money(it.surplusAmount)}
                {num(it.surplusAmount) > 0 && <div><Badge tone={it.settlementStatus === 'Pending' ? 'amber' : it.settlementStatus === 'Refunded' ? 'green' : 'red'} dot>{it.settlementStatus}</Badge></div>}
              </> : '—'}
            </Td>
            <Td align="center"><Badge tone={ITEM_STATUS_TONE[it.status] || 'neutral'} dot>{it.status}</Badge></Td>
            <Td align="right">
              {it.status === 'Listed' && (
                <Can user={me} module="Pawning Auctions" action="Edit">
                  <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                    <Btn size="sm" variant="primary" onClick={() => setSaleItem(it)}>Record Sale</Btn>
                    <button className="mms-act" title="Mark Unsold" onClick={() => markUnsold(it)}><Icon n="x" s={14} /></button>
                  </div>
                </Can>
              )}
              {it.status === 'Sold' && num(it.surplusAmount) > 0 && it.settlementStatus === 'Pending' && (
                <Can user={me} module="Pawning Auctions" action="Edit">
                  <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                    <Btn size="sm" variant="primary" onClick={() => settleSurplus(it)}>Refund Surplus</Btn>
                    <Btn size="sm" variant="solid" onClick={() => forfeitSurplus(it)}>Forfeit</Btn>
                  </div>
                </Can>
              )}
            </Td>
          </>} />
      </Card>

      <AddEligibleModal auction={auction} open={addOpen} onClose={() => setAddOpen(false)} onChanged={onChanged} notify={notify} />
      <RecordSaleModal item={saleItem} onClose={() => setSaleItem(null)} onChanged={onChanged} notify={notify} />
    </div>
  )
}

function AddEligibleModal({ auction, open, onClose, onChanged, notify }: { auction: PawningAuction; open: boolean; onClose: () => void; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const [eligible, setEligible] = useState<PawningAuctionEligiblePawning[]>([])
  const [loading, setLoading] = useState(false)
  const [selected, setSelected] = useState<number[]>([])
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!open) return
    setLoading(true); setSelected([])
    pawningAuctionsApi.eligible(auction.branchId).then(setEligible).catch(() => setEligible([])).finally(() => setLoading(false))
  }, [open, auction.branchId])

  const toggle = (id: number) => setSelected((sel) => (sel.includes(id) ? sel.filter((x) => x !== id) : [...sel, id]))

  const submit = async () => {
    if (selected.length === 0) { onClose(); return }
    setBusy(true)
    try {
      await pawningAuctionsApi.addItems(auction.id, selected)
      onClose(); onChanged()
      notify.success(`${selected.length} article(s) added to the auction`)
    } catch (ex: any) { notify.error('Failed to add articles', getErrorText(ex)) }
    finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={640} title="Add Eligible Articles" sub={auction.auctionNo}
      footer={<>
        <Btn variant="solid" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={submit} disabled={busy}>{busy ? 'Adding…' : `Add ${selected.length || ''} Article(s)`}</Btn>
      </>}>
      {loading ? <LoadingBlock compact /> : eligible.length === 0 ? (
        <div className="t-2" style={{ fontSize: 12.5, padding: 20, textAlign: 'center' }}>No articles are past their grace + auction-eligible window for this branch right now.</div>
      ) : (
        <div className="col gap-1" style={{ maxHeight: 360, overflowY: 'auto' }}>
          {eligible.map((tkt) => (tkt.collateral || []).map((c) => (
            <label key={c.id} className="row gap-2" style={{ alignItems: 'center', padding: '8px', border: '1px solid var(--line-soft)', borderRadius: 'var(--r-s)', cursor: 'pointer' }}>
              <input type="checkbox" checked={selected.includes(c.id)} onChange={() => toggle(c.id)} />
              <div style={{ flex: 1 }}>
                <div style={{ fontWeight: 600, fontSize: 13 }}>{c.pawnDetail?.articleType?.name || c.description} <span className="t-2" style={{ fontWeight: 400 }}>— {tkt.code}</span></div>
                <div className="t-2" style={{ fontSize: 11.5 }}>{tkt.customer?.fullName} · {tkt.daysPastEligible}d past eligible · outstanding {money(tkt.outstandingBalanceCache)}</div>
              </div>
            </label>
          )))}
        </div>
      )}
    </Modal>
  )
}

function RecordSaleModal({ item, onClose, onChanged, notify }: { item: PawningAuctionItem | null; onClose: () => void; onChanged: () => void; notify: ReturnType<typeof useNotify> }) {
  const [hammerPrice, setHammerPrice] = useState('')
  const [buyerName, setBuyerName] = useState('')
  const [buyerNic, setBuyerNic] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  if (!item) return null
  const owed = num(item.outstandingCapital) + num(item.outstandingInterest)
  const surplus = Math.max(0, num(hammerPrice) - owed)

  const submit = async () => {
    if (!hammerPrice || Number(hammerPrice) <= 0) { setErr('Enter the hammer price.'); return }
    setBusy(true); setErr('')
    try {
      await pawningAuctionsApi.recordSale(item.id, { hammerPrice, buyerName: buyerName || undefined, buyerNic: buyerNic || undefined })
      onClose(); setHammerPrice(''); setBuyerName(''); setBuyerNic(''); onChanged()
      notify.success('Sale recorded')
    } catch (ex: any) { setErr(getErrorText(ex, 'Failed to record sale')) }
    finally { setBusy(false) }
  }

  return (
    <Modal open={!!item} onClose={onClose} title="Record Sale" sub={item.facility?.code}
      footer={<>
        <Btn variant="solid" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={submit} disabled={busy}>{busy ? 'Recording…' : 'Record Sale'}</Btn>
      </>}>
      <div className="col gap-3">
        <ErrorBanner error={err} style={{ marginBottom: 0 }} />
        <div className="t-2" style={{ fontSize: 12.5 }}>Owed on this article: <b style={{ color: 'var(--tx-0)' }}>{money(owed)}</b></div>
        <Field label="Hammer Price"><Input type="number" value={hammerPrice} onChange={(e) => setHammerPrice(e.target.value)} /></Field>
        <Field label="Buyer Name (optional)"><Input value={buyerName} onChange={(e) => setBuyerName(e.target.value)} /></Field>
        <Field label="Buyer NIC (optional)"><Input value={buyerNic} onChange={(e) => setBuyerNic(e.target.value)} /></Field>
        {num(hammerPrice) > 0 && (
          <div className="t-2" style={{ fontSize: 12.5 }}>
            Surplus owed back to the pledger: <b style={{ color: surplus > 0 ? 'var(--ok)' : 'var(--tx-0)' }}>{money(surplus)}</b>
          </div>
        )}
      </div>
    </Modal>
  )
}

export default PawningAuctionsScreen
