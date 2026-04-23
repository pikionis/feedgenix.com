import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import FeedsTable, { type FeedRow } from './FeedsTable';

function withRouter(ui: React.ReactNode) {
  return render(<MemoryRouter>{ui}</MemoryRouter>);
}

describe('FeedsTable', () => {
  it('renders the empty state with a link to the upload flow when rows is empty', () => {
    withRouter(<FeedsTable rows={[]} />);
    expect(screen.getByText(/No feeds yet/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Upload your first CSV/ })).toHaveAttribute(
      'href',
      '/projects/new'
    );
  });

  it('honors a custom empty node', () => {
    withRouter(<FeedsTable rows={[]} empty="Nothing here, come back later." />);
    expect(screen.getByText('Nothing here, come back later.')).toBeInTheDocument();
  });

  it('renders a row with name, filename, locale pills, and status badge', () => {
    const rows: FeedRow[] = [
      {
        id: 42,
        name: 'Autumn/Winter 2026',
        filename: 'shopify_export.csv',
        rowCount: 12_480,
        languages: ['de', 'fr', 'it', 'el'],
        status: 'completed',
      },
    ];
    withRouter(<FeedsTable rows={rows} />);

    expect(screen.getByText('Autumn/Winter 2026')).toBeInTheDocument();
    expect(screen.getByText('shopify_export.csv')).toBeInTheDocument();
    expect(screen.getByText('12,480')).toBeInTheDocument();
    for (const code of ['DE', 'FR', 'IT', 'EL']) {
      expect(screen.getByText(code)).toBeInTheDocument();
    }
    expect(screen.getByText('Synced')).toBeInTheDocument();

    // Row links to the detail page.
    const link = screen.getByRole('link');
    expect(link).toHaveAttribute('href', '/projects/42');
  });

  it('shows a progress bar only for translating feeds', () => {
    const rows: FeedRow[] = [
      { id: 1, name: 'A', rowCount: 10, languages: ['de'], status: 'translating', progress: 0.42 },
      { id: 2, name: 'B', rowCount: 10, languages: ['de'], status: 'completed' },
    ];
    const { container } = withRouter(<FeedsTable rows={rows} />);

    // Exactly one progress bar (the translating row). The completed row has none.
    // #C87A1B → rgb(200, 122, 27)
    const bars = container.querySelectorAll('div[style*="background: rgb(200, 122, 27)"]');
    expect(bars).toHaveLength(1);
  });

  it('applies per-language tints to the locale pills', () => {
    const rows: FeedRow[] = [
      { id: 1, name: 'x', rowCount: 1, languages: ['de'], status: 'completed' },
    ];
    const { container } = withRouter(<FeedsTable rows={rows} />);
    const de = screen.getByText('DE') as HTMLElement;
    expect(de.style.background).toBe('rgb(231, 228, 250)');   // --lang-de-bg
    expect(de.style.color).toBe('rgb(59, 52, 144)');          // --lang-de-fg
    expect(container).toBeTruthy();
  });
});
