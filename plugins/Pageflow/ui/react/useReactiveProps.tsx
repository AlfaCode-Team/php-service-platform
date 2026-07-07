import { openReactiveStream, ReactiveOptions } from '@pageflow/core'
import { useEffect, useRef } from 'react'
import { router } from '.'

export type UseReactivePropsOptions = Omit<ReactiveOptions, 'onStale'> & {
  /** Debounce bursts of signals into a single reload (ms). Default 50. */
  debounce?: number
  /** Extra reload options (headers, preserveScroll…). */
  reload?: Record<string, unknown>
}

/**
 * Subscribe to server-pushed "stale" signals and reload the given prop keys
 * through the NORMAL authenticated pipeline (router.reload({ only })).
 *
 * The server never sends data here — only which keys changed — so this cannot
 * leak anything a normal partial reload wouldn't. See core/reactive.ts.
 *
 *   useReactiveProps(['orders', 'stats'], { channel: 'dashboard' })
 */
export default function useReactiveProps(
  keys: string | string[],
  options: UseReactivePropsOptions = {},
): void {
  const keyList = Array.isArray(keys) ? keys : [keys]
  const keyToken = keyList.join(',')
  const timer = useRef<ReturnType<typeof setTimeout>>()

  useEffect(() => {
    const watched = new Set(keyList)
    const debounceMs = options.debounce ?? 50

    const dispose = openReactiveStream({
      endpoint: options.endpoint,
      channel: options.channel,
      params: options.params,
      onError: options.onError,
      onStale: (staleKeys) => {
        // Only react to keys this component actually cares about.
        const relevant = staleKeys.filter((key) => watched.has(key))
        if (relevant.length === 0) {
          return
        }

        clearTimeout(timer.current)
        timer.current = setTimeout(() => {
          router.reload({ ...options.reload, only: relevant })
        }, debounceMs)
      },
    })

    return () => {
      clearTimeout(timer.current)
      dispose()
    }
    // Re-subscribe when the watched keys, channel, endpoint, or scoping params
    // change (params can carry a resource id → a different logical channel).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [keyToken, options.channel, options.endpoint, JSON.stringify(options.params)])
}
