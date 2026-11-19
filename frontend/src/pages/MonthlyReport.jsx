import { useCallback, useEffect, useState } from 'react'
import { get } from '../services/api'
import { useLang } from '../i18n.jsx'
import PageHeader from '../components/PageHeader'
import { money, num } from '../utils/format'

const now = new Date()

export default function MonthlyReport() {
  const { t, lang } = useLang()
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [year, setYear] = useState(now.getFullYear())
  const [rows, setRows] = useState([])
  const [moneySum, setMoneySum] = useState({ income: 0, expense: 0 })
  const [mats, setMats] = useState({ summary: [], grand_total: 0 })
  const [loading, setLoading] = useState(true)

  const monthName = (m, y) =>
    new Date(y, m - 1, 1).toLocaleDateString(lang === 'mr' ? 'mr-IN' : 'en-IN',
      { month: 'long', year: 'numeric' })

  const load = useCallback(async () => {
    setLoading(true)
    const from = `${year}-${String(month).padStart(2, '0')}-01`
    const to = new Date(year, month, 0).toISOString().slice(0, 10)
    try {
      const [eggs, mn, mt] = await Promise.all([
        get('/layers/records', { from, to, per_page: 40 }),
        get('/money', { from, to, per_page: 1 }),
        get('/materials', { from, to, per_page: 1 }).catch(() => ({ summary: [], grand_total: 0 })),
      ])
      setRows([...eggs.items].sort((a, b) => a.record_date.localeCompare(b.record_date)))
      setMoneySum({ income: mn.income || 0, expense: mn.expense || 0 })
      setMats({ summary: mt.summary || [], grand_total: mt.grand_total || 0 })
    } catch { setRows([]) }
    setLoading(false)
  }, [month, year])

  useEffect(() => { load() }, [load])

  const sum = (k) => rows.reduce((s, r) => s + Number(r[k] || 0), 0)
  const endStock = rows.length ? rows[rows.length - 1].closing_eggs : 0
  const totalExpense = moneySum.expense + mats.grand_total   // incl. raw materials
  const net = moneySum.income - totalExpense

  const cards = [
    [t('mr_produced'), num(sum('eggs_collected')), '#2e7d32'],
    [t('mr_received'), num(sum('eggs_purchased')), '#1565c0'],
    [t('mr_sold'), num(sum('eggs_sold')), '#ef6c00'],
    [t('mr_damaged'), num(sum('eggs_damaged')), '#c62828'],
    [t('mr_end_stock'), num(endStock), '#6a1b9a'],
    [t('mr_income'), money(moneySum.income), '#2e7d32'],
    [t('mr_expense'), money(totalExpense), '#c62828'],
    [t('mr_net'), money(net), net >= 0 ? '#2e7d32' : '#c62828'],
  ]

  const years = []
  for (let y = now.getFullYear(); y >= now.getFullYear() - 4; y--) years.push(y)
  const months = Array.from({ length: 12 }, (_, i) => i + 1)

  return (
    <div className="mx-auto" style={{ maxWidth: 900 }}>
      <PageHeader title={t('mr_title')} subtitle={monthName(month, year)}
        actions={<button className="btn btn-outline-primary no-print" onClick={() => window.print()}>
          <i className="bi bi-printer" /> {t('mr_print')}</button>} />

      <div className="content-card p-3 mb-3 no-print">
        <div className="row g-2">
          <div className="col-6 col-md-3">
            <label className="form-label mb-1">{t('mr_month')}</label>
            <select className="form-select form-select-lg" value={month} onChange={(e) => setMonth(Number(e.target.value))}>
              {months.map((m) => (
                <option key={m} value={m}>
                  {new Date(2024, m - 1, 1).toLocaleDateString(lang === 'mr' ? 'mr-IN' : 'en-IN', { month: 'long' })}
                </option>
              ))}
            </select>
          </div>
          <div className="col-6 col-md-3">
            <label className="form-label mb-1">{t('mr_year')}</label>
            <select className="form-select form-select-lg" value={year} onChange={(e) => setYear(Number(e.target.value))}>
              {years.map((y) => <option key={y}>{y}</option>)}
            </select>
          </div>
        </div>
      </div>

      {loading && <div className="text-center py-4"><div className="spinner-border text-success" /></div>}

      {!loading && <>
        <h5 className="d-none d-print-block">{monthName(month, year)} — {t('mr_title')}</h5>
        <div className="row g-3 mb-3">
          {cards.map(([l, v, c]) => (
            <div className="col-6 col-md-3" key={l}>
              <div className="big-card">
                <div className="big-label">{l}</div>
                <div className="big-num" style={{ color: c, fontSize: '1.5rem' }}>{v}</div>
              </div>
            </div>
          ))}
        </div>

        <div className="content-card">
          <div className="card-header py-2 px-3">{t('mr_table')}</div>
          <div className="table-responsive">
            <table className="table table-hover mb-0 tbl-cards">
              <thead>
                <tr>
                  <th>{t('date')}</th><th className="text-end">{t('col_start')}</th>
                  <th className="text-end">{t('ee_produced')}</th><th className="text-end">{t('ee_received')}</th>
                  <th className="text-end">{t('ee_sold')}</th><th className="text-end">{t('ee_damaged')}</th>
                  <th className="text-end">{t('ee_closing')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td data-label={t('date')}>{r.record_date}</td>
                    <td data-label={t('col_start')} className="text-end">{num(r.opening_eggs)}</td>
                    <td data-label={t('ee_produced')} className="text-end">{num(r.eggs_collected)}</td>
                    <td data-label={t('ee_received')} className="text-end">{num(r.eggs_purchased)}</td>
                    <td data-label={t('ee_sold')} className="text-end">{num(r.eggs_sold)}</td>
                    <td data-label={t('ee_damaged')} className="text-end">{num(r.eggs_damaged)}</td>
                    <td data-label={t('ee_closing')} className="text-end fw-semibold">{num(r.closing_eggs)}</td>
                  </tr>
                ))}
                  {rows.length === 0 && <tr><td colSpan={7}><div className="empty-state">{t('mr_empty')}</div></td></tr>}
              </tbody>
              {rows.length > 0 && (
                <tfoot>
                  <tr className="fw-bold table-light">
                    <td>{t('total_lbl')}</td><td></td>
                    <td className="text-end">{num(sum('eggs_collected'))}</td>
                    <td className="text-end">{num(sum('eggs_purchased'))}</td>
                    <td className="text-end">{num(sum('eggs_sold'))}</td>
                    <td className="text-end">{num(sum('eggs_damaged'))}</td>
                    <td className="text-end">{num(endStock)}</td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </div>

        {/* material expense breakdown */}
        {mats.summary.length > 0 && (
          <div className="content-card mt-3">
            <div className="card-header py-2 px-3">{t('mr_materials')}</div>
            <div className="table-responsive">
              <table className="table mb-0 tbl-cards">
                <thead>
                  <tr><th>{t('mat_material')}</th>
                    <th className="text-end">{t('mat_qty')}</th><th>{t('mat_unit')}</th>
                    <th className="text-end">{t('mat_amt_total')}</th></tr>
                </thead>
                <tbody>
                  {mats.summary.map((s) => (
                    <tr key={s.category_id}>
                      <td data-label={t('mat_material')}>{s.name}</td>
                      <td data-label={t('mat_qty')} className="text-end">{num(s.qty)}</td>
                      <td data-label={t('mat_unit')}>{t('unit_' + s.unit.toLowerCase())}</td>
                      <td data-label={t('mat_amt_total')} className="text-end fw-semibold">{money(s.amount)}</td>
                    </tr>
                  ))}
                  <tr className="fw-bold table-light">
                    <td colSpan={3} data-label="">{t('total_lbl')}</td>
                    <td data-label={t('mat_amt_total')} className="text-end">{money(mats.grand_total)}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        )}
      </>}
    </div>
  )
}
