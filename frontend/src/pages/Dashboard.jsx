import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { get, queueCount, flushQueue } from '../services/api'
import { useAuth } from '../context/AuthContext'
import { useLang } from '../i18n.jsx'
import { today, money, num } from '../utils/format'

function BigCard({ label, value, icon, bg, fg, sub }) {
  return (
    <div className="big-card">
      <div className="big-icon" style={{ background: bg, color: fg }}>
        <i className={`bi ${icon}`} />
      </div>
      <div>
        <div className="big-label">{label}</div>
        <div className="big-num" style={{ color: fg }}>{value}</div>
        {sub && <div className="big-sub">{sub}</div>}
      </div>
    </div>
  )
}

export default function Dashboard() {
  const { t, lang } = useLang()
  const { user } = useAuth()
  const [rec, setRec] = useState(null)
  const [stock, setStock] = useState(0)
  const [m, setM] = useState({ income: 0, expense: 0, mat: 0 })
  const [mm, setMm] = useState({ income: 0, expense: 0, mat: 0 })
  const [pending, setPending] = useState(queueCount())
  const [prefs, setPrefs] = useState({})
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const onQ = (e) => setPending(e.detail)
    window.addEventListener('farm-queue', onQ)
    flushQueue()                       // retry queued entries whenever dashboard loads
    const d = today()
    const monthStart = d.slice(0, 8) + '01'
    Promise.all([
      get(`/layers/records/${d}`).catch(() => null),
      get('/layers/egg-stock').catch(() => ({ stock: 0 })),
      get('/money', { from: d, to: d, per_page: 1 }).catch(() => ({ income: 0, expense: 0 })),
      get('/materials/totals', { from: d, to: d }).catch(() => ({ total: 0 })),
      get('/money', { from: monthStart, to: d, per_page: 1 }).catch(() => ({ income: 0, expense: 0 })),
      get('/materials/totals', { from: monthStart, to: d }).catch(() => ({ total: 0 })),
      get('/prefs').catch(() => ({ prefs: {} })),
    ]).then(([r, s, mn, mt, mmn, mmt, pf]) => {
      setRec(r); setStock(s.stock ?? 0)
      setM({ income: mn.income || 0, expense: mn.expense || 0, mat: mt.total || 0 })
      setMm({ income: mmn.income || 0, expense: mmn.expense || 0, mat: mmt.total || 0 })
      setPrefs(pf.prefs || {})
      setLoading(false)
    })
    return () => window.removeEventListener('farm-queue', onQ)
  }, [])

  const cards = [
    { label: t('d_produced'), value: num(rec?.eggs_collected || 0), icon: 'bi-egg-fill', bg: '#fef3c7', fg: '#b45309' },
    { label: t('d_received'), value: num(rec?.eggs_purchased || 0), icon: 'bi-box-arrow-in-down', bg: '#e0f2fe', fg: '#0369a1' },
    { label: t('d_damaged'), value: num(rec?.eggs_damaged || 0), icon: 'bi-x-octagon', bg: '#fee2e2', fg: '#b91c1c' },
    { label: t('d_sold'), value: num(rec?.eggs_sold || 0), icon: 'bi-bag-check', bg: '#ffedd5', fg: '#c2410c' },
    { label: t('d_stock'), value: num(stock), icon: 'bi-boxes', bg: '#f3e8ff', fg: '#7e22ce',
      sub: stock >= 30 ? t('d_trays', { t: Math.floor(stock / 30), e: stock % 30 }) : null },
    { label: t('d_income'), value: money(m.income), icon: 'bi-cash-coin', bg: '#dcfce7', fg: '#15803d' },
    { label: t('d_expense'), value: money(m.expense + m.mat), icon: 'bi-wallet2', bg: '#fee2e2', fg: '#b91c1c',
      sub: m.mat > 0 ? t('d_exp_incl', { x: money(m.mat) }) : null },
  ]

  const name = user?.name?.split(' ')[0] || ''
  const todayStr = new Date().toLocaleDateString(lang === 'mr' ? 'mr-IN' : 'en-IN',
    { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

  if (loading) return <div className="text-center py-5"><div className="spinner-border text-success" /></div>

  return (
    <div>
      <div className="hero-strip d-flex justify-content-between align-items-center">
        <div>
          <div className="hero-date">{todayStr}</div>
          <h3>🐔 {t('hello')}, {name}!</h3>
        </div>
        <div className="d-none d-md-block" style={{ fontSize: '2.4rem', opacity: .35 }}>
          <i className="bi bi-egg-fried" />
        </div>
      </div>

      {!rec && (
        <Link to="/eggs" className="alert alert-warning d-flex align-items-center gap-2 text-decoration-none fw-semibold">
          <i className="bi bi-exclamation-triangle-fill" /> {t('d_noentry')}
          <i className="bi bi-arrow-right ms-auto" />
        </Link>
      )}

      {prefs.vaccine_date && (() => {
        const diff = Math.ceil((new Date(prefs.vaccine_date + 'T00:00:00') - new Date(today() + 'T00:00:00')) / 86400000)
        if (diff > 3) return null
        return (
          <div className={`alert ${diff < 0 ? 'alert-danger' : 'alert-warning'} d-flex align-items-center gap-2 fw-semibold`}>
            <i className="bi bi-capsule" />
            {diff < 0 ? t('vac_overdue', { d: prefs.vaccine_date })
              : diff === 0 ? t('vac_today') : t('vac_soon', { d: prefs.vaccine_date, n: diff })}
            {prefs.vaccine_note ? ` — ${prefs.vaccine_note}` : ''}
          </div>
        )
      })()}

      {pending > 0 && (
        <div className="alert alert-info py-2 d-flex align-items-center gap-2 mb-3">
          <i className="bi bi-cloud-arrow-up" /> {t('off_pending', { n: pending })}
        </div>
      )}

      <div className="row g-3">
        {cards.map((c) => (
          <div className="col-6 col-md-4 col-xl-3" key={c.label}>
            <BigCard {...c} />
          </div>
        ))}
      </div>

      {(() => {
        const net = mm.income - mm.expense - mm.mat
        const pos = net >= 0
        return (
          <div className="content-card mt-3 p-3 d-flex align-items-center gap-3"
            style={{ borderLeft: `6px solid ${pos ? '#16a34a' : '#dc2626'}` }}>
            <div className="big-icon" style={{ background: pos ? '#dcfce7' : '#fee2e2', color: pos ? '#15803d' : '#b91c1c' }}>
              <i className={`bi ${pos ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow'}`} />
            </div>
            <div>
              <div className="big-label">{pos ? t('d_month_profit') : t('d_month_loss')}</div>
              <div className="big-num" style={{ color: pos ? '#15803d' : '#b91c1c' }}>{money(Math.abs(net))}</div>
              <div className="big-sub">{t('d_net_sub', { a: money(mm.income), b: money(mm.expense + mm.mat) })}</div>
            </div>
          </div>
        )
      })()}

      <div className="row g-2 mt-2">
        <div className="col-12 col-md-4">
          <Link to="/eggs" className="quick-btn w-100" style={{ background: 'linear-gradient(120deg,#ea580c,#f97316)' }}>
            <i className="bi bi-egg-fill" /><span>{t('d_go_entry')}</span>
          </Link>
        </div>
        <div className="col-12 col-md-4">
          <Link to="/money" className="quick-btn w-100" style={{ background: 'linear-gradient(120deg,#15803d,#22c55e)' }}>
            <i className="bi bi-cash" /><span>{t('d_go_money')}</span>
          </Link>
        </div>
        <div className="col-12 col-md-4">
          <Link to="/backup" className="quick-btn w-100" style={{ background: 'linear-gradient(120deg,#1d4ed8,#3b82f6)' }}>
            <i className="bi bi-cloud-download" /><span>{t('bk_title')}</span>
          </Link>
        </div>
      </div>
    </div>
  )
}
