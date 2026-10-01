import { useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { Notice } from '~/components/ui/notice'
import { api } from '~/lib/api/client'
import type { FeedImport } from '~/lib/api/types'
import { applyServerErrors, zodResolver } from '~/lib/forms'
import { feedErrorMessage } from './errors'
import { DELIMITERS, ENCODINGS, isDelimiter, isEncoding } from './labels'
import { feedImportsQuery, feedQuery } from './queries'

const schema = z.object({
  encoding: z.string(),
  delimiter: z.string(),
  xml_item_path: z
    .string()
    .trim()
    .regex(/^([^/\s]+(\/[^/\s]+)*)?$/, { error: 'Element names separated by /, like rss/channel/item.' }),
})

const selectClass = 'h-9 w-full rounded-md border-[0.5px] border-hairline bg-background px-2 text-sm'

/**
 * Read the stored file again with corrected options, when the automatic reading got it wrong
 * (mangled accents: the encoding; one column where there should be many: the separator). The
 * choice is kept for the feed's next files. Only the options that matter for the format are shown.
 */
export function ReadingOptionsForm({ data }: { data: FeedImport }) {
  const client = useQueryClient()
  const options = data.parse_options
  const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      encoding: options.encoding ?? '',
      delimiter: options.delimiter ?? '',
      xml_item_path: options.xml_item_path ?? '',
    },
  })
  const { errors, isSubmitting, isDirty } = form.formState
  const showEncoding = data.format !== 'xlsx'
  const showDelimiter = data.format === null || data.format === 'csv'
  const showItemPath = data.format === null || data.format === 'xml'

  const onSubmit = form.handleSubmit(async (values) => {
    const {
      data: queued,
      error,
      response,
    } = await api.POST('/v1/imports/{import}/reread', {
      params: { path: { import: data.id } },
      body: {
        encoding: isEncoding(values.encoding) ? values.encoding : null,
        delimiter: isDelimiter(values.delimiter) ? values.delimiter : null,
        xml_item_path: values.xml_item_path === '' ? null : values.xml_item_path,
      },
    })
    if (queued === undefined) {
      if (!applyServerErrors(error, form.setError, ['encoding', 'delimiter', 'xml_item_path'])) {
        form.setError('root.server', { message: feedErrorMessage(response.status, error) })
      }
      return
    }
    await Promise.all([
      client.invalidateQueries({ queryKey: feedQuery(data.feed_id).queryKey, exact: true }),
      client.invalidateQueries({ queryKey: feedImportsQuery(data.feed_id).queryKey }),
    ])
    form.reset(values)
  })

  if (!showEncoding && !showDelimiter && !showItemPath) return null

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-3">
        {showEncoding && (
          <Field label="Encoding" error={errors.encoding?.message}>
            {(control) => (
              <select className={selectClass} {...control} {...form.register('encoding')}>
                <option value="">Recognise it</option>
                {Object.entries(ENCODINGS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            )}
          </Field>
        )}
        {showDelimiter && (
          <Field label="Separator" error={errors.delimiter?.message}>
            {(control) => (
              <select className={selectClass} {...control} {...form.register('delimiter')}>
                <option value="">Recognise it</option>
                {Object.entries(DELIMITERS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            )}
          </Field>
        )}
        {showItemPath && (
          <Field label="Products at (XML)" hint="Leave empty to find them." error={errors.xml_item_path?.message}>
            {(control) => (
              <Input
                className="font-mono"
                placeholder="rss/channel/item"
                autoComplete="off"
                {...control}
                {...form.register('xml_item_path')}
              />
            )}
          </Field>
        )}
      </div>
      {errors.root?.server !== undefined && <Notice tone="error">{errors.root.server.message}</Notice>}
      <div>
        <Button type="submit" variant="secondary" disabled={isSubmitting || !isDirty}>
          {isSubmitting ? 'Reading…' : 'Read it again'}
        </Button>
      </div>
    </form>
  )
}
