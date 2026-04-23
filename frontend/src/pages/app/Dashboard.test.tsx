import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Dashboard from './Dashboard';
import { useAuthStore } from '@/stores/authStore';

function setUser(partial: Partial<ReturnType<typeof useAuthStore.getState>['user']>) {
  useAuthStore.setState({
    user: {
      id: 1,
      name: 'Maria Papadopoulos',
      email: 'maria@example.com',
      role: 'owner',
      ...partial,
    } as ReturnType<typeof useAuthStore.getState>['user'],
    isAuthenticated: true,
    isLoading: false,
  });
}

function renderDashboard() {
  return render(
    <MemoryRouter>
      <Dashboard />
    </MemoryRouter>
  );
}

describe('Dashboard', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: null,
      isAuthenticated: false,
      isLoading: false,
    });
  });

  it('greets the user by first name', () => {
    setUser({ name: 'Maria Papadopoulos' });
    renderDashboard();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Welcome back, Maria');
  });

  it('falls back to "there" when the user has no name yet', () => {
    setUser({ name: '' });
    renderDashboard();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Welcome back, there');
  });

  it('renders all four stat cards', () => {
    setUser({ name: 'M' });
    renderDashboard();
    for (const label of ['Products', 'Languages', 'Cache hit rate', 'Cost / 1K rows']) {
      expect(screen.getByText(label)).toBeInTheDocument();
    }
  });

  it('renders both header CTAs that link to the right routes', () => {
    setUser({ name: 'M' });
    renderDashboard();
    expect(screen.getByRole('link', { name: 'All feeds' })).toHaveAttribute('href', '/projects');
    expect(screen.getByRole('link', { name: '+ New feed' })).toHaveAttribute('href', '/projects/new');
  });

  it('shows the empty-state message when there are no feeds yet', () => {
    setUser({ name: 'M' });
    renderDashboard();
    expect(screen.getByText(/No feeds yet/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /upload/i })).toHaveAttribute('href', '/projects/new');
  });

  it('does not render the delta callout when no rows have changed', () => {
    setUser({ name: 'M' });
    renderDashboard();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });
});
