import { useEffect, useState, useMemo } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useProjectStore } from '@/stores/projectStore';
import { useMappingStore, type FieldMapping } from '@/stores/mappingStore';
import PageHeader from '@/components/app/PageHeader';

type Bucket = 'translatable' | 'protected' | 'skipped';

const BUCKET_LABEL: Record<Bucket, string> = {
  translatable: 'Translatable',
  protected: 'Protected (Golden Rule)',
  skipped: 'Skipped (codes / numbers)',
};

const BUCKET_HINT: Record<Bucket, string> = {
  translatable: 'Sent to the translator. Pay only for unique strings.',
  protected: 'Pass through untouched — prices, stock, barcodes, SKUs, weights, dimensions.',
  skipped: 'Not translated. Codes, sizes, enums, pure numbers.',
};

/**
 * Field mapping review page. After ProcessUpload + RunFieldMapping have run,
 * the project sits at status='review' with a draft FieldMapping. The user
 * confirms which columns go in each bucket and approves — at which point the
 * project is ready for translation.
 *
 * Buckets are mutually exclusive: moving a column to one bucket removes it
 * from the others. Ambiguous columns are flagged inline with a warn pill.
 */
export default function MappingReview() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const projectId = Number(id);

  const project = useProjectStore((s) => s.currentProject);
  const fetchProject = useProjectStore((s) => s.fetchProject);
  const { mapping, isLoading, isSaving, error, fetch, save, approve, reset } = useMappingStore();

  // Local working copy of the buckets so the user can preview changes before
  // hitting Save. We seed from the server mapping the first time it loads.
  const [working, setWorking] = useState<Pick<FieldMapping, 'translatable_fields' | 'protected_fields' | 'skipped_fields'> | null>(null);

  useEffect(() => {
    if (Number.isFinite(projectId)) {
      void fetchProject(projectId);
      void fetch(projectId);
    }
    return () => reset();
  }, [projectId, fetchProject, fetch, reset]);

  useEffect(() => {
    if (mapping && !working) {
      setWorking({
        translatable_fields: [...mapping.translatable_fields],
        protected_fields: [...mapping.protected_fields],
        skipped_fields: [...mapping.skipped_fields],
      });
    }
  }, [mapping, working]);

  const dirty = useMemo(() => {
    if (!mapping || !working) return false;
    return (
      !sameSet(mapping.translatable_fields, working.translatable_fields) ||
      !sameSet(mapping.protected_fields, working.protected_fields) ||
      !sameSet(mapping.skipped_fields, working.skipped_fields)
    );
  }, [mapping, working]);

  if (isLoading || !mapping || !working) {
    return (
      <div className="mx-auto max-w-5xl text-[14px] text-ink-500">
        {error ? (
          <ErrorState message={error} projectId={projectId} />
        ) : (
          'Loading mapping…'
        )}
      </div>
    );
  }

  const ambiguous = new Set(mapping.ambiguous_fields);

  function moveTo(col: string, bucket: Bucket) {
    setWorking((w) => {
      if (!w) return w;
      return {
        translatable_fields: bucket === 'translatable' ? unique([...w.translatable_fields, col]) : w.translatable_fields.filter((c) => c !== col),
        protected_fields: bucket === 'protected' ? unique([...w.protected_fields, col]) : w.protected_fields.filter((c) => c !== col),
        skipped_fields: bucket === 'skipped' ? unique([...w.skipped_fields, col]) : w.skipped_fields.filter((c) => c !== col),
      };
    });
  }

  async function handleSave() {
    if (!working) return;
    await save(projectId, working);
  }

  async function handleApprove() {
    // Save any unsaved bucket changes first so the approval persists them too.
    if (dirty) {
      await save(projectId, working);
    }
    await approve(projectId);
    await fetchProject(projectId);
    navigate(`/projects/${projectId}`);
  }

  return (
    <div className="mx-auto max-w-5xl">
      <nav aria-label="Breadcrumb" className="mb-3 flex items-center gap-2 text-[12px] text-ink-500">
        <Link to="/projects" className="hover:text-ink">Feeds</Link>
        <span aria-hidden>/</span>
        <Link to={`/projects/${projectId}`} className="hover:text-ink">{project?.name ?? 'Feed'}</Link>
        <span aria-hidden>/</span>
        <span className="text-ink">Review mapping</span>
      </nav>

      <PageHeader
        title="Review field mapping"
        subtitle={
          <>
            {Object.keys(mapping.mapping).length} columns ·{' '}
            {mapping.detected_source_language
              ? <>source language detected as <strong className="font-medium text-ink">{mapping.detected_source_language.toUpperCase()}</strong></>
              : 'source language not detected'}
            {mapping.ai_confidence !== null && (
              <> · AI confidence {Math.round(mapping.ai_confidence * 100)}%</>
            )}
          </>
        }
        actions={
          <>
            <button
              type="button"
              onClick={handleSave}
              disabled={!dirty || isSaving}
              className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2 text-[13px] font-medium text-ink transition-colors duration-200 hover:bg-mist disabled:cursor-not-allowed disabled:text-ink-400"
            >
              {isSaving ? 'Saving…' : 'Save changes'}
            </button>
            <button
              type="button"
              onClick={handleApprove}
              disabled={isSaving || working.translatable_fields.length === 0}
              className="rounded-lg bg-indigo px-3.5 py-2 text-[13px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600 disabled:cursor-not-allowed disabled:bg-ink-300"
            >
              Approve and translate →
            </button>
          </>
        }
      />

      {error && (
        <div role="alert" className="mb-4 rounded-lg border-[0.5px] border-[#C6392A]/30 bg-[#FCE5E1] px-3 py-2.5 text-[13px] text-[#C6392A]">
          {error}
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {(['translatable', 'protected', 'skipped'] as const).map((bucket) => (
          <BucketCard
            key={bucket}
            bucket={bucket}
            columns={working[`${bucket}_fields`]}
            mapping={mapping.mapping}
            ambiguous={ambiguous}
            onMove={moveTo}
            currentlyIn={(col) => currentBucket(col, working)}
          />
        ))}
      </div>

      {working.translatable_fields.length === 0 && (
        <p className="mt-4 text-[13px] text-ink-500">
          Pick at least one translatable column before approving.
        </p>
      )}
    </div>
  );
}

function BucketCard({
  bucket,
  columns,
  mapping,
  ambiguous,
  onMove,
  currentlyIn,
}: {
  bucket: Bucket;
  columns: string[];
  mapping: FieldMapping['mapping'];
  ambiguous: Set<string>;
  onMove: (col: string, bucket: Bucket) => void;
  currentlyIn: (col: string) => Bucket | null;
}) {
  return (
    <section
      className="rounded-lg border-[0.5px] border-ink/10 bg-paper p-4"
      aria-label={`${BUCKET_LABEL[bucket]} columns`}
    >
      <header className="mb-3">
        <h3 className="text-[14px] font-medium text-ink">
          {BUCKET_LABEL[bucket]}
          <span className="ml-2 rounded-full bg-mist px-2 py-0.5 text-[11px] font-medium text-ink-500">
            {columns.length}
          </span>
        </h3>
        <p className="mt-0.5 text-[12px] leading-[1.45] text-ink-500">{BUCKET_HINT[bucket]}</p>
      </header>

      {columns.length === 0 ? (
        <p className="text-[12.5px] text-ink-400">No columns in this bucket.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {columns.map((col) => (
            <ColumnRow
              key={col}
              col={col}
              info={mapping[col]}
              ambiguous={ambiguous.has(col)}
              activeBucket={currentlyIn(col) ?? bucket}
              onMove={onMove}
            />
          ))}
        </ul>
      )}
    </section>
  );
}

function ColumnRow({
  col,
  info,
  ambiguous,
  activeBucket,
  onMove,
}: {
  col: string;
  info: FieldMapping['mapping'][string] | undefined;
  ambiguous: boolean;
  activeBucket: Bucket;
  onMove: (col: string, bucket: Bucket) => void;
}) {
  return (
    <li className="rounded-md border-[0.5px] border-ink/8 bg-paper p-2.5">
      <div className="flex items-center justify-between gap-2">
        <code className="font-mono text-[12.5px] text-ink">{col}</code>
        {ambiguous && (
          <span
            className="rounded-full px-2 py-0.5 text-[10.5px] font-medium"
            style={{ background: '#FAEEDA', color: '#A35412' }}
            aria-label={`${col} is ambiguous`}
          >
            ambiguous
          </span>
        )}
      </div>
      {info && (
        <div className="mt-1 text-[11.5px] text-ink-400">
          {info.kind} · {Math.round(info.confidence * 100)}% confidence
        </div>
      )}
      <div className="mt-2 flex flex-wrap gap-1.5" role="radiogroup" aria-label={`Move ${col} to bucket`}>
        {(['translatable', 'protected', 'skipped'] as const).map((b) => (
          <button
            key={b}
            type="button"
            role="radio"
            aria-checked={activeBucket === b}
            onClick={() => activeBucket !== b && onMove(col, b)}
            className={[
              'rounded-full px-2.5 py-0.75 text-[11px] font-medium transition-colors duration-200',
              activeBucket === b
                ? 'bg-indigo text-white'
                : 'border-[0.5px] border-ink/14 bg-paper text-ink-500 hover:bg-mist',
            ].join(' ')}
          >
            {bucketShort(b)}
          </button>
        ))}
      </div>
    </li>
  );
}

function ErrorState({ message, projectId }: { message: string; projectId: number }) {
  return (
    <div className="rounded-lg border-[0.5px] border-ink/10 bg-paper p-8 text-center">
      <p className="mb-3 text-[14px] text-ink">{message}</p>
      <Link
        to={`/projects/${projectId}`}
        className="text-[13px] font-medium text-indigo hover:text-indigo-600"
      >
        ← Back to feed
      </Link>
    </div>
  );
}

function bucketShort(b: Bucket): string {
  return b === 'translatable' ? 'Translate' : b === 'protected' ? 'Protect' : 'Skip';
}

function currentBucket(
  col: string,
  w: { translatable_fields: string[]; protected_fields: string[]; skipped_fields: string[] },
): Bucket | null {
  if (w.translatable_fields.includes(col)) return 'translatable';
  if (w.protected_fields.includes(col))    return 'protected';
  if (w.skipped_fields.includes(col))      return 'skipped';
  return null;
}

function unique(arr: string[]): string[] {
  return Array.from(new Set(arr));
}

function sameSet(a: string[], b: string[]): boolean {
  if (a.length !== b.length) return false;
  const set = new Set(a);
  return b.every((x) => set.has(x));
}
