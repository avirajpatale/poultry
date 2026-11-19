import { createContext, useContext, useEffect, useState } from 'react'
import { get, post } from '../services/api'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    get('/auth/me')
      .then((d) => setUser(d.user))
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  const login = async (email, password) => {
    const d = await post('/auth/login', { email, password })
    setUser(d.user)
    return d.user
  }

  const register = async (payload) => {
    const d = await post('/auth/register', payload)
    setUser(d.user)
    return d.user
  }

  const logout = async () => {
    try { await post('/auth/logout', {}) } catch { /* session may be gone */ }
    setUser(null)
  }

  const can = (perm) =>
    !!user && (user.role === 'admin' || user.permissions?.includes(perm))

  return (
    <AuthContext.Provider value={{ user, loading, login, register, logout, can }}>
      {children}
    </AuthContext.Provider>
  )
}

export const useAuth = () => useContext(AuthContext)
