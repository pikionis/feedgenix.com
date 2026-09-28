# ADR 0011: Sandboxed services and a forced-command deploy receiver

- Status: accepted
- Date: 2026-09-29
- Refines: [ADR 0009](0009-hosting-deploys-and-backups.md). The tools it named (Deployer, Supervisor
  programs, `redis-server@feedgenix`, a crontab) are replaced below. Its goals stand: additive only,
  isolated by construction, release directories, and rollback on a failed smoke test.

## Context
The beta runs on a production host that still serves the frozen legacy app. Writing the server
scripts for M1 showed that three of ADR 0009's tools would share something with that app:
- Supervisor is the legacy app's process manager.
- The stock `redis-server@` template runs as the `redis` user, which owns the legacy Redis data.
- A pool inside the system `php8.4-fpm` master is reloaded together with the legacy pool.

Deployer also needs a general shell for its SSH user. If the CI deploy key leaked, that shell would
sit next to another application's secrets.

## Decision

### Processes
Every FeedGenix process runs as its own systemd unit under the `feedgenix` user, in
`feedgenix.slice` (CPU and memory caps):
- **PHP-FPM:** FeedGenix's own master (`feedgenix-php`), with its own opcache. Because it runs as
  `feedgenix`, the deploy receiver can reload it without sudo. Its socket sits in a directory with
  mode `0710 feedgenix:<nginx group>`, so only nginx can connect.
- **Redis:** a private instance (`feedgenix-redis`) with a unix socket only, no TCP port, and
  `noeviction`.
- **Queue workers:** `feedgenix-worker@<queue>`.
- **Scheduler:** a systemd timer that starts one `schedule:run` tick a minute. Each tick starts on
  the live release, so a deploy never has to restart it.

### Sandbox
One drop-in, `feedgenix-.service.d/10-sandbox.conf`, applies to every `feedgenix-*` service
through systemd's prefix drop-ins. It sets:
- `ProtectSystem`, `ProtectHome` and `PrivateTmp`, and `ProtectProc=invisible`, which hides other
  users' processes.
- `InaccessiblePaths` over `/var/www`, Supervisor, MySQL, the system Redis, the crontabs and the
  certificates.
- `IPAddressDeny=localhost` plus the host's own addresses, with only systemd-resolved allowed. A
  FeedGenix process cannot reach another app's Redis or MySQL even by mistake.

### Data and access
- **PostgreSQL** listens on its unix socket only, with peer authentication, so no database password
  exists. The app role is `NOSUPERUSER NOBYPASSRLS` (ADR 0002). The database uses the builtin
  `C.UTF-8` locale, so glibc upgrades cannot silently change index order.
- **Deploys:** the CI key's `authorized_keys` line is `restrict,command="…/receive"`. The key has no
  shell, no PTY and no forwarding; it can only `deploy REVISION SHA256`, `rollback` or `status`.
  The receiver:
  1. streams the artifact with a size cap and checks its sha256, so a truncated upload never
     becomes a release;
  2. unpacks it into a new release directory;
  3. refuses an `.env` that reaches PostgreSQL or Redis over TCP;
  4. runs the additive migrations and `optimize` while the old release keeps serving;
  5. switches the `current` symlink atomically;
  6. reloads PHP-FPM gracefully and runs `queue:restart`;
  7. smoke-tests the site through nginx exactly as a visitor would: `/api/health`, the release's
     own `/` and SPA shell, and an anonymous `401`;
  8. switches back if the smoke test fails.

  One `flock` lets only one deploy or rollback run at a time. nginx runs PHP on `$realpath_root`
  with `open_file_cache off`, so the next request after a switch runs the new release.
- **Provisioning** is one idempotent script, `ops/provision.sh --mode=shared|dedicated`, with a
  `--dry-run` that changes nothing:
  - While apt runs, `policy-rc.d` blocks every service start and restart and needrestart is
    suspended, so installing a package never restarts a legacy service.
  - In shared mode, any upgrade or removal of an already-installed package aborts before anything
    is installed, unless `--allow-upgrades` is given.
  - Every nginx change must pass `nginx -t` or is reverted, so a broken config never waits on disk
    for the next legacy reload. After each reload the legacy site must still answer.

### Verification
- **On the server:** before its first change, provisioning records a fingerprint of the legacy app
  (content hashes of its configs, crontab, `.env` and freeze file; hashes only, root-only).
  `feedgenix-check` compares it after every change and deploy. It also checks, live, that:
  - the legacy workers are stopped and the freeze file is present;
  - the app user can read nothing of the legacy app;
  - the legacy directory is empty inside the running PHP-FPM's namespace;
  - a probe unit in the sandbox reaches FeedGenix's own sockets but no TCP service on the host.
- **In CI:** `ops/tests/rehearse.sh` runs all of this on fresh Ubuntu 24.04 VMs, next to a stand-in
  legacy app and on a clean server. It:
  - provisions twice (the second run must change nothing);
  - deploys through the real SSH path;
  - signs up with an invite and verifies the email;
  - deploys broken releases, a truncated upload and concurrent deploys;
  - tries shell and forwarding with the key;
  - pushes a broken nginx template;
  - breaks each isolation guarantee once, to prove its check fails.

  The legacy app's process IDs must not change, which proves nothing restarted it.

## Consequences
- The legacy app and FeedGenix share only the kernel, nginx and systemd. Removing FeedGenix means
  removing its units, vhost, user and directories (ops/README.md).
- Release code also runs outside the sandbox: the receiver runs migrations and `optimize`, and
  administrators run `feedgenix-artisan`. That code is kept away from the legacy app by file
  permissions, which `feedgenix-check` tests, and by the receiver's socket-only `.env` guard.
  Running those steps inside a sandboxed unit is a launch-server item; there the legacy app does
  not exist.
- No CSP header yet: the pre-rendered pages carry inline hydration scripts that need hashes. CSP is
  part of the M11 security baseline.
- The beta sets `APP_ENV=production`. It faces the internet with real invited users, so it gets
  the production posture: breached-password checks, and no strict-model exceptions for users to
  hit. "Staging" is only its host name. Strict models stay on in local development and tests.
