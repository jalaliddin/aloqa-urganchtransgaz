import { Center, Loader } from '@mantine/core'
import { IconLock } from '@tabler/icons-react'
import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { EmptyState } from '@/components/common/States'
import { useAuth } from './AuthContext'

export function RequireAuth({ children }: { children: ReactNode }) {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) {
    return (
      <Center h="100vh">
        <Loader aria-label="Yuklanmoqda" />
      </Center>
    )
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  }

  return <>{children}</>
}

/** UI-level guard only; every endpoint is authorised again on the server. */
export function RequirePermission({ anyOf, children }: { anyOf: string[]; children: ReactNode }) {
  const { canAny } = useAuth()

  if (!canAny(...anyOf)) {
    return (
      <EmptyState
        icon={<IconLock size={36} stroke={1.5} />}
        title="Bu bo'limga ruxsatingiz yo'q"
        description="Kerakli vakolat uchun tizim administratoriga murojaat qiling."
      />
    )
  }

  return <>{children}</>
}
