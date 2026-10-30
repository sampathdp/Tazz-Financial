/* Tazz Investment & Credit — My Profile: account details plus the user's own achievements. */
import { useEffect, useRef, useState } from 'react'
import { UserAvatar, notifyPhotoChanged } from '../components/UserAvatar'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Modal, ErrorBanner, LoadingBlock, inputStyle } from '../components/ui'
import { achievementsApi, profileApi, type UserAchievement, type Staff, type AuthUser } from '../api'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const blankForm = (): Partial<UserAchievement> => ({ title: '', description: '', achievedOn: '' })
const fmtDate = (iso?: string | null) => {
  const m = (iso || '').match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}-${m[2]}-${m[1]}` : 'No date'
}

export function ProfileScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<UserAchievement[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<UserAchievement | null>(null)
  const [form, setForm] = useState<Partial<UserAchievement>>(blankForm())

  const refresh = () => {
    setLoading(true)
    achievementsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])
  const [staff, setStaff] = useState<Staff | null>(null)
  useEffect(() => { profileApi.staff().then(setStaff).catch(() => setStaff(null)) }, [])

  const openCreate = () => { setEditing(null); setForm(blankForm()); setErr(''); setModal(true) }
  const openEdit = (r: UserAchievement) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.title?.trim()) { setErr('Title is required.'); return }
    setBusy(true); setErr('')
    try {
      const body = { title: form.title.trim(), description: form.description?.trim() || null, achievedOn: form.achievedOn || null }
      if (editing) await achievementsApi.update(editing.id, body); else await achievementsApi.create(body)
      close(); refresh()
      notify.success(editing ? 'Achievement updated' : 'Achievement added', body.title)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (r: UserAchievement) => {
    const ok = await notify.confirm({ title: `Delete "${r.title}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await achievementsApi.remove(r.id); refresh(); notify.success('Achievement deleted', r.title) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const [hasPhoto, setHasPhoto] = useState(!!me?.hasPhoto)
  const [photoBusy, setPhotoBusy] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)

  const pickPhoto = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setPhotoBusy(true)
    try {
      await profileApi.uploadPhoto(file)
      setHasPhoto(true); notifyPhotoChanged(true)
      notify.success('Profile picture updated')
    } catch (ex: any) { notify.error('Upload failed', getErrorText(ex)) }
    finally { setPhotoBusy(false) }
  }

  const removePhoto = async () => {
    const ok = await notify.confirm({ title: 'Remove profile picture?', msg: 'Your initials will be shown instead.', danger: true, okText: 'Remove' })
    if (!ok) return
    try { await profileApi.removePhoto(); setHasPhoto(false); notifyPhotoChanged(false) }
    catch (ex: any) { notify.error('Remove failed', getErrorText(ex)) }
  }

  const name = staff?.name || me?.name || 'User'
  const status = staff?.status || me?.status || 'Active'
  const detail = (label: string, value?: string | null, mono?: boolean) => (
    <div style={{ minWidth: 0 }}>
      <div style={{ fontSize: 11.5, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.04em', marginBottom: 2 }}>{label}</div>
      <div style={{ fontWeight: 600, wordBreak: 'break-word', fontFamily: mono ? 'monospace' : undefined }}>{value || '—'}</div>
    </div>
  )

  return (
    <div>
      <PageHead crumbs="Profile" title="My Profile" icon="users" sub="Your account, staff details and achievements" />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-4" style={{ flexWrap: 'wrap', alignItems: 'center' }}>
          <UserAvatar name={name} hasPhoto={hasPhoto} size={112} radius="var(--r-m)" />
          <div style={{ flex: 1, minWidth: 220 }}>
            <div style={{ fontFamily: 'Saira', fontWeight: 700, fontSize: 22, color: 'var(--tx-0)' }}>{name}</div>
            <div style={{ color: 'var(--tx-2)', marginBottom: 8 }}>{staff?.designation || me?.role || ''}</div>
            <div className="row gap-2" style={{ flexWrap: 'wrap', marginBottom: 12 }}>
              {me?.role ? <Badge tone="blue">{me.role}</Badge> : null}
              <Badge tone={status === 'Active' ? 'green' : 'red'} dot>{status}</Badge>
              {(staff?.branch?.name || me?.branch?.name) ? <Badge>{staff?.branch?.name || me?.branch?.name}</Badge> : null}
            </div>
            <div className="row gap-2" style={{ flexWrap: 'wrap', alignItems: 'center' }}>
              <input ref={fileInput} type="file" accept="image/png,image/jpeg,image/webp" onChange={pickPhoto} style={{ display: 'none' }} />
              <Btn variant="solid" icon="upload" size="sm" onClick={() => fileInput.current?.click()} disabled={photoBusy}>
                {photoBusy ? 'Uploading…' : hasPhoto ? 'Change Picture' : 'Upload Picture'}
              </Btn>
              {hasPhoto && <Btn variant="ghost" icon="trash" size="sm" onClick={removePhoto}>Remove</Btn>}
              <span className="t-2" style={{ fontSize: 11 }}>JPG, PNG or WebP · max 5 MB</span>
            </div>
          </div>
        </div>
      </Card>

      <Card style={{ marginBottom: 16 }}>
        <div style={{ fontWeight: 700, marginBottom: 12 }}>Details</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 18 }}>
          {detail('Staff ID', staff?.staffCode, true)}
          {detail('NIC', staff?.nicNo)}
          {detail('Phone', staff?.phone)}
          {detail('Email', staff?.email || me?.email)}
          {detail('Designation', staff?.designation)}
          {detail('Branch', staff?.branch?.name || me?.branch?.name)}
        </div>
      </Card>

      <Card>
        <div className="row between" style={{ marginBottom: 12 }}>
          <div style={{ fontWeight: 700 }}>Achievements <Badge tone="blue">{rows.length}</Badge></div>
          <Btn variant="primary" icon="plus" onClick={openCreate}>Add Achievement</Btn>
        </div>
        {loading ? <LoadingBlock /> : rows.length === 0 ? (
          <div style={{ color: 'var(--tx-2)', padding: '16px 0' }}>No achievements yet. Add your first one.</div>
        ) : (
          <div style={{ display: 'grid', gap: 10 }}>
            {rows.map((r) => (
              <div key={r.id} className="row between" style={{ gap: 12, padding: 12, border: '1px solid var(--line)', borderRadius: 'var(--r-s)', background: 'var(--bg-2)', alignItems: 'flex-start' }}>
                <div style={{ minWidth: 0 }}>
                  <div style={{ fontWeight: 600, color: 'var(--tx-0)' }}>{r.title}</div>
                  <div style={{ fontSize: 12, color: 'var(--tx-2)', marginBottom: r.description ? 6 : 0 }}>{fmtDate(r.achievedOn)}</div>
                  {r.description ? <div style={{ fontSize: 13, whiteSpace: 'pre-wrap' }}>{r.description}</div> : null}
                </div>
                <div className="row gap-1" style={{ flexShrink: 0 }}>
                  <Btn variant="ghost" size="sm" onClick={() => openEdit(r)}>Edit</Btn>
                  <Btn variant="ghost" size="sm" onClick={() => remove(r)}>Delete</Btn>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Modal open={modal} onClose={close} width={520} title={editing ? 'Edit Achievement' : 'Add Achievement'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Add')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gap: 16 }}>
          <Field label="Title"><Input value={form.title || ''} maxLength={150} placeholder="e.g. Top collector - Q3" onChange={(e) => setForm((s) => ({ ...s, title: e.target.value }))} /></Field>
          <Field label="Date achieved"><DateInput value={form.achievedOn || ''} onChange={(v) => setForm((s) => ({ ...s, achievedOn: v }))} /></Field>
          <Field label="Description"><textarea value={form.description || ''} rows={4} maxLength={2000} onChange={(e) => setForm((s) => ({ ...s, description: e.target.value }))} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}
