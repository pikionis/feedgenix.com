import { useQueryClient } from '@tanstack/react-query'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Dialog, DialogClose } from '~/components/ui/dialog'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { Notice } from '~/components/ui/notice'
import { api } from '~/lib/api/client'
import type { Feed } from '~/lib/api/types'
import { applyServerErrors, zodResolver } from '~/lib/forms'
import { feedErrorMessage } from './errors'
import {
  addressIssue,
  ChoiceOption,
  EMPTY_LOGIN,
  HostKeyField,
  hostKeyIssue,
  isFeedAddress,
  isSftp,
  LoginFields,
  loginBody,
  loginIssues,
  loginValues,
  NOT_AN_ADDRESS,
  PlainFtpWarning,
  type LoginKind,
} from './LoginFields'
import { feedQuery, feedsQuery } from './queries'

function isNewLogin(login: string): login is LoginKind {
  return login === 'password' || login === 'header' || login === 'key'
}

const schema = z
  .object({
    url: z.string().trim(),
    // `keep` sends nothing, so a stored login can never be wiped by accident (legacy D16).
    login: z.enum(['keep', 'remove', 'password', 'header', 'key']),
    hostKey: z.string().trim(),
    ...loginValues,
  })
  .superRefine((values, context) => {
    if (values.url !== '' && !isFeedAddress(values.url)) {
      context.addIssue({ code: 'custom', path: ['url'], message: NOT_AN_ADDRESS })
    }
    const fingerprint = hostKeyIssue(values.hostKey)
    if (fingerprint !== undefined) context.addIssue({ code: 'custom', path: ['hostKey'], message: fingerprint })
    if (isNewLogin(values.login)) {
      const issue = addressIssue(values.login, values.url)
      if (issue !== undefined && isFeedAddress(values.url)) {
        context.addIssue({ code: 'custom', path: ['url'], message: issue })
      }
      for (const [field, message] of loginIssues(values.login, values)) {
        context.addIssue({ code: 'custom', path: [field], message })
      }
    }
  })

/**
 * Where a URL feed is fetched from: a new address, a new login, or no login. The stored address and
 * login are never shown, only replaced. A change applies from the next fetch.
 */
export function EditSourceDialog({ feed, onClose }: { feed: Feed; onClose: () => void }) {
  const client = useQueryClient()
  const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      url: '',
      login: 'keep',
      hostKey: feed.host_key ?? '',
      ...EMPTY_LOGIN,
    } satisfies z.input<typeof schema>,
  })
  const { errors, isSubmitting } = form.formState
  const login = useWatch({ control: form.control, name: 'login' })
  const url = useWatch({ control: form.control, name: 'url' })
  const saved = feed.auth !== null

  const onSubmit = form.handleSubmit(async (values) => {
    const body = {
      ...(values.url === '' ? {} : { url: values.url }),
      ...(values.login === 'remove'
        ? { auth: null }
        : values.login === 'keep'
          ? {}
          : { auth: loginBody(values.login, values) }),
      ...(values.hostKey === (feed.host_key ?? '') ? {} : { host_key: values.hostKey === '' ? null : values.hostKey }),
    }
    if (Object.keys(body).length === 0) return onClose()

    const { data, error, response } = await api.PATCH('/v1/feeds/{feed}', { params: { path: { feed: feed.id } }, body })
    if (data === undefined) {
      if (!applyServerErrors(error, form.setError, ['url'])) {
        form.setError('root.server', { message: feedErrorMessage(response.status, error) })
      }
      return
    }

    await Promise.all([
      client.invalidateQueries({ queryKey: feedQuery(feed.id).queryKey, exact: true }),
      client.invalidateQueries({ queryKey: feedsQuery.queryKey, exact: true }),
    ])
    onClose()
  })

  return (
    <Dialog
      open
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
      title="Feed source"
      description={`Fetched from ${feed.host ?? 'its URL'}. Changes apply from the next fetch.`}
    >
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        <Field
          label="New feed URL"
          hint="Leave it empty to keep the current address, which stays private."
          error={errors.url?.message}
        >
          {(control) => (
            <Input
              type="url"
              inputMode="url"
              placeholder="https://shop.example/feed.xml"
              autoComplete="off"
              {...control}
              {...form.register('url')}
            />
          )}
        </Field>

        <fieldset className="flex flex-col gap-2" disabled={isSubmitting}>
          <legend className="mb-2 text-sm font-medium">Login</legend>
          <div className="grid grid-cols-2 gap-2">
            <ChoiceOption value="keep" current={login} register={form.register('login')}>
              {saved ? 'Keep the saved one' : 'No login'}
            </ChoiceOption>
            {saved && (
              <ChoiceOption value="remove" current={login} register={form.register('login')}>
                Remove it
              </ChoiceOption>
            )}
            <ChoiceOption value="password" current={login} register={form.register('login')}>
              {saved ? 'New password' : 'Password'}
            </ChoiceOption>
            <ChoiceOption value="header" current={login} register={form.register('login')}>
              {saved ? 'New header' : 'Header'}
            </ChoiceOption>
            <ChoiceOption value="key" current={login} register={form.register('login')}>
              {saved ? 'New private key' : 'Private key'}
            </ChoiceOption>
          </div>
          {saved && (
            <p className="text-sm text-faint">
              A saved login is never sent to another server: moving the feed to one removes it, unless you add a new one
              here.
            </p>
          )}
        </fieldset>

        {isNewLogin(login) && (
          <LoginFields
            kind={login}
            register={(field) => form.register(field)}
            errors={{
              username: errors.username?.message,
              password: errors.password?.message,
              headerName: errors.headerName?.message,
              headerValue: errors.headerValue?.message,
              privateKey: errors.privateKey?.message,
              passphrase: errors.passphrase?.message,
            }}
          />
        )}
        {login === 'password' && /^ftp:/i.test(url.trim()) && <PlainFtpWarning />}
        {(feed.host_key !== null || isSftp(url)) && (
          <HostKeyField
            hint="The server must show this key on every fetch, or nothing is sent to it. Empty it to trust the key it shows next: do that only if your host rebuilt or moved the server."
            error={errors.hostKey?.message}
            register={form.register('hostKey')}
          />
        )}

        {errors.root?.server !== undefined && <Notice tone="error">{errors.root.server.message}</Notice>}

        <div className="flex justify-end gap-2">
          <DialogClose asChild>
            <Button type="button" variant="secondary">
              Cancel
            </Button>
          </DialogClose>
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
