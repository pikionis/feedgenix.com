import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';

export type FeedStatus = 'draft' | 'mapping' | 'review' | 'translating' | 'completed' | 'error';

export interface FeedRow {
  id: number;
  name: string;
  filename?: string;
  rowCount: number;
  languages: string[];    // ISO 639-1 codes — 'de', 'fr', 'it', 'el', …
  status: FeedStatus;
  /** 0..1 — only drawn in "translating" status. */
  progress?: number;
}

interface FeedsTableProps {
  rows: FeedRow[];
  /** Shown when rows is empty — lets callers tailor the message to the context. */
  empty?: ReactNode;
}

/** 2-letter language pill with a distinct tint per language. */
const LANG_TINTS: Record<string, { bg: string; fg: string }> = {
  de: { bg: '#E7E4FA', fg: '#3B3490' },
  fr: { bg: '#DCEAF9', fg: '#1F4E87' },
  it: { bg: '#DFF1E8', fg: '#0B7A63' },
  el: { bg: '#FAE9DC', fg: '#A35412' },
  en: { bg: '#EDEBE3', fg: '#2E2B42' },
};

const STATUS_TEXT: Record<FeedStatus, string> = {
  draft: 'Draft',
  mapping: 'Mapping',
  review: 'Review',
  translating: 'Translating',
  completed: 'Synced',     // design-system copy: "Synced" reads better in a table than "completed"
  error: 'Error',
};

const STATUS_TINTS: Record<FeedStatus, { bg: string; fg: string }> = {
  draft:       { bg: '#EDEBE3', fg: '#56536A' },
  mapping:     { bg: '#EDEBE3', fg: '#56536A' },
  review:      { bg: '#E0E7FF', fg: '#3B3490' },
  translating: { bg: '#FAEEDA', fg: '#A35412' },
  completed:   { bg: '#D6F1E8', fg: '#0B7A63' },
  error:       { bg: '#FCE5E1', fg: '#C6392A' },
};

export default function FeedsTable({ rows, empty }: FeedsTableProps) {
  if (rows.length === 0) {
    return (
      <div className="rounded-lg border-[0.5px] border-ink/10 bg-paper p-8 text-center">
        <div className="text-[14px] text-ink-500">
          {empty ?? (
            <>
              No feeds yet.{' '}
              <Link to="/projects/new" className="font-medium text-indigo hover:text-indigo-600">
                Upload your first CSV →
              </Link>
            </>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border-[0.5px] border-ink/10 bg-paper">
      <div
        className="grid items-center gap-3.5 bg-[#FAFAF7] px-5 py-2.5 text-[10.5px] font-medium uppercase tracking-[0.05em] text-ink-400"
        style={{ gridTemplateColumns: '40px 1.4fr 0.9fr 1fr 0.8fr' }}
    >
        <span />
        <span>Feed</span>
        <span>Rows</span>
        <span>Languages</span>
        <span>Status</span>
      </div>
      {rows.map((row) => (
        <Row key={row.id} row={row} />
      ))}
    </div>
  );
}

function Row({ row }: { row: FeedRow }) {
  return (
    <Link
      to={`/projects/${row.id}`}
      className="grid items-center gap-3.5 border-t-[0.5px] border-ink/10 px-5 py-3.5 text-[13.5px] transition-colors duration-200 hover:bg-[#FAFAF7]"
      style={{ gridTemplateColumns: '40px 1.4fr 0.9fr 1fr 0.8fr' }}
>
      <FeedAvatar name={row.name} />
      <div className="min-w-0">
        <div className="truncate font-medium text-ink">{row.name}</div>
        {row.filename && (
          <div className="mt-0.5 truncate font-mono text-[11px] text-ink-400">{row.filename}</div>
        )}
      </div>
      <div className="font-mono text-ink-700">{row.rowCount.toLocaleString('en-US')}</div>
      <div className="flex flex-wrap gap-1">
        {row.languages.map((lang) => (
          <LangPill key={lang} code={lang} />
        ))}
      </div>
      <div className="flex items-center gap-2">
        <StatusBadge status={row.status} />
        {row.status === 'translating' && typeof row.progress === 'number' && (
          <div className="h-1 w-14 overflow-hidden rounded-full" style={{ background: 'rgba(195,122,27,0.2)' }}>
            <div
              className="h-full"
              style={{
                width: `${Math.max(0, Math.min(1, row.progress)) * 100}%`,
                background: '#C87A1B',
              }}
            />
          </div>
        )}
      </div>
    </Link>
  );
}

function FeedAvatar({ name }: { name: string }) {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const letters = (parts[0]?.[0] ?? '?') + (parts[1]?.[0] ?? parts[0]?.[1] ?? '');
  return (
    <span className="inline-flex h-8 w-8 items-center justify-center rounded-md bg-mist text-[12px] font-medium text-ink-700">
      {letters.slice(0, 2).toUpperCase()}
    </span>
  );
}

function LangPill({ code }: { code: string }) {
  const tint = LANG_TINTS[code.toLowerCase()] ?? { bg: '#EDEBE3', fg: '#2E2B42' };
  return (
    <span
      className="rounded-full px-2 py-0.25 text-[10.5px] font-medium uppercase"
      style={{ background: tint.bg, color: tint.fg }}
    >
      {code.toUpperCase()}
    </span>
  );
}

function StatusBadge({ status }: { status: FeedStatus }) {
  const tint = STATUS_TINTS[status];
  return (
    <span
      className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-medium"
      style={{ background: tint.bg, color: tint.fg }}
    >
      <span className="h-1 w-1 rounded-full" style={{ background: 'currentColor' }} />
      {STATUS_TEXT[status]}
    </span>
  );
}
