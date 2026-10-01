/**
 * The backend environment for end-to-end runs. Locally this is a dedicated database
 * (feedgenix_e2e); in CI the job's DB_DATABASE (a throwaway service container).
 */
export const e2eEnvironment: Record<string, string> = {
  DB_DATABASE: process.env.CI ? (process.env.DB_DATABASE ?? 'feedgenix_test') : 'feedgenix_e2e',
  FEEDGENIX_REGISTRATION: 'open',
  MAIL_MAILER: 'log',
  // Rate limits live in the cache; here every request is a fresh process, so they never trigger.
  // The suite signs up many accounts from one address within a minute; the limits themselves are
  // covered by the backend tests.
  CACHE_STORE: 'array',
  // Imports run inside the request: no queue worker runs during end-to-end tests.
  QUEUE_CONNECTION: 'sync',
  // The demo feed is served from 127.0.0.1 (refused in production whatever this says).
  FEEDGENIX_FETCH_ALLOW_PRIVATE: 'true',
  // The built-in PHP server handles one request at a time unless told otherwise (not on Windows).
  PHP_CLI_SERVER_WORKERS: '4',
}
