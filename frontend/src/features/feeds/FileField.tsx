import { Field } from '~/components/ui/field'
import { formatBytes } from './labels'
import type { UploadProgress } from './upload'

/** Choosing the feed file. Any name works: the format is recognised from the content. */
export function FileField({
  file,
  onChange,
  error,
  disabled,
}: {
  file: File | null
  onChange: (file: File | null) => void
  error?: string | undefined
  disabled?: boolean
}) {
  return (
    <Field
      label="File"
      hint={
        file !== null
          ? `${file.name} · ${formatBytes(file.size)}`
          : 'CSV, XML, JSON or Excel (.xlsx), also inside gzip or zip.'
      }
      error={error}
    >
      {(control) => (
        <input
          type="file"
          disabled={disabled}
          className="text-sm file:mr-3 file:h-9 file:rounded-md file:border-[0.5px] file:border-hairline file:bg-background file:px-4 file:text-sm file:font-medium file:text-foreground hover:file:bg-surface"
          onChange={(event) => onChange(event.target.files?.[0] ?? null)}
          {...control}
        />
      )}
    </Field>
  )
}

export function UploadProgressBar({ progress }: { progress: UploadProgress }) {
  const percent = progress.total === 0 ? 100 : Math.floor((progress.sent / progress.total) * 100)

  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex justify-between text-sm text-muted-foreground">
        <span>{percent < 100 ? 'Uploading…' : 'Checking the file…'}</span>
        <span className="font-mono text-xs">
          {formatBytes(progress.sent)} / {formatBytes(progress.total)}
        </span>
      </div>
      <progress
        aria-label="Upload progress"
        value={percent}
        max={100}
        className="h-1 w-full appearance-none overflow-hidden rounded-full bg-surface [&::-moz-progress-bar]:bg-primary [&::-webkit-progress-bar]:bg-surface [&::-webkit-progress-value]:bg-primary"
      />
    </div>
  )
}
