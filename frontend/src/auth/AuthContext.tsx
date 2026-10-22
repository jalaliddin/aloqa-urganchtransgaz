import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useEffect, useMemo, type ReactNode } from 'react'
import { api, ensureCsrfCookie, setUnauthenticatedHandler, type ApiError } from '@/api/client'
import type { CurrentUser } from '@/api/types'

interface AuthContextValue {
  user: CurrentUser | null
  isLoading: boolean
  can: (permission: string) => boolean
  canAny: (...permissions: string[]) => boolean
  login: (email: string, password: string) => Promise<CurrentUser>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

const ME_KEY = ['auth', 'me']

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()

  const { data: user = null, isLoading } = useQuery<CurrentUser | null, ApiError>({
    queryKey: ME_KEY,
    queryFn: async () => {
      try {
        return (await api.get<{ data: CurrentUser }>('/auth/me')).data.data
      } catch (error) {
        if ((error as ApiError).status === 401) return null
        throw error
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  })

  useEffect(() => {
    setUnauthenticatedHandler(() => {
      queryClient.setQueryData(ME_KEY, null)
    })
  }, [queryClient])

  const login = useCallback(
    async (email: string, password: string) => {
      await ensureCsrfCookie()
      const profile = (await api.post<{ data: CurrentUser }>('/auth/login', { email, password })).data.data
      // Drop data cached for a previous user, but keep the live auth query so observers update
      queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'auth' })
      queryClient.setQueryData(ME_KEY, profile)
      return profile
    },
    [queryClient],
  )

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } finally {
      queryClient.setQueryData(ME_KEY, null)
      queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'auth' })
    }
  }, [queryClient])

  const value = useMemo<AuthContextValue>(() => {
    const permissions = new Set(user?.permissions ?? [])
    const can = (permission: string) => permissions.has(permission)
    return { user, isLoading, can, canAny: (...list) => list.some(can), login, logout }
  }, [user, isLoading, login, logout])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}
