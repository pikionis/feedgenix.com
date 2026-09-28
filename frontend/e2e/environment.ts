/**
 * The backend environment for end-to-end runs. Locally this is a dedicated database
 * (feedgenix_e2e); in CI the job's DB_DATABASE (a throwaway service container).
 */
export const e2eEnvironment: Record<string, string> = {
  DB_DATABASE: process.env.CI ? (process.env.DB_DATABASE ?? 'feedgenix_test') : 'feedgenix_e2e',
  FEEDGENIX_REGISTRATION: 'open',
  MAIL_MAILER: 'log',
  // The built-in PHP server handles one request at a time unless told otherwise (not on Windows).
  PHP_CLI_SERVER_WORKERS: '4',
}
