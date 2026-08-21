import type { CSSProperties } from 'react'
import { Field, Select } from './ui'
import { DATE_RANGE_PRESETS, CUSTOM_RANGE_KEY, detectDateRangePreset } from '../lib/dateRanges'

/** QuickBooks-style "Report period" dropdown: picking a preset fills the
 *  from/to date fields it's paired with; hand-editing those fields (by any
 *  caller) flips this back to "Custom" on its own, since the shown value is
 *  derived from from/to rather than tracked as separate state. */
export function DateRangeSelect({ from, to, onChange, label = 'Period', style }: {
  from: string; to: string; onChange: (from: string, to: string) => void; label?: string; style?: CSSProperties
}) {
  const current = detectDateRangePreset(from, to)

  return (
    <Field label={label}>
      <Select value={current} style={style} onChange={(e) => {
        const key = e.target.value
        if (key === CUSTOM_RANGE_KEY) return
        const preset = DATE_RANGE_PRESETS.find((p) => p.key === key)
        if (preset) { const r = preset.range(); onChange(r.from, r.to) }
      }}>
        {DATE_RANGE_PRESETS.map((p) => <option key={p.key} value={p.key}>{p.label}</option>)}
        <option value={CUSTOM_RANGE_KEY}>Custom</option>
      </Select>
    </Field>
  )
}
