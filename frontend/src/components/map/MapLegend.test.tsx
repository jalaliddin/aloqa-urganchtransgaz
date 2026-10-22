import { fireEvent, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderWithProviders } from '@/test/utils'
import { MapLegend } from './MapLegend'

function setViewport(narrow: boolean) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: narrow && query.includes('max-width'),
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }))
}

describe('MapLegend', () => {
  afterEach(() => setViewport(false))

  it('lists every status with a text label and marks demo geometry', () => {
    setViewport(false)
    renderWithProviders(<MapLegend mode="usage_status" onModeChange={() => {}} />)

    expect(screen.getByText('Aloqa tizimida foydalaniladi')).toBeInTheDocument()
    expect(screen.getByText('Aloqa tizimida ishlatilmaydi')).toBeInTheDocument()
    expect(screen.getByText('DEMO geometriya (haqiqiy emas)')).toBeInTheDocument()
    expect(screen.getByRole('radiogroup', { name: 'Ranglash mezoni' })).toBeInTheDocument()
  })

  it('switches to a compact select on mobile screens', () => {
    setViewport(true)
    renderWithProviders(<MapLegend mode="dismantling_status" onModeChange={() => {}} />)

    const select = screen.getAllByLabelText('Ranglash mezoni').find((el) => el.tagName === 'INPUT')!
    expect(select).toHaveValue('Demontaj holati')
    expect(screen.queryByRole('radiogroup')).not.toBeInTheDocument()
  })

  it('can be collapsed to leave the map visible', () => {
    setViewport(false)
    renderWithProviders(<MapLegend mode="dismantling_status" onModeChange={() => {}} defaultOpened={false} />)

    const toggle = screen.getByRole('button', { name: /Legenda: Demontaj holati/ })
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    fireEvent.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
  })
})
