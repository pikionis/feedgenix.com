import { useQuery } from '@tanstack/react-query'
import { Lock } from 'lucide-react'
import { useParams } from 'react-router'
import { Button } from '~/components/ui/button'
import { Callout } from '~/components/ui/callout'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { Rows, Section } from '~/components/ui/section'
import { Highlight } from '~/components/ui/mosaic'
import { FeedStateBadge } from '~/features/feeds/FeedState'
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

  const back = { to: `/app/feeds/${feedId}`, label: feed.data?.name ?? 'The feed' }

  if (mapping.error instanceof NoProfile) {
    return (
      <div className="flex flex-col gap-5">
        <PageHeader back={back} title="Nothing to map yet" />
        <p className="max-w-xl rounded-lg bg-surface px-5 py-4 text-sm text-muted-foreground">
          {mapping.error.message}
        </p>
      </div>
    )
  }
  if (mapping.isError) return <Notice tone="error">{mapping.error.message}</Notice>
  if (mapping.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  return <MappingView feedId={feedId} mapping={mapping.data} back={back} />
}

function MappingView({
  feedId,
  mapping,
  back,
}: {
  feedId: number
  mapping: Mapping
  back: { to: string; label: string }
}) {
  const session = useSession()
  const role = session.data?.workspace?.role
  const canManage = role === 'owner' || role === 'admin'
  const actions = useMappingActions(feedId, mapping)
  const sourceLocale = mapping.source_locale ?? 'en'

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-col gap-5">
        <PageHeader
          back={back}
          title={<Highlight tone="cyan">Mapping</Highlight>}
          subtitle="Which column fills each product field. Anything uncertain stays empty until you choose."
          actions={
            <FeedStateBadge
              state={
                mapping.status === 'approved'
                  ? { label: 'Approved', tone: 'success' }
                  : { label: 'Draft', tone: 'warn' }
              }
            />
          }
        />

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
      </div>

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
        // Sticky: the button stays in reach on a long page.
        <Callout
          className="sticky bottom-4 z-10"
          action={
            <Button disabled={actions.busy !== null} onClick={() => void actions.approve()}>
              {actions.busy === 'approve' ? 'Approving…' : 'Approve the mapping'}
            </Button>
          }
        >
          <p className="text-muted-foreground">
            When the SKU and the texts you want to translate are right, approve the mapping. You can change it later.
          </p>
        </Callout>
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
    <div className="flex flex-col gap-3 rounded-lg bg-surface px-5 py-4">
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
    </div>
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
    <Section
      title={
        <span className="flex flex-wrap items-center gap-2.5">
          {title}
          {golden && (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-warm px-2.5 py-0.5 text-xs font-medium">
              <Lock size={12} strokeWidth={1.5} aria-hidden="true" />
              Golden Rule
            </span>
          )}
        </span>
      }
      description={note}
    >
      <Rows>{shown.map(render)}</Rows>
      {more.length > 0 && (
        <details className="group flex flex-col gap-3">
          <summary className="cursor-pointer text-sm text-primary hover:text-primary-hover">
            {more.length} more {more.length === 1 ? 'field' : 'fields'}
          </summary>
          <Rows className="mt-3">{more.map(render)}</Rows>
        </details>
      )}
    </Section>
  )
}
