import { clearPageflowSWCache } from '@pageflow/core'
import { useEffect, useRef } from 'react'
import { router } from '.'
import useAuth from './useAuth'

/**
 * Flush the prefetch cache whenever the authenticated identity changes.
 *
 * SECURITY: the prefetch cache is keyed only by URL. Without this, a page
 * prefetched as user A could be served from cache to user B after a login/logout
 * in the SAME tab (or a tenant switch), leaking data across principals. Placing
 * this once near the app root clears any pre-authorized pages on every identity
 * transition, so post-switch navigations always re-fetch through the pipeline.
 *
 *   function Root() { useFlushOnIdentityChange(); return <Outlet /> }
 */
export default function useFlushOnIdentityChange(): void {
  const { userId, tenantId } = useAuth()
  const previous = useRef<string | null>(null)
  const principal = `${tenantId}::${userId}`

  useEffect(() => {
    if (previous.current !== null && previous.current !== principal) {
      // Wipe both the in-memory prefetch cache AND the service-worker page
      // cache so no pre-authorized page survives the principal switch.
      router.flushAll()
      void clearPageflowSWCache()
    }
    previous.current = principal
  }, [principal])
}
