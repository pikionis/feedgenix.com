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

/** A feed with its latest import's summary (GET /v1/feeds/{feed}). */
export type Feed = components['schemas']['FeedResource']

export type ImportSummary = components['schemas']['ImportSummaryResource']

/** An import in full: how the file was read, its column profile and sample rows. */
export type FeedImport = components['schemas']['ImportResource']

export type ImportStatus = components['schemas']['ImportStatus']

export type ValueKind = components['schemas']['ValueKind']

export type FeedFormat = components['schemas']['Format']

export type EncodingReason = NonNullable<FeedImport['encoding']>['reason']

/** What the customer may correct when a file was read wrongly (POST /v1/imports/{import}/reread). */
export type ReadingOptions = components['schemas']['RereadImportRequest']

export type Encoding = NonNullable<ReadingOptions['encoding']>

export type Delimiter = NonNullable<ReadingOptions['delimiter']>

export type RegistrationMode = components['schemas']['RegistrationMode']

export type AuthOptions = paths['/auth/options']['get']['responses'][200]['content']['application/json']

/** A feed's mapping for review (GET /v1/feeds/{feed}/mapping). */
export type Mapping = components['schemas']['MappingResource']

export type MappingField = Mapping['fields'][number]

export type MappingColumn = Mapping['columns'][number]

/** A canonical field a column can fill. */
export type CanonicalField = Mapping['available_fields'][number]

export type FieldClass = components['schemas']['FieldClass']

/** A feed's translation plan, its work, estimate and latest run (GET /v1/feeds/{feed}/translation-plan). */
export type TranslationPlan = components['schemas']['TranslationPlanResource']

export type TranslationMode = TranslationPlan['fields'][number]['mode']

export type PipelineRun = NonNullable<TranslationPlan['run']>
