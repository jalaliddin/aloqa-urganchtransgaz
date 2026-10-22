import { Collapse, Group, Paper, SegmentedControl, Select, Stack, Text, UnstyledButton } from '@mantine/core'
import { useDisclosure, useMediaQuery } from '@mantine/hooks'
import { IconChevronDown, IconChevronUp } from '@tabler/icons-react'
import { COLOR_MODES, useFeatureColor, type ColorMode } from './mapColors'

interface MapLegendProps {
  mode: ColorMode
  onModeChange: (mode: ColorMode) => void
  /** Collapsed legends leave more of a small map visible */
  defaultOpened?: boolean
}

/** Legend with text labels (colour is never the only cue) and the colouring mode switch. */
export function MapLegend({ mode, onModeChange, defaultOpened = true }: MapLegendProps) {
  const { legend } = useFeatureColor(mode)
  const narrow = useMediaQuery('(max-width: 48em)', false, { getInitialValueInEffect: false })
  const [opened, { toggle }] = useDisclosure(defaultOpened)
  const modeLabel = COLOR_MODES.find((m) => m.value === mode)?.label

  return (
    <Paper p="sm" shadow="sm" style={{ maxWidth: 300 }} aria-label="Xarita legendasi">
      <UnstyledButton onClick={toggle} aria-expanded={opened} w="100%" mb={opened ? 8 : 0}>
        <Group justify="space-between" gap="xs" wrap="nowrap">
          <Text size="sm" fw={600}>
            Legenda: {modeLabel}
          </Text>
          {opened ? <IconChevronDown size={16} /> : <IconChevronUp size={16} />}
        </Group>
      </UnstyledButton>
      <Collapse expanded={opened}>
        <Stack gap={8}>
          {narrow ? (
            <Select
              size="xs"
              aria-label="Ranglash mezoni"
              data={COLOR_MODES.map(({ value, label }) => ({ value, label }))}
              value={mode}
              onChange={(v) => v && onModeChange(v as ColorMode)}
              allowDeselect={false}
            />
          ) : (
            <SegmentedControl
              size="xs"
              fullWidth
              orientation="vertical"
              aria-label="Ranglash mezoni"
              data={COLOR_MODES.map(({ value, label }) => ({ value, label }))}
              value={mode}
              onChange={(v) => onModeChange(v as ColorMode)}
            />
          )}
          <Stack gap={4}>
            {legend.map((item) => (
              <Group key={item.value} gap={8} wrap="nowrap">
                <span aria-hidden style={{ width: 22, height: 4, borderRadius: 2, background: item.color, flexShrink: 0 }} />
                <Text size="xs">{item.label}</Text>
              </Group>
            ))}
            <Group gap={8} wrap="nowrap" mt={4}>
              <span aria-hidden style={{ width: 22, height: 0, borderTop: '4px dashed #868e96', flexShrink: 0 }} />
              <Text size="xs">DEMO geometriya (haqiqiy emas)</Text>
            </Group>
            <Group gap={8} wrap="nowrap">
              <span aria-hidden className="route-peg" style={{ width: 12, height: 12, flexShrink: 0 }} />
              <Text size="xs">Uchastka boshi / oxiri</Text>
            </Group>
          </Stack>
        </Stack>
      </Collapse>
    </Paper>
  )
}
