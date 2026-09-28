import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { Notice } from '~/components/ui/notice'
import { AuthLayout } from '~/features/auth/AuthLayout'
import { api } from '~/lib/api/client'
import { applyServerErrors, describeFailure, zodResolver } from '~/lib/forms'
import type { Route } from './+types/forgot-password'

export const meta: Route.MetaFunction = () => [
  { title: 'Reset your password — FeedGenix' },
  { name: 'robots', content: 'noindex' },
]

const schema = z.object({ email: z.email({ error: 'Enter a valid email address.' }) })

export default function ForgotPassword() {
  const [sent, setSent] = useState<string | null>(null)
  const form = useForm({ resolver: zodResolver(schema), defaultValues: { email: '' } })
  const { errors, isSubmitting } = form.formState

  const onSubmit = form.handleSubmit(async (values) => {
    const { data, error, response } = await api.POST('/auth/forgot-password', { body: values })

    if (data !== undefined) {
      setSent(data.status)
      return
    }

    if (!applyServerErrors(error, form.setError, ['email'])) {
      form.setError('root.server', { message: describeFailure(response.status) })
    }
  })

  return (
    <AuthLayout
      title="Reset your password"
      subtitle="We email you a link to choose a new one."
      footer={
        <Link to="/login" className="text-primary hover:text-primary-hover">
          Back to sign in
        </Link>
      }
    >
      {sent !== null ? (
        <Notice tone="success">{sent}</Notice>
      ) : (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          <Field label="Email" error={errors.email?.message}>
            {(control) => <Input type="email" autoComplete="email" {...control} {...form.register('email')} />}
          </Field>
          {errors.root?.server?.message !== undefined && <Notice tone="error">{errors.root.server.message}</Notice>}
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Sending…' : 'Email me a link'}
          </Button>
        </form>
      )}
    </AuthLayout>
  )
}
