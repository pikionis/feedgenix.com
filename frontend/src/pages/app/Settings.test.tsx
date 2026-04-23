import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Settings from './Settings';
import { useAuthStore } from '@/stores/authStore';

type User = ReturnType<typeof useAuthStore.getState>['user'];

function setUser(partial: Partial<NonNullable<User>> | null) {
  useAuthStore.setState({
    user: partial === null
      ? null
      : ({
          id: 1,
          name: 'Maria Papadopoulos',
          email: 'maria@example.com',
          role: 'owner',
          team: { id: 10, name: 'Deindeal AG', slug: 'deindeal', plan: 'pro' },
          ...partial,
        } as NonNullable<User>),
    isAuthenticated: partial !== null,
    isLoading: false,
  });
}

describe('Settings', () => {
  beforeEach(() => {
    setUser({});
  });

  it('renders account + team details from the auth store', () => {
    render(<Settings />);
    expect(screen.getByText('Maria Papadopoulos')).toBeInTheDocument();
    expect(screen.getByText('maria@example.com')).toBeInTheDocument();
    expect(screen.getByText('Deindeal AG')).toBeInTheDocument();
    expect(screen.getByText('deindeal')).toBeInTheDocument();
  });

  it('falls back to em-dash when account fields are missing', () => {
    setUser({ name: '', email: '', role: '', team: undefined });
    render(<Settings />);
    // Three em-dash placeholders for name, email, role.
    expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(3);
  });

  it('shows an empty state for the team section when the user has no team', () => {
    setUser({ team: undefined });
    render(<Settings />);
    expect(
      screen.getByText(/No team is associated/)
    ).toBeInTheDocument();
  });

  it('reveals the confirm row after clicking "Delete account…"', async () => {
    const user = userEvent.setup();
    render(<Settings />);

    await user.click(screen.getByRole('button', { name: /Delete account/ }));

    expect(screen.getByText(/Really delete/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Yes, delete' })).toBeDisabled();
  });

  it('can back out of the delete confirmation', async () => {
    const user = userEvent.setup();
    render(<Settings />);

    await user.click(screen.getByRole('button', { name: /Delete account/ }));
    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(screen.queryByText(/Really delete/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Delete account/ })).toBeInTheDocument();
  });
});
