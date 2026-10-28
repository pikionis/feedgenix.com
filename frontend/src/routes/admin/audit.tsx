import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { Table, TD, TH, THead, TR } from '~/components/ui/table'
import { adminAuditQuery } from '~/features/admin/queries'
import { formatWhen } from '~/features/feeds/labels'
import type { Route } from './+types/audit'

export const meta: Route.MetaFunction = () => [
  { title: 'Staff trail — FeedGenix staff' },
  { name: 'robots', content: 'noindex' },
]

/** What staff did: every request to the staff area, with its answer. Nobody can change or remove a line. */
export default function AdminAudit() {
  const audit = useQuery(adminAuditQuery)

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Staff trail"
        subtitle="Every request to the staff area, newest first, with the answer it got. Lines are never changed or removed."
      />
      {audit.isError && <Notice tone="error">{audit.error.message}</Notice>}
      {audit.data !== undefined && (
        <Table aria-label="Staff trail" className="min-w-[760px]">
          <THead>
            <tr>
              <TH>When</TH>
              <TH>Who</TH>
              <TH>What</TH>
              <TH>Request</TH>
              <TH numeric>Answer</TH>
              <TH>Workspace</TH>
            </tr>
          </THead>
          <tbody>
            {audit.data.map((line) => (
              <TR key={line.id}>
                <TD className="text-xs whitespace-nowrap text-faint">{formatWhen(line.created_at)}</TD>
                <TD className="max-w-[220px] truncate">{line.user_email}</TD>
                <TD>{line.action}</TD>
                <TD className="font-mono text-xs break-all">
                  {line.method} {line.path}
                </TD>
                <TD numeric className={line.status >= 400 ? 'text-warn' : undefined}>
                  {line.status}
                </TD>
                <TD>
                  {line.subject_tenant_id === null ? (
                    <span className="text-faint">—</span>
                  ) : (
                    <Link
                      to={`/admin/workspaces/${line.subject_tenant_id}`}
                      className="text-primary hover:text-primary-hover"
                    >
                      #{line.subject_tenant_id}
                    </Link>
                  )}
                </TD>
              </TR>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  )
}
