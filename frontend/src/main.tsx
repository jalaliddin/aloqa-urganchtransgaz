import '@fontsource/ibm-plex-sans/400.css'
import '@fontsource/ibm-plex-sans/500.css'
import '@fontsource/ibm-plex-sans/600.css'
import '@mantine/core/styles.css'
import '@mantine/dates/styles.css'
import '@mantine/notifications/styles.css'
import '@mantine/charts/styles.css'
import '@mantine/dropzone/styles.css'
import './styles/global.css'
import './i18n'

import { MantineProvider } from '@mantine/core'
import { DatesProvider } from '@mantine/dates'
import { ModalsProvider } from '@mantine/modals'
import { Notifications } from '@mantine/notifications'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import dayjs from 'dayjs'
import 'dayjs/locale/uz-latn'
import { StrictMode, Suspense } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router'
import { ApiError } from './api/client'
import { AuthProvider } from './auth/AuthContext'
import { PageSkeleton } from './components/common/States'
import { router } from './router'
import { theme } from './theme'

dayjs.locale('uz-latn')

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      // Never retry authorisation / validation failures
      retry: (count, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && count < 2,
    },
  },
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <MantineProvider theme={theme} defaultColorScheme="light">
      <DatesProvider settings={{ locale: 'uz-latn', firstDayOfWeek: 1 }}>
        <ModalsProvider labels={{ confirm: 'Tasdiqlash', cancel: 'Bekor qilish' }}>
          <Notifications position="top-right" />
          <QueryClientProvider client={queryClient}>
            <AuthProvider>
              <Suspense fallback={<PageSkeleton />}>
                <RouterProvider router={router} />
              </Suspense>
            </AuthProvider>
          </QueryClientProvider>
        </ModalsProvider>
      </DatesProvider>
    </MantineProvider>
  </StrictMode>,
)
