/* Tracks how many downloads (blob fetches, client-side Excel builds) are in
   flight, app-wide. api.ts bumps this for every `responseType: 'blob'`
   request automatically, so every document/report download gets a preloader
   without each screen wiring its own loading state. */
type Listener = (count: number) => void
let count = 0
const listeners = new Set<Listener>()

export function beginDownload() {
  count += 1
  listeners.forEach((l) => l(count))
}

export function endDownload() {
  count = Math.max(0, count - 1)
  listeners.forEach((l) => l(count))
}

export function subscribeDownloads(fn: Listener) {
  listeners.add(fn)
  return () => { listeners.delete(fn) }
}

export function getDownloadCount() {
  return count
}
