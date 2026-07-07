/**
 * Client-side registration for the Pageflow offline service worker.
 *
 * Serve `pageflow-sw.js` from your web root and register it once at bootstrap:
 *   import { registerPageflowSW } from '@pageflow/core'
 *   registerPageflowSW()
 *
 * On logout, call clearPageflowSWCache() to wipe any cached (possibly
 * user-specific) page objects — important on shared devices.
 */

export type ServiceWorkerOptions = {
  /** Path the SW is served from. Default '/pageflow-sw.js'. */
  url?: string
  /** Scope. Default '/'. */
  scope?: string
}

export function registerPageflowSW(options: ServiceWorkerOptions = {}): void {
  if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
    return
  }

  const url = options.url ?? '/pageflow-sw.js'
  const scope = options.scope ?? '/'

  // Register after load so it never competes with the initial render.
  window.addEventListener('load', () => {
    navigator.serviceWorker.register(url, { scope }).catch(() => {
      // Registration is best-effort; a failure just means no offline support.
    })
  })
}

/**
 * Wipe the SW caches (call on logout / identity change). Resolves even if no SW
 * is active.
 *
 * SECURITY: deletes the Cache Storage DIRECTLY from the page — the SW postMessage
 * is only a best-effort secondary signal. A `waiting`/`installing` SW would never
 * receive the message, which previously left authenticated pages cached after
 * logout; deleting from the window context closes that gap deterministically.
 */
export async function clearPageflowSWCache(): Promise<void> {
  // Direct deletion (works whenever the Cache API exists, SW active or not).
  if (typeof caches !== 'undefined') {
    try {
      const names = await caches.keys()
      await Promise.all(names.filter((n) => n.startsWith('pageflow-')).map((n) => caches.delete(n)))
    } catch {
      // Best-effort; fall through to the SW signal below.
    }
  }

  // Secondary signal so an active SW drops any in-flight/derived state too.
  if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
    const registration = await navigator.serviceWorker.getRegistration()
    registration?.active?.postMessage({ type: 'pageflow-clear' })
  }
}
