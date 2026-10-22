/**
 * Display formatting. The database stores lengths in metres (decimal strings);
 * the UI shows km for long distances and m for short ones, never altering the stored value.
 */

function toNumber(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null
  const n = typeof value === 'number' ? value : Number(value)
  return Number.isFinite(n) ? n : null
}

/**
 * Uzbek number format, identical in every browser: space between thousands, comma decimal.
 * (Intl locale data for "uz" differs between engines, so it is not relied upon.)
 */
function grouped(n: number, fractionDigits: number, trimZeros = false): string {
  const fixed = Math.abs(n).toFixed(fractionDigits)
  const [integerPart, fractionPart = ''] = fixed.split('.')
  const integer = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
  const fraction = trimZeros ? fractionPart.replace(/0+$/, '') : fractionPart
  const sign = n < 0 && Number(fixed) !== 0 ? '-' : ''
  return `${sign}${integer}${fraction ? `,${fraction}` : ''}`
}

/** 1250.5 → "1,25 km"; 850 → "850 m"; null → "—" */
export function formatLength(metres: string | number | null | undefined, unit: 'auto' | 'm' | 'km' = 'auto'): string {
  const n = toNumber(metres)
  if (n === null) return '—'
  const useKm = unit === 'km' || (unit === 'auto' && Math.abs(n) >= 1000)
  return useKm ? `${grouped(n / 1000, 2)} km` : `${grouped(n, Number.isInteger(n) ? 0 : 2)} m`
}

export function formatKm(metres: string | number | null | undefined): string {
  return formatLength(metres, 'km')
}

export function formatNumber(value: string | number | null | undefined, fractionDigits = 0): string {
  const n = toNumber(value)
  return n === null ? '—' : grouped(n, fractionDigits)
}

export function formatSignedLength(metres: string | number | null | undefined): string {
  const n = toNumber(metres)
  if (n === null) return '—'
  const sign = n > 0 ? '+' : n < 0 ? '−' : ''
  return `${sign}${formatLength(Math.abs(n))}`
}

export function formatMoney(value: string | number | null | undefined): string {
  const n = toNumber(value)
  return n === null ? '—' : `${grouped(n, 2)} so'm`
}

export function formatPercent(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : `${grouped(value, 1)} %`
}

export function formatQuantity(value: string | number | null | undefined, unitLabel: string): string {
  const n = toNumber(value)
  return n === null ? '—' : `${grouped(n, 3, true)} ${unitLabel}`
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value.length === 10 ? `${value}T00:00:00` : value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return `${formatDate(value)} ${date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`
}

export function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${grouped(bytes / 1024, 1)} KB`
  return `${grouped(bytes / 1024 / 1024, 1)} MB`
}

const MONTHS = ['yan', 'fev', 'mar', 'apr', 'may', 'iyun', 'iyul', 'avg', 'sen', 'okt', 'noy', 'dek']

/** "2026-03" → "mar 2026" */
export function formatMonth(value: string): string {
  const [year, month] = value.split('-').map(Number)
  return `${MONTHS[(month ?? 1) - 1]} ${year}`
}

/** kg per metre: up to 6 decimals, trailing zeros trimmed; null → "noma'lum" (never 0). */
export function formatKgPerMetre(value: string | number | null | undefined): string {
  const n = toNumber(value)
  return n === null ? "noma'lum" : `${grouped(n, 6, true)} kg/m`
}

/** Mass in kg; null → "noma'lum" (an unknown mass is never shown as 0). */
export function formatMass(value: string | number | null | undefined): string {
  const n = toNumber(value)
  return n === null ? "noma'lum" : `${grouped(n, 3, true)} kg`
}
