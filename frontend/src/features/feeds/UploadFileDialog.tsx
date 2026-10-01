import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '~/components/ui/button'
import { Dialog, DialogClose } from '~/components/ui/dialog'
import { Notice } from '~/components/ui/notice'
import type { Feed } from '~/lib/api/types'
import { FileField, UploadProgressBar } from './FileField'
import { feedQuery, feedsQuery } from './queries'
import { useFileUpload } from './useFileUpload'

/** A new file for a feed fed by uploads. Closing the dialog cancels the upload. */
export function UploadFileDialog({ feed, onClose }: { feed: Feed; onClose: () => void }) {
  const client = useQueryClient()
  const upload = useFileUpload()
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<string>()

  const send = async () => {
    if (file === null) return setError('Choose the file to upload.')
    setError(undefined)
    const outcome = await upload.start(feed.id, file)
    if ('error' in outcome) return setError(outcome.error)
    if ('import' in outcome) {
      await Promise.all([
        client.invalidateQueries({ queryKey: feedQuery(feed.id).queryKey }),
        client.invalidateQueries({ queryKey: feedsQuery.queryKey, exact: true }),
      ])
      onClose()
    }
  }

  return (
    <Dialog
      open
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
      title={`Upload a new file to ${feed.name}`}
      description="It is read as soon as it arrives. The same file again is recognised and not read twice."
    >
      <div className="flex flex-col gap-4">
        <FileField
          file={file}
          disabled={upload.uploading}
          error={file === null ? error : undefined}
          onChange={(chosen) => {
            setFile(chosen)
            setError(undefined)
          }}
        />
        {upload.progress !== null && <UploadProgressBar progress={upload.progress} />}
        {error !== undefined && file !== null && <Notice tone="error">{error}</Notice>}
        <div className="flex justify-end gap-2">
          <DialogClose asChild>
            <Button type="button" variant="secondary">
              {upload.uploading ? 'Cancel upload' : 'Cancel'}
            </Button>
          </DialogClose>
          <Button type="button" disabled={upload.uploading} onClick={() => void send()}>
            {upload.uploading ? 'Uploading…' : 'Upload'}
          </Button>
        </div>
      </div>
    </Dialog>
  )
}
