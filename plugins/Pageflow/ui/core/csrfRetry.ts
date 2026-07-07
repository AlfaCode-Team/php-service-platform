import { default as axios } from 'axios'
import { csrfHeaderName, csrfToken, isSameOriginUrl, setCsrfToken } from './csrf'

export type CsrfAutoRefreshOptions = {
  /** Endpoint returning a fresh token as { token }. Default '/pageflow/csrf'. */
  endpoint?: string
}

let installed = false

/**
 * Install an axios response interceptor that transparently recovers from an
 * expired CSRF token on a long-lived SPA.
 *
 * When a mutating request fails with 403 (the platform's CsrfTokenLayer verdict)
 * and hasn't already been retried, it fetches a fresh token from the refresh
 * endpoint, updates the <meta> tag, and replays the ORIGINAL request exactly
 * once. Only ONE refresh runs at a time (shared promise) so a burst of 403s
 * doesn't stampede the endpoint.
 *
 * SECURITY: this only re-attaches a CSRF token; it never bypasses auth. A 403
 * that is NOT a stale-token case simply fails on the single retry as before.
 * Call once at bootstrap (idempotent).
 */
export function installCsrfAutoRefresh(options: CsrfAutoRefreshOptions = {}): void {
  if (installed || typeof window === 'undefined') {
    return
  }
  installed = true

  const endpoint = options.endpoint ?? '/pageflow/csrf'
  let refreshing: Promise<string | null> | null = null

  const refresh = (): Promise<string | null> => {
    if (!refreshing) {
      refreshing = axios
        .get(endpoint, { withCredentials: true, headers: { Accept: 'application/json' } })
        .then((response) => {
          const token = response?.data?.token
          if (typeof token === 'string' && token !== '') {
            setCsrfToken(token)
            return token
          }
          return null
        })
        .catch(() => null)
        .finally(() => {
          refreshing = null
        })
    }
    return refreshing
  }

  axios.interceptors.response.use(
    (response) => response,
    async (error) => {
      const config = error?.config
      const status = error?.response?.status
      const method = String(config?.method ?? 'get').toLowerCase()

      // Only recover from a CSRF-type 403 — NOT an authorization denial. We look
      // at the error envelope; when it's a clear non-CSRF denial we reject
      // immediately (no wasted refresh, no masking the real 403). An empty/
      // unreadable body is treated as ambiguous and allowed one refresh.
      const reason = extractReason(error?.response?.data)
      const looksLikeCsrf = reason.includes('csrf') || reason === ''

      // SECURITY: only ever touch SAME-ORIGIN requests. The interceptor is on
      // the global axios instance, so a third-party 403 must NOT trigger a token
      // refresh or have our CSRF token attached on replay (that would leak it).
      const sameOrigin = isSameOriginUrl(buildUrl(config))

      const retryable =
        status === 403 &&
        config &&
        sameOrigin &&
        !config.__pageflowCsrfRetried &&
        method !== 'get' &&
        method !== 'head' &&
        looksLikeCsrf

      if (!retryable) {
        return Promise.reject(error)
      }

      const token = await refresh()
      if (!token) {
        return Promise.reject(error)
      }

      config.__pageflowCsrfRetried = true
      config.headers = { ...(config.headers || {}), [csrfHeaderName()]: token }
      return axios(config)
    },
  )

  // Prime the interceptor's view of the current token (no-op if none).
  void csrfToken()
}

/** Reconstruct the absolute request URL from an axios config (baseURL + url). */
function buildUrl(config: any): string {
  const url = String(config?.url ?? '')
  const base = String(config?.baseURL ?? '')
  if (!base) return url
  if (/^https?:\/\//i.test(url)) return url
  return base.replace(/\/+$/, '') + '/' + url.replace(/^\/+/, '')
}

/** Extract a lowercase reason string from the platform error envelope, or ''. */
function extractReason(data: unknown): string {
  if (typeof data === 'string') {
    return data.toLowerCase()
  }
  if (data && typeof data === 'object') {
    const err = (data as any).error ?? data
    const parts = [err?.code, err?.message, (data as any).message]
      .filter((v) => typeof v === 'string')
      .join(' ')
    return parts.toLowerCase()
  }
  return ''
}
