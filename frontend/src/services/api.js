import axios from 'axios'

/** Single axios instance for the whole app. Session cookie = auth. */
const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
})

api.interceptors.response.use(
  (res) => res.data,
  (err) => {
    const status = err.response?.status
    const message = err.response?.data?.error || err.message || 'Request failed'
    if (status === 401 && !location.pathname.startsWith('/login')) {
      window.location.href = '/login'
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
  return `/api${path}?${qs}`
}

export default api
