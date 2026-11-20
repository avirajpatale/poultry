import { useCallback, useEffect, useState } from 'react'
import { get, post, put, del } from '../services/api'
import { useToast } from '../context/ToastContext'
import { useLang } from '../i18n.jsx'
import PageHeader from '../components/PageHeader'
import ConfirmDialog from '../components/ConfirmDialog'
import { today, monthStart, money } from '../utils/format'

const EMPTY = { txn_date: today(), type: 'expense', category: '', description: '', amount: '', payment_mode: 'cash' }
const CREDIT_EMPTY = { txn_date: today(), person: '', direction: 'they_owe_me', amount: '', note: '' }

// map backend category names -> i18n keys
const CAT_KEY = {
  'Egg Sale': 'cat_egg_sale', 'Bird Sale': 'cat_bird_sale', 'Manure Sale': 'cat_manure',
  'Other Income': 'cat_other_income', 'Feed': 'cat_feed', 'Medicine': 'cat_medicine',
  'Chicks': 'cat_chicks', 'Labour': 'cat_labour', 'Electricity': 'cat_electricity',
  'Transport': 'cat_transport', 'Other': 'cat_other',
}

export default function Money() {
  const toast = useToast()
  const { t } = useLang()
  const [cats, setCats] = useState({ income: [], expense: [] })
  const [form, setForm] = useState(EMPTY)
  const [editing, setEditing] = useState(null)
  const [busy, setBusy] = useState(false)
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), type: '', page: 1 })
  const [data, setData] = useState({ items: [], income: 0, expense: 0, balance: 0, total: 0, page: 1, per_page: 20 })
  const [toDelete, setToDelete] = useState(null)
  const [credit, setCredit] = useState({ items: [], summary: [] })
  const [cForm, setCForm] = useState(CREDIT_EMPTY)

  const loadCredit = useCallback(() => {
    get('/credit').then(setCredit).catch(() => {})
  }, [])
  useEffect(() => { loadCredit() }, [loadCredit])

  const cset = (k) => (e) => setCForm({ ...cForm, [k]: e.target.value })

  const saveCredit = async () => {
    if (!cForm.person.trim() || !cForm.amount || Number(cForm.amount) <= 0)
      return toast.error(t('cr_required'))
    try {
      await post('/credit', cForm)
      toast.success(t('cr_saved')); setCForm(CREDIT_EMPTY); loadCredit()
    } catch (e) { toast.error(e.message) }
  }
  const settleCredit = async (r, v) => {
    try { await put(`/credit/${r.id}`, { settled: v }); loadCredit() }
    catch (e) { toast.error(e.message) }
  }
  const delCredit = async (r) => {
    try { await del(`/credit/${r.id}`); loadCredit() }
    catch (e) { toast.error(e.message) }
  }

  const load = useCallback(async () => {
    try { setData(await get('/money', { ...filters, per_page: 20 })) }
    catch (e) { toast.error(e.message) }
  }, [filters, toast])
  useEffect(() => { load() }, [load])
  useEffect(() => { get('/money/categories').then(setCats).catch(() => {}) }, [])

  const set = (k) => (e) => {
    const patch = { [k]: e.target.value }
    if (k === 'type') patch.category = ''
    setForm({ ...form, ...patch })
  }

  const catLabel = (name) => CAT_KEY[name] ? t(CAT_KEY[name]) : name
  const catList = cats[form.type] || []

  const save = async () => {
    if (!form.amount || Number(form.amount) <= 0) return toast.error(t('mn_amount'))
    setBusy(true)
    try {
      const payload = { ...form, category: form.category || 'Other' }
      if (editing) { await put(`/money/${editing.id}`, payload); toast.success(t('mn_updated')) }
      else { await post('/money', payload); toast.success(t('mn_saved')) }
      setForm(EMPTY); setEditing(null); load()
    } catch (e) { toast.error(e.message) }
    setBusy(false)
  }

  const startEdit = (r) => {
    setEditing(r)
    setForm({
      txn_date: r.txn_date, type: r.type, category: r.category,
      description: r.description || '', amount: r.amount, payment_mode: r.payment_mode,
    })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const remove = async () => {
    try {
      await del(`/money/${toDelete.id}`)
      toast.success(t('mn_deleted')); setToDelete(null); load()
    } catch (e) { toast.error(e.message) }
  }

  return (
    <div className="mx-auto" style={{ maxWidth: 900 }}>
      <PageHeader title={t('mn_title')} />

      {/* entry form */}
      <div className="content-card p-4 mb-3">
        {editing && <div className="alert alert-info py-2 small">{t('mn_editing')}</div>}
        <div className="row g-3">
          <div className="col-md-4">
            <label className="form-label">{t('date')}</label>
            <input type="date" className="form-control form-control-lg" value={form.txn_date} onChange={set('txn_date')} />
          </div>
          <div className="col-md-8">
            <label className="form-label">{t('type_lbl')}</label>
            <div className="btn-group w-100">
              <button type="button" onClick={() => set('type')({ target: { value: 'income' } })}
                className={`btn btn-lg ${form.type === 'income' ? 'btn-success' : 'btn-outline-secondary'}`}>
                <i className="bi bi-arrow-down-circle" /> {t('mn_income')}</button>
              <button type="button" onClick={() => set('type')({ target: { value: 'expense' } })}
                className={`btn btn-lg ${form.type === 'expense' ? 'btn-danger' : 'btn-outline-secondary'}`}>
                <i className="bi bi-arrow-up-circle" /> {t('mn_expense')}</button>
            </div>
          </div>
          <div className="col-md-6">
            <label className="form-label">{t('mn_category')}</label>
            <select className="form-select form-select-lg" value={form.category} onChange={set('category')}>
              <option value="">{t('select_lbl')}</option>
              {catList.map((c) => <option key={c} value={c}>{catLabel(c)}</option>)}
            </select>
          </div>
          <div className="col-md-6">
            <label className="form-label">{t('mn_amount')} *</label>
            <input type="number" min="0" step="0.01" inputMode="decimal" className="form-control form-control-lg"
              value={form.amount} onChange={set('amount')} />
          </div>
          <div className="col-md-6">
            <label className="form-label">{t('details_lbl')}</label>
            <input className="form-control form-control-lg" value={form.description} onChange={set('description')} />
          </div>
          <div className="col-md-6">
            <label className="form-label">{t('mn_mode')}</label>
            <select className="form-select form-select-lg" value={form.payment_mode} onChange={set('payment_mode')}>
              <option value="cash">{t('cash')}</option>
              <option value="upi">{t('upi')}</option>
              <option value="bank">{t('bank')}</option>
            </select>
          </div>
        </div>
        <div className="d-grid mt-3">
          <button className="btn btn-success btn-lg" disabled={busy} onClick={save}>
            {busy ? t('sf_saving') : (editing ? t('mn_update') : t('mn_add'))}
          </button>
        </div>
      </div>

      {/* totals */}
      <div className="row g-3 mb-3 money-totals">
        <div className="col-4"><div className="big-card">
          <div className="big-label">{t('mr_income')}</div>
          <div className="big-num" style={{ color: '#2e7d32', fontSize: '1.4rem' }}>{money(data.income)}</div>
        </div></div>
        <div className="col-4"><div className="big-card">
          <div className="big-label">{t('mr_expense')}</div>
          <div className="big-num" style={{ color: '#c62828', fontSize: '1.4rem' }}>{money(data.expense)}</div>
        </div></div>
        <div className="col-4"><div className="big-card">
          <div className="big-label">{t('mn_balance')}</div>
          <div className="big-num" style={{ color: data.balance >= 0 ? '#1565c0' : '#c62828', fontSize: '1.4rem' }}>{money(data.balance)}</div>
        </div></div>
      </div>

      {/* history */}
      <div className="content-card">
        <div className="card-header py-2 px-3 d-flex gap-2 flex-wrap align-items-center">
          <strong className="me-auto">{t('mn_history')}</strong>
          <input type="date" className="form-control form-control-sm w-auto" value={filters.from}
            onChange={(e) => setFilters({ ...filters, from: e.target.value, page: 1 })} />
          <span className="text-muted small">{t('to_lbl')}</span>
          <input type="date" className="form-control form-control-sm w-auto" value={filters.to}
            onChange={(e) => setFilters({ ...filters, to: e.target.value, page: 1 })} />
          <select className="form-select form-select-sm w-auto" value={filters.type}
            onChange={(e) => setFilters({ ...filters, type: e.target.value, page: 1 })}>
            <option value="">{t('all')}</option>
            <option value="income">{t('mn_income')}</option>
            <option value="expense">{t('mn_expense')}</option>
          </select>
        </div>
        <div className="table-responsive">
          <table className="table table-hover mb-0 tbl-cards">
            <thead>
              <tr><th>{t('date')}</th><th>{t('type_lbl')}</th><th>{t('mn_category')}</th>
                <th>{t('details_lbl')}</th><th>{t('col_by')}</th>
                <th className="text-end">{t('amount')}</th><th></th></tr>
            </thead>
            <tbody>
              {data.items.map((r) => (
                <tr key={r.id}>
                  <td data-label={t('date')}>{r.txn_date}</td>
                  <td data-label={t('type_lbl')}>{r.type === 'income'
                    ? <span className="badge-soft badge-paid">{t('mn_income')}</span>
                    : <span className="badge-soft badge-credit">{t('mn_expense')}</span>}</td>
                  <td data-label={t('mn_category')}>{catLabel(r.category)}</td>
                  <td data-label={t('details_lbl')} className="small text-muted">{r.description || '—'}</td>
                  <td data-label={t('col_by')} className="text-capitalize">{r.payment_mode}</td>
                  <td data-label={t('amount')} className={`text-end fw-semibold ${r.type === 'income' ? 'text-success' : 'text-danger'}`}>
                    {r.type === 'income' ? '+' : '−'}{money(r.amount)}</td>
                  <td data-label="" className="text-end">
                    <div className="btn-group btn-group-sm">
                      <button className="btn btn-outline-primary" onClick={() => startEdit(r)}><i className="bi bi-pencil" /></button>
                      <button className="btn btn-outline-danger" onClick={() => setToDelete(r)}><i className="bi bi-trash" /></button>
                    </div>
                  </td>
                </tr>
              ))}
              {data.items.length === 0 && <tr><td colSpan={7}><div className="empty-state">{t('mn_empty')}</div></td></tr>}
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

      {/* उधारी / credit register */}
      <details className="content-card mt-3" open={credit.summary.length > 0}>
        <summary className="card-header py-2 px-3 fw-semibold" style={{ cursor: 'pointer', listStyle: 'none' }}>
          <i className="bi bi-journal-bookmark me-1" /> {t('cr_title')}
          {credit.summary.length > 0 && <span className="badge-soft badge-credit ms-2">{credit.summary.length}</span>}
        </summary>
        <div className="p-3 border-bottom">
          <div className="row g-2 align-items-end">
            <div className="col-6 col-md-2">
              <label className="form-label small mb-1">{t('date')}</label>
              <input type="date" className="form-control" value={cForm.txn_date} onChange={cset('txn_date')} />
            </div>
            <div className="col-6 col-md-3">
              <label className="form-label small mb-1">{t('cr_person')} *</label>
              <input className="form-control" value={cForm.person} onChange={cset('person')} />
            </div>
            <div className="col-12 col-md-3">
              <div className="btn-group w-100">
                <button type="button" onClick={() => setCForm({ ...cForm, direction: 'they_owe_me' })}
                  className={`btn ${cForm.direction === 'they_owe_me' ? 'btn-success' : 'btn-outline-secondary'}`}>
                  {t('cr_they')}</button>
                <button type="button" onClick={() => setCForm({ ...cForm, direction: 'i_owe_them' })}
                  className={`btn ${cForm.direction === 'i_owe_them' ? 'btn-danger' : 'btn-outline-secondary'}`}>
                  {t('cr_i_owe')}</button>
              </div>
            </div>
            <div className="col-6 col-md-2">
              <label className="form-label small mb-1">{t('amount')} *</label>
              <input type="number" min="0" step="0.01" inputMode="decimal" className="form-control"
                value={cForm.amount} onChange={cset('amount')} />
            </div>
            <div className="col-6 col-md-2">
              <button className="btn btn-primary w-100" onClick={saveCredit}>{t('add')}</button>
            </div>
          </div>
        </div>
        {credit.summary.length > 0 && (
          <div className="px-3 pt-2 d-flex flex-wrap gap-2">
            {credit.summary.map((s) => (
              <span key={s.person} className={`badge-soft ${Number(s.net) > 0 ? 'badge-paid' : 'badge-credit'}`}>
                {s.person}: {Number(s.net) > 0 ? t('cr_owes_me', { a: money(s.net) }) : t('cr_i_owes', { a: money(Math.abs(s.net)) })}
              </span>
            ))}
          </div>
        )}
        <div className="table-responsive">
          <table className="table table-hover mb-0 tbl-cards">
            <tbody>
              {credit.items.filter((r) => !Number(r.settled)).slice(0, 20).map((r) => (
                <tr key={r.id}>
                  <td data-label={t('date')}>{r.txn_date}</td>
                  <td data-label={t('cr_person')} className="fw-semibold">{r.person}</td>
                  <td data-label={t('type_lbl')}>
                    <span className={`badge-soft ${r.direction === 'they_owe_me' ? 'badge-paid' : 'badge-credit'}`}>
                      {t(r.direction === 'they_owe_me' ? 'cr_they' : 'cr_i_owe')}
                    </span>
                  </td>
                  <td data-label={t('amount')} className="text-end fw-semibold">{money(r.amount)}</td>
                  <td data-label="" className="text-end">
                    <div className="btn-group btn-group-sm">
                      <button className="btn btn-outline-success" title={t('cr_settle')}
                        onClick={() => settleCredit(r, 1)}><i className="bi bi-check-lg" /></button>
                      <button className="btn btn-outline-danger" onClick={() => delCredit(r)}><i className="bi bi-trash" /></button>
                    </div>
                  </td>
                </tr>
              ))}
              {credit.items.filter((r) => !Number(r.settled)).length === 0 &&
                <tr><td><div className="empty-state">{t('cr_none')}</div></td></tr>}
            </tbody>
          </table>
        </div>
      </details>

      <ConfirmDialog open={!!toDelete} title={t('del')}
        message={t('mn_del_q', { a: money(toDelete?.amount) })}
        confirmLabel={t('del')} onConfirm={remove} onClose={() => setToDelete(null)} />
    </div>
  )
}
