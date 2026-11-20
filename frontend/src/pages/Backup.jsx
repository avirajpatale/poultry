import { useEffect, useRef, useState } from 'react'
import { get, post, put } from '../services/api'
import { useToast } from '../context/ToastContext'
import { useLang } from '../i18n.jsx'
import PageHeader from '../components/PageHeader'
import ConfirmDialog from '../components/ConfirmDialog'

/** Download all my records as a JSON file; restore replaces my data from one. */
export default function Backup() {
  const { t } = useLang()
  const toast = useToast()
  const fileRef = useRef(null)
  const [pending, setPending] = useState(null)   // parsed backup awaiting confirm
  const [busy, setBusy] = useState(false)
  const [hasPin, setHasPin] = useState(!!localStorage.getItem('farm_pin'))
  const [pin, setPinInput] = useState('')

  const setPin = () => {
    if (!/^\d{4}$/.test(pin)) return toast.error(t('pin_bad'))
    localStorage.setItem('farm_pin', pin)
    setHasPin(true); setPinInput('')
    toast.success(t('pin_set_ok'))
  }
  const removePin = () => {
    if (pin !== localStorage.getItem('farm_pin')) return toast.error(t('pin_wrong'))
    localStorage.removeItem('farm_pin')
    setHasPin(false); setPinInput('')
    toast.success(t('pin_removed'))
  }

  // ---- vaccine reminder ----
  const [vacDate, setVacDate] = useState('')
  const [vacNote, setVacNote] = useState('')
  useEffect(() => {
    get('/prefs').then((d) => {
      setVacDate(d.prefs?.vaccine_date || '')
      setVacNote(d.prefs?.vaccine_note || '')
    }).catch(() => {})
  }, [])
  const saveVac = async () => {
    try {
      await put('/prefs', { key: 'vaccine_date', value: vacDate })
      await put('/prefs', { key: 'vaccine_note', value: vacNote })
      toast.success(t('vac_saved'))
    } catch (e) { toast.error(e.message) }
  }

  const download = async () => {
    setBusy(true)
    try {
      const data = await get('/backup')
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
      const a = document.createElement('a')
      a.href = URL.createObjectURL(blob)
      a.download = `poultry-backup-${new Date().toISOString().slice(0, 10)}.json`
      a.click()
      URL.revokeObjectURL(a.href)
      toast.success(t('bk_done_dl'))
    } catch (e) { toast.error(e.message) }
    setBusy(false)
  }

  const pickFile = (e) => {
    const f = e.target.files?.[0]
    e.target.value = ''
    if (!f) return
    const rd = new FileReader()
    rd.onload = () => {
      try {
        const parsed = JSON.parse(rd.result)
        if (!parsed || typeof parsed.data !== 'object') throw new Error('bad')
        setPending(parsed)
      } catch { toast.error(t('bk_bad')) }
    }
    rd.readAsText(f)
  }

  const restore = async () => {
    setBusy(true)
    try {
      const r = await post('/backup/restore', { data: pending.data })
      const n = Object.values(r.restored || {}).reduce((a, b) => a + b, 0)
      toast.success(t('bk_done_rs', { n }))
    } catch (e) { toast.error(e.message) }
    setPending(null); setBusy(false)
  }

  return (
    <div className="mx-auto" style={{ maxWidth: 640 }}>
      <PageHeader title={t('bk_title')} subtitle={t('bk_desc')} />

      <div className="content-card p-4 mb-3 text-center">
        <div className="big-icon mx-auto mb-2" style={{ background: '#dbeafe', color: '#1d4ed8' }}>
          <i className="bi bi-cloud-download" />
        </div>
        <p className="text-muted small">{t('bk_dl_hint')}</p>
        <button className="btn btn-primary btn-lg w-100" onClick={download} disabled={busy}>
          <i className="bi bi-download me-1" /> {t('bk_download')}
        </button>
      </div>

      <div className="content-card p-4 text-center">
        <div className="big-icon mx-auto mb-2" style={{ background: '#fef3c7', color: '#b45309' }}>
          <i className="bi bi-cloud-upload" />
        </div>
        <p className="text-muted small">{t('bk_rs_hint')}</p>
        <button className="btn btn-outline-warning btn-lg w-100" onClick={() => fileRef.current?.click()} disabled={busy}>
          <i className="bi bi-upload me-1" /> {t('bk_restore')}
        </button>
        <input ref={fileRef} type="file" accept=".json,application/json" className="d-none" onChange={pickFile} />
      </div>

      {/* 4-digit app lock */}
      <div className="content-card p-4 mt-3 text-center">
        <div className="big-icon mx-auto mb-2" style={{ background: '#f3e8ff', color: '#7e22ce' }}>
          <i className="bi bi-shield-lock" />
        </div>
        <p className="text-muted small">{hasPin ? t('pin_on_hint') : t('pin_off_hint')}</p>
        <div className="d-flex gap-2">
          <input type="password" inputMode="numeric" maxLength={4}
            className="form-control form-control-lg text-center"
            placeholder="••••" value={pin} onChange={(e) => setPinInput(e.target.value.replace(/\D/g, ''))} />
          {hasPin
            ? <button className="btn btn-outline-danger btn-lg" onClick={removePin}>{t('pin_remove')}</button>
            : <button className="btn btn-purple btn-lg" style={{ background: '#7e22ce', color: '#fff' }} onClick={setPin}>{t('pin_set')}</button>}
        </div>
      </div>

      {/* vaccine / medicine reminder */}
      <div className="content-card p-4 mt-3">
        <div className="d-flex align-items-center gap-2 mb-2">
          <div className="big-icon" style={{ background: '#fee2e2', color: '#b91c1c' }}>
            <i className="bi bi-capsule" />
          </div>
          <strong>{t('vac_title')}</strong>
        </div>
        <div className="row g-2">
          <div className="col-6">
            <label className="form-label small mb-1">{t('date')}</label>
            <input type="date" className="form-control form-control-lg"
              value={vacDate} onChange={(e) => setVacDate(e.target.value)} />
          </div>
          <div className="col-6">
            <label className="form-label small mb-1">{t('vac_note')}</label>
            <input className="form-control form-control-lg" value={vacNote}
              onChange={(e) => setVacNote(e.target.value)} />
          </div>
        </div>
        <button className="btn btn-danger btn-lg w-100 mt-2" onClick={saveVac}>{t('save')}</button>
      </div>

      <ConfirmDialog open={!!pending} title={t('bk_restore')} message={t('bk_confirm')}
        confirmLabel={t('bk_restore')} onConfirm={restore} onClose={() => setPending(null)} />
    </div>
  )
}
