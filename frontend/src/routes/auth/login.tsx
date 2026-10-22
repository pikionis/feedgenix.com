import { useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { Notice } from '~/components/ui/notice'
import { AuthLayout } from '~/features/auth/AuthLayout'
import { Divider, GOOGLE_ERRORS, GoogleButton, useGoogleSignIn } from '~/features/auth/google'
import { api } from '~/lib/api/client'
import { applyServerErrors, describeFailure, zodResolver } from '~/lib/forms'
import { nextPath, sessionQuery } from '~/lib/session'
import type { Route } from './+types/login'

export const meta: Route.MetaFunction = () => [{ title: 'Sign in — FeedGenix' }, { name: 'robots', content: 'noindex' }]

const schema = z.object({
  email: z.email({ error: 'Enter a valid email address.' }),
  password: z.string().min(1, { error: 'Enter your password.' }),
})

export default function Login() {
  const [search] = useSearchParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const form = useForm({ resolver: zodResolver(schema), defaultValues: { email: '', password: '' } })
  const { errors, isSubmitting } = form.formState

  const onSubmit = form.handleSubmit(async (values) => {
    const { data, error, response } = await api.POST('/auth/login', { body: { ...values, remember: true } })

    if (data !== undefined) {
      queryClient.setQueryData(sessionQuery.queryKey, data.user)
      await navigate(nextPath(search))
      return
    }

    if (!applyServerErrors(error, form.setError, ['email', 'password'])) {
      form.setError('root.server', { message: describeFailure(response.status) })
    }
  })

  const googleError = GOOGLE_ERRORS[search.get('error') ?? '']
  const google = useGoogleSignIn()

  return (
    <AuthLayout
      title="Sign in"
      subtitle="Welcome back."
      footer={
        <>
          New here?{' '}
          <Link to="/register" className="text-primary hover:text-primary-hover">
            Create an account
          </Link>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {search.get('reset') === '1' && (
          <Notice tone="success">Your password was reset. Sign in with the new one.</Notice>
        )}
        {googleError !== undefined && <Notice tone="error">{googleError}</Notice>}
        {google && <GoogleButton />}
      </div>
      {google && <Divider />}
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        <Field label="Email" error={errors.email?.message}>
          {(control) => <Input type="email" autoComplete="email" {...control} {...form.register('email')} />}
        </Field>
        <Field label="Password" error={errors.password?.message}>
          {(control) => (
            <Input type="password" autoComplete="current-password" {...control} {...form.register('password')} />
          )}
        </Field>
        {errors.root?.server?.message !== undefined && <Notice tone="error">{errors.root.server.message}</Notice>}
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Signing in…' : 'Sign in'}
        </Button>
        <Link to="/forgot-password" className="text-center text-sm text-muted-foreground hover:text-foreground">
          Forgot your password?
        </Link>
      </form>
    </AuthLayout>
  )
}
