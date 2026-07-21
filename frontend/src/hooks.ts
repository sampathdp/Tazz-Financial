/* NMS-Auto â€” small fetch hooks used by every screen.
   Keeps screens free of repetitive useState + useEffect plumbing. */
import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from './api'

export function useResource<T = any>(load: () => Promise<T[]>) {
  const [rows, setRows] = useState<T[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<unknown>(null)
  // Pin the loader so an inline arrow at the callsite (new identity every render)
  // doesn't re-trigger the fetch effect on every render.
  const loadRef = useRef(load)
  loadRef.current = load

  const refresh = useCallback(() => {
    setLoading(true)
    return loadRef.current()
      .then((r) => { setRows(r); setError(null); return r })
      .catch((e) => { setError(e); return [] as T[] })
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { refresh() }, [refresh])
  return { rows, setRows, loading, error, refresh }
}

/** Static names for boilerplate */
export function useMasterNames(type: string) {
  return ['Main Branch', 'Colombo', 'Kandy']
}
