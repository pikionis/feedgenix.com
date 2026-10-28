import type { ComponentProps } from 'react'
import { cn } from '~/lib/utils'

/*
 * Tables as the app kit draws them: on Paper, a hairline frame with 10px corners, a quiet header row
 * on the subtle surface, hairlines between rows. Numbers are right-aligned and in the mono face.
 */

export function Table({ className, children, ...props }: ComponentProps<'table'>) {
  return (
    <div className="overflow-x-auto rounded-lg border-[0.5px] border-hairline bg-background">
      <table className={cn('w-full border-collapse text-sm', className)} {...props}>
        {children}
      </table>
    </div>
  )
}

export function THead({ className, ...props }: ComponentProps<'thead'>) {
  return <thead className={cn('bg-subtle text-left text-xs text-faint', className)} {...props} />
}

export function TH({ className, numeric, ...props }: ComponentProps<'th'> & { numeric?: boolean }) {
  return <th className={cn('px-4 py-2.5 font-normal', numeric && 'text-right', className)} {...props} />
}

export function TR({ className, ...props }: ComponentProps<'tr'>) {
  return <tr className={cn('border-t-[0.5px] border-hairline', className)} {...props} />
}

export function TD({ className, numeric, ...props }: ComponentProps<'td'> & { numeric?: boolean }) {
  return <td className={cn('px-4 py-3 align-top', numeric && 'text-right font-mono text-xs', className)} {...props} />
}
