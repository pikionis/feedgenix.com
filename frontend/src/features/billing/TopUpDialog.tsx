import { useId, useState } from 'react'
import { Button } from '~/components/ui/button'
import { Dialog, DialogClose } from '~/components/ui/dialog'
import { cn } from '~/lib/utils'
import { euros } from './format'

/** 1 credit = €0.0001 (ADR 0005): €1 buys 10,000 credits. */
export const CREDITS_PER_EURO = 10_000

const PACKS = [10, 50, 100] as const
const MINIMUM_EUR = 10

/**
 * Buying credits: a pack or an amount of at least €10. Payment goes through Stripe Checkout (M7);
 * until then the dialog shows what will be bought and says that checkout opens soon.
 */
export function TopUpDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const [choice, setChoice] = useState<number | 'other'>(50)
  const [other, setOther] = useState('25')
  const amountId = useId()
  const amount = choice === 'other' ? Number(other.replace(',', '.')) : choice
  const valid = Number.isFinite(amount) && amount >= MINIMUM_EUR

  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      title="Buy credits"
      description="Credits pay for AI work: mapping suggestions and translations. They never expire."
    >
      <div className="flex flex-col gap-5">
        <fieldset className="flex flex-col gap-2.5">
          <legend className="mb-2.5 text-sm font-medium">Choose a pack</legend>
          <div className="grid grid-cols-2 gap-2.5">
            {[...PACKS, 'other' as const].map((pack) => (
              <button
                key={pack}
                type="button"
                aria-pressed={choice === pack}
                onClick={() => setChoice(pack)}
                className={cn(
                  'rounded-lg px-3.5 py-3 text-left transition-colors duration-200',
                  choice === pack
                    ? 'border border-primary bg-primary/5'
                    : 'border-[0.5px] border-hairline bg-background hover:bg-surface',
                )}
              >
                <span className="block text-xl font-medium tracking-tight">
                  {pack === 'other' ? 'Other' : euros(pack)}
                </span>
                <span className="block text-sm text-muted-foreground">
                  {pack === 'other' ? 'Your amount' : `${(pack * CREDITS_PER_EURO).toLocaleString('en')} credits`}
                </span>
              </button>
            ))}
          </div>
        </fieldset>

        {choice === 'other' && (
          <div className="flex flex-col gap-1.5 text-sm">
            <label htmlFor={amountId} className="font-medium">
              Amount in euro
            </label>
            <input
              id={amountId}
              inputMode="decimal"
              value={other}
              onChange={(event) => setOther(event.target.value)}
              aria-invalid={!valid}
              className="h-9 max-w-[200px] rounded-md border-[0.5px] border-hairline bg-background px-3 font-mono text-sm focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary focus-visible:outline-none aria-invalid:border-error"
            />
            <span className={cn('text-xs', valid ? 'text-muted-foreground' : 'text-error')}>
              At least {euros(MINIMUM_EUR)}. {euros(1)} buys {CREDITS_PER_EURO.toLocaleString('en')} credits.
            </span>
          </div>
        )}

        <dl className="flex flex-col gap-2 border-t-[0.5px] border-hairline pt-4 text-sm">
          <div className="flex justify-between">
            <dt className="text-muted-foreground">You get</dt>
            <dd className="font-mono">{valid ? `${(amount * CREDITS_PER_EURO).toLocaleString('en')} credits` : '—'}</dd>
          </div>
          <div className="flex justify-between font-medium">
            <dt>Due today</dt>
            <dd>{valid ? `${euros(amount)} + VAT` : '—'}</dd>
          </div>
        </dl>

        <p className="text-sm text-muted-foreground">
          Every run shows its estimate before it starts, reserves its credits and is charged only what it used. Texts
          from the translation memory cost nothing.
        </p>

        <div className="flex justify-end gap-2">
          <DialogClose asChild>
            <Button variant="secondary">Cancel</Button>
          </DialogClose>
          <Button disabled>Checkout opens soon</Button>
        </div>
      </div>
    </Dialog>
  )
}
