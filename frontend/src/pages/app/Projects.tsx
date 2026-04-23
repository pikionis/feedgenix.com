import { useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useProjectStore } from '@/stores/projectStore';
import PageHeader from '@/components/app/PageHeader';
import FeedsTable, { type FeedRow, type FeedStatus } from '@/components/app/FeedsTable';

/** Legal FeedStatus values — anything the API returns outside this set falls back to 'draft'. */
const FEED_STATUSES: FeedStatus[] = ['draft', 'mapping', 'review', 'translating', 'completed', 'error'];

export default function Projects() {
  const { projects, isLoading, fetchProjects } = useProjectStore();

  useEffect(() => {
    fetchProjects();
  }, [fetchProjects]);

  const rows: FeedRow[] = projects.map((p) => ({
    id: p.id,
    name: p.name,
    filename: p.description ?? undefined,
    rowCount: p.products_count,
    languages: p.target_languages ?? [],
    status: FEED_STATUSES.includes(p.status as FeedStatus) ? (p.status as FeedStatus) : 'draft',
  }));

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title="Feeds"
        subtitle={summarize(projects.length, isLoading)}
        actions={
          <>
            <Link
              to="/projects"
              className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2 text-[13px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
            >
              Import
            </Link>
            <Link
              to="/projects/new"
              className="rounded-lg bg-indigo px-3.5 py-2 text-[13px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600"
            >
              + New feed
            </Link>
          </>
        }
      />

      {isLoading && projects.length === 0 ? (
        <div className="rounded-lg border-[0.5px] border-ink/10 bg-paper p-8 text-center text-[14px] text-ink-500">
          Loading feeds…
        </div>
      ) : (
        <FeedsTable rows={rows} />
      )}
    </div>
  );
}

function summarize(count: number, loading: boolean): string {
  if (loading && count === 0) return 'Fetching your feeds…';
  if (count === 0) return 'No feeds yet.';
  return `${count} feed${count === 1 ? '' : 's'} · tap a row to open it`;
}
