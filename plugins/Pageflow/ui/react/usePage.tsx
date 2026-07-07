import { Page, PageProps } from '@pageflow/core'
import { useContext } from 'react'
import PageContext from './PageContext'

// TPageProps is the PAGE-SPECIFIC props only — the always-present shared props
// (PageProps: pageflow_auth, errors, …) are merged into the return type here, so
// a page types `usePage<{ users: User[] }>()` WITHOUT re-declaring shared props
// yet still reads props.pageflow_auth. Hence the loose Record constraint.
export default function usePage<
  TPageProps extends Record<string, unknown> = Record<string, unknown>,
>(): Page<TPageProps & PageProps> {
  const page = useContext(PageContext)

  if (!page) {
    throw new Error('usePage must be used within the Pageflow component')
  }

  return page as unknown as Page<TPageProps & PageProps>
}
