#!/usr/bin/env bash
# feedgenix-check: FeedGenix isolation and health checks. Read-only. Run as root after every
# provisioning run and every deploy (ADR 0009, ADR 0011). Exits 1 when any check fails.
#   --rebaseline   after the owner deliberately changed the legacy app: record its new fingerprint
set -Eeuo pipefail
shopt -s nullglob
on_error() {
  local src=${BASH_SOURCE[1]:-$0}
  printf '\nERROR: %s line %s: %s (exit %s)\n' "${src##*/}" "$1" "$3" "$2" >&2
}
trap 'on_error "$LINENO" "$?" "$BASH_COMMAND"' ERR

for lib in "$(dirname "$(readlink -f "$0")")/lib/common.sh" /usr/local/lib/feedgenix/common.sh; do
  if [[ -f $lib ]]; then
    # shellcheck source=lib/common.sh
    . "$lib"
    break
  fi
done
[[ $EUID -eq 0 ]] || die "run as root"
[[ -f $FG_ETC/host.conf ]] || die "$FG_ETC/host.conf is missing: provision.sh has not run on this host"
# shellcheck source=/dev/null
. "$FG_ETC/host.conf"
cd /

# Tables that carry tenant_id but are read before any workspace context exists (ADR 0002). The same
# list as RLS_EXEMPT in tests/Feature/Tenancy/RowLevelSecurityTest.php; a Pest test keeps them equal.
RLS_EXEMPT_TABLES=(tenant_user)

PASSED=0
FAILED=0
WARNED=0
pass() {
  PASSED=$((PASSED + 1))
  printf '  PASS  %s\n' "$*"
}
fail() {
  FAILED=$((FAILED + 1))
  printf '  FAIL  %s\n' "$*"
}
advise() {
  WARNED=$((WARNED + 1))
  printf '  WARN  %s\n' "$*"
}
heading() { printf '\n%s\n' "$*"; }

main_pid() {
  local pid
  pid=$(systemctl show --property=MainPID --value "$1" 2>/dev/null || true)
  [[ $pid =~ ^[1-9][0-9]*$ ]] && printf '%s\n' "$pid"
}

# probe COMMAND… — run COMMAND as the app user inside the FeedGenix sandbox: a transient
# feedgenix-probe-* unit picks up the same feedgenix-.service.d drop-in as the real services.
probe() {
  systemd-run --quiet --wait --pipe --collect --unit="feedgenix-probe-$$-$RANDOM" \
    --uid="$FG_USER" --gid="$FG_USER" --working-directory=/ -- "$@" </dev/null
}

pg() { runuser -u postgres -- psql -X -Atq -p "$FG_PG_PORT" "$@" 2>/dev/null; }

# ---- the legacy app --------------------------------------------------------------------------------
check_legacy() {
  [[ $FG_MODE == shared ]] || return 0
  heading "Legacy app: must be untouched"

  if legacy_answers; then
    pass "the legacy site answers (HTTP $LEGACY_STATUS)"
  else
    fail "the legacy site does not answer (HTTP ${LEGACY_STATUS:-none}): $FG_LEGACY_URL"
  fi

  if ! command -v supervisorctl >/dev/null; then
    pass "no Supervisor on this host: no legacy worker can run"
  else
    local status running auto
    status=$(supervisorctl status 2>&1 || true)
    if grep -qE 'no such file|refused connection|SHUTDOWN_STATE' <<<"$status"; then
      pass "supervisord is not running: no legacy worker can run"
    else
      running=$(awk '$1 !~ /^feedgenix/ && $2 ~ /^(RUNNING|STARTING|BACKOFF|STOPPING)$/ { print $1 }' <<<"$status" | paste -sd' ' -)
      if [[ -z $running ]]; then pass "the legacy workers are stopped"; else fail "legacy workers are running: $running"; fi
      auto=$(supervisorctl avail 2>/dev/null | awk '$1 !~ /^feedgenix/ && / auto / { print $1 }' | paste -sd' ' -)
      if [[ -z $auto ]]; then pass "no legacy worker starts at boot (autostart=false)"; else fail "legacy programs start at boot: $auto"; fi
    fi
  fi

  if [[ -f $FG_LEGACY_FREEZE_FILE ]]; then
    pass "the freeze file is present"
  else
    fail "the freeze file is missing: $FG_LEGACY_FREEZE_FILE"
  fi

  if [[ ! -f $FG_BASELINE ]]; then
    fail "no legacy baseline ($FG_BASELINE): provision.sh records it before its first change"
  else
    local drifted
    # Every baseline entry must still be there with the same hash; files added since are ignored.
    drifted=$(comm -23 <(sort "$FG_BASELINE") <(legacy_fingerprint | sort) | awk '{ print $2 }' | paste -sd' ' -)
    if [[ -z $drifted ]]; then
      pass "legacy nginx/PHP/Supervisor/Redis/MySQL configs, crontab, .env and freeze file match the baseline"
    else
      fail "changed or removed since the baseline: $drifted"
    fi
  fi

  if runuser -u "$FG_USER" -- test -r "$FG_LEGACY_DIR/.env" 2>/dev/null; then
    fail "user $FG_USER can read $FG_LEGACY_DIR/.env (the fix needs the owner's go-ahead: ops/README.md, 'Legacy permissions')"
  else
    pass "user $FG_USER cannot read the legacy .env"
  fi
  if runuser -u "$FG_USER" -- ls "$FG_LEGACY_DIR" >/dev/null 2>&1; then
    fail "user $FG_USER can list $FG_LEGACY_DIR (the fix needs the owner's go-ahead: ops/README.md, 'Legacy permissions')"
  else
    pass "user $FG_USER cannot list the legacy app directory"
  fi

  # Inside the running PHP-FPM's own mount namespace the legacy app must not exist at all.
  local pid
  if ! pid=$(main_pid feedgenix-php.service); then
    fail "feedgenix-php is not running: its view of the legacy app cannot be checked"
  elif [[ -z $(find "$FG_LEGACY_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null) ]]; then
    advise "the legacy app directory is empty: the sandbox view proves nothing"
  elif [[ -n $(nsenter --target "$pid" --mount -- find "$FG_LEGACY_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null) ]]; then
    fail "the legacy app directory is visible inside the FeedGenix sandbox"
  else
    pass "inside the FeedGenix sandbox the legacy app directory is empty and inaccessible"
  fi
}

# ---- the app account -------------------------------------------------------------------------------
check_account() {
  heading "The $FG_USER account"
  local groups group bad=()
  groups=$(id -nG "$FG_USER" | tr ' ' '\n')
  for group in sudo admin adm wheel www-data redis mysql postgres docker lxd systemd-journal supervisor; do
    if grep -qx "$group" <<<"$groups"; then bad+=("$group"); fi
  done
  if ((${#bad[@]} == 0)); then pass "not in any privileged group"; else fail "in privileged groups: ${bad[*]}"; fi

  local allowed
  allowed=$(sudo_allowed "$FG_USER" | paste -sd' ' -)
  if [[ -z $allowed ]]; then
    pass "no sudo rights in effect (a rule for every user, like CloudPanel's, is overridden by /etc/sudoers.d/zz-feedgenix)"
  else
    fail "$FG_USER may run as root through sudo: $allowed (provision.sh installs /etc/sudoers.d/zz-feedgenix)"
  fi

  local home=/home/$FG_USER keys=/home/$FG_USER/.ssh/authorized_keys
  if [[ $(stat -c %U "$home" 2>/dev/null) == root && $(stat -c %U "$home/.ssh" 2>/dev/null) == root ]] &&
    ! runuser -u "$FG_USER" -- test -w "$home/.ssh" 2>/dev/null; then
    pass "its home and .ssh belong to root: it cannot change its own keys"
  else
    fail "$home or $home/.ssh is not owned by root, or is writable by $FG_USER"
  fi
  if [[ ! -e $keys ]]; then
    advise "no deploy key installed yet (provision.sh --deploy-key=FILE)"
  else
    local loose
    loose=$(grep -vE '^[[:space:]]*(#|$)' "$keys" | grep -vc "^restrict,command=\"$FG_LIB/receive\" " || true)
    if [[ $(stat -c %U "$keys") == root && $loose == 0 ]]; then
      pass "every deploy key is forced to the receiver (no shell, no PTY, no forwarding)"
    else
      fail "$keys has $loose key(s) without restrict,command=\"$FG_LIB/receive\", or is not owned by root"
    fi
  fi

  local env=$FG_ROOT/shared/.env
  if [[ ! -f $env ]]; then
    fail "$env is missing"
  else
    if [[ $(stat -c '%a %U' "$env") == "600 $FG_USER" ]]; then pass ".env is mode 600, owned by $FG_USER"; else fail ".env is $(stat -c '%a %U' "$env"), not 600 $FG_USER"; fi
    if grep -Eq '^DB_HOST=/' "$env" && grep -Eq '^REDIS_HOST=/' "$env"; then
      pass "the app reaches PostgreSQL and Redis over unix sockets only"
    else
      fail ".env uses TCP for DB_HOST or REDIS_HOST (must be unix sockets)"
    fi
    if grep -Eq '^APP_DEBUG=false' "$env"; then pass "APP_DEBUG=false"; else fail "APP_DEBUG is not false"; fi
    if grep -qx "APP_URL=https://$FG_DOMAIN" "$env" && grep -qx "SANCTUM_STATEFUL_DOMAINS=$FG_DOMAIN" "$env"; then
      pass "APP_URL and SANCTUM_STATEFUL_DOMAINS name $FG_DOMAIN"
    else
      fail "APP_URL or SANCTUM_STATEFUL_DOMAINS does not name $FG_DOMAIN (re-run provision.sh: it keeps them in step)"
    fi
  fi
}

# ---- PostgreSQL, Redis, PHP-FPM, services ----------------------------------------------------------
check_services() {
  heading "Services"
  local unit
  for unit in "postgresql@$FG_PG_VERSION-main.service" "${FG_SERVICES[@]}"; do
    if systemctl is-active --quiet "$unit"; then pass "$unit is active"; else fail "$unit is not active"; fi
  done
  for unit in feedgenix-redis.service feedgenix-php.service feedgenix-worker@default.service "postgresql@$FG_PG_VERSION-main.service"; do
    if [[ $(systemctl show --property=Slice --value "$unit") == feedgenix.slice ]]; then
      pass "$unit runs in feedgenix.slice"
    else
      fail "$unit is not in feedgenix.slice"
    fi
  done
  local pid
  for unit in feedgenix-redis.service feedgenix-php.service; do
    if pid=$(main_pid "$unit") && [[ $(ps -o user= -p "$pid" | tr -d ' ') == "$FG_USER" ]]; then
      pass "$unit runs as $FG_USER"
    else
      fail "$unit does not run as $FG_USER"
    fi
  done
  for unit in feedgenix-php.service feedgenix-redis.service feedgenix-worker@default.service; do
    if [[ -n $(systemctl show --property=IPAddressDeny --value "$unit") &&
      $(systemctl show --property=ProtectSystem --value "$unit") == full &&
      $(systemctl show --property=InaccessiblePaths --value "$unit") == *'/var/www'* ]]; then
      pass "$unit has the sandbox (ProtectSystem, InaccessiblePaths, IPAddressDeny)"
    else
      fail "$unit is missing the sandbox drop-in (feedgenix-.service.d/10-sandbox.conf)"
    fi
  done

  # The sandbox, tested live: our own sockets work from inside, this host's TCP services do not.
  if [[ $(probe /usr/bin/redis-cli -s "$FG_REDIS_SOCKET" ping 2>/dev/null) == PONG ]]; then
    pass "inside the sandbox: FeedGenix's Redis answers on its unix socket"
  else
    fail "inside the sandbox: FeedGenix's Redis does not answer (the probe itself may be broken)"
  fi
  if [[ $(probe /usr/bin/psql -X -Atq -h "$FG_PG_SOCKET_DIR" -p "$FG_PG_PORT" -d "$FG_USER" -c 'select 1' 2>/dev/null) == 1 ]]; then
    pass "inside the sandbox: PostgreSQL answers on its unix socket (peer authentication)"
  else
    fail "inside the sandbox: PostgreSQL does not answer on its unix socket"
  fi
  if ! timeout 5 bash -c 'exec 3<>/dev/tcp/127.0.0.1/80' 2>/dev/null; then
    advise "nothing listens on 127.0.0.1:80, so the loopback filter cannot be tested"
  elif probe /usr/bin/timeout 5 /bin/bash -c 'exec 3<>/dev/tcp/127.0.0.1/80' >/dev/null 2>&1; then
    fail "inside the sandbox: a FeedGenix process can open TCP connections to this host (127.0.0.1:80)"
  else
    pass "inside the sandbox: no TCP to this host's services (127.0.0.1:80 refused)"
  fi

  heading "PostgreSQL"
  if [[ $(pg -c "SELECT rolsuper::text || ',' || rolbypassrls::text || ',' || rolcreaterole::text FROM pg_roles WHERE rolname = '$FG_USER'") == false,false,false ]]; then
    pass "role $FG_USER is NOSUPERUSER NOBYPASSRLS NOCREATEROLE"
  else
    fail "role $FG_USER is missing or has SUPERUSER, BYPASSRLS or CREATEROLE"
  fi
  if [[ -z $(pg -c 'SHOW listen_addresses') ]]; then pass "PostgreSQL listens on its unix socket only"; else fail "PostgreSQL listens on the network: $(pg -c 'SHOW listen_addresses')"; fi
  if [[ $(pg -d "$FG_USER" -c "SELECT 1 FROM pg_extension WHERE extname = 'vector'") == 1 ]]; then pass "pgvector is installed"; else fail "the vector extension is missing"; fi
  local loose_tables exempt
  exempt=$(printf "'%s'," "${RLS_EXEMPT_TABLES[@]}")
  loose_tables=$(pg -d "$FG_USER" -c "SELECT string_agg(c.relname, ' ') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relkind = 'r' AND NOT (c.relrowsecurity AND c.relforcerowsecurity)
      AND c.relname NOT IN (${exempt%,})
      AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped)")
  if [[ -z $loose_tables ]]; then
    pass "every table with tenant_id has row-level security enabled and forced (exempt by design: ${RLS_EXEMPT_TABLES[*]})"
  else
    fail "tenant tables without forced row-level security: $loose_tables"
  fi

  heading "Redis and PHP-FPM"
  if [[ $(redis-cli -s "$FG_REDIS_SOCKET" config get port 2>/dev/null | tail -n 1) == 0 ]]; then pass "Redis has no TCP port"; else fail "Redis listens on TCP"; fi
  if [[ $(redis-cli -s "$FG_REDIS_SOCKET" config get maxmemory-policy 2>/dev/null | tail -n 1) == noeviction ]]; then pass "Redis never evicts (the queue lives there)"; else fail "Redis maxmemory-policy is not noeviction"; fi
  local nginx_group
  nginx_group=$(id -gn "$FG_NGINX_USER" 2>/dev/null || echo "$FG_NGINX_USER")
  if [[ $(stat -c '%a %U %G' "$FG_PHP_SOCKET_DIR" 2>/dev/null) == "710 $FG_USER $nginx_group" ]]; then
    pass "only nginx can reach the PHP-FPM socket ($FG_PHP_SOCKET_DIR is 0710 $FG_USER:$nginx_group)"
  else
    fail "$FG_PHP_SOCKET_DIR is $(stat -c '%a %U %G' "$FG_PHP_SOCKET_DIR" 2>/dev/null), not 710 $FG_USER $nginx_group"
  fi
}

# ---- nginx, TLS and the live site ------------------------------------------------------------------
check_site() {
  heading "nginx and the site"
  if nginx -t -q 2>/dev/null; then pass "nginx -t passes"; else fail "nginx -t fails"; fi
  if [[ -n $FG_TLS_CA ]]; then
    advise "TLS uses the self-signed placeholder: re-run provision.sh once DNS points here"
  else
    pass "TLS uses a Let's Encrypt certificate"
  fi

  # Every other name answers with a permanent redirect to the site, path and query kept.
  local host answer
  local -a redirects=()
  read -ra redirects <<<"${FG_REDIRECT_HOSTS:-}"
  for host in "${redirects[@]}"; do
    local -a ropts=(-s --max-time 10 --resolve "$host:443:127.0.0.1" -o /dev/null -w '%{http_code} %{redirect_url}')
    if [[ -n $FG_TLS_CA ]]; then ropts+=(--cacert "$FG_TLS_CA"); fi
    answer=$(curl "${ropts[@]}" "https://$host/f/check?from=feedgenix-check" || true)
    if [[ $answer == "301 https://$FG_DOMAIN/f/check?from=feedgenix-check" ]]; then
      pass "https://$host redirects to https://$FG_DOMAIN (path kept)"
    else
      fail "https://$host answers '$answer', not a 301 to https://$FG_DOMAIN"
    fi
  done

  local live
  live=$(readlink -e "$FG_ROOT/current" 2>/dev/null || true)
  if [[ -z $live ]]; then
    advise "no release is deployed yet"
    return 0
  fi
  local -a opts=(-s --max-time 10 --resolve "$FG_DOMAIN:443:127.0.0.1" -o /dev/null -w '%{http_code}')
  if [[ -n $FG_TLS_CA ]]; then opts+=(--cacert "$FG_TLS_CA"); fi
  local code
  code=$(curl "${opts[@]}" "https://$FG_DOMAIN/api/health" || true)
  if [[ $code == 200 ]]; then pass "https://$FG_DOMAIN/api/health answers 200 (release ${live##*/})"; else fail "https://$FG_DOMAIN/api/health answers HTTP $code"; fi
  local pid
  if pid=$(main_pid feedgenix-worker@default.service) && [[ $(readlink -f "/proc/$pid/cwd") == "$live" ]]; then
    pass "the queue worker runs the live release"
  else
    advise "the queue worker is not (yet) on the live release (it restarts after its current job)"
  fi
}

check_dedicated() {
  [[ $FG_MODE == dedicated ]] || return 0
  heading "Host hardening"
  if [[ $(ufw status 2>/dev/null | head -n 1) == 'Status: active' ]]; then pass "ufw is active"; else fail "ufw is not active"; fi
  if systemctl is-active --quiet fail2ban; then pass "fail2ban is active"; else fail "fail2ban is not active"; fi
  if grep -qx 'passwordauthentication no' <<<"$(sshd -T 2>/dev/null)"; then pass "SSH accepts keys only"; else fail "SSH still accepts passwords"; fi
}

if [[ ${1:-} == --rebaseline ]]; then
  [[ $FG_MODE == shared ]] || die "--rebaseline is for shared mode only"
  legacy_fingerprint >"$FG_BASELINE.new"
  chmod 0600 "$FG_BASELINE.new"
  mv -f "$FG_BASELINE.new" "$FG_BASELINE"
  echo "Recorded a new legacy baseline in $FG_BASELINE."
  exit 0
fi

printf 'feedgenix-check: %s (%s mode)\n' "$FG_DOMAIN" "$FG_MODE"
check_legacy
check_account
check_services
check_site
check_dedicated
printf '\n%d passed, %d failed, %d warnings\n' "$PASSED" "$FAILED" "$WARNED"
((FAILED == 0))
