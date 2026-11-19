/** Sri Lanka NIC: old = 9 digits + V/X (birth year 1900+YY), new = 12 digits (birth year YYYY).
 *  Digits 3-5 (old) / 5-7 (new) are day-of-year, +500 for female. Returns YYYY-MM-DD. */
export function nicToDob(nic: string): string | undefined {
  const s = nic.trim().toUpperCase()
  let year: number, dayOfYear: number
  if (/^\d{9}[VX]$/.test(s)) { year = 1900 + parseInt(s.slice(0, 2), 10); dayOfYear = parseInt(s.slice(2, 5), 10) }
  else if (/^\d{12}$/.test(s)) { year = parseInt(s.slice(0, 4), 10); dayOfYear = parseInt(s.slice(4, 7), 10) }
  else return undefined
  if (dayOfYear > 500) dayOfYear -= 500
  if (dayOfYear < 1 || dayOfYear > 366) return undefined
  // NIC day-of-year is always encoded against a 366-day calendar (Feb treated as 29 days),
  // so in non-leap years anything from Mar 1 onward (encoded day > 59) needs -1.
  const isLeap = (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0
  if (!isLeap && dayOfYear > 59) dayOfYear -= 1
  const d = new Date(year, 0, dayOfYear)
  if (d.getFullYear() !== year) return undefined
  return `${year}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export const validateNic = (v: string): string | undefined => {
  if (!v) return undefined
  const s = v.trim().toUpperCase()
  return /^\d{9}[VX]$/.test(s) || /^\d{12}$/.test(s) ? undefined : 'Enter valid NIC (9 digits + V/X, or 12 digits)'
}
