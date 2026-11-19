import { useState } from 'react'
import { Link } from 'react-router-dom'
import { post } from '../services/api'
import { useLang, LangSwitch } from '../i18n.jsx'

export default function ForgotPassword() {
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [sent, setSent] = useState(null)
  const { t } = useLang()

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    try { setSent(await post('/auth/forgot', { email })) }
    catch (err) { setSent({ error: err.message }) }
    setBusy(false)
  }

  return (
    <div className="d-flex align-items-center justify-content-center vh-100 auth-bg">
      <div className="card border-0 shadow-lg" style={{ width: 380, borderRadius: '1rem' }}>
        <div className="card-body p-4">
          <div className="d-flex justify-content-end mb-1"><LangSwitch /></div>
          <div className="text-center mb-4">
            <div style={{ fontSize: '2.5rem' }}>🔑</div>
            <h4 className="mb-0">{t('fg_title')}</h4>
          </div>
          {sent ? (
            <div className="text-center">
              <div className={`alert ${sent.error ? 'alert-danger' : 'alert-success'}`}>
                {sent.error || t('fg_sent')}
              </div>
              {sent.dev_link && (
                <div className="alert alert-warning small text-start">
                  Dev mode reset link:<br />
                  <a href={sent.dev_link} className="text-break">{sent.dev_link}</a>
                </div>
              )}
              <Link to="/login" className="btn btn-success w-100">{t('back_login')}</Link>
            </div>
          ) : (
            <form onSubmit={submit}>
              <p className="text-muted small">{t('fg_hint')}</p>
              <div className="mb-4">
                <input type="email" className="form-control form-control-lg" autoFocus required
                  placeholder={t('f_email')} value={email} onChange={(e) => setEmail(e.target.value)} />
              </div>
              <button className="btn btn-success w-100 btn-lg" disabled={busy}>
                {busy ? '…' : t('fg_send')}
              </button>
              <div className="text-center mt-3">
                <small><Link to="/login">{t('back_login')}</Link></small>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
