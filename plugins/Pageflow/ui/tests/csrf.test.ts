import { afterEach, describe, expect, it } from 'vitest'
import { configureCsrf, csrfHeaderName, csrfToken } from '../core/csrf'

function setMeta(name: string, content: string) {
  const meta = document.createElement('meta')
  meta.name = name
  meta.content = content
  document.head.appendChild(meta)
  return meta
}

afterEach(() => {
  document.head.querySelectorAll('meta').forEach((m) => m.remove())
  // Restore defaults for isolation between tests.
  configureCsrf({ headerName: 'X-CSRF-Token', metaName: 'csrf-token', resolver: null })
})

describe('csrf', () => {
  it('defaults to the platform X-CSRF-Token header', () => {
    expect(csrfHeaderName()).toBe('X-CSRF-Token')
  })

  it('reads the token from the <meta name="csrf-token"> tag', () => {
    setMeta('csrf-token', 'abc123')
    expect(csrfToken()).toBe('abc123')
  })

  it('returns null when no token is present', () => {
    expect(csrfToken()).toBeNull()
  })

  it('honours a custom meta name', () => {
    configureCsrf({ metaName: 'x-token' })
    setMeta('x-token', 'zzz')
    expect(csrfToken()).toBe('zzz')
  })

  it('prefers an explicit resolver over the meta tag', () => {
    setMeta('csrf-token', 'from-meta')
    configureCsrf({ resolver: () => 'from-resolver' })
    expect(csrfToken()).toBe('from-resolver')
  })

  it('allows overriding the header name', () => {
    configureCsrf({ headerName: 'X-XSRF-TOKEN' })
    expect(csrfHeaderName()).toBe('X-XSRF-TOKEN')
  })
})
