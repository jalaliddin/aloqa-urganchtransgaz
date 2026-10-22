import { fireEvent, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithProviders, testUser } from '@/test/utils'
import { AppLayout } from './AppLayout'

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { get: vi.fn(async () => ({ data: { data: [], unread: 0 } })), post: vi.fn() } }
})

describe('AppLayout', () => {
  it('shows only the menu items the user is permitted to use', () => {
    renderWithProviders(<AppLayout />)

    const nav = screen.getByRole('navigation', { name: 'Asosiy menyu' })
    expect(nav).toHaveTextContent('Kabel liniyalari reyestri')
    expect(nav).toHaveTextContent('Interaktiv xarita')
    expect(nav).not.toHaveTextContent('Foydalanuvchilar va rollar')
    expect(nav).not.toHaveTextContent('Audit jurnali')
  })

  it('has a burger toggle for the mobile navigation', () => {
    renderWithProviders(<AppLayout />)

    const burger = screen.getByRole('button', { name: 'Menyuni ochish' })
    expect(burger).toHaveAttribute('aria-expanded', 'false')
    fireEvent.click(burger)
    expect(burger).toHaveAttribute('aria-expanded', 'true')
  })

  it('warns when demo data is present', () => {
    renderWithProviders(<AppLayout />, { user: { ...testUser, demo_data_present: true } })

    expect(screen.getByText(/DEMO ma'lumotlar/)).toBeInTheDocument()
  })
})
