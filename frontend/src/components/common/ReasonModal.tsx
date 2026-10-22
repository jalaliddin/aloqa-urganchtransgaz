import { Button, Group, Modal, Stack, Text, Textarea } from '@mantine/core'
import { useState, type ReactNode } from 'react'
import { ApiErrorAlert } from './States'

interface ReasonModalProps {
  opened: boolean
  onClose: () => void
  title: string
  description?: ReactNode
  confirmLabel: string
  color?: string
  /** Required reasons are validated client-side; the server validates again. */
  reasonRequired?: boolean
  reasonLabel?: string
  minLength?: number
  onConfirm: (reason: string) => Promise<unknown>
}

/** Confirmation dialog for critical actions that records who decided and why. */
export function ReasonModal({
  opened, onClose, title, description, confirmLabel, color = 'steel', reasonRequired = true,
  reasonLabel = 'Sabab', minLength = 5, onConfirm,
}: ReasonModalProps) {
  const [reason, setReason] = useState('')
  const [touched, setTouched] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [submitting, setSubmitting] = useState(false)

  const invalid = reasonRequired && reason.trim().length < minLength
  const close = () => {
    setReason('')
    setTouched(false)
    setError(null)
    onClose()
  }

  const submit = async () => {
    setTouched(true)
    if (invalid) return
    setSubmitting(true)
    setError(null)
    try {
      await onConfirm(reason.trim())
      close()
    } catch (e) {
      setError(e)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal opened={opened} onClose={close} title={<Text fw={600}>{title}</Text>} size="lg">
      <Stack gap="md">
        {description && (typeof description === 'string' ? <Text size="sm">{description}</Text> : description)}
        <Textarea
          label={reasonLabel}
          required={reasonRequired}
          autosize
          minRows={3}
          value={reason}
          onChange={(e) => setReason(e.currentTarget.value)}
          onBlur={() => setTouched(true)}
          error={touched && invalid ? `Kamida ${minLength} belgi kiriting.` : undefined}
          data-autofocus
        />
        <ApiErrorAlert error={error} />
        <Group justify="flex-end">
          <Button variant="default" onClick={close}>
            Bekor qilish
          </Button>
          <Button color={color} loading={submitting} onClick={submit}>
            {confirmLabel}
          </Button>
        </Group>
      </Stack>
    </Modal>
  )
}
