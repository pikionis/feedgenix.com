import { useId, type ReactNode } from 'react'
import { Label } from './label'

/** A labelled control with its hint and its error, wired for screen readers. */
export function Field({
  label,
  hint,
  error,
  children,
}: {
  label: string
  hint?: ReactNode
  error?: string | undefined
  children: (control: { id: string; 'aria-invalid': boolean; 'aria-describedby': string | undefined }) => ReactNode
}) {
  const id = useId()
  const describedBy = error !== undefined ? `${id}-error` : hint !== undefined ? `${id}-hint` : undefined

  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children({ id, 'aria-invalid': error !== undefined, 'aria-describedby': describedBy })}
      {error !== undefined ? (
        <p id={`${id}-error`} className="text-sm text-error">
          {error}
        </p>
      ) : hint !== undefined ? (
        <p id={`${id}-hint`} className="text-sm text-faint">
          {hint}
        </p>
      ) : null}
    </div>
  )
}
