import { Badge, type BadgeProps } from '@mantine/core'
import { useEnum } from '@/lib/meta'

interface StatusBadgeProps extends Omit<BadgeProps, 'color' | 'children'> {
  enumKey: string
  value: string | null | undefined
}

/** Colour plus a text label — colour is never the only carrier of meaning. */
export function StatusBadge({ enumKey, value, ...props }: StatusBadgeProps) {
  const helper = useEnum(enumKey)
  if (!value) return <>—</>

  return (
    <Badge color={helper.color(value)} variant="light" leftSection={<Dot color={helper.color(value)} />} {...props}>
      {helper.label(value)}
    </Badge>
  )
}

function Dot({ color }: { color: string }) {
  return (
    <span
      aria-hidden
      style={{ display: 'inline-block', width: 7, height: 7, borderRadius: '50%', background: `var(--mantine-color-${color}-6)` }}
    />
  )
}
