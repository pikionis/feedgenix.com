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
    route('settings', 'routes/app/settings.tsx'),
  ]),
] satisfies RouteConfig
