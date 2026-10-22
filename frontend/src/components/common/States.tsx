import { Alert, Button, Center, List, Skeleton, Stack, Text } from '@mantine/core'
import { IconAlertTriangle, IconInbox } from '@tabler/icons-react'
import type { ReactNode } from 'react'
import { ApiError } from '@/api/client'

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const apiError = error instanceof ApiError ? error : null
  const message = apiError?.message ?? (error instanceof Error ? error.message : "Ma'lumotni yuklab bo'lmadi.")

  return (
    <Alert color="red" icon={<IconAlertTriangle size={18} />} title={apiError?.status === 403 ? "Ruxsat yo'q" : "Ma'lumot yuklanmadi"}>
      <Stack gap="xs">
        <Text size="sm">{message}</Text>
        {onRetry && (
          <div>
            <Button size="xs" variant="white" color="red" onClick={onRetry}>
              Qayta urinish
            </Button>
          </div>
        )}
      </Stack>
    </Alert>
  )
}

/** Shows business-rule errors (422) including every field message. */
export function ApiErrorAlert({ error, title }: { error: unknown; title?: string }) {
  if (!error) return null
  const apiError = error instanceof ApiError ? error : null

  return (
    <Alert color="red" variant="light" icon={<IconAlertTriangle size={18} />} title={title ?? apiError?.message ?? 'Xatolik'}>
      {apiError && apiError.details.length > 0 ? (
        <List size="sm" spacing={2}>
          {apiError.details.map((detail, index) => (
            <List.Item key={index}>{detail}</List.Item>
          ))}
        </List>
      ) : (
        !apiError && <Text size="sm">{String(error)}</Text>
      )}
    </Alert>
  )
}

export function EmptyState({ title, description, action, icon }: { title: string; description?: ReactNode; action?: ReactNode; icon?: ReactNode }) {
  return (
    <Center py="xl">
      <Stack align="center" gap="xs" maw={420} ta="center">
        <div style={{ color: 'var(--mantine-color-gray-5)' }}>{icon ?? <IconInbox size={36} stroke={1.5} />}</div>
        <Text fw={600}>{title}</Text>
        {description && (
          <Text size="sm" c="dimmed">
            {description}
          </Text>
        )}
        {action}
      </Stack>
    </Center>
  )
}

export function PageSkeleton() {
  return (
    <Stack gap="md" aria-busy="true" aria-label="Yuklanmoqda">
      <Skeleton height={32} width="40%" />
      <Skeleton height={16} width="60%" />
      <Skeleton height={220} />
      <Skeleton height={220} />
    </Stack>
  )
}
