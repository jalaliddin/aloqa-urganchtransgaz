import { useQuery } from '@tanstack/react-query'
import { api } from './client'
import type { GasPipeline, Organization } from './types'

export function useOrganizations() {
  return useQuery({
    queryKey: ['organizations'],
    queryFn: async () => (await api.get<{ data: Organization[] }>('/organizations')).data.data,
    staleTime: 5 * 60_000,
  })
}

export function useOrganizationOptions(onlyMgqb = false) {
  const { data = [] } = useOrganizations()
  return data
    .filter((o) => !onlyMgqb || o.type === 'mgqb')
    .map((o) => ({ value: String(o.id), label: o.short_name || o.name }))
}

export function usePipelines() {
  return useQuery({
    queryKey: ['gas-pipelines'],
    queryFn: async () => (await api.get<{ data: GasPipeline[] }>('/gas-pipelines')).data.data,
    staleTime: 5 * 60_000,
  })
}

export interface DirectoryUser {
  id: number
  name: string
  position: string | null
  organization_id: number | null
}

export function useUserDirectory(organizationId?: number | null) {
  return useQuery({
    queryKey: ['users-directory', organizationId ?? null],
    queryFn: async () =>
      (await api.get<{ data: DirectoryUser[] }>('/users/directory', { params: { organization_id: organizationId ?? undefined } })).data.data,
    staleTime: 60_000,
  })
}

export function useCableTypes(activeOnly = false) {
  return useQuery({
    queryKey: ['cable-types', activeOnly],
    queryFn: async () =>
      (await api.get<{ data: import('./types').CableTypeItem[] }>('/cable-types', { params: { active_only: activeOnly ? 1 : undefined } })).data.data,
    staleTime: 60_000,
  })
}
