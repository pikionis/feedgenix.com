# ADR 0009 — Hosting, deploys and backups

- Status: accepted
- Date: 2026-09-28

## Context
The owner decided (2026-09-28): run the private beta/staging on the existing VPS until the product
is ready, then move to a new server for the public launch. The existing VPS (Ubuntu 24.04, 8 vCPU /
31 GB, PHP 8.4, MySQL 8.4, nginx + Varnish) still hosts the frozen legacy app, which must stay
untouched.

## Decision
**Beta on the existing server — additive only, isolated by construction:**
- Unix user `feedgenix` without sudo, releases in `/srv/feedgenix/{releases,shared,current}`; it must
  be unable to read the legacy app directory (checked after provisioning).
- PostgreSQL 18 + pgvector from the PGDG apt repository, listening on localhost only; MySQL untouched.
- Its own Redis instance (`redis-server@feedgenix`, unix socket, `noeviction`); the legacy Redis is
  never touched.
- Its own PHP 8.4-FPM pool (user `feedgenix`, `open_basedir`), its own nginx vhost terminating TLS
  directly (no Varnish), certificate via `certbot certonly --webroot` so nothing rewrites the legacy
  configs; `nginx -t` always before a reload.
- Its own Supervisor programs and crontab. Invite-only signup, `noindex`, staff TOTP,
  environment-specific AI keys with provider budget alerts.
- **Every change on this shared host needs the owner's explicit go-ahead at that moment.** After each
  change and deploy: the legacy site still answers, its workers are still stopped, its freeze file is
  still present.

**Production at launch — a new server** provisioned by the same idempotent `ops/provision.sh`
(`--mode=shared` for the beta host, `--mode=dedicated` for a fresh server with ufw, fail2ban, SSH
keys only and unattended upgrades). The dedicated mode doubles as the Custom plan's blueprint.

**Deploys:** CI builds one artifact; Deployer uploads it to a new release directory, runs additive
migrations, switches the `current` symlink, reloads PHP-FPM, restarts the queue workers and runs a
smoke test (`/api/health`), rolling back automatically on failure. Never `git pull` on a server.

**Backups:** beta — nightly encrypted `pg_dump` off-site. Production — pgBackRest point-in-time
recovery to Backblaze B2 (EU) plus restic for storage; a restore drill before launch and monthly
(targets: ≤ 5 minutes of lost data, ≤ 2 hours to restore).

**Monitoring and secrets:** Sentry (EU), uptime and heartbeat checks, AI-spend alerts; `.env` mode
600 on the server, separate keys per environment, never the legacy keys.

## Consequences
- The beta costs nothing extra but shares a host with the archive; isolation is verified, not assumed.
- Moving to production is a re-run of the provisioning script plus a data move, rehearsed before launch.
