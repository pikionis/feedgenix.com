import type { components, paths } from './schema'

/** The signed-in person (GET /v1/auth/user). */
export type SessionUser = components['schemas']['UserResource']

/** The workspace view (GET /v1/workspace). */
export type Workspace = components['schemas']['WorkspaceResource']

export type Plan = components['schemas']['PlanResource']

export type Language = components['schemas']['LanguageResource']

/** The 402 payload: a plan limit or a feature of a higher plan. */
export type LimitHit = paths['/v1/workspace/languages']['put']['responses'][402]['content']['application/json']

/** Laravel's 422 body. */
export interface ValidationErrorBody {
  message: string
  errors: Record<string, string[]>
}

export function isValidationError(body: unknown): body is ValidationErrorBody {
  return typeof body === 'object' && body !== null && 'errors' in body && typeof body.errors === 'object'
}
