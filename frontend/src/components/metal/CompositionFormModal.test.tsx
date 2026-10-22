import { fireEvent, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithProviders } from '@/test/utils'
import { CompositionFormModal } from './CompositionFormModal'

const post = vi.fn(async () => ({ data: {} }))
vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return {
    ...actual,
    api: { get: vi.fn(async () => ({ data: { data: [], meta: { total: 0 } } })), post: (...args: unknown[]) => post(...(args as [])) },
  }
})

describe('CompositionFormModal', () => {
  it('sends empty fields as null (unknown) and keeps 0 as zero', async () => {
    renderWithProviders(<CompositionFormModal scope={{ type: 'cable_type', id: 7, label: 'MKSB' }} onClose={() => {}} onSaved={() => {}} />)

    fireEvent.change(screen.getByLabelText('Alyuminiy'), { target: { value: '0.4215' } })
    fireEvent.change(screen.getByLabelText('Mis'), { target: { value: '0' } })
    fireEvent.change(screen.getByRole('textbox', { name: /^Manba\s*\*?$/ }), { target: { value: 'Kabel pasporti 17' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tasdiqlashga yuborish' }))

    await waitFor(() => expect(post).toHaveBeenCalled())
    const [url, body] = post.mock.calls[0] as unknown as [string, Record<string, unknown>]
    expect(url).toBe('/metal-compositions')
    expect(body).toMatchObject({ scope_type: 'cable_type', scope_id: 7, aluminium_kg_per_m: 0.4215, lead_kg_per_m: null, copper_kg_per_m: 0 })
  })

  it('refuses to submit when every metal is left unknown', async () => {
    post.mockClear()
    renderWithProviders(<CompositionFormModal scope={{ type: 'cable_type', id: 7, label: 'MKSB' }} onClose={() => {}} onSaved={() => {}} />)

    fireEvent.click(screen.getByRole('button', { name: 'Tasdiqlashga yuborish' }))

    expect(await screen.findByText("Kamida bitta qiymat kiriting (metall yo'q bo'lsa 0)")).toBeInTheDocument()
    expect(post).not.toHaveBeenCalled()
  })
})
