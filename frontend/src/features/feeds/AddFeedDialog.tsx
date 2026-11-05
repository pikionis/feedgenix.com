import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useNavigate } from 'react-router'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Dialog, DialogClose } from '~/components/ui/dialog'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { Notice } from '~/components/ui/notice'
import { workspaceQuery } from '~/features/workspace/queries'
import { api } from '~/lib/api/client'
import { applyServerErrors, zodResolver } from '~/lib/forms'
import { feedErrorMessage } from './errors'
import { FileField, UploadProgressBar } from './FileField'
import { ChoiceOption, EMPTY_LOGIN, HTTPS_ONLY, LoginFields, loginBody, loginIssues, loginValues } from './LoginFields'
import { feedsQuery } from './queries'
import { useFileUpload } from './useFileUpload'

const schema = z
  .object({
    name: z.string().trim().min(1, { error: 'Name the feed.' }).max(120, { error: 'Use at most 120 characters.' }),
    source: z.enum(['url', 'upload']),
    url: z.string().trim(),
    login: z.enum(['none', 'password', 'header']),
    ...loginValues,
  })
  .superRefine((values, context) => {
    if (values.source !== 'url') return
    if (!/^https?:\/\/\S+$/i.test(values.url)) {
      context.addIssue({ code: 'custom', path: ['url'], message: 'Enter the full address, starting with https://.' })
    } else if (values.login !== 'none' && !/^https:/i.test(values.url)) {
      context.addIssue({ code: 'custom', path: ['url'], message: HTTPS_ONLY })
    }
    if (values.login !== 'none') {
      for (const [field, message] of loginIssues(values.login, values)) {
        context.addIssue({ code: 'custom', path: [field], message })
      }
    }
  })

const DEFAULTS: z.input<typeof schema> = { name: '', source: 'url', url: '', login: 'none', ...EMPTY_LOGIN }

/**
 * Add a feed: a URL is fetched and read at once; a file is uploaded in chunks, then read. Either
 * way the new feed's page opens. Over the plan's feed limit the API answers 402: this dialog closes
 * and the upgrade dialog takes over.
 */
export function AddFeedDialog({ onClose }: { onClose: () => void }) {
  const client = useQueryClient()
  const navigate = useNavigate()
  const upload = useFileUpload()
  const form = useForm({ resolver: zodResolver(schema), defaultValues: DEFAULTS })
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string>()
  // Set once the feed exists: a failed upload is retried into it, never into a second feed.
  const [createdFeedId, setCreatedFeedId] = useState<number | null>(null)
  const { errors, isSubmitting } = form.formState
  const source = useWatch({ control: form.control, name: 'source' })
  const login = useWatch({ control: form.control, name: 'login' })

  const open = async (feedId: number) => {
    await Promise.all([
      client.invalidateQueries({ queryKey: feedsQuery.queryKey }),
      client.invalidateQueries({ queryKey: workspaceQuery.queryKey }),
    ])
    onClose()
    await navigate(`/app/feeds/${feedId}`)
  }

  const onSubmit = form.handleSubmit(async (values) => {
    setFileError(undefined)
    if (values.source === 'upload' && (file === null || file.size === 0)) {
      setFileError(file === null ? 'Choose the file to upload.' : 'This file is empty.')
      return
    }

    let feedId = createdFeedId
    if (feedId === null) {
      const { data, error, response } = await api.POST('/v1/feeds', {
        // With a file, its size goes first: a file the plan cannot read is refused before a feed is made.
        body:
          values.source === 'url'
            ? {
                name: values.name,
                url: values.url,
                ...(values.login === 'none' ? {} : { auth: loginBody(values.login, values) }),
              }
            : file === null
              ? { name: values.name }
              : { name: values.name, upload_size: file.size },
      })
      if (data === undefined) {
        if (response.status === 402) return onClose()
        if (!applyServerErrors(error, form.setError, ['name', 'url'])) {
          form.setError('root.server', { message: feedErrorMessage(response.status, error) })
        }
        return
      }
      if (values.source === 'url') return open(data.feed.id)

      feedId = data.feed.id
      setCreatedFeedId(feedId)
      // The feed exists from here on, whatever happens to the upload.
      void client.invalidateQueries({ queryKey: feedsQuery.queryKey })
      void client.invalidateQueries({ queryKey: workspaceQuery.queryKey })
    }

    if (file === null) return
    const outcome = await upload.start(feedId, file)
    if ('import' in outcome) return open(feedId)
    if ('error' in outcome) {
      if (outcome.overPlan) return onClose()
      form.setError('root.server', { message: outcome.error })
    }
  })

  const busy = isSubmitting || upload.uploading

  return (
    <Dialog
      open
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
      title="Add a feed"
      description="The address your shop publishes its products at, or a file you export."
    >
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        <Field label="Name" error={errors.name?.message}>
          {(control) => (
            <Input
              placeholder="Main shop"
              autoComplete="off"
              disabled={createdFeedId !== null}
              {...control}
              {...form.register('name')}
            />
          )}
        </Field>

        <fieldset className="flex flex-col gap-2" disabled={createdFeedId !== null || busy}>
          <legend className="mb-2 text-sm font-medium">Where the products come from</legend>
          <div className="grid grid-cols-2 gap-2">
            <ChoiceOption value="url" current={source} register={form.register('source')}>
              A URL
            </ChoiceOption>
            <ChoiceOption value="upload" current={source} register={form.register('source')}>
              A file
            </ChoiceOption>
          </div>
        </fieldset>

        {source === 'url' ? (
          <>
            <Field
              label="Feed URL"
              hint="Fetched now and whenever you ask. It stays private: only its host is shown."
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

            <fieldset className="flex flex-col gap-2" disabled={busy}>
              <legend className="mb-2 text-sm font-medium">Does this address need a login?</legend>
              <div className="grid grid-cols-3 gap-2">
                <ChoiceOption value="none" current={login} register={form.register('login')}>
                  No
                </ChoiceOption>
                <ChoiceOption value="password" current={login} register={form.register('login')}>
                  Password
                </ChoiceOption>
                <ChoiceOption value="header" current={login} register={form.register('login')}>
                  Header
                </ChoiceOption>
              </div>
            </fieldset>

            {login !== 'none' && (
              <LoginFields
                kind={login}
                register={(field) => form.register(field)}
                errors={{
                  username: errors.username?.message,
                  password: errors.password?.message,
                  headerName: errors.headerName?.message,
                  headerValue: errors.headerValue?.message,
                }}
              />
            )}
          </>
        ) : (
          <FileField
            file={file}
            disabled={busy}
            error={fileError}
            onChange={(chosen) => {
              setFile(chosen)
              setFileError(undefined)
            }}
          />
        )}

        {upload.progress !== null && <UploadProgressBar progress={upload.progress} />}

        {errors.root?.server !== undefined && (
          <Notice tone="error">
            {createdFeedId !== null && 'The feed was created, but its file did not arrive. '}
            {errors.root.server.message}
          </Notice>
        )}

        <div className="flex justify-end gap-2">
          <DialogClose asChild>
            <Button type="button" variant="secondary">
              {upload.uploading ? 'Cancel upload' : 'Cancel'}
            </Button>
          </DialogClose>
          <Button type="submit" disabled={busy}>
            {busy
              ? source === 'url'
                ? 'Reading the feed…'
                : 'Uploading…'
              : createdFeedId !== null
                ? 'Upload again'
                : 'Add feed'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
