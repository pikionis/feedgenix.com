import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router'
import { Button } from '~/components/ui/button'
import { Callout } from '~/components/ui/callout'
import { LanguagePill } from '~/components/ui/language-pill'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { Rows, Section } from '~/components/ui/section'
import { Table, TD, TH, THead, TR } from '~/components/ui/table'
import { FeedStateBadge, type FeedTone } from '~/features/feeds/FeedState'
import { feedQuery } from '~/features/feeds/queries'
import { useTranslationActions } from '~/features/translation/actions'
import { localeName, MODES, runLabel, texts, unavailableMessage } from '~/features/translation/labels'
import { canStart, translationPlanQuery } from '~/features/translation/queries'
import { RunCard } from '~/features/translation/RunCard'
import type { TranslationMode, TranslationPlan } from '~/lib/api/types'
import { useSession } from '~/lib/session'
import { cn } from '~/lib/utils'
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

  const back = { to: `/app/feeds/${feedId}`, label: feed.data?.name ?? 'The feed' }

  if (plan.isError) return <Notice tone="error">{plan.error.message}</Notice>
  if (plan.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  return <TranslationView feedId={feedId} plan={plan.data} back={back} />
}

function TranslationView({
  feedId,
  plan,
  back,
}: {
  feedId: number
  plan: TranslationPlan
  back: { to: string; label: string }
}) {
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
    <div className="flex flex-col gap-8">
      <div className="flex flex-col gap-5">
        <PageHeader
          back={back}
          title="Translation"
          subtitle="Which texts to translate, and into which languages. SKUs, prices, stock and the other identifiers are never translated: they pass through as they are."
          actions={
            <>
              <FeedStateBadge state={{ label: runLabel(plan.run), tone: runTone(plan.run) }} />
              {reviewable && (
                <Button asChild variant="secondary">
                  <Link to={`/app/feeds/${feedId}/products`}>Review the texts</Link>
                </Button>
              )}
            </>
          }
        />

        {actions.error !== undefined && <Notice tone="error">{actions.error}</Notice>}

        <RunCard
          run={plan.run}
          canManage={canManage}
          busy={actions.busy === 'read'}
          onReadAgain={() => void actions.readAgain()}
        />
      </div>

      <Section
        title="Translate into"
        description={
          <>
            The languages are your workspace&apos;s.{' '}
            <Link to="/app/settings#languages" className="text-primary hover:text-primary-hover">
              Add one in the settings
            </Link>
            .
          </>
        }
      >
        {plan.workspace_locales.length === 0 ? (
          <p className="text-sm text-muted-foreground">Your workspace has no languages yet.</p>
        ) : (
          <ul className="flex flex-wrap gap-2">
            {plan.workspace_locales.map((locale) => (
              <li key={locale.code}>
                <label
                  className={cn(
                    'flex items-center gap-2.5 rounded-lg border-[0.5px] border-hairline px-3 py-2 text-sm transition-colors duration-200',
                    'has-[:checked]:border-primary/40 has-[:checked]:bg-primary-soft',
                    editable && 'cursor-pointer hover:bg-subtle',
                  )}
                >
                  <input
                    type="checkbox"
                    className="size-4 accent-primary"
                    checked={plan.target_locales.includes(locale.code)}
                    disabled={!editable}
                    onChange={(event) => void actions.setTarget(locale.code, event.target.checked)}
                  />
                  <span aria-hidden="true">
                    <LanguagePill code={locale.code} />
                  </span>
                  {locale.name}
                  {locale.paused && <span className="text-xs text-warn">paused by your plan</span>}
                </label>
              </li>
            ))}
          </ul>
        )}
      </Section>

      <Section title="Texts" description="What happens to each text of the catalogue.">
        {plan.fields.length > 0 && (
          <Rows>
            {plan.fields.map((field) => (
              <li key={field.field} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
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
          </Rows>
        )}
        {plan.fields.length === 0 && (
          <p className="text-sm text-muted-foreground">
            The catalogue has no texts yet. Map a title or a description, then approve the mapping.
          </p>
        )}
      </Section>

      <Section title="The work">
        {plan.work.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {plan.target_locales.length === 0
              ? 'Choose a language to translate into.'
              : 'Nothing to do: every text is translated and up to date.'}
          </p>
        ) : (
          <Table>
            <THead>
              <tr>
                <TH>Text</TH>
                <TH>Language</TH>
                <TH numeric>To do</TH>
                <TH numeric>From memory</TH>
                <TH numeric>With AI</TH>
              </tr>
            </THead>
            <tbody>
              {plan.work.map((line) => (
                <TR key={`${line.field}@${line.target_locale}`}>
                  <TD>{plan.fields.find((field) => field.field === line.field)?.label ?? line.field}</TD>
                  <TD>{localeName(plan, line.target_locale)}</TD>
                  <TD numeric>{line.texts.toLocaleString('en')}</TD>
                  <TD numeric className="text-muted-foreground">
                    {line.mode === 'keep' ? '—' : line.memory.toLocaleString('en')}
                  </TD>
                  <TD numeric>
                    {line.mode === 'keep' ? 'kept as is' : (line.texts - line.memory).toLocaleString('en')}
                  </TD>
                </TR>
              ))}
            </tbody>
          </Table>
        )}

        <Estimate plan={plan} toAi={toAi} />
        <Allowance plan={plan} />

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
          <Callout
            className="mt-2"
            action={
              <Button disabled={!startable || actions.busy !== null} onClick={() => void actions.start()}>
                {actions.busy === 'start'
                  ? 'Starting…'
                  : plan.run?.stage === 'done'
                    ? 'Translate what is new'
                    : 'Start translating'}
              </Button>
            }
          >
            <p className="text-muted-foreground">
              {startable
                ? 'Each batch reserves its credits before it runs and is charged what it used.'
                : plan.run?.stage === 'translate'
                  ? 'Translating: the plan can change again when it is done.'
                  : 'Choose at least one language with texts to translate.'}
            </p>
          </Callout>
        )}
      </Section>
    </div>
  )
}

/** The run's word, in the colour of the feeds list. */
function runTone(run: TranslationPlan['run']): FeedTone {
  if (run === null) return 'queued'
  if (run.status === 'failed') return 'error'
  if (run.status === 'held' || run.stage === 'plan') return 'warn'
  if (run.stage === 'done') return 'success'

  return 'running'
}

function Estimate({ plan, toAi }: { plan: TranslationPlan; toAi: number }) {
  if (toAi === 0) return null
  const { estimate } = plan
  if (!estimate.available) return <Notice tone="warn">{unavailableMessage(estimate.unavailable_reason)}</Notice>

  return (
    <p className="rounded-lg bg-surface px-4 py-3.5 text-sm">
      {texts(toAi)} with AI: about{' '}
      <strong className="font-medium">{(estimate.about_credits ?? 0).toLocaleString('en')} credits</strong>, at most{' '}
      {(estimate.at_most_credits ?? 0).toLocaleString('en')}.
    </p>
  )
}

/** What a start counts against the period's products, before anyone presses it. */
function Allowance({ plan }: { plan: TranslationPlan }) {
  const { allowance, new: added, over, used, held, resets_at: resets } = plan.allowance
  if (allowance === null || added === 0) return null
  const until = resets.slice(0, 10)
  if (over === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Counts {added.toLocaleString('en')} {added === 1 ? 'product' : 'products'} of the{' '}
        {Math.max(0, allowance - used).toLocaleString('en')} left until {until}.
      </p>
    )
  }

  return (
    <Notice tone="warn">
      {over.toLocaleString('en')} {over === 1 ? 'product is' : 'products are'} over the {allowance.toLocaleString('en')}{' '}
      your plan includes until {until}.{' '}
      {held
        ? 'They wait until then, or start at once when you upgrade; the others are translated now.'
        : 'They go ahead as overage, charged per product.'}{' '}
      <Link to="/app/billing/plans" className="text-primary hover:text-primary-hover">
        See the plans
      </Link>
    </Notice>
  )
}
