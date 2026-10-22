import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { CableLine, MapFeatureProperties } from '@/api/types'
import { renderWithProviders } from '@/test/utils'
import RegistryPage from './RegistryPage'

// The real Leaflet map cannot run in jsdom: a stand-in records what the page passes to it
const mapProps: { selectedLineId?: number | null; onSelect?: (p: MapFeatureProperties) => void; features?: unknown[] } = {}
vi.mock('@/components/map/CableMap', () => ({
  CableMap: (props: typeof mapProps & { children?: React.ReactNode }) => {
    Object.assign(mapProps, props)
    return <div data-testid="map" data-selected={props.selectedLineId ?? ''} />
  },
}))
vi.mock('@/components/map/SelectedLinePanel', () => ({
  SelectedLinePanel: ({ lineId }: { lineId: number }) => <div data-testid="panel">panel-{lineId}</div>,
}))

const line = (id: number, registry: string): CableLine =>
  ({
    id, registry_number: registry, cable_code: null, name: `Liniya ${id}`, laid_year_label: '1960-yillar', design_length_m: '1000.00',
    measured_length_m: null, usage_status: 'in_use', technical_condition: 'poor', risk_level: 'low', inspection_status: 'inspected',
    dismantling_status: 'not_planned', has_geometry: true, is_complex: false, is_demo: false, organization: { id: 1, name: 'TEST', short_name: 'TEST' },
  }) as unknown as CableLine

const get = vi.fn()
vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, api: { get: (...args: unknown[]) => get(...args), post: vi.fn() } }
})

describe('Registry ↔ map synchronisation', () => {
  beforeEach(() => {
    get.mockImplementation(async (url: string) => {
      if (url === '/cable-lines') {
        return { data: { data: [line(1, 'KL-2026-00001'), line(2, 'KL-2026-00002')], meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 } } }
      }
      if (url === '/map/features') return { data: { type: 'FeatureCollection', features: [] } }
      return { data: { data: [] } }
    })
  })

  it('selecting a table row selects the same line on the map', async () => {
    renderWithProviders(<RegistryPage />, { route: '/registry?view=split' })

    const row = (await screen.findByText('KL-2026-00002')).closest('tr')!
    fireEvent.click(row)

    await waitFor(() => expect(screen.getByTestId('map')).toHaveAttribute('data-selected', '2'))
    expect(row).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByTestId('panel')).toHaveTextContent('panel-2')
  })

  it('clicking a route on the map highlights the matching table row', async () => {
    renderWithProviders(<RegistryPage />, { route: '/registry?view=split' })
    await screen.findByText('KL-2026-00001')

    act(() => mapProps.onSelect?.({ line_id: 1, segment_id: 10 } as MapFeatureProperties))

    const row = (await screen.findByText('KL-2026-00001')).closest('tr')!
    await waitFor(() => expect(row).toHaveAttribute('aria-selected', 'true'))
    expect(screen.getByText('KL-2026-00002').closest('tr')).toHaveAttribute('aria-selected', 'false')
  })

  it('table and map use the same filters', async () => {
    renderWithProviders(<RegistryPage />, { route: '/registry?view=split&risk_level=high&q=KL' })
    await screen.findByText('KL-2026-00001')

    const lineCall = get.mock.calls.find(([url]) => url === '/cable-lines')!
    const mapCall = get.mock.calls.find(([url]) => url === '/map/features')!
    expect(lineCall[1].params).toMatchObject({ risk_level: ['high'], q: 'KL' })
    expect(mapCall[1].params).toMatchObject({ risk_level: ['high'], q: 'KL' })
  })

  it('table-only mode does not load map data', async () => {
    renderWithProviders(<RegistryPage />, { route: '/registry' })
    const cell = await screen.findByText('KL-2026-00001')

    expect(within(screen.getByRole('table')).getByText('KL-2026-00002')).toBeInTheDocument()
    expect(cell).toBeInTheDocument()
    expect(screen.queryByTestId('map')).not.toBeInTheDocument()
    expect(get.mock.calls.some(([url]) => url === '/map/features')).toBe(false)
  })
})
