/* Laravel validation failures return { message, errors: { field: [msg, ...] } } — the
   generic top-level `message` ("The given data was invalid.") is useless on its own.
   These helpers surface every field's messages instead of just the first one found. */

export function getErrorMessages(err: any, fallback = 'Something went wrong. Please try again.'): string[] {
  const errors = err?.response?.data?.errors
  if (errors && typeof errors === 'object') {
    const msgs = Object.values(errors).flat().filter((m): m is string => typeof m === 'string' && m.length > 0)
    if (msgs.length) return msgs
  }
  const message = err?.response?.data?.message
  if (typeof message === 'string' && message) return [message]
  if (typeof err?.message === 'string' && err.message) return [err.message]
  return [fallback]
}

/** Single-string form (one message per line) for toasts and inline banners. */
export function getErrorText(err: any, fallback?: string): string {
  return getErrorMessages(err, fallback).join('\n')
}
