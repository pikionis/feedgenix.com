import type { ComponentProps } from 'react'
import { cn } from '~/lib/utils'

// Paper on a Mist or Sand surface: hairline border, 12px radius, flat.
export function Card({ className, ...props }: ComponentProps<'div'>) {
  return <div className={cn('rounded-xl border-[0.5px] border-hairline bg-background p-6', className)} {...props} />
}
