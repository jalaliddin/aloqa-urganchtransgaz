import { screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { renderWithProviders } from '@/test/utils'
import { StatusBadge } from './StatusBadge'

describe('StatusBadge', () => {
  it('always carries a text label, not only a colour', () => {
    renderWithProviders(<StatusBadge enumKey="usage_status" value="not_in_use" />)

    expect(screen.getByText('Aloqa tizimida ishlatilmaydi')).toBeInTheDocument()
  })

  it('renders a dash for an empty value', () => {
    renderWithProviders(<StatusBadge enumKey="usage_status" value={null} />)

    expect(screen.getByText('—')).toBeInTheDocument()
  })
})
