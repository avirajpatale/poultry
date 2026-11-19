import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useLang, LangSwitch } from '../i18n.jsx'

// Exactly 5 menu items — nothing else.
const NAV = [
  { to: '/', label: 'nav_dashboard', icon: 'bi-house-door', end: true },
  { to: '/eggs', label: 'nav_egg_entry', icon: 'bi-egg-fill' },
  { to: '/daily', label: 'nav_daily_report', icon: 'bi-calendar-day' },
  { to: '/monthly', label: 'nav_monthly', icon: 'bi-calendar-month' },
  { to: '/money', label: 'nav_hisab', icon: 'bi-cash-stack' },
  { to: '/materials', label: 'nav_materials', icon: 'bi-box-seam' },
]

export default function AdminLayout() {
  const { user, logout } = useAuth()
  const { t, lang } = useLang()
  const [open, setOpen] = useState(false)
  const navigate = useNavigate()

  // admin sees one extra menu: user approvals
  const nav = user?.role === 'admin'
    ? [...NAV, { to: '/users', label: 'nav_users', icon: 'bi-people' }]
    : NAV

  const doLogout = async () => {
    await logout()
    navigate('/login')
  }

  return (
    <div className="app-shell">
      <aside className={`sidebar ${open ? 'open' : ''}`}>
        <div className="brand">
          <span style={{ fontSize: '1.4rem' }}>🐔</span> {t('app_title2')}
        </div>
        <nav className="py-2">
          {nav.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end}
              className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}
              onClick={() => setOpen(false)}>
              <i className={`bi ${item.icon}`} /> {t(item.label)}
            </NavLink>
          ))}
        </nav>
      </aside>

      <div className="main-col">
        <header className="topbar">
          <div className="d-flex align-items-center gap-2">
            <button className="btn btn-outline-secondary btn-sm sidebar-toggle"
              onClick={() => setOpen(!open)}>
              <i className="bi bi-list" />
            </button>
            <strong>{t('app_title2')}</strong>
          </div>
          <div className="d-flex align-items-center gap-3">
            <span className="text-muted small d-none d-md-inline">
              {new Date().toLocaleDateString(lang === 'mr' ? 'mr-IN' : 'en-IN',
                { weekday: 'short', day: 'numeric', month: 'short' })}
              {' · '}{user?.name}
            </span>
            <LangSwitch />
            <button className="btn btn-outline-danger btn-sm" onClick={doLogout}>
              <i className="bi bi-box-arrow-right" />
              <span className="d-none d-sm-inline"> {t('logout')}</span>
            </button>
          </div>
        </header>
        <main className="page-body">
          <Outlet />
        </main>

        {/* Bottom tab bar — the main navigation on phones */}
        <nav className="bottom-nav">
          {nav.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end}
              className={({ isActive }) => isActive ? 'active' : ''}>
              <i className={`bi ${item.icon}`} />
              <span>{t(item.label)}</span>
            </NavLink>
          ))}
        </nav>
      </div>
    </div>
  )
}
