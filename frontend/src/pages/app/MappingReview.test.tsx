import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import MappingReview from './MappingReview';
import { useMappingStore, type FieldMapping } from '@/stores/mappingStore';
import { useProjectStore } from '@/stores/projectStore';

function fakeMapping(overrides: Partial<FieldMapping> = {}): FieldMapping {
  return {
    id: 1,
    project_id: 42,
    upload_id: 7,
    mapping: {
      title: { kind: 'translatable', confidence: 0.92, source_column: 'title' },
      description: { kind: 'translatable', confidence: 0.61, source_column: 'description' },
      price: { kind: 'protected', confidence: 1, source_column: 'price' },
      sku: { kind: 'protected', confidence: 1, source_column: 'sku' },
      size: { kind: 'code', confidence: 0.88, source_column: 'size' },
    },
    translatable_fields: ['title', 'description'],
    protected_fields: ['price', 'sku'],
    skipped_fields: ['size'],
    ambiguous_fields: ['description'],
    detected_source_language: 'en',
    ai_confidence: 0.92,
    ai_model: 'claude-opus-4-7',
    user_approved: false,
    approved_at: null,
    ...overrides,
  };
}

function renderAt(id: string) {
  return render(
    <MemoryRouter initialEntries={[`/projects/${id}/mapping`]}>
      <Routes>
        <Route path="/projects/:id/mapping" element={<MappingReview />} />
        <Route path="/projects/:id" element={<div>Feed detail</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

function setMapping(m: FieldMapping | null) {
  // Preserve any pre-existing error — tests that pre-set an error and then
  // load a mapping rely on the banner showing alongside the buckets.
  useMappingStore.setState({ mapping: m, isLoading: false });
}

describe('MappingReview', () => {
  beforeEach(() => {
    useProjectStore.setState({
      currentProject: {
        id: 42,
        name: 'Autumn/winter 2026',
        description: null,
        source_language: 'en',
        target_languages: ['de', 'fr'],
        status: 'review',
        products_count: 100,
        created_at: '2026-01-01',
      },
      isLoading: false,
      fetchProject: vi.fn().mockResolvedValue(undefined),
    });
    useMappingStore.setState({
      mapping: null,
      isLoading: false,
      isSaving: false,
      error: null,
      fetch: vi.fn().mockResolvedValue(undefined),
      save: vi.fn().mockResolvedValue(undefined),
      approve: vi.fn().mockResolvedValue(undefined),
      reset: vi.fn(),
    });
  });

  it('shows the loading placeholder before the mapping has loaded', () => {
    useMappingStore.setState({ isLoading: true, mapping: null });
    renderAt('42');
    expect(screen.getByText('Loading mapping…')).toBeInTheDocument();
  });

  it('shows an error state with a back link when fetch fails', () => {
    useMappingStore.setState({
      isLoading: false,
      mapping: null,
      error: 'No mapping found for this project. Upload a file first.',
    });
    renderAt('42');
    expect(screen.getByText(/No mapping found/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Back to feed/ })).toHaveAttribute('href', '/projects/42');
  });

  it('renders the three buckets with their initial counts', () => {
    setMapping(fakeMapping());
    renderAt('42');

    expect(screen.getByLabelText('Translatable columns')).toHaveTextContent('Translatable');
    expect(screen.getByLabelText('Translatable columns')).toHaveTextContent('2');
    expect(screen.getByLabelText('Protected (Golden Rule) columns')).toHaveTextContent('2');
    expect(screen.getByLabelText('Skipped (codes / numbers) columns')).toHaveTextContent('1');
  });

  it('renders the detected source language and AI confidence in the subtitle', () => {
    setMapping(fakeMapping());
    renderAt('42');

    expect(screen.getByText('EN')).toBeInTheDocument();
    expect(screen.getByText(/AI confidence 92%/)).toBeInTheDocument();
  });

  it('flags ambiguous columns inline', () => {
    setMapping(fakeMapping());
    renderAt('42');

    const desc = screen.getByLabelText('description is ambiguous');
    expect(desc).toBeInTheDocument();
  });

  it('moves a column between buckets via the radio chips', async () => {
    const user = userEvent.setup();
    setMapping(fakeMapping());
    renderAt('42');

    // The "title" column is currently translatable. Move it to "Skip".
    const titleRow = screen.getByText('title').closest('li')!;
    const skipChip = within(titleRow).getByRole('radio', { name: 'Skip' });
    await user.click(skipChip);

    // After the move, the Translatable bucket should now have only 'description'.
    const translatable = screen.getByLabelText('Translatable columns');
    expect(translatable).toHaveTextContent('1');
    const skipped = screen.getByLabelText('Skipped (codes / numbers) columns');
    expect(skipped).toHaveTextContent('2');
  });

  it('disables Save until something is dirty', () => {
    setMapping(fakeMapping());
    renderAt('42');
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
  });

  it('enables Save once a column has been moved', async () => {
    const user = userEvent.setup();
    setMapping(fakeMapping());
    renderAt('42');

    const titleRow = screen.getByText('title').closest('li')!;
    await user.click(within(titleRow).getByRole('radio', { name: 'Skip' }));

    expect(screen.getByRole('button', { name: 'Save changes' })).not.toBeDisabled();
  });

  it('disables Approve when the translatable bucket is empty', async () => {
    const user = userEvent.setup();
    setMapping(fakeMapping({ translatable_fields: ['title'], protected_fields: ['price', 'sku'], skipped_fields: ['description', 'size'] }));
    renderAt('42');

    const titleRow = screen.getByText('title').closest('li')!;
    await user.click(within(titleRow).getByRole('radio', { name: 'Skip' }));

    expect(screen.getByRole('button', { name: /Approve and translate/ })).toBeDisabled();
    expect(screen.getByText(/Pick at least one translatable column/)).toBeInTheDocument();
  });

  it('Approve flow: saves dirty changes, calls approve, refetches project, navigates to feed', async () => {
    const user = userEvent.setup();
    const save = vi.fn().mockResolvedValue(undefined);
    const approve = vi.fn().mockResolvedValue(undefined);
    const fetchProject = vi.fn().mockResolvedValue(undefined);

    useMappingStore.setState({ save, approve });
    useProjectStore.setState({ fetchProject });

    setMapping(fakeMapping());
    renderAt('42');

    // Move 'description' to 'protected' to make the form dirty.
    const descRow = screen.getByText('description').closest('li')!;
    await user.click(within(descRow).getByRole('radio', { name: 'Protect' }));

    await user.click(screen.getByRole('button', { name: /Approve and translate/ }));

    await waitFor(() => expect(save).toHaveBeenCalled());
    await waitFor(() => expect(approve).toHaveBeenCalledWith(42));
    await waitFor(() => expect(fetchProject).toHaveBeenCalledWith(42));
    await screen.findByText('Feed detail');
  });

  it('Approve without dirty state skips save and goes straight to approve', async () => {
    const user = userEvent.setup();
    const save = vi.fn().mockResolvedValue(undefined);
    const approve = vi.fn().mockResolvedValue(undefined);
    useMappingStore.setState({ save, approve });

    setMapping(fakeMapping());
    renderAt('42');

    await user.click(screen.getByRole('button', { name: /Approve and translate/ }));

    await waitFor(() => expect(approve).toHaveBeenCalledWith(42));
    expect(save).not.toHaveBeenCalled();
  });

  it('renders the store error banner above the buckets when save/approve fails', () => {
    useMappingStore.setState({ error: 'Plan does not allow this many languages.' });
    setMapping(fakeMapping());
    renderAt('42');

    expect(screen.getByRole('alert')).toHaveTextContent('Plan does not allow this many languages.');
  });
});

// ───────────────────────────────────────────────────────────────────────────
// Local helper kept inline because Testing Library's `within` hides imports
// at the bottom of the file improves diff readability for these small specs.
import { within } from '@testing-library/react';
