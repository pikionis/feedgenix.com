#!/usr/bin/env bash
# CI only: the adversarial rehearsal of ops/ on a fresh Ubuntu 24.04 VM (ADR 0011).
#   shared     next to ops/tests/fake-legacy.sh: nothing of the legacy app may change, not even the
#              PID of one of its processes
#   dedicated  a clean server, with host hardening
# It provisions twice (the second run must change nothing), deploys the real artifact through the
# real forced-command SSH path, signs up through the API, then tries to break things: a release that
# fails its smoke test, a release whose code does not load, a truncated upload, two deploys at once,
# shell and forwarding attempts with the deploy key, a broken nginx template, and every isolation
# check against the fault it exists for.
# Usage: sudo ops/tests/rehearse.sh shared|dedicated DIST_DIR
set -Eeuo pipefail
shopt -s nullglob
on_error() { printf '\nERROR: %s line %s: %s (exit %s)\n' "${0##*/}" "$1" "$3" "$2" >&2; }
trap 'on_error "$LINENO" "$?" "$BASH_COMMAND"' ERR

mode=${1:?usage: rehearse.sh shared|dedicated DIST_DIR}
dist=$(cd "${2:?usage: rehearse.sh shared|dedicated DIST_DIR}" && pwd)
OPS=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
DOMAIN=staging.feedgenix.test
LEGACY=/var/www/feed-intelligence
WORK=$(mktemp -d)
LOG=$WORK/step.log
trap 'rm -rf "$WORK"' EXIT
export DEBIAN_FRONTEND=noninteractive
[[ $EUID -eq 0 ]] || {
  echo "run with sudo" >&2
  exit 1
}

count=0
ok() {
  count=$((count + 1))
  printf '  ✓ %s\n' "$*"
}
bad() {
  printf '  ✗ %s\n' "$*"
  exit 1
}
section() { printf '\n== %s\n' "$*"; }
expect_ok() { # LABEL COMMAND…
  local label=$1
  shift
  if "$@" >"$LOG" 2>&1; then ok "$label"; else
    cat "$LOG"
    bad "$label"
  fi
}
expect_fail() { # LABEL COMMAND…
  local label=$1
  shift
  if "$@" >"$LOG" 2>&1; then
    cat "$LOG"
    bad "$label (it succeeded)"
  fi
  ok "$label"
}
expect_output() { # LABEL ERE COMMAND… (the exit status is not checked)
  local label=$1 pattern=$2
  shift 2
  "$@" >"$LOG" 2>&1 || true
  if grep -qE -- "$pattern" "$LOG"; then ok "$label"; else
    cat "$LOG"
    bad "$label (no /$pattern/ in the output)"
  fi
}
wait_until() { # SECONDS COMMAND…
  local deadline=$((SECONDS + $1))
  shift
  until "$@" >/dev/null 2>&1; do
    ((SECONDS < deadline)) || return 1
    sleep 1
  done
}

provision() {
  local -a args=(--mode="$mode" --domain="$DOMAIN" --tls=self-signed --deploy-key="$WORK/deploy.pub")
  if [[ $mode == shared ]]; then args+=(--legacy-url=https://legacy.test/); fi
  "$OPS/provision.sh" "${args[@]}" "$@"
}
receiver() {
  ssh -i "$WORK/deploy" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
    -o UserKnownHostsFile="$WORK/known_hosts" feedgenix@127.0.0.1 "$@"
}
deploy() { receiver "deploy $2 $(sha256sum <"$1" | cut -c1-64)" <"$1"; }
curl_site() { curl -s --max-time 10 --cacert /etc/feedgenix/tls/cert.pem --resolve "$DOMAIN:443:127.0.0.1" "$@"; }
status_of() { curl_site -o /dev/null -w '%{http_code}' "https://$DOMAIN$1"; }
healthy() { [[ $(curl_site "https://$DOMAIN/api/health") == *'"status":"ok"'* ]]; }
live() { readlink -f /srv/feedgenix/current; }
release_count() { find /srv/feedgenix/releases -mindepth 1 -maxdepth 1 -type d | wc -l; }
worker_on_live() {
  local pid
  pid=$(systemctl show --property=MainPID --value feedgenix-worker@default.service)
  [[ $pid != 0 && $(readlink -f "/proc/$pid/cwd") == "$(live)" ]]
}
fake_revision() { printf '%s%.0s' "$1" {1..40}; }

# A package dpkg left half-configured, like the beta VPS's control panel (cloudpanel, since July
# 2026): its postinst fails every time and counts its runs. Any apt install would run it again, so
# provisioning must refuse until it is allowed by name, then tolerate its failure and nothing else's.
PANEL_RUNS=/var/lib/fake-panel-runs
make_unfinished_package() {
  local dir=$WORK/fake-panel
  install -d "$dir/DEBIAN"
  printf 'Package: fake-panel\nVersion: 1.0\nArchitecture: all\nMaintainer: FeedGenix rehearsal <ops@feedgenix.test>\nDescription: stand-in for a control panel whose postinst always fails\n' \
    >"$dir/DEBIAN/control"
  printf '#!/bin/sh\necho "postinst $*" >>%s\nexit 1\n' "$PANEL_RUNS" >"$dir/DEBIAN/postinst"
  chmod 0755 "$dir/DEBIAN/postinst"
  dpkg-deb --build --root-owner-group "$dir" "$WORK/fake-panel.deb" >/dev/null
  dpkg -i "$WORK/fake-panel.deb" >/dev/null 2>&1 || true
  [[ $(dpkg-query -W -f='${Status}' fake-panel) == 'install ok half-configured' ]]
}
panel_runs() { if [[ -f $PANEL_RUNS ]]; then wc -l <"$PANEL_RUNS"; else echo 0; fi; }
pg_installed() { [[ $(dpkg-query -W -f='${Status}' postgresql-18 2>/dev/null) == 'install ok installed' ]]; }

# repack REVISION [EDIT-FUNCTION] — the tested artifact as another revision, optionally edited.
repack() {
  local revision=$1 edit=${2:-} dir
  dir=$(mktemp -d -p "$WORK")
  tar -xzf "$artifact" -C "$dir"
  printf '%s\n' "$revision" >"$dir/REVISION"
  if [[ -n $edit ]]; then (cd "$dir" && "$edit"); fi
  tar -C "$dir" -czf "$WORK/$revision.tar.gz" .
  printf '%s\n' "$WORK/$revision.tar.gz"
}
break_health() {
  cat >app/Http/Controllers/Public/HealthController.php <<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

final class HealthController
{
    public function __invoke(): never
    {
        throw new \RuntimeException('rehearsal: a release that fails its smoke test');
    }
}
PHP
}
break_code() { printf '<?php this is not PHP\n' >routes/api.php; }

# Everything about the legacy app that must stay identical, down to the PID of every process:
# a changed PID means something restarted it.
legacy_state() {
  printf 'site=%s\n' "$(curl -sk --max-time 10 --resolve legacy.test:443:127.0.0.1 https://legacy.test/)"
  printf 'worker=%s\n' "$(supervisorctl status legacy-worker | awk '{ print $2 }')"
  printf 'pause-flag=%s\n' "$(redis-cli get legacy:pipeline:paused)"
  printf 'freeze=%s\n' "$([[ -f $LEGACY/storage/app/PIPELINE_FROZEN ]] && echo present)"
  printf 'vhost=%s\n' "$(sha256sum </etc/nginx/sites-available/legacy)"
  local unit
  for unit in nginx php8.4-fpm redis-server supervisor; do
    printf '%s-pid=%s\n' "$unit" "$(systemctl show --property=MainPID --value "$unit")"
  done
}

section "Setup ($mode mode)"
artifact=$(find "$dist" -maxdepth 1 -name 'feedgenix-*.tar.gz' | head -n 1)
[[ -f $artifact ]] || bad "no artifact in $dist"
revision=$(basename "$artifact" .tar.gz)
revision=${revision#feedgenix-}
[[ $revision =~ ^[0-9a-f]{40}$ ]] || bad "artifact name has no revision: $artifact"
apt-get install -y -q openssh-server >/dev/null
systemctl start ssh
ssh-keygen -q -t ed25519 -N '' -C rehearsal-deploy -f "$WORK/deploy"
ssh-keyscan -t ed25519 127.0.0.1 2>/dev/null >"$WORK/known_hosts"
if [[ $mode == dedicated ]]; then
  systemctl disable --now apache2 >/dev/null 2>&1 || true
  # The key-only SSH step needs an administrator key first (it never locks the admin out).
  install -d -m 0700 /root/.ssh
  cat "$WORK/deploy.pub" >>/root/.ssh/authorized_keys
else
  legacy_before=$(legacy_state)
fi
ok "artifact $revision, sshd, a deploy key"
dry_extra=()
if [[ $mode == shared ]]; then
  expect_ok "a half-configured package (fake-panel), like the VPS's control panel" make_unfinished_package
  runs_before=$(panel_runs)
fi

section "Dry run: changes nothing"
if [[ $mode == shared ]]; then
  expect_output "with a package unfinished the dry run refuses and names the flag" \
    'dpkg left these packages unfinished: fake-panel\. .*--allow-unfinished=fake-panel\. Nothing was installed' \
    provision --dry-run
  dry_extra=(--allow-unfinished=fake-panel)
fi
# In shared mode the guard may refuse because apt would upgrade a package the runner image already
# has; both outcomes are correct, anything else is not.
if provision --dry-run "${dry_extra[@]}" >"$LOG" 2>&1; then
  grep -q 'would be made. Nothing was changed.' "$LOG" || bad "the dry run did not summarise"
  ok "the dry run completes"
elif grep -q 'would UPGRADE installed packages' "$LOG"; then
  ok "the dry run stops at the upgrade guard (it lists: $(grep -A3 'apt would also' "$LOG" | tail -n +2 | xargs))"
else
  cat "$LOG"
  bad "the dry run failed"
fi
expect_fail "no feedgenix user" id feedgenix
expect_ok "no /srv/feedgenix, no /etc/feedgenix, no vhost, no policy-rc.d left" \
  bash -c '[[ ! -e /srv/feedgenix && ! -e /etc/feedgenix && ! -e /etc/nginx/sites-available/feedgenix.conf && ! -e /usr/sbin/policy-rc.d ]]'
if [[ $mode == shared ]]; then
  expect_ok "the legacy app is exactly as before" test "$(legacy_state)" == "$legacy_before"
  expect_ok "the unfinished package's postinst never ran" test "$(panel_runs)" == "$runs_before"
fi

section "Provision"
extra=()
if [[ $mode == shared ]]; then
  expect_output "provisioning refuses while a package is unfinished" \
    'dpkg left these packages unfinished: fake-panel' provision --allow-upgrades
  expect_fail "…and installs nothing" pg_installed
  expect_ok "…runs no maintainer script and leaves no policy-rc.d" \
    bash -c "[[ $(panel_runs) == $runs_before && ! -e /usr/sbin/policy-rc.d ]]"
  extra=(--allow-upgrades --allow-unfinished=fake-panel)
fi
expect_ok "provision.sh --mode=$mode" provision "${extra[@]}"
expect_ok "no policy-rc.d is left behind" test ! -e /usr/sbin/policy-rc.d
if [[ $mode == shared ]]; then
  expect_ok "apt re-ran the allowed package's postinst; it failed again and was tolerated" \
    bash -c "(( $(panel_runs) > $runs_before )) && [[ \$(dpkg-query -W -f='\${Status}' fake-panel) == 'install ok half-configured' ]]"
  runs_after=$(panel_runs)
fi
provision "${extra[@]}" >"$LOG" 2>&1 || {
  cat "$LOG"
  bad "the second run failed"
}
if grep -qx '   0 change(s)' "$LOG"; then ok "a second run changes nothing"; else
  cat "$LOG"
  bad "the second run changed something"
fi
if [[ $mode == shared ]]; then
  expect_ok "a converged host runs no apt at all (the unfinished package's postinst did not run again)" \
    test "$(panel_runs)" == "$runs_after"
fi
if [[ $mode == shared ]]; then
  expect_ok "the legacy app is exactly as before: same processes (none restarted), site, worker, flag, freeze file" \
    test "$(legacy_state)" == "$legacy_before"
fi

section "Deploy through the forced-command SSH key"
expect_ok "status before any release" receiver status
expect_ok "deploy $revision" deploy "$artifact" "$revision"
expect_ok "the health route answers through nginx" healthy
expect_ok "GET / is the release's pre-rendered home page" \
  test "$(curl_site "https://$DOMAIN/" | sha256sum)" == "$(tar -xzOf "$artifact" ./frontend/build/client/index.html | sha256sum)"
expect_ok "an app route gets the SPA shell" test "$(status_of /app/settings)" == 200
expect_ok "a missing asset is a 404, not the SPA shell" test "$(status_of /assets/missing.js)" == 404
expect_output "responses carry HSTS" '^strict-transport-security: max-age=31536000' curl_site -I "https://$DOMAIN/"
if [[ $mode == shared ]]; then
  expect_output "the beta asks search engines to stay away" '^x-robots-tag: noindex' curl_site -I "https://$DOMAIN/"
fi
expect_ok "the queue worker starts on the live release" wait_until 45 worker_on_live
expect_fail "a rollback with a single release is refused" receiver rollback
expect_ok "feedgenix-check passes" feedgenix-check

section "Sign up with an invite, verify the address, open the workspace"
invite=$(feedgenix-artisan invites:create --note=rehearsal | sed -n 's/^Invite code: *//p')
[[ -n $invite ]] || bad "invites:create printed no code"
jar=$WORK/cookies
api() { curl_site -b "$jar" -c "$jar" -H "Referer: https://$DOMAIN/" -H 'Accept: application/json' "$@"; }
api -o /dev/null "https://$DOMAIN/sanctum/csrf-cookie"
xsrf=$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$jar" | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))')
password="rehearsal-$(openssl rand -hex 12)"
code=$(api -o "$WORK/register.json" -w '%{http_code}' -H "X-XSRF-TOKEN: $xsrf" -H 'Content-Type: application/json' \
  --data "{\"name\":\"Rehearsal\",\"email\":\"rehearsal@example.com\",\"password\":\"$password\",\"password_confirmation\":\"$password\",\"invite_code\":\"$invite\"}" \
  "https://$DOMAIN/api/auth/register")
if [[ $code == 201 ]]; then ok "registered with the invite (HTTP 201)"; else
  cat "$WORK/register.json"
  bad "register answered HTTP $code"
fi
verification_url() {
  # The log mailer writes the whole message; undo quoted-printable soft breaks and =3D, then &amp;.
  sed -e ':a' -e '/=\r\?$/{N;s/=\r\?\n//;ba}' /srv/feedgenix/shared/storage/logs/laravel-*.log 2>/dev/null |
    sed -e 's/=3D/=/g' -e 's/&amp;/\&/g' | grep -o "https://$DOMAIN/api/auth/verify-email/[^\"' <>)]*" | tail -n 1
}
has_verification_url() { [[ -n $(verification_url) ]]; }
wait_until 20 has_verification_url || bad "no verification link in the mail log"
code=$(api -o /dev/null -w '%{http_code}' "$(verification_url)")
if [[ $code =~ ^(2|3)[0-9][0-9]$ ]]; then ok "the verification link works (HTTP $code)"; else bad "the verification link answered HTTP $code"; fi
expect_output "the account is verified" '"email_verified":true' api "https://$DOMAIN/api/v1/auth/user"
expect_output "the workspace opens on the Free plan" '"key":"free"' api "https://$DOMAIN/api/v1/workspace"

section "A second release, then a rollback"
first=$(live)
worker_before=$(systemctl show --property=MainPID --value feedgenix-worker@default.service)
rev2=$(fake_revision b)
art2=$(repack "$rev2")
expect_ok "deploy a second release" deploy "$art2" "$rev2"
expect_ok "it is live" test "$(cat "$(live)/REVISION")" == "$rev2"
expect_ok "the worker restarted onto it" wait_until 45 bash -c \
  "[[ \$(systemctl show --property=MainPID --value feedgenix-worker@default.service) != $worker_before ]] && [[ \$(readlink -f /proc/\$(systemctl show --property=MainPID --value feedgenix-worker@default.service)/cwd) == $(live) ]]"
expect_output "status lists both releases and the live one" "^\* [0-9]{14}-bbbbbbbbbbbb" receiver status
expect_ok "rollback" receiver rollback
expect_ok "the first release is live again" test "$(live)" == "$first"
expect_ok "and healthy" healthy

section "Releases that must never go live"
before=$(live)
releases_before=$(release_count)
art3=$(repack "$(fake_revision c)" break_health)
if deploy "$art3" "$(fake_revision c)" >"$LOG" 2>&1; then
  cat "$LOG"
  bad "a release that fails its smoke test was accepted"
fi
if grep -q 'rolled back: .* is live again' "$LOG"; then ok "a release that fails its smoke test is refused and switched back"; else
  cat "$LOG"
  bad "the failed release was not switched back"
fi
expect_ok "the previous release is live and healthy" bash -c "[[ \$(readlink -f /srv/feedgenix/current) == $before ]] && curl -s --cacert /etc/feedgenix/tls/cert.pem --resolve $DOMAIN:443:127.0.0.1 https://$DOMAIN/api/health | grep -q '\"status\":\"ok\"'"
art4=$(repack "$(fake_revision d)" break_code)
expect_output "a release whose code does not load never goes live" '(migrations|caching) failed; the live release is unchanged' deploy "$art4" "$(fake_revision d)"
expect_output "a truncated upload is refused" 'checksum mismatch' bash -c \
  "head -c 200000 '$artifact' | ssh -i '$WORK/deploy' -o BatchMode=yes -o IdentitiesOnly=yes -o UserKnownHostsFile='$WORK/known_hosts' feedgenix@127.0.0.1 'deploy $(fake_revision e) $(sha256sum <"$artifact" | cut -c1-64)'"
expect_ok "none of them left a release, an upload or a switch behind" \
  bash -c "[[ \$(readlink -f /srv/feedgenix/current) == $before && \$(find /srv/feedgenix/releases -mindepth 1 -maxdepth 1 -type d | wc -l) == $releases_before && -z \$(ls -A /srv/feedgenix/shared/incoming) ]]"

section "One deploy at a time"
(sleep 6 && cat "$art2") | receiver "deploy $rev2 $(sha256sum <"$art2" | cut -c1-64)" >"$WORK/slow-deploy.log" 2>&1 &
slow=$!
sleep 2
expect_output "a rollback during a deploy is refused" 'another deploy or rollback is running' receiver rollback
wait "$slow" || {
  cat "$WORK/slow-deploy.log"
  bad "the deploy holding the lock failed"
}
ok "the deploy holding the lock finished"

section "The deploy key can do nothing else"
expect_output "no shell commands" 'unknown command' receiver 'cat /etc/passwd'
expect_fail "…and it fails" receiver id
expect_output "no path tricks" 'REVISION must be a full git sha' receiver "deploy ../../etc/passwd $(fake_revision 0)$(fake_revision 0 | cut -c1-24)"
expect_fail "no remote port forwarding" ssh -i "$WORK/deploy" -o BatchMode=yes -o IdentitiesOnly=yes \
  -o UserKnownHostsFile="$WORK/known_hosts" -o ExitOnForwardFailure=yes -R 16379:127.0.0.1:22 feedgenix@127.0.0.1 status
expect_fail "no TCP forwarding to local services" ssh -i "$WORK/deploy" -o BatchMode=yes -o IdentitiesOnly=yes \
  -o UserKnownHostsFile="$WORK/known_hosts" -W 127.0.0.1:22 feedgenix@127.0.0.1

section "A broken nginx template is reverted, never left on disk"
cp -a "$OPS" "$WORK/ops-broken"
printf 'this_is_not_an_nginx_directive;\n' >>"$WORK/ops-broken/templates/nginx/feedgenix-headers.conf"
broken_args=(--mode="$mode" --domain="$DOMAIN" --tls=self-signed)
if [[ $mode == shared ]]; then broken_args+=(--legacy-url=https://legacy.test/ --allow-upgrades); fi
expect_output "provision.sh stops and reverts" 'nginx -t failed: .* reverted' "$WORK/ops-broken/provision.sh" "${broken_args[@]}"
expect_ok "nginx -t passes" nginx -t
expect_fail "the broken snippet is not on disk" grep -q this_is_not_an_nginx_directive /etc/nginx/snippets/feedgenix-headers.conf
expect_ok "the site still answers" healthy

section "Restarts"
systemctl restart "postgresql@18-main.service" feedgenix-redis.service feedgenix-php.service
expect_ok "healthy again after PostgreSQL, Redis and PHP-FPM restart" wait_until 30 healthy

if [[ $mode == shared ]]; then
  section "Nothing above touched the legacy app"
  expect_ok "same processes (none restarted), site, worker, pause flag, freeze file, vhost" \
    test "$(legacy_state)" == "$legacy_before"

  section "Every isolation check catches the fault it exists for"
  chmod o+rx "$LEGACY"
  chmod o+r "$LEGACY/.env"
  expect_output "a readable legacy .env fails the check" 'FAIL .*can read .*\.env' feedgenix-check
  expect_output "a listable legacy directory fails the check" 'FAIL .*can list' feedgenix-check
  chmod o-rwx "$LEGACY" "$LEGACY/.env"
  expect_ok "fixed: the check passes" feedgenix-check

  cp -p /etc/nginx/sites-available/legacy "$WORK/legacy-vhost"
  printf '# touched\n' >>/etc/nginx/sites-available/legacy
  expect_output "a changed legacy config fails the check" 'FAIL +changed or removed since the baseline: .*sites-available/legacy' feedgenix-check
  cp -p "$WORK/legacy-vhost" /etc/nginx/sites-available/legacy

  supervisorctl start legacy-worker >/dev/null
  expect_output "a running legacy worker fails the check" 'FAIL +legacy workers are running: legacy-worker' feedgenix-check
  supervisorctl stop legacy-worker >/dev/null

  mv "$LEGACY/storage/app/PIPELINE_FROZEN" "$WORK/"
  expect_output "a missing freeze file fails the check" 'FAIL +the freeze file is missing' feedgenix-check
  mv "$WORK/PIPELINE_FROZEN" "$LEGACY/storage/app/"

  systemctl stop php8.4-fpm
  expect_output "a legacy site that stops answering fails the check" 'FAIL +the legacy site does not answer' feedgenix-check
  systemctl start php8.4-fpm
  expect_ok "all restored: the check passes" wait_until 20 feedgenix-check
fi

section "The sandbox checks catch a missing sandbox"
mv /etc/systemd/system/feedgenix-.service.d/10-sandbox.conf "$WORK/sandbox.conf"
systemctl daemon-reload
systemctl restart feedgenix-php.service
expect_output "without the drop-in the check fails" 'FAIL +feedgenix-php.service is missing the sandbox' feedgenix-check
expect_output "…and the live probe reaches this host over TCP" 'FAIL +inside the sandbox: a FeedGenix process can open TCP connections' feedgenix-check
mv "$WORK/sandbox.conf" /etc/systemd/system/feedgenix-.service.d/10-sandbox.conf
systemctl daemon-reload
systemctl restart feedgenix-php.service feedgenix-redis.service feedgenix-worker@default.service
expect_ok "restored: the check passes" wait_until 30 feedgenix-check
expect_ok "and the site is healthy" wait_until 30 healthy

section "Result"
feedgenix-check | tail -n 1
printf '%d rehearsal checks passed (%s mode)\n' "$count" "$mode"
