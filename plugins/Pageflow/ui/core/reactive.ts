/**
 * Reactive props — secure server push for Pageflow.
 *
 * SECURITY MODEL (read before changing anything):
 *   The server NEVER pushes prop data over this channel. It pushes only a
 *   lightweight "these prop keys are stale" signal. The client reacts by doing
 *   a NORMAL authenticated partial reload (router.reload({ only: keys })), which
 *   flows through the full kernel pipeline again — SecurityStage, route filters,
 *   authorization, tenant routing. So even a hijacked/forged channel can leak
 *   nothing: the worst it can do is ask the client to re-fetch data it is already
 *   allowed to see. Authentication of the channel itself is the session cookie
 *   (EventSource with credentials); the server authorizes the subscription.
 *
 * Wire format (text/event-stream):
 *   event: stale
 *   data: {"keys":["orders","stats"]}
 *
 *   event: ping           (keep-alive, ignored)
 *   data: {}
 */

import { isSameOriginUrl } from './csrf'

export type ReactiveFrame = { keys: string[] }

export type ReactiveOptions = {
  /** Endpoint that streams stale-key signals. Default: '/pageflow/stream'. */
  endpoint?: string
  /** Opaque channel/topic name the server uses to scope the subscription. */
  channel?: string
  /** Extra query params (e.g. a resource id) the server needs to authorize/scope. */
  params?: Record<string, string>
  /** Called with the stale keys the client should reload. */
  onStale: (keys: string[]) => void
  /** Optional error hook (network drop, auth failure). EventSource auto-reconnects. */
  onError?: (event: Event) => void
}

/** True when the current environment can open a server-sent-events stream. */
export function supportsReactive(): boolean {
  return typeof window !== 'undefined' && typeof window.EventSource !== 'undefined'
}

/**
 * Open a reactive stream. Returns a disposer that closes it.
 * No-ops (returns a noop disposer) when EventSource is unavailable (SSR/old env).
 */
export function openReactiveStream(options: ReactiveOptions): () => void {
  if (!supportsReactive()) {
    return () => {}
  }

  const endpoint = options.endpoint ?? '/pageflow/stream'
  const query = new URLSearchParams()

  if (options.channel) {
    query.set('channel', options.channel)
  }
  for (const [key, value] of Object.entries(options.params ?? {})) {
    query.set(key, value)
  }

  const url = query.toString() ? `${endpoint}?${query.toString()}` : endpoint

  // withCredentials => the platform session cookie authenticates the stream.
  // Only for a same-origin endpoint, so the cookie is never sent to a third party.
  const source = new EventSource(url, { withCredentials: isSameOriginUrl(endpoint) })

  const handleStale = (event: MessageEvent) => {
    const keys = parseKeys(event.data)
    if (keys.length > 0) {
      options.onStale(keys)
    }
  }

  source.addEventListener('stale', handleStale as EventListener)
  // Some servers emit unnamed `message` events; treat them as stale too.
  source.addEventListener('message', handleStale as EventListener)

  if (options.onError) {
    source.addEventListener('error', options.onError)
  }

  return () => {
    source.removeEventListener('stale', handleStale as EventListener)
    source.removeEventListener('message', handleStale as EventListener)
    if (options.onError) {
      source.removeEventListener('error', options.onError)
    }
    source.close()
  }
}

/** Defensively parse a `stale` frame; malformed input yields no keys (fail-closed). */
export function parseKeys(raw: unknown): string[] {
  if (typeof raw !== 'string' || raw === '') {
    return []
  }

  try {
    const parsed = JSON.parse(raw) as ReactiveFrame | string[]
    const keys = Array.isArray(parsed) ? parsed : parsed?.keys
    if (!Array.isArray(keys)) {
      return []
    }
    // Only accept plain non-empty strings — never trust arbitrary shapes.
    return keys.filter((key): key is string => typeof key === 'string' && key !== '')
  } catch {
    return []
  }
}
