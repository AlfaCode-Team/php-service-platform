import { Errors, Method, precognitiveValidate, RequestPayload } from '@pageflow/core'
import { useCallback, useEffect, useRef, useState } from 'react'

export interface UsePrecognitionOptions {
  /** Debounce validation calls (ms) — protects the server from per-keystroke spam. Default 300. */
  debounce?: number
}

export interface PrecognitionHelpers {
  errors: Errors
  hasErrors: boolean
  validating: boolean
  /** Validate the whole form (or only `fields`) against the server rules. */
  validate: (fields?: string[]) => Promise<boolean>
  /** Convenience: validate a single field — ideal for onBlur/onChange. */
  validateField: (field: string) => Promise<boolean>
  clearErrors: (...fields: string[]) => void
  setErrors: (errors: Errors) => void
}

/**
 * Live, server-truth validation for a form endpoint — no rule duplication.
 *
 * Calls are debounced (default 300ms) so typing doesn't hammer the server; pair
 * with the platform `throttle` route filter for a hard server-side ceiling.
 *
 *   const pre = usePrecognition('post', '/register', () => data)
 *   <input onBlur={() => pre.validateField('email')} />
 */
export default function usePrecognition(
  method: Method,
  url: string,
  getData: () => RequestPayload,
  options: UsePrecognitionOptions = {},
): PrecognitionHelpers {
  const [errors, setErrorsState] = useState<Errors>({})
  const [validating, setValidating] = useState(false)

  // Refs keep the callback identity stable and avoid stale closures.
  const errorsRef = useRef(errors)
  errorsRef.current = errors
  const getDataRef = useRef(getData)
  getDataRef.current = getData
  const runId = useRef(0)
  const timer = useRef<ReturnType<typeof setTimeout>>()
  const debounceMs = options.debounce ?? 300

  useEffect(() => () => clearTimeout(timer.current), [])

  const runValidation = useCallback(
    async (fields: string[]): Promise<boolean> => {
      const id = ++runId.current
      setValidating(true)
      try {
        const result = await precognitiveValidate(method, url, getDataRef.current(), fields)
        if (id !== runId.current) {
          // A newer run superseded us; don't clobber fresher results.
          return Object.keys(errorsRef.current).length === 0
        }
        setErrorsState((current) => {
          if (fields.length === 0) return result
          const next = { ...current }
          fields.forEach((field) => delete next[field])
          return { ...next, ...result }
        })
        return Object.keys(result).length === 0
      } finally {
        if (id === runId.current) setValidating(false)
      }
    },
    [method, url],
  )

  const validate = useCallback(
    (fields: string[] = []) =>
      new Promise<boolean>((resolve) => {
        clearTimeout(timer.current)
        timer.current = setTimeout(() => {
          runValidation(fields).then(resolve)
        }, debounceMs)
      }),
    [runValidation, debounceMs],
  )

  const validateField = useCallback((field: string) => validate([field]), [validate])

  const clearErrors = useCallback((...fields: string[]) => {
    setErrorsState((current) => {
      if (fields.length === 0) return {}
      const next = { ...current }
      fields.forEach((field) => delete next[field])
      return next
    })
  }, [])

  return {
    errors,
    hasErrors: Object.keys(errors).length > 0,
    validating,
    validate,
    validateField,
    clearErrors,
    setErrors: setErrorsState,
  }
}
