import { useCallback, useEffect, useState } from 'react'
import { get, post, put, del } from '../services/api'
import { useToast } from '../context/ToastContext'
import { useLang } from '../i18n.jsx'
import PageHeader from '../components/PageHeader'
import ConfirmDialog from '../components/ConfirmDialog'

const EMPTY = { name: '', email: '', phone: '', password: '', role_id: '' }

export default function Users() {
  const toast = useToast()
  const { t } = useLang()
  const [users, setUsers] = useState([])
  const [roles, setRoles] = useState([])
  const [form, setForm] = useState(EMPTY)
  const [busy, setBusy] = useState(false)
  const [toDelete, setToDelete] = useState(null)

  const load = useCallback(async () => {
    try {
      const [u, r] = await Promise.all([get('/users'), get('/roles')])
      setUsers(u.items); setRoles(r.items)
    } catch (e) { toast.error(e.message) }
  }, [toast])
  useEffect(() => { load() }, [load])

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const create = async () => {
    if (!form.name || !form.email || !form.password) return toast.error(t('u_create'))
    setBusy(true)
    try {
      await post('/users', { ...form, role_id: form.role_id || undefined })
      toast.success(t('u_created')); setForm(EMPTY); load()
    } catch (e) { toast.error(e.message) }
    setBusy(false)
  }

  const setStatus = async (u, status) => {
    try {
      await put(`/users/${u.id}`, { status })
      toast.success(t('u_updated')); load()
    } catch (e) { toast.error(e.message) }
  }

  const remove = async () => {
    try {
      await del(`/users/${toDelete.id}`)
      toast.success(t('u_deleted')); setToDelete(null); load()
    } catch (e) { toast.error(e.message) }
  }

  const pending = users.filter((u) => u.status === 'pending')
  const others = users.filter((u) => u.status !== 'pending')
  const badge = (s) => ({ active: 'badge-paid', pending: 'badge-partial',
    rejected: 'badge-credit', disabled: 'badge-cancelled' }[s] || 'badge-closed')

  return (
    <div className="mx-auto" style={{ maxWidth: 900 }}>
      <PageHeader title={t('nav_users')} />

      {/* pending approvals */}
      {pending.length > 0 && (
        <div className="content-card mb-3">
          <div className="card-header py-2 px-3 text-warning-emphasis">
            <i className="bi bi-hourglass-split" /> {t('u_pending')} ({pending.length})
          </div>
          <div className="table-responsive">
            <table className="table mb-0">
              <tbody>
                {pending.map((u) => (
                  <tr key={u.id}>
                    <td><strong>{u.name}</strong><br />
                      <small className="text-muted">{u.email} · {u.phone}</small></td>
                    <td className="text-end">
                      <div className="btn-group btn-group-sm">
                        <button className="btn btn-success" onClick={() => setStatus(u, 'active')}>
                          <i className="bi bi-check" /> {t('u_approve')}</button>
                        <button className="btn btn-danger" onClick={() => setStatus(u, 'rejected')}>
                          <i className="bi bi-x" /> {t('u_reject')}</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* create user */}
      <div className="content-card p-3 mb-3">
        <div className="row g-2 align-items-end">
          <div className="col-md-3"><label className="form-label mb-1">{t('name')}</label>
            <input className="form-control" value={form.name} onChange={set('name')} /></div>
          <div className="col-md-3"><label className="form-label mb-1">{t('f_email')}</label>
            <input type="email" className="form-control" value={form.email} onChange={set('email')} /></div>
          <div className="col-md-2"><label className="form-label mb-1">{t('f_mobile')}</label>
            <input className="form-control" value={form.phone} onChange={set('phone')} /></div>
          <div className="col-md-2"><label className="form-label mb-1">{t('f_pwd')}</label>
            <input type="password" className="form-control" placeholder={t('pwd_ph')} value={form.password} onChange={set('password')} /></div>
          <div className="col-md-1"><label className="form-label mb-1">{t('u_role')}</label>
            <select className="form-select" value={form.role_id} onChange={set('role_id')}>
              {roles.filter((r) => r.name !== 'admin').map((r) => (
                <option key={r.id} value={r.id}>{r.name}</option>))}
            </select></div>
          <div className="col-md-1">
            <button className="btn btn-success w-100" disabled={busy} onClick={create}>
              <i className="bi bi-plus" /> {t('add')}
            </button>
          </div>
        </div>
      </div>

      {/* all users */}
      <div className="content-card">
        <div className="card-header py-2 px-3">{t('u_all')}</div>
        <div className="table-responsive">
          <table className="table table-hover mb-0 tbl-cards">
            <thead>
              <tr><th>{t('name')}</th><th>{t('f_email')}</th><th>{t('u_role')}</th>
                <th>{t('u_status')}</th><th></th></tr>
            </thead>
            <tbody>
              {others.map((u) => (
                <tr key={u.id}>
                  <td data-label={t('name')}>{u.name}</td>
                  <td data-label={t('f_email')} className="small">{u.email}</td>
                  <td data-label={t('u_role')}>{u.role}</td>
                  <td data-label={t('u_status')}><span className={`badge-soft ${badge(u.status)}`}>{t('st_' + u.status)}</span></td>
                  <td data-label="" className="text-end">
                    <div className="btn-group btn-group-sm">
                      {u.status === 'rejected' || u.status === 'disabled' ? (
                        <button className="btn btn-outline-success" onClick={() => setStatus(u, 'active')}>
                          {t('u_activate')}</button>
                      ) : u.status === 'active' && u.role !== 'admin' ? (
                        <button className="btn btn-outline-secondary" onClick={() => setStatus(u, 'disabled')}>
                          {t('u_deactivate')}</button>
                      ) : null}
                      {u.role !== 'admin' && (
                        <button className="btn btn-outline-danger" onClick={() => setToDelete(u)}>
                          <i className="bi bi-trash" /></button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <ConfirmDialog open={!!toDelete} title={t('u_del')}
        message={t('u_del_q', { n: toDelete?.name })}
        confirmLabel={t('del')} onConfirm={remove} onClose={() => setToDelete(null)} />
    </div>
  )
}
