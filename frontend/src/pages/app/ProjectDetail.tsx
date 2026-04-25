import { useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useProjectStore } from '@/stores/projectStore';
import { useUploadStore } from '@/stores/uploadStore';
import { useTranslationJobStore } from '@/stores/translationJobStore';
import PageHeader from '@/components/app/PageHeader';
import UploadDropzone from '@/components/app/UploadDropzone';

const PIPELINE = [
  { key: 'draft',       label: 'Upload' },
  { key: 'mapping',     label: 'AI mapping' },
  { key: 'review',      label: 'Review' },
  { key: 'translating', label: 'Translate' },
  { key: 'completed',   label: 'Export' },
] as const;

type StepKey = (typeof PIPELINE)[number]['key'];

export default function ProjectDetail() {
  const { id } = useParams<{ id: string }>();
  const { currentProject, isLoading, fetchProject } = useProjectStore();
  const { upload, isUploading, progress, error: uploadError, reset: resetUpload } = useUploadStore();
  const {
    job: translationJob,
    progressPct,
    isStarting: isStartingTranslation,
    error: translationError,
    start: startTranslation,
    refresh: refreshTranslation,
    startPolling,
    stopPolling,
    reset: resetTranslation,
  } = useTranslationJobStore();

  useEffect(() => {
    if (id) void fetchProject(Number(id));
  }, [id, fetchProject]);

  useEffect(() => {
    // Clear any stale per-project state when the route changes.
    resetUpload();
    resetTranslation();
  }, [id, resetUpload, resetTranslation]);

  // One-shot fetch of the latest translation job whenever the project loads,
  // so revisiting a feed mid-translation immediately shows current progress.
  useEffect(() => {
    if (!currentProject) return;
    void refreshTranslation(currentProject.id);
  }, [currentProject?.id, refreshTranslation]);

  // Live-poll while the project is actively translating.
  useEffect(() => {
    if (!currentProject) return;
    if (currentProject.status === 'translating') {
      startPolling(currentProject.id);
      return () => stopPolling();
    }
  }, [currentProject?.id, currentProject?.status, startPolling, stopPolling]);

  // When polling sees the job finish, refresh the project so the pipeline
  // ticks over to "completed" without the user reloading.
  useEffect(() => {
    if (translationJob?.status === 'completed' && currentProject?.status !== 'completed') {
      void fetchProject(Number(id));
    }
  }, [translationJob?.status, currentProject?.status, id, fetchProject]);

  const handleUpload = async (file: File) => {
    if (!currentProject) return;
    try {
      await upload(currentProject.id, file);
      // Refetch so the page transitions out of 'draft' once ProcessUpload
      // has run and updated the project status.
      await fetchProject(currentProject.id);
    } catch {
      // Error already surfaced via the store; no further action here.
    }
  };

  const handleStartTranslation = async () => {
    if (!currentProject) return;
    try {
      await startTranslation(currentProject.id);
      await fetchProject(currentProject.id);
    } catch {
      // surfaced via translationError
    }
  };

  if (isLoading || !currentProject) {
    return (
      <div className="mx-auto max-w-5xl text-[14px] text-ink-500">
        Loading feed…
      </div>
    );
  }

  const status = currentProject.status as StepKey | 'error';
  const currentStep = PIPELINE.findIndex((s) => s.key === status);
  const hasError = status === 'error';

  return (
    <div className="mx-auto max-w-5xl">
      <nav aria-label="Breadcrumb" className="mb-3 flex items-center gap-2 text-[12px] text-ink-500">
        <Link to="/projects" className="hover:text-ink">Feeds</Link>
        <span aria-hidden>/</span>
        <span className="text-ink">{currentProject.name}</span>
      </nav>

      <PageHeader
        title={currentProject.name}
        subtitle={
          <>
            {currentProject.products_count.toLocaleString('en-US')} products ·{' '}
            {currentProject.source_language ?? '—'} →{' '}
            {currentProject.target_languages.length > 0
              ? currentProject.target_languages.map((l) => l.toUpperCase()).join(' · ')
              : 'no target languages'}
          </>
        }
        actions={
          <Link
            to="/projects"
            className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2 text-[13px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
          >
            All feeds
          </Link>
        }
      />

      {hasError ? (
        <div
          role="alert"
          className="mb-6 rounded-lg border-[0.5px] border-[#C6392A]/30 bg-[#FCE5E1] px-4 py-3 text-[13px] text-[#C6392A]"
        >
          This feed is in an error state. Check the job log or re-upload the source CSV.
        </div>
      ) : (
        <Pipeline currentStep={currentStep} />
      )}

      <div className="mt-8 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <SectionCard
          title="Upload"
          body="Drop a CSV with your product catalog. We sniff the delimiter and sample the file automatically — no schema config required."
          cta={
            isDraftOrError(status) ? (
              <UploadDropzone
                onFile={handleUpload}
                uploading={isUploading}
                progress={progress}
                error={uploadError}
              />
            ) : (
              <p className="text-[12.5px] text-ink-500">
                Catalog already ingested. Re-upload coming soon.
              </p>
            )
          }
        />

        <SectionCard
          title="Field mapping"
          body="Review the AI mapping, confirm which columns get translated, and approve before running."
          cta={
            currentStep < 1 ? (
              <button
                type="button"
                disabled
                className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2 text-[13px] font-medium text-ink-400"
              >
                Review mapping
              </button>
            ) : (
              <Link
                to={`/projects/${currentProject.id}/mapping`}
                className="inline-block rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2 text-[13px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
              >
                Review mapping →
              </Link>
            )
          }
        />

        <SectionCard
          title="Translate"
          body="Queue a translation job. Paid providers only run when free tiers are exhausted."
          cta={
            <TranslateCta
              status={status}
              isStarting={isStartingTranslation}
              onStart={handleStartTranslation}
              progressPct={progressPct}
              job={translationJob}
              error={translationError}
            />
          }
        />

        <SectionCard
          title="Export"
          body="Download a clean CSV with translated fields merged in. Protected columns pass through untouched."
          cta={
            <button
              type="button"
              disabled={status !== 'completed'}
              className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2 text-[13px] font-medium text-ink transition-colors duration-200 hover:bg-mist disabled:text-ink-400 disabled:hover:bg-paper"
            >
              Download export
            </button>
          }
        />
      </div>

      <dl className="mt-8 grid grid-cols-1 gap-3 rounded-lg border-[0.5px] border-ink/10 bg-paper p-5 sm:grid-cols-4">
        <Info label="Source language" value={(currentProject.source_language ?? '—').toUpperCase()} />
        <Info label="Target languages" value={currentProject.target_languages.length === 0 ? '—' : currentProject.target_languages.map((l) => l.toUpperCase()).join(' · ')} />
        <Info label="Status" value={status} capitalize />
        <Info label="Created" value={new Date(currentProject.created_at).toLocaleDateString()} />
      </dl>
    </div>
  );
}

function isDraftOrError(status: string): boolean {
  return status === 'draft' || status === 'error';
}

function TranslateCta({
  status,
  isStarting,
  onStart,
  progressPct,
  job,
  error,
}: {
  status: string;
  isStarting: boolean;
  onStart: () => void;
  progressPct: number;
  job: import('@/stores/translationJobStore').TranslationJob | null;
  error: string | null;
}) {
  // Three visual states:
  //   1) review (or error retry): show a primary "Start translation" button.
  //   2) translating: show progress bar + counters.
  //   3) completed: show a green check + summary.
  if (status === 'translating' || job?.status === 'processing' || job?.status === 'queued') {
    const pct = Math.max(0, Math.min(100, Math.round(progressPct)));
    return (
      <div data-testid="translation-progress">
        <div className="mb-1.5 flex items-center justify-between text-[12px] font-medium text-ink">
          <span>{job?.status === 'queued' ? 'Queued — starting up…' : 'Translating…'}</span>
          <span>{pct}%</span>
        </div>
        <div
          className="h-1.5 overflow-hidden rounded-full bg-mist"
          role="progressbar"
          aria-valuenow={pct}
          aria-valuemin={0}
          aria-valuemax={100}
        >
          <div className="h-full transition-[width] duration-200" style={{ width: `${pct}%`, background: '#4F46E5' }} />
        </div>
        {job && job.total_translations > 0 && (
          <p className="mt-1.5 text-[11.5px] text-ink-500">
            {job.completed_translations.toLocaleString('en-US')} / {job.total_translations.toLocaleString('en-US')} translations
            {job.cached_translations > 0 && (
              <> · {Math.round((job.cached_translations / Math.max(1, job.completed_translations)) * 100)}% cached</>
            )}
            {job.failed_translations > 0 && (
              <> · <span style={{ color: '#C6392A' }}>{job.failed_translations} failed</span></>
            )}
          </p>
        )}
      </div>
    );
  }

  if (status === 'completed' || job?.status === 'completed') {
    return (
      <div className="flex items-center gap-2 text-[13px] text-[#0B7A63]">
        <span aria-hidden>✓</span>
        <span>
          Translated {job?.completed_translations.toLocaleString('en-US') ?? 'all'} cells
          {job && job.cached_translations > 0 && (
            <> · {Math.round((job.cached_translations / Math.max(1, job.completed_translations)) * 100)}% cached</>
          )}
        </span>
      </div>
    );
  }

  if (job?.status === 'failed' || error) {
    return (
      <div>
        <p role="alert" className="mb-2 text-[12.5px] font-medium" style={{ color: '#C6392A' }}>
          {job?.error_message ?? error ?? 'Translation failed.'}
        </p>
        <button
          type="button"
          onClick={onStart}
          disabled={status !== 'review' || isStarting}
          className="rounded-lg bg-indigo px-3.5 py-2 text-[13px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600 disabled:cursor-not-allowed disabled:bg-ink-300"
        >
          {isStarting ? 'Starting…' : 'Try again'}
        </button>
      </div>
    );
  }

  return (
    <button
      type="button"
      onClick={onStart}
      disabled={status !== 'review' || isStarting}
      className="rounded-lg bg-indigo px-3.5 py-2 text-[13px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600 disabled:cursor-not-allowed disabled:bg-ink-300"
    >
      {isStarting ? 'Starting…' : 'Start translation'}
    </button>
  );
}

function Pipeline({ currentStep }: { currentStep: number }) {
  return (
    <ol
      aria-label="Feed pipeline"
      className="mb-2 flex flex-wrap items-center gap-2 rounded-lg border-[0.5px] border-ink/10 bg-paper px-3 py-2.5"
    >
      {PIPELINE.map((step, i) => {
        const state: 'done' | 'current' | 'pending' =
          i < currentStep ? 'done' : i === currentStep ? 'current' : 'pending';
        return (
          <li key={step.key} className="flex items-center gap-2">
            <span
              className={[
                'rounded-full px-3 py-1 text-[12px] font-medium',
                state === 'current'
                  ? 'bg-indigo text-white'
                  : state === 'done'
                    ? 'bg-indigo-100 text-[#3B3490]'
                    : 'bg-mist text-ink-500',
              ].join(' ')}
            >
              {step.label}
            </span>
            {i < PIPELINE.length - 1 && (
              <span
                aria-hidden
                className="h-0.5 w-6"
                style={{ background: i < currentStep ? '#4F46E5' : 'rgba(31,29,46,0.10)' }}
              />
            )}
          </li>
        );
      })}
    </ol>
  );
}

function SectionCard({ title, body, cta }: { title: string; body: string; cta: React.ReactNode }) {
  return (
    <section className="rounded-lg border-[0.5px] border-ink/10 bg-paper p-5">
      <h2 className="mb-1.5 text-[17px] font-medium tracking-[-0.01em] text-ink">{title}</h2>
      <p className="mb-3.5 text-[13.5px] leading-[1.55] text-ink-500">{body}</p>
      {cta}
    </section>
  );
}

function Info({ label, value, capitalize }: { label: string; value: string; capitalize?: boolean }) {
  return (
    <div>
      <dt className="text-[11px] font-medium tracking-[0.04em] text-ink-400 uppercase">{label}</dt>
      <dd className={`mt-1 text-[13px] font-medium text-ink ${capitalize ? 'capitalize' : ''}`}>
        {value}
      </dd>
    </div>
  );
}
