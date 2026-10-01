# ADR 0014: Feed size per plan

- Status: accepted
- Date: 2026-10-01
- Decided by: the owner (the limits); the mechanics follow from them

## Context
Shops export feeds far larger than 2 GB, which was one fixed cap for everyone. A larger feed costs
the server time and disk, not memory: the readers stream. Measured on the read-and-profile path, a
typical export reads at about 16 MB/s (CSV) to 19 MB/s (XML), with memory flat at about 70 MB. So a
50 GB feed takes about 50 minutes to read, plus the download.

## Decision
1. **Each plan has a feed size**, measured **uncompressed**: what costs time is what is read, and a
   `.gz` can be a tenth of it on the wire.

   | Plan | Feed size | Rows |
   |---|---|---|
   | Free | 2 GB | 5,000,000 |
   | Pro | 10 GB | 25,000,000 |
   | Ultra | 50 GB | 100,000,000 |
   | Custom | 50 GB by default; larger by agreement (`tenants.limits_override`) | 100,000,000 |

   They are public plan limits (`feed_bytes`, `feed_rows` in `config/plans.php`), shown on the plan
   cards. They replace the earlier internal guards `max_upload_mb` and `max_rows_per_feed`.
2. **The server has a ceiling** that no plan exceeds: `FEEDGENIX_FEED_MAX_BYTES` (2 GB when unset)
   and `FEEDGENIX_FEED_MAX_ROWS`. The shared beta server stays at 2 GB, so the legacy app's disk is
   never at risk. A dedicated server raises the ceiling to the largest plan.
3. **One class decides and words it** (`Domain/Ingest/FeedSizeLimit`): the workspace's limit is its
   plan's, within the ceiling.
   - Before an upload sends a byte (and before an upload feed is created, when its first file's
     size is known): over the plan, a **402** naming the cheapest plan that reads the file; over the
     ceiling, a **422**, since no plan would help.
   - During the download, which stops at the limit, and during the read (decompressed bytes, rows):
     the import fails `too_large` or `too_many_rows`, saying what the plan reads and what reads
     more.
   - An upgrade is promised only as far as this server really reads.
4. **A decompressed feed over the limit is `too_large`**, not a decompression bomb. The bomb is
   only the ratio no real feed has.
5. **Large imports have room:**
   - the import job may run three hours, and the queue's `retry_after` exceeds that;
   - an unfinished import counts as abandoned only after that time plus a margin;
   - downloads may take two hours, and a stalled one (under 1 KB/s for two minutes) is cut;
   - a short-job queue connection comes with the work dispatcher (M4).
6. **Disk:**
   - an upload is assembled by appending each chunk and deleting it, so the file never exists twice;
   - each feed keeps the files of its last three imports, plus the one its profile was read from.
     The nightly `ingest:prune` deletes older files once no other import uses them; those imports
     can no longer be read again.

## Consequences
- Pricing gains a concrete line per plan ("Feeds up to 10 GB each").
- The beta cannot offer more than 2 GB to anyone until the new server (M11) raises the ceiling.
- Tests pin the boundary, the 402/422 split, the download cap, the wording, the stale threshold and
  the file retention (22 of 22 mutants caught).
