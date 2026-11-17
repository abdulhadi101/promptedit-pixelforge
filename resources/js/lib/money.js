// Money arrives as integer nanodollars. Show at least 4 decimals, trim trailing zeros beyond that.
export function formatNano(nano) {
  if (nano === null || nano === undefined) return '-'
  const s = String(nano).padStart(10, '0')
  const whole = s.slice(0, -9).replace(/^0+/, '') || '0'
  const frac = s.slice(-9).replace(/0+$/, '').padEnd(4, '0')
  return `$${whole}.${frac}`
}
