import { setupServer } from 'msw/node'

/** The mock network for unit tests: tests add handlers with server.use(), anything unhandled fails. */
export const server = setupServer()
