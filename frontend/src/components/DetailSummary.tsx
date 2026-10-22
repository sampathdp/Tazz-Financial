/* Shared summary block for record detail pages (loan, speed draft, pawning, fixed deposit):
   hero figure, key-figure strip, date timeline and full detail list. Styles: .ld-* in theme.css. */
import type { ReactNode } from 'react'

export interface SummaryStat { label: string; value: ReactNode; tone?: string }
export interface SummaryStep { label: string; date?: string | null; done?: boolean }
export interface SummaryGroup { title: string; rows: [string, ReactNode][] }

const dateOnly = (v?: string | null) => (typeof v === 'string' && v ? v.slice(0, 10) : '')

export function DetailSummary({ code, title, titleNote, badges, amountLabel, amount, stats, steps, groups }: {
  code: ReactNode; title: ReactNode; titleNote?: ReactNode; badges?: ReactNode
  amountLabel: string; amount: ReactNode
  stats?: SummaryStat[]; steps?: SummaryStep[]; groups: SummaryGroup[]
}) {
  const isDone = (st: SummaryStep) => st.done ?? !!st.date
  const next = (steps || []).findIndex((st) => !isDone(st))
  return (
    <div className="ld-sum">
      <div className="ld-hero">
        <div>
          <div className="ld-hero-code">{code}</div>
          <div className="ld-hero-member">{title}{titleNote && <span>{titleNote}</span>}</div>
          {badges && <div className="row gap-2 wrap" style={{ marginTop: 8 }}>{badges}</div>}
        </div>
        <div className="ld-hero-amt"><span>{amountLabel}</span><b>{amount}</b></div>
      </div>

      {!!stats?.length && (
        <div className="ld-stats">
          {stats.map((s) => (
            <div key={s.label} className="ld-stat"><span>{s.label}</span><b style={s.tone ? { color: s.tone } : undefined}>{s.value}</b></div>
          ))}
        </div>
      )}

      {!!steps?.length && (
        <ol className="ld-steps">
          {steps.map((st, i) => (
            <li key={st.label} className={isDone(st) ? 'done' : i === next ? 'next' : ''}>
              <i />
              <b>{dateOnly(st.date) || (isDone(st) ? 'Done' : 'Not yet')}</b>
              <span>{st.label}</span>
            </li>
          ))}
        </ol>
      )}

      <div className="ld-details">
        {groups.filter((g) => g.rows.length).map((g) => (
          <section key={g.title}>
            <h4>{g.title}</h4>
            <dl>{g.rows.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl>
          </section>
        ))}
      </div>
    </div>
  )
}
