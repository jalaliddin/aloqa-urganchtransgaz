import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import type { MapFeatureCollection } from '@/api/types'

export type CableFilters = Record<string, string | string[] | number | boolean | null | undefined>

/** Removes empty values so they are not sent as query parameters. */
export function cleanParams(filters: CableFilters): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(filters).filter(([, v]) => v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0)),
  )
}

export function useMapFeatures(filters: CableFilters, bbox?: string | null, enabled = true) {
  return useQuery({
    queryKey: ['map-features', filters, bbox ?? null],
    queryFn: async () => (await api.get<MapFeatureCollection>('/map/features', { params: { ...cleanParams(filters), bbox: bbox ?? undefined } })).data,
    placeholderData: keepPreviousData,
    enabled,
  })
}

export interface MissingGeometryRow {
  id: number
  code: string
  name: string | null
  line_id: number
  registry_number: string
  line_name: string
  organization: string | null
  location_description: string | null
  dismantling_status: string
}

export function useMissingGeometry(filters: CableFilters) {
  return useQuery({
    queryKey: ['map-missing', filters],
    queryFn: async () => (await api.get<{ data: MissingGeometryRow[] }>('/map/missing-geometry', { params: cleanParams(filters) })).data.data,
  })
}
