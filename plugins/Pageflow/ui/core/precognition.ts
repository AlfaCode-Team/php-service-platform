import { default as axios } from 'axios'
import { csrfHeaderName, csrfToken, isSameOriginUrl } from './csrf'
import { Method, RequestPayload } from './types'

export type Errors = Record<string, string>

/**
 * Precognition — run the server's validation for a request WITHOUT executing it.
 *
 * The server sees `Precognition: true`, runs only its validation rules, and
 * replies 2xx (valid) or 422 with `{ errors: {...} }`. No controller side
 * effects occur. This powers live, server-truth validation (validate on blur)
 * without duplicating rules on the client.
 *
 * `only` restricts validation to specific fields (validate-as-you-type).
 *
 * SECURITY: still a normal authenticated request (CSRF token + credentials), so
 * the server enforces the same auth as the real submit. Precognition responses
 * MUST NOT include data — only field errors.
 */
export async function precognitiveValidate(
  method: Method,
  url: string,
  data: RequestPayload,
  only: string[] = [],
): Promise<Errors> {
  const headers: Record<string, string> = {
    'X-Pageflow': 'true',
    Precognition: 'true',
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'application/json',
  }

  if (only.length > 0) {
    headers['Precognition-Validate-Only'] = only.join(',')
  }

  // Mutating precognition still needs the CSRF token (server auth is unchanged),
  // but ONLY for same-origin targets — never leak it to a third-party host.
  if (method !== 'get' && isSameOriginUrl(url)) {
    const token = csrfToken()
    if (token) {
      headers[csrfHeaderName()] = token
    }
  }

  try {
    await axios({
      method,
      url,
      data: method === 'get' ? undefined : data,
      params: method === 'get' ? data : undefined,
      headers,
      withCredentials: isSameOriginUrl(url),
    })
    return {}
  } catch (error: any) {
    // 422 => validation errors. Anything else is a real failure — rethrow.
    if (error?.response?.status === 422) {
      const body = error.response.data
      const errors = typeof body === 'string' ? safeParse(body) : body
      return normalizeErrors(errors)
    }
    throw error
  }
}

function safeParse(raw: string): unknown {
  try {
    return JSON.parse(raw)
  } catch {
    return {}
  }
}

/** Accept both `{ errors: {...} }` and a bare `{ field: message }` shape. */
export function normalizeErrors(body: any): Errors {
  const source = body && typeof body === 'object' && body.errors ? body.errors : body
  if (!source || typeof source !== 'object') {
    return {}
  }

  const out: Errors = {}
  for (const [field, message] of Object.entries(source)) {
    out[field] = Array.isArray(message) ? String(message[0] ?? '') : String(message ?? '')
  }
  return out
}
