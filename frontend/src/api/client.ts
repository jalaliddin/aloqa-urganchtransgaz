import axios, { AxiosError, type AxiosRequestConfig } from 'axios'

/**
 * Same-origin API client. Authentication is Laravel Sanctum's cookie session:
 * the XSRF-TOKEN cookie is mirrored into the X-XSRF-TOKEN header by axios.
 */
export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

export async function ensureCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

/** Normalised API error used across the UI. */
export class ApiError extends Error {
  readonly status: number
  readonly code?: string
  readonly fieldErrors: Record<string, string[]>
  /** Raw response body, for endpoints that return extra data with an error (e.g. import reports) */
  readonly body: unknown

  constructor(message: string, status: number, fieldErrors: Record<string, string[]> = {}, code?: string, body?: unknown) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.fieldErrors = fieldErrors
    this.body = body
  }

  /** All messages, field errors included — for notifications. */
  get details(): string[] {
    return Object.values(this.fieldErrors).flat()
  }
}

type LaravelErrorBody = { message?: string; errors?: Record<string, string[]>; code?: string }

export function toApiError(error: unknown): ApiError {
  if (error instanceof ApiError) return error
  if (error instanceof AxiosError) {
    const status = error.response?.status ?? 0
    const body = (error.response?.data ?? {}) as LaravelErrorBody
    const fallback =
      status === 0
        ? "Server bilan aloqa yo'q. Tarmoqni tekshirib, qayta urinib ko'ring."
        : status >= 500
          ? "Serverda xatolik yuz berdi. Amal saqlanmadi — birozdan so'ng qayta urinib ko'ring."
          : "So'rov bajarilmadi."
    return new ApiError(body.message || fallback, status, body.errors ?? {}, body.code, error.response?.data)
  }
  return new ApiError(error instanceof Error ? error.message : "Noma'lum xatolik", 0)
}

let onUnauthenticated: (() => void) | null = null
export function setUnauthenticatedHandler(handler: () => void): void {
  onUnauthenticated = handler
}

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const config = error.config as (AxiosRequestConfig & { _retried?: boolean }) | undefined
    // CSRF token expired: refresh the cookie once and retry
    if (error.response?.status === 419 && config && !config._retried) {
      config._retried = true
      await ensureCsrfCookie()
      return api.request(config)
    }
    if (error.response?.status === 401 && !config?.url?.includes('auth/login')) {
      onUnauthenticated?.()
    }
    return Promise.reject(toApiError(error))
  },
)

/** Triggers a browser download for an authenticated file endpoint. */
export async function downloadFile(url: string, params?: Record<string, unknown>): Promise<void> {
  const response = await api.get(url, { params, responseType: 'blob' })
  const disposition = String(response.headers['content-disposition'] ?? '')
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
  const fileName = match ? decodeURIComponent(match[1]) : 'fayl'
  const href = URL.createObjectURL(response.data as Blob)
  const link = document.createElement('a')
  link.href = href
  link.download = fileName
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(href)
}
