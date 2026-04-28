import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useEffect } from 'react';
import { useAuthStore } from '@/stores/authStore';

// Public pages
import Landing from '@/pages/public/Landing';
import Pricing from '@/pages/public/Pricing';

// Auth pages
import Login from '@/pages/auth/Login';
import Register from '@/pages/auth/Register';
import EmailVerifyPending from '@/pages/auth/EmailVerifyPending';
import EmailVerified from '@/pages/auth/EmailVerified';
import EmailVerifyFailed from '@/pages/auth/EmailVerifyFailed';

// App pages
import Dashboard from '@/pages/app/Dashboard';
import Projects from '@/pages/app/Projects';
import ProjectDetail from '@/pages/app/ProjectDetail';
import MappingReview from '@/pages/app/MappingReview';
import NewProject from '@/pages/app/NewProject';
import Settings from '@/pages/app/Settings';
import Billing from '@/pages/app/Billing';

// Layouts
import AppLayout from '@/components/layout/AppLayout';
import MarketingLayout from '@/components/layout/MarketingLayout';

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, isLoading } = useAuthStore();
  if (isLoading) return <div className="flex h-screen items-center justify-center text-muted-foreground">Loading…</div>;
  if (!isAuthenticated) return <Navigate to="/login" />;
  return <>{children}</>;
}

function GuestRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, isLoading } = useAuthStore();
  if (isLoading) return <div className="flex h-screen items-center justify-center text-muted-foreground">Loading…</div>;
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
        {/* Public marketing site */}
        <Route element={<MarketingLayout />}>
          <Route path="/" element={<Landing />} />
          <Route path="/pricing" element={<Pricing />} />
        </Route>

        {/* Auth (standalone, centered layout) */}
        <Route path="/login" element={<GuestRoute><Login /></GuestRoute>} />
        <Route path="/register" element={<GuestRoute><Register /></GuestRoute>} />

        {/* Email verification — pending requires auth, the result pages don't
            (the user might land on /email/verified from a different browser). */}
        <Route path="/email/pending" element={<ProtectedRoute><EmailVerifyPending /></ProtectedRoute>} />
        <Route path="/email/verified" element={<EmailVerified />} />
        <Route path="/email/verify-failed" element={<EmailVerifyFailed />} />

        {/* App (protected, sidebar layout) */}
        <Route element={<ProtectedRoute><AppLayout /></ProtectedRoute>}>
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/projects" element={<Projects />} />
          <Route path="/projects/new" element={<NewProject />} />
          <Route path="/projects/:id" element={<ProjectDetail />} />
          <Route path="/projects/:id/mapping" element={<MappingReview />} />
          <Route path="/settings" element={<Settings />} />
          <Route path="/billing" element={<Billing />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
