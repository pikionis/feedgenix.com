import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import EmailVerificationBanner from './EmailVerificationBanner';
import { useEmailVerificationStore } from '@/stores/emailVerificationStore';

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

describe('EmailVerificationBanner', () => {
  beforeEach(() => {
    setStore({});
  });

  it('renders nothing while verification status is unknown (avoids flash on guest pages)', () => {
    setStore({ verified: null });
    render(<EmailVerificationBanner />);
    expect(screen.queryByTestId('email-verification-banner')).not.toBeInTheDocument();
  });

  it('renders nothing when the user is already verified', () => {
    setStore({ verified: true, email: 'maria@example.com' });
    render(<EmailVerificationBanner />);
    expect(screen.queryByTestId('email-verification-banner')).not.toBeInTheDocument();
  });

  it('renders the warn-tone banner when the user is unverified', () => {
    setStore({ verified: false, email: 'maria@example.com' });
    render(<EmailVerificationBanner />);

    const banner = screen.getByTestId('email-verification-banner');
    expect(banner).toBeInTheDocument();
    expect(banner).toHaveTextContent('Verify your email');
    expect(banner).toHaveTextContent('maria@example.com');
  });

  it('falls back to "your inbox" when email is missing', () => {
    setStore({ verified: false, email: null });
    render(<EmailVerificationBanner />);
    expect(screen.getByTestId('email-verification-banner')).toHaveTextContent('your inbox');
  });

  it('calls fetchStatus on mount', () => {
    const fetchStatus = vi.fn().mockResolvedValue(undefined);
    setStore({ fetchStatus });
    render(<EmailVerificationBanner />);
    expect(fetchStatus).toHaveBeenCalled();
  });

  it('calls resend on click and disables the button while sending', async () => {
    const user = userEvent.setup();
    const resend = vi.fn().mockResolvedValue(undefined);
    setStore({ verified: false, email: 'x@x', resend });

    render(<EmailVerificationBanner />);
    const btn = screen.getByRole('button', { name: 'Resend email' });

    await user.click(btn);
    await waitFor(() => expect(resend).toHaveBeenCalledOnce());
  });

  it('reflects the recently-resent state when resentAt is < 10s old', () => {
    setStore({
      verified: false,
      email: 'x@x',
      resentAt: Date.now() - 2_000,   // 2s ago
    });
    render(<EmailVerificationBanner />);
    const btn = screen.getByRole('button');
    expect(btn).toHaveTextContent('Sent — check your inbox');
    expect(btn).toBeDisabled();
  });

  it('shows the throttle error inline when the store error is set', () => {
    setStore({
      verified: false,
      email: 'x@x',
      error: 'Slow down — wait a minute before requesting another email.',
    });
    render(<EmailVerificationBanner />);
    expect(screen.getByTestId('email-verification-banner')).toHaveTextContent(/Slow down/);
  });

  it('disables resend while a request is in flight', () => {
    setStore({ verified: false, email: 'x@x', isSending: true });
    render(<EmailVerificationBanner />);
    const btn = screen.getByRole('button');
    expect(btn).toBeDisabled();
    expect(btn).toHaveTextContent('Sending…');
  });
});
