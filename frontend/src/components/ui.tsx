/* NMS-Auto — shared UI kit, ported from ui.jsx */
import { Children, useEffect, useRef, useState } from 'react'
import type { CSSProperties, ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Icon } from './Icon'

/* ---------- primitives ---------- */
type Variant = 'primary' | 'solid' | 'ghost' | 'danger' | 'ok' | 'plain'
interface BtnProps {
  children?: ReactNode
  variant?: Variant
  size?: 'sm' | 'md' | 'lg'
  icon?: string
  iconR?: string
  onClick?: () => void
  active?: boolean
  disabled?: boolean
  style?: CSSProperties
  title?: string
}
export function Btn({ children, variant = 'ghost', size = 'md', icon, iconR, onClick, active, disabled, style, title }: BtnProps) {
  const base: CSSProperties = {
    display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8,
    fontFamily: 'Outfit', fontWeight: 600,
    borderRadius: 'var(--r-s)', border: '1px solid transparent',
    padding: size === 'sm' ? '5px 10px' : size === 'lg' ? '11px 18px' : '8px 14px',
    fontSize: size === 'sm' ? 12.5 : 13.5, lineHeight: 1, transition: 'all .15s var(--ease)',
    opacity: disabled ? 0.5 : 1, pointerEvents: disabled ? 'none' : 'auto', whiteSpace: 'nowrap', ...style,
  }
  const V: Record<Variant, CSSProperties> = {
    primary: { background: 'var(--ac)', color: '#fff', boxShadow: '0 2px 10px -2px var(--ac-line)' },
    solid: { background: 'var(--bg-2)', color: 'var(--tx-0)', border: '1px solid var(--line)' },
    ghost: { background: active ? 'var(--ac-dim)' : 'transparent', color: active ? 'var(--ac-bright)' : 'var(--tx-1)', border: '1px solid ' + (active ? 'var(--ac-line)' : 'var(--line-soft)') },
    danger: { background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)' },
    ok: { background: 'var(--ok-dim)', color: 'var(--ok)', border: '1px solid var(--ok)' },
    plain: { background: 'transparent', color: 'var(--tx-2)', border: '1px solid transparent' },
  }
  return (
    <button title={title} className="mms-btn" style={{ ...base, ...V[variant] }} onClick={onClick} disabled={disabled}>
      {icon && <Icon n={icon} s={size === 'sm' ? 14 : 16} />}
      {children}
      {iconR && <Icon n={iconR} s={size === 'sm' ? 14 : 16} />}
    </button>
  )
}

type Tone = 'neutral' | 'blue' | 'green' | 'amber' | 'red'
export function Badge({ children, tone = 'neutral', dot }: { children?: ReactNode; tone?: Tone; dot?: boolean }) {
  const T = ({
    neutral: { bg: 'var(--bg-3)', c: 'var(--tx-1)' },
    blue: { bg: 'var(--ac-dim)', c: 'var(--ac-bright)' },
    green: { bg: 'var(--ok-dim)', c: 'var(--ok)' },
    amber: { bg: 'var(--warn-dim)', c: 'var(--warn)' },
    red: { bg: 'var(--bad-dim)', c: 'var(--bad)' },
  } as Record<Tone, { bg: string; c: string }>)[tone] || { bg: 'var(--bg-3)', c: 'var(--tx-1)' }
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '3px 9px', borderRadius: '100px', background: T.bg, color: T.c, fontSize: 11.5, fontWeight: 600, fontFamily: 'Outfit', letterSpacing: '.01em', whiteSpace: 'nowrap' }}>
      {dot && <span style={{ width: 6, height: 6, borderRadius: '50%', background: T.c }} />}
      {children}
    </span>
  )
}

export function Card({ children, style, pad = 20, className = '' }: { children?: ReactNode; style?: CSSProperties; pad?: number; className?: string }) {
  return (
    <div className={'mms-card ' + className} style={{ background: 'var(--bg-1)', border: '1px solid var(--line)', borderRadius: 'var(--r-l)', padding: pad, boxShadow: 'var(--sh-1)', ...style }}>
      {children}
    </div>
  )
}

export function PageHead({ title, sub, crumbs, actions, icon }: { title: string; sub?: ReactNode; crumbs?: ReactNode; actions?: ReactNode; icon?: string }) {
  return (
    <div className="row between wrap gap-4" style={{ marginBottom: 20 }}>
      <div className="row gap-3" style={{ alignItems: 'flex-start' }}>
        {icon && (
          <div style={{ width: 42, height: 42, borderRadius: 'var(--r-m)', background: 'var(--ac-dim)', border: '1px solid var(--ac-line)', display: 'grid', placeItems: 'center', color: 'var(--ac-bright)', flexShrink: 0 }}>
            <Icon n={icon} s={22} />
          </div>
        )}
        <div>
          {crumbs && <div className="eyebrow" style={{ marginBottom: 5 }}>{crumbs}</div>}
          <h1 style={{ fontSize: 25, letterSpacing: '-0.02em' }}>{title}</h1>
          {sub && <div className="t-2" style={{ fontSize: 13, marginTop: 3 }}>{sub}</div>}
        </div>
      </div>
      {actions && <div className="row gap-2 wrap">{actions}</div>}
    </div>
  )
}

export function Field({ label, children, style, full }: { label?: ReactNode; children?: ReactNode; style?: CSSProperties; full?: boolean }) {
  return (
    <label className="col gap-2" style={{ gridColumn: full ? '1/-1' : 'auto', ...style }}>
      {label && <span style={{ fontSize: 11.5, fontWeight: 600, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.06em' }}>{label}</span>}
      {children}
    </label>
  )
}

export const inputStyle: CSSProperties = {
  background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)',
  padding: '9px 12px', color: 'var(--tx-0)', fontSize: 13.5, fontFamily: 'Outfit', width: '100%', outline: 'none',
}
export function Input(p: React.InputHTMLAttributes<HTMLInputElement>) {
  return <input {...p} onFocus={(e) => {
    if (p.type === 'number') e.target.select()
    p.onFocus?.(e)
  }} style={{ ...inputStyle, ...(p.style || {}) }} />
}
/** DateInput — accepts/emits ISO `yyyy-mm-dd`, displays dd-mm-yyyy, opens native calendar. */
const isoToDmy = (iso: string) => {
  const m = (iso || '').match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}-${m[2]}-${m[1]}` : ''
}
const dmyToIso = (dmy: string): string | null => {
  const s = (dmy || '').trim().replace(/[./]/g, '-')
  const m = s.match(/^(\d{1,2})-(\d{1,2})-(\d{2}|\d{4})$/)
  if (!m) return null
  let [, d, mo, y] = m
  if (y.length === 2) y = (Number(y) >= 70 ? '19' : '20') + y
  const dd = d.padStart(2, '0'); const mm = mo.padStart(2, '0')
  const dt = new Date(`${y}-${mm}-${dd}`)
  if (isNaN(dt.getTime())) return null
  return `${y}-${mm}-${dd}`
}
export function DateInput({ value, onChange, placeholder = 'dd-mm-yyyy', style, disabled }: {
  value?: string
  onChange?: (iso: string) => void
  placeholder?: string
  style?: CSSProperties
  disabled?: boolean
}) {
  const [text, setText] = useState(isoToDmy(value || ''))
  const hidden = useRef<HTMLInputElement>(null)
  useEffect(() => { setText(isoToDmy(value || '')) }, [value])

  const commit = (raw: string) => {
    if (!raw.trim()) { onChange?.(''); return }
    const iso = dmyToIso(raw)
    if (iso) onChange?.(iso)
    else setText(isoToDmy(value || ''))
  }
  const openPicker = () => {
    const el = hidden.current; if (!el) return
    // showPicker is supported on modern Chromium/Firefox; fall back to focus
    if (typeof (el as any).showPicker === 'function') (el as any).showPicker()
    else el.focus()
  }
  return (
    <div style={{ position: 'relative', ...style }}>
      <input
        type="text"
        value={text}
        disabled={disabled}
        placeholder={placeholder}
        onChange={(e) => setText(e.target.value)}
        onBlur={(e) => commit(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); commit((e.target as HTMLInputElement).value) } }}
        style={{ ...inputStyle, paddingRight: 34 }}
      />
      <button type="button" onClick={openPicker} disabled={disabled} title="Pick date"
        style={{
          position: 'absolute', right: 6, top: '50%', transform: 'translateY(-50%)',
          background: 'transparent', border: 'none', cursor: 'pointer',
          color: 'var(--tx-2)', padding: 4, display: 'flex', alignItems: 'center',
        }}>
        <Icon n="calendar" s={16} />
      </button>
      <input
        ref={hidden}
        type="date"
        value={value || ''}
        onChange={(e) => { onChange?.(e.target.value); setText(isoToDmy(e.target.value)) }}
        tabIndex={-1}
        aria-hidden
        style={{ position: 'absolute', right: 6, top: '50%', transform: 'translateY(-50%)', width: 1, height: 1, opacity: 0, pointerEvents: 'none' }}
      />
    </div>
  )
}

export function Select({ children, ...p }: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <div style={{ position: 'relative' }}>
      <select {...p} style={{ ...inputStyle, appearance: 'none', paddingRight: 32, cursor: 'pointer', ...(p.style || {}) }}>{children}</select>
      <span style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none', color: 'var(--tx-2)' }}>
        <Icon n="chevd" s={15} />
      </span>
    </div>
  )
}

/** SearchSelect — inline type-ahead combobox: type to filter `options` in a
 *  live dropdown, click to pick. Same interaction as the item-line search
 *  boxes on PO/GRN, reused here for single-value fields like customer/supplier. */
export function SearchSelect({ value, onChange, options, getLabel, getSub, placeholder = 'Search…', onCreate, createLabel, disabled, autoFocus }: {
  value: string
  onChange: (name: string, opt?: any) => void
  options: any[]
  getLabel?: (o: any) => string
  getSub?: (o: any) => string
  placeholder?: string
  onCreate?: (query: string) => void
  createLabel?: (query: string) => string
  disabled?: boolean
  autoFocus?: boolean
}) {
  const label = getLabel || ((o: any) => o.name)
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const boxRef = useRef<HTMLDivElement>(null)

  // Closing without a pick: if the user backspaced the field empty, that's an
  // explicit clear; otherwise just revert to showing the previous value.
  const closeAndMaybeClear = () => {
    if (query.trim() === '' && value) onChange('', undefined)
    setOpen(false)
    setQuery('')
  }

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) closeAndMaybeClear()
    }
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [open, query, value]) // eslint-disable-line react-hooks/exhaustive-deps

  const q = query.trim().toLowerCase()
  const filtered = q
    ? options.filter((o) => [label(o), getSub?.(o)].some((v) => (v || '').toLowerCase().includes(q)))
    : options
  const exactMatch = filtered.some((o) => label(o).toLowerCase() === q)

  const pick = (o: any) => { onChange(label(o), o); setOpen(false); setQuery('') }
  // Options use onMouseDown+preventDefault so picking one doesn't blur the
  // input — which means a re-click after picking is NOT a real focus
  // transition, so onFocus alone never fires again. onClick always fires on
  // click regardless of prior focus state, so it's the reliable reopen hook;
  // guarded so a click while already open (e.g. repositioning the cursor)
  // doesn't stomp on text the user is mid-typing.
  const openField = () => { if (!open) { setOpen(true); setQuery(value) } }

  return (
    <div ref={boxRef} style={{ position: 'relative' }}>
      <input
        value={open ? query : value}
        disabled={disabled}
        autoFocus={autoFocus}
        onFocus={openField}
        onClick={openField}
        onChange={(e) => setQuery(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Escape') { setOpen(false); setQuery('') } }}
        placeholder={placeholder}
        style={{ ...inputStyle, color: value ? 'var(--tx-0)' : 'var(--tx-2)' }}
      />
      {open && (
        <div style={{ position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 30, marginTop: 4, background: 'var(--bg-2)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', boxShadow: 'var(--sh-2)', overflow: 'hidden', maxHeight: 280, overflowY: 'auto' }}>
          {filtered.slice(0, 8).map((o) => (
            <button key={o.id} type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => pick(o)}
              className="row between" style={{ width: '100%', background: 'none', border: 'none', borderBottom: '1px solid var(--line-soft)', padding: '9px 12px', textAlign: 'left', color: 'var(--tx-0)', cursor: 'pointer' }}>
              <span style={{ fontSize: 13, fontWeight: 500 }}>{label(o)}</span>
              {getSub && <span className="t-2" style={{ fontSize: 11.5 }}>{getSub(o)}</span>}
            </button>
          ))}
          {filtered.length === 0 && !onCreate && (
            <div style={{ padding: '14px 12px', textAlign: 'center', fontSize: 12.5, color: 'var(--tx-3)' }}>No matches</div>
          )}
          {onCreate && q && !exactMatch && (
            <button type="button" onMouseDown={(e) => e.preventDefault()}
              onClick={() => { onCreate(query.trim()); setOpen(false); setQuery('') }}
              className="row gap-2" style={{ width: '100%', background: 'var(--ac-dim)', border: 'none', padding: '9px 12px', textAlign: 'left', color: 'var(--ac-bright)', cursor: 'pointer', fontSize: 12.5, fontWeight: 600 }}>
              <Icon n="plus" s={14} />{createLabel ? createLabel(query.trim()) : `Add "${query.trim()}"`}
            </button>
          )}
        </div>
      )}
    </div>
  )
}

/* ---------- Modal ---------- */
export function Modal({ open, onClose, title, sub, children, width = 560, footer }: { open?: boolean; onClose?: () => void; title?: ReactNode; sub?: ReactNode; children?: ReactNode; width?: number; footer?: ReactNode }) {
  if (!open) return null
  return createPortal(
    <div onClick={onClose} className="mms-modal-overlay" style={{ position: 'fixed', inset: 0, zIndex: 200, background: 'oklch(0 0 0 / 0.6)', backdropFilter: 'blur(3px)', display: 'grid', placeItems: 'center', padding: 20, paddingLeft: 'calc(var(--sb-w) + 20px)', animation: 'fade .2s ease both' }}>
      <div onClick={(e) => e.stopPropagation()} style={{ width: 'min(' + width + 'px,100%)', maxHeight: '90vh', overflow: 'auto', background: 'var(--bg-1)', border: '1px solid var(--line)', borderRadius: 'var(--r-l)', boxShadow: 'var(--sh-3)', animation: 'slideIn .25s var(--ease) both' }}>
        <div className="row between" style={{ padding: '18px 22px', borderBottom: '1px solid var(--line)', position: 'sticky', top: 0, background: 'var(--bg-1)', zIndex: 1 }}>
          <div>
            <h3 style={{ fontSize: 18 }}>{title}</h3>
            {sub && <div className="t-2" style={{ fontSize: 12.5, marginTop: 2 }}>{sub}</div>}
          </div>
          <Btn variant="plain" size="sm" icon="x" onClick={onClose} style={{ padding: 6 }} />
        </div>
        <div style={{ padding: 22 }}>{children}</div>
        {footer && <div className="row between gap-2" style={{ padding: '14px 22px', borderTop: '1px solid var(--line)', position: 'sticky', bottom: 0, background: 'var(--bg-1)' }}>{footer}</div>}
      </div>
    </div>,
    document.body,
  )
}

/* ---------- Table ---------- */
export interface Col { label: ReactNode; align?: 'left' | 'right' | 'center'; w?: number | string }
export function Table<T = any>({ cols, rows, render, empty = 'No records', onRow }: { cols: Col[]; rows: T[]; render: (r: T, i: number) => ReactNode; empty?: string; onRow?: (r: T) => void }) {
  return (
    <div style={{ overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
        <thead>
          <tr>
            {cols.map((c, i) => (
              <th key={i} style={{ textAlign: c.align || 'left', padding: '10px 14px', fontSize: 11, fontWeight: 600, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.06em', borderBottom: '1px solid var(--line)', whiteSpace: 'nowrap', position: 'sticky', top: 0, background: 'var(--bg-1)', width: c.w }}>{c.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && (
            <tr><td colSpan={cols.length} style={{ padding: '40px', textAlign: 'center', color: 'var(--tx-3)' }}>{empty}</td></tr>
          )}
          {rows.map((r, ri) => (
            <tr key={ri} className="mms-tr" onClick={onRow ? () => onRow(r) : undefined} style={{ cursor: onRow ? 'pointer' : 'default', transition: 'background .12s' }}>
              {render(r, ri)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
export function Td({ children, align, mono, c, style }: { children?: ReactNode; align?: 'left' | 'right' | 'center'; mono?: boolean; c?: string; style?: CSSProperties }) {
  return (
    <td style={{ padding: '11px 14px', textAlign: align || 'left', borderBottom: '1px solid var(--line-soft)', color: c || 'var(--tx-1)', fontFamily: mono ? 'JetBrains Mono' : 'inherit', fontVariantNumeric: mono ? 'tabular-nums' : 'normal', whiteSpace: 'nowrap', ...style }}>
      {children}
    </td>
  )
}

export function statusTone(s: string): Tone {
  s = (s || '').toLowerCase()
  if (/(paid|approved|posted|completed|converted|in|ok|active)/.test(s)) return 'green'
  if (/(pending|partial|draft|open|low|risk)/.test(s)) return 'amber'
  if (/(unpaid|rejected|expired|out|overdue|inactive)/.test(s)) return 'red'
  return 'neutral'
}

/* ---------- Dropdown ----------
   The menu panel is rendered in a portal with fixed positioning so it is never
   clipped by an ancestor's overflow (e.g. a Table's `overflow-x: auto` wrapper).
   It also flips above the trigger when there isn't room below. */
export function DropdownMenu({ trigger, children, width = 180 }: { trigger: ReactNode; children: ReactNode; width?: number }) {
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState<{ top: number; left: number } | null>(null)
  const triggerRef = useRef<HTMLDivElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)

  const place = () => {
    const el = triggerRef.current
    if (!el) return
    const r = el.getBoundingClientRect()
    const count = Children.count(children)
    const estH = 8 + count * 38 // ~one row per item + padding
    let left = r.right - width
    if (left < 8) left = 8
    if (left + width > window.innerWidth - 8) left = window.innerWidth - 8 - width
    let top = r.bottom + 4
    if (top + estH > window.innerHeight - 8) top = Math.max(8, r.top - estH - 4) // flip up
    setPos({ top, left })
  }

  const toggle = () => { if (!open) place(); setOpen((o) => !o) }

  useEffect(() => {
    if (!open) return
    const onDocDown = (e: MouseEvent) => {
      const t = e.target as Node
      if (menuRef.current?.contains(t) || triggerRef.current?.contains(t)) return
      setOpen(false)
    }
    // Re-anchor on scroll rather than closing: a scroll can fire right after the
    // menu opens (e.g. the trigger being scrolled into view), which would other-
    // wise dismiss the menu the instant it appeared.
    const reposition = () => place()
    document.addEventListener('mousedown', onDocDown)
    window.addEventListener('scroll', reposition, true)
    window.addEventListener('resize', reposition)
    return () => {
      document.removeEventListener('mousedown', onDocDown)
      window.removeEventListener('scroll', reposition, true)
      window.removeEventListener('resize', reposition)
    }
  }, [open])

  return (
    <>
      <div ref={triggerRef} onClick={toggle} style={{ cursor: 'pointer', display: 'inline-flex' }}>
        {trigger}
      </div>
      {open && pos && createPortal(
        <div ref={menuRef} onClick={() => setOpen(false)} style={{
          position: 'fixed', top: pos.top, left: pos.left, width,
          background: 'var(--bg-1)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)',
          boxShadow: 'var(--sh-3)', zIndex: 300, padding: 4,
          display: 'flex', flexDirection: 'column', gap: 2,
        }}>
          {children}
        </div>,
        document.body,
      )}
    </>
  )
}

export function DropdownItem({ icon, label, onClick, danger }: { icon?: string; label: string; onClick?: () => void; danger?: boolean }) {
  const [hover, setHover] = useState(false)
  return (
    <button
      onClick={(e) => {
        e.stopPropagation()
        onClick?.()
      }}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px',
        background: hover ? 'var(--bg-2)' : 'transparent', border: 'none', borderRadius: 'var(--r-s)',
        color: danger ? 'var(--bad)' : 'var(--tx-0)', fontSize: 13,
        cursor: 'pointer', textAlign: 'left', width: '100%', transition: 'background .15s'
      }}>
      {icon && <Icon n={icon} s={15} c={danger ? 'var(--bad)' : 'var(--tx-2)'} />}
      {label}
    </button>
  )
}
