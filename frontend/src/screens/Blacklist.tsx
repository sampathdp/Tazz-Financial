/* Sanction / Blacklist — admin only (unmapped in ROUTE_MODULE, so canAccessRoute denies everyone else).
   A blacklisted customer is refused every new facility in every branch. */
import { useCallback, useEffect, useRef, useState } from 'react'
import { Card, PageHead, Badge, Btn, Input, Table, Td, LoadingBlock } from '../components/ui'
import { customersApi, customerReportsApi, blacklistApi, type Customer } from '../api'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { ScreenProps } from './types'

const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')

export function BlacklistScreen(_: ScreenProps) {
  const notify = useNotify()
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [found, setFound] = useState<Customer[]>([])
  const [target, setTarget] = useState<Customer | null>(null)
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    customerReportsApi.get('blacklisted').then((d) => setRows(d || [])).catch((ex) => notify.error('Load failed', getErrorText(ex))).finally(() => setLoading(false))
  }, [notify])
  useEffect(load, [load])

  const timer = useRef<ReturnType<typeof setTimeout>>()
  useEffect(() => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => setDebounced(search.trim()), 300)
    return () => { if (timer.current) clearTimeout(timer.current) }
  }, [search])
  useEffect(() => {
    if (!debounced) { setFound([]); return }
    customersApi.browse({ search: debounced, page: 1, perPage: 10 }).then((r) => setFound(r.data)).catch(() => setFound([]))
  }, [debounced])

  const add = async () => {
    if (!target) return
    if (!reason.trim()) { notify.error('Reason required'); return }
    setBusy(true)
    try {
      await blacklistApi.add(target.id, reason.trim(), note.trim() || undefined)
      notify.success('Customer blacklisted', target.fullName)
      setTarget(null); setReason(''); setNote(''); setSearch(''); load()
    } catch (ex) { notify.error('Failed', getErrorText(ex)) } finally { setBusy(false) }
  }
  const remove = async (c: any) => {
    const ok = await notify.confirm({ title: `Remove "${c.fullName}" from the blacklist?`, msg: 'They will be able to receive facilities again.', okText: 'Remove' })
    if (!ok) return
    try { await blacklistApi.remove(c.id); notify.success('Removed from blacklist', c.fullName); load() } catch (ex) { notify.error('Failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Customer Management" title="Sanction / Blacklist" icon="shield"
        sub="Blacklisted customers cannot be given any facility in any branch. Only admins can change this list." />
      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Add to blacklist</div>
        {target ? (
          <div className="row gap-3 wrap" style={{ alignItems: 'center' }}>
            <b>{target.fullName}</b><span className="t-2">{target.code}</span>
            <div style={{ flex: 1, minWidth: 240 }}><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason (required)" autoFocus /></div>
            <div style={{ flex: '1 1 100%' }}><Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Note (optional) — details, references, incident…" /></div>
            <Btn variant="solid" onClick={add} disabled={busy}>Blacklist</Btn>
            <Btn onClick={() => { setTarget(null); setReason(''); setNote('') }}>Cancel</Btn>
          </div>
        ) : (
          <>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search customer by name, code, NIC or phone…" />
            {found.length > 0 && (
              <div style={{ marginTop: 10 }}>
                <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'NIC' }, { label: 'Status', align: 'center' as const }, { label: '', align: 'right' as const }]}
                  rows={found} empty=""
                  render={(c: Customer) => <>
                    <Td mono>{c.code}</Td><Td>{c.fullName}</Td><Td mono>{c.nicNo || '—'}</Td>
                    <Td align="center"><Badge tone={c.status === 'Blacklisted' ? 'red' : 'green'} dot>{c.status || 'Active'}</Badge></Td>
                    <Td align="right">{c.status === 'Blacklisted' ? <span className="t-3">Already listed</span> : <Btn size="sm" onClick={() => setTarget(c)}>Select</Btn>}</Td>
                  </>} />
              </div>
            )}
          </>
        )}
      </Card>
      {loading ? <LoadingBlock /> : (
        <Card pad={0}>
          <Table cols={[{ label: 'Code' }, { label: 'Name' }, { label: 'NIC' }, { label: 'Branch' }, { label: 'Reason' }, { label: 'Note' }, { label: 'Blacklisted On' }, { label: 'By' }, { label: '', align: 'right' as const }]}
            rows={rows} empty="No blacklisted customers"
            render={(c: any) => <>
              <Td mono>{c.code}</Td><Td>{c.fullName}</Td><Td mono>{c.nicNo || '—'}</Td><Td>{c.branch?.name || '—'}</Td>
              <Td>{c.reason || '—'}</Td><Td>{c.note || '—'}</Td><Td>{dateOnly(c.blacklistedAt)}</Td><Td>{c.blacklistedBy || '—'}</Td>
              <Td align="right"><Btn size="sm" onClick={() => remove(c)}>Remove</Btn></Td>
            </>} />
        </Card>
      )}
    </div>
  )
}
