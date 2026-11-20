import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useLang, LangSwitch } from '../i18n.jsx'

export default function Register() {
  const [form, setForm] = useState({ name: '', email: '', mobile: '', password: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const { register } = useAuth()
  const { t } = useLang()
  const navigate = useNavigate()

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true); setError('')
    try {
      await register(form)   // creates account AND logs in → straight to dashboard
      navigate('/')
    } catch (err) { setError(err.message) }
    setBusy(false)
  }

  return (
    <div className="d-flex align-items-center justify-content-center min-vh-100 py-4 auth-bg">
      <div className="card border-0 shadow-lg auth-card">
        <div className="card-body p-4">
          <div className="d-flex justify-content-end mb-1"><LangSwitch /></div>
          <div className="text-center mb-4">
            <div style={{ fontSize: '2.5rem' }}>🐔</div>
            <h4 className="mb-0">{t('reg_title')}</h4>
          </div>

          {error && <div className="alert alert-danger py-2">{error}</div>}
          <form onSubmit={submit}>
            <div className="mb-3">
              <label className="form-label">{t('name')}</label>
              <input className="form-control" autoFocus required value={form.name} onChange={set('name')} />
            </div>
            <div className="mb-3">
              <label className="form-label">{t('f_email')}</label>
              <input type="email" className="form-control" required value={form.email} onChange={set('email')} />
            </div>
            <div className="mb-3">
              <label className="form-label">{t('f_mobile')}</label>
              <input type="tel" className="form-control" required value={form.mobile} onChange={set('mobile')} />
            </div>
            <div className="mb-4">
              <label className="form-label">{t('f_pwd')}</label>
              <input type="password" className="form-control" required minLength={6}
                placeholder={t('pwd_ph')} value={form.password} onChange={set('password')} />
            </div>
            <button className="btn btn-success w-100 btn-lg" disabled={busy}>
              {busy ? '…' : t('reg_btn')}
            </button>
          </form>
          <div className="text-center mt-3">
            <small><Link to="/login">{t('back_login')}</Link></small>
          </div>
        </div>
      </div>
    </div>
  )
}
