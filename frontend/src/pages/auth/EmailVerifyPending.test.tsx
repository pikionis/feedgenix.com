import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import EmailVerifyPending from './EmailVerifyPending';
import { useEmailVerificationStore } from '@/stores/emailVerificationStore';
import { useAuthStore } from '@/stores/authStore';

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/email/pending']}>
      <Routes>
        <Route path="/email/pending" element={<EmailVerifyPending />} />
        <Route path="/dashboard" element={<div>Dashboard page</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

function setStore(partial: Partial<ReturnType<typeof useEmailVerificationStore.getState>>) {
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
    ...partial,
  });
}

describe('EmailVerifyPending', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: { id: 1, name: 'Maria', email: 'maria@example.com', role: 'owner' } as any,
      isAuthenticated: true,
      isLoading: false,
    });
    setStore({});
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('renders the inbox check-in copy with the user email', () => {
    setStore({ email: 'maria@example.com' });
    renderPage();
    expect(screen.getByText(/Check your inbox/)).toBeInTheDocument();
    expect(screen.getByText('maria@example.com')).toBeInTheDocument();
    expect(screen.getByText(/7-day trial/)).toBeInTheDocument();
  });

  it('falls back to the auth user email when store email is empty', () => {
    setStore({ email: null });
    renderPage();
    expect(screen.getByText('maria@example.com')).toBeInTheDocument();
  });

  it('calls fetchStatus on mount and polls every 4 seconds', async () => {
    vi.useFakeTimers();
    const fetchStatus = vi.fn().mockResolvedValue(undefined);
    setStore({ fetchStatus });

    renderPage();
    expect(fetchStatus).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(4_000);
    expect(fetchStatus).toHaveBeenCalledTimes(2);

    await vi.advanceTimersByTimeAsync(4_000);
    expect(fetchStatus).toHaveBeenCalledTimes(3);
  });

  it('redirects to /dashboard once verified flips to true', async () => {
    setStore({ verified: true, email: 'x@x' });
    renderPage();
    expect(await screen.findByText('Dashboard page')).toBeInTheDocument();
  });

  it('triggers resend on button click', async () => {
    const user = userEvent.setup();
    const resend = vi.fn().mockResolvedValue(undefined);
    setStore({ email: 'x@x', resend });

    renderPage();
    await user.click(screen.getByRole('button', { name: /Resend verification/ }));

    await waitFor(() => expect(resend).toHaveBeenCalledOnce());
  });

  it('disables resend with success copy for 10s after a successful resend', () => {
    setStore({ email: 'x@x', resentAt: Date.now() - 1_000 });
    renderPage();

    const btn = screen.getByRole('button');
    expect(btn).toHaveTextContent('Sent — check your inbox');
    expect(btn).toBeDisabled();
  });

  it('shows server-side error inline (e.g. throttle 429)', () => {
    setStore({
      email: 'x@x',
      error: 'Slow down — wait a minute before requesting another email.',
    });
    renderPage();
    expect(screen.getByRole('alert')).toHaveTextContent(/Slow down/);
  });
});
