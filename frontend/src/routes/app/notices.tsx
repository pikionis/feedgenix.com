import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { Link } from 'react-router'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { Rows } from '~/components/ui/section'
import { formatWhen } from '~/features/feeds/labels'
import { noticesQuery } from '~/features/notices/queries'
import { api } from '~/lib/api/client'
import { cn } from '~/lib/utils'
import type { Route } from './+types/notices'

export const meta: Route.MetaFunction = () => [{ title: 'Notices — FeedGenix' }, { name: 'robots', content: 'noindex' }]

const LEVELS = {
  action: 'bg-warn',
  warn: 'bg-warn',
  info: 'bg-faint',
} as const

/** What the workspace was told, newest first. Opening the page marks what it shows as read. */
export default function NoticesPage() {
  const client = useQueryClient()
  const notices = useQuery(noticesQuery)
  const unread = notices.data?.notices.filter((notice) => !notice.read).map((notice) => notice.id) ?? []
  const key = unread.join(',')

  useEffect(() => {
    if (key === '') return
    void api
      .POST('/v1/notices/read', { body: { ids: key.split(',').map(Number) } })
      .then(({ data }) => data !== undefined && client.setQueryData(noticesQuery.queryKey, data))
  }, [key, client])

  if (notices.isError) return <Notice tone="error">{notices.error.message}</Notice>
  if (notices.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Notices"
        subtitle="What your workspace was told: usage, work that waits, plan changes and held feeds. The owner and admins get the ones that need a decision by email too."
      />
      {notices.data.notices.length === 0 ? (
        <p className="rounded-lg border-[0.5px] border-hairline px-4 py-6 text-center text-sm text-muted-foreground">
          Nothing yet.
        </p>
      ) : (
        <Rows aria-label="Notices">
          {notices.data.notices.map((notice) => (
            <li key={notice.id} className="flex flex-col gap-1.5 px-4 py-3.5">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <h2 className="flex items-center gap-2 text-sm font-medium">
                  <span className={cn('size-1.5 shrink-0 rounded-full', LEVELS[notice.level])} aria-hidden="true" />
                  {notice.title}
                  {!notice.read && <span className="sr-only">(new)</span>}
                </h2>
                <span className="text-xs text-faint">{formatWhen(notice.created_at ?? '')}</span>
              </div>
              <p className="pl-3.5 text-sm text-muted-foreground">{notice.body}</p>
              {notice.action_label !== null && notice.action_path !== null && (
                <Link
                  to={notice.action_path}
                  className="self-start pl-3.5 text-sm text-primary hover:text-primary-hover"
                >
                  {notice.action_label}
                </Link>
              )}
            </li>
          ))}
        </Rows>
      )}
    </div>
  )
}
