import { useAuthStore } from '@/stores/authStore';

const stats = [
  { label: 'Projects', value: '--', description: 'Active projects' },
  { label: 'Products', value: '--', description: 'Total products processed' },
  { label: 'Translations', value: '--', description: 'Completed this month' },
  { label: 'Usage', value: '--%', description: 'Of monthly limit used' },
];

export default function Dashboard() {
  const user = useAuthStore((s) => s.user);

  return (
    <div>
      <h1 className="text-2xl font-bold mb-1">Welcome back, {user?.name}</h1>
      <p className="text-muted-foreground mb-8">
        Here's an overview of your feed intelligence activity.
      </p>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        {stats.map((stat) => (
          <div key={stat.label} className="rounded-xl border border-border p-5">
            <div className="text-sm text-muted-foreground mb-1">{stat.label}</div>
            <div className="text-2xl font-bold mb-1">{stat.value}</div>
            <div className="text-xs text-muted-foreground">{stat.description}</div>
          </div>
        ))}
      </div>

      <div className="rounded-xl border border-border p-6">
        <h2 className="text-lg font-semibold mb-4">Recent Activity</h2>
        <p className="text-sm text-muted-foreground">
          No recent activity yet. Create a project to get started.
        </p>
      </div>
    </div>
  );
}
