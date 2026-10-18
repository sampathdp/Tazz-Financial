/* Connectivity state. `navigator.onLine` only reports the network adapter, not whether the
   API is reachable, so it is combined with real signals: any API answer = online, a request
   that fails without a response (dropped link / timeout) = offline. While offline a light
   heartbeat polls /ping and flips the state back as soon as the server answers. */
import { useSyncExternalStore } from 'react'

let online = typeof navigator === 'undefined' ? true : navigator.onLine
const listeners = new Set<() => void>()
let timer: number | undefined

const emit = () => listeners.forEach((l) => l())

const probe = async () => {
  try {
    const ctrl = new AbortController()
    const t = window.setTimeout(() => ctrl.abort(), 6000)
    const base = (import.meta.env.VITE_API_URL || '/api').replace(/\/$/, '')
    const r = await fetch(`${base}/ping`, { cache: 'no-store', signal: ctrl.signal })
    window.clearTimeout(t)
    if (r.ok) setOnline(true)
  } catch { /* still offline */ }
}

export function setOnline(next: boolean) {
  if (next === online) return
  online = next
  if (!online && timer === undefined) timer = window.setInterval(probe, 5000)
  if (online && timer !== undefined) { window.clearInterval(timer); timer = undefined }
  emit()
}

export const isOnline = () => online

if (typeof window !== 'undefined') {
  window.addEventListener('online', () => { void probe() })
  window.addEventListener('offline', () => setOnline(false))
  if (!online) setOnline(false) // start heartbeat when page opens offline
}

export function useOnline(): boolean {
  return useSyncExternalStore(
    (cb) => { listeners.add(cb); return () => { listeners.delete(cb) } },
    () => online,
  )
}

/** True for failures where no server answer arrived (offline, dropped, timed out). */
export const isNetworkError = (err: any) =>
  !!err && !err.response && (err.code === 'ERR_NETWORK' || err.code === 'ECONNABORTED' || err.code === 'ETIMEDOUT' || /network error|timeout/i.test(err.message ?? ''))

/* A read that still failed after retries. Many screens swallow fetch errors and render an
   empty list, which looks like "no data" — this flag lets the banner say otherwise. */
let loadFailed = false
export function setLoadFailed(next: boolean) {
  if (next === loadFailed) return
  loadFailed = next
  emit()
}
export function useLoadFailed(): boolean {
  return useSyncExternalStore(
    (cb) => { listeners.add(cb); return () => { listeners.delete(cb) } },
    () => loadFailed,
  )
}
