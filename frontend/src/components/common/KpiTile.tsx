import { Group, Paper, Stack, Text, ThemeIcon } from '@mantine/core'
import type { ReactNode } from 'react'
import { Link } from 'react-router'

interface KpiTileProps {
  label: string
  value: ReactNode
  hint?: ReactNode
  icon: ReactNode
  tone?: 'neutral' | 'attention' | 'risk'
  to?: string
}

const toneColor = { neutral: 'steel', attention: 'orange', risk: 'red' } as const

export function KpiTile({ label, value, hint, icon, tone = 'neutral', to }: KpiTileProps) {
  const body = (
    <Paper p="md" h="100%" style={{ borderLeft: `3px solid var(--mantine-color-${toneColor[tone]}-6)` }}>
      <Group justify="space-between" align="flex-start" wrap="nowrap" gap="sm">
        <Stack gap={2} style={{ minWidth: 0 }}>
          <Text size="sm" c="dimmed">
            {label}
          </Text>
          <Text fz={24} fw={600} lh={1.2}>
            {value}
          </Text>
          {hint && (
            <Text size="xs" c="dimmed">
              {hint}
            </Text>
          )}
        </Stack>
        <ThemeIcon variant="light" color={toneColor[tone]} size="lg" radius="sm" aria-hidden>
          {icon}
        </ThemeIcon>
      </Group>
    </Paper>
  )

  return to ? (
    <Link to={to} style={{ textDecoration: 'none', color: 'inherit' }} aria-label={`${label}: batafsil`}>
      {body}
    </Link>
  ) : (
    body
  )
}
