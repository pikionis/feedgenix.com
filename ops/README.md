# FeedGenix ops

The scripts that put FeedGenix on a server and deploy it (ADR 0009, ADR 0011). **On the beta VPS
every step that changes something needs the owner's go-ahead at that moment.** The survey and
every `--dry-run` change nothing.

| File | What it does |
|---|---|
| `survey.sh` | **Read-only.** The facts the scripts depend on: ports, nginx, PHP and the modules FeedGenix still needs, unfinished dpkg packages, Supervisor, certificates, the legacy app's permissions. No secrets. |
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
- `/etc/sudoers.d/zz-feedgenix` (`feedgenix ALL=(ALL:ALL) !ALL`): even a rule another package grants
  to every user (CloudPanel's wrapper) does not apply to `feedgenix`. Checked with `visudo` before
  and after; removed again if sudoers would stop parsing;
- `/etc/feedgenix`, `/usr/local/lib/feedgenix`, `feedgenix-check` and `feedgenix-artisan`;
- PostgreSQL 18 with pgvector from the PGDG repository, on its unix socket only;
- FeedGenix's own PHP-FPM master, Redis, queue worker and scheduler timer, as systemd units in
  `feedgenix.slice` (at most 4 CPUs and 8 GB);
- one nginx vhost for the site's name, `feedgenix.com` (ADR 0029), and the names that redirect to it
  (`www.feedgenix.com`, `staging.feedgenix.com`), with two snippets and one Let's Encrypt certificate
  for all of them.

**Never touched:**
- the legacy app's directory and `.env`;
- its nginx sites, PHP-FPM pool, Supervisor programs, Redis and MySQL;
- root's crontab, the freeze file and the nginx :8443 self-heal drop-in;
- the firewall and SSH.

`feedgenix-check` compares all of these with a fingerprint recorded before the first change.
Inside the app, the staff page `/admin/work` says whether the reconciler ran in the last five
minutes (a silent one means the scheduler timer stopped), how many jobs wait in the queue, the
failed jobs of the last day and the runs that do not move.

**Shared things that do change, and how:**
- **nginx:** a graceful reload after `nginx -t` passes. The legacy site must answer afterwards,
  otherwise FeedGenix's files are reverted.
- **Packages:** installed with every service start and restart blocked (`policy-rc.d`), so no
  package's own service restarts. If apt would upgrade or remove any installed package, provisioning
  stops before installing anything, unless `--allow-upgrades` is given after reviewing the list.
- **Unfinished packages:** `policy-rc.d` cannot stop a maintainer script that calls `systemctl`
  itself. A package dpkg left unfinished (half-configured, unpacked…) has its script re-run by the
  next apt install of anything. So provisioning (both modes) refuses to run apt until each one is
  named with `--allow-unfinished=PKG` after reading its script. Then apt's error from that package
  is tolerated, and nothing else's: every FeedGenix package must be installed and no other package
  may be left unfinished.
- **PHP:** only the modules the PHP CLI or PHP-FPM does not load yet are installed (by module, not by
  package name: a control panel's PHP may contain a module without Ubuntu's package for it). New
  extensions (such as `pdo_pgsql`) also become available to the system PHP. The legacy PHP-FPM loads
  them only at its next restart, for example after a reboot.
- **Certificate renewal** reloads nginx only after `nginx -t` passes.

## First setup on the beta VPS

**Before any change:**
- rotate the root password and revoke the legacy AI keys;
- add a DNS A record, `staging.feedgenix.com`, pointing to the VPS;
- give the go-ahead for each step below.

**1. Survey (read-only; streamed, so not even the script is written to the server):**
```bash
ssh root@VPS 'bash -s' < ops/survey.sh > survey.txt
```

Read the survey for:
- ports 80/443 held by nginx;
- the legacy `.env` not readable by others, and the user its PHP runs as (the vhost's
  `fastcgi_pass`, then that pool's `user`);
- no `Inst … [old version]` and no `Remv` lines in the PHP simulation;
- `Conf` lines for other packages, and the "unfinished packages" section: read each one's
  `/var/lib/dpkg/info/PKG.postinst` before allowing it (see "The beta VPS" below);
- no `AllowUsers` that would shut the `feedgenix` user out of SSH.

**2. Copy the scripts up, then dry run** (changes nothing; prints every file diff and command):
```bash
git archive HEAD ops | ssh root@VPS 'rm -rf /root/feedgenix-ops && mkdir -p /root/feedgenix-ops && tar -x -C /root/feedgenix-ops'
ssh root@VPS 'bash /root/feedgenix-ops/ops/provision.sh --mode=shared --domain=staging.feedgenix.com \
  --legacy-url=https://LEGACY-DOMAIN/ [--allow-unfinished=PKG] --dry-run'
```
`--email` is needed only when certbot has no account on the host yet.

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

## The beta VPS (survey of 2026-10-02)
- **A CloudPanel host.** nginx, PHP 7.1–8.5 and Varnish come from CloudPanel's apt repository
  (`+clp-noble`). Its PHP 8.4 already loads every module FeedGenix needs except `pdo_pgsql`; the
  repository has `php8.4-pgsql`, and `php8.4-redis` does not exist there. nginx workers run as root.
- **The `cloudpanel` package has been half-configured since 2026-07-29.** Every apt install re-runs
  its postinst. Because CloudPanel is installed (`/home/clp/htdocs/app/files` exists), the script
  takes its update branch, which fails at its first command (a missing `/tmp/cloudpanel/…` file),
  before it restarts anything. Hostinger's weekly `/etc/cron.d/monarx-update` (`apt-get install
  monarx-*`, Fridays 19:43 UTC) has re-run it that way every week. Provisioning therefore needs
  `--allow-unfinished=cloudpanel`, and apt ends with that package's error, which is tolerated.
  Check the survey's "unfinished packages" section again before every provisioning run: if
  `/tmp/cloudpanel` ever exists, the update branch would stop and restart CloudPanel's own services.
- **The legacy app** runs on the system PHP 8.4-FPM pool `www-data` (127.0.0.1:19000), and its
  Supervisor workers run as `www-data`. Its `.env` belonged to another user (`user`) with mode 0664,
  readable by everyone. **Locked on 2026-10-02** with the owner's go-ahead ("Legacy permissions",
  second case):
  - The directory is `750` (group `www-data`); the `.env` is `640 user:www-data`.
  - `www-data` kept every access it had (9 of 9 checked), and the site answered 200.
  - Other users can no longer read or list either. That includes `user`, which no legacy process
    runs as.
  - To undo:
    `chown 197609:197121 /var/www/feed-intelligence && chmod 755 /var/www/feed-intelligence && chown 1002:1004 /var/www/feed-intelligence/.env && chmod 664 /var/www/feed-intelligence/.env`
- MySQL keeps its data in `/home/mysql`. The FeedGenix sandbox hides `/home` entirely (`ProtectHome`).
- **CloudPanel's sudoers lets every local user run `/usr/bin/clpctlWrapper` as root** (`ALL ALL=(ALL)
  NOPASSWD: …`). The wrapper runs any `clpctl` command as `clp`, and `clp` has `NOPASSWD: ALL`. So
  any account on the host can administer CloudPanel. FeedGenix's services cannot use sudo anyway
  (`NoNewPrivileges`); `/etc/sudoers.d/zz-feedgenix` also shuts out code that runs as `feedgenix`
  outside them (deploy-time migrations, `feedgenix-artisan`). Narrowing the rule for every other
  account is the owner's decision, outside FeedGenix.
- **CloudPanel's `/etc/nginx/fastcgi_params` sets `fastcgi_buffers`**, so FeedGenix's PHP snippet
  carries its own FastCGI parameters instead of including that file.

## Legacy permissions
If `feedgenix-check` reports that the `feedgenix` user can read or list the legacy app, fix it
**with the owner's go-ahead**. First check who owns the files and which user the legacy PHP runs as
(the vhost's `fastcgi_pass`, then that pool's `user =`):
```bash
stat -c '%A %U:%G %n' /var/www/feed-intelligence /var/www/feed-intelligence/.env
```
- **If the owner or group is the web user (`www-data`):** nginx and PHP keep their access, and:
  ```bash
  chmod o-rwx /var/www/feed-intelligence
  chmod 640 /var/www/feed-intelligence/.env
  ```
- **If the files belong to another user and the web user reads them only as "other"** (the beta VPS:
  `.env` is `user:user` 0664, and the directory belongs to an unnamed uid): give the web user's group
  read access, then take it away from everyone else:
  ```bash
  stat -c '%a %u:%g %n' /var/www/feed-intelligence /var/www/feed-intelligence/.env   # note them to undo
  chgrp www-data /var/www/feed-intelligence /var/www/feed-intelligence/.env
  chmod 750 /var/www/feed-intelligence
  chmod 640 /var/www/feed-intelligence/.env
  runuser -u www-data -- test -r /var/www/feed-intelligence/.env && echo "www-data can still read it"
  ```
  Then the legacy site must still answer. To undo, restore the noted modes and owners with `chmod`
  and `chown`.

Then run `feedgenix-check` again. The FeedGenix services cannot see the directory either way (the
sandbox); this closes the gap for code running outside them: deploy-time migrations and
`feedgenix-artisan`.

## Everyday
- **State:** `feedgenix-check` (as root), or `ssh -i feedgenix-deploy feedgenix@HOST status`.
- **Beta invites:** `feedgenix-artisan invites:create --email=someone@example.com --note="Alpine Shop"`
- **Who may sign up:** `FEEDGENIX_REGISTRATION` in `/srv/feedgenix/shared/.env`: `invite` (the
  private beta, the default), `open` (the launch) or `closed`. The sign-up page asks
  `/api/auth/options` and shows the invite field only in `invite` mode. Open sign-up only once a
  mail provider sends the verification emails; until then they go to the mail log.
- **Plans:** `feedgenix-artisan tenants:set-plan TENANT pro`
- **AI budget for a beta workspace** (ADR 0016): `feedgenix-artisan credits:grant TENANT 10 --note="beta budget"`
  adds €10 of credits (100,000). Every model call reserves from that wallet first; nothing is
  called without it.
- **AI on or off:** `FEEDGENIX_AI_ENABLED` in `/srv/feedgenix/shared/.env` (off by default; the kill
  switch refuses every model call). Turn it on only with the provider keys set and every routed
  model's prices confirmed in `config/ai.php`.
- **Emails until a mail provider is set up** (verification, password reset): they are written to
  the log.
  `grep -h 'verify-email\|reset-password' /srv/feedgenix/shared/storage/logs/mail-*.log | tail`
  (their own `mail` channel: the log mailer writes at debug level, which `LOG_LEVEL=info` would drop)
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

## Moving the site to another name (ADR 0029)
`--domain` is the site's own name; `--redirect-from` lists the names that answer with a `301` to it,
path and query kept. A re-run with other names moves the site: the vhost, one certificate for every
name, and the three `.env` keys that name the site (`APP_URL`, `FRONTEND_URL`,
`SANCTUM_STATEFUL_DOMAINS`; nothing else in `.env` changes). Then the live release's cached
configuration is rebuilt and PHP-FPM and the worker restart.

**`staging.feedgenix.com` → `feedgenix.com`:**
1. **DNS** (Hostinger, *Domains → feedgenix.com → DNS records*): the `A` record for `@` points to the
   VPS, `69.62.116.163`, instead of the parking address. `www` stays a `CNAME` to `feedgenix.com`.
   There is no `AAAA` record, unless it is the VPS's own IPv6 address.
2. **Check from the laptop:** `nslookup feedgenix.com` and `nslookup www.feedgenix.com` both answer
   `69.62.116.163`.
3. **Copy the scripts up and do a dry run** (go-ahead). If DNS is not ready, it stops and changes nothing:
   ```bash
   git archive HEAD ops | ssh root@VPS 'rm -rf /root/feedgenix-ops && mkdir -p /root/feedgenix-ops && tar -x -C /root/feedgenix-ops'
   ssh root@VPS 'bash /root/feedgenix-ops/ops/provision.sh --mode=shared --domain=feedgenix.com \
     --redirect-from=www.feedgenix.com,staging.feedgenix.com --legacy-url=https://feed-intel.site/ \
     --allow-unfinished=cloudpanel --dry-run'
   ```
4. **Provision** (go-ahead): the same command without `--dry-run`. It ends with `feedgenix-check`,
   which now checks each redirect too.
5. **Sign in again** at `https://feedgenix.com`. Sessions are per host.

If the VPS's own resolver still holds the old address, the run reports that DNS does not point here.
Wait out the record's TTL, or, once `nslookup` from outside agrees, add `--skip-dns-check`.

**Back to `staging.feedgenix.com`:** the same command with `--domain=staging.feedgenix.com` and no
`--redirect-from`. Its old certificate is still there.

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
  /usr/local/bin/feedgenix-artisan /usr/local/sbin/feedgenix-check /etc/sudoers.d/zz-feedgenix
userdel feedgenix && rm -r /home/feedgenix
```

## How this is tested
- **`ops/tests/unit.sh`**, anywhere: the apt guards (upgrades and removals, unfinished packages, the
  `--allow-unfinished` match), the PHP packages chosen by missing module, template rendering, legacy
  liveness and port detection. Also the move to another name: the `.env` keys (every other line byte
  for byte), certificate names matched exactly, and DNS that is not ready (an IPv4 or AAAA record
  still parked elsewhere).
- **`ops/tests/rehearse.sh`**, in CI on fresh Ubuntu 24.04 VMs, next to a stand-in legacy app
  (`tests/fake-legacy.sh`) and on a clean server:
  - Next to a half-configured package whose postinst always fails, it refuses to run apt (dry run
    and real run) until the package is allowed by name, then tolerates only that package's failure.
  - It provisions twice; the second run must change nothing and run no apt at all.
  - It deploys through the real SSH path.
  - It signs up with an invite, verifies the email and opens the workspace.
  - It tries a release that fails its smoke test, one whose code does not load, a truncated upload,
    concurrent deploys, shell and forwarding with the key, and a broken nginx template.
  - It moves the site to a new name and back. The old names must redirect (path kept, valid
    certificate). Sign-in must hold its session on the new name, the smoke test of a deploy must
    pass there, and a second run must change nothing.
  - It breaks each isolation guarantee once, to see its check fail.
  - The legacy app's processes must keep their PIDs throughout.
