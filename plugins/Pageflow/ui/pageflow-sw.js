/**
 * Pageflow service worker — optional offline support.
 *
 * Strategy:
 *   • Static assets (script/style/font/image) → cache-first (fast repeat loads).
 *   • Navigations & Pageflow GET page objects → network-first, falling back to
 *     the cache ONLY when offline (so users see the last-known page instead of
 *     the browser's dinosaur).
 *   • Non-GET requests are never touched (mutations always hit the network).
 *
 * SECURITY: cached page objects can contain user data. This cache is per-origin
 * and per-browser-profile, but on a SHARED device it could outlive a logout.
 * The client calls clients.claim() and the app should postMessage
 * {type:'pageflow-clear'} on logout to wipe it (handled below). Do not enable
 * this SW for highly sensitive apps without that logout wiring.
 */

const VERSION = 'pageflow-v1'
const STATIC_CACHE = `${VERSION}-static`
const PAGE_CACHE = `${VERSION}-pages`

self.addEventListener('install', (event) => {
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      // Drop OUR older-version caches only — never touch other apps' caches
      // that may live on the same origin.
      const names = await caches.keys()
      await Promise.all(
        names
          .filter((n) => n.startsWith('pageflow-') && !n.startsWith(VERSION))
          .map((n) => caches.delete(n)),
      )
      await self.clients.claim()
    })(),
  )
})

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'pageflow-clear') {
    event.waitUntil(
      caches
        .keys()
        .then((names) =>
          Promise.all(names.filter((n) => n.startsWith('pageflow-')).map((n) => caches.delete(n))),
        ),
    )
  }
})

self.addEventListener('fetch', (event) => {
  const request = event.request

  if (request.method !== 'GET') {
    return // never cache mutations
  }

  const url = new URL(request.url)
  if (url.origin !== self.location.origin) {
    return // only manage same-origin
  }

  const isPageflow =
    request.headers.get('X-Pageflow') !== null ||
    request.mode === 'navigate' ||
    (request.headers.get('accept') || '').includes('text/html')

  if (isPageflow) {
    event.respondWith(networkFirst(request))
    return
  }

  if (['script', 'style', 'font', 'image'].includes(request.destination)) {
    event.respondWith(cacheFirst(request))
  }
})

function isCacheable(response) {
  // OPT-IN, secure by default: a page object is cached for offline use ONLY when
  // the server explicitly allows it — either `Cache-Control: public` or the
  // opt-in header `X-Pageflow-Cache: 1`. Authenticated pages (which carry
  // neither) are therefore NEVER cached by default, so a forgotten
  // Cache-Control can't leak user data at rest. `no-store`/`private` always win.
  const cc = (response.headers.get('Cache-Control') || '').toLowerCase()
  if (cc.includes('no-store') || cc.includes('private')) {
    return false
  }
  const optIn = response.headers.get('X-Pageflow-Cache') === '1'
  return optIn || cc.includes('public')
}

async function networkFirst(request) {
  try {
    const response = await fetch(request)
    if (response && response.ok && isCacheable(response)) {
      const cache = await caches.open(PAGE_CACHE)
      cache.put(request, response.clone())
    }
    return response
  } catch (error) {
    const cached = await caches.match(request)
    if (cached) {
      return cached
    }
    throw error
  }
}

async function cacheFirst(request) {
  const cached = await caches.match(request)
  if (cached) {
    return cached
  }
  const response = await fetch(request)
  if (response && response.ok) {
    const cache = await caches.open(STATIC_CACHE)
    cache.put(request, response.clone())
  }
  return response
}
