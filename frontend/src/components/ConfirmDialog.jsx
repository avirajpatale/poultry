import Modal from './Modal'
import { useLang } from '../i18n.jsx'

export default function ConfirmDialog({ open, title, message, confirmLabel, danger = true, onConfirm, onClose }) {
  const { t } = useLang()
  return (
    <Modal open={open} title={title || t('confirm_lbl')} onClose={onClose} size="sm"
      footer={<>
        <button className="btn btn-outline-secondary" onClick={onClose}>{t('cancel')}</button>
        <button className={`btn ${danger ? 'btn-danger' : 'btn-primary'}`} onClick={onConfirm}>
          {confirmLabel || t('confirm_lbl')}
        </button>
      </>}>
      <p className="mb-0">{message}</p>
    </Modal>
  )
}
