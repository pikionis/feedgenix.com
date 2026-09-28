# ADR 0004 — The AI gateway

- Status: accepted
- Date: 2026-09-28

## Context
In the legacy app several services called models directly, the Gemini key travelled in URLs (and
leaked into exception messages), 429s were never retried, daily spend was not atomic, and a model
that renumbered its outputs silently put translations on the wrong products.

## Decision
- **`AiClient` is the only way to reach a model.** `generate(AiRequest)` takes an explicit
  `tenantId` (never ambient), a purpose, a versioned prompt (`translate@3.1`), items with ids,
  a response schema, max output tokens, an optional model and a credit reservation.
- **Decorator chain, in order:**
  1. PolicyGate — denies when the tenant is frozen or AI-paused, the feed is paused, the model or
     feature is not in the plan, a safety cap or the kill switch is hit, or there is no reservation.
  2. ReservationGate — the call's worst-case cost must fit the reservation.
  3. Router — picks the route per purpose; Claude is a fallback for reasoning purposes only.
  4. CircuitBreaker (429s don't trip it) → Retry (backoff with jitter, honours Retry-After) →
     Recorder (one `engine_calls` row per attempt, cost added to the reservation) → Provider
     (Gemini, Claude, Fake).
- **JSON in, JSON out, with an id per item** and `responseSchema`. The returned id set must match
  exactly; otherwise the unit is split, never guessed.
- **Transport:** keys in headers (`x-goog-api-key`), never URLs; TLS always verified; errors are
  typed (`AiDenied`, `AiRetryable`, `AiTerminal`); nothing matches on message strings; keys are
  redacted from every log line.
- **Gemini paid tier only** — free-tier content may be used to improve Google's products.
- **Models and prompts are configuration** (model ids and prices in config/DB). A model or prompt
  change must pass the recorded evals before it ships.
- An `AiEgressGuard` HTTP middleware rejects any request to an AI host that did not come through the
  gateway; an arch test forbids the `Http` facade outside AI providers and ingest fetchers.
- Tests put `FakeAiProvider` *under* the real gateway, so policy, reservations, recording and
  splitting are always exercised. It can inject 429s, timeouts, RECITATION and renumbered or missing
  ids.

## Consequences
- Cost, limits and safety are enforced in one place and cannot be bypassed by a new feature.
- Product text is treated as data: prompts separate instructions from content, outputs are schema
  validated, and golden fields never reach a prompt.
