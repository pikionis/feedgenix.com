import { type RouteConfig, index, route } from '@react-router/dev/routes'

export default [
  index('routes/home.tsx'),

  // Signing in and up (public).
  route('login', 'routes/auth/login.tsx'),
  route('register', 'routes/auth/register.tsx'),
  route('forgot-password', 'routes/auth/forgot-password.tsx'),
  route('reset-password', 'routes/auth/reset-password.tsx'),

  // The workspace: every page needs a session (the layout's loader redirects to sign-in).
  route('app', 'routes/app/layout.tsx', [
    index('routes/app/overview.tsx'),
    route('feeds', 'routes/app/feeds.tsx'),
    route('feeds/:feedId', 'routes/app/feed.tsx'),
    route('feeds/:feedId/mapping', 'routes/app/mapping.tsx'),
    route('feeds/:feedId/translation', 'routes/app/translation.tsx'),
    route('feeds/:feedId/products', 'routes/app/products.tsx'),
    route('feeds/:feedId/publish', 'routes/app/publish.tsx'),
    route('notices', 'routes/app/notices.tsx'),
    route('billing', 'routes/app/billing.tsx'),
    route('billing/plans', 'routes/app/plans.tsx'),
    route('billing/checkout', 'routes/app/checkout.tsx'),
    route('settings', 'routes/app/settings.tsx'),
  ]),

  // The staff area: staff only, two-factor proven in the session (enforced by the API).
  route('admin', 'routes/admin/layout.tsx', [
    index('routes/admin/index.tsx'),
    route('workspaces', 'routes/admin/workspaces.tsx'),
    route('workspaces/:workspaceId', 'routes/admin/workspace.tsx'),
    route('work', 'routes/admin/work.tsx'),
    route('audit', 'routes/admin/audit.tsx'),
  ]),
] satisfies RouteConfig
