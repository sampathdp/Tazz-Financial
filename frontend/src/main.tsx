import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './theme.css'
import App from './App'
import { NotifyProvider } from './components/Notify'
import { DownloadIndicator } from './components/DownloadIndicator'
import { OfflineBanner } from './components/OfflineBanner'

// Apply the saved theme + accent before first paint (avoids a flash of the wrong theme).
// Theme defaults to dark; falls back to the OS preference only when nothing was saved.
;(() => {
  const el = document.documentElement
  const saved = localStorage.getItem('mms-theme')
  el.dataset.theme = saved ?? (window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
  const accent = localStorage.getItem('mms-accent')
  if (accent && accent !== 'blue') el.dataset.accent = accent
})()

// After a deploy, old tabs reference hashed chunks that no longer exist (server answers with
// index.html). Reload once to pick up the new build instead of leaving a blank screen.
const reloadForNewBuild = () => {
  const key = 'chunk-reload-at'
  const last = Number(sessionStorage.getItem(key) ?? 0)
  if (Date.now() - last < 10_000) return
  sessionStorage.setItem(key, String(Date.now()))
  window.location.reload()
}
window.addEventListener('vite:preloadError', (e) => {
  e.preventDefault()
  reloadForNewBuild()
})
window.addEventListener('unhandledrejection', (e) => {
  const msg = String((e.reason as Error | undefined)?.message ?? e.reason)
  if (/Failed to fetch dynamically imported module|Importing a module script failed/i.test(msg)) reloadForNewBuild()
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <NotifyProvider>
      <App />
      <DownloadIndicator />
      <OfflineBanner />
    </NotifyProvider>
  </StrictMode>,
)
