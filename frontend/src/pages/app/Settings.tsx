import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import PageHeader from '@/components/app/PageHeader';

export default function Settings() {
  const user = useAuthStore((s) => s.user);
  const [showDangerConfirm, setShowDangerConfirm] = useState(false);

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        title="Settings"
        subtitle="Your account, your team, and the things that are hard to undo."
      />

      <Section title="Account">
        <Row label="Name"  value={user?.name  || '—'} />
        <Row label="Email" value={user?.email || '—'} />
        <Row label="Role"  value={user?.role  || '—'} capitalize />
      </Section>

      <Section title="Team">
        {user?.team ? (
          <>
            <Row label="Team name" value={user.team.name} />
            <Row label="Workspace slug" value={user.team.slug} mono />
            <Row label="Plan" value={user.team.plan} capitalize />
          </>
        ) : (
          <p className="text-[13px] text-ink-500">
            No team is associated with this account yet.
          </p>
        )}
      </Section>

      <section
        className="mt-6 rounded-lg p-5"
        style={{ border: '0.5px solid rgba(198, 57, 42, 0.30)', background: '#FCE5E1' }}
      >
        <h2 className="mb-1.5 text-[17px] font-medium tracking-[-0.01em]" style={{ color: '#8B1F16' }}>
          Danger zone
        </h2>
        <p className="mb-3.5 text-[13.5px] leading-[1.55]" style={{ color: '#8B1F16' }}>
          Permanently delete your account and everything in it — feeds, jobs, translations,
          API keys. There is no undo.
        </p>

        {!showDangerConfirm ? (
          <button
            type="button"
            onClick={() => setShowDangerConfirm(true)}
            className="rounded-lg px-3.5 py-2 text-[13px] font-medium text-white transition-opacity duration-200 hover:opacity-90"
            style={{ background: '#C6392A' }}
          >
            Delete account…
          </button>
        ) : (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-[13px] font-medium" style={{ color: '#8B1F16' }}>
              Really delete? This cannot be undone.
            </span>
            <button
              type="button"
              // Actual deletion wiring happens when the /api/account endpoint exists.
              disabled
              className="rounded-lg px-3.5 py-2 text-[13px] font-medium text-white"
              style={{ background: '#C6392A', opacity: 0.8, cursor: 'not-allowed' }}
            >
              Yes, delete
            </button>
            <button
              type="button"
              onClick={() => setShowDangerConfirm(false)}
              className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2 text-[13px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
            >
              Cancel
            </button>
          </div>
        )}
      </section>
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="mb-4 rounded-lg border-[0.5px] border-ink/10 bg-paper p-5">
      <h2 className="mb-3.5 text-[17px] font-medium tracking-[-0.01em] text-ink">{title}</h2>
      <dl className="space-y-2.5">{children}</dl>
    </section>
  );
}

function Row({
  label,
  value,
  capitalize,
  mono,
}: {
  label: string;
  value: string;
  capitalize?: boolean;
  mono?: boolean;
}) {
  return (
    <div className="grid grid-cols-[160px_1fr] items-baseline gap-3 text-[13.5px]">
      <dt className="text-ink-500">{label}</dt>
      <dd
        className={[
          'font-medium text-ink',
          capitalize ? 'capitalize' : '',
          mono ? 'font-mono text-[13px]' : '',
        ].filter(Boolean).join(' ')}
      >
        {value}
      </dd>
    </div>
  );
}
