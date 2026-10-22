import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router'
import type { CableFilters } from '@/components/map/useMapFeatures'

export const ARRAY_FILTERS = ['dismantling_status', 'usage_status', 'technical_condition', 'risk_level', 'inspection_status']

const DEFAULT_RESERVED = ['selected', 'segment', 'view', 'page', 'per_page', 'tab', 'color']

/**
 * Filters live in the URL so views are shareable, survive reloads and can be
 * linked to from the dashboard. Array filters are comma-separated.
 */
export function useUrlFilters(reservedKeys: string[] = DEFAULT_RESERVED) {
  const [params, setParams] = useSearchParams()

  const filters = useMemo<CableFilters>(() => {
    const result: CableFilters = {}
    params.forEach((value, key) => {
      if (reservedKeys.includes(key)) return
      result[key] = ARRAY_FILTERS.includes(key) ? value.split(',').filter(Boolean) : value
    })
    return result
  }, [params, reservedKeys])

  const setFilters = useCallback(
    (next: CableFilters) => {
      setParams(
        (current) => {
          const updated = new URLSearchParams()
          reservedKeys.forEach((key) => {
            const v = current.get(key)
            if (v !== null && key !== 'page') updated.set(key, v)
          })
          Object.entries(next).forEach(([key, value]) => {
            if (value === undefined || value === null || value === '') return
            if (Array.isArray(value)) {
              if (value.length) updated.set(key, value.join(','))
            } else {
              updated.set(key, String(value))
            }
          })
          return updated
        },
        { replace: true },
      )
    },
    [setParams, reservedKeys],
  )

  const getParam = useCallback((key: string) => params.get(key), [params])
  const setParam = useCallback(
    (key: string, value: string | number | null) => {
      setParams(
        (current) => {
          const updated = new URLSearchParams(current)
          if (value === null || value === '') updated.delete(key)
          else updated.set(key, String(value))
          return updated
        },
        { replace: true },
      )
    },
    [setParams],
  )

  return { filters, setFilters, getParam, setParam }
}
