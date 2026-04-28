import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import EmailVerifyFailed from './EmailVerifyFailed';
import { useEmailVerificationStore } from '@/stores/emailVerificationStore';

function renderAt(query: string) {
  return render(
    <MemoryRouter initialEntries={[`/email/verify-failed${query}`]}>
      <Routes>
        <Route path="/email/verify-failed" element={<EmailVerifyFailed />} />
        <Route path="/login" element={<div>Login page</div>} />
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

describe('EmailVerifyFailed', () => {
  beforeEach(() => setStore({}));

  it('renders the expired copy by default (no reason in URL)', () => {
    renderAt('');
    expect(screen.getByText('Verification link expired')).toBeInTheDocument();
    expect(screen.getByText(/60 minutes/)).toBeInTheDocument();
  });

  it('renders the invalid-hash copy when reason=invalid-hash', () => {
    renderAt('?reason=invalid-hash');
    expect(screen.getByText('Verification link is invalid')).toBeInTheDocument();
  });

  it('renders the unknown-user copy when reason=unknown-user', () => {
    renderAt('?reason=unknown-user');
    expect(screen.getByText('Account not found')).toBeInTheDocument();
  });

  it('falls back to expired copy for unknown reason values', () => {
    renderAt('?reason=mystery');
    expect(screen.getByText('Verification link expired')).toBeInTheDocument();
  });

  it('triggers resend on button click', async () => {
    const user = userEvent.setup();
    const resend = vi.fn().mockResolvedValue(undefined);
    setStore({ resend });

    renderAt('');
    await user.click(screen.getByRole('button', { name: /Send a new verification link/ }));

    await waitFor(() => expect(resend).toHaveBeenCalledOnce());
  });

  it('swallows errors from resend so the UI does not throw on auth-required call', async () => {
    const user = userEvent.setup();
    const resend = vi.fn().mockRejectedValue(new Error('boom'));
    setStore({ resend });

    renderAt('');
    await user.click(screen.getByRole('button', { name: /Send a new verification link/ }));

    await waitFor(() => expect(resend).toHaveBeenCalled());
    // No unhandled exception, page still rendered.
    expect(screen.getByText('Verification link expired')).toBeInTheDocument();
  });

  it('renders the resend cooldown copy when resentAt < 10s ago', () => {
    setStore({ resentAt: Date.now() - 1_000 });
    renderAt('');

    const btn = screen.getByRole('button');
    expect(btn).toHaveTextContent('Sent — check your inbox');
    expect(btn).toBeDisabled();
  });

  it('shows store error inline (e.g. throttle 429)', () => {
    setStore({ error: 'Slow down — wait a minute before requesting another email.' });
    renderAt('');
    expect(screen.getByRole('alert')).toHaveTextContent(/Slow down/);
  });

  it('links to /login as fallback when user is not signed in', () => {
    renderAt('');
    expect(screen.getByRole('link', { name: 'Sign in first' })).toHaveAttribute('href', '/login');
  });
});
