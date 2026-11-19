import { useCallback, useEffect, useMemo, useState } from 'react'
import { get, post, put, del } from '../services/api'
import { useToast } from '../context/ToastContext'
import { useLang } from '../i18n.jsx'
import PageHeader from '../components/PageHeader'
import ConfirmDialog from '../components/ConfirmDialog'
import { today, monthStart, money, num } from '../utils/format'

const UNITS = ['KG', 'Quintal', 'Ton', 'Bag', 'Litre', 'Piece', 'Other']
const UNIT_KEY = { KG: 'unit_kg', Quintal: 'unit_quintal', Ton: 'unit_ton', Bag: 'unit_bag', Litre: 'unit_litre', Piece: 'unit_piece', Other: 'unit_other' }

const EMPTY = {
  txn_date: today(), category_id: '', quantity: '', unit: 'KG', rate: '',
  supplier: '', payment_mode: 'cash', notes: '',
}

export default function Materials() {
  const toast = useToast()
  const { t, lang } = useLang()
  const [cats, setCats] = useState([])
  const [form, setForm] = useState(EMPTY)
  const [editing, setEditing] = useState(null)
  const [busy, setBusy] = useState(false)
  const [newCat, setNewCat] = useState('')
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), category_id: '', page: 1 })
  const [data, setData] = useState({ items: [], summary: [], grand_qty: 0, grand_total: 0, total: 0, page: 1, per_page: 20 })
  const [toDelete, setToDelete] = useState(null)

  const load = useCallback(async () => {
    try { setData(await get('/materials', { ...filters, per_page: 20 })) }
    catch (e) { toast.error(e.message) }
  }, [filters, toast])
  const loadCats = useCallback(async () => {
    try { setCats((await get('/materials/categories')).items) } catch { /* ignore */ }
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { loadCats() }, [loadCats])

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const total = useMemo(() =>
    (Number(form.quantity) || 0) * (Number(form.rate) || 0), [form.quantity, form.rate])

  const addCategory = async () => {
    const name = newCat.trim()
    if (!name) return
    try {
      await post('/materials/categories', { name })
      setNewCat(''); loadCats(); toast.success(t('mat_cat_saved'))
    } catch (e) { toast.error(e.message) }
  }

  const save = async () => {
    if (!form.category_id || !form.quantity || !form.rate) {
      return toast.error(t('mat_required'))
    }
    setBusy(true)
    try {
      if (editing) { await put(`/materials/${editing.id}`, form); toast.success(t('mat_updated')) }
      else { await post('/materials', form); toast.success(t('mat_saved')) }
      setForm(EMPTY); setEditing(null); load()
    } catch (e) { toast.error(e.message) }
    setBusy(false)
  }

  const startEdit = (r) => {
    setEditing(r)
    setForm({
      txn_date: r.txn_date, category_id: r.category_id, quantity: r.quantity,
      unit: r.unit, rate: r.rate, supplier: r.supplier || '',
      payment_mode: r.payment_mode, notes: r.notes || '',
    })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const remove = async () => {
    try {
      await del(`/materials/${toDelete.id}`)
      toast.success(t('mat_deleted')); setToDelete(null); load()
    } catch (e) { toast.error(e.message) }
  }

  const unitLabel = (u) => t(UNIT_KEY[u] || 'unit_other')

  return (
    <div className="mx-auto" style={{ maxWidth: 900 }}>
      <PageHeader title={t('nav_materials')} />

      {/* entry form */}
      <div className="content-card p-4 mb-3">
        {editing && <div className="alert alert-info py-2 small">{t('mn_editing')}</div>}

        {/* material picker + add new */}
        <div className="row g-3">
          <div className="col-md-4">
            <label className="form-label">{t('date')}</label>
            <input type="date" className="form-control form-control-lg" value={form.txn_date} onChange={set('txn_date')} />
          </div>
          <div className="col-md-4">
            <label className="form-label">{t('mat_material')} *</label>
            <select className="form-select form-select-lg" value={form.category_id} onChange={set('category_id')}>
              <option value="">{t('select_lbl')}</option>
              {cats.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <div className="col-md-4">
            <label className="form-label">{t('mat_new_cat')}</label>
            <div className="input-group">
              <input className="form-control" value={newCat} onChange={(e) => setNewCat(e.target.value)}
                placeholder={t('mat_cat_name')} onKeyDown={(e) => e.key === 'Enter' && addCategory()} />
              <button className="btn btn-outline-success" type="button" onClick={addCategory}>
                <i className="bi bi-plus" /></button>
            </div>
          </div>

          <div className="col-md-3">
            <label className="form-label">{t('mat_qty')} *</label>
            <input type="number" min="0" step="0.01" inputMode="decimal" className="form-control form-control-lg"
              value={form.quantity} onChange={set('quantity')} />
          </div>
          <div className="col-md-3">
            <label className="form-label">{t('mat_unit')}</label>
            <select className="form-select form-select-lg" value={form.unit} onChange={set('unit')}>
              {UNITS.map((u) => <option key={u} value={u}>{unitLabel(u)}</option>)}
            </select>
          </div>
          <div className="col-md-3">
            <label className="form-label">{t('mat_rate')} *</label>
            <input type="number" min="0" step="0.01" inputMode="decimal" className="form-control form-control-lg"
              value={form.rate} onChange={set('rate')} />
          </div>
          <div className="col-md-3">
            <label className="form-label">{t('mat_total')}</label>
            <div className="form-control form-control-lg bg-light fw-bold text-success">
              {money(total)}
            </div>
          </div>

          <div className="col-md-4">
            <label className="form-label">{t('mat_supplier')}</label>
            <input className="form-control" value={form.supplier} onChange={set('supplier')} />
          </div>
          <div className="col-md-4">
            <label className="form-label">{t('mn_mode')}</label>
            <select className="form-select" value={form.payment_mode} onChange={set('payment_mode')}>
              <option value="cash">{t('cash')}</option>
              <option value="upi">{t('upi')}</option>
              <option value="bank">{t('bank')}</option>
            </select>
          </div>
          <div className="col-md-4">
            <label className="form-label">{t('notes')}</label>
            <input className="form-control" value={form.notes} onChange={set('notes')} />
          </div>
        </div>
        <div className="d-grid mt-3">
          <button className="btn btn-success btn-lg" disabled={busy} onClick={save}>
            {busy ? t('sf_saving') : (editing ? t('mn_update') : t('mn_add'))}
          </button>
        </div>
      </div>

      {/* per-material totals */}
      {data.summary.length > 0 && (
        <div className="row g-3 mb-3">
          {data.summary.map((s) => (
            <div className="col-6 col-md-3" key={s.category_id}>
              <div className="big-card">
                <div className="big-icon" style={{ background: '#fef3c7', color: '#b45309' }}>
                  <i className="bi bi-box-seam" />
                </div>
                <div>
                  <div className="big-label">{s.name}</div>
                  <div className="big-num" style={{ fontSize: '1.25rem' }}>{money(s.amount)}</div>
                  <div className="big-sub">{num(s.qty)} {unitLabel(s.unit)}</div>
                </div>
              </div>
            </div>
          ))}
          <div className="col-6 col-md-3">
            <div className="big-card" style={{ background: '#f0fdf4' }}>
              <div className="big-icon" style={{ background: '#dcfce7', color: '#15803d' }}>
                <i className="bi bi-cash-coin" />
              </div>
              <div>
                <div className="big-label">{t('mat_amt_total')}</div>
                <div className="big-num" style={{ fontSize: '1.25rem', color: '#15803d' }}>{money(data.grand_total)}</div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* history + filters */}
      <div className="content-card">
        <div className="card-header py-2 px-3 d-flex gap-2 flex-wrap align-items-center">
          <strong className="me-auto">{t('mat_history')}</strong>
          <input type="date" className="form-control form-control-sm w-auto" value={filters.from}
            onChange={(e) => setFilters({ ...filters, from: e.target.value, page: 1 })} />
          <span className="text-muted small">{t('to_lbl')}</span>
          <input type="date" className="form-control form-control-sm w-auto" value={filters.to}
            onChange={(e) => setFilters({ ...filters, to: e.target.value, page: 1 })} />
          <select className="form-select form-select-sm w-auto" value={filters.category_id}
            onChange={(e) => setFilters({ ...filters, category_id: e.target.value, page: 1 })}>
            <option value="">{t('all')}</option>
            {cats.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
        <div className="table-responsive">
          <table className="table table-hover mb-0 tbl-cards">
            <thead>
              <tr><th>{t('date')}</th><th>{t('mat_material')}</th>
                <th className="text-end">{t('mat_qty')}</th><th>{t('mat_unit')}</th>
                <th className="text-end">{t('mat_rate')}</th><th className="text-end">{t('mat_total')}</th>
                <th>{t('mat_supplier')}</th><th></th></tr>
            </thead>
            <tbody>
              {data.items.map((r) => (
                <tr key={r.id}>
                  <td data-label={t('date')}>{r.txn_date}</td>
                  <td data-label={t('mat_material')} className="fw-semibold">{r.category_name}</td>
                  <td data-label={t('mat_qty')} className="text-end">{num(r.quantity)}</td>
                  <td data-label={t('mat_unit')}>{unitLabel(r.unit)}</td>
                  <td data-label={t('mat_rate')} className="text-end">{money(r.rate)}</td>
                  <td data-label={t('mat_total')} className="text-end fw-semibold text-danger">{money(r.amount)}</td>
                  <td data-label={t('mat_supplier')} className="small text-muted">{r.supplier || '—'}</td>
                  <td data-label="" className="text-end">
                    <div className="btn-group btn-group-sm">
                      <button className="btn btn-outline-primary" onClick={() => startEdit(r)}><i className="bi bi-pencil" /></button>
                      <button className="btn btn-outline-danger" onClick={() => setToDelete(r)}><i className="bi bi-trash" /></button>
                    </div>
                  </td>
                </tr>
              ))}
              {data.items.length === 0 && <tr><td colSpan={8}><div className="empty-state">{t('mat_empty')}</div></td></tr>}
            </tbody>
          </table>
        </div>
        {Math.ceil(data.total / data.per_page) > 1 && (
          <div className="d-flex justify-content-between align-items-center px-3 py-2 border-top">
            <small className="text-muted">{t('page_info', { c: data.page, p: Math.ceil(data.total / data.per_page) })}</small>
            <div className="btn-group btn-group-sm">
              <button className="btn btn-outline-secondary" disabled={data.page <= 1}
                onClick={() => setFilters({ ...filters, page: data.page - 1 })}>{t('prev_btn')}</button>
              <button className="btn btn-outline-secondary" disabled={data.page >= Math.ceil(data.total / data.per_page)}
                onClick={() => setFilters({ ...filters, page: data.page + 1 })}>{t('next_btn')}</button>
            </div>
          </div>
        )}
      </div>

      <ConfirmDialog open={!!toDelete} title={t('del')}
        message={t('mat_del_q', { a: money(toDelete?.amount) })}
        confirmLabel={t('del')} onConfirm={remove} onClose={() => setToDelete(null)} />
    </div>
  )
}
