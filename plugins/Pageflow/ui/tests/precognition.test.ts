import { describe, expect, it } from 'vitest'
import { normalizeErrors } from '../core/precognition'

describe('normalizeErrors', () => {
  it('reads a { errors: {...} } envelope', () => {
    expect(normalizeErrors({ errors: { email: 'Required.' } })).toEqual({ email: 'Required.' })
  })

  it('reads a bare { field: message } shape', () => {
    expect(normalizeErrors({ email: 'Required.' })).toEqual({ email: 'Required.' })
  })

  it('flattens array messages to the first entry', () => {
    expect(normalizeErrors({ errors: { pw: ['Too short', 'No digit'] } })).toEqual({ pw: 'Too short' })
  })

  it('coerces non-string messages', () => {
    expect(normalizeErrors({ n: 5 })).toEqual({ n: '5' })
  })

  it('returns {} for null / non-object', () => {
    expect(normalizeErrors(null)).toEqual({})
    expect(normalizeErrors('nope')).toEqual({})
    expect(normalizeErrors(undefined)).toEqual({})
  })

  it('handles an empty errors envelope', () => {
    expect(normalizeErrors({ errors: {} })).toEqual({})
  })
})
