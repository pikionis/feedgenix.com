import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Logo from './Logo';

/** Logo is load-bearing: wrong render and every page in the app looks broken. */
describe('Logo', () => {
  it('renders the mark + wordmark by default', () => {
    render(
      <MemoryRouter>
        <Logo />
      </MemoryRouter>
    );

    expect(screen.getByText('FeedGenix')).toBeInTheDocument();
    // The mark is an SVG — present via its aria-hidden img role equivalent.
    const link = screen.getByRole('link');
    expect(link).toHaveAttribute('href', '/');
    expect(link.querySelector('svg')).toBeInTheDocument();
  });

  it('renders only the mark when variant is "mark" (no anchor, no wordmark)', () => {
    render(
      <MemoryRouter>
        <Logo variant="mark" />
      </MemoryRouter>
    );
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.queryByText('FeedGenix')).not.toBeInTheDocument();
  });

  it('renders a non-anchor span when asStatic is set (e.g. inside the footer)', () => {
    render(
      <MemoryRouter>
        <Logo asStatic />
      </MemoryRouter>
    );
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.getByText('FeedGenix')).toBeInTheDocument();
  });
});
