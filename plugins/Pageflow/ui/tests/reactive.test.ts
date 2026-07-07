import { describe, expect, it } from 'vitest'
import { parseKeys } from '../core/reactive'

describe('parseKeys (reactive push — fail closed)', () => {
  it('parses a { keys: [...] } frame', () => {
    expect(parseKeys('{"keys":["orders","stats"]}')).toEqual(['orders', 'stats'])
  })

  it('parses a bare array frame', () => {
    expect(parseKeys('["a","b"]')).toEqual(['a', 'b'])
  })

  it('drops non-string and empty keys', () => {
    expect(parseKeys('{"keys":["ok", 1, "", null, "x"]}')).toEqual(['ok', 'x'])
  })

  it('returns [] for malformed JSON (never throws)', () => {
    expect(parseKeys('{not json')).toEqual([])
  })

  it('returns [] for non-string input', () => {
    expect(parseKeys(undefined)).toEqual([])
    expect(parseKeys(42)).toEqual([])
    expect(parseKeys('')).toEqual([])
  })

  it('returns [] when keys is not an array', () => {
    expect(parseKeys('{"keys":"orders"}')).toEqual([])
    expect(parseKeys('{"keys":{"0":"x"}}')).toEqual([])
  })
})
