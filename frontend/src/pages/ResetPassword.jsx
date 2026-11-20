import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { post } from '../services/api'
import { useLang, LangSwitch } from '../i18n.jsx'

export default function ResetPassword() {
  const [sp] = useSearchParams()
  const token = sp.get('token') || ''
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState(null)  // {ok, text}
  const { t } = useLang()

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    try {
      await post('/auth/reset', { token, password })
      setMsg({ ok: true, text: t('rst_done') })
    } catch (err) { setMsg({ ok: false, text: err.message }) }
    setBusy(false)
  }

  return (
    <div className="d-flex align-items-center justify-content-center vh-100 auth-bg">
      <div className="card border-0 shadow-lg auth-card">
        <div className="card-body p-4">
          <div className="d-flex justify-content-end mb-1"><LangSwitch /></div>
          <div className="text-center mb-4">
            <div style={{ fontSize: '2.5rem' }}>🔑</div>
            <h4 className="mb-0">{t('rst_title')}</h4>
          </div>
          {msg ? (
            <div className="text-center">
              <div className={`alert ${msg.ok ? 'alert-success' : 'alert-danger'}`}>{msg.text}</div>
              <Link to="/login" className="btn btn-success w-100">{t('back_login')}</Link>
            </div>
          ) : (
            <form onSubmit={submit}>
              <div className="mb-4">
                <label className="form-label">{t('rst_new')}</label>
                <input type="password" className="form-control form-control-lg" autoFocus required
                  minLength={6} placeholder={t('pwd_ph')}
                  value={password} onChange={(e) => setPassword(e.target.value)} />
              </div>
              <button className="btn btn-success w-100 btn-lg" disabled={busy || !token}>
                {busy ? '…' : t('save')}
              </button>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
