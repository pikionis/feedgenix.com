import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useEffect } from 'react';
import { useAuthStore } from '@/stores/authStore';

// Public pages
import Landing from '@/pages/public/Landing';
import Pricing from '@/pages/public/Pricing';

// Auth pages
import Login from '@/pages/auth/Login';
import Register from '@/pages/auth/Register';

// App pages
import Dashboard from '@/pages/app/Dashboard';
import Projects from '@/pages/app/Projects';
import ProjectDetail from '@/pages/app/ProjectDetail';
import NewProject from '@/pages/app/NewProject';
import Settings from '@/pages/app/Settings';

// Layout
import AppLayout from '@/components/layout/AppLayout';

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, isLoading } = useAuthStore();
  if (isLoading) return <div className="flex items-center justify-center h-screen">Loading...</div>;
  if (!isAuthenticated) return <Navigate to="/login" />;
  return <>{children}</>;
}

function GuestRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, isLoading } = useAuthStore();
  if (isLoading) return <div className="flex items-center justify-center h-screen">Loading...</div>;
  if (isAuthenticated) return <Navigate to="/dashboard" />;
  return <>{children}</>;
}

export default function App() {
  const fetchUser = useAuthStore((s) => s.fetchUser);

  useEffect(() => {
    fetchUser();
  }, [fetchUser]);

  return (
    <BrowserRouter>
      <Routes>
        {/* Public */}
        <Route path="/" element={<Landing />} />
        <Route path="/pricing" element={<Pricing />} />

        {/* Auth */}
        <Route path="/login" element={<GuestRoute><Login /></GuestRoute>} />
        <Route path="/register" element={<GuestRoute><Register /></GuestRoute>} />

        {/* App (protected) */}
        <Route path="/" element={<ProtectedRoute><AppLayout /></ProtectedRoute>}>
          <Route path="dashboard" element={<Dashboard />} />
          <Route path="projects" element={<Projects />} />
          <Route path="projects/new" element={<NewProject />} />
          <Route path="projects/:id" element={<ProjectDetail />} />
          <Route path="settings" element={<Settings />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
