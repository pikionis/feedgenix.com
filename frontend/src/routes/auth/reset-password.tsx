import { useForm } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { Notice } from '~/components/ui/notice'
import { AuthLayout } from '~/features/auth/AuthLayout'
import { api } from '~/lib/api/client'
import { applyServerErrors, describeFailure, zodResolver } from '~/lib/forms'
import type { Route } from './+types/reset-password'

export const meta: Route.MetaFunction = () => [
  { title: 'Choose a new password — FeedGenix' },
  { name: 'robots', content: 'noindex' },
]

const schema = z
  .object({
    password: z.string().min(8, { error: 'Use at least 8 characters.' }).max(200),
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    error: 'The passwords do not match.',
    path: ['password_confirmation'],
  })

export default function ResetPassword() {
  const [search] = useSearchParams()
  const navigate = useNavigate()
  const token = search.get('token') ?? ''
  const email = search.get('email') ?? ''
  const form = useForm({ resolver: zodResolver(schema), defaultValues: { password: '', password_confirmation: '' } })
  const { errors, isSubmitting } = form.formState

  const onSubmit = form.handleSubmit(async (values) => {
    const { data, error, response } = await api.POST('/auth/reset-password', { body: { ...values, token, email } })

    if (data !== undefined) {
      await navigate('/login?reset=1')
      return
    }

    if (!applyServerErrors(error, form.setError, ['password', 'password_confirmation'])) {
      form.setError('root.server', { message: describeFailure(response.status) })
    }
  })

  const footer = (
    <Link to="/forgot-password" className="text-primary hover:text-primary-hover">
      Request a new link
    </Link>
  )

  if (token === '' || email === '') {
    return (
      <AuthLayout title="Choose a new password" footer={footer}>
        <Notice tone="error">This link is incomplete. Open the link from the email again, or request a new one.</Notice>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title="Choose a new password" subtitle={email} footer={footer}>
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        <Field label="New password" hint="At least 8 characters." error={errors.password?.message}>
          {(control) => (
            <Input type="password" autoComplete="new-password" {...control} {...form.register('password')} />
          )}
        </Field>
        <Field label="Repeat the password" error={errors.password_confirmation?.message}>
          {(control) => (
            <Input
              type="password"
              autoComplete="new-password"
              {...control}
              {...form.register('password_confirmation')}
            />
          )}
        </Field>
        {errors.root?.server?.message !== undefined && <Notice tone="error">{errors.root.server.message}</Notice>}
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Saving…' : 'Save the new password'}
        </Button>
      </form>
    </AuthLayout>
  )
}
