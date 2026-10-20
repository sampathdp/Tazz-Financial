/* Admin-only "Edit" button + modal used on facility detail screens. Renders nothing for
   non-admins; the backend enforces the same rule with its `admin` middleware. */
import { useState } from 'react'
import type { AuthUser } from '../api'
import { isAdmin } from '../lib/permissions'
import { getErrorText } from '../lib/apiError'
import { Btn, Modal, Field, Input, DateInput, Select, ErrorBanner } from './ui'
import { useNotify } from './Notify'

export interface EditField {
  key: string
  label: string
  type?: 'text' | 'number' | 'date' | 'textarea' | 'select'
  options?: { value: string; label: string }[]
  /** Shown read-only (locked) when true. */
  readOnly?: boolean
  full?: boolean
  step?: string
}

export function AdminEditButton({ user, title, fields, values, onSave, onSaved, hint }: {
  user?: AuthUser | null
  title: string
  fields: EditField[]
  values: object
  onSave: (v: Record<string, any>) => Promise<any>
  onSaved: (result: any) => void
  hint?: string
}) {
  const notify = useNotify()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  if (!isAdmin(user)) return null

  const start = () => {
    setForm(Object.fromEntries(fields.map((f) => [f.key, (values as any)[f.key] == null ? '' : String((values as any)[f.key])])))
    setErr(''); setOpen(true)
  }
  const save = async () => {
    setBusy(true); setErr('')
    try {
      const body = Object.fromEntries(fields.filter((f) => !f.readOnly).map((f) => [f.key, form[f.key] === '' ? null : form[f.key]]))
      const res = await onSave(body)
      notify.success('Saved', title)
      setOpen(false); onSaved(res)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not save changes.'))
    } finally { setBusy(false) }
  }

  return (
    <>
      <Btn size="sm" variant="solid" icon="edit" onClick={start}>Edit</Btn>
      <Modal open={open} onClose={() => setOpen(false)} width={560} title={title}
        footer={<>
          <Btn variant="plain" onClick={() => setOpen(false)}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : 'Save'}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
          {fields.map((f) => (
            <Field key={f.key} label={f.label} full={f.full || f.type === 'textarea'}>
              {f.type === 'date'
                ? <DateInput value={form[f.key] || ''} onChange={(v) => setForm((s) => ({ ...s, [f.key]: v }))} disabled={f.readOnly} />
                : f.type === 'select'
                  ? <Select value={form[f.key] || ''} disabled={f.readOnly} onChange={(e) => setForm((s) => ({ ...s, [f.key]: e.target.value }))}>
                      {f.options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </Select>
                  : f.type === 'textarea'
                    ? <textarea rows={3} value={form[f.key] || ''} disabled={f.readOnly} onChange={(e) => setForm((s) => ({ ...s, [f.key]: e.target.value }))} style={{ width: '100%' }} />
                    : <Input type={f.type === 'number' ? 'number' : 'text'} step={f.step} value={form[f.key] || ''} disabled={f.readOnly} onChange={(e) => setForm((s) => ({ ...s, [f.key]: e.target.value }))} />}
            </Field>
          ))}
        </div>
        {hint && <div className="t-2" style={{ fontSize: 11.5, marginTop: 10 }}>{hint}</div>}
      </Modal>
    </>
  )
}
