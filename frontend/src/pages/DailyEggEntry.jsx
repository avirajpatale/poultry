import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { get, post } from '../services/api'
import { useToast } from '../context/ToastContext'
import { useLang } from '../i18n.jsx'
import PageHeader from '../components/PageHeader'
import { today, num } from '../utils/format'

const EMPTY = {
  record_date: today(), opening_eggs: '', eggs_collected: '',
  eggs_purchased: '', eggs_sold: '', eggs_damaged: '', notes: '',
}

export default function DailyEggEntry() {
  const toast = useToast()
  const { t } = useLang()
  const [sp, setSp] = useSearchParams()
  const [form, setForm] = useState(EMPTY)
  const [editing, setEditing] = useState(false)
  const [busy, setBusy] = useState(false)
  const [prevHint, setPrevHint] = useState('')

  const load = useCallback(async (date) => {
    try {
      const rec = await get(`/layers/records/${date}`)
      setEditing(true)
      setForm({
        record_date: rec.record_date,
        opening_eggs: rec.opening_eggs,
        eggs_collected: rec.eggs_collected,
        eggs_purchased: rec.eggs_purchased || '',
        eggs_sold: rec.eggs_sold || '',
        eggs_damaged: rec.eggs_damaged || '',
        notes: rec.notes || '',
      })
    } catch {
      // no record for this date — find previous day's closing as default opening
      setEditing(false)
      try {
        const d = await get('/layers/records', { to: date, per_page: 1 })
        const prev = (d.items || []).find((r) => r.record_date < date)
        setForm({ ...EMPTY, record_date: date, opening_eggs: prev ? prev.closing_eggs : 0 })
        setPrevHint(prev ? prev.record_date : '')
      } catch {
        setForm({ ...EMPTY, record_date: date, opening_eggs: 0 })
        setPrevHint('')
      }
    }
  }, [])

  useEffect(() => { load(sp.get('date') || today()) }, [load, sp])

  const set = (k) => (e) => {
    const v = e.target.value
    if (k === 'record_date') { setSp({ date: v }); return }
    setForm((f) => ({ ...f, [k]: v }))
  }

  const n = (v) => Number(v) || 0
  const closing = n(form.opening_eggs) + n(form.eggs_collected) + n(form.eggs_purchased)
    - n(form.eggs_sold) - n(form.eggs_damaged)

  const save = async () => {
    if (!form.record_date || form.eggs_collected === '') return toast.error(t('ee_required'))
    setBusy(true)
    try {
      await post('/layers/records', {
        record_date: form.record_date,
        // blank opening → let the server use the previous day's closing
        ...(form.opening_eggs === '' ? {} : { opening_eggs: n(form.opening_eggs) }),
        eggs_collected: n(form.eggs_collected),
        eggs_purchased: n(form.eggs_purchased),
        eggs_sold: n(form.eggs_sold),
        eggs_damaged: n(form.eggs_damaged),
        notes: form.notes,
      })
      toast.success(t('ee_saved'))
      // clear the form, keep the selected date
      setForm({ ...EMPTY, record_date: form.record_date })
      setEditing(false)
      setPrevHint('')
    } catch (e) { toast.error(e.message) }
    setBusy(false)
  }

  return (
    <div className="mx-auto" style={{ maxWidth: 640 }}>
      <PageHeader title={t('ee_title')}
        subtitle={editing ? t('ee_edit_of', { d: form.record_date }) : ''} />

      <div className="content-card p-4">
        <div className="mb-3">
          <label className="form-label">{t('date')}</label>
          <input type="date" className="form-control form-control-lg"
            value={form.record_date} onChange={set('record_date')} />
        </div>
        <div className="mb-3">
          <label className="form-label">{t('ee_opening')} {prevHint && <small className="text-muted">{t('ee_prev')} {prevHint}</small>}</label>
          <input type="number" min="0" inputMode="numeric" className="form-control form-control-lg"
            value={form.opening_eggs} onChange={set('opening_eggs')} />
        </div>
        <div className="mb-3">
          <label className="form-label fw-bold">{t('ee_produced')} *</label>
          <input type="number" min="0" inputMode="numeric" className="form-control form-control-lg" autoFocus
            value={form.eggs_collected} onChange={set('eggs_collected')} />
        </div>
        <div className="mb-3">
          <label className="form-label">{t('ee_received')}</label>
          <input type="number" min="0" inputMode="numeric" className="form-control form-control-lg"
            value={form.eggs_purchased} onChange={set('eggs_purchased')} />
        </div>
        <div className="mb-3">
          <label className="form-label">{t('ee_sold')}</label>
          <input type="number" min="0" inputMode="numeric" className="form-control form-control-lg"
            value={form.eggs_sold} onChange={set('eggs_sold')} />
        </div>
        <div className="mb-3">
          <label className="form-label">{t('ee_damaged')}</label>
          <input type="number" min="0" inputMode="numeric" className="form-control form-control-lg"
            value={form.eggs_damaged} onChange={set('eggs_damaged')} />
        </div>
        <div className="mb-3">
          <label className="form-label">{t('notes')}</label>
          <input className="form-control" value={form.notes} onChange={set('notes')} />
        </div>

        <div className="alert alert-success d-flex justify-content-between align-items-center fs-5">
          <span>{t('ee_closing')}</span>
          <strong className="fs-3">{num(closing)}</strong>
        </div>

        <button className="btn btn-success btn-lg w-100" disabled={busy} onClick={save}>
          {busy ? t('sf_saving') : t('save')}
        </button>
      </div>
    </div>
  )
}
