# ADR 0023 — When exports are built, and how feed URLs answer

- Status: accepted
- Date: 2026-10-23
- Refines: ADR 0007 (feed serving), ADR 0022 (the export build)

## Context
The legacy app rebuilt a feed when its translations had finished, or once a day as a safety net,
and a request for a missing file started a build. Prices and stock change every day; a feed URL
must follow them without waiting for a person, and never be built by whoever pulls it.

## Decision
- **A change to export** is recorded on the feed (`feeds.content_changed_at`) when a file is read
  into the catalogue, when a translation run ends (a build that started during the run saw only
  part of it), when a start writes kept texts and memory repeats, and when a person writes,
  approves or hands back a text.
- **The reconciler** queues a build of every active profile with no build since the last change,
  while nothing reads or translates the feed (a run waiting for a decision, credits or the
  allowance does not hold prices back). The job is unique per profile, so a slow queue is not
  flooded. A build killed mid-way is marked failed after the job's time limit.
- A new profile is built at once; a change of its files (dialect, layout, languages, or being
  resumed) builds it again; a paused profile is never built and keeps serving.
- **Feed URLs** are `/f/{40-character token}/{file}` outside the session middleware (no cookie for
  a channel's pull), rate limited to 60 a minute per token. The token is looked up by its hash
  in the system context, then everything runs as its workspace. The published build only: 503
  with Retry-After before the first one, 404 for an unknown token, file or deleted profile, and
  for a rotated token once its 72-hour grace is over. ETags are the file's SHA-256 (304 when it
  matches); the plain URL sends the gzip of the same build to a client that accepts it, and
  `{file}.gz` is the gzip itself. `noindex, nofollow` on every answer.
- Files are streamed by PHP for now. nginx sends `/f/` to Laravel (the vhost change needs a
  provision run on the server); serving through `X-Accel-Redirect` comes with the production
  server.

## Consequences
- A supplier's new prices reach the channels within minutes of the file, even while a person
  has not decided about its translations.
- A channel pulling an unchanged feed costs a hash lookup and a 304.
