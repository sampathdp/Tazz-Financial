/* NMS-Auto — post-login "what's new" modal.
   Shows the latest changelog entry once per user per version, using
   localStorage to remember what they've already seen. */
import { useEffect, useState } from 'react'
import { Modal, Btn } from './ui'
import { Icon } from './Icon'
import { CHANGELOG, CURRENT_VERSION } from '../changelog'
import type { AuthUser } from '../api'

const storageKey = (user: AuthUser) => `mms-whats-new-seen-${user.id ?? user.email ?? 'anon'}`

export function WhatsNewModal({ user }: { user: AuthUser }) {
  const [open, setOpen] = useState(false)
  const latest = CHANGELOG[0]

  useEffect(() => {
    if (!latest) return
    let seen: string | null = null
    try { seen = localStorage.getItem(storageKey(user)) } catch { /* ignore */ }
    if (seen !== CURRENT_VERSION) setOpen(true)
  }, [user, latest])

  if (!latest) return null

  const close = () => {
    try { localStorage.setItem(storageKey(user), CURRENT_VERSION) } catch { /* quota */ }
    setOpen(false)
  }

  return (
    <Modal open={open} onClose={close} title="What's new" sub={`Recent update — ${latest.date}`} width={480}
      footer={<Btn variant="primary" onClick={close} style={{ marginLeft: 'auto' }}>Got it</Btn>}>
      <div className="col gap-3">
        <div className="row gap-2" style={{ alignItems: 'center', color: 'var(--ac-bright)' }}>
          <Icon n="bolt" s={18} c="var(--ac-bright)" />
          <span style={{ fontWeight: 600, fontSize: 13.5 }}>Here's what we improved recently</span>
        </div>
        <ul style={{ margin: 0, paddingLeft: 18, display: 'flex', flexDirection: 'column', gap: 10 }}>
          {latest.highlights.map((h, i) => (
            <li key={i} style={{ fontSize: 13.5, color: 'var(--tx-1)', lineHeight: 1.5 }}>{h}</li>
          ))}
        </ul>
      </div>
    </Modal>
  )
}
