import { default as axios, AxiosProgressEvent, AxiosRequestConfig } from 'axios'
import { csrfHeaderName, csrfToken, isSameOriginUrl } from '../csrf'
import { fireExceptionEvent, fireFinishEvent, firePrefetchingEvent, fireProgressEvent, fireStartEvent } from '../eventHandler/events'
import { page as currentPage } from '../page'
import { RequestParams } from './requestParams'
import { Response } from './response'
import { ActiveVisit, Page } from '../types'
import { urlWithoutHash } from '../url'

export class Request {
  protected response!: Response
  protected cancelToken!: AbortController
  protected requestParams: RequestParams
  protected requestHasFinished = false

  constructor(
    params: ActiveVisit,
    protected page: Page,
  ) {
    this.requestParams = RequestParams.create(params)
    this.cancelToken = new AbortController()
  }

  public static create(params: ActiveVisit, page: Page): Request {
    return new Request(params, page)
  }

  public async send() {
    this.requestParams.onCancelToken(() => this.cancel({ cancelled: true }))

    fireStartEvent(this.requestParams.all())
    this.requestParams.onStart()

    if (this.requestParams.all().prefetch) {
      this.requestParams.onPrefetching()
      firePrefetchingEvent(this.requestParams.all())
    }

    // We capture this up here because the response
    // will clear the prefetch flag so it can use it
    // as a regular response once the prefetch is done
    const originallyPrefetch = this.requestParams.all().prefetch

    return axios({
      method: this.requestParams.all().method,
      url: urlWithoutHash(this.requestParams.all().url).href,
      data: this.requestParams.data(),
      params: this.requestParams.queryParams(),
      signal: this.cancelToken.signal,
      headers: this.getHeaders(),
      // Send the platform session cookie so SecurityStage can authenticate the
      // request — but ONLY same-origin, so a cross-origin visit never ships the
      // session cookie to a third party.
      withCredentials: isSameOriginUrl(String(this.requestParams.all().url)),
      onUploadProgress: this.onProgress.bind(this),
      // Why text? This allows us to delay JSON.parse until we're ready to use the response,
      // helps with performance particularly on large responses + history encryption
      responseType: 'text',
    })
      .then((response) => {
        this.response = Response.create(this.requestParams, response, this.page)

        return this.response.handle()
      })
      .catch((error) => {
        if (error?.response) {
          this.response = Response.create(this.requestParams, error.response, this.page)

          return this.response.handle()
        }

        return Promise.reject(error)
      })
      .catch((error) => {
        if (axios.isCancel(error)) {
          return
        }

        if (fireExceptionEvent(error)) {
          return Promise.reject(error)
        }
      })
      .finally(() => {
        this.finish()

        if (originallyPrefetch && this.response) {
          this.requestParams.onPrefetchResponse(this.response)
        }
      })
  }

  protected finish(): void {
    if (this.requestParams.wasCancelledAtAll()) {
      return
    }

    this.requestParams.markAsFinished()
    this.fireFinishEvents()
  }

  protected fireFinishEvents(): void {
    if (this.requestHasFinished) {
      // This could be called from multiple places, don't let it re-fire
      return
    }

    this.requestHasFinished = true

    fireFinishEvent(this.requestParams.all())
    this.requestParams.onFinish()
  }

  public cancel({ cancelled = false, interrupted = false }: { cancelled?: boolean; interrupted?: boolean }): void {
    if (this.requestHasFinished) {
      // If the request has already finished, there's no need to cancel it
      return
    }

    this.cancelToken.abort()

    this.requestParams.markAsCancelled({ cancelled, interrupted })

    this.fireFinishEvents()
  }

  protected onProgress(progress: AxiosProgressEvent): void {
    if (this.requestParams.data() instanceof FormData) {
      progress.percentage = progress.progress ? Math.round(progress.progress * 100) : 0
      fireProgressEvent(progress)
      this.requestParams.all().onProgress(progress)
    }
  }

  protected getHeaders(): AxiosRequestConfig['headers'] {
    const headers: AxiosRequestConfig['headers'] = {
      ...this.requestParams.headers(),
      Accept: 'text/html, application/xhtml+xml',
      'X-Requested-With': 'XMLHttpRequest',
      'X-Pageflow': true,
    }

    if (currentPage.get().version) {
      headers['X-Pageflow-Version'] = currentPage.get().version
    }

    // Attach the platform's HMAC CSRF token on mutating requests so the
    // kernel SecurityStage (CsrfTokenLayer) doesn't deny them. GET/HEAD are
    // safe methods and never require a token. SECURITY: only for same-origin
    // targets — never leak the token to a third-party host.
    const method = this.requestParams.all().method
    if (method !== 'get' && isSameOriginUrl(String(this.requestParams.all().url))) {
      const token = csrfToken()
      if (token) {
        headers[csrfHeaderName()] = token
      }
    }

    return headers
  }
}
