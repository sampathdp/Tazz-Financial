/* Unsaved-form drafts. A page reload, crash, power cut or mid-form 401 wipes React state;
   this keeps a debounced copy of a *new-record* form in localStorage so it can be restored.
   Drafts expire after 24h, are cleared on explicit logout, and are never auto-applied —
   the user is asked (restore / discard) so a stale draft can't silently overwrite a form. */
import { useCallback, useEffect, useRef, useState } from 'react'

const PREFIX = 'mms-draft:'
const TTL_MS = 24 * 60 * 60 * 1000

interface Stored<T> { ts: number; value: T }

const read = <T,>(key: string): Stored<T> | null => {
  try {
    const raw = localStorage.getItem(PREFIX + key)
    if (!raw) return null
    const d = JSON.parse(raw) as Stored<T>
    if (!d || typeof d.ts !== 'number' || Date.now() - d.ts > TTL_MS) { localStorage.removeItem(PREFIX + key); return null }
    return d
  } catch { return null }
}

export const clearAllDrafts = () => {
  try {
    Object.keys(localStorage).filter((k) => k.startsWith(PREFIX)).forEach((k) => localStorage.removeItem(k))
  } catch { /* storage unavailable */ }
}

/**
 * @param key      unique per form (e.g. "customer:new")
 * @param active   true only while that form is open for a NEW record
 * @param value    current form state
 * @param initial  blank form state — a form equal to this is never saved as a draft
 */
export function useFormDraft<T>(key: string, active: boolean, value: T, initial: T) {
  const [pending, setPending] = useState<Stored<T> | null>(null)
  const initialJson = useRef(JSON.stringify(initial))

  // On open: surface a previous draft (if any).
  useEffect(() => { setPending(active ? read<T>(key) : null) }, [active, key])

  // While open: debounce-save any non-blank, not-pending-decision state.
  useEffect(() => {
    if (!active || pending) return
    const json = JSON.stringify(value)
    if (json === initialJson.current) return
    const t = window.setTimeout(() => {
      try { localStorage.setItem(PREFIX + key, JSON.stringify({ ts: Date.now(), value } satisfies Stored<T>)) } catch { /* quota / private mode */ }
    }, 800)
    return () => window.clearTimeout(t)
  }, [active, key, value, pending])

  const clear = useCallback(() => {
    try { localStorage.removeItem(PREFIX + key) } catch { /* ignore */ }
    setPending(null)
  }, [key])

  /** Stop prompting (the caller has applied the draft); keep it stored until saved or discarded. */
  const accept = useCallback(() => setPending(null), [])

  return { draft: pending, clear, accept }
}

export function DraftBanner({ savedAt, onRestore, onDiscard }: { savedAt: number; onRestore: () => void; onDiscard: () => void }) {
  return (
    <div
      role="status"
      style={{
        display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap', padding: '10px 14px', marginBottom: 14,
        border: '1px solid var(--line)', borderRadius: 'var(--r-m)', background: 'var(--bg-2)', fontSize: 13, color: 'var(--tx-1)',
      }}
    >
      <span>Unsaved draft found from {new Date(savedAt).toLocaleString()}.</span>
      <button onClick={onRestore} style={btn}>Restore draft</button>
      <button onClick={onDiscard} style={{ ...btn, opacity: 0.8 }}>Discard</button>
    </div>
  )
}

const btn = {
  padding: '4px 12px', borderRadius: 6, border: '1px solid var(--line)', background: 'var(--bg-1, transparent)',
  color: 'var(--tx-0)', cursor: 'pointer', fontSize: 13,
} as const
