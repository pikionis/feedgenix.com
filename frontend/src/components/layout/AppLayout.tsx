import { Outlet, Link, useLocation } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';

const navItems = [
  { path: '/dashboard', label: 'Dashboard', icon: 'D' },
  { path: '/projects', label: 'Projects', icon: 'P' },
  { path: '/settings', label: 'Settings', icon: 'S' },
];

export default function AppLayout() {
  const location = useLocation();
  const { user, logout } = useAuthStore();

  return (
    <div className="flex h-screen bg-background">
      {/* Sidebar */}
      <aside className="w-64 border-r border-border flex flex-col">
        <div className="p-6 border-b border-border">
          <Link to="/dashboard" className="text-xl font-bold text-primary">
            FeedGenix
          </Link>
        </div>
        <nav className="flex-1 p-4 space-y-1">
          {navItems.map((item) => (
            <Link
              key={item.path}
              to={item.path}
              className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors ${
                location.pathname === item.path
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
              }`}
            >
              <span className="w-5 h-5 rounded bg-muted flex items-center justify-center text-xs font-medium">
                {item.icon}
              </span>
              {item.label}
            </Link>
          ))}
        </nav>
        <div className="p-4 border-t border-border">
          <div className="text-sm text-muted-foreground mb-2">{user?.name}</div>
          <button
            onClick={() => logout()}
            className="text-sm text-destructive hover:underline"
          >
            Sign out
          </button>
        </div>
      </aside>
      {/* Main content */}
      <main className="flex-1 overflow-auto p-8">
        <Outlet />
      </main>
    </div>
  );
}
