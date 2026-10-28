import { useId, type ComponentProps, type ReactNode } from 'react'
import { cn } from '~/lib/utils'

/**
 * A part of a page on the white canvas: a heading, one optional line under it, something quiet on
 * the right (a time, a link), then the content. No frame of its own: what is inside brings one.
 */
export function Section({
  title,
  description,
  aside,
  children,
  className,
}: {
  title: ReactNode
  description?: ReactNode
  aside?: ReactNode
  children: ReactNode
  className?: string
}) {
  const id = useId()

  return (
    <section aria-labelledby={id} className={cn('flex flex-col gap-3', className)}>
      <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <div className="min-w-0">
          <h2 id={id} className="text-[15px] font-medium">
            {title}
          </h2>
          {description !== undefined && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
        </div>
        {aside}
      </div>
      {children}
    </section>
  )
}

/** Rows in one hairline frame (the app kit's list): what a page would otherwise split into cards. */
export function Rows({ className, children, ...props }: ComponentProps<'ul'>) {
  return (
    <ul
      className={cn(
        'divide-y-[0.5px] divide-hairline overflow-hidden rounded-lg border-[0.5px] border-hairline bg-background',
        className,
      )}
      {...props}
    >
      {children}
    </ul>
  )
}
