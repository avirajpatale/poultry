/** Lightweight React modal — no bootstrap JS dependency. */
export default function Modal({ open, title, onClose, children, footer, size = 'md' }) {
  if (!open) return null
  const widths = { sm: 400, md: 560, lg: 780, xl: 1000 }
  return (
    <div className="modal-backdrop-custom" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal-panel" style={{ maxWidth: widths[size] }}>
        <div className="modal-head">
          <span>{title}</span>
          <button className="btn-close" onClick={onClose} />
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-foot">{footer}</div>}
      </div>
    </div>
  )
}
