/* Small fixed pill shown whenever a download is in flight — document
   viewers, receipt/report PDFs, Excel exports. Driven by lib/downloadProgress,
   which api.ts bumps automatically for every `responseType: 'blob'` request,
   so no screen has to wire its own loading state for this. */
import { useSyncExternalStore } from 'react'
import { getDownloadCount, subscribeDownloads } from '../lib/downloadProgress'

export function DownloadIndicator() {
  const count = useSyncExternalStore(subscribeDownloads, getDownloadCount)
  if (count === 0) return null

  return (
    <div className="row gap-2 fade-in" style={{
      position: 'fixed', bottom: 18, right: 18, zIndex: 390,
      background: 'var(--bg-1)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)',
      padding: '9px 14px', boxShadow: 'var(--sh-1)', alignItems: 'center',
    }}>
      <span className="mms-spin" style={{ width: 14, height: 14, border: '2px solid var(--line)', borderTopColor: 'var(--ac)', borderRadius: '50%', display: 'inline-block' }} />
      <span style={{ fontSize: 12.5, color: 'var(--tx-1)', fontWeight: 600 }}>
        {count > 1 ? `Downloading ${count} files…` : 'Downloading…'}
      </span>
    </div>
  )
}
