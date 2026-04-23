import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import QuotaWidget from './QuotaWidget';

describe('QuotaWidget', () => {
  it('renders used / limit text in European-grouped integers', () => {
    render(<QuotaWidget used={63214} limit={100_000} />);
    expect(screen.getByText('63,214 / 100,000')).toBeInTheDocument();
    expect(screen.getByText('63%')).toBeInTheDocument();
  });

  it('sets the progressbar aria-valuenow to the rounded percentage', () => {
    render(<QuotaWidget used={63214} limit={100_000} />);
    const bar = screen.getByRole('progressbar');
    expect(bar).toHaveAttribute('aria-valuenow', '63');
  });

  it('clamps percentage into [0, 100] when usage exceeds the limit', () => {
    render(<QuotaWidget used={200_000} limit={100_000} />);
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
    expect(screen.getByText('100%')).toBeInTheDocument();
  });

  it('switches the bar to the warn color once at 90% or above', () => {
    const { container } = render(<QuotaWidget used={95_000} limit={100_000} />);
    const fill = container.querySelector('[role="progressbar"] > div') as HTMLElement;
    expect(fill.style.background).toBe('rgb(200, 122, 27)');  // status-warn
  });

  it('keeps the indigo color below the 90% threshold', () => {
    const { container } = render(<QuotaWidget used={89_000} limit={100_000} />);
    const fill = container.querySelector('[role="progressbar"] > div') as HTMLElement;
    expect(fill.style.background).toBe('rgb(79, 70, 229)');   // indigo
  });

  it('shows Unlimited / infinity label when the tier has no cap', () => {
    render(<QuotaWidget used={500_000} limit={Infinity} />);
    expect(screen.getByText('∞')).toBeInTheDocument();
    expect(screen.getByText('Unlimited rows')).toBeInTheDocument();
  });

  it('handles a zero-limit tier without dividing by zero', () => {
    render(<QuotaWidget used={0} limit={0} />);
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '0');
  });
});
