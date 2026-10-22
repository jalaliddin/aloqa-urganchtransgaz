import { useMantineTheme } from '@mantine/core'
import { useCallback } from 'react'
import type { MapFeatureProperties } from '@/api/types'
import { useEnum } from '@/lib/meta'

export type ColorMode = 'dismantling_status' | 'usage_status' | 'technical_condition' | 'risk_level'

export const COLOR_MODES: { value: ColorMode; label: string; enumKey: string }[] = [
  { value: 'dismantling_status', label: 'Demontaj holati', enumKey: 'dismantling_status' },
  { value: 'usage_status', label: 'Foydalanish holati', enumKey: 'usage_status' },
  { value: 'technical_condition', label: 'Texnik holat', enumKey: 'technical_condition' },
  { value: 'risk_level', label: 'Xavf darajasi', enumKey: 'risk_level' },
]

/** Resolves the line colour of a feature from the active legend mode (same colours as status badges). */
export function useFeatureColor(mode: ColorMode) {
  const theme = useMantineTheme()
  const helper = useEnum(COLOR_MODES.find((m) => m.value === mode)!.enumKey)

  const toHex = useCallback(
    (name: string) => {
      const palette = theme.colors[name as keyof typeof theme.colors]
      return palette ? palette[name === 'dark' ? 7 : 6] : theme.colors.gray[6]
    },
    [theme],
  )

  const colorOf = useCallback((properties: MapFeatureProperties) => toHex(helper.color(properties[mode] as string)), [helper, mode, toHex])

  const legend = helper.options.map((option) => ({ value: option.value, label: option.label, color: toHex(option.color) }))

  return { colorOf, legend }
}
