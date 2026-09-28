import { useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { Notice } from '~/components/ui/notice'
import { AuthLayout } from '~/features/auth/AuthLayout'
import { Divider, GOOGLE_ERRORS, GoogleButton } from '~/features/auth/google'
import { api } from '~/lib/api/client'
import { applyServerErrors, describeFailure, zodResolver } from '~/lib/forms'
import { sessionQuery } from '~/lib/session'
import type { Route } from './+types/register'

export const meta: Route.MetaFunction = () => [
  { title: 'Create your account — FeedGenix' },
  { name: 'robots', content: 'noindex' },
]

const schema = z
  .object({
    name: z.string().trim().min(1, { error: 'Enter your name.' }).max(120),
    email: z.email({ error: 'Enter a valid email address.' }),
    password: z.string().min(8, { error: 'Use at least 8 characters.' }).max(200),
    password_confirmation: z.string(),
    invite_code: z.string().trim().max(64),
  })
  .refine((values) => values.password === values.password_confirmation, {
    error: 'The passwords do not match.',
    path: ['password_confirmation'],
  })

const FIELDS = ['name', 'email', 'password', 'password_confirmation', 'invite_code'] as const

export default function Register() {
  const [search] = useSearchParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const invite = search.get('invite') ?? ''
  const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: { name: '', email: '', password: '', password_confirmation: '', invite_code: invite },
  })
  const { errors, isSubmitting } = form.formState

  const onSubmit = form.handleSubmit(async (values) => {
    const { data, error, response } = await api.POST('/auth/register', {
      body: { ...values, invite_code: values.invite_code === '' ? null : values.invite_code, remember: true },
    })

    if (data !== undefined) {
      queryClient.setQueryData(sessionQuery.queryKey, data.user)
      await navigate('/app')
      return
    }

    if (!applyServerErrors(error, form.setError, FIELDS)) {
      form.setError('root.server', {
        message: response.status === 403 ? 'Sign-up is closed at the moment.' : describeFailure(response.status),
      })
    }
  })

  const googleError = GOOGLE_ERRORS[search.get('error') ?? '']

  return (
    <AuthLayout
      title="Create your account"
      subtitle="Your catalog, in every language your buyers shop in."
      footer={
        <>
          Already have an account?{' '}
          <Link to="/login" className="text-primary hover:text-primary-hover">
            Sign in
          </Link>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {googleError !== undefined && <Notice tone="error">{googleError}</Notice>}
        <GoogleButton invite={invite} />
      </div>
      <Divider />
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        <Field label="Name" error={errors.name?.message}>
          {(control) => <Input autoComplete="name" {...control} {...form.register('name')} />}
        </Field>
        <Field label="Email" error={errors.email?.message}>
          {(control) => <Input type="email" autoComplete="email" {...control} {...form.register('email')} />}
        </Field>
        <Field label="Password" hint="At least 8 characters." error={errors.password?.message}>
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
        <Field label="Invite code" hint="FeedGenix is invite-only during the beta." error={errors.invite_code?.message}>
          {(control) => <Input autoComplete="off" spellCheck={false} {...control} {...form.register('invite_code')} />}
        </Field>
        {errors.root?.server?.message !== undefined && <Notice tone="error">{errors.root.server.message}</Notice>}
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Creating your account…' : 'Create account'}
        </Button>
      </form>
    </AuthLayout>
  )
}
