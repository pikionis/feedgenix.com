import { useQuery } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { Link, useParams } from 'react-router'
import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import { Notice } from '~/components/ui/notice'
import { feedQuery } from '~/features/feeds/queries'
import { useTranslationActions } from '~/features/translation/actions'
import { localeName, MODES, runLabel, texts, unavailableMessage } from '~/features/translation/labels'
import { canStart, translationPlanQuery } from '~/features/translation/queries'
import { RunCard } from '~/features/translation/RunCard'
import type { TranslationMode, TranslationPlan } from '~/lib/api/types'
import { useSession } from '~/lib/session'
import type { Route } from './+types/translation'

export const meta: Route.MetaFunction = () => [
  { title: 'Translation — FeedGenix' },
  { name: 'robots', content: 'noindex' },
]

export default function TranslationPage() {
  const params = useParams()
  const feedId = Number(params.feedId)
  const valid = Number.isInteger(feedId) && feedId > 0
  const plan = useQuery({ ...translationPlanQuery(valid ? feedId : 0), enabled: valid })
  const feed = useQuery({ ...feedQuery(valid ? feedId : 0), enabled: valid })

  const back = (
    <Link
      to={`/app/feeds/${feedId}`}
      className="inline-flex items-center gap-1 self-start text-sm text-muted-foreground hover:text-foreground"
    >
      <ArrowLeft size={14} strokeWidth={1.5} aria-hidden="true" /> {feed.data?.name ?? 'The feed'}
    </Link>
  )

  if (plan.isError) return <Notice tone="error">{plan.error.message}</Notice>
  if (plan.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  return <TranslationView feedId={feedId} plan={plan.data} back={back} />
}

function TranslationView({ feedId, plan, back }: { feedId: number; plan: TranslationPlan; back: React.ReactNode }) {
  const session = useSession()
  const role = session.data?.workspace?.role
  const canManage = role === 'owner' || role === 'admin'
  const actions = useTranslationActions(feedId, plan)
  const editable = canManage && actions.busy === null
  const toAi = plan.work
    .filter((line) => line.mode === 'translate')
    .reduce((sum, line) => sum + line.texts - line.memory, 0)
  const anyWork = plan.work.some((line) => line.texts > 0)
  const startable = canManage && canStart(plan.run) && plan.target_locales.length > 0 && anyWork
  // The products are in the catalogue: their texts can be looked at, translated or not.
  const reviewable = plan.target_locales.length > 0 && plan.run !== null && plan.run.stage !== 'sync'

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3">
        {back}
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <h1 className="text-2xl font-medium tracking-tight">Translation</h1>
            <p className="mt-1 max-w-2xl text-muted-foreground">
              Which texts to translate, and into which languages. SKUs, prices, stock and the other identifiers are
              never translated: they pass through as they are.
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <span className="rounded-full bg-surface px-3 py-1 text-sm">{runLabel(plan.run)}</span>
            {reviewable && (
              <Button asChild variant="secondary">
                <Link to={`/app/feeds/${feedId}/products`}>Review the texts</Link>
              </Button>
            )}
          </div>
        </div>
      </div>

      {actions.error !== undefined && <Notice tone="error">{actions.error}</Notice>}

      <RunCard
        run={plan.run}
        canManage={canManage}
        busy={actions.busy === 'read'}
        onReadAgain={() => void actions.readAgain()}
      />

      <Card className="flex flex-col gap-3">
        <h2 className="font-medium">Translate into</h2>
        {plan.workspace_locales.length === 0 ? (
          <p className="text-sm text-muted-foreground">Your workspace has no languages yet.</p>
        ) : (
          <ul className="flex flex-wrap gap-x-6 gap-y-2">
            {plan.workspace_locales.map((locale) => (
              <li key={locale.code}>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="size-4 accent-primary"
                    checked={plan.target_locales.includes(locale.code)}
                    disabled={!editable}
                    onChange={(event) => void actions.setTarget(locale.code, event.target.checked)}
                  />
                  {locale.name}
                  {locale.paused && <span className="text-xs text-warn">paused by your plan</span>}
                </label>
              </li>
            ))}
          </ul>
        )}
        <p className="text-sm text-muted-foreground">
          The languages are your workspace&apos;s.{' '}
          <Link to="/app/settings" className="text-primary hover:text-primary-hover">
            Add one in the settings
          </Link>
          .
        </p>
      </Card>

      <Card className="flex flex-col gap-3">
        <h2 className="font-medium">Texts</h2>
        <ul className="divide-y divide-hairline">
          {plan.fields.map((field) => (
            <li key={field.field} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div className="min-w-0">
                <p className="text-sm font-medium">{field.label}</p>
                <p className="text-xs text-muted-foreground">
                  In the feed:{' '}
                  {field.sources
                    .map((source) => `${localeName(plan, source.locale)} (${source.products.toLocaleString('en')})`)
                    .join(', ')}
                </p>
              </div>
              <div className="flex flex-col items-end gap-1">
                <select
                  aria-label={field.label}
                  className="h-9 rounded-md border-[0.5px] border-hairline bg-background px-2 text-sm disabled:opacity-60"
                  value={field.mode}
                  disabled={!editable}
                  onChange={(event) => void actions.setMode(field.field, event.target.value as TranslationMode)}
                >
                  {(Object.keys(MODES) as TranslationMode[]).map((mode) => (
                    <option key={mode} value={mode}>
                      {MODES[mode].label}
                    </option>
                  ))}
                </select>
                <span className="text-xs text-faint">{MODES[field.mode].note}</span>
              </div>
            </li>
          ))}
        </ul>
        {plan.fields.length === 0 && (
          <p className="text-sm text-muted-foreground">
            The catalogue has no texts yet. Map a title or a description, then approve the mapping.
          </p>
        )}
      </Card>

      <Card className="flex flex-col gap-4">
        <h2 className="font-medium">The work</h2>
        {plan.work.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {plan.target_locales.length === 0
              ? 'Choose a language to translate into.'
              : 'Nothing to do: every text is translated and up to date.'}
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-xs text-muted-foreground">
                <tr>
                  <th className="py-2 pr-4 font-normal">Text</th>
                  <th className="py-2 pr-4 font-normal">Language</th>
                  <th className="py-2 pr-4 text-right font-normal">To do</th>
                  <th className="py-2 pr-4 text-right font-normal">From memory</th>
                  <th className="py-2 text-right font-normal">With AI</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-hairline">
                {plan.work.map((line) => (
                  <tr key={`${line.field}@${line.target_locale}`}>
                    <td className="py-2 pr-4">
                      {plan.fields.find((field) => field.field === line.field)?.label ?? line.field}
                    </td>
                    <td className="py-2 pr-4">{localeName(plan, line.target_locale)}</td>
                    <td className="py-2 pr-4 text-right font-mono text-xs">{line.texts.toLocaleString('en')}</td>
                    <td className="py-2 pr-4 text-right font-mono text-xs">
                      {line.mode === 'keep' ? '—' : line.memory.toLocaleString('en')}
                    </td>
                    <td className="py-2 text-right font-mono text-xs">
                      {line.mode === 'keep' ? 'kept as is' : (line.texts - line.memory).toLocaleString('en')}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <Estimate plan={plan} toAi={toAi} />

        <label className="flex items-start gap-2 text-sm">
          <input
            type="checkbox"
            className="mt-0.5 size-4 accent-primary"
            checked={plan.auto_translate}
            disabled={!editable}
            onChange={(event) => void actions.setAuto(event.target.checked)}
          />
          <span>
            Translate every new file by itself
            <span className="block text-xs text-muted-foreground">
              Only its new and changed texts, as this plan says. Each batch still reserves its credits first; without
              them the work waits and tells you why.
            </span>
          </span>
        </label>

        {canManage && (
          <div className="flex flex-wrap items-center justify-between gap-4 border-t-[0.5px] border-hairline pt-4">
            <p className="text-sm text-muted-foreground">
              {startable
                ? 'Each batch reserves its credits before it runs and is charged what it used.'
                : plan.run?.stage === 'translate'
                  ? 'Translating: the plan can change again when it is done.'
                  : 'Choose at least one language with texts to translate.'}
            </p>
            <Button disabled={!startable || actions.busy !== null} onClick={() => void actions.start()}>
              {actions.busy === 'start'
                ? 'Starting…'
                : plan.run?.stage === 'done'
                  ? 'Translate what is new'
                  : 'Start translating'}
            </Button>
          </div>
        )}
      </Card>
    </div>
  )
}

function Estimate({ plan, toAi }: { plan: TranslationPlan; toAi: number }) {
  if (toAi === 0) return null
  const { estimate } = plan
  if (!estimate.available) return <Notice tone="warn">{unavailableMessage(estimate.unavailable_reason)}</Notice>

  return (
    <p className="text-sm">
      {texts(toAi)} with AI: about <strong>{(estimate.about_credits ?? 0).toLocaleString('en')} credits</strong>, at
      most {(estimate.at_most_credits ?? 0).toLocaleString('en')}.
    </p>
  )
}
