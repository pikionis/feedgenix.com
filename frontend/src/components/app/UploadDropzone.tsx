import { useCallback, useState } from 'react';
import { useDropzone, type FileRejection } from 'react-dropzone';

interface UploadDropzoneProps {
  /** Called once with a single accepted file. */
  onFile: (file: File) => void;
  /** Disable interactions (e.g. while a previous upload is mid-flight). */
  disabled?: boolean;
  /** External error shown verbatim under the dropzone (e.g. server response). */
  error?: string | null;
  /** Optional progress percentage (0..100). When provided, the dropzone shows the bar instead of the idle CTA. */
  progress?: number;
  /** Whether an upload is currently in flight. */
  uploading?: boolean;
  /** Override the 50MB default cap if a tier-specific limit is enforced upstream. */
  maxBytes?: number;
}

/**
 * Drag-drop CSV upload zone for the project pipeline. Accepts a single CSV/TSV
 * file under the size cap; rejects everything else with an inline message
 * before the API ever sees it.
 *
 * Visual states (stacked top-to-bottom):
 *   - idle:      "Drag a CSV here, or click to choose."
 *   - hovering:  Indigo dashed border, "Drop the file…"
 *   - rejected:  Local validation error in destructive tone.
 *   - uploading: Progress bar + percentage.
 *   - error:     External error from caller (server-side validation, network, …).
 */

const DEFAULT_MAX_BYTES = 50 * 1024 * 1024; // 50 MB

export default function UploadDropzone({
  onFile,
  disabled = false,
  error = null,
  progress,
  uploading = false,
  maxBytes = DEFAULT_MAX_BYTES,
}: UploadDropzoneProps) {
  const [localError, setLocalError] = useState<string | null>(null);

  const onDrop = useCallback(
    (accepted: File[], rejections: FileRejection[]) => {
      setLocalError(null);

      if (rejections.length > 0) {
        setLocalError(rejectionMessage(rejections[0], maxBytes));
        return;
      }
      if (accepted.length === 0) {
        return;
      }

      // Belt-and-braces validation: browsers (and jsdom) don't all honour the
      // file input's `accept` attribute, so a non-CSV can make it past
      // react-dropzone's filter. Re-validate by extension + MIME type and
      // size before handing off.
      const file = accepted[0];
      if (!isCsvLike(file)) {
        setLocalError('Only CSV or TSV files are accepted.');
        return;
      }
      if (file.size > maxBytes) {
        setLocalError(
          `That file is larger than ${Math.round(maxBytes / (1024 * 1024))} MB. Split it or upgrade your plan.`
        );
        return;
      }

      onFile(file);
    },
    [onFile, maxBytes]
  );

  // We do our own type/size validation in onDrop (browsers + jsdom can't
  // be trusted to honour the input's `accept` attribute consistently).
  // react-dropzone's only job here is dispatching the file to onDrop.
  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    multiple: false,
    disabled: disabled || uploading,
  });

  const showProgress = uploading && typeof progress === 'number';

  return (
    <div>
      <div
        {...getRootProps()}
        role="button"
        aria-label="Upload CSV file"
        aria-disabled={disabled || uploading}
        data-state={
          showProgress ? 'uploading' : isDragActive ? 'hover' : 'idle'
        }
        className={[
          'flex min-h-32 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg p-6 text-center transition-colors duration-200',
          isDragActive
            ? 'bg-indigo-100 text-[#3B3490]'
            : 'bg-paper text-ink-500 hover:bg-mist',
          disabled || uploading ? 'cursor-not-allowed opacity-80' : '',
        ].join(' ')}
        style={{ border: '1px dashed rgba(79, 70, 229, 0.4)' }}
      >
        <input {...getInputProps()} aria-label="CSV file" />

        {showProgress ? (
          <UploadingState progress={progress!} />
        ) : isDragActive ? (
          <p className="text-[14px] font-medium text-[#3B3490]">
            Drop the file to upload…
          </p>
        ) : (
          <IdleState maxBytes={maxBytes} />
        )}
      </div>

      {(localError || error) && (
        <p
          role="alert"
          className="mt-2 text-[12.5px] font-medium"
          style={{ color: '#C6392A' }}
        >
          {localError ?? error}
        </p>
      )}
    </div>
  );
}

function IdleState({ maxBytes }: { maxBytes: number }) {
  return (
    <>
      <UploadIcon />
      <p className="text-[14px] font-medium text-ink">
        Drag a CSV here, or click to choose
      </p>
      <p className="text-[12px] text-ink-400">
        CSV or TSV up to {Math.round(maxBytes / (1024 * 1024))} MB · any delimiter
      </p>
    </>
  );
}

function UploadingState({ progress }: { progress: number }) {
  const pct = Math.max(0, Math.min(100, Math.round(progress)));
  return (
    <div className="w-full max-w-72">
      <div className="mb-1.5 flex items-center justify-between text-[12px] font-medium text-ink">
        <span>Uploading…</span>
        <span>{pct}%</span>
      </div>
      <div
        className="h-1.5 overflow-hidden rounded-full bg-mist"
        role="progressbar"
        aria-valuenow={pct}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div
          className="h-full transition-[width] duration-200"
          style={{ width: `${pct}%`, background: '#4F46E5' }}
        />
      </div>
    </div>
  );
}

function UploadIcon() {
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M12 5v12" />
      <path d="m6 11 6-6 6 6" />
      <path d="M5 19h14" />
    </svg>
  );
}

function isCsvLike(file: File): boolean {
  const name = file.name.toLowerCase();
  if (name.endsWith('.csv') || name.endsWith('.tsv') || name.endsWith('.txt')) {
    return true;
  }
  // MIME types vary by OS — accept the obvious csv/tsv variants and the
  // ms-excel quirk Windows + Excel reports for .csv.
  const type = (file.type || '').toLowerCase();
  return (
    type === 'text/csv' ||
    type === 'text/plain' ||
    type === 'text/tab-separated-values' ||
    type === 'application/vnd.ms-excel'
  );
}

function rejectionMessage(rej: FileRejection, maxBytes: number): string {
  const code = rej.errors[0]?.code;
  switch (code) {
    case 'file-too-large':
      return `That file is larger than ${Math.round(maxBytes / (1024 * 1024))} MB. Split it or upgrade your plan.`;
    case 'file-invalid-type':
      return 'Only CSV or TSV files are accepted.';
    case 'too-many-files':
      return 'Upload one file at a time.';
    default:
      return rej.errors[0]?.message ?? 'That file was rejected.';
  }
}
