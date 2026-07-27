/** Template used when a loan type has no reference_format of its own. */
export const DEFAULT_REFERENCE_FORMAT = '{BRANCH}-{CODE}-{YEAR}-{SEQ:6}'

/**
 * Mirrors the backend's App\Services\NumberGenerator placeholder substitution,
 * for client-side previews only — the real number (and its sequence) is only
 * assigned by the server at save time.
 */
export function previewReferenceNumber(format: string, branchCode: string, code: string, seq = 1): string {
  const year = String(new Date().getFullYear())
  let out = format
    .replace(/\{BRANCH\}/g, branchCode || 'BR')
    .replace(/\{CODE\}/g, code || 'CODE')
    .replace(/\{YEAR\}/g, year)
  out = out.replace(/\{SEQ(?::(\d+))?\}/g, (_, n) => String(seq).padStart(n ? Number(n) : 6, '0'))
  return out
}
