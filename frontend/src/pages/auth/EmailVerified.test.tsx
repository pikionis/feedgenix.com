import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import EmailVerified from './EmailVerified';
import { useAuthStore } from '@/stores/authStore';
import { useEmailVerificationStore } from '@/stores/emailVerificationStore';

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/email/verified']}>
      <Routes>
        <Route path="/email/verified" element={<EmailVerified />} />
        <Route path="/dashboard" element={<div>Dashboard page</div>} />
        <Route path="/login" element={<div>Login page</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('EmailVerified', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: null,
      isAuthenticated: false,
      isLoading: false,
      fetchUser: vi.fn().mockResolvedValue(undefined),
    });
    useEmailVerificationStore.setState({
      verified: null,
      email: null,
      isLoading: false,
      isSending: false,
      error: null,
      resentAt: null,
      fetchStatus: vi.fn().mockResolvedValue(undefined),
      resend: vi.fn().mockResolvedValue(undefined),
      markVerified: vi.fn(),
      reset: vi.fn(),
    });
  });

  afterEach(() => vi.useRealTimers());

  it('renders the success copy with the trial-started message', () => {
    renderPage();
    expect(screen.getByText('Email verified')).toBeInTheDocument();
    expect(screen.getByText(/7-day FeedGenix trial/)).toBeInTheDocument();
    expect(screen.getByText(/€0 charged/)).toBeInTheDocument();
  });

  it('calls markVerified + fetchUser on mount', () => {
    const markVerified = vi.fn();
    const fetchUser = vi.fn().mockResolvedValue(undefined);
    useEmailVerificationStore.setState({ markVerified });
    useAuthStore.setState({ fetchUser });

    renderPage();

    expect(markVerified).toHaveBeenCalledOnce();
    expect(fetchUser).toHaveBeenCalledOnce();
  });

  it('auto-redirects to /dashboard after the success delay when authenticated', async () => {
    useAuthStore.setState({
      user: { id: 1, name: 'M', email: 'm@x', role: 'owner' } as any,
      isAuthenticated: true,
      isLoading: false,
      fetchUser: vi.fn().mockResolvedValue(undefined),
    });

    renderPage();
    // findByText polls (default 1s) — avoids brittle fake-timer + microtask
    // interleaving in jsdom that intermittently misses the navigate update.
    await screen.findByText('Dashboard page', {}, { timeout: 4_000 });
  });

  it('redirects to /login when the user is not authenticated', async () => {
    useAuthStore.setState({ isAuthenticated: false });

    renderPage();
    await screen.findByText('Login page', {}, { timeout: 4_000 });
  });

  it('shows a "Go now" link as fallback', () => {
    useAuthStore.setState({
      user: { id: 1, name: 'M', email: 'm@x', role: 'owner' } as any,
      isAuthenticated: true,
      isLoading: false,
      fetchUser: vi.fn().mockResolvedValue(undefined),
    });
    renderPage();
    const link = screen.getByRole('link', { name: 'Go now' });
    expect(link).toHaveAttribute('href', '/dashboard');
  });
});
