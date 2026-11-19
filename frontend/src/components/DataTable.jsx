import { useState } from 'react'
import { useLang } from '../i18n.jsx'

/**
 * DataTable — columns: [{key, label, render?, className?}]
 * Server pagination optional via `pagination` {total,page,pages,onPage}.
 */
export default function DataTable({ columns, rows, loading, empty, rowClass, pagination, footer }) {
  const { t } = useLang()
  const [page, setPage] = useState(1)
  const perPage = 15
  const clientPaged = !pagination && rows.length > perPage
  const visible = clientPaged ? rows.slice((page - 1) * perPage, page * perPage) : rows
  const pages = pagination?.pages ?? Math.ceil(rows.length / perPage)
  const curPage = pagination?.page ?? page

  const goPage = (p) => (pagination ? pagination.onPage(p) : setPage(p))

  return (
    <div>
      <div className="table-responsive">
        <table className="table table-hover mb-0">
          <thead>
            <tr>{columns.map((c) => <th key={c.key} className={c.className}>{c.label}</th>)}</tr>
          </thead>
          <tbody>
            {loading && (
              <tr><td colSpan={columns.length} className="text-center py-4">
                <span className="spinner-border spinner-border-sm me-2" />{t('loading_lbl')}
              </td></tr>
            )}
            {!loading && visible.length === 0 && (
              <tr><td colSpan={columns.length}>
                <div className="empty-state">{empty || t('empty_default')}</div>
              </td></tr>
            )}
            {!loading && visible.map((r, i) => (
              <tr key={r.id ?? i} className={rowClass ? rowClass(r) : ''}>
                {columns.map((c) => (
                  <td key={c.key} className={c.className}>
                    {c.render ? c.render(r) : r[c.key]}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
          {footer}
        </table>
      </div>
      {(clientPaged || pagination) && pages > 1 && (
        <div className="d-flex justify-content-between align-items-center px-3 py-2 border-top">
          <small className="text-muted">{t('page_info', { c: curPage, p: pages })}</small>
          <div className="btn-group btn-group-sm">
            <button className="btn btn-outline-secondary" disabled={curPage <= 1} onClick={() => goPage(curPage - 1)}>{t('prev_btn')}</button>
            <button className="btn btn-outline-secondary" disabled={curPage >= pages} onClick={() => goPage(curPage + 1)}>{t('next_btn')}</button>
          </div>
        </div>
      )}
    </div>
  )
}
