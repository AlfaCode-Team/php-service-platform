import { describe, expect, it } from 'vitest'
import Queue from '../core/queue'

describe('Queue', () => {
  it('runs queued tasks in FIFO order', async () => {
    const order: number[] = []
    const q = new Queue<Promise<void>>()

    q.add(() => Promise.resolve().then(() => void order.push(1)))
    q.add(() => Promise.resolve().then(() => void order.push(2)))
    await q.add(() => Promise.resolve().then(() => void order.push(3)))

    expect(order).toEqual([1, 2, 3])
  })

  it('does not overlap tasks (each awaits the previous)', async () => {
    const events: string[] = []
    const q = new Queue<Promise<void>>()

    q.add(
      () =>
        new Promise<void>((resolve) => {
          events.push('start-1')
          setTimeout(() => {
            events.push('end-1')
            resolve()
          }, 20)
        }),
    )
    await q.add(
      () =>
        new Promise<void>((resolve) => {
          events.push('start-2')
          resolve()
        }),
    )

    expect(events).toEqual(['start-1', 'end-1', 'start-2'])
  })
})
