import { Outlet } from 'react-router-dom';
import SiteHeader from './SiteHeader';
import SiteFooter from './SiteFooter';

/**
 * Marketing shell. Canvas is Mist so the hero's mosaic blocks can bleed
 * into a continuous warm surface — sections below (logos, features, FAQ)
 * each declare their own Paper/Sand background.
 */
export default function MarketingLayout() {
  return (
    <div className="min-h-screen bg-mist">
      <SiteHeader />
      <main>
        <Outlet />
      </main>
      <SiteFooter />
    </div>
  );
}
