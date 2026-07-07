import { Page, PageProps, PageResolver, router, setupProgress } from '@pageflow/core'
import { ComponentType, FunctionComponent, Key, ReactElement, ReactNode, createElement } from 'react'
import { renderToString } from 'react-dom/server'
import App from './App'

type ReactInstance = ReactElement
type ReactComponent = ReactNode

type HeadManagerOnUpdate = (elements: string[]) => void // TODO: When shipped, replace with: Pageflow.HeadManagerOnUpdate
type HeadManagerTitleCallback = (title: string) => string // TODO: When shipped, replace with: Pageflow.HeadManagerTitleCallback

type AppType<SharedProps extends PageProps = PageProps> = FunctionComponent<
  {
    children?: (props: { Component: ComponentType; key: Key; props: Page<SharedProps>['props'] }) => ReactNode
  } & SetupOptions<unknown, SharedProps>['props']
>

export type SetupOptions<ElementType, SharedProps extends PageProps> = {
  el: ElementType
  App: AppType
  props: {
    initialPage: Page<SharedProps>
    initialComponent: ReactComponent
    resolveComponent: PageResolver
    titleCallback?: HeadManagerTitleCallback
    onHeadUpdate?: HeadManagerOnUpdate
  }
}

type BasePageflowAppOptions = {
  title?: HeadManagerTitleCallback
  resolve: PageResolver
}

type CreatePageflowAppSetupReturnType = ReactInstance | void
type PageflowAppOptionsForCSR<SharedProps extends PageProps> = BasePageflowAppOptions & {
  id?: string
  page?: Page | string
  render?: undefined
  progress?:
    | false
    | {
        delay?: number
        color?: string
        includeCSS?: boolean
        showSpinner?: boolean
      }
  setup(options: SetupOptions<HTMLElement, SharedProps>): CreatePageflowAppSetupReturnType
}

type CreatePageflowAppSSRContent = { head: string[]; body: string }
type PageflowAppOptionsForSSR<SharedProps extends PageProps> = BasePageflowAppOptions & {
  id?: undefined
  page: Page | string
  render: typeof renderToString
  progress?: undefined
  setup(options: SetupOptions<null, SharedProps>): ReactInstance
}

export default async function createPageflowApp<SharedProps extends PageProps = PageProps>(
  options: PageflowAppOptionsForCSR<SharedProps>,
): Promise<CreatePageflowAppSetupReturnType>
export default async function createPageflowApp<SharedProps extends PageProps = PageProps>(
  options: PageflowAppOptionsForSSR<SharedProps>,
): Promise<CreatePageflowAppSSRContent>
export default async function createPageflowApp<SharedProps extends PageProps = PageProps>({
  id = 'app',
  resolve,
  setup,
  title,
  progress = {},
  page,
  render,
}: PageflowAppOptionsForCSR<SharedProps> | PageflowAppOptionsForSSR<SharedProps>): Promise<
  CreatePageflowAppSetupReturnType | CreatePageflowAppSSRContent
> {
  const isServer = typeof window === 'undefined'
  const el = isServer ? null : document.getElementById(id)
  const initialPage = page || JSON.parse(el.dataset.page)
  const resolveComponent = (name) => Promise.resolve(resolve(name)).then((module) => module.default || module)

  let head = []

  const reactApp = await Promise.all([
    resolveComponent(initialPage.component),
    router.decryptHistory().catch(() => {}),
  ]).then(([initialComponent]) => {

    return setup({
      // @ts-expect-error
      el,
      App,
      props: {
        initialPage,
        initialComponent,
        resolveComponent,
        titleCallback: title,
        onHeadUpdate: isServer ? (elements) => (head = elements) : null,
      },
    })
  })

  if (!isServer && progress) {
    setupProgress(progress)
  }

  if (isServer) {
    const body = await render(
      createElement(
        'div',
        {
          id,
          'data-page': JSON.stringify(initialPage),
        },
        // @ts-expect-error
        reactApp,
      ),
    )

    return { head, body }
  }
}
