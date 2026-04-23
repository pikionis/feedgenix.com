import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import ProjectDetail from './ProjectDetail';
import { useProjectStore } from '@/stores/projectStore';

type Project = NonNullable<ReturnType<typeof useProjectStore.getState>['currentProject']>;

function setProject(partial: Partial<Project>, isLoading = false) {
  useProjectStore.setState({
    currentProject: {
      id: 42,
      name: 'Autumn/winter 2026',
      description: null,
      source_language: 'en',
      target_languages: ['de', 'fr', 'it'],
      status: 'review',
      products_count: 12_480,
      created_at: '2026-01-01',
      ...partial,
    },
    isLoading,
  });
}

function renderAt(id: string) {
  return render(
    <MemoryRouter initialEntries={[`/projects/${id}`]}>
      <Routes>
        <Route path="/projects/:id" element={<ProjectDetail />} />
      </Routes>
    </MemoryRouter>
  );
}

describe('ProjectDetail', () => {
  beforeEach(() => {
    useProjectStore.setState({
      currentProject: null,
      isLoading: false,
      fetchProject: vi.fn().mockResolvedValue(undefined),
    });
  });

  it('calls fetchProject on mount with the url id', async () => {
    const fetchProject = vi.fn().mockResolvedValue(undefined);
    useProjectStore.setState({ fetchProject });

    renderAt('42');

    await waitFor(() => expect(fetchProject).toHaveBeenCalledWith(42));
  });

  it('shows the loading placeholder while the project is being fetched', () => {
    useProjectStore.setState({ isLoading: true, currentProject: null });
    renderAt('42');
    expect(screen.getByText('Loading feed…')).toBeInTheDocument();
  });

  it('renders the project title + formatted stats subtitle', () => {
    setProject({});
    renderAt('42');
    expect(screen.getByRole('heading', { level: 1, name: 'Autumn/winter 2026' })).toBeInTheDocument();
    // Target langs appear in both the header subtitle and the info <dl>.
    // The subtitle is inside a <p>, the dl entry is inside a <dd>; scope by <p>.
    expect(screen.getByText(/12,480 products/)).toBeInTheDocument();
    expect(screen.getAllByText(/DE · FR · IT/).length).toBeGreaterThanOrEqual(1);
  });

  it('marks the correct pipeline step as current based on status', () => {
    setProject({ status: 'review' });
    renderAt('42');
    const pipeline = screen.getByRole('list', { name: 'Feed pipeline' });
    const current = pipeline.querySelector('.bg-indigo.text-white') as HTMLElement;
    expect(current?.textContent).toBe('Review');
  });

  it('disables the "Start translation" button when status is draft', () => {
    setProject({ status: 'draft' });
    renderAt('42');
    expect(screen.getByRole('button', { name: /Start translation/ })).toBeDisabled();
  });

  it('enables "Start translation" once status reaches review', () => {
    setProject({ status: 'review' });
    renderAt('42');
    expect(screen.getByRole('button', { name: /Start translation/ })).not.toBeDisabled();
  });

  it('disables the download button while translating', () => {
    setProject({ status: 'translating' });
    renderAt('42');
    expect(screen.getByRole('button', { name: /Download export/ })).toBeDisabled();
  });

  it('enables the download button once status is completed', () => {
    setProject({ status: 'completed' });
    renderAt('42');
    expect(screen.getByRole('button', { name: /Download export/ })).not.toBeDisabled();
  });


  it('shows an error banner (and no pipeline) when status is "error"', () => {
    setProject({ status: 'error' });
    renderAt('42');
    expect(screen.getByRole('alert')).toHaveTextContent('error state');
    expect(screen.queryByRole('list', { name: 'Feed pipeline' })).not.toBeInTheDocument();
  });

  it('displays "—" for missing source/target languages', () => {
    setProject({ source_language: null, target_languages: [] });
    renderAt('42');
    // Both the dl and the subtitle should gracefully render "—".
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
  });
});
