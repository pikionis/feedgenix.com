import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Lock } from 'lucide-react'
import { Link, useParams } from 'react-router'
import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import { Notice } from '~/components/ui/notice'
import { feedQuery } from '~/features/feeds/queries'
import { formatWhen } from '~/features/feeds/labels'
import { useMappingActions } from '~/features/mapping/actions'
import { AiPanel } from '~/features/mapping/AiPanel'
import { FieldRow } from '~/features/mapping/FieldRow'
import { ALWAYS_SHOWN, GROUPS, languageName } from '~/features/mapping/labels'
import { mappingQuery, NoProfile } from '~/features/mapping/queries'
import type { CanonicalField, Mapping, MappingField } from '~/lib/api/types'
import { languagesQuery } from '~/features/workspace/queries'
import { useSession } from '~/lib/session'
import type { Route } from './+types/mapping'

export const meta: Route.MetaFunction = () => [{ title: 'Mapping — FeedGenix' }, { name: 'robots', content: 'noindex' }]

export default function MappingPage() {
  const params = useParams()
  const feedId = Number(params.feedId)
  const valid = Number.isInteger(feedId) && feedId > 0
  const mapping = useQuery({ ...mappingQuery(valid ? feedId : 0), enabled: valid })
  const feed = useQuery({ ...feedQuery(valid ? feedId : 0), enabled: valid })

  const back = (
    <Link
      to={`/app/feeds/${feedId}`}
      className="inline-flex items-center gap-1 self-start text-sm text-muted-foreground hover:text-foreground"
    >
      <ArrowLeft size={14} strokeWidth={1.5} aria-hidden="true" /> {feed.data?.name ?? 'The feed'}
    </Link>
  )

  if (mapping.error instanceof NoProfile) {
    return (
      <div className="flex flex-col gap-4">
        {back}
        <Card className="flex max-w-xl flex-col gap-2">
          <h1 className="font-medium">Nothing to map yet</h1>
          <p className="text-sm text-muted-foreground">{mapping.error.message}</p>
        </Card>
      </div>
    )
  }
  if (mapping.isError) return <Notice tone="error">{mapping.error.message}</Notice>
  if (mapping.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  return <MappingView feedId={feedId} mapping={mapping.data} back={back} />
}

function MappingView({ feedId, mapping, back }: { feedId: number; mapping: Mapping; back: React.ReactNode }) {
  const session = useSession()
  const role = session.data?.workspace?.role
  const canManage = role === 'owner' || role === 'admin'
  const actions = useMappingActions(feedId, mapping)
  const sourceLocale = mapping.source_locale ?? 'en'

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3">
        {back}
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <h1 className="text-2xl font-medium tracking-tight">Mapping</h1>
            <p className="mt-1 max-w-2xl text-muted-foreground">
              Which column fills each product field. Anything uncertain stays empty until you choose.
            </p>
          </div>
          <span className="rounded-full bg-surface px-3 py-1 text-sm">
            {mapping.status === 'approved' ? 'Approved' : 'Draft'}
          </span>
        </div>
      </div>

      {actions.error !== undefined && <Notice tone="error">{actions.error}</Notice>}

      <FeedLanguage
        locale={mapping.source_locale}
        canEdit={canManage}
        busy={actions.busy === 'language'}
        onChange={(locale) => void actions.setLanguage(locale)}
      />
      {mapping.status === 'approved' && mapping.approved_at !== null && (
        <Notice tone="success">
          Approved {formatWhen(mapping.approved_at)}. Changing a field sends it back to draft.
        </Notice>
      )}

      {canManage && <AiPanel mapping={mapping} busy={actions.busy === 'suggest'} onSuggest={actions.suggest} />}

      {GROUPS.map((group) => (
        <FieldGroup
          key={group.title}
          title={group.title}
          note={group.note}
          golden={group.classes.includes('golden')}
          fields={mapping.available_fields.filter((field) => group.classes.includes(field.class))}
          mapping={mapping}
          sourceLocale={sourceLocale}
          canEdit={canManage}
          busyKey={actions.busy}
          onChange={(target, locale, column) => void actions.setField({ target, locale, column })}
        />
      ))}

      {canManage && mapping.status === 'draft' && (
        <Card className="flex flex-wrap items-center justify-between gap-4">
          <p className="text-sm text-muted-foreground">
            When the SKU and the texts you want to translate are right, approve the mapping. You can change it later.
          </p>
          <Button disabled={actions.busy !== null} onClick={() => void actions.approve()}>
            {actions.busy === 'approve' ? 'Approving…' : 'Approve the mapping'}
          </Button>
        </Card>
      )}
    </div>
  )
}

function FeedLanguage({
  locale,
  canEdit,
  busy,
  onChange,
}: {
  locale: string | null
  canEdit: boolean
  busy: boolean
  onChange: (locale: string) => void
}) {
  const languages = useQuery(languagesQuery)

  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <label htmlFor="feed-language" className="text-sm font-medium">
          The feed is written in
        </label>
        <select
          id="feed-language"
          className="h-9 rounded-md border-[0.5px] border-hairline bg-background px-2 text-sm disabled:opacity-60"
          value={locale ?? ''}
          disabled={!canEdit || busy || languages.data === undefined}
          onChange={(event) => event.target.value !== '' && onChange(event.target.value)}
        >
          {locale === null && <option value="">Choose a language</option>}
          {locale !== null && languages.data?.every((language) => language.code !== locale) === true && (
            <option value={locale}>{languageName(locale)}</option>
          )}
          {languages.data?.map((language) => (
            <option key={language.code} value={language.code}>
              {language.name}
            </option>
          ))}
        </select>
      </div>
      {locale === null && (
        <Notice tone="warn">
          We could not tell the feed&apos;s language from its texts. Choose it, and its titles and descriptions are
          filed under it.
        </Notice>
      )}
    </Card>
  )
}

function FieldGroup({
  golden,
  title,
  note,
  fields,
  mapping,
  sourceLocale,
  canEdit,
  busyKey,
  onChange,
}: {
  golden: boolean
  title: string
  note: string
  fields: CanonicalField[]
  mapping: Mapping
  sourceLocale: string
  canEdit: boolean
  busyKey: string | null
  onChange: (target: string, locale: string | null, column: string | null) => void
}) {
  // One row per decided field and language; an undecided field gets one row in the feed's language.
  const rows = fields.flatMap((field) => {
    const decided = mapping.fields.filter((decision) => decision.target === field.key)
    if (decided.length > 0) return decided.map((decision) => ({ field, decision, locale: decision.locale }))

    return [{ field, decision: undefined as MappingField | undefined, locale: field.localized ? sourceLocale : null }]
  })
  const shown = rows.filter((row) => row.decision !== undefined || ALWAYS_SHOWN.has(row.field.key))
  const more = rows.filter((row) => !shown.includes(row))

  const render = (row: (typeof rows)[number]) => (
    <FieldRow
      key={`${row.field.key}@${row.locale ?? ''}`}
      field={row.field}
      decision={row.decision}
      locale={row.locale}
      columns={mapping.columns}
      canEdit={canEdit}
      busy={busyKey === `field:${row.field.key}@${row.locale ?? ''}`}
      onChange={(column) => onChange(row.field.key, row.locale, column)}
    />
  )

  return (
    <Card className="flex flex-col">
      <div className="flex flex-wrap items-center gap-2.5">
        <h2 className="font-medium">{title}</h2>
        {golden && (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-warm px-2.5 py-0.5 text-xs font-medium">
            <Lock size={12} strokeWidth={1.5} aria-hidden="true" />
            Golden Rule
          </span>
        )}
      </div>
      <p className="text-sm text-muted-foreground">{note}</p>
      <ul className="divide-y divide-hairline">{shown.map(render)}</ul>
      {more.length > 0 && (
        <details className="mt-2">
          <summary className="cursor-pointer text-sm text-primary">
            {more.length} more {more.length === 1 ? 'field' : 'fields'}
          </summary>
          <ul className="divide-y divide-hairline">{more.map(render)}</ul>
        </details>
      )}
    </Card>
  )
}
