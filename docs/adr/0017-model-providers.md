# ADR 0017 — The model providers: Gemini over HTTP, Claude through the official SDK

- Status: accepted
- Date: 2026-10-23
- Refines: ADR 0004 (AI gateway), ADR 0016 (credit ledger)

## Context
M3 step 2 connects the gateway to real hosts. ADR 0004 fixed the transport rules (keys in headers,
TLS verified, typed errors, an egress guard, JSON with an id per item). Three choices are expensive
to undo once traffic and money run through them.

## Decision
- **Claude goes through the official PHP SDK (`anthropic-ai/sdk`)**, not hand-written HTTP, so the
  request shape follows the API as it changes. Its PSR-18 transporter is Laravel's HTTP client
  (`LaravelTransporter`), so the SDK's traffic passes the same guards as every other outbound call
  (the egress guard, TLS, timeouts) and, in tests, `Http::fake()` and `preventStrayRequests()`. The
  SDK's own retries are off; the gateway's Retry decides. SDK exceptions are never re-thrown or
  chained: they carry the request (with the key) and the response body.
- **Gemini goes over Laravel's HTTP client** (Google publishes no PHP SDK for the Gemini API):
  `generateContent` with the key in `x-goog-api-key`, `responseJsonSchema`, paid tier only.
- **Claude model:** `claude-opus-5-5` (effort `medium`) is the reasoning fallback for mapping and
  quality. The owner may choose a cheaper Claude model; that is a pricing decision, not ours.
- **Structured outputs, both providers:** the answer is `{"items": [{"id", "value"}]}` under a
  strict schema whose `id` is an enum of the call's item ids. Forced tool use is not used (current
  Claude models reject it). Every answer is still checked against its value schema by the gateway,
  because a provider's schema mode may ignore keywords.
- **Usage, normalised:** Gemini counts cached tokens inside the prompt and thoughts beside the
  output; Claude counts thinking inside `output_tokens`. Both become `AiUsage` with input excluding
  cached input, and output and thinking billed once each at the output price.
- **Refusal fallbacks (Claude):** a server-side fallback (`fallbacks`, beta
  `server-side-fallback-2026-06-01`) is sent only when every fallback model has a confirmed price,
  because each hop is billed at its own model's rate (`usage.iterations`). The reservation covers
  every hop's worst case. With no price confirmed, no fallback is offered and a refusal is terminal.
- **The egress guard is an allowlist of callers:** a request to a model host (Gemini, Vertex AI,
  Anthropic, OpenAI) leaves only with the marker header a provider sets, which the guard removes. A
  feed URL pointed at a model host is refused the same way.
- **TLS is never turned off:** an arch test bans `withoutVerifying()`, `'verify' => false` and the
  cURL verification switches in product code.

## Consequences
- No test can reach a model host: the providers' HTTP goes through Laravel's client, which every
  test blocks.
- Moving a model, a price or a fallback is configuration (`config/ai.php`); a model without all three
  prices stays uncallable.
- The first call on each real key (the integrations phase) confirms the request shapes against the
  live APIs: `responseJsonSchema` on Gemini and the beta fallback header on Claude.
