import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import ProjectDetail from './ProjectDetail';
import { useProjectStore } from '@/stores/projectStore';
import { useUploadStore } from '@/stores/uploadStore';

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
    useUploadStore.setState({
      isUploading: false,
      progress: 0,
      error: null,
      upload: vi.fn().mockResolvedValue(undefined),
      reset: vi.fn(),
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

  // ─── Upload integration ─────────────────────────────────────────────────

  it('shows the upload dropzone in the Upload section when status is draft', () => {
    setProject({ status: 'draft' });
    renderAt('42');
    expect(screen.getByRole('button', { name: 'Upload CSV file' })).toBeInTheDocument();
    expect(screen.queryByText('Catalog already ingested. Re-upload coming soon.')).not.toBeInTheDocument();
  });

  it('shows the upload dropzone again when status is "error" (so the user can retry)', () => {
    setProject({ status: 'error' });
    renderAt('42');
    expect(screen.getByRole('button', { name: 'Upload CSV file' })).toBeInTheDocument();
  });

  it('replaces the dropzone with a "ingested" hint once the catalog is past upload', () => {
    setProject({ status: 'review' });
    renderAt('42');
    expect(screen.queryByRole('button', { name: 'Upload CSV file' })).not.toBeInTheDocument();
    expect(screen.getByText(/already ingested/i)).toBeInTheDocument();
  });

  it('calls upload(projectId, file) and refetches the project on success', async () => {
    const user = userEvent.setup();
    const upload = vi.fn().mockResolvedValue(undefined);
    const fetchProject = vi.fn().mockResolvedValue(undefined);
    useUploadStore.setState({ upload });
    useProjectStore.setState({ fetchProject });

    setProject({ id: 7, status: 'draft' });
    renderAt('7');

    const csv = new File(['sku,title\nA,B'], 'p.csv', { type: 'text/csv' });
    await user.upload(screen.getByLabelText('CSV file'), csv);

    await waitFor(() => expect(upload).toHaveBeenCalledWith(7, csv));
    // Refetch is awaited after upload — this proves the page recovers state
    // automatically once ProcessUpload promotes the project to 'mapping'.
    await waitFor(() => expect(fetchProject).toHaveBeenCalledWith(7));
  });

  it('shows the dropzone progress bar when an upload is mid-flight', () => {
    useUploadStore.setState({ isUploading: true, progress: 73 });
    setProject({ status: 'draft' });
    renderAt('42');

    const bar = screen.getByRole('progressbar');
    expect(bar).toHaveAttribute('aria-valuenow', '73');
  });

  it('surfaces an upload error from the store inline under the dropzone', () => {
    useUploadStore.setState({ error: 'The file must be a CSV.' });
    setProject({ status: 'draft' });
    renderAt('42');

    const alerts = screen.getAllByRole('alert');
    expect(alerts.some((a) => a.textContent?.includes('The file must be a CSV.'))).toBe(true);
  });

  it('clears stale upload state when the project id changes', () => {
    const reset = vi.fn();
    useUploadStore.setState({ reset });
    setProject({ id: 1, status: 'draft' });

    renderAt('1');

    expect(reset).toHaveBeenCalled();
  });
});
