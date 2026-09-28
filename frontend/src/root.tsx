import '@fontsource/inter/400.css'
import '@fontsource/inter/500.css'
import '@fontsource/jetbrains-mono/400.css'
import './app.css'

import { QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { isRouteErrorResponse, Links, Meta, Outlet, Scripts, ScrollRestoration } from 'react-router'
import { queryClient } from '~/lib/query'
import type { Route } from './+types/root'

export const links: Route.LinksFunction = () => [{ rel: 'icon', href: '/favicon.svg', type: 'image/svg+xml' }]

export function Layout({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <head>
        <meta charSet="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <Meta />
        <Links />
      </head>
      <body>
        {children}
        <ScrollRestoration />
        <Scripts />
      </body>
    </html>
  )
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <Outlet />
    </QueryClientProvider>
  )
}

/** Shown while the SPA hydrates: the design system's indigo progress bar, never a spinner. */
export function HydrateFallback() {
  return (
    <div>
      <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />
      <p className="sr-only" aria-live="polite">
        Loading…
      </p>
    </div>
  )
}

export function ErrorBoundary({ error }: Route.ErrorBoundaryProps) {
  const title = isRouteErrorResponse(error) ? `${error.status} ${error.statusText}` : 'Something went wrong'

  return (
    <main className="mx-auto max-w-xl px-6 py-16">
      <h1 className="text-2xl font-medium tracking-tight">{title}</h1>
      <p className="mt-3 text-muted-foreground">Reload the page. If it keeps happening, contact support.</p>
    </main>
  )
}
