import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import Pricing, { externalRedirect } from './Pricing';
import { useAuthStore } from '@/stores/authStore';
import { useSubscriptionStore } from '@/stores/subscriptionStore';

function renderPricing() {
  return render(
    <MemoryRouter initialEntries={['/pricing']}>
      <Routes>
        <Route path="/pricing" element={<Pricing />} />
        <Route path="/register" element={<div>Register page</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('Pricing CTAs', () => {
  beforeEach(() => {
    useAuthStore.setState({ user: null, isAuthenticated: false, isLoading: false });
    useSubscriptionStore.setState({
      info: null,
      isLoading: false,
      isStartingCheckout: false,
      isUpdating: false,
      isCanceling: false,
      error: null,
      checkout: vi.fn().mockResolvedValue('https://checkout.stripe.com/test'),
      changePlan: vi.fn(),
      cancel: vi.fn(),
      portal: vi.fn(),
      fetch: vi.fn(),
      reset: vi.fn(),
    });
    externalRedirect.go = vi.fn();
  });

  it('Free tier link goes to /register', () => {
    renderPricing();
    const free = screen.getByRole('link', { name: 'Start free' });
    expect(free).toHaveAttribute('href', '/register');
  });

  it('Enterprise tier link is a mailto', () => {
    renderPricing();
    const ent = screen.getByRole('link', { name: 'Talk to sales' });
    expect(ent).toHaveAttribute('href', 'mailto:sales@feedgenix.com');
  });

  it('Paid tier link points to /register?plan=…&cycle=… when logged out', () => {
    renderPricing();
    const starter = screen.getByRole('link', { name: 'Choose Starter' });
    expect(starter.getAttribute('href')).toContain('/register?plan=starter');
    expect(starter.getAttribute('href')).toContain('cycle=monthly');
  });

  it('Paid tier link reflects the yearly cycle when toggle is yearly', async () => {
    const user = userEvent.setup();
    renderPricing();
    await user.click(screen.getByRole('button', { name: /Yearly/ }));

    const starter = screen.getByRole('link', { name: 'Choose Starter' });
    expect(starter.getAttribute('href')).toContain('cycle=yearly');
  });

  it('Logged-out click on a paid tier just navigates — never calls checkout', async () => {
    const user = userEvent.setup();
    const checkout = vi.fn();
    useSubscriptionStore.setState({ checkout: checkout as any });

    renderPricing();
    await user.click(screen.getByRole('link', { name: 'Choose Starter' }));

    expect(checkout).not.toHaveBeenCalled();
    expect(await screen.findByText('Register page')).toBeInTheDocument();
  });

  it('Logged-in click on a paid tier calls checkout(plan, cycle) and redirects', async () => {
    const user = userEvent.setup();
    useAuthStore.setState({
      user: { id: 1, name: 'Maria', email: 'm@x.com', role: 'owner' } as any,
      isAuthenticated: true,
      isLoading: false,
    });
    const checkout = vi.fn().mockResolvedValue('https://checkout.stripe.com/cs_test_99');
    useSubscriptionStore.setState({ checkout: checkout as any });

    renderPricing();
    // Switch to yearly so we can also assert the cycle value.
    await user.click(screen.getByRole('button', { name: /Yearly/ }));
    await user.click(screen.getByRole('link', { name: /Start Pro trial/ }));

    await waitFor(() => expect(checkout).toHaveBeenCalledWith('pro', 'yearly'));
    await waitFor(() => expect(externalRedirect.go).toHaveBeenCalledWith('https://checkout.stripe.com/cs_test_99'));
  });

  it('renders the pass-through transparency badge', () => {
    renderPricing();
    expect(screen.getByTestId('passthrough-badge')).toHaveTextContent(/Pass-through translation cost/);
    expect(screen.getByTestId('passthrough-badge')).toHaveTextContent(/no markup/);
  });

  it('shows the new tier headline copy explaining the pricing model', () => {
    renderPricing();
    expect(
      screen.getByRole('heading', { level: 1, name: /Pay for software/ }),
    ).toBeInTheDocument();
  });

  it('reflects the new tier capacities (Free 500 / Starter 10K / Pro 50K)', () => {
    renderPricing();
    expect(screen.getByText('500 products / month')).toBeInTheDocument();
    expect(screen.getByText('10,000 products / month')).toBeInTheDocument();
    expect(screen.getByText('50,000 products / month')).toBeInTheDocument();
    expect(screen.getByText('Unlimited products')).toBeInTheDocument();
  });

  it('lists language caps consistent with the new tier model', () => {
    renderPricing();
    expect(screen.getByText('1 target language')).toBeInTheDocument();
    expect(screen.getByText('4 target languages')).toBeInTheDocument();
    expect(screen.getByText('7 target languages')).toBeInTheDocument();
    expect(screen.getByText(/Unlimited languages/)).toBeInTheDocument();
  });

  it('Free tier always goes to /register, even when logged in', async () => {
    const user = userEvent.setup();
    useAuthStore.setState({
      user: { id: 1, name: 'Maria', email: 'm@x.com', role: 'owner' } as any,
      isAuthenticated: true,
      isLoading: false,
    });
    const checkout = vi.fn();
    useSubscriptionStore.setState({ checkout: checkout as any });

    renderPricing();
    await user.click(screen.getByRole('link', { name: 'Start free' }));

    expect(checkout).not.toHaveBeenCalled();
    expect(await screen.findByText('Register page')).toBeInTheDocument();
  });

  it('renders the checkout error banner above the tier grid when the store has an error', () => {
    useSubscriptionStore.setState({ error: 'Invalid plan or billing cycle configuration.' });
    renderPricing();

    const alerts = screen.getAllByRole('alert');
    expect(alerts.some((a) => a.textContent?.includes('Invalid plan or billing cycle'))).toBe(true);
  });

  it('Tier link shows the loading label and is busy while checkout is in flight', () => {
    useAuthStore.setState({
      user: { id: 1, name: 'Maria', email: 'm@x.com', role: 'owner' } as any,
      isAuthenticated: true,
      isLoading: false,
    });
    useSubscriptionStore.setState({ isStartingCheckout: true });

    renderPricing();
    // Starter card shows the busy label.
    const starter = screen.getAllByText('Starting checkout…')[0];
    expect(starter).toBeInTheDocument();
    const link = starter.closest('a');
    expect(link).toHaveAttribute('aria-busy', 'true');
  });
});
