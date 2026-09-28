# Lessons from the legacy app

Feed Intelligence ran one customer's catalogues (up to ~1M products) in production from 2026-05 to
2026-09. These are the incidents that shaped FeedGenix's design. Each lesson becomes at least one test
in the milestone named in brackets.

## Data safety
- **A silently truncated gzip was served** (the writer stopped at row 218K without an error).
  → Write to a new file, verify by decompressing and counting rows, then swap atomically. [M5]
- **An interrupted scan marked most of a catalogue as disappeared** (2,855 of 4,030 products).
  → Never run the disappear step for an incomplete import, and refuse it when more than 30% of a
  catalogue larger than 50 would vanish. [M4]
- **Re-imports overwrote stored values wholesale**, three times, each time wiping a different field.
  → No shared JSON documents; one row per product × field × language, merges never replace. [M4]
- **A parallel scan wiped a classification** that only existed in stored data, not in the feed.
  → Derived data is recomputable or protected by fenced writes; one sync code path. [M4]
- **Manual edits were lost on re-import.** → `origin = manual` survives until the source changes. [M4]
- **Literal newlines and tabs in product text split rows** in the exported TSV.
  → Dialects escape or collapse control characters; golden values are never rewritten. [M5]
- **An export shrank by 60% overnight** because a supplier sent a partial file.
  → Shrink guard: the old file keeps serving and someone is told. [M5]

## AI correctness and cost
- **A model renumbered its outputs** and translations landed on the wrong products.
  → JSON with an id per item; the returned id set must match exactly or the unit is split. [M4]
- **Gemini's RECITATION filter blocked verbatim translations** of long product text.
  → A typed error with a paraphrase-retry path, never an empty "success". [M4]
- **Wrong-language output** passed as a translation. → QA checks the output language per item. [M4]
- **Large HTML fields overflowed the output budget** and returned truncated JSON.
  → Batch sizes per field class (long HTML in small batches); truncated JSON splits the unit. [M4]
- **"Leave empty rather than wrong"** was the rule that made composition (N columns → 1 field)
  trustworthy: low confidence or empty on samples means the field stays empty. [M3]
- **429 storms and non-atomic spend counters** let cost run past the daily cap.
  → Backoff with Retry-After, and caps enforced atomically in the database. [M3]
- **Translation memory was shared across tenants** before tenancy existed.
  → The memory key includes the tenant, field class, style and prompt version. [M4]

## Operations
- **Four overlapping "healer" crons** fought each other and still left work "covered but pending".
  → One reconciler with an explicit state for every unit. [M4]
- **Queue workers kept running old code** after a deploy. → Every deploy restarts the workers. [M1]
- **A self-spawning worker in a monitor ran against a real database during tests.**
  → Tests have no side effects outside the test database; no process spawning in tests. [M0 policy]
- **Tests reached real AI APIs** with dummy keys. → `Http::preventStrayRequests()` and MSW in error
  mode; the frontend API client resolves `fetch` per call so nothing bypasses MSW. [M0, done]
- **An unindexed GROUP BY over 1M rows made the dashboard take 69 s.**
  → Composite indexes led by `tenant_id` for every dashboard query; query plans checked in review. [M6]
- **A shared web server lost its HTTPS port to an orphaned process** and the site went down.
  → The beta vhost is independent, `nginx -t` before every reload, uptime checks alert. [M1]

## Security
- **A chunked-upload path let a crafted request delete arbitrary files.**
  → Upload paths are derived from ids, never from client input. [M2]
- **Re-saving an import without credentials wiped the stored ones.**
  → Omitted secrets mean "unchanged", tested for every secret field. [M8]
- **API keys ended up in a committed settings file.**
  → gitleaks in CI; secrets only in the server's `.env`, per environment. [M0, done]
