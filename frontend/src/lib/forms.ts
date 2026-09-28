import type { FieldErrors, FieldValues, Path, Resolver, UseFormSetError } from 'react-hook-form'
import type { z } from 'zod'
import { isValidationError } from '~/lib/api/types'

/**
 * react-hook-form ↔ zod, without @hookform/resolvers (its optional peer chain conflicts with the
 * valibot version React Router uses). Field errors are keyed by their path, first message wins.
 */
export function zodResolver<Schema extends z.ZodType<FieldValues, FieldValues>>(
  schema: Schema,
): Resolver<z.input<Schema>, unknown, z.output<Schema>> {
  return async (values) => {
    const result = await schema.safeParseAsync(values)

    if (result.success) return { values: result.data, errors: {} }

    const errors: Record<string, { type: string; message: string }> = {}
    for (const issue of result.error.issues) {
      const key = issue.path.join('.')
      errors[key] ??= { type: issue.code, message: issue.message }
    }

    // The keys are the schema's own field paths, which is what react-hook-form expects.
    return { values: {}, errors: errors as FieldErrors<z.input<Schema>> }
  }
}

/**
 * Show a Laravel 422 on the form: each server error on its field (unknown fields go to `root`).
 * Returns true when the body was a validation error.
 */
export function applyServerErrors<T extends FieldValues>(
  body: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[],
): boolean {
  if (!isValidationError(body)) return false

  for (const [field, messages] of Object.entries(body.errors)) {
    const message = messages[0] ?? body.message
    if ((fields as readonly string[]).includes(field)) {
      setError(field as Path<T>, { type: 'server', message })
    } else {
      setError('root.server', { type: 'server', message })
    }
  }

  return true
}

/** One sentence for errors that are not about a field. */
export function describeFailure(status: number): string {
  if (status === 429) return 'Too many attempts. Wait a minute and try again.'
  if (status === 419) return 'Your session expired. Reload the page and try again.'
  if (status >= 500) return 'Something went wrong on our side. Try again in a moment.'

  return 'That did not work. Check the details and try again.'
}
