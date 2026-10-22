import { MantineProvider } from '@mantine/core'
import { ModalsProvider } from '@mantine/modals'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import type { ReactElement } from 'react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { CurrentUser, Meta } from '@/api/types'
import { AuthProvider } from '@/auth/AuthContext'
import { theme } from '@/theme'

export const testMeta: Meta = {
  enums: {
    dismantling_status: [
      { value: 'not_planned', label: 'Rejalashtirilmagan', color: 'gray' },
      { value: 'completed', label: "To'liq bajarilgan", color: 'teal' },
    ],
    usage_status: [
      { value: 'in_use', label: 'Aloqa tizimida foydalaniladi', color: 'teal' },
      { value: 'not_in_use', label: 'Aloqa tizimida ishlatilmaydi', color: 'gray' },
    ],
    technical_condition: [{ value: 'poor', label: 'Yomon', color: 'orange' }],
    risk_level: [
      { value: 'low', label: 'Past', color: 'teal' },
      { value: 'high', label: 'Yuqori', color: 'orange' },
    ],
    segment_inspection_status: [{ value: 'inspected', label: 'Tekshirilgan', color: 'teal' }],
  },
  roles: [],
  map: {
    tile_url: 'https://tile.example/{z}/{x}/{y}.png',
    attribution: 'test',
    max_zoom: 19,
    default_center: [41.5, 60.6],
    default_zoom: 8,
    satellite_url: 'https://imagery.example/{z}/{y}/{x}',
    satellite_attribution: 'imagery',
    satellite_max_native_zoom: 18,
    satellite_labels_url: 'https://labels.example/{z}/{y}/{x}',
    wms_layers: [],
  },
  tolerance: { length_m: 5, length_percent: 2, material_percent: 1, metal_percent: 3 },
  documents: { max_kb: 1024, allowed_extensions: ['pdf'], required: {} },
}

export const testUser: CurrentUser = {
  id: 1,
  name: 'Test Foydalanuvchi',
  email: 'test@example.uz',
  position: null,
  organization_id: 1,
  organization: { id: 1, name: 'TEST MGQB', short_name: 'TEST MGQB' },
  roles: [{ name: 'at_service', label: 'AT xizmati' }],
  permissions: ['dashboard.view', 'cable_lines.view', 'cable_lines.create', 'reports.export'],
  can_view_all_organizations: false,
  demo_data_present: false,
}

export function renderWithProviders(ui: ReactElement, { route = '/', user = testUser }: { route?: string; user?: CurrentUser | null } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  queryClient.setQueryData(['meta'], testMeta)
  queryClient.setQueryData(['auth', 'me'], user)
  // A data router, like the app's, so hooks such as useMatches work
  const router = createMemoryRouter([{ path: '*', element: ui }], { initialEntries: [route] })

  return {
    queryClient,
    ...render(
      <MantineProvider theme={theme}>
        <ModalsProvider>
          <QueryClientProvider client={queryClient}>
            <AuthProvider>
              <RouterProvider router={router} />
            </AuthProvider>
          </QueryClientProvider>
        </ModalsProvider>
      </MantineProvider>,
    ),
  }
}
