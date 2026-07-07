import {
  FormDataConvertible,
  Method,
  precognitiveValidate,
  Progress,
  router,
  VisitOptions,
} from '@pageflow/core'
import {
  createElement,
  forwardRef,
  ReactNode,
  useCallback,
  useRef,
  useState,
} from 'react'

type Errors = Record<string, string>

/** State + helpers handed to a render-prop child. */
export interface FormRenderProps {
  errors: Errors
  hasErrors: boolean
  processing: boolean
  progress: Progress | null
  wasSuccessful: boolean
  recentlySuccessful: boolean
  clearErrors: (...fields: string[]) => void
  setError: (field: string, message: string) => void
  reset: () => void
  submit: () => void
}

export interface PageflowFormProps
  extends Omit<React.FormHTMLAttributes<HTMLFormElement>, 'method' | 'action' | 'onError' | 'onSubmit' | 'children'> {
  action?: string
  method?: Method
  headers?: Record<string, string>
  only?: string[]
  except?: string[]
  reset?: string[]
  replace?: boolean
  preserveScroll?: VisitOptions['preserveScroll']
  preserveState?: VisitOptions['preserveState']
  errorBag?: string | null
  queryStringArrayFormat?: 'indices' | 'brackets'
  forceFormData?: boolean
  resetOnSuccess?: boolean | string[]
  resetOnError?: boolean | string[]
  /** Live server-truth validation: validate a field on blur or on change. */
  validateOn?: 'blur' | 'change'
  /** Debounce for validateOn="change" (ms). Default 300. */
  validationDebounce?: number
  /** Mutate the collected form data before it is sent. */
  transform?: (data: Record<string, FormDataConvertible>) => Record<string, FormDataConvertible>
  onBefore?: VisitOptions['onBefore']
  onStart?: VisitOptions['onStart']
  onProgress?: VisitOptions['onProgress']
  onSuccess?: VisitOptions['onSuccess']
  onError?: (errors: Errors) => void
  onFinish?: VisitOptions['onFinish']
  onCancel?: VisitOptions['onCancel']
  children?: ReactNode | ((props: FormRenderProps) => ReactNode)
}

/**
 * Turn a bracket/dot form-field name into a key path.
 *   "user[name]" -> ["user", "name"] ; "tags[]" -> ["tags", ""]
 */
function toPath(name: string): string[] {
  return name
    .replace(/\]/g, '')
    .split(/\[|\./)
    .filter((segment, index) => segment !== '' || index !== 0 || name.startsWith('['))
}

/** Assign a value into a nested object, creating arrays for empty `[]` segments. */
function assign(target: Record<string, any>, path: string[], value: FormDataConvertible): void {
  let node = target
  path.forEach((rawKey, index) => {
    const isLast = index === path.length - 1
    const key = rawKey === '' ? String((Array.isArray(node) ? node.length : Object.keys(node).length)) : rawKey

    if (isLast) {
      // Repeated field name -> collect into an array.
      if (node[key] !== undefined) {
        node[key] = Array.isArray(node[key]) ? [...node[key], value] : [node[key], value]
      } else {
        node[key] = value
      }
      return
    }

    if (node[key] === undefined) {
      node[key] = path[index + 1] === '' ? [] : {}
    }
    node = node[key]
  })
}

/** Serialize a native <form> into a nested plain object (File values preserved). */
function serializeForm(form: HTMLFormElement): Record<string, FormDataConvertible> {
  const data: Record<string, FormDataConvertible> = {}
  const formData = new FormData(form)

  for (const [name, value] of formData.entries()) {
    assign(data, toPath(name), value as FormDataConvertible)
  }

  return data
}

const Form = forwardRef<HTMLFormElement, PageflowFormProps>(function Form(
  {
    action = '',
    method = 'get',
    headers = {},
    only = [],
    except = [],
    reset: resetKeys = [],
    replace = false,
    preserveScroll,
    preserveState,
    errorBag = null,
    queryStringArrayFormat = 'brackets',
    forceFormData = false,
    resetOnSuccess,
    resetOnError,
    validateOn,
    validationDebounce = 300,
    transform,
    onBefore,
    onStart,
    onProgress,
    onSuccess,
    onError,
    onFinish,
    onCancel,
    children,
    ...props
  },
  ref,
) {
  const formRef = useRef<HTMLFormElement>(null)
  const setRef = (node: HTMLFormElement | null) => {
    formRef.current = node
    if (typeof ref === 'function') ref(node)
    else if (ref) (ref as React.MutableRefObject<HTMLFormElement | null>).current = node
  }

  const [errors, setErrors] = useState<Errors>({})
  const [processing, setProcessing] = useState(false)
  const [progress, setProgress] = useState<Progress | null>(null)
  const [wasSuccessful, setWasSuccessful] = useState(false)
  const [recentlySuccessful, setRecentlySuccessful] = useState(false)
  const successTimeout = useRef<ReturnType<typeof setTimeout>>()
  const validateTimer = useRef<ReturnType<typeof setTimeout>>()

  // Live per-field validation via precognition (validateOn="blur"|"change").
  const validateFieldNow = (field: string) => {
    if (!field || !formRef.current) return
    let data = serializeForm(formRef.current)
    if (transform) data = transform(data)
    precognitiveValidate(method, action, data, [field])
      .then((fieldErrors) => {
        setErrors((current) => {
          const next = { ...current }
          delete next[field]
          return { ...next, ...fieldErrors }
        })
      })
      .catch(() => {
        // Network/other failure: leave existing errors untouched (fail-open UX;
        // the real submit still enforces server-side).
      })
  }

  const handleFieldEvent = (event: React.SyntheticEvent, trigger: 'blur' | 'change') => {
    if (validateOn !== trigger) return
    const target = event.target as HTMLElement & { name?: string }
    const field = target?.name
    if (!field) return

    if (trigger === 'change') {
      clearTimeout(validateTimer.current)
      validateTimer.current = setTimeout(() => validateFieldNow(field), validationDebounce)
    } else {
      validateFieldNow(field)
    }
  }

  const clearErrors = useCallback((...fields: string[]) => {
    setErrors((current) => {
      if (fields.length === 0) return {}
      const next = { ...current }
      fields.forEach((field) => delete next[field])
      return next
    })
  }, [])

  const setError = useCallback((field: string, message: string) => {
    setErrors((current) => ({ ...current, [field]: message }))
  }, [])

  const resetForm = useCallback(() => {
    formRef.current?.reset()
  }, [])

  const applyReset = (which: boolean | string[] | undefined) => {
    if (which === true) {
      resetForm()
    } else if (Array.isArray(which) && which.length > 0 && formRef.current) {
      which.forEach((name) => {
        const field = formRef.current!.elements.namedItem(name)
        if (field && 'value' in field) (field as HTMLInputElement).value = ''
      })
    }
  }

  const scopedErrors = (all: Errors): Errors => (errorBag ? (all as any)[errorBag] || {} : all)

  const submit = useCallback(() => {
    if (!formRef.current) return

    let data = serializeForm(formRef.current)
    if (transform) data = transform(data)

    const options: VisitOptions = {
      method,
      data,
      headers,
      only,
      except,
      reset: resetKeys,
      replace,
      preserveScroll,
      preserveState,
      errorBag: errorBag ?? '',
      queryStringArrayFormat,
      forceFormData,
      onBefore,
      onProgress: (event) => {
        setProgress(event ?? null)
        onProgress?.(event)
      },
      onStart: (visit) => {
        setProcessing(true)
        setWasSuccessful(false)
        setRecentlySuccessful(false)
        clearTimeout(successTimeout.current)
        onStart?.(visit)
      },
      onSuccess: (page) => {
        setProcessing(false)
        setProgress(null)
        setErrors({})
        setWasSuccessful(true)
        setRecentlySuccessful(true)
        applyReset(resetOnSuccess)
        successTimeout.current = setTimeout(() => setRecentlySuccessful(false), 2000)
        return onSuccess?.(page)
      },
      onError: (allErrors) => {
        setProcessing(false)
        setProgress(null)
        const scoped = scopedErrors(allErrors as Errors)
        setErrors(scoped)
        applyReset(resetOnError)
        onError?.(scoped)
      },
      onCancel,
      onFinish: (visit) => {
        setProcessing(false)
        setProgress(null)
        onFinish?.(visit)
      },
    }

    router.visit(action, options)
  }, [
    action, method, headers, only, except, resetKeys, replace, preserveScroll, preserveState,
    errorBag, queryStringArrayFormat, forceFormData, transform, resetOnSuccess, resetOnError,
    onBefore, onStart, onProgress, onSuccess, onError, onFinish, onCancel,
  ])

  const renderProps: FormRenderProps = {
    errors,
    hasErrors: Object.keys(errors).length > 0,
    processing,
    progress,
    wasSuccessful,
    recentlySuccessful,
    clearErrors,
    setError,
    reset: resetForm,
    submit,
  }

  return createElement(
    'form',
    {
      ...props,
      ref: setRef,
      action,
      method: method === 'get' ? 'get' : 'post',
      onSubmit: (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault()
        submit()
      },
      // Event delegation for live validation — capture blur (which doesn't
      // bubble) and change from any named field inside the form.
      ...(validateOn
        ? {
            onBlurCapture: (e: React.FocusEvent) => handleFieldEvent(e, 'blur'),
            onChange: (e: React.ChangeEvent) => handleFieldEvent(e, 'change'),
          }
        : {}),
    },
    typeof children === 'function' ? (children as (p: FormRenderProps) => ReactNode)(renderProps) : children,
  )
})

Form.displayName = 'PageflowForm'

export default Form
