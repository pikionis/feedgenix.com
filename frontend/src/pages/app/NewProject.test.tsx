import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import NewProject from './NewProject';
import { useProjectStore } from '@/stores/projectStore';

function renderWithRouter(initial = ['/projects/new']) {
  return render(
    <MemoryRouter initialEntries={initial}>
      <Routes>
        <Route path="/projects/new" element={<NewProject />} />
        <Route path="/projects/:id" element={<div>Feed detail</div>} />
        <Route path="/projects" element={<div>Feeds list</div>} />
      </Routes>
    </MemoryRouter>
  );
}

describe('NewProject', () => {
  beforeEach(() => {
    useProjectStore.setState({
      projects: [],
      isLoading: false,
      createProject: vi.fn().mockResolvedValue({ id: 42 }) as any,
    });
  });

  it('surfaces validation errors for empty required fields', async () => {
    const user = userEvent.setup();
    renderWithRouter();

    await user.click(screen.getByRole('button', { name: /Create feed/ }));

    expect(await screen.findByText('Feed name is required')).toBeInTheDocument();
    expect(await screen.findByText('Pick the source language')).toBeInTheDocument();
    expect(await screen.findByText('Pick at least one target language')).toBeInTheDocument();
  });

  it('omits the chosen source language from the target chip list', async () => {
    const user = userEvent.setup();
    renderWithRouter();

    await user.selectOptions(screen.getByLabelText('Source language'), 'de');

    // German is no longer clickable as a target.
    expect(screen.queryByRole('button', { name: 'German' })).not.toBeInTheDocument();
    // Other languages still available.
    expect(screen.getByRole('button', { name: 'French' })).toBeInTheDocument();
  });

  it('toggles a target language chip on and off', async () => {
    const user = userEvent.setup();
    renderWithRouter();

    const fr = screen.getByRole('button', { name: 'French' });
    expect(fr).toHaveAttribute('aria-pressed', 'false');

    await user.click(fr);
    expect(fr).toHaveAttribute('aria-pressed', 'true');

    await user.click(fr);
    expect(fr).toHaveAttribute('aria-pressed', 'false');
  });

  it('submits valid data and navigates to the new feed detail', async () => {
    const user = userEvent.setup();
    const createProject = vi.fn().mockResolvedValue({ id: 99 });
    useProjectStore.setState({ createProject: createProject as any });

    renderWithRouter();

    await user.type(screen.getByLabelText('Feed name'), 'Autumn/winter 2026');
    await user.selectOptions(screen.getByLabelText('Source language'), 'en');
    await user.click(screen.getByRole('button', { name: 'French' }));
    await user.click(screen.getByRole('button', { name: /Create feed/ }));

    await waitFor(() =>
      expect(createProject).toHaveBeenCalledWith(
        expect.objectContaining({
          name: 'Autumn/winter 2026',
          source_language: 'en',
          target_languages: ['fr'],
        })
      )
    );

    await screen.findByText('Feed detail');
  });

  it('shows a server-side error message when createProject rejects with response.data.message', async () => {
    const user = userEvent.setup();
    const createProject = vi.fn().mockRejectedValue({
      response: { data: { message: 'Plan does not allow this many target languages' } },
    });
    useProjectStore.setState({ createProject: createProject as any });

    renderWithRouter();

    await user.type(screen.getByLabelText('Feed name'), 'X');
    await user.selectOptions(screen.getByLabelText('Source language'), 'en');
    await user.click(screen.getByRole('button', { name: 'French' }));
    await user.click(screen.getByRole('button', { name: /Create feed/ }));

    expect(
      await screen.findByRole('alert')
    ).toHaveTextContent('Plan does not allow this many target languages');
  });

  it('shows a generic error message on unstructured failures', async () => {
    const user = userEvent.setup();
    const createProject = vi.fn().mockRejectedValue(new Error('network blip'));
    useProjectStore.setState({ createProject: createProject as any });

    renderWithRouter();

    await user.type(screen.getByLabelText('Feed name'), 'X');
    await user.selectOptions(screen.getByLabelText('Source language'), 'en');
    await user.click(screen.getByRole('button', { name: 'French' }));
    await user.click(screen.getByRole('button', { name: /Create feed/ }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Failed to create the feed');
  });
});
