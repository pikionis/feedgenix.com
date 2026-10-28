import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { Button } from '~/components/ui/button'
import { Initials } from '~/components/ui/initials'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { Table, TD, TH, THead, TR } from '~/components/ui/table'
import { adminWorkspacesQuery } from '~/features/admin/queries'
import { formatWhen } from '~/features/feeds/labels'
import type { AdminWorkspaceRow } from '~/lib/api/types'
import type { Route } from './+types/workspaces'

export const meta: Route.MetaFunction = () => [
  { title: 'Workspaces — FeedGenix staff' },
  { name: 'robots', content: 'noindex' },
]

export default function AdminWorkspaces() {
  const [params, setParams] = useSearchParams()
  const search = params.get('search') ?? ''
  const page = Math.max(1, Number(params.get('page')) || 1)
  const [typed, setTyped] = useState(search)
  const workspaces = useQuery(adminWorkspacesQuery(search, page))
  const paging = workspaces.data?.meta

  const go = (changes: { search?: string; page?: number }) => {
    const next = new URLSearchParams(params)
    if (changes.search !== undefined) {
      if (changes.search === '') next.delete('search')
      else next.set('search', changes.search)
      next.delete('page')
    }
    if (changes.page !== undefined) next.set('page', String(changes.page))
    setParams(next, { replace: true })
  }

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Workspaces"
        subtitle={
          paging === undefined
            ? 'Every workspace, newest first.'
            : `${paging.total.toLocaleString('en')} ${paging.total === 1 ? 'workspace' : 'workspaces'}, newest first. AI spend in credits.`
        }
      />

      <search>
        <form
          className="flex flex-wrap items-end gap-2"
          onSubmit={(event) => {
            event.preventDefault()
            go({ search: typed.trim() })
          }}
        >
          <label className="flex flex-col gap-1.5 text-sm">
            <span>Name, slug, owner email or id</span>
            <input
              type="search"
              value={typed}
              maxLength={100}
              onChange={(event) => setTyped(event.target.value)}
              className="h-9 w-72 max-w-full rounded-md border-[0.5px] border-hairline bg-background px-3 text-sm focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary focus-visible:outline-none"
            />
          </label>
          <Button type="submit" variant="secondary">
            Search
          </Button>
        </form>
      </search>

      {workspaces.isError && <Notice tone="error">{workspaces.error.message}</Notice>}
      {workspaces.data !== undefined && workspaces.data.workspaces.length === 0 && (
        <p className="rounded-lg border-[0.5px] border-hairline px-4 py-6 text-center text-sm text-muted-foreground">
          {search === '' ? 'No workspaces yet.' : 'No workspace matches the search.'}
        </p>
      )}
      {workspaces.data !== undefined && workspaces.data.workspaces.length > 0 && (
        <Table aria-label="Workspaces" className="min-w-[880px]">
          <THead>
            <tr>
              <TH>Workspace</TH>
              <TH>Owner</TH>
              <TH>Plan</TH>
              <TH numeric>Feeds</TH>
              <TH numeric>Languages</TH>
              <TH numeric>Products</TH>
              <TH numeric>Credits</TH>
              <TH numeric>AI, 30 days</TH>
              <TH>Last file</TH>
            </tr>
          </THead>
          <tbody>
            {workspaces.data.workspaces.map((row) => (
              <WorkspaceRow key={row.id} row={row} />
            ))}
          </tbody>
        </Table>
      )}

      {paging !== undefined && paging.pages > 1 && (
        <nav aria-label="Pages" className="flex items-center justify-between gap-4">
          <Button variant="secondary" disabled={paging.page <= 1} onClick={() => go({ page: paging.page - 1 })}>
            Previous
          </Button>
          <span className="text-sm text-muted-foreground">
            Page {paging.page} of {paging.pages}
          </span>
          <Button
            variant="secondary"
            disabled={paging.page >= paging.pages}
            onClick={() => go({ page: paging.page + 1 })}
          >
            Next
          </Button>
        </nav>
      )}
    </div>
  )
}

function WorkspaceRow({ row }: { row: AdminWorkspaceRow }) {
  const marks = [row.internal && 'internal', row.frozen && 'frozen', row.ai_paused && 'AI paused'].filter(
    (mark): mark is string => typeof mark === 'string',
  )

  return (
    <TR>
      <TD>
        <Link to={`/admin/workspaces/${row.id}`} className="flex items-center gap-3 hover:text-primary">
          <Initials name={row.name} />
          <span className="flex min-w-0 flex-col">
            <span className="truncate font-medium">{row.name}</span>
            <span className="truncate font-mono text-[11px] text-faint">
              #{row.id} · {row.slug}
              {marks.length > 0 && ` · ${marks.join(' · ')}`}
            </span>
          </span>
        </Link>
      </TD>
      <TD className="max-w-[220px]">
        {row.owner === null ? (
          <span className="text-faint">—</span>
        ) : (
          <span className="flex flex-col">
            <span className="truncate">{row.owner.email}</span>
            {!row.owner.verified && <span className="text-xs text-warn">not verified</span>}
          </span>
        )}
      </TD>
      <TD className="whitespace-nowrap">
        {row.plan_name}
        {row.plan !== 'free' && <span className="text-faint"> · {row.interval === 'year' ? 'yearly' : 'monthly'}</span>}
      </TD>
      <TD numeric>
        {row.feeds}
        {row.feeds_paused > 0 && <span className="block text-warn">{row.feeds_paused} paused</span>}
      </TD>
      <TD numeric>{row.languages}</TD>
      <TD numeric>{row.products_this_period.toLocaleString('en')}</TD>
      <TD numeric>{row.credits_available.toLocaleString('en')}</TD>
      <TD numeric>{row.ai_credits_30d.toLocaleString('en')}</TD>
      <TD className="text-xs whitespace-nowrap text-faint">
        {row.last_import_at === null ? '—' : formatWhen(row.last_import_at)}
      </TD>
    </TR>
  )
}
