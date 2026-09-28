import { useQuery, useQueryClient } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { useState } from 'react'
import { Button } from '~/components/ui/button'
import { Label } from '~/components/ui/label'
import { Notice } from '~/components/ui/notice'
import { UsageBar } from '~/components/ui/usage-bar'
import { api } from '~/lib/api/client'
import type { Workspace } from '~/lib/api/types'
import { describeFailure } from '~/lib/forms'
import { languagesQuery, workspaceQuery } from './queries'

/**
 * The target languages. Saving replaces the whole list; over the plan's limit the API answers 402
 * and the upgrade dialog opens with the numbers (nothing is saved). The parent keys it by the saved
 * list, so a new saved list starts a fresh selection.
 */
export function LanguageSettings({ workspace }: { workspace: Workspace }) {
  const client = useQueryClient()
  const registry = useQuery(languagesQuery)
  const current = workspace.languages.map((language) => language.code)
  const [selected, setSelected] = useState<string[]>(current)
  const [status, setStatus] = useState<{ tone: 'success' | 'error'; text: string } | null>(null)
  const [saving, setSaving] = useState(false)
  const canManage = workspace.workspace.role === 'owner' || workspace.workspace.role === 'admin'
  const limit = workspace.entitlements.limits.languages

  const byCode = new Map((registry.data ?? []).map((language) => [language.code, language]))
  const changed = selected.join('|') !== current.join('|')

  const save = async () => {
    setSaving(true)
    setStatus(null)
    const { data, response } = await api.PUT('/v1/workspace/languages', { body: { languages: selected } })
    setSaving(false)

    if (data !== undefined) {
      client.setQueryData(workspaceQuery.queryKey, data)
      setStatus({ tone: 'success', text: 'Saved' })
    } else if (response.status !== 402) {
      // A 402 opens the upgrade dialog; anything else is shown here.
      setStatus({ tone: 'error', text: describeFailure(response.status) })
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <UsageBar label="Target languages on your plan" used={selected.length} allowed={limit} />
      <ul className="flex flex-wrap gap-2" aria-label="Selected target languages">
        {selected.length === 0 && <li className="text-sm text-muted-foreground">None yet.</li>}
        {selected.map((code) => (
          <li key={code} className="inline-flex items-center gap-1 rounded-full bg-surface py-1 pr-1.5 pl-3 text-sm">
            {byCode.get(code)?.name ?? code}
            {canManage && (
              <button
                type="button"
                className="rounded-full p-0.5 text-muted-foreground hover:text-foreground"
                aria-label={`Remove ${byCode.get(code)?.name ?? code}`}
                onClick={() => setSelected(selected.filter((item) => item !== code))}
              >
                <X size={14} strokeWidth={1.5} aria-hidden="true" />
              </button>
            )}
          </li>
        ))}
      </ul>
      {canManage ? (
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="add-language">Add a language</Label>
            <select
              id="add-language"
              className="h-9 rounded-md border-[0.5px] border-hairline bg-background px-2 text-sm"
              value=""
              onChange={(event) => {
                if (event.target.value !== '') setSelected([...selected, event.target.value])
              }}
            >
              <option value="">Choose…</option>
              {(registry.data ?? [])
                .filter((language) => !selected.includes(language.code))
                .map((language) => (
                  <option key={language.code} value={language.code}>
                    {language.name} · {language.native_name}
                  </option>
                ))}
            </select>
          </div>
          <Button onClick={() => void save()} disabled={saving || !changed}>
            {saving ? 'Saving…' : 'Save languages'}
          </Button>
        </div>
      ) : (
        <p className="text-sm text-faint">Only the owner or an admin can change the languages.</p>
      )}
      {status !== null && <Notice tone={status.tone}>{status.text}</Notice>}
    </div>
  )
}
