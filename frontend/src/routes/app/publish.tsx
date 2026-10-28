import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useParams } from 'react-router'
import { Button } from '~/components/ui/button'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { feedQuery } from '~/features/feeds/queries'
import { useExportActions } from '~/features/publish/actions'
import { ExportCard } from '~/features/publish/ExportCard'
import { NewExportForm } from '~/features/publish/NewExportForm'
import { exportsQuery } from '~/features/publish/queries'
import { useSession } from '~/lib/session'
import type { Route } from './+types/publish'

export const meta: Route.MetaFunction = () => [{ title: 'Publish — FeedGenix' }, { name: 'robots', content: 'noindex' }]

export default function PublishPage() {
  const params = useParams()
  const feedId = Number(params.feedId)
  const valid = Number.isInteger(feedId) && feedId > 0
  const exports = useQuery({ ...exportsQuery(valid ? feedId : 0), enabled: valid })
  const feed = useQuery({ ...feedQuery(valid ? feedId : 0), enabled: valid })
  const session = useSession()
  const role = session.data?.workspace?.role
  const canManage = role === 'owner' || role === 'admin'
  const actions = useExportActions(feedId)
  const [adding, setAdding] = useState(false)

  const back = { to: `/app/feeds/${feedId}`, label: feed.data?.name ?? 'The feed' }
  if (exports.isError) return <Notice tone="error">{exports.error.message}</Notice>
  if (exports.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  const { presets, locales } = exports.data
  const names = Object.fromEntries(locales.map((locale) => [locale.code, locale.name]))
  const list = exports.data.exports
  const showForm = canManage && (adding || list.length === 0)

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        back={back}
        title="Publish"
        subtitle="Feed URLs your sales channels pull. Each serves a whole, checked build; new prices and stock from a new file reach it within minutes, translations when they are done."
        actions={
          canManage && list.length > 0 && !adding ? (
            <Button variant="secondary" onClick={() => setAdding(true)}>
              New export
            </Button>
          ) : undefined
        }
      />

      {feed.data?.run === null && (
        <Notice>The products are read into the catalogue when the mapping is approved: approve it first.</Notice>
      )}
      {list.length === 0 && !canManage && (
        <p className="text-sm text-muted-foreground">This feed has no exports yet.</p>
      )}

      {showForm && (
        <NewExportForm
          presets={presets}
          locales={locales}
          busy={actions.busy === 'new'}
          error={actions.errors.new}
          onCreate={async (body) => {
            const created = await actions.create(body)
            if (created) setAdding(false)

            return created
          }}
          onCancel={list.length > 0 ? () => setAdding(false) : undefined}
        />
      )}

      {list.map((profile) => (
        <ExportCard key={profile.id} profile={profile} names={names} canManage={canManage} actions={actions} />
      ))}
    </div>
  )
}
