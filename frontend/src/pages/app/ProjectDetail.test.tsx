import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import ProjectDetail from './ProjectDetail';
import { useProjectStore } from '@/stores/projectStore';
import { useUploadStore } from '@/stores/uploadStore';
import { useTranslationJobStore, type TranslationJob } from '@/stores/translationJobStore';

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
    useTranslationJobStore.setState({
      job: null,
      progressPct: 0,
      isStarting: false,
      isPolling: false,
      error: null,
      start: vi.fn().mockResolvedValue(undefined),
      refresh: vi.fn().mockResolvedValue(undefined),
      startPolling: vi.fn(),
      stopPolling: vi.fn(),
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

  it('replaces the disabled button with a real download link once status is completed', () => {
    setProject({ status: 'completed' });
    renderAt('42');
    const link = screen.getByRole('link', { name: /Download CSV export/ });
    expect(link).toBeInTheDocument();
    expect(link.getAttribute('href') ?? '').toMatch(/\/api\/projects\/42\/export$/);
    expect(link).toHaveAttribute('download');
  });

  it('does not render the download link for any non-completed status', () => {
    for (const status of ['draft', 'mapping', 'review', 'translating', 'error'] as const) {
      setProject({ status });
      const view = renderAt('42');
      expect(screen.queryByRole('link', { name: /Download CSV export/ })).not.toBeInTheDocument();
      view.unmount();
    }
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

  // ─── Translation integration ────────────────────────────────────────────

  function fakeJob(overrides: Partial<TranslationJob> = {}): TranslationJob {
    return {
      id: 1,
      project_id: 42,
      team_id: 10,
      status: 'queued',
      total_products: 100,
      processed_products: 0,
      total_translations: 200,
      completed_translations: 0,
      cached_translations: 0,
      failed_translations: 0,
      estimated_cost: 0,
      actual_cost: 0,
      started_at: null,
      completed_at: null,
      error_message: null,
      ...overrides,
    };
  }

  it('shows the "Start translation" button when status is review and no job exists', () => {
    setProject({ status: 'review' });
    renderAt('42');
    const btn = screen.getByRole('button', { name: 'Start translation' });
    expect(btn).not.toBeDisabled();
  });

  it('disables "Start translation" when status is still draft or mapping', () => {
    setProject({ status: 'mapping' });
    renderAt('42');
    expect(screen.getByRole('button', { name: 'Start translation' })).toBeDisabled();
  });

  it('calls start(projectId) and then refetches the project on click', async () => {
    const user = userEvent.setup();
    const start = vi.fn().mockResolvedValue(undefined);
    const fetchProject = vi.fn().mockResolvedValue(undefined);
    useTranslationJobStore.setState({ start });
    useProjectStore.setState({ fetchProject });

    setProject({ id: 7, status: 'review' });
    renderAt('7');

    await user.click(screen.getByRole('button', { name: 'Start translation' }));

    await waitFor(() => expect(start).toHaveBeenCalledWith(7));
    await waitFor(() => expect(fetchProject).toHaveBeenCalledWith(7));
  });

  it('shows the live progress bar while translating, with cached/failed counters', () => {
    useTranslationJobStore.setState({
      job: fakeJob({
        status: 'processing',
        completed_translations: 120,
        total_translations: 200,
        cached_translations: 60,
        failed_translations: 3,
      }),
      progressPct: 60,
    });
    setProject({ status: 'translating' });
    renderAt('42');

    const card = screen.getByTestId('translation-progress');
    expect(card.querySelector('[role="progressbar"]')).toHaveAttribute('aria-valuenow', '60');
    expect(card).toHaveTextContent('120 / 200 translations');
    expect(card).toHaveTextContent(/50% cached/);
    expect(card).toHaveTextContent(/3 failed/);
  });

  it('starts the live poll when status flips to translating, and stops it on unmount', () => {
    const startPolling = vi.fn();
    const stopPolling = vi.fn();
    useTranslationJobStore.setState({ startPolling, stopPolling });

    setProject({ id: 9, status: 'translating' });
    const { unmount } = renderAt('9');

    expect(startPolling).toHaveBeenCalledWith(9);

    unmount();
    expect(stopPolling).toHaveBeenCalled();
  });

  it('does NOT poll when the project is just sitting at "review"', () => {
    const startPolling = vi.fn();
    useTranslationJobStore.setState({ startPolling });

    setProject({ status: 'review' });
    renderAt('42');

    expect(startPolling).not.toHaveBeenCalled();
  });

  it('shows the completed summary once status reaches completed', () => {
    useTranslationJobStore.setState({
      job: fakeJob({ status: 'completed', completed_translations: 200, cached_translations: 130 }),
      progressPct: 100,
    });
    setProject({ status: 'completed' });
    renderAt('42');

    expect(screen.getByText(/Translated 200 cells/)).toBeInTheDocument();
    expect(screen.getByText(/65% cached/)).toBeInTheDocument();
  });

  it('shows the job error_message and a "Try again" button when the job failed', () => {
    useTranslationJobStore.setState({
      job: fakeJob({ status: 'failed', error_message: 'Monthly translation cap reached.' }),
    });
    setProject({ status: 'review' });   // user can retry from review
    renderAt('42');

    expect(screen.getByRole('alert')).toHaveTextContent('Monthly translation cap reached.');
    expect(screen.getByRole('button', { name: 'Try again' })).not.toBeDisabled();
  });
});
