import { Link } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import PageHeader from '@/components/app/PageHeader';
import StatCard from '@/components/app/StatCard';
import FeedsTable, { type FeedRow } from '@/components/app/FeedsTable';
import DeltaCallout from '@/components/app/DeltaCallout';

/**
 * Authenticated landing — mirrors `ui_kits/app/index.html`:
 *   PageHeader ▸ 4 stat cards ▸ recent feeds table ▸ delta callout.
 *
 * Data is stubbed to zero for now (the API endpoints exist but don't return
 * dashboard-aggregated stats yet). Once `/api/dashboard` returns summaries,
 * swap the `stats` const for a hook call — the component shape stays the same.
 */
export default function Dashboard() {
  const user = useAuthStore((s) => s.user);
  const firstName = (user?.name ?? '').split(' ')[0] || 'there';

  // Stubs until the API aggregates this server-side. Keeping the same shape
  // so the hook drop-in doesn't change any layout below.
  const stats = {
    products: 0,
    languages: 0,
    cacheHitRate: 0,
    costPer1kRows: 0,
  };
  const feeds: FeedRow[] = [];
  const changedRows = 0;

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title={`Welcome back, ${firstName}`}
        subtitle="Here's what's been happening in your workspace."
        actions={
          <>
            <Link
              to="/projects"
              className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2 text-[13px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
            >
              All feeds
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

      <section
        aria-label="Workspace stats"
        className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
      >
        <StatCard label="Products" value={stats.products.toLocaleString('en-US')} />
        <StatCard
          label="Languages"
          value={stats.languages}
          delta={
            stats.languages > 0
              ? { text: 'Active targets', tone: 'neutral' }
              : undefined
          }
        />
        <StatCard
          label="Cache hit rate"
          value={`${stats.cacheHitRate}%`}
        />
        <StatCard
          label="Cost / 1K rows"
          value={`€${stats.costPer1kRows.toFixed(2)}`}
        />
      </section>

      <FeedsTable
        rows={feeds}
        empty={
          <>
            No feeds yet. Drop a CSV to see your first translation job in action —{' '}
            <Link to="/projects/new" className="font-medium text-indigo hover:text-indigo-600">
              upload →
            </Link>
          </>
        }
      />

      {changedRows > 0 && (
        <DeltaCallout
          message={
            <>
              <strong className="font-medium text-ink">
                {changedRows.toLocaleString('en-US')} rows changed
              </strong>{' '}
              since last sync — ready to re-translate the delta only.
            </>
          }
          action={
            <Link
              to="/projects"
              className="rounded-lg bg-indigo px-3 py-1.5 text-[12px] font-medium text-white hover:bg-indigo-600"
            >
              Run delta job
            </Link>
          }
        />
      )}
    </div>
  );
}
