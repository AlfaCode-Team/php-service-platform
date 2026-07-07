import { describe, expect, it } from 'vitest'
import { resolvePageComponent } from '../index'

describe('resolvePageComponent', () => {
  const pages = {
    'Users/Index': { name: 'index' },
    'Users/Show': () => Promise.resolve({ name: 'show' }),
    'Home': () => ({ name: 'home' }),
  }

  it('returns a direct component value', async () => {
    expect(await resolvePageComponent('Users/Index', pages)).toEqual({ name: 'index' })
  })

  it('awaits an async loader', async () => {
    expect(await resolvePageComponent('Users/Show', pages)).toEqual({ name: 'show' })
  })

  it('calls a synchronous loader', async () => {
    expect(await resolvePageComponent('Home', pages)).toEqual({ name: 'home' })
  })

  it('tries candidate paths in order', async () => {
    expect(await resolvePageComponent(['Missing', 'Users/Index'], pages)).toEqual({ name: 'index' })
  })

  it('throws when no candidate matches', async () => {
    await expect(resolvePageComponent('Nope', pages)).rejects.toThrow('Page not found: Nope')
  })
})
