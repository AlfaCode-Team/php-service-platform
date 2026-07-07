import { ReactElement, ReactNode } from 'react'
import useAuth from './useAuth'

export interface CanProps {
  /** Require this single permission. */
  permission?: string
  /** Require ANY of these permissions. */
  anyOf?: string[]
  /** Require ALL of these permissions. */
  allOf?: string[]
  /** Require this role. */
  role?: string
  /** Rendered when the check fails. Default: nothing. */
  fallback?: ReactNode
  children: ReactNode
}

/**
 * Conditionally render UI based on the current identity's permissions/roles.
 *
 *   <Can permission="invoice:create"><NewInvoiceButton /></Can>
 *   <Can anyOf={['post:edit', 'post:moderate']} fallback={<ReadOnly />}> … </Can>
 *
 * SECURITY: presentation only. This hides UI; it does not protect data or
 * actions — the server Service layer is the real authorization boundary.
 */
export default function Can({
  permission,
  anyOf,
  allOf,
  role,
  fallback = null,
  children,
}: CanProps): ReactElement {
  const auth = useAuth()

  const checks: boolean[] = []
  if (permission !== undefined) checks.push(auth.can(permission))
  if (anyOf !== undefined) checks.push(auth.canAny(...anyOf))
  if (allOf !== undefined) checks.push(auth.canAll(...allOf))
  if (role !== undefined) checks.push(auth.hasRole(role))

  // No criteria given -> nothing to authorize -> render (avoids silent hiding).
  const allowed = checks.length === 0 ? true : checks.every(Boolean)

  return <>{allowed ? children : fallback}</>
}
