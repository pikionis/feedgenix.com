import * as DialogPrimitive from '@radix-ui/react-dialog'
import type { ReactNode } from 'react'

/** A modal dialog (Radix: focus trap, Escape, aria). Overlays are the only place with a shadow. */
export function Dialog({
  open,
  onOpenChange,
  title,
  description,
  children,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description?: string
  children: ReactNode
}) {
  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fixed inset-0 bg-ink/40" />
        <DialogPrimitive.Content
          className="fixed top-1/2 left-1/2 w-[min(92vw,480px)] -translate-x-1/2 -translate-y-1/2 rounded-xl border-[0.5px] border-hairline bg-background p-6"
          style={{ boxShadow: 'var(--elev-overlay)' }}
        >
          <DialogPrimitive.Title className="text-lg font-medium tracking-tight">{title}</DialogPrimitive.Title>
          {description !== undefined ? (
            <DialogPrimitive.Description className="mt-2 text-sm text-muted-foreground">
              {description}
            </DialogPrimitive.Description>
          ) : (
            <DialogPrimitive.Description className="sr-only">{title}</DialogPrimitive.Description>
          )}
          <div className="mt-5">{children}</div>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  )
}

export const DialogClose = DialogPrimitive.Close
