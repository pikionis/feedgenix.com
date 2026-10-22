import { setupServer } from 'msw/node'
import { authProviders } from './handlers'

/**
 * The mock network for unit tests: tests add handlers with server.use(), anything unhandled fails.
 * The one default is what the sign-in pages always ask: which methods the server offers (no Google).
 */
export const server = setupServer(authProviders(false))
