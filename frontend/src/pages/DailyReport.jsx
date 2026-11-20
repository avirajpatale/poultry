import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { get, del } from '../services/api'
import { useToast } from '../context/ToastContext'
import { useLang } from '../i18n.jsx'
import PageHeader from '../components/PageHeader'
import ConfirmDialog from '../components/ConfirmDialog'
import { today, num } from '../utils/format'

const COLS = [
  ['record_date', 'date'], ['opening_eggs', 'col_start'], ['eggs_collected', 'ee_produced'],
  ['eggs_purchased', 'ee_received'], ['eggs_sold', 'ee_sold'],
  ['eggs_damaged', 'ee_damaged'], ['closing_eggs', 'ee_closing'],
]

export default function DailyReport() {
  const { t, lang } = useLang()
  const toast = useToast()
  const [date, setDate] = useState(today())
  const [rec, setRec] = useState(undefined)  // undefined = loading, null = none
  const [recent, setRecent] = useState([])
  const [confirmDel, setConfirmDel] = useState(false)

  const loadDay = useCallback(async (d) => {
    setRec(undefined)
    try { setRec(await get(`/layers/records/${d}`)) }
    catch { setRec(null) }
  }, [])

  useEffect(() => { loadDay(date) }, [date, loadDay])
  useEffect(() => {
    get('/layers/records', { per_page: 15 }).then((d) => setRecent(d.items)).catch(() => {})
  }, [rec])

  const remove = async () => {
    try {
      await del(`/layers/records/${date}`)
      toast.success(t('dr_deleted'))
      setConfirmDel(false); setRec(null)
    } catch (e) { toast.error(e.message); setConfirmDel(false) }
  }

  const fmt = (d) => new Date(d + 'T00:00:00').toLocaleDateString(lang === 'mr' ? 'mr-IN' : 'en-IN',
    { weekday: 'short', day: 'numeric', month: 'short' })

  const share = () => {
    const lines = [
      `🐔 ${t('dr_title')} — ${fmt(date)}`,
      ...COLS.slice(1).map(([k, l]) => `${t(l)}: ${num(rec[k])}`),
      ...(rec.notes ? [`${t('notes')}: ${rec.notes}`] : []),
    ]
    const text = lines.join('\n')
    if (navigator.share) navigator.share({ text }).catch(() => {})
    else window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank')
  }

  return (
    <div className="mx-auto" style={{ maxWidth: 900 }}>
      <PageHeader title={t('dr_title')} subtitle={t('dr_view_edit')} />

      <div className="content-card p-3 mb-3">
        <div className="row g-2 align-items-end">
          <div className="col-6 col-md-4">
            <label className="form-label mb-1">{t('dr_pick')}</label>
            <input type="date" className="form-control form-control-lg" value={date}
              onChange={(e) => setDate(e.target.value)} />
          </div>
          {rec && (
            <>
              <div className="col-2 col-md-3">
                <Link to={`/eggs?date=${date}`} className="btn btn-outline-primary btn-lg w-100">
                  <i className="bi bi-pencil" /> {t('edit')}
                </Link>
              </div>
              <div className="col-2 col-md-2">
                <button className="btn btn-outline-success btn-lg w-100" onClick={share}>
                  <i className="bi bi-whatsapp" /> <span className="d-none d-sm-inline">{t('share')}</span>
                </button>
              </div>
              <div className="col-2 col-md-2">
                <button className="btn btn-outline-danger btn-lg w-100" onClick={() => setConfirmDel(true)}>
                  <i className="bi bi-trash" /> <span className="d-none d-sm-inline">{t('dr_del')}</span>
                </button>
              </div>
            </>
          )}
        </div>

        {rec === undefined && <div className="text-center py-4"><div className="spinner-border text-success" /></div>}
        {rec === null && <div className="empty-state">{t('dr_no_entry')}</div>}
        {rec && (
          <div className="table-responsive mt-3">
            <table className="table table-bordered mb-0">
              <tbody>
                {COLS.slice(1).map(([k, l]) => (
                  <tr key={k}>
                    <th className="w-50 bg-light">{t(l)}</th>
                    <td className="fs-5 fw-semibold text-end">{num(rec[k])}</td>
                  </tr>
                ))}
                {[['birds_count', 'ee_birds'], ['mortality', 'ee_mortality'], ['feed_used_kg', 'ee_feed']]
                  .filter(([k]) => rec[k] !== null && rec[k] !== undefined && Number(rec[k]) > 0)
                  .map(([k, l]) => (
                    <tr key={k}>
                      <th className="bg-light">{t(l)}</th>
                      <td className="text-end">{num(rec[k])}</td>
                    </tr>
                  ))}
                {rec.notes && <tr><th className="bg-light">{t('notes')}</th><td>{rec.notes}</td></tr>}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="content-card">
        <div className="card-header py-2 px-3">{t('dr_recent')}</div>
        <div className="table-responsive">
          <table className="table table-hover mb-0 tbl-cards">
            <thead>
              <tr>
                <th>{t('date')}</th>
                <th className="text-end">{t('ee_produced')}</th>
                <th className="text-end">{t('ee_sold')}</th>
                <th className="text-end">{t('ee_damaged')}</th>
                <th className="text-end">{t('ee_closing')}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {recent.map((r) => (
                <tr key={r.id} className={r.record_date === date ? 'table-success' : ''}
                  style={{ cursor: 'pointer' }} onClick={() => setDate(r.record_date)}>
                  <td data-label={t('date')}>{fmt(r.record_date)}</td>
                  <td data-label={t('ee_produced')} className="text-end">{num(r.eggs_collected)}</td>
                  <td data-label={t('ee_sold')} className="text-end">{num(r.eggs_sold)}</td>
                  <td data-label={t('ee_damaged')} className="text-end">{num(r.eggs_damaged)}</td>
                  <td data-label={t('ee_closing')} className="text-end fw-semibold">{num(r.closing_eggs)}</td>
                  <td data-label="" className="text-end">
                    <Link to={`/eggs?date=${r.record_date}`} className="btn btn-sm btn-outline-primary"
                      onClick={(e) => e.stopPropagation()}>{t('edit')}</Link>
                  </td>
                </tr>
              ))}
              {recent.length === 0 && <tr><td colSpan={6}><div className="empty-state">{t('mr_empty')}</div></td></tr>}
            </tbody>
          </table>
        </div>
      </div>
      <ConfirmDialog open={confirmDel} title={t('dr_del')}
        message={t('dr_del_q', { d: date })}
        confirmLabel={t('del')} onConfirm={remove} onClose={() => setConfirmDel(false)} />
    </div>
  )
}
