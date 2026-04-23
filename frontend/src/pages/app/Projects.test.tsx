import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Projects from './Projects';
import { useProjectStore } from '@/stores/projectStore';

function seed(projects: Parameters<typeof useProjectStore.setState>[0] extends infer T ? T extends { projects: infer P } ? P : never : never): void {
  useProjectStore.setState({
    projects: projects as any,
    isLoading: false,
  });
}

function setLoading(loading: boolean) {
  useProjectStore.setState({ isLoading: loading });
}

function renderProjects() {
  return render(
    <MemoryRouter>
      <Projects />
    </MemoryRouter>
  );
}

describe('Projects page', () => {
  beforeEach(() => {
    // Reset + stub fetchProjects so it doesn't hit the network in jsdom.
    useProjectStore.setState({
      projects: [],
      isLoading: false,
      fetchProjects: vi.fn().mockResolvedValue(undefined),
    });
  });

  it('calls fetchProjects on mount', async () => {
    const fetchProjects = vi.fn().mockResolvedValue(undefined);
    useProjectStore.setState({ fetchProjects });

    renderProjects();

    await waitFor(() => expect(fetchProjects).toHaveBeenCalled());
  });

  it('shows the loading placeholder when isLoading is true and no projects yet', () => {
    setLoading(true);
    renderProjects();
    expect(screen.getByText('Loading feeds…')).toBeInTheDocument();
  });

  it('renders the empty state via FeedsTable when the list is empty and not loading', () => {
    renderProjects();
    // The phrase appears twice (subtitle + empty-state body), so the upload
    // link is what uniquely identifies the FeedsTable's empty block.
    expect(screen.getByRole('link', { name: /Upload your first CSV/ })).toBeInTheDocument();
  });

  it('renders projects as feed rows, including languages and name', () => {
    seed([
      {
        id: 42,
        name: 'Deindeal winter catalog',
        description: 'deindeal-2026-01.csv',
        source_language: 'en',
        target_languages: ['de', 'fr', 'it'],
        status: 'completed',
        products_count: 12_847,
        created_at: '2026-01-01',
      },
    ]);

    renderProjects();

    expect(screen.getByText('Deindeal winter catalog')).toBeInTheDocument();
    expect(screen.getByText('deindeal-2026-01.csv')).toBeInTheDocument();
    expect(screen.getByText('12,847')).toBeInTheDocument();
    for (const code of ['DE', 'FR', 'IT']) {
      expect(screen.getByText(code)).toBeInTheDocument();
    }
    expect(screen.getByText('Synced')).toBeInTheDocument();
  });

  it('maps unknown status strings back to "draft" so the UI never explodes', () => {
    seed([
      {
        id: 1,
        name: 'Weirdly statused feed',
        description: null,
        source_language: null,
        target_languages: [],
        status: 'total_nonsense',
        products_count: 0,
        created_at: '2026-01-01',
      },
    ]);

    renderProjects();

    // "Draft" is the draft status label — our fallback.
    expect(screen.getByText('Draft')).toBeInTheDocument();
  });

  it('renders both header CTAs', () => {
    renderProjects();
    expect(screen.getByRole('link', { name: 'Import' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: '+ New feed' })).toHaveAttribute('href', '/projects/new');
  });
});
