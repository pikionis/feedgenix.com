import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { api } from '~/lib/api/client'
import type { Workspace } from '~/lib/api/types'
import { applyServerErrors, describeFailure, zodResolver } from '~/lib/forms'
import { sessionQuery } from '~/lib/session'
import { workspaceQuery } from './queries'

const schema = z.object({ name: z.string().trim().min(2, { error: 'Use at least 2 characters.' }).max(80) })

export function WorkspaceNameForm({ workspace }: { workspace: Workspace['workspace'] }) {
  const client = useQueryClient()
  const [saved, setSaved] = useState(false)
  const canManage = workspace.role === 'owner' || workspace.role === 'admin'
  const form = useForm({ resolver: zodResolver(schema), values: { name: workspace.name } })
  const { errors, isSubmitting, isDirty } = form.formState

  const onSubmit = form.handleSubmit(async (values) => {
    setSaved(false)
    const { data, error, response } = await api.PUT('/v1/workspace', { body: values })
    if (data !== undefined) {
      client.setQueryData(workspaceQuery.queryKey, data)
      await client.invalidateQueries({ queryKey: sessionQuery.queryKey })
      setSaved(true)
      return
    }
    if (!applyServerErrors(error, form.setError, ['name'])) {
      form.setError('root.server', { message: describeFailure(response.status) })
    }
  })

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <Field
        label="Workspace name"
        hint={canManage ? undefined : 'Only the owner or an admin can rename it.'}
        error={errors.name?.message ?? errors.root?.server?.message}
      >
        {(control) => (
          <Input disabled={!canManage} {...control} {...form.register('name', { onChange: () => setSaved(false) })} />
        )}
      </Field>
      <Field label="Workspace address">
        {(control) => <Input disabled value={workspace.slug} className="font-mono" {...control} />}
      </Field>
      {canManage && (
        <div className="flex items-center gap-3">
          <Button type="submit" disabled={isSubmitting || !isDirty}>
            {isSubmitting ? 'Saving…' : 'Save'}
          </Button>
          {saved && <output className="text-sm text-success">Saved</output>}
        </div>
      )}
    </form>
  )
}
