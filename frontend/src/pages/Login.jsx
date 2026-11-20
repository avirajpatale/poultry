import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useLang, LangSwitch } from '../i18n.jsx'

export default function Login() {
  const [form, setForm] = useState({ email: '', password: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const { login } = useAuth()
  const { t } = useLang()
  const navigate = useNavigate()

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true); setError('')
    try {
      await login(form.email, form.password)
      navigate('/')
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="d-flex align-items-center justify-content-center vh-100 auth-bg">
      <div className="card border-0 shadow-lg auth-card">
        <div className="card-body p-4">
          <div className="d-flex justify-content-end mb-1"><LangSwitch /></div>
          <div className="text-center mb-4">
            <div style={{ fontSize: '2.5rem' }}>🐔</div>
            <h4 className="mb-0">{t('app_title2')}</h4>
            <small className="text-muted">{t('login_sub')}</small>
          </div>
          {error && <div className="alert alert-danger py-2">{error}</div>}
          <form onSubmit={submit}>
            <div className="mb-3">
              <label className="form-label">{t('f_email')}</label>
              <input type="email" className="form-control" autoFocus required
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </div>
            <div className="mb-3">
              <label className="form-label">{t('f_pwd')}</label>
              <input type="password" className="form-control" required
                value={form.password}
                onChange={(e) => setForm({ ...form, password: e.target.value })} />
            </div>
            <button className="btn btn-success w-100 btn-lg" disabled={busy}>
              {busy ? '…' : t('login_btn')}
            </button>
          </form>
          <div className="d-flex justify-content-between mt-3">
            <small><Link to="/forgot">{t('forgot_lnk')}</Link></small>
            <small><Link to="/register">{t('reg_lnk')}</Link></small>
          </div>
        </div>
      </div>
    </div>
  )
}
