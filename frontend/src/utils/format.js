/** Display helpers shared across pages. */
export const money = (n) =>
  '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const num = (n) => Number(n || 0).toLocaleString('en-IN')

export const today = () => new Date().toISOString().slice(0, 10)

export const monthStart = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

export const daysAgo = (n) => {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

export const badgeClass = (status) =>
  ({ paid: 'badge-paid', partial: 'badge-partial', credit: 'badge-credit',
     cancelled: 'badge-cancelled', active: 'badge-active', closed: 'badge-closed',
     completed: 'badge-paid', received: 'badge-active', inactive: 'badge-closed' }[status] || 'badge-closed')
