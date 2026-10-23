import { useState } from 'react'
import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import { Notice } from '~/components/ui/notice'
import type { ExportPreset } from '~/lib/api/types'
import type { NewExport } from './actions'
import { DIALECTS, LAYOUTS } from './labels'

/** A new export: which channel's columns, written how, in which languages. */
export function NewExportForm({
  presets,
  locales,
  busy,
  error,
  onCreate,
  onCancel,
}: {
  presets: ExportPreset[]
  locales: { code: string; name: string }[]
  busy: boolean
  error: string | undefined
  onCreate: (body: NewExport) => Promise<boolean>
  onCancel?: () => void
}) {
  const first = presets[0]
  const [preset, setPreset] = useState(first?.key ?? '')
  const chosen = presets.find((item) => item.key === preset) ?? first
  const [name, setName] = useState(first?.label ?? '')
  const [dialect, setDialect] = useState<NewExport['dialect']>(first?.dialects[0] ?? 'csv')
  const [layout, setLayout] = useState<NewExport['layout']>(first?.layouts[0] ?? 'wide')
  const [chosenLocales, setChosenLocales] = useState(locales.map((locale) => locale.code))
  if (chosen === undefined) return null

  const pick = (key: string) => {
    const next = presets.find((item) => item.key === key)
    if (next === undefined) return
    setPreset(key)
    setName((current) => (presets.some((item) => item.label === current) || current === '' ? next.label : current))
    setDialect((current) => (next.dialects.includes(current) ? current : (next.dialects[0] ?? 'csv')))
    setLayout((current) => (next.layouts.includes(current) ? current : (next.layouts[0] ?? 'wide')))
  }
  const select = 'h-9 rounded-md border-[0.5px] border-hairline bg-background px-2 text-sm'

  return (
    <Card className="flex flex-col gap-4">
      <h2 className="font-medium">New export</h2>
      <form
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          void onCreate({ name: name.trim(), preset, dialect, layout, locales: chosenLocales })
        }}
      >
        <div className="flex flex-wrap gap-4">
          <label className="flex flex-col gap-1.5 text-sm">
            <span>For</span>
            <select className={select} value={preset} onChange={(event) => pick(event.target.value)}>
              {presets.map((item) => (
                <option key={item.key} value={item.key}>
                  {item.label}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1.5 text-sm">
            <span>Name</span>
            <input
              required
              maxLength={80}
              value={name}
              onChange={(event) => setName(event.target.value)}
              className="h-9 w-56 rounded-md border-[0.5px] border-hairline bg-background px-3 text-sm"
            />
          </label>
          <label className="flex flex-col gap-1.5 text-sm">
            <span>Format</span>
            <select
              className={select}
              value={dialect}
              disabled={chosen.dialects.length < 2}
              onChange={(event) => setDialect(event.target.value as NewExport['dialect'])}
            >
              {chosen.dialects.map((item) => (
                <option key={item} value={item}>
                  {DIALECTS[item]}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1.5 text-sm">
            <span>Files</span>
            <select
              className={select}
              value={layout}
              disabled={chosen.layouts.length < 2}
              onChange={(event) => setLayout(event.target.value as NewExport['layout'])}
            >
              {chosen.layouts.map((item) => (
                <option key={item} value={item}>
                  {LAYOUTS[item]}
                </option>
              ))}
            </select>
          </label>
        </div>
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm">Languages</legend>
          <div className="flex flex-wrap gap-x-6 gap-y-2">
            {locales.map((locale) => (
              <label key={locale.code} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="size-4 accent-primary"
                  checked={chosenLocales.includes(locale.code)}
                  onChange={(event) =>
                    setChosenLocales((current) =>
                      event.target.checked ? [...current, locale.code] : current.filter((code) => code !== locale.code),
                    )
                  }
                />
                {locale.name}
              </label>
            ))}
          </div>
        </fieldset>
        {error !== undefined && <Notice tone="error">{error}</Notice>}
        <div className="flex gap-2">
          <Button type="submit" disabled={busy || name.trim() === '' || chosenLocales.length === 0}>
            {busy ? 'Creating…' : 'Create the export'}
          </Button>
          {onCancel !== undefined && (
            <Button type="button" variant="ghost" onClick={onCancel}>
              Cancel
            </Button>
          )}
        </div>
      </form>
    </Card>
  )
}
