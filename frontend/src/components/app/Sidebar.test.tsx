import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import Sidebar from './Sidebar';

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Sidebar
        workspaceName="Deindeal AG"
        plan="Pro plan"
        rowsUsed={63_214}
        rowsLimit={100_000}
      />
    </MemoryRouter>
  );
}

describe('Sidebar', () => {
  it('renders workspace card, all nav items, and the quota widget', () => {
    renderAt('/dashboard');

    expect(screen.getByText('Deindeal AG')).toBeInTheDocument();
    expect(screen.getByText('Pro plan')).toBeInTheDocument();

    for (const label of ['Dashboard', 'Feeds', 'Jobs', 'Usage', 'Settings']) {
      expect(screen.getByRole('link', { name: new RegExp(label) })).toBeInTheDocument();
    }

    expect(screen.getByRole('progressbar')).toBeInTheDocument();
    expect(screen.getByText('63,214 / 100,000')).toBeInTheDocument();
  });

  it('marks the Dashboard link active on /dashboard', () => {
    renderAt('/dashboard');
    const dash = screen.getByRole('link', { name: /Dashboard/ });
    expect(dash.className).toMatch(/bg-paper/);
  });

  it('marks the Feeds link active on /projects', () => {
    renderAt('/projects');
    const feeds = screen.getByRole('link', { name: /Feeds/ });
    expect(feeds.className).toMatch(/bg-paper/);
  });

  it('does not keep Dashboard highlighted when navigating to another page (end prop)', () => {
    renderAt('/projects');
    const dash = screen.getByRole('link', { name: /Dashboard/ });
    expect(dash.className).not.toMatch(/bg-paper/);
  });

  it('renders the Sign out button when onSignOut is provided and calls it on click', async () => {
    const user = userEvent.setup();
    const onSignOut = vi.fn();

    render(
      <MemoryRouter>
        <Sidebar
          workspaceName="Acme"
          plan="Free"
          rowsUsed={0}
          rowsLimit={500}
          onSignOut={onSignOut}
        />
      </MemoryRouter>
    );

    const btn = screen.getByRole('button', { name: 'Sign out' });
    await user.click(btn);
    expect(onSignOut).toHaveBeenCalledOnce();
  });

  it('omits the Sign out button when onSignOut is not provided', () => {
    renderAt('/dashboard');
    expect(screen.queryByRole('button', { name: 'Sign out' })).not.toBeInTheDocument();
  });
});
