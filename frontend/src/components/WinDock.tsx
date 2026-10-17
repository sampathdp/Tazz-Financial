import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { Icon } from './Icon'

// Desktop "window" state for pages: minimized pages stay mounted (see PageHost
// in App.tsx) and are listed in a taskbar at the bottom; maximize hides the chrome.
export interface DockItem { key: string; to: string; title: string; crumbs?: ReactNode }

interface Ctx {
  items: DockItem[]
  maximized: boolean
  isMin: (key: string) => boolean
  setMaximized: (b: boolean) => void
  minimize: (item: DockItem) => void
  restore: (key: string) => void
  close: (key: string) => void
}

const noop = () => {}
const WinDockCtx = createContext<Ctx>({ items: [], maximized: false, isMin: () => false, setMaximized: noop, minimize: noop, restore: noop, close: noop })
export const useWinDock = () => useContext(WinDockCtx)

export function WinDockProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<DockItem[]>([])
  const [maximized, setMaximized] = useState(false)
  const location = useLocation()
  const navigate = useNavigate()

  useEffect(() => { setMaximized(false) }, [location.pathname])
  useEffect(() => {
    if (maximized) document.documentElement.dataset.win = 'max'
    else delete document.documentElement.dataset.win
    return () => { delete document.documentElement.dataset.win }
  }, [maximized])
  useEffect(() => {
    const k = (e: KeyboardEvent) => { if (e.key === 'Escape') setMaximized(false) }
    window.addEventListener('keydown', k)
    return () => window.removeEventListener('keydown', k)
  }, [])

  const minimize = useCallback((item: DockItem) => {
    setMaximized(false)
    setItems((l) => (l.some((i) => i.key === item.key) ? l.map((i) => (i.key === item.key ? item : i)) : [...l, item]))
  }, [])
  const close = useCallback((key: string) => setItems((l) => l.filter((i) => i.key !== key)), [])
  const restore = useCallback((key: string) => {
    const it = items.find((i) => i.key === key)
    if (!it) return
    setItems((l) => l.filter((i) => i.key !== key))
    if (location.pathname !== key) navigate(it.to)
  }, [items, location.pathname, navigate])

  const value = useMemo<Ctx>(() => ({ items, maximized, isMin: (k) => items.some((i) => i.key === k), setMaximized, minimize, restore, close }), [items, maximized, minimize, restore, close])
  return <WinDockCtx.Provider value={value}>{children}</WinDockCtx.Provider>
}

export function WinDockBar() {
  const { items, restore, close } = useWinDock()
  if (!items.length) return null
  return (
    <div className="mms-dock" style={{ position: 'fixed', bottom: 16, left: 'calc(var(--sb-w) + 16px)', right: 16, zIndex: 150, display: 'flex', flexWrap: 'wrap', gap: 8, pointerEvents: 'none' }}>
      {items.map((it) => (
        <div key={it.key} className="row between" style={{ pointerEvents: 'auto', width: 260, padding: '8px 10px 8px 14px', background: 'var(--bg-1)', border: '1px solid var(--line)', borderRadius: 'var(--r-l)', boxShadow: 'var(--sh-3)', cursor: 'pointer', animation: 'fade .2s ease both' }} onClick={() => restore(it.key)} title="Click to open">
          <div style={{ minWidth: 0 }}>
            <div style={{ fontWeight: 600, fontSize: 13, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{it.title}</div>
            {it.crumbs && <div className="t-2" style={{ fontSize: 11, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{it.crumbs}</div>}
          </div>
          <div className="row" style={{ gap: 2, flexShrink: 0 }}>
            <button onClick={(e) => { e.stopPropagation(); restore(it.key) }} title="Restore" style={{ background: 'none', border: 0, padding: 6, color: 'var(--tx-1)', cursor: 'pointer' }}><Icon n="restore" s={14} /></button>
            <button onClick={(e) => { e.stopPropagation(); close(it.key) }} title="Close" style={{ background: 'none', border: 0, padding: 6, color: 'var(--tx-1)', cursor: 'pointer' }}><Icon n="x" s={14} /></button>
          </div>
        </div>
      ))}
    </div>
  )
}
