import { setupServer } from 'msw/node'
import { authOptions, notices } from './handlers'

/**
 * The mock network for unit tests: tests add handlers with server.use(), anything unhandled fails.
 * The defaults are what every page asks: the sign-in options (no Google, invite-only sign-up, the
 * server's own default) and the workspace's notices (none).
 */
export const server = setupServer(authOptions(), notices())
