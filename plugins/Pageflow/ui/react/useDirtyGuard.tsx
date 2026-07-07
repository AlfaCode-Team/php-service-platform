import { useEffect } from 'react'
import { router } from '.'

export interface DirtyGuardOptions {
  /** Confirm prompt for in-app (Pageflow) navigations. Set '' to skip the prompt. */
  message?: string
  /** Also guard hard browser unloads (refresh/close/external links). Default true. */
  guardUnload?: boolean
}

/**
 * Warn the user before they navigate away from a form with unsaved changes.
 *
 * Guards both hard browser unloads (beforeunload) and in-app Pageflow visits
 * (the cancelable `before` event). No-ops when `dirty` is false.
 *
 *   const form = useForm({ ... })
 *   useDirtyGuard(form.isDirty)
 */
export default function useDirtyGuard(dirty: boolean, options: DirtyGuardOptions = {}): void {
  const message = options.message ?? 'You have unsaved changes. Leave this page?'
  const guardUnload = options.guardUnload ?? true

  useEffect(() => {
    if (!dirty) {
      return
    }

    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault()
      // Modern browsers show their own generic message; returnValue must be set.
      event.returnValue = ''
      return ''
    }

    if (guardUnload && typeof window !== 'undefined') {
      window.addEventListener('beforeunload', onBeforeUnload)
    }

    // Cancel an in-app Pageflow visit unless the user confirms.
    const removeBefore = router.on('before', () => {
      if (message === '') {
        return
      }
      if (typeof window !== 'undefined' && !window.confirm(message)) {
        return false // cancels the visit
      }
    })

    return () => {
      if (guardUnload && typeof window !== 'undefined') {
        window.removeEventListener('beforeunload', onBeforeUnload)
      }
      removeBefore()
    }
  }, [dirty, message, guardUnload])
}
