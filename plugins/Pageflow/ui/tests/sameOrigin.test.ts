import { describe, expect, it } from 'vitest'
import { isSameOriginUrl } from '../core/csrf'

// jsdom default origin is http://localhost:3000
describe('isSameOriginUrl (CSRF token must never cross origin)', () => {
  it('treats relative/empty URLs as same-origin', () => {
    expect(isSameOriginUrl('/users')).toBe(true)
    expect(isSameOriginUrl('')).toBe(true)
    expect(isSameOriginUrl(undefined)).toBe(true)
    expect(isSameOriginUrl(null)).toBe(true)
  })

  it('accepts an absolute same-origin URL', () => {
    expect(isSameOriginUrl(`${window.location.origin}/api/x`)).toBe(true)
  })

  it('rejects a third-party origin', () => {
    expect(isSameOriginUrl('https://evil.com/x')).toBe(false)
    expect(isSameOriginUrl('http://attacker.example/steal')).toBe(false)
  })

  it('rejects a protocol-relative cross-origin URL', () => {
    expect(isSameOriginUrl('//evil.com/x')).toBe(false)
  })

  it('rejects a look-alike subdomain of a trusted host', () => {
    expect(isSameOriginUrl('https://localhost.evil.com/x')).toBe(false)
  })

  it('treats an unparseable-as-absolute string as a same-origin relative path (safe)', () => {
    // Resolves against the base as a relative reference → our own origin, so
    // attaching the token here is safe (it never leaves our server).
    expect(isSameOriginUrl('ht!tp://weird')).toBe(true)
  })
})
