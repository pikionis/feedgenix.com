import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import WorkspaceCard from './WorkspaceCard';

describe('WorkspaceCard', () => {
  it('renders name + plan + derived monogram for a multi-word team name', () => {
    render(<WorkspaceCard name="Deindeal AG" plan="Pro plan" />);
    expect(screen.getByText('Deindeal AG')).toBeInTheDocument();
    expect(screen.getByText('Pro plan')).toBeInTheDocument();
    expect(screen.getByText('DA')).toBeInTheDocument();
  });

  it('derives monogram from the first two chars for a single-word team name', () => {
    render(<WorkspaceCard name="Migros" plan="Starter" />);
    expect(screen.getByText('MI')).toBeInTheDocument();
  });

  it('accepts an explicit initials override', () => {
    render(<WorkspaceCard name="Deindeal AG" plan="Pro" initials="DD" />);
    expect(screen.getByText('DD')).toBeInTheDocument();
  });

  it('falls back to ?? when name is all whitespace', () => {
    render(<WorkspaceCard name="   " plan="Free" />);
    expect(screen.getByText('??')).toBeInTheDocument();
  });
});
