/* NMS-Auto — client-facing "what's new" log.
   Add a new entry to the top each time an update is pushed; the modal
   in components/WhatsNewModal.tsx shows the top entry to anyone who
   hasn't seen that version yet. Keep entries short and non-technical. */

export interface ChangelogEntry {
  version: string
  date: string
  highlights: string[]
}

export const CHANGELOG: ChangelogEntry[] = [
  {
    version: '1.1.0',
    date: '2026-07-29',
    highlights: [
      'Loan Adjustments now shows a clear arrears summary, so overdue amounts are easy to spot at a glance.',
      'Loan summaries now include total interest remaining, giving a fuller picture of what is left to be paid.',
      'Added a new Funding section with a Dashboard, Products, and Reports screen for tracking funding activity.',
      'Loan types now support a grace period setting, with related calculations updating automatically.',
    ],
  },
]

export const CURRENT_VERSION = CHANGELOG[0].version
