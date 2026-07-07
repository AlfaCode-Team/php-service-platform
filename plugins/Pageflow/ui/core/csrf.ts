/**
 * CSRF integration for the AlfacodeTeam PhpServicePlatform.
 *
 * The platform's `CsrfTokenLayer` (see docs/ai-context/21_CSRF.md) expects an
 * HMAC-signed token on every mutating request, sent in the `X-CSRF-Token`
 * header (or the `_csrf_token` field for classic form posts). Unlike Laravel,
 * it does NOT use the `XSRF-TOKEN` cookie double-submit scheme, so axios cannot
 * auto-attach it — Pageflow must read the token the server rendered into the
 * page and forward it itself.
 *
 * Server contract: render the current token into the document head, e.g.
 *   <meta name="csrf-token" content="{{ csrf_token() }}">
 * (or expose it as a shared prop / global; see `configureCsrf`).
 */

export type CsrfConfig = {
  /** Header name the platform's CsrfTokenLayer reads. Default: 'X-CSRF-Token'. */
  headerName: string
  /** <meta name="..."> that carries the token in the rendered HTML. Default: 'csrf-token'. */
  metaName: string
  /**
   * Optional explicit resolver. When set, it wins over the meta tag — use this
   * to read the token from a shared prop, cookie, or global instead.
   */
  resolver: (() => string | null | undefined) | null
}

const config: CsrfConfig = {
  headerName: 'X-CSRF-Token',
  metaName: 'csrf-token',
  resolver: null,
}

/**
 * Override CSRF behaviour once, at app bootstrap (before the first visit).
 * All fields are optional and merge over the defaults.
 */
export function configureCsrf(overrides: Partial<CsrfConfig>): void {
  Object.assign(config, overrides)
}

/** The header name the platform expects the token in. */
export function csrfHeaderName(): string {
  return config.headerName
}

/**
 * Resolve the current CSRF token, or `null` when none is available
 * (SSR, or a page the server rendered without a token — e.g. a pure GET app).
 */
export function csrfToken(): string | null {
  if (config.resolver) {
    return config.resolver() ?? null
  }

  if (typeof document === 'undefined') {
    return null
  }

  const meta = document.head?.querySelector<HTMLMetaElement>(`meta[name="${config.metaName}"]`)

  return meta?.content || null
}

/**
 * True when `url` targets the SAME origin as the current page (or is a relative
 * URL). Used to gate CSRF-token attachment: the token must NEVER be sent to a
 * third-party origin (that would leak it). Malformed URLs are treated as
 * cross-origin (fail closed).
 */
export function isSameOriginUrl(url: string | undefined | null): boolean {
  if (typeof window === 'undefined') {
    // SSR: no cross-origin concept; treat as same-origin so headers still build.
    return true
  }
  if (!url) {
    return true // relative/empty → same origin
  }
  try {
    return new URL(url, window.location.href).origin === window.location.origin
  } catch {
    return false
  }
}

/**
 * Update the stored CSRF token (writes the <meta> tag, creating it if absent).
 * Used by the auto-refresh interceptor after fetching a fresh token; no-op when
 * a custom resolver owns the token.
 */
export function setCsrfToken(token: string): void {
  if (config.resolver || typeof document === 'undefined') {
    return
  }

  let meta = document.head?.querySelector<HTMLMetaElement>(`meta[name="${config.metaName}"]`)
  if (!meta) {
    meta = document.createElement('meta')
    meta.name = config.metaName
    document.head?.appendChild(meta)
  }
  meta.content = token
}
