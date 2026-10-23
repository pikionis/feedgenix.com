import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '~/lib/api/client'
import { isValidationError, type ReviewPage, type ReviewProduct, type ReviewText } from '~/lib/api/types'
import { describeFailure } from '~/lib/forms'

/** One sentence for a failed review call, in the server's words when it has them. */
export function reviewErrorMessage(status: number, body: unknown): string {
  if (isValidationError(body)) return Object.values(body.errors)[0]?.[0] ?? body.message
  if (typeof body === 'object' && body !== null && 'error' in body) {
    const error = body as { error: string; message?: string }
    if (error.error === 'source_changed') return 'This text changed in the feed meanwhile. It was reloaded: look again.'
    if (typeof error.message === 'string') return error.message
  }

  return describeFailure(status)
}

/**
 * Write, approve or hand back one text, each fenced by the source on screen. The answer replaces
 * the product in every cached page; the counts are refetched after.
 */
export function useReviewActions(feedId: number, locale: string) {
  const client = useQueryClient()
  const [busy, setBusy] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const prefix = ['feeds', feedId, 'products']

  const run = async (
    product: ReviewProduct,
    text: ReviewText,
    call: () => Promise<{ data?: { product: ReviewProduct }; error?: unknown; response: Response }>,
  ) => {
    const key = `${product.id}:${text.field}`
    setBusy(key)
    setErrors((current) => Object.fromEntries(Object.entries(current).filter(([name]) => name !== key)))
    const { data, error, response } = await call()
    setBusy(null)
    if (data === undefined) {
      setErrors((current) => ({ ...current, [key]: reviewErrorMessage(response.status, error) }))
      if (response.status === 409) await client.invalidateQueries({ queryKey: prefix })

      return false
    }
    client.setQueriesData<ReviewPage>({ queryKey: prefix }, (page) =>
      page === undefined
        ? page
        : { ...page, data: page.data.map((row) => (row.id === data.product.id ? data.product : row)) },
    )
    // The counts and filters may have changed; the page refetches without losing its place.
    void client.invalidateQueries({ queryKey: prefix })

    return true
  }

  const path = (product: ReviewProduct, text: ReviewText) => ({
    path: { product: product.id, field: text.field, locale },
  })

  return {
    busy,
    errors,
    write: (product: ReviewProduct, text: ReviewText, value: string) =>
      run(product, text, () =>
        api.PUT('/v1/products/{product}/texts/{field}/{locale}', {
          params: path(product, text),
          body: { value, source_locale: text.source?.locale ?? '', source_hash: text.source?.hash ?? '' },
        }),
      ),
    approve: (product: ReviewProduct, text: ReviewText) =>
      run(product, text, () =>
        api.POST('/v1/products/{product}/texts/{field}/{locale}/approve', {
          params: path(product, text),
          body: { source_hash: text.source?.hash ?? '' },
        }),
      ),
    reset: (product: ReviewProduct, text: ReviewText) =>
      run(product, text, () =>
        api.DELETE('/v1/products/{product}/texts/{field}/{locale}', { params: path(product, text) }),
      ),
  }
}
