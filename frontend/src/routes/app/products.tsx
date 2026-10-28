import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useParams, useSearchParams } from 'react-router'
import { Button } from '~/components/ui/button'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { feedQuery } from '~/features/feeds/queries'
import { useReviewActions } from '~/features/review/actions'
import { FILTERS } from '~/features/review/labels'
import { reviewQuery } from '~/features/review/queries'
import { TextRow } from '~/features/review/TextRow'
import { translationPlanQuery } from '~/features/translation/queries'
import type { ReviewFilter } from '~/lib/api/types'
import { useSession } from '~/lib/session'
import { cn } from '~/lib/utils'
import type { Route } from './+types/products'

export const meta: Route.MetaFunction = () => [{ title: 'Review — FeedGenix' }, { name: 'robots', content: 'noindex' }]

export default function ProductsPage() {
  const params = useParams()
  const feedId = Number(params.feedId)
  const valid = Number.isInteger(feedId) && feedId > 0
  const feed = useQuery({ ...feedQuery(valid ? feedId : 0), enabled: valid })
  const plan = useQuery({ ...translationPlanQuery(valid ? feedId : 0), enabled: valid })

  const back = { to: `/app/feeds/${feedId}/translation`, label: `${feed.data?.name ?? 'The feed'}: translation` }

  if (plan.isError) return <Notice tone="error">{plan.error.message}</Notice>
  if (plan.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />
  const languages = plan.data.workspace_locales.filter((locale) => plan.data.target_locales.includes(locale.code))
  if (languages.length === 0) {
    return (
      <div className="flex flex-col gap-5">
        <PageHeader back={back} title="Nothing to review yet" />
        <p className="max-w-xl rounded-lg bg-surface px-5 py-4 text-sm text-muted-foreground">
          Choose a language to translate into first.
        </p>
      </div>
    )
  }

  return <Review feedId={feedId} languages={languages} back={back} />
}

function Review({
  feedId,
  languages,
  back,
}: {
  feedId: number
  languages: { code: string; name: string }[]
  back: { to: string; label: string }
}) {
  const [params, setParams] = useSearchParams()
  const chosen = params.get('locale')
  const locale =
    chosen !== null && languages.some((language) => language.code === chosen) ? chosen : (languages[0]?.code ?? '')
  const filter = (FILTERS.find((item) => item.key === params.get('filter'))?.key ?? 'all') as ReviewFilter
  const search = params.get('search') ?? ''
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [typed, setTyped] = useState(search)
  const products = useQuery(reviewQuery(feedId, { locale, filter, search, page }))
  const session = useSession()
  const role = session.data?.workspace?.role
  const canEdit = role === 'owner' || role === 'admin'
  const actions = useReviewActions(feedId, locale)

  const go = (changes: Record<string, string | null>) => {
    const next = new URLSearchParams(params)
    for (const [key, value] of Object.entries(changes)) {
      if (value === null || value === '') next.delete(key)
      else next.set(key, value)
    }
    if (!('page' in changes)) next.delete('page')
    setParams(next, { replace: true })
  }
  const counts = products.data?.meta.counts

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        back={back}
        title="Review the texts"
        subtitle="One language at a time. What you write yourself is never replaced by the AI, and the same text elsewhere reuses it."
      />

      <div className="flex flex-col gap-4 rounded-lg bg-surface px-5 py-4">
        <div className="flex flex-wrap items-end gap-4">
          <label className="flex flex-col gap-1.5 text-sm">
            <span>Language</span>
            <select
              className="h-9 rounded-md border-[0.5px] border-hairline bg-background px-2 text-sm"
              value={locale}
              onChange={(event) => go({ locale: event.target.value })}
            >
              {languages.map((language) => (
                <option key={language.code} value={language.code}>
                  {language.name}
                </option>
              ))}
            </select>
          </label>
          <search>
            <form
              className="flex items-end gap-2"
              onSubmit={(event) => {
                event.preventDefault()
                go({ search: typed.trim() })
              }}
            >
              <label className="flex flex-col gap-1.5 text-sm">
                <span>SKU or title</span>
                <input
                  type="search"
                  value={typed}
                  maxLength={100}
                  onChange={(event) => setTyped(event.target.value)}
                  className="h-9 w-56 rounded-md border-[0.5px] border-hairline bg-background px-3 text-sm"
                />
              </label>
              <Button type="submit" variant="secondary">
                Search
              </Button>
            </form>
          </search>
        </div>
        <div role="tablist" aria-label="Show" className="flex flex-wrap gap-1 self-start">
          {FILTERS.map((item) => (
            <button
              key={item.key}
              type="button"
              role="tab"
              aria-selected={item.key === filter}
              onClick={() => go({ filter: item.key === 'all' ? null : item.key })}
              className={cn(
                'rounded-md border-[0.5px] px-3 py-1.5 text-[13px] transition-colors duration-200',
                item.key === filter
                  ? 'border-hairline bg-background font-medium text-foreground'
                  : 'border-transparent text-muted-foreground hover:bg-ink/[0.04]',
              )}
            >
              {item.label}
              {counts !== undefined && ` (${counts[item.key].toLocaleString('en')})`}
            </button>
          ))}
        </div>
      </div>

      {products.isError && <Notice tone="error">{products.error.message}</Notice>}
      {products.data === undefined && !products.isError && (
        <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />
      )}
      {products.data !== undefined && products.data.data.length === 0 && (
        <p className="rounded-lg border-[0.5px] border-hairline px-4 py-6 text-center text-sm text-muted-foreground">
          {filter === 'review'
            ? 'Nothing to review in this language.'
            : search !== ''
              ? 'No product matches the search.'
              : 'No products here.'}
        </p>
      )}

      {products.data?.data.map((product) => (
        <section
          key={product.id}
          aria-label={product.sku}
          className="overflow-hidden rounded-lg border-[0.5px] border-hairline bg-background"
        >
          <h2 className="border-b-[0.5px] border-hairline bg-subtle px-4 py-2 font-mono text-xs text-muted-foreground">
            {product.sku}
          </h2>
          <ul className="divide-y-[0.5px] divide-hairline">
            {product.texts.map((text) => {
              const key = `${product.id}:${text.field}`

              return (
                <TextRow
                  key={`${key}:${locale}`}
                  text={text}
                  canEdit={canEdit}
                  busy={actions.busy === key}
                  error={actions.errors[key]}
                  onWrite={(value) => actions.write(product, text, value)}
                  onApprove={() => void actions.approve(product, text)}
                  onReset={() => void actions.reset(product, text)}
                />
              )
            })}
          </ul>
        </section>
      ))}

      {products.data !== undefined && products.data.meta.pages > 1 && (
        <nav aria-label="Pages" className="flex items-center justify-between gap-4">
          <Button variant="secondary" disabled={page <= 1} onClick={() => go({ page: String(page - 1) })}>
            Previous
          </Button>
          <span className="text-sm text-muted-foreground">
            Page {products.data.meta.page} of {products.data.meta.pages}
          </span>
          <Button
            variant="secondary"
            disabled={page >= products.data.meta.pages}
            onClick={() => go({ page: String(page + 1) })}
          >
            Next
          </Button>
        </nav>
      )}
    </div>
  )
}
