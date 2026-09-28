# FeedGenix ops

The scripts that put FeedGenix on a server and deploy it (ADR 0009, ADR 0011). **On the beta VPS
every step that changes something needs the owner's go-ahead at that moment.** The survey and
every `--dry-run` change nothing.

| File | What it does |
|---|---|
| `survey.sh` | **Read-only.** The facts the scripts depend on: ports, nginx, PHP, Supervisor, certificates, the legacy app's permissions. No secrets. |
| `provision.sh` | Idempotent provisioning, `--mode=shared` (the beta VPS, next to the frozen legacy app) or `--mode=dedicated` (a fresh server). **Start with `--dry-run`.** |
| `check.sh` | `feedgenix-check` on the server: isolation and health, read-only. It runs after every provisioning run and deploy. |
| `build-artifact.sh` | The release artifact (production `vendor/`, the SPA build, no tests, no `.env`). CI runs it. |
| `bin/receive` | The deploy receiver: the only command the CI key can run (`deploy`, `rollback`, `status`). |
| `bin/run-artisan`, `bin/feedgenix-artisan` | artisan on the live release, for the units and for administrators. |
| `templates/` | Every file provisioning writes (units, sandbox, nginx, PHP-FPM, Redis, PostgreSQL). |
| `tests/unit.sh` | Helper and template tests (any bash, including Git Bash on a laptop). |
| `tests/rehearse.sh` | The full rehearsal on a fresh Ubuntu 24.04 VM (CI: `.github/workflows/ops.yml`). |

## On the shared beta host

**Added by provisioning:**
- the user `feedgenix` (no password, no sudo, home owned by root) and `/srv/feedgenix`;
- `/etc/feedgenix`, `/usr/local/lib/feedgenix`, `feedgenix-check` and `feedgenix-artisan`;
- PostgreSQL 18 with pgvector from the PGDG repository, on its unix socket only;
- FeedGenix's own PHP-FPM master, Redis, queue worker and scheduler timer, as systemd units in
  `feedgenix.slice` (at most 4 CPUs and 8 GB);
- one nginx vhost for `staging.feedgenix.com` (with two snippets) and a Let's Encrypt certificate.

**Never touched:**
- the legacy app's directory and `.env`;
- its nginx sites, PHP-FPM pool, Supervisor programs, Redis and MySQL;
- root's crontab, the freeze file and the nginx :8443 self-heal drop-in;
- the firewall and SSH.

`feedgenix-check` compares all of these with a fingerprint recorded before the first change.

**Shared things that do change, and how:**
- **nginx:** a graceful reload after `nginx -t` passes. The legacy site must answer afterwards,
  otherwise FeedGenix's files are reverted.
- **Packages:** installed with every service start and restart blocked, so nothing restarts. If apt
  would upgrade or remove any installed package, provisioning stops before installing anything,
  unless `--allow-upgrades` is given after reviewing the list.
- **PHP:** new PHP extensions (such as `pdo_pgsql`) also become available to the system PHP. The
  legacy PHP-FPM loads them only at its next restart, for example after a reboot.
- **Certificate renewal** reloads nginx only after `nginx -t` passes.

## First setup on the beta VPS

**Before any change:**
- rotate the root password and revoke the legacy AI keys;
- add a DNS A record, `staging.feedgenix.com`, pointing to the VPS;
- give the go-ahead for each step below.

**1. Copy the scripts up and survey (read-only):**
```bash
git archive HEAD ops | ssh root@VPS 'rm -rf /root/feedgenix-ops && mkdir -p /root/feedgenix-ops && tar -x -C /root/feedgenix-ops'
ssh root@VPS 'bash /root/feedgenix-ops/ops/survey.sh' > survey.txt
```

Read the survey for:
- ports 80/443 held by nginx;
- the legacy `.env` not readable by others;
- no `Inst … [old version]` lines in the PHP simulation;
- no `AllowUsers` that would shut the `feedgenix` user out of SSH.

**2. Dry run** (changes nothing; prints every file diff and command):
```bash
ssh root@VPS 'bash /root/feedgenix-ops/ops/provision.sh --mode=shared --domain=staging.feedgenix.com \
  --legacy-url=https://LEGACY-DOMAIN/ --email=OPS-EMAIL --dry-run'
```

**3. Provision** (go-ahead): the same command without `--dry-run`. It ends with `feedgenix-check`.
Two warnings are expected until the first deploy: "no release is deployed yet", and the self-signed
certificate if DNS does not point here yet (re-run once it does).

**4. CI deploys:**
1. On a laptop: `ssh-keygen -t ed25519 -N '' -C feedgenix-ci-deploy -f feedgenix-deploy`.
2. Copy `feedgenix-deploy.pub` to the server. Re-run step 3 with
   `--deploy-key=/root/feedgenix-deploy.pub`.
3. In GitHub, under *Settings → Secrets and variables → Actions*, add:

   | Kind | Name | Value |
   |---|---|---|
   | secret | `STAGING_SSH_KEY` | the private key file. Delete the local copy afterwards. |
   | secret | `STAGING_KNOWN_HOSTS` | the output of `ssh-keyscan -t ed25519 HOST`. **Check it** against the fingerprint of the server's `/etc/ssh/ssh_host_ed25519_key.pub`. |
   | variable | `STAGING_HOST` | the same host as in `known_hosts` |
   | variable | `STAGING_SSH_PORT` | only if SSH is not on 22; `known_hosts` then uses `[host]:port` |
   | variable | `STAGING_DEPLOY` | `enabled` |

4. Every push to `main` that passes CI now deploys. You can also start one by hand: *Actions →
   Deploy · staging → Run workflow*. The same menu has `rollback`.

**5. Check:** `feedgenix-check` on the server shows all PASS. Then the demo: invite, sign up, verify,
workspace.

## Legacy permissions
If `feedgenix-check` reports that the `feedgenix` user can read or list the legacy app, fix it
**with the owner's go-ahead**. First check who owns the files:
```bash
stat -c '%A %U:%G %n' /var/www/feed-intelligence /var/www/feed-intelligence/.env
```
If the owner or group is the web user (`www-data`), nginx and PHP keep their access, and:
```bash
chmod o-rwx /var/www/feed-intelligence
chmod 640 /var/www/feed-intelligence/.env
```
Then run `feedgenix-check` again. The FeedGenix services cannot see the directory either way (the
sandbox); this closes the gap for code running outside them: deploy-time migrations and
`feedgenix-artisan`.

## Everyday
- **State:** `feedgenix-check` (as root), or `ssh -i feedgenix-deploy feedgenix@HOST status`.
- **Beta invites:** `feedgenix-artisan invites:create --email=someone@example.com --note="Alpine Shop"`
- **Plans:** `feedgenix-artisan tenants:set-plan TENANT pro`
- **Emails until a mail provider is set up** (verification, password reset): they are written to
  the log.
  `grep -h 'verify-email\|reset-password' /srv/feedgenix/shared/storage/logs/laravel-*.log | tail`
- **Logs:**
  - the app: `/srv/feedgenix/shared/storage/logs/`
  - deploys: `/srv/feedgenix/shared/logs/deploys.log`
  - services: `journalctl -u 'feedgenix-*'`
  - nginx: `/var/log/nginx/feedgenix.*.log`
- **After editing `/srv/feedgenix/shared/.env`:**
  `feedgenix-artisan optimize && systemctl reload feedgenix-php && feedgenix-artisan queue:restart`
- **Roll back:** *Actions → Deploy · staging → rollback*, or
  `ssh -i feedgenix-deploy feedgenix@HOST rollback`. Migrations are additive, so a rollback never
  needs to undo one.
- **Manual deploy** (Linux, WSL or CI, with PHP 8.4, Composer 2 and Node 24):
  ```bash
  a=$(ops/build-artifact.sh dist)
  ssh -i feedgenix-deploy feedgenix@HOST "deploy $(git rev-parse HEAD) $(cat "$a.sha256")" < "$a"
  ```

## Removing FeedGenix from the shared host
```bash
systemctl disable --now feedgenix-schedule.timer feedgenix-worker@default feedgenix-php feedgenix-redis
rm /etc/nginx/sites-enabled/feedgenix.conf && nginx -t && systemctl reload nginx
rm -r /etc/systemd/system/feedgenix* /etc/systemd/system/feedgenix-.service.d \
  /etc/systemd/system/postgresql@18-main.service.d/10-feedgenix.conf /etc/tmpfiles.d/feedgenix.conf
systemctl daemon-reload
pg_dropcluster --stop 18 main    # deletes the FeedGenix database: back it up first
rm -r /srv/feedgenix /etc/feedgenix /usr/local/lib/feedgenix /var/lib/feedgenix-redis /var/lib/feedgenix-acme \
  /var/lib/feedgenix-ops /etc/nginx/sites-available/feedgenix.conf /etc/nginx/snippets/feedgenix-*.conf \
  /usr/local/bin/feedgenix-artisan /usr/local/sbin/feedgenix-check
userdel feedgenix && rm -r /home/feedgenix
```

## How this is tested
- **`ops/tests/unit.sh`**, anywhere: the apt guard, template rendering, legacy liveness and port
  detection.
- **`ops/tests/rehearse.sh`**, in CI on fresh Ubuntu 24.04 VMs, next to a stand-in legacy app
  (`tests/fake-legacy.sh`) and on a clean server:
  - It provisions twice; the second run must change nothing.
  - It deploys through the real SSH path.
  - It signs up with an invite, verifies the email and opens the workspace.
  - It tries a release that fails its smoke test, one whose code does not load, a truncated upload,
    concurrent deploys, shell and forwarding with the key, and a broken nginx template.
  - It breaks each isolation guarantee once, to see its check fail.
  - The legacy app's processes must keep their PIDs throughout.
