import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import PageHeader from './PageHeader';
import StatCard from './StatCard';
import DeltaCallout from './DeltaCallout';

describe('PageHeader', () => {
  it('renders title and subtitle', () => {
    render(<PageHeader title="Feeds" subtitle="4 active feeds · last sync 8 min ago" />);
    expect(screen.getByRole('heading', { level: 1, name: 'Feeds' })).toBeInTheDocument();
    expect(screen.getByText('4 active feeds · last sync 8 min ago')).toBeInTheDocument();
  });

  it('omits the subtitle element entirely when not provided', () => {
    const { container } = render(<PageHeader title="Feeds" />);
    expect(container.querySelector('p')).toBeNull();
  });

  it('renders actions on the right-hand side when provided', () => {
    render(
      <PageHeader
        title="Feeds"
        actions={<button>New feed</button>}
      />
    );
    expect(screen.getByRole('button', { name: 'New feed' })).toBeInTheDocument();
  });
});

describe('StatCard', () => {
  it('renders label + value', () => {
    render(<StatCard label="Products" value="28,411" />);
    expect(screen.getByText('Products')).toBeInTheDocument();
    expect(screen.getByText('28,411')).toBeInTheDocument();
  });

  it('renders a delta chip with the emerald palette for an "up" tone', () => {
    const { container } = render(
      <StatCard label="Cache hit rate" value="68%" delta={{ text: '↑ 4 pts', tone: 'up' }} />
    );
    const chip = container.querySelector('span[style]') as HTMLElement;
    expect(chip.textContent).toBe('↑ 4 pts');
    expect(chip.style.background).toBe('rgb(214, 241, 232)');  // emerald-100
  });

  it('renders a neutral chip with slate palette', () => {
    const { container } = render(
      <StatCard label="Languages" value="7" delta={{ text: 'DE · FR · IT', tone: 'neutral' }} />
    );
    const chip = container.querySelector('span[style]') as HTMLElement;
    expect(chip.style.background).toBe('rgb(237, 235, 227)');  // slate mist
  });

  it('omits the delta chip when no delta is given', () => {
    const { container } = render(<StatCard label="Products" value="0" />);
    expect(container.querySelector('span[style]')).toBeNull();
  });
});

describe('DeltaCallout', () => {
  it('renders the message and action side-by-side', () => {
    render(
      <DeltaCallout
        message={<><strong>3,847 rows changed</strong> since last sync.</>}
        action={<button>Run delta job</button>}
      />
    );
    expect(screen.getByText('3,847 rows changed')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Run delta job' })).toBeInTheDocument();
  });

  it('is announced as a live status region', () => {
    render(<DeltaCallout message="x" action={<button>y</button>} />);
    expect(screen.getByRole('status')).toBeInTheDocument();
  });
});
