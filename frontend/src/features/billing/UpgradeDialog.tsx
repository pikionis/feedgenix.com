import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { Button } from '~/components/ui/button'
import { Dialog, DialogClose } from '~/components/ui/dialog'
import { UsageBar } from '~/components/ui/usage-bar'
import type { LimitHit } from '~/lib/api/types'
import { limitHit } from '~/lib/events'
import { plansQuery } from '~/features/workspace/queries'
import { euros } from './format'
import { highlights } from './PlanCards'

const TITLES: Record<string, string> = {
  plan_limit: 'You have reached your plan’s limit',
  plan_feature: 'This is part of a higher plan',
}

const UNITS: Record<string, string> = {
  feeds: 'feeds',
  languages: 'target languages',
  products_per_month: 'products this month',
}

/**
 * The one place every plan limit surfaces: any 402 from the API opens it, with the server's own
 * wording and the cheapest plan that fits. Mounted once in the app shell.
 */
export function UpgradeDialog() {
  const [hit, setHit] = useState<LimitHit | null>(null)
  const plans = useQuery({ ...plansQuery, enabled: hit !== null })

  useEffect(() => limitHit.on(setHit), [])

  const target = plans.data?.plans.find((plan) => plan.key === hit?.upgrade_to) ?? null

  return (
    <Dialog
      open={hit !== null}
      onOpenChange={(open) => {
        if (!open) setHit(null)
      }}
      title={hit !== null ? (TITLES[hit.error] ?? TITLES.plan_limit ?? '') : ''}
      description={hit?.message}
    >
      {hit !== null && (
        <div className="flex flex-col gap-5">
          {/* Counted limits get the numbers; a feed's size is said in the message, in GB. */}
          {UNITS[hit.limit] !== undefined && hit.allowed !== null && (hit.requested ?? hit.used) !== null && (
            <UsageBar
              used={hit.requested ?? hit.used ?? 0}
              allowed={hit.allowed}
              label={`Asked for · included in your plan (${UNITS[hit.limit] ?? hit.limit})`}
            />
          )}
          {target !== null ? (
            <div className="rounded-xl border-2 border-primary p-4">
              <p className="font-medium">{target.name}</p>
              <p className="mt-1 text-sm">
                {euros(target.price_monthly_eur)} / month · or {euros(target.price_yearly_eur)} a year
              </p>
              <ul className="mt-3 flex flex-col gap-1 text-sm text-muted-foreground">
                {highlights(target).map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
            </div>
          ) : (
            hit.upgrade_to === null && (
              <p className="text-sm text-muted-foreground">
                This needs a custom setup. Write to us and we size it with you.
              </p>
            )
          )}
          <div className="flex justify-end gap-2">
            <DialogClose asChild>
              <Button variant="secondary">Not now</Button>
            </DialogClose>
            <Button asChild>
              <Link to="/app/settings#plan" onClick={() => setHit(null)}>
                {target !== null ? `See ${target.name}` : 'See plans'}
              </Link>
            </Button>
          </div>
        </div>
      )}
    </Dialog>
  )
}
