import { Group, Stack, Text, Title } from '@mantine/core'
import type { ReactNode } from 'react'

interface PageHeaderProps {
  title: ReactNode
  description?: ReactNode
  actions?: ReactNode
  badges?: ReactNode
}

export function PageHeader({ title, description, actions, badges }: PageHeaderProps) {
  return (
    <Group justify="space-between" align="flex-start" wrap="wrap" gap="md" mb="lg">
      <Stack gap={4} style={{ minWidth: 0, flex: '1 1 320px' }}>
        <Group gap="sm" wrap="wrap">
          <Title order={1}>{title}</Title>
          {badges}
        </Group>
        {description && (
          <Text c="dimmed" size="sm" maw={720}>
            {description}
          </Text>
        )}
      </Stack>
      {actions && (
        <Group gap="sm" wrap="wrap">
          {actions}
        </Group>
      )}
    </Group>
  )
}
