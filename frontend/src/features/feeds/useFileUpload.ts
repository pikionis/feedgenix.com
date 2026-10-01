import { useEffect, useRef, useState } from 'react'
import type { ImportSummary } from '~/lib/api/types'
import { uploadFile, UploadFailed, type UploadProgress } from './upload'

/** `overPlan`: the API answered 402 and the upgrade dialog is open; the caller steps aside. */
export type UploadOutcome = { import: ImportSummary } | { error: string; overPlan: boolean } | { cancelled: true }

/** Runs one upload at a time, with its progress. Unmounting (closing the dialog) cancels it. */
export function useFileUpload() {
  const [progress, setProgress] = useState<UploadProgress | null>(null)
  const controller = useRef<AbortController | null>(null)

  useEffect(() => () => controller.current?.abort(), [])

  const start = async (feedId: number, file: File): Promise<UploadOutcome> => {
    if (file.size === 0) return { error: 'This file is empty.', overPlan: false }

    const current = new AbortController()
    controller.current = current
    setProgress({ sent: 0, total: file.size })
    try {
      return { import: await uploadFile(feedId, file, setProgress, current.signal) }
    } catch (error) {
      if (current.signal.aborted) return { cancelled: true }

      return error instanceof UploadFailed
        ? { error: error.message, overPlan: error.status === 402 }
        : { error: 'The upload stopped. Try again.', overPlan: false }
    } finally {
      controller.current = null
      if (!current.signal.aborted) setProgress(null)
    }
  }

  return { progress, uploading: progress !== null, start }
}
