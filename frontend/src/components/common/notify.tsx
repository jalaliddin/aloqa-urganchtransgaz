import { notifications } from '@mantine/notifications'
import { IconAlertTriangle, IconCheck } from '@tabler/icons-react'
import { ApiError } from '@/api/client'

export function notifySuccess(message: string, title?: string) {
  notifications.show({ color: 'teal', title, message, icon: <IconCheck size={18} /> })
}

export function notifyError(error: unknown, title?: string) {
  const apiError = error instanceof ApiError ? error : null
  const details = apiError?.details ?? []
  notifications.show({
    color: 'red',
    title: title ?? apiError?.message ?? 'Xatolik',
    message: details.length > 0 ? details.slice(0, 4).join(' ') : apiError ? ' ' : String(error),
    icon: <IconAlertTriangle size={18} />,
    autoClose: 9000,
  })
}
