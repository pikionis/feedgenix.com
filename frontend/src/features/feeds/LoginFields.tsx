import type { ReactNode } from 'react'
import type { UseFormRegisterReturn } from 'react-hook-form'
import { z } from 'zod'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { cn } from '~/lib/utils'

/** The login's values, never trimmed: they reach the source's server exactly as typed. */
export const loginValues = {
  username: z.string(),
  password: z.string(),
  headerName: z.string(),
  headerValue: z.string(),
}

export type LoginKind = 'password' | 'header'
export type LoginValues = { username: string; password: string; headerName: string; headerValue: string }

export const EMPTY_LOGIN: LoginValues = { username: '', password: '', headerName: '', headerValue: '' }

export const HTTPS_ONLY = 'A login is only ever sent over https. Use an https:// address.'

/** What is missing or cannot be sent, field by field. The server checks all of it again. */
export function loginIssues(kind: LoginKind, values: LoginValues): [keyof LoginValues, string][] {
  const issues: [keyof LoginValues, string][] = []
  if (kind === 'password') {
    if (values.username === '') issues.push(['username', 'Enter the username.'])
    if (values.username.includes(':')) issues.push(['username', 'A username cannot contain a colon.'])
    if (values.password === '') issues.push(['password', 'Enter the password.'])
  } else {
    if (values.headerName === '') issues.push(['headerName', 'Enter the name of the header.'])
    if (values.headerValue === '') issues.push(['headerValue', 'Enter the value of the header.'])
  }

  return issues
}

export function loginBody(kind: LoginKind, values: LoginValues) {
  return kind === 'password'
    ? { type: kind, username: values.username, password: values.password }
    : { type: kind, name: values.headerName, value: values.headerValue }
}

/**
 * The fields of a login. The browser must not fill them from what it saved for FeedGenix itself:
 * these belong to the customer's feed server.
 */
export function LoginFields({
  kind,
  register,
  errors,
}: {
  kind: LoginKind
  register: (name: keyof LoginValues) => UseFormRegisterReturn
  errors: Partial<Record<keyof LoginValues, string | undefined>>
}) {
  if (kind === 'header') {
    return (
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Header name" error={errors.headerName}>
          {(control) => <Input placeholder="X-Api-Key" autoComplete="off" {...control} {...register('headerName')} />}
        </Field>
        <Field label="Header value" error={errors.headerValue}>
          {(control) => <Input type="password" autoComplete="new-password" {...control} {...register('headerValue')} />}
        </Field>
      </div>
    )
  }

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label="Username" error={errors.username}>
        {(control) => <Input autoComplete="off" {...control} {...register('username')} />}
      </Field>
      <Field label="Password" error={errors.password}>
        {(control) => <Input type="password" autoComplete="new-password" {...control} {...register('password')} />}
      </Field>
    </div>
  )
}

/** One choice of a small set, as a tile. */
export function ChoiceOption({
  value,
  current,
  register,
  children,
}: {
  value: string
  current: string
  register: UseFormRegisterReturn
  children: ReactNode
}) {
  return (
    <label
      className={cn(
        'flex cursor-pointer items-center gap-2 rounded-md border-[0.5px] px-3 py-2 text-sm has-disabled:cursor-not-allowed has-disabled:opacity-60',
        current === value ? 'border-primary bg-primary/5' : 'border-hairline hover:bg-surface',
      )}
    >
      <input type="radio" value={value} className="accent-primary" {...register} />
      {children}
    </label>
  )
}
