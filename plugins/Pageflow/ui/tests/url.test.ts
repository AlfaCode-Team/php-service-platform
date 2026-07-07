import { describe, expect, it } from 'vitest'
import {
  hrefToUrl,
  isSameUrlWithoutHash,
  mergeDataIntoQueryString,
  urlWithoutHash,
} from '../core/url'

describe('hrefToUrl', () => {
  it('resolves a relative path against the current origin', () => {
    const url = hrefToUrl('/users')
    expect(url.pathname).toBe('/users')
  })

  it('accepts an absolute URL untouched', () => {
    const url = hrefToUrl('https://example.com/a?b=1')
    expect(url.host).toBe('example.com')
    expect(url.search).toBe('?b=1')
  })
})

describe('mergeDataIntoQueryString', () => {
  it('appends GET data to the query string', () => {
    const [href] = mergeDataIntoQueryString('get', '/users', { page: 2, sort: 'name' })
    expect(href).toContain('page=2')
    expect(href).toContain('sort=name')
  })

  it('merges with existing query params', () => {
    const [href] = mergeDataIntoQueryString('get', '/users?active=1', { page: 2 })
    expect(href).toContain('active=1')
    expect(href).toContain('page=2')
  })

  it('leaves data untouched for non-GET methods and returns it separately', () => {
    const [href, data] = mergeDataIntoQueryString('post', '/users', { name: 'Sam' })
    expect(href).toBe('/users')
    expect(data).toEqual({ name: 'Sam' })
  })

  it('serializes arrays using bracket format by default', () => {
    const [href] = mergeDataIntoQueryString('get', '/users', { ids: [1, 2] })
    expect(decodeURIComponent(href)).toContain('ids[]=1')
    expect(decodeURIComponent(href)).toContain('ids[]=2')
  })

  it('preserves the hash fragment', () => {
    const [href] = mergeDataIntoQueryString('get', '/users#section', { page: 2 })
    expect(href).toContain('#section')
  })
})

describe('urlWithoutHash / isSameUrlWithoutHash', () => {
  it('strips the hash', () => {
    expect(urlWithoutHash(new URL('https://x.test/a#top')).href).toBe('https://x.test/a')
  })

  it('treats two URLs differing only by hash as the same', () => {
    expect(isSameUrlWithoutHash(new URL('https://x.test/a#one'), new URL('https://x.test/a#two'))).toBe(true)
  })

  it('treats different paths as different', () => {
    expect(isSameUrlWithoutHash(new URL('https://x.test/a'), new URL('https://x.test/b'))).toBe(false)
  })
})
