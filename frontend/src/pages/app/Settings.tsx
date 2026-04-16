import { useAuthStore } from '@/stores/authStore';

export default function Settings() {
  const user = useAuthStore((s) => s.user);

  return (
    <div className="max-w-2xl">
      <h1 className="text-2xl font-bold mb-6">Settings</h1>

      {/* Account */}
      <div className="rounded-xl border border-border p-6 mb-6">
        <h2 className="text-lg font-semibold mb-4">Account</h2>
        <dl className="space-y-4">
          <div>
            <dt className="text-sm text-muted-foreground">Name</dt>
            <dd className="text-sm font-medium">{user?.name}</dd>
          </div>
          <div>
            <dt className="text-sm text-muted-foreground">Email</dt>
            <dd className="text-sm font-medium">{user?.email}</dd>
          </div>
          <div>
            <dt className="text-sm text-muted-foreground">Role</dt>
            <dd className="text-sm font-medium capitalize">{user?.role}</dd>
          </div>
        </dl>
      </div>

      {/* Team */}
      <div className="rounded-xl border border-border p-6 mb-6">
        <h2 className="text-lg font-semibold mb-4">Team</h2>
        {user?.team ? (
          <dl className="space-y-4">
            <div>
              <dt className="text-sm text-muted-foreground">Team Name</dt>
              <dd className="text-sm font-medium">{user.team.name}</dd>
            </div>
            <div>
              <dt className="text-sm text-muted-foreground">Plan</dt>
              <dd className="text-sm font-medium capitalize">{user.team.plan}</dd>
            </div>
          </dl>
        ) : (
          <p className="text-sm text-muted-foreground">No team associated with this account.</p>
        )}
      </div>

      {/* Danger zone */}
      <div className="rounded-xl border border-destructive/30 p-6">
        <h2 className="text-lg font-semibold text-destructive mb-2">Danger Zone</h2>
        <p className="text-sm text-muted-foreground mb-4">
          Permanently delete your account and all associated data.
        </p>
        <button className="text-sm text-destructive hover:underline">
          Delete Account
        </button>
      </div>
    </div>
  );
}
