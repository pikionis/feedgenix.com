import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { applyServerErrors, describeFailure, zodResolver } from '~/lib/forms'

const schema = z.object({ code: z.string().trim().min(6, { error: 'Enter the code.' }).max(40) })

/** One code field: a 6-digit app code (or, where allowed, a recovery code). */
export function TwoFactorCodeForm({
  label,
  hint,
  submitLabel,
  onSubmit,
}: {
  label: string
  hint: string
  submitLabel: string
  onSubmit: (code: string) => Promise<{ ok: boolean; status: number; error?: unknown }>
}) {
  const form = useForm({ resolver: zodResolver(schema), defaultValues: { code: '' } })
  const { errors, isSubmitting } = form.formState

  const submit = form.handleSubmit(async ({ code }) => {
    const result = await onSubmit(code)
    if (result.ok) return
    if (!applyServerErrors(result.error, form.setError, ['code'])) {
      form.setError('code', { message: describeFailure(result.status) })
    }
  })

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      <Field label={label} hint={hint} error={errors.code?.message}>
        {(control) => (
          <Input
            inputMode="numeric"
            autoComplete="one-time-code"
            spellCheck={false}
            className="font-mono tracking-widest"
            {...control}
            {...form.register('code')}
          />
        )}
      </Field>
      <Button type="submit" disabled={isSubmitting}>
        {isSubmitting ? 'Checking…' : submitLabel}
      </Button>
    </form>
  )
}
