import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { api } from '@/api/client'
import type { EnumOption, Meta } from '@/api/types'

export function useMeta() {
  return useQuery({
    queryKey: ['meta'],
    queryFn: async () => (await api.get<{ data: Meta }>('/meta')).data.data,
    staleTime: Infinity,
  })
}

export interface EnumHelper {
  options: EnumOption[]
  selectData: { value: string; label: string }[]
  label: (value: string | null | undefined) => string
  color: (value: string | null | undefined) => string
}

/** Labels and colours of a backend enum (single source of truth: /api/meta). */
export function useEnum(key: string): EnumHelper {
  const { data } = useMeta()

  return useMemo(() => {
    const options = data?.enums[key] ?? []
    const byValue = new Map(options.map((o) => [o.value, o]))
    return {
      options,
      selectData: options.map(({ value, label }) => ({ value, label })),
      label: (value) => (value ? (byValue.get(value)?.label ?? value) : '—'),
      color: (value) => (value ? (byValue.get(value)?.color ?? 'gray') : 'gray'),
    }
  }, [data, key])
}
