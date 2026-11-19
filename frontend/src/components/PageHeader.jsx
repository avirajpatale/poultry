export default function PageHeader({ title, subtitle, actions }) {
  return (
    <div className="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <div>
        <h4 className="mb-0">{title}</h4>
        {subtitle && <div className="text-muted small">{subtitle}</div>}
      </div>
      {actions && <div className="d-flex gap-2">{actions}</div>}
    </div>
  )
}
