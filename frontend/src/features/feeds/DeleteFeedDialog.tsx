import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router'
import { Button } from '~/components/ui/button'
import { Dialog, DialogClose } from '~/components/ui/dialog'
import { Notice } from '~/components/ui/notice'
import { workspaceQuery } from '~/features/workspace/queries'
import { api } from '~/lib/api/client'
import type { Feed } from '~/lib/api/types'
import { feedErrorMessage } from './errors'
import { feedsQuery } from './queries'

export function DeleteFeedDialog({ feed, onClose }: { feed: Feed; onClose: () => void }) {
  const client = useQueryClient()
  const navigate = useNavigate()
  const [deleting, setDeleting] = useState(false)
  const [error, setError] = useState<string>()

  const remove = async () => {
    setDeleting(true)
    const { error: body, response } = await api.DELETE('/v1/feeds/{feed}', { params: { path: { feed: feed.id } } })
    if (!response.ok) {
      setDeleting(false)
      return setError(feedErrorMessage(response.status, body))
    }
    // Leave its page first, so nothing asks for the deleted feed again.
    onClose()
    await navigate('/app/feeds')
    client.removeQueries({ queryKey: [...feedsQuery.queryKey, feed.id] })
    await Promise.all([
      client.invalidateQueries({ queryKey: feedsQuery.queryKey, exact: true }),
      client.invalidateQueries({ queryKey: workspaceQuery.queryKey }),
    ])
  }

  return (
    <Dialog
      open
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
      title={`Delete ${feed.name}?`}
      description="Its imports and stored files are deleted. This cannot be undone."
    >
      <div className="flex flex-col gap-4">
        {error !== undefined && <Notice tone="error">{error}</Notice>}
        <div className="flex justify-end gap-2">
          <DialogClose asChild>
            <Button type="button" variant="secondary">
              Keep it
            </Button>
          </DialogClose>
          <Button
            type="button"
            className="bg-error hover:bg-error/90"
            disabled={deleting}
            onClick={() => void remove()}
          >
            {deleting ? 'Deleting…' : 'Delete feed'}
          </Button>
        </div>
      </div>
    </Dialog>
  )
}
