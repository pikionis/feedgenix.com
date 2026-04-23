import { Outlet } from 'react-router-dom';
import Sidebar from '@/components/app/Sidebar';
import { useAuthStore } from '@/stores/authStore';

/**
 * Authenticated app shell. 180px Mist sidebar on the left, Paper main on the
 * right. The sidebar holds navigation + quota; the main area is scroll-free
 * (individual pages handle their own overflow) to keep the sidebar fixed.
 */
export default function AppLayout() {
  const { user, logout } = useAuthStore();

  // Graceful defaults for cases where the user object hasn't fully arrived yet
  // (e.g. very first render after login). Shows generic copy rather than crashing.
  const workspaceName = user?.team?.name ?? 'Your workspace';
  const plan = formatPlan(user?.team?.plan);
  const { used, limit } = usageFor(user?.team?.plan);

  return (
    <div className="flex h-screen bg-paper">
      <Sidebar
        workspaceName={workspaceName}
        plan={plan}
        rowsUsed={used}
        rowsLimit={limit}
        onSignOut={() => void logout()}
      />
      <main className="flex-1 overflow-auto p-8">
        <Outlet />
      </main>
    </div>
  );
}

function formatPlan(plan?: string): string {
  if (!plan) return 'Free plan';
  const map: Record<string, string> = {
    free: 'Free plan',
    starter: 'Starter plan',
    pro: 'Pro plan',
    enterprise: 'Enterprise plan',
  };
  return map[plan] ?? `${plan[0].toUpperCase()}${plan.slice(1)} plan`;
}

/**
 * Derives the quota widget's used/limit for the current plan. Real numbers
 * will later come from a /api/usage endpoint; until that's wired, fall back
 * to plan-defined tier limits so the sidebar still reads sensibly.
 */
function usageFor(plan?: string): { used: number; limit: number } {
  const limits: Record<string, number> = {
    free: 500,
    starter: 10_000,
    pro: 100_000,
    enterprise: Number.POSITIVE_INFINITY,
  };
  const limit = limits[plan ?? 'free'] ?? 500;
  return { used: 0, limit };
}
