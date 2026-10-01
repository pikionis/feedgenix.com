import { useQuery } from '@tanstack/react-query'
import type { ImportSummary } from '~/lib/api/types'
import { formatWhen } from './labels'
import { feedImportsQuery } from './queries'
import { StatusBadge } from './StatusBadge'

const ORIGIN: Record<ImportSummary['origin'], string> = {
  fetch: 'Fetched',
  upload: 'Uploaded',
  reread: 'Read again',
}

/** The feed's last 20 imports, newest first. */
export function ImportHistory({ feedId, latest }: { feedId: number; latest: ImportSummary | null }) {
  // Keyed by the latest import and its status: the list follows what the (polled) feed reports.
  const base = feedImportsQuery(feedId)
  const imports = useQuery({ ...base, queryKey: [...base.queryKey, latest?.id ?? 0, latest?.status ?? 'none'] })

  if (imports.data === undefined || imports.data.length === 0) return null

  return (
    <ol className="flex flex-col text-sm" aria-label="Imports">
      {imports.data.map((item) => (
        <li
          key={item.id}
          className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t-[0.5px] border-hairline py-2.5 first:border-t-0"
        >
          <StatusBadge status={item.status} />
          <span>{ORIGIN[item.origin]}</span>
          {item.original_name !== null && (
            <span className="max-w-[240px] truncate text-muted-foreground">{item.original_name}</span>
          )}
          {item.row_count !== null && item.status === 'profiled' && (
            <span className="text-muted-foreground">{item.row_count.toLocaleString('en')} products</span>
          )}
          {item.failure !== null && <span className="text-muted-foreground">{item.failure.message}</span>}
          <time className="ml-auto text-faint" dateTime={item.created_at ?? undefined}>
            {formatWhen(item.created_at)}
          </time>
        </li>
      ))}
    </ol>
  )
}
