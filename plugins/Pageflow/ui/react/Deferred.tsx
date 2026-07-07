import { ReactElement, useEffect, useMemo, useState } from 'react'
import { router } from '.'
import usePage from './usePage'

const urlWithoutHash = (url: URL | Location): URL => {
  url = new URL(url.href)
  url.hash = ''

  return url
}

const isSameUrlWithoutHash = (url1: URL | Location, url2: URL | Location): boolean => {
  return urlWithoutHash(url1).href === urlWithoutHash(url2).href
}

interface DeferredProps {
  children: ReactElement | number | string
  fallback: ReactElement | number | string
  data: string | string[]
}

const Deferred = ({ children, data, fallback }: DeferredProps) => {
  if (!data) {
    throw new Error('`<Deferred>` requires a `data` prop to be a string or array of strings')
  }

  const [loaded, setLoaded] = useState(false)
  const pageProps = usePage().props
  const keys = useMemo(() => (Array.isArray(data) ? data : [data]), [data])

  useEffect(() => {
    const removeListener = router.on('start', (e) => {
      const onlyKeys = e.detail.visit.only
      const exceptKeys = e.detail.visit.except

      // Determine whether this visit will (re)load any of our deferred keys:
      // - `only`: reloads a key only if it is listed
      // - `except`: reloads a key unless it is excluded
      // - neither: a full visit reloads everything
      const isReloadingKey =
        onlyKeys.length > 0
          ? onlyKeys.some((key) => keys.includes(key))
          : exceptKeys.length > 0
            ? !exceptKeys.some((key) => keys.includes(key))
            : true

      if (isSameUrlWithoutHash(e.detail.visit.url, window.location) && isReloadingKey) {
        setLoaded(false)
      }
    })

    return () => {
      removeListener()
    }
  }, [])

  useEffect(() => {
    setLoaded(keys.every((key) => pageProps[key] !== undefined))
  }, [pageProps, keys])

  return loaded ? children : fallback
}

Deferred.displayName = 'PageflowDeferred'

export default Deferred
