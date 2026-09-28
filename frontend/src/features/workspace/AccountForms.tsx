import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { api } from '~/lib/api/client'
import type { SessionUser } from '~/lib/api/types'
import { applyServerErrors, describeFailure, zodResolver } from '~/lib/forms'
import { sessionQuery } from '~/lib/session'

const profileSchema = z.object({ name: z.string().trim().min(1, { error: 'Enter your name.' }).max(120) })

export function ProfileForm({ user }: { user: SessionUser }) {
  const client = useQueryClient()
  const [saved, setSaved] = useState(false)
  const form = useForm({ resolver: zodResolver(profileSchema), values: { name: user.name } })
  const { errors, isSubmitting, isDirty } = form.formState

  const onSubmit = form.handleSubmit(async (values) => {
    setSaved(false)
    const { data, error, response } = await api.PUT('/v1/profile', { body: values })
    if (data !== undefined) {
      client.setQueryData(sessionQuery.queryKey, data.user)
      setSaved(true)
      return
    }
    if (!applyServerErrors(error, form.setError, ['name'])) {
      form.setError('root.server', { message: describeFailure(response.status) })
    }
  })

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <Field label="Your name" error={errors.name?.message ?? errors.root?.server?.message}>
        {(control) => (
          <Input autoComplete="name" {...control} {...form.register('name', { onChange: () => setSaved(false) })} />
        )}
      </Field>
      <Field label="Email">{(control) => <Input disabled value={user.email} {...control} />}</Field>
      <div className="flex items-center gap-3">
        <Button type="submit" disabled={isSubmitting || !isDirty}>
          {isSubmitting ? 'Saving…' : 'Save'}
        </Button>
        {saved && <output className="text-sm text-success">Saved</output>}
      </div>
    </form>
  )
}

const passwordSchema = z
  .object({
    current_password: z.string(),
    password: z.string().min(8, { error: 'Use at least 8 characters.' }).max(200),
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    error: 'The passwords do not match.',
    path: ['password_confirmation'],
  })

/** Change the password, or set a first one for an account that signed up with Google. */
export function PasswordForm({ hasPassword }: { hasPassword: boolean }) {
  const client = useQueryClient()
  const [saved, setSaved] = useState(false)
  const form = useForm({
    resolver: zodResolver(passwordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })
  const { errors, isSubmitting } = form.formState

  const onSubmit = form.handleSubmit(async ({ current_password, ...values }) => {
    setSaved(false)
    const body = hasPassword ? { ...values, current_password } : values
    const { error, response } = await api.PUT('/v1/profile/password', { body })
    if (response.ok) {
      form.reset()
      await client.invalidateQueries({ queryKey: sessionQuery.queryKey })
      setSaved(true)
      return
    }
    if (!applyServerErrors(error, form.setError, ['current_password', 'password', 'password_confirmation'])) {
      form.setError('root.server', { message: describeFailure(response.status) })
    }
  })

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      {hasPassword ? (
        <Field label="Current password" error={errors.current_password?.message}>
          {(control) => (
            <Input
              type="password"
              autoComplete="current-password"
              {...control}
              {...form.register('current_password')}
            />
          )}
        </Field>
      ) : (
        <p className="text-sm text-muted-foreground">
          You sign in with Google. Set a password to also sign in with your email.
        </p>
      )}
      <Field label="New password" hint="At least 8 characters." error={errors.password?.message}>
        {(control) => <Input type="password" autoComplete="new-password" {...control} {...form.register('password')} />}
      </Field>
      <Field
        label="Repeat the new password"
        error={errors.password_confirmation?.message ?? errors.root?.server?.message}
      >
        {(control) => (
          <Input type="password" autoComplete="new-password" {...control} {...form.register('password_confirmation')} />
        )}
      </Field>
      <div className="flex items-center gap-3">
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Saving…' : hasPassword ? 'Change password' : 'Set password'}
        </Button>
        {saved && <output className="text-sm text-success">Saved. Your other sessions were signed out.</output>}
      </div>
    </form>
  )
}
