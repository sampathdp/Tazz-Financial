import { useLoadFailed, useOnline } from '../lib/network'

const bar = {
  position: 'fixed', top: 0, left: 0, right: 0, zIndex: 10000, padding: '8px 16px',
  color: '#fff', textAlign: 'center', fontSize: 13, fontWeight: 600,
} as const

/** Fixed strip shown while the API is unreachable, or when a read failed after retries
 *  (screens would otherwise show an empty list that looks like "no data"). */
export function OfflineBanner() {
  const online = useOnline()
  const loadFailed = useLoadFailed()
  if (!online) {
    return (
      <div role="alert" style={{ ...bar, background: 'var(--danger, #c0392b)' }}>
        You are offline. Changes can't be saved until the connection returns — data on screen may be out of date.
      </div>
    )
  }
  if (loadFailed) {
    return (
      <div role="alert" style={{ ...bar, background: 'var(--warn, #b9770e)' }}>
        Some data couldn't be loaded because of a slow or unstable connection — empty lists may not be accurate.{' '}
        <button
          onClick={() => window.location.reload()}
          style={{ marginLeft: 8, padding: '2px 10px', borderRadius: 6, border: '1px solid #fff', background: 'transparent', color: '#fff', cursor: 'pointer' }}
        >
          Reload
        </button>
      </div>
    )
  }
  return null
}
