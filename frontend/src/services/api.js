import axios from 'axios'

// Dev: vite proxies '/api' to PHP. APK/prod: VITE_API_URL points at the server.
export const API_BASE = import.meta.env.VITE_API_URL || '/api'

const TOKEN_KEY = 'farm_token'
export const getToken = () => localStorage.getItem(TOKEN_KEY)
export const setToken = (t) => (t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY))

// ---------- offline write queue ----------
// Entries saved while the server is unreachable are kept on the device and
// re-sent in order when the connection comes back (phone away from Wi-Fi,
// PC/server asleep, etc). Only writes queue; reads still fail loudly.
const QUEUE_KEY = 'farm_queue'
const readQueue = () => {
  try { return JSON.parse(localStorage.getItem(QUEUE_KEY)) || [] } catch { return [] }
}
const writeQueue = (q) => {
  localStorage.setItem(QUEUE_KEY, JSON.stringify(q))
  window.dispatchEvent(new CustomEvent('farm-queue', { detail: q.length }))
}
export const queueCount = () => readQueue().length

let flushing = false
export const flushQueue = async () => {
  if (flushing) return
  flushing = true
  try {
    while (readQueue().length) {
      const { method, url, data, params } = readQueue()[0]
      try {
        await api.request({ method, url, data, params })
      } catch (e) {
        if (!e.response) break          // still offline — retry later
        // server rejected it (validation) — drop it so it doesn't block the queue
      }
      writeQueue(readQueue().slice(1))
    }
  } finally { flushing = false }
}
window.addEventListener('online', flushQueue)
setInterval(flushQueue, 30_000)

/** Session cookie for the browser; Bearer token for the Android app. */
const api = axios.create({
  baseURL: API_BASE,
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
})

api.interceptors.request.use((cfg) => {
  const t = getToken()
  if (t) cfg.headers.Authorization = `Bearer ${t}`
  return cfg
})

api.interceptors.response.use(
  (res) => res.data,
  (err) => {
    const status = err.response?.status
    const cfg = err.config || {}

    // real network failure on a write → queue for later, report success-ish
    if (!err.response && ['post', 'put', 'delete'].includes(cfg.method)
        && !cfg.url?.startsWith('/auth')) {
      let data = cfg.data
      if (typeof data === 'string') { try { data = JSON.parse(data) } catch { data = null } }
      writeQueue([...readQueue(), { method: cfg.method, url: cfg.url, data, params: cfg.params }])
      return Promise.resolve({ success: true, data: { queued: true } })
    }

    const message = err.response?.data?.error || err.message || 'Request failed'
    if (status === 401) {
      setToken(null)
      if (!location.pathname.startsWith('/login')) {
        window.location.href = '/login'
      }
    }
    return Promise.reject(new Error(message))
  },
)

// unwrap {success,data,error} envelopes
export const get  = (url, params) => api.get(url, { params }).then((r) => r.data)
export const post = (url, body) => api.post(url, body).then((r) => r.data)
export const put  = (url, body) => api.put(url, body).then((r) => r.data)
export const del  = (url) => api.delete(url).then((r) => r.data)

/** Build a download URL for CSV exports (browser navigates directly). */
export const csvUrl = (path, params = {}) => {
  const qs = new URLSearchParams({ ...params, format: 'csv' }).toString()
  return `${API_BASE}${path}?${qs}`
}

export default api
