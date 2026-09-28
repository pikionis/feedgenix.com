import type { ComponentProps } from 'react'
import { cn } from '~/lib/utils'

// 8px radius, hairline border, 1px indigo ring on focus, no glow (design/README.md).
export function Input({ className, ...props }: ComponentProps<'input'>) {
  return (
    <input
      data-slot="input"
      className={cn(
        'h-9 w-full rounded-md border-[0.5px] border-hairline bg-background px-3 text-sm text-foreground',
        'placeholder:text-faint focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary focus-visible:outline-none',
        'disabled:cursor-not-allowed disabled:opacity-60 aria-invalid:border-error',
        className,
      )}
      {...props}
    />
  )
}
