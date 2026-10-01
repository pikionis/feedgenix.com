import { isValidationError } from '~/lib/api/types'
import { describeFailure } from '~/lib/forms'

/**
 * One sentence for a failed feed call. The server's own words when it has them: 422 (the first
 * field error), 409 (another import is running) and 410 (the upload expired).
 */
export function feedErrorMessage(status: number, body: unknown): string {
  if (isValidationError(body)) return Object.values(body.errors)[0]?.[0] ?? body.message
  if ((status === 409 || status === 410) && hasMessage(body)) return body.message
  if (status === 410) return 'This upload has expired. Start it again.'
  if (status === 413) return 'This file is too large to upload.'

  return describeFailure(status)
}

function hasMessage(body: unknown): body is { message: string } {
  return typeof body === 'object' && body !== null && 'message' in body && typeof body.message === 'string'
}
