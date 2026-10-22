import { setupServer } from 'msw/node'
import { authOptions } from './handlers'

/**
 * The mock network for unit tests: tests add handlers with server.use(), anything unhandled fails.
 * The one default is what the sign-in pages always ask: no Google, invite-only sign-up (the server's
 * own default).
 */
export const server = setupServer(authOptions())
