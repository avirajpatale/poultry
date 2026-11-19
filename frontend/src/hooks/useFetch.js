import { useCallback, useEffect, useState } from 'react'
import { get } from '../services/api'

/** Simple data-fetching hook: const { data, loading, reload } = useFetch('/path', params) */
export function useFetch(path, params = {}, deps = []) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const reload = useCallback(async () => {
    setLoading(true); setError(null)
    try { setData(await get(path, params)) }
    catch (e) { setError(e.message) }
    setLoading(false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, JSON.stringify(params)])

  useEffect(() => { reload() }, [reload, ...deps])
  return { data, loading, error, reload }
}

export function useDebounce(value, delay = 400) {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setV(value), delay)
    return () => clearTimeout(t)
  }, [value, delay])
  return v
}
