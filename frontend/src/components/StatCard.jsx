export default function StatCard({ label, value, icon, tone = '#2e7d32', sub }) {
  return (
    <div className="stat-card d-flex justify-content-between align-items-center">
      <div>
        <div className="stat-label">{label}</div>
        <div className="stat-value">{value}</div>
        {sub && <div className="small text-muted">{sub}</div>}
      </div>
      {icon && (
        <div className="stat-icon" style={{ background: tone + '1a', color: tone }}>
          <i className={icon} />
        </div>
      )}
    </div>
  )
}
