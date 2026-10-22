import { describe, expect, it } from 'vitest'
import { formatLength, formatMoney, formatPercent, formatQuantity, formatSignedLength } from './format'

describe('formatLength', () => {
  it('shows metres below one kilometre and kilometres above', () => {
    expect(formatLength('850')).toBe('850 m')
    expect(formatLength('850.50')).toBe('850,50 m')
    expect(formatLength('1250.50')).toBe('1,25 km')
    expect(formatLength(12345678)).toBe('12 345,68 km')
  })

  it('never invents a value for missing data', () => {
    expect(formatLength(null)).toBe('—')
    expect(formatLength('')).toBe('—')
    expect(formatLength(undefined)).toBe('—')
  })

  it('formats signed differences', () => {
    expect(formatSignedLength('-100.00')).toBe('−100 m')
    expect(formatSignedLength('2500')).toBe('+2,50 km')
  })

  it('formats quantities without trailing zeros', () => {
    expect(formatQuantity('4851.740', 'm')).toBe('4 851,74 m')
    expect(formatQuantity('418.000', 'kg')).toBe('418 kg')
    expect(formatQuantity('-20.000', 'm')).toBe('-20 m')
  })

  it('formats money and percent', () => {
    expect(formatMoney('1500000.5')).toBe("1 500 000,50 so'm")
    expect(formatPercent(null)).toBe('—')
    expect(formatPercent(33.333)).toBe('33,3 %')
  })
})
