#!/usr/bin/env bash
# shellcheck disable=SC2034 # the FG_* variables set here are read by render() from the templates
# FeedGenix host provisioning (ADR 0009, ADR 0011). Idempotent: a second run on a converged host
# reports "0 change(s)". Ubuntu 24.04, run as root. Always start with --dry-run.
#
#   --mode=shared     next to the frozen legacy app on the beta VPS: only ADDS things. It never
#                     touches the legacy app, its nginx sites, PHP-FPM pool, Supervisor programs,
#                     Redis, MySQL, crontab, the firewall or SSH, and refuses to upgrade or remove an
#                     installed package unless --allow-upgrades is given.
#   --mode=dedicated  a fresh server (production): also installs the base stack and hardens the
#                     host (ufw, fail2ban, unattended upgrades, key-only SSH).
# In both modes apt never runs while dpkg has unfinished packages (apt would re-run their maintainer
# scripts) unless each one is named with --allow-unfinished after reviewing it (ADR 0015).
set -Eeuo pipefail
shopt -s nullglob

OPS_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib/common.sh
. "$OPS_DIR/lib/common.sh"
TEMPLATES=$OPS_DIR/templates
cd /

usage() {
  cat <<'EOF'
Usage: provision.sh --mode=shared|dedicated --domain=HOST [options]

  --mode=shared|dedicated       see the header of this script
  --domain=HOST                 e.g. staging.feedgenix.com
  --tls=letsencrypt|self-signed letsencrypt (default) keeps a self-signed placeholder until the
                                domain's DNS points here; re-run once it does
  --email=ADDRESS               Let's Encrypt account email (when certbot has no account yet)
  --legacy-dir=PATH             shared: the legacy app (default /var/www/feed-intelligence)
  --legacy-url=URL              shared: the legacy site that must keep answering (required)
  --legacy-freeze-file=PATH     shared: default <legacy-dir>/storage/app/PIPELINE_FROZEN
  --deploy-key=FILE             the public key CI deploys with (forced to the deploy receiver)
  --app-env=production|staging  APP_ENV for a new .env (default production: an internet-facing app)
  --indexing=block|allow        search engines (default: block in shared mode, allow in dedicated)
  --allow-upgrades              shared: let apt upgrade already-installed packages
  --allow-unfinished=PKG[,PKG]  let apt finish these packages dpkg left unfinished: it re-runs their
                                maintainer scripts, so read them first (the survey lists them).
                                Their failure is tolerated; nothing else's is
  --skip-dns-check              request the certificate even if DNS does not seem to point here
  --dry-run                     change nothing; print every diff and command
EOF
}

# ---- arguments -------------------------------------------------------------------------------------
FG_MODE=
FG_DOMAIN=
FG_TLS=letsencrypt
FG_APP_ENV=production
FG_LEGACY_DIR=/var/www/feed-intelligence
FG_LEGACY_URL=
FG_LEGACY_FREEZE_FILE=
INDEXING=
EMAIL=
DEPLOY_KEY=
ALLOW_UPGRADES=0
ALLOW_UNFINISHED=
SKIP_DNS_CHECK=0

for arg in "$@"; do
  case $arg in
    --mode=*) FG_MODE=${arg#*=} ;;
    --domain=*) FG_DOMAIN=${arg#*=} ;;
    --tls=*) FG_TLS=${arg#*=} ;;
    --email=*) EMAIL=${arg#*=} ;;
    --legacy-dir=*) FG_LEGACY_DIR=${arg#*=} ;;
    --legacy-url=*) FG_LEGACY_URL=${arg#*=} ;;
    --legacy-freeze-file=*) FG_LEGACY_FREEZE_FILE=${arg#*=} ;;
    --deploy-key=*) DEPLOY_KEY=${arg#*=} ;;
    --app-env=*) FG_APP_ENV=${arg#*=} ;;
    --indexing=*) INDEXING=${arg#*=} ;;
    --allow-upgrades) ALLOW_UPGRADES=1 ;;
    --allow-unfinished=*) ALLOW_UNFINISHED=${arg#*=} ;;
    --skip-dns-check) SKIP_DNS_CHECK=1 ;;
    --dry-run) FG_DRY_RUN=1 ;;
    -h | --help)
      usage
      exit 0
      ;;
    *) die "unknown option: $arg (see --help)" ;;
  esac
done
FG_LEGACY_FREEZE_FILE=${FG_LEGACY_FREEZE_FILE:-$FG_LEGACY_DIR/storage/app/PIPELINE_FROZEN}

WORK=$(mktemp -d)
POLICY_RC_CREATED=0
cleanup() {
  if ((POLICY_RC_CREATED)); then rm -f /usr/sbin/policy-rc.d; fi
  rm -rf "$WORK"
}
trap cleanup EXIT

RESTART=()       # services whose files changed: restarted at the end (only FeedGenix's own)
NGINX_TOUCHED=() # nginx files changed in this pass: reverted if nginx -t fails
NGINX_RELOAD=0
UNITS_CHANGED=0
INSTALLED_NOW=()
FG_TLS_CERT=
FG_TLS_KEY=
FG_TLS_CA=
FG_PG_PORT=5432
FG_NGINX_USER=www-data

# ---- preflight -------------------------------------------------------------------------------------
preflight() {
  section "Preflight ($FG_MODE mode$( ((FG_DRY_RUN)) && echo ', DRY RUN: nothing will change'))"
  [[ $EUID -eq 0 ]] || die "run as root"
  # shellcheck source=/dev/null
  . /etc/os-release
  [[ ${ID:-} == ubuntu && ${VERSION_ID:-} == 24.04 ]] || die "Ubuntu 24.04 only (this is ${PRETTY_NAME:-unknown})"
  [[ -d /run/systemd/system ]] || die "systemd is not running"
  [[ -f /sys/fs/cgroup/cgroup.controllers ]] || die "cgroup v2 is required"

  [[ $FG_MODE == shared || $FG_MODE == dedicated ]] || die "--mode=shared or --mode=dedicated is required"
  [[ $FG_DOMAIN =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] ||
    die "--domain must be a lowercase host name (got '$FG_DOMAIN')"
  [[ $FG_TLS == letsencrypt || $FG_TLS == self-signed ]] || die "--tls must be letsencrypt or self-signed"
  [[ $FG_APP_ENV == production || $FG_APP_ENV == staging ]] || die "--app-env must be production or staging"
  [[ -z $EMAIL || $EMAIL =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "--email is not an address"
  [[ -z $DEPLOY_KEY || -f $DEPLOY_KEY ]] || die "--deploy-key file not found: $DEPLOY_KEY"
  [[ -z $ALLOW_UNFINISHED || $ALLOW_UNFINISHED =~ ^[a-z0-9][a-z0-9+.-]*(,[a-z0-9][a-z0-9+.-]*)*$ ]] ||
    die "--allow-unfinished takes package names separated by commas (got '$ALLOW_UNFINISHED')"
  if [[ -z $INDEXING ]]; then
    if [[ $FG_MODE == shared ]]; then INDEXING=block; else INDEXING=allow; fi
  fi
  [[ $INDEXING == block || $INDEXING == allow ]] || die "--indexing must be block or allow"

  if [[ $FG_MODE == shared ]]; then
    local path
    for path in "$FG_LEGACY_DIR" "$FG_LEGACY_FREEZE_FILE"; do
      [[ $path =~ ^/[A-Za-z0-9._/-]+$ && $path != *..* ]] || die "not a safe absolute path: $path"
    done
    [[ -d $FG_LEGACY_DIR ]] || die "legacy dir $FG_LEGACY_DIR not found (wrong --legacy-dir?)"
    [[ $FG_LEGACY_URL =~ ^https?://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~/?=\&%-]*)?$ ]] ||
      die "--legacy-url is required in shared mode (the legacy site that must keep answering)"
    command -v nginx >/dev/null || die "nginx is not installed; shared mode never installs the web server"
    [[ -x /usr/sbin/php-fpm$FG_PHP_VERSION ]] ||
      die "php$FG_PHP_VERSION-fpm is not installed; shared mode never installs PHP itself"
    systemctl is-active --quiet nginx || die "nginx is not running"
    nginx -t -q 2>/dev/null || die "nginx -t fails BEFORE any change: the existing config must be fixed first"
    legacy_answers ||
      die "the legacy site $FG_LEGACY_URL does not answer (HTTP ${LEGACY_STATUS:-none}) BEFORE any change; nothing was touched"
    ok "the legacy site answers (HTTP $LEGACY_STATUS); nginx -t passes"
    [[ -f $FG_LEGACY_FREEZE_FILE ]] || warn "freeze file $FG_LEGACY_FREEZE_FILE is missing (feedgenix-check will fail)"
  else
    FG_LEGACY_DIR=
    FG_LEGACY_URL=
    FG_LEGACY_FREEZE_FILE=
  fi

  local port owners
  for port in 80 443; do
    owners=$(port_owners "$port")
    [[ -z $owners || $owners == nginx ]] ||
      die "port $port is held by '$owners', not nginx; the FeedGenix vhost needs nginx on 80 and 443"
  done
  [[ ! -e /usr/sbin/policy-rc.d ]] || die "/usr/sbin/policy-rc.d already exists; refusing to replace it"

  local extra
  extra=$(loopback_resolvers | grep -vx '127.0.0.53' || true)
  [[ -z $extra ]] ||
    die "/etc/resolv.conf uses a loopback resolver other than systemd-resolved ($extra): the sandbox could not allow DNS without opening every local service"

  if id "$FG_USER" >/dev/null 2>&1; then
    local group groups
    groups=$(id -nG "$FG_USER" | tr ' ' '\n')
    for group in sudo admin adm wheel www-data redis mysql postgres docker lxd systemd-journal supervisor; do
      if grep -qx "$group" <<<"$groups"; then
        die "user $FG_USER is in the privileged group '$group'; remove it first"
      fi
    done
  fi
  ok "Ubuntu 24.04, systemd, cgroup v2; ports 80/443 free or held by nginx"
}

# The legacy fingerprint is recorded once, before the first change; feedgenix-check compares it.
record_legacy_baseline() {
  [[ $FG_MODE == shared ]] || return 0
  section "Legacy baseline"
  if [[ -f $FG_BASELINE ]]; then
    ok "kept $FG_BASELINE (recorded $(stat -c %y "$FG_BASELINE" | cut -d. -f1))"
    return 0
  fi
  changed "record the legacy fingerprint in $FG_BASELINE (hashes only, readable by root only)"
  if ((!FG_DRY_RUN)); then
    install -d -m 0700 "$FG_STATE"
    legacy_fingerprint >"$WORK/baseline"
    install -m 0600 "$WORK/baseline" "$FG_BASELINE"
  fi
}

# ---- packages --------------------------------------------------------------------------------------
# The lock timeout waits out another apt run (a host's own cron) instead of failing on its lock.
apt_run() {
  DEBIAN_FRONTEND=noninteractive NEEDRESTART_SUSPEND=1 apt-get -q -y -o DPkg::Lock::Timeout=300 \
    -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$@"
}

# policy-rc.d exits 101: while apt runs, no package script may start or restart a service, so a
# package install can never restart a legacy service. Removed again on exit, even after a failure.
freeze_services() {
  printf '#!/bin/sh\n# Temporary (FeedGenix ops/provision.sh): no service (re)starts during package installs.\nexit 101\n' \
    >/usr/sbin/policy-rc.d
  chmod 0755 /usr/sbin/policy-rc.d
  POLICY_RC_CREATED=1
}
unfreeze_services() {
  rm -f /usr/sbin/policy-rc.d
  POLICY_RC_CREATED=0
}

PGDG_FINGERPRINT=B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8
PGDG_KEY=/etc/apt/keyrings/feedgenix-pgdg.asc
PGDG_LIST=/etc/apt/sources.list.d/feedgenix-pgdg.sources

pgdg_configured() { grep -rqs 'apt.postgresql.org' /etc/apt/sources.list /etc/apt/sources.list.d/; }

# Download the PGDG signing key into FILE and check its fingerprint.
fetch_pgdg_key() {
  local fingerprints
  curl -fsSL --max-time 30 https://www.postgresql.org/media/keys/ACCC4CF8.asc -o "$1" ||
    die "could not download the PGDG signing key"
  fingerprints=$(gpg --show-keys --with-colons "$1" 2>/dev/null | awk -F: '$1 == "fpr" { print $10 }')
  grep -qx "$PGDG_FINGERPRINT" <<<"$fingerprints" || die "the PGDG key's fingerprint is not $PGDG_FINGERPRINT"
}

installed() { [[ $(dpkg-query -W -f='${Status}' "$1" 2>/dev/null) == 'install ok installed' ]]; }
has_candidate() { [[ $(apt-cache policy "$1" 2>/dev/null) =~ Candidate:\ [0-9] ]]; }

# The packages an install would upgrade or remove, from a simulation's output; dies with apt's own
# error when the simulation fails (a missing package, a broken repository).
disruptions_of() { # SIMULATE-FUNCTION PACKAGES…
  local simulation
  simulation=$("$@" 2>&1) || {
    grep -E '^(E|W):' <<<"$simulation" >&2 || printf '%s\n' "$simulation" | tail -n 20 >&2
    die "apt cannot install ${*:2}"
  }
  apt_disruptions <<<"$simulation"
}
simulate_now() { apt-get -s install --no-install-recommends "$@"; }

pgdg_source() { # KEYFILE
  printf 'Types: deb\nURIs: https://apt.postgresql.org/pub/repos/apt\nSuites: noble-pgdg\nComponents: main\nSigned-By: %s\n' "$1"
}

# `apt-get -s install PACKAGES` against package lists that already include every repository this run
# would add, without touching the system's apt state: the dry run shows the full picture up front.
simulate_install() {
  local lists=$WORK/apt-lists parts=$WORK/apt-sources
  mkdir -p "$lists" "$parts"
  cp -a /var/lib/apt/lists/. "$lists/" 2>/dev/null || true
  rm -f "$lists/lock"
  mkdir -p "$lists/partial"
  cp -a /etc/apt/sources.list.d/. "$parts/" 2>/dev/null || true
  if ! pgdg_configured; then
    fetch_pgdg_key "$WORK/pgdg.asc"
    pgdg_source "$WORK/pgdg.asc" >"$parts/feedgenix-pgdg.sources"
  fi
  local -a opts=(-o "Dir::Etc::SourceParts=$parts" -o "Dir::State::Lists=$lists"
    -o Debug::NoLocking=1 -o APT::Sandbox::User=root)
  apt-get -q "${opts[@]}" update >"$WORK/apt-update.log" 2>&1 || {
    cat "$WORK/apt-update.log" >&2
    die "apt-get update (simulation) failed"
  }
  apt-get -s "${opts[@]}" install --no-install-recommends "$@"
}

# dpkg's unfinished packages are finished by the next apt run: their maintainer scripts run again, and
# a script that calls systemctl itself is beyond policy-rc.d. (The beta VPS's control panel package
# has been half-configured since July 2026; its postinst can restart services and move MySQL's data.)
# apt runs only when every unfinished package is named with --allow-unfinished, after it was read.
UNFINISHED=
guard_unfinished() {
  UNFINISHED=$(unfinished_packages | paste -sd, -)
  [[ -n $UNFINISHED ]] || return 0
  local blockers
  blockers=$(tr ',' '\n' <<<"$UNFINISHED" | not_in_list "$ALLOW_UNFINISHED" | paste -sd, -)
  [[ -z $blockers ]] ||
    die "dpkg left these packages unfinished: $blockers. Any apt install re-runs their maintainer scripts, and apt fails when they fail. Read them (dpkg --audit; /var/lib/dpkg/info/PKG.postinst), then re-run with --allow-unfinished=$UNFINISHED. Nothing was installed."
  warn "apt will re-run the maintainer scripts of $UNFINISHED (--allow-unfinished): their failure is tolerated, nothing else's"
}

# apt failed. Accepted only when it was finishing allowed unfinished packages, every package of this
# run is installed, and no package is unfinished except the allowed ones.
accept_apt_failure() { # PACKAGES…
  [[ -n $UNFINISHED ]] || die "apt-get install failed"
  local pkg left
  for pkg in "$@"; do
    installed "$pkg" || die "apt-get install failed: $pkg is not installed"
  done
  left=$(unfinished_packages | not_in_list "$ALLOW_UNFINISHED" | paste -sd' ' -)
  [[ -z $left ]] || die "apt-get install left packages unfinished: $left"
  warn "apt reported the allowed unfinished package(s) failing again ($(unfinished_packages | paste -sd' ' -)); every package of this run is installed"
}

install_packages() {
  section "Packages"
  local -a wanted=(
    "postgresql-$FG_PG_VERSION" "postgresql-$FG_PG_VERSION-pgvector" "postgresql-client-$FG_PG_VERSION"
    redis-server curl gettext-base openssl ca-certificates gnupg
  )
  local -a php_packages
  mapfile -t php_packages < <(php_packages_needed "$FG_PHP_VERSION")
  wanted+=("${php_packages[@]}")
  if [[ $FG_TLS == letsencrypt ]]; then wanted+=(certbot); fi
  if [[ $FG_MODE == dedicated ]]; then wanted+=(nginx "php$FG_PHP_VERSION-fpm" ufw fail2ban unattended-upgrades); fi

  local -a missing=()
  local pkg
  for pkg in "${wanted[@]}"; do
    installed "$pkg" || missing+=("$pkg")
  done
  if ((${#missing[@]} > 0)); then guard_unfinished; fi
  if ((${#missing[@]} == 0)); then
    ok "all packages present"
  elif [[ $FG_MODE == shared ]]; then
    # Anything already installed that apt would upgrade or remove is listed before any change.
    local disruptions
    disruptions=$(disruptions_of simulate_install "${missing[@]}")
    if [[ -n $disruptions ]]; then
      local -a lines
      mapfile -t lines <<<"$disruptions"
      printf '   apt would also:\n'
      printf '             %s\n' "${lines[@]}"
      if grep -q '^remove ' <<<"$disruptions"; then die "apt would REMOVE installed packages; nothing was changed"; fi
      ((ALLOW_UPGRADES)) ||
        die "apt would UPGRADE installed packages the legacy app may use; review the list above and re-run with --allow-upgrades"
    fi
    changed "install ${missing[*]} (no service is started or restarted by apt)"
  else
    changed "install ${missing[*]}"
  fi
  INSTALLED_NOW=("${missing[@]}")

  if [[ $FG_MODE == dedicated ]] && ! has_candidate "php$FG_PHP_VERSION-fpm"; then
    changed "add ppa:ondrej/php (PHP $FG_PHP_VERSION)"
    if ((!FG_DRY_RUN)); then
      apt_run install software-properties-common >/dev/null
      add-apt-repository -y ppa:ondrej/php >/dev/null
    fi
  fi
  if ! pgdg_configured; then
    changed "add the PostgreSQL apt repository ($PGDG_LIST, key $PGDG_FINGERPRINT)"
    if ((!FG_DRY_RUN)); then
      install -d -m 0755 /etc/apt/keyrings
      fetch_pgdg_key "$WORK/pgdg.asc"
      install -m 0644 "$WORK/pgdg.asc" "$PGDG_KEY"
      pgdg_source "$PGDG_KEY" >"$WORK/pgdg.sources"
      install -m 0644 "$WORK/pgdg.sources" "$PGDG_LIST"
    fi
  fi

  if ((${#missing[@]} == 0 || FG_DRY_RUN)); then return 0; fi
  freeze_services
  apt_run update >/dev/null
  if [[ $FG_MODE == shared ]]; then
    # Checked again against the real lists: the guard holds even if a repository moved on meanwhile.
    local disruptions
    disruptions=$(disruptions_of simulate_now "${missing[@]}")
    if grep -q '^remove ' <<<"$disruptions" || { [[ -n $disruptions ]] && ((!ALLOW_UPGRADES)); }; then
      die "apt would now also: $(tr '\n' ' ' <<<"$disruptions")— nothing was installed"
    fi
  fi
  if ! apt_run install --no-install-recommends "${missing[@]}"; then accept_apt_failure "${missing[@]}"; fi
  unfreeze_services

  # Default services of packages this run installed (never a pre-existing one): FeedGenix runs its own.
  for pkg in "${INSTALLED_NOW[@]}"; do
    case $pkg in
      redis-server) systemctl disable --now redis-server.service >/dev/null 2>&1 || true ;;
      "php$FG_PHP_VERSION-fpm") systemctl disable --now "php$FG_PHP_VERSION-fpm.service" >/dev/null 2>&1 || true ;;
    esac
  done
  if [[ $FG_MODE == dedicated && -L /etc/nginx/sites-enabled/default ]]; then
    rm -f /etc/nginx/sites-enabled/default
    note "removed nginx's default site"
  fi
}

# Packages were chosen by missing module; afterwards every module must load, in the CLI and in
# PHP-FPM (an installed but disabled extension would otherwise surface only as a broken release).
verify_php_modules() {
  if ((FG_DRY_RUN)); then return 0; fi
  local still
  still=$(php_packages_needed "$FG_PHP_VERSION" | paste -sd' ' -)
  [[ -z $still ]] ||
    die "PHP $FG_PHP_VERSION still lacks modules from: $still (installed but disabled? see phpenmod)"
  ok "PHP $FG_PHP_VERSION CLI and PHP-FPM load every module FeedGenix needs"
}

# ---- the app user and directories ----------------------------------------------------------------
setup_user_and_dirs() {
  section "User $FG_USER and directories"
  if ! id "$FG_USER" >/dev/null 2>&1; then
    changed "create user $FG_USER (no password, no sudo, home owned by root)"
    # /bin/sh: sshd runs the deploy key's forced command through the login shell.
    apply useradd --system --user-group --shell /bin/sh --home-dir "/home/$FG_USER" --no-create-home "$FG_USER"
    # "*": no usable password, but not "locked", so key-only SSH works with and without PAM.
    apply usermod -p '*' "$FG_USER"
  fi
  # Home and .ssh belong to root: nothing running as the app can change its own SSH keys.
  ensure_dir "/home/$FG_USER" 0755 root root
  ensure_dir "/home/$FG_USER/.ssh" 0755 root root

  # 0711: nginx (another user) may traverse to the SPA build and to public/, never list anything.
  ensure_dir "$FG_ROOT" 0711 "$FG_USER" "$FG_USER"
  ensure_dir "$FG_ROOT/releases" 0711 "$FG_USER" "$FG_USER"
  ensure_dir "$FG_ROOT/shared" 0750 "$FG_USER" "$FG_USER"
  local dir
  for dir in incoming logs storage storage/app storage/app/private storage/app/public storage/framework \
    storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views \
    storage/logs; do
    ensure_dir "$FG_ROOT/shared/$dir" 0750 "$FG_USER" "$FG_USER"
  done
  ensure_dir "$FG_ETC" 0750 root "$FG_USER"
  ensure_dir "$FG_ETC/php" 0750 root "$FG_USER"
  ensure_dir "$FG_ETC/php/fpm.d" 0750 root "$FG_USER"
  ensure_dir "$FG_ETC/php/cli.d" 0750 root "$FG_USER"
  ensure_dir "$FG_LIB" 0755 root root
  ensure_dir "$FG_ACME_ROOT" 0755 root root
  ok "$FG_ROOT/{releases,shared}, $FG_ETC, $FG_LIB"
}

# ---- PostgreSQL ------------------------------------------------------------------------------------
psql_admin() { runuser -u postgres -- psql -X -q -v ON_ERROR_STOP=1 -p "$FG_PG_PORT" "$@"; }
pg_cluster_line() { pg_lsclusters --no-header 2>/dev/null | awk -v v="$FG_PG_VERSION" '$1 == v && $2 == "main"'; }

pg_ensure_objects() {
  if [[ -z $(psql_admin -Atc "SELECT 1 FROM pg_roles WHERE rolname = '$FG_USER'") ]]; then
    changed "role $FG_USER (peer authentication over the unix socket: no password exists)"
    apply psql_admin -c "CREATE ROLE $FG_USER LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION"
  elif [[ $(psql_admin -Atc "SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication)::text FROM pg_roles WHERE rolname = '$FG_USER'") == true ]]; then
    changed "strip SUPERUSER/BYPASSRLS/CREATEDB/CREATEROLE/REPLICATION from role $FG_USER (ADR 0002)"
    apply psql_admin -c "ALTER ROLE $FG_USER NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION"
  fi
  if [[ -z $(psql_admin -Atc "SELECT 1 FROM pg_database WHERE datname = '$FG_USER'") ]]; then
    changed "database $FG_USER (UTF-8, builtin C.UTF-8 locale: immune to glibc collation changes)"
    apply psql_admin -c "CREATE DATABASE $FG_USER OWNER $FG_USER ENCODING 'UTF8' TEMPLATE template0 LOCALE_PROVIDER builtin BUILTIN_LOCALE 'C.UTF-8'"
    if ((FG_DRY_RUN)); then return 0; fi
  fi
  if [[ -z $(psql_admin -d "$FG_USER" -Atc "SELECT 1 FROM pg_extension WHERE extname = 'vector'") ]]; then
    changed "extension vector in $FG_USER (not a trusted extension: created by the superuser)"
    apply psql_admin -d "$FG_USER" -c 'CREATE EXTENSION IF NOT EXISTS vector'
  fi
}

setup_postgres() {
  section "PostgreSQL $FG_PG_VERSION"
  if ! command -v pg_lsclusters >/dev/null; then
    ((FG_DRY_RUN)) || die "pg_lsclusters is missing after the package install"
    changed "cluster $FG_PG_VERSION/main on the unix socket only; role and database $FG_USER; extension vector"
    return 0
  fi
  local cluster
  cluster=$(pg_cluster_line)
  if [[ -z $cluster ]]; then
    changed "create cluster $FG_PG_VERSION/main"
    if ((FG_DRY_RUN)); then return 0; fi
    pg_createcluster --start-conf=auto "$FG_PG_VERSION" main >/dev/null
    cluster=$(pg_cluster_line)
  fi
  FG_PG_PORT=$(awk '{ print $3 }' <<<"$cluster")
  [[ $FG_PG_PORT =~ ^[0-9]+$ ]] || die "could not read the port of cluster $FG_PG_VERSION/main"
  [[ $FG_PG_PORT == 5432 ]] || note "cluster $FG_PG_VERSION/main uses port $FG_PG_PORT (another cluster has 5432)"

  local mem_mb
  mem_mb=$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)
  if [[ $FG_MODE == shared ]]; then
    FG_PG_SHARED_BUFFERS=1GB
    FG_PG_EFFECTIVE_CACHE_SIZE=4GB
  else
    FG_PG_SHARED_BUFFERS="$((mem_mb / 4 > 8192 ? 8192 : mem_mb / 4))MB"
    FG_PG_EFFECTIVE_CACHE_SIZE="$((mem_mb * 3 / 4))MB"
  fi
  render "$TEMPLATES/postgres/feedgenix.conf" >"$WORK/pg.conf"
  install_file "$WORK/pg.conf" "/etc/postgresql/$FG_PG_VERSION/main/conf.d/feedgenix.conf" 0644 postgres postgres
  local conf_changed=$FG_CHANGED
  render "$TEMPLATES/systemd/postgresql-slice.conf" >"$WORK/pg-slice.conf"
  install_file "$WORK/pg-slice.conf" "/etc/systemd/system/postgresql@$FG_PG_VERSION-main.service.d/10-feedgenix.conf" 0644 root root
  if ((FG_CHANGED)); then
    conf_changed=1
    apply systemctl daemon-reload
  fi

  local unit=postgresql@$FG_PG_VERSION-main.service
  if ((conf_changed)) && systemctl is-active --quiet "$unit"; then
    changed "restart $unit (its own cluster: nothing else uses it)"
    apply systemctl restart "$unit"
  fi
  if ! systemctl is-active --quiet "$unit"; then
    changed "start $unit"
    if ((FG_DRY_RUN)); then return 0; fi
    systemctl enable --quiet postgresql.service
    systemctl start "$unit"
  fi
  pg_ensure_objects
  if ((!FG_DRY_RUN)); then
    [[ $(psql_admin -Atc "SELECT (rolsuper OR rolbypassrls)::text FROM pg_roles WHERE rolname = '$FG_USER'") == false ]] ||
      die "role $FG_USER is SUPERUSER or BYPASSRLS"
  fi
  ok "cluster $FG_PG_VERSION/main (unix socket, port $FG_PG_PORT); role $FG_USER is NOSUPERUSER NOBYPASSRLS"
}

# ---- systemd units, Redis, PHP-FPM, workers -------------------------------------------------------
install_unit() { # TEMPLATE DEST [SERVICE-TO-RESTART]
  render "$TEMPLATES/systemd/$1" >"$WORK/unit"
  install_file "$WORK/unit" "$2" 0644 root root
  if ((FG_CHANGED)); then
    UNITS_CHANGED=1
    if [[ -n ${3:-} ]]; then RESTART+=("$3"); fi
  fi
}

install_config() { # TEMPLATE DEST SERVICE-TO-RESTART
  render "$TEMPLATES/$1" >"$WORK/config"
  install_file "$WORK/config" "$2" 0640 root "$FG_USER"
  if ((FG_CHANGED)); then RESTART+=("$3"); fi
}

setup_services() {
  section "Services: own units in feedgenix.slice, run by $FG_USER, sandboxed"
  FG_NGINX_USER=$(nginx_user)
  FG_NGINX_GROUP=$(id -gn "$FG_NGINX_USER" 2>/dev/null || echo "$FG_NGINX_USER")

  # What FeedGenix processes must never see or reach (ADR 0011).
  FG_INACCESSIBLE_PATHS="-/var/www -/etc/supervisor -/etc/mysql -/var/lib/mysql -/run/mysqld -/etc/redis -/var/lib/redis -/run/redis -/var/spool/cron -/etc/letsencrypt -$FG_STATE"
  if [[ -n $FG_LEGACY_DIR && $FG_LEGACY_DIR != /var/www/* ]]; then
    FG_INACCESSIBLE_PATHS="-$FG_LEGACY_DIR $FG_INACCESSIBLE_PATHS"
  fi
  FG_HOST_ADDRESSES=$(ip -o addr show scope global | awk '{ split($4, a, "/"); print a[1] (index(a[1], ":") ? "/128" : "/32") }' | sort -u | paste -sd' ' -)

  if [[ $FG_MODE == shared ]]; then
    FG_CPU_QUOTA=400%
    FG_MEMORY_HIGH=6G
    FG_MEMORY_MAX=8G
    FG_FPM_MAX_CHILDREN=16
  else
    FG_CPU_QUOTA=
    FG_MEMORY_HIGH=infinity
    FG_MEMORY_MAX=infinity
    FG_FPM_MAX_CHILDREN=32
  fi

  install_unit feedgenix.slice /etc/systemd/system/feedgenix.slice
  install_unit sandbox.conf /etc/systemd/system/feedgenix-.service.d/10-sandbox.conf feedgenix-php.service
  if ((FG_CHANGED)); then RESTART+=(feedgenix-redis.service feedgenix-worker@default.service); fi
  install_unit feedgenix-redis.service /etc/systemd/system/feedgenix-redis.service feedgenix-redis.service
  install_unit feedgenix-php.service /etc/systemd/system/feedgenix-php.service feedgenix-php.service
  install_unit feedgenix-worker@.service /etc/systemd/system/feedgenix-worker@.service feedgenix-worker@default.service
  install_unit feedgenix-schedule.service /etc/systemd/system/feedgenix-schedule.service
  install_unit feedgenix-schedule.timer /etc/systemd/system/feedgenix-schedule.timer

  # The PHP-FPM socket directory: owner feedgenix, group of nginx, 0710. nginx may connect; nobody
  # else may even look inside. systemd-tmpfiles recreates it at every boot.
  render "$TEMPLATES/systemd/tmpfiles.conf" >"$WORK/tmpfiles.conf"
  install_file "$WORK/tmpfiles.conf" /etc/tmpfiles.d/feedgenix.conf 0644 root root
  if ((FG_CHANGED)); then
    apply systemd-tmpfiles --create /etc/tmpfiles.d/feedgenix.conf
    RESTART+=(feedgenix-php.service)
  fi

  install_config redis/redis.conf "$FG_ETC/redis.conf" feedgenix-redis.service
  install_config php/php-fpm.conf "$FG_ETC/php-fpm.conf" feedgenix-php.service
  install_config php/fpm.ini "$FG_ETC/php/fpm.d/99-feedgenix.ini" feedgenix-php.service
  install_config php/cli.ini "$FG_ETC/php/cli.d/99-feedgenix.ini" feedgenix-worker@default.service

  if ((!FG_DRY_RUN)); then
    # The pool is tested as the user that runs it.
    runuser -u "$FG_USER" -- env "PHP_INI_SCAN_DIR=:$FG_ETC/php/fpm.d" \
      "/usr/sbin/php-fpm$FG_PHP_VERSION" --test --fpm-config "$FG_ETC/php-fpm.conf" >"$WORK/fpm-test.log" 2>&1 || {
      cat "$WORK/fpm-test.log" >&2
      die "php-fpm --test failed for $FG_ETC/php-fpm.conf"
    }
  fi
  if ((UNITS_CHANGED)); then apply systemctl daemon-reload; fi
  ok "feedgenix.slice, feedgenix-{redis,php,worker@default,schedule}, sandbox drop-in"
}

# ---- tools: deploy receiver, artisan wrappers, feedgenix-check -----------------------------------
install_tools() {
  section "Tools"
  install_file "$OPS_DIR/bin/receive" "$FG_LIB/receive" 0755 root root
  install_file "$OPS_DIR/bin/run-artisan" "$FG_LIB/run-artisan" 0755 root root
  if ((FG_CHANGED)); then RESTART+=(feedgenix-worker@default.service); fi
  install_file "$OPS_DIR/lib/common.sh" "$FG_LIB/common.sh" 0644 root root
  install_file "$OPS_DIR/bin/feedgenix-artisan" /usr/local/bin/feedgenix-artisan 0755 root root
  install_file "$OPS_DIR/check.sh" /usr/local/sbin/feedgenix-check 0755 root root
  ok "$FG_LIB/{receive,run-artisan,common.sh}, feedgenix-artisan, feedgenix-check"
}

install_deploy_key() {
  section "Deploy key"
  if [[ -z $DEPLOY_KEY ]]; then
    if [[ -s /home/$FG_USER/.ssh/authorized_keys ]]; then
      ok "kept the installed deploy key"
    else
      note "none yet (--deploy-key=FILE): CI cannot deploy until one is installed"
    fi
    return 0
  fi
  ssh-keygen -l -f "$DEPLOY_KEY" >/dev/null 2>&1 || die "$DEPLOY_KEY is not an SSH public key"
  local key
  key=$(awk '$1 ~ /^(ssh-ed25519|ecdsa-sha2-nistp256|sk-ssh-ed25519@openssh\.com)$/ { print $1 " " $2; exit }' "$DEPLOY_KEY")
  [[ -n $key ]] || die "the deploy key must be ed25519 or ecdsa (got: $(awk '{ print $1; exit }' "$DEPLOY_KEY"))"
  # restrict: no shell, no PTY, no forwarding. The key can only run the receiver.
  printf '# FeedGenix CI deploys. Managed by ops/provision.sh.\nrestrict,command="%s/receive" %s feedgenix-deploy\n' \
    "$FG_LIB" "$key" >"$WORK/authorized_keys"
  install_file "$WORK/authorized_keys" "/home/$FG_USER/.ssh/authorized_keys" 0644 root root
  ((FG_CHANGED)) || ok "deploy key unchanged"
}

write_env() {
  section "Application environment"
  local env=$FG_ROOT/shared/.env
  if [[ -f $env ]]; then
    ok "$env exists (never rewritten: edit it by hand)"
    return 0
  fi
  FG_APP_KEY="base64:$(openssl rand -base64 32)"
  render "$TEMPLATES/env.template" >"$WORK/env"
  install_secret_file "$WORK/env" "$env" 0600 "$FG_USER" "$FG_USER"
  unset FG_APP_KEY
}

# ---- TLS and nginx ---------------------------------------------------------------------------------
self_signed_cert() {
  local dir=$FG_ETC/tls
  FG_TLS_CERT=$dir/cert.pem
  FG_TLS_KEY=$dir/key.pem
  FG_TLS_CA=$FG_TLS_CERT
  if [[ -f $FG_TLS_CERT ]] && openssl x509 -checkend 604800 -noout -in "$FG_TLS_CERT" >/dev/null 2>&1 &&
    grep -q "DNS:$FG_DOMAIN\$" <<<"$(openssl x509 -noout -ext subjectAltName -in "$FG_TLS_CERT" 2>/dev/null)"; then
    return 0
  fi
  changed "self-signed certificate for $FG_DOMAIN in $dir (a placeholder until Let's Encrypt)"
  if ((FG_DRY_RUN)); then return 0; fi
  install -d -m 0755 "$dir"
  openssl req -x509 -newkey rsa:2048 -nodes -days 90 -subj "/CN=$FG_DOMAIN" \
    -addext "subjectAltName=DNS:$FG_DOMAIN" -keyout "$WORK/key.pem" -out "$WORK/cert.pem" 2>/dev/null
  install -m 0600 "$WORK/key.pem" "$FG_TLS_KEY"
  install -m 0644 "$WORK/cert.pem" "$FG_TLS_CERT"
  NGINX_RELOAD=1
}

use_letsencrypt_cert() {
  FG_TLS_CERT=/etc/letsencrypt/live/$FG_DOMAIN/fullchain.pem
  FG_TLS_KEY=/etc/letsencrypt/live/$FG_DOMAIN/privkey.pem
  FG_TLS_CA=
}

render_nginx() {
  if dpkg --compare-versions "$(nginx_version)" ge 1.25.1; then
    FG_HTTP2_LISTEN=
    FG_HTTP2_DIRECTIVE='http2 on;'
  else
    FG_HTTP2_LISTEN=' http2'
    FG_HTTP2_DIRECTIVE='# HTTP/2 is on the listen lines (nginx < 1.25.1)'
  fi
  FG_IPV6_LISTEN_80='# (no IPv6 on this host)'
  FG_IPV6_LISTEN_443='# (no IPv6 on this host)'
  if [[ -s /proc/net/if_inet6 ]]; then
    FG_IPV6_LISTEN_80='listen [::]:80;'
    FG_IPV6_LISTEN_443="listen [::]:443 ssl${FG_HTTP2_LISTEN};"
  fi
  if [[ $INDEXING == block ]]; then
    FG_NOINDEX_HEADER='add_header X-Robots-Tag "noindex, nofollow" always;'
    FG_ROBOTS_TXT='User-agent: *\nDisallow: /\n'
  else
    FG_NOINDEX_HEADER='# search engines may index this site'
    FG_ROBOTS_TXT='User-agent: *\nDisallow: /app/\nDisallow: /admin/\n'
  fi

  case $(nginx_sites_layout) in
    sites-enabled)
      NGINX_SITE=/etc/nginx/sites-available/feedgenix.conf
      NGINX_LINK=/etc/nginx/sites-enabled/feedgenix.conf
      ;;
    conf.d)
      NGINX_SITE=/etc/nginx/conf.d/feedgenix.conf
      NGINX_LINK=
      ;;
    *) die "nginx.conf includes neither sites-enabled/ nor conf.d/: add the vhost by hand" ;;
  esac

  local name
  for name in feedgenix-headers feedgenix-php; do
    render "$TEMPLATES/nginx/$name.conf" >"$WORK/$name.conf"
    install_file "$WORK/$name.conf" "/etc/nginx/snippets/$name.conf" 0644 root root keep-prev
    if ((FG_CHANGED)); then NGINX_TOUCHED+=("/etc/nginx/snippets/$name.conf"); fi
  done
  render "$TEMPLATES/nginx/feedgenix.conf" >"$WORK/site.conf"
  install_file "$WORK/site.conf" "$NGINX_SITE" 0644 root root keep-prev
  if ((FG_CHANGED)); then NGINX_TOUCHED+=("$NGINX_SITE"); fi
  if [[ -n $NGINX_LINK ]]; then
    ensure_symlink "$NGINX_SITE" "$NGINX_LINK"
    if ((FG_CHANGED)); then NGINX_TOUCHED+=("$NGINX_LINK"); fi
  fi
}

nginx_revert() {
  local f
  for f in "${NGINX_TOUCHED[@]}"; do
    if [[ -f $f.feedgenix-prev ]]; then mv -f "$f.feedgenix-prev" "$f"; else rm -f "$f"; fi
  done
  nginx -t -q 2>/dev/null ||
    die "nginx -t STILL fails after reverting FeedGenix's files: investigate NOW (nginx was not reloaded; the running config is unchanged)"
}

# nginx -t, then a graceful reload. A failed test reverts every FeedGenix nginx file, so a broken
# config never waits on disk for the next legacy reload. After the reload the legacy site must answer.
nginx_apply() {
  ((${#NGINX_TOUCHED[@]} > 0 || NGINX_RELOAD)) || return 0
  if ((FG_DRY_RUN)); then
    changed "nginx -t && systemctl reload nginx"
    return 0
  fi
  if ! nginx -t >"$WORK/nginx-t.log" 2>&1; then
    cat "$WORK/nginx-t.log" >&2
    nginx_revert
    die "nginx -t failed: FeedGenix's nginx files were reverted and nginx was NOT reloaded"
  fi
  if systemctl is-active --quiet nginx; then systemctl reload nginx; else systemctl enable --now --quiet nginx; fi
  sleep 1
  if [[ $FG_MODE == shared ]] && ! legacy_answers; then
    nginx_revert
    systemctl reload nginx
    die "the legacy site stopped answering (HTTP ${LEGACY_STATUS:-none}) after the reload: FeedGenix's nginx files were reverted"
  fi
  local f
  for f in "${NGINX_TOUCHED[@]}"; do rm -f "$f.feedgenix-prev"; done
  changed "nginx reloaded (nginx -t passed$([[ $FG_MODE == shared ]] && echo "; the legacy site answers HTTP $LEGACY_STATUS"))"
  NGINX_TOUCHED=()
  NGINX_RELOAD=0
}

dns_points_here() {
  local resolved ours
  resolved=$(getent ahostsv4 "$FG_DOMAIN" | awk '{ print $1 }' | sort -u)
  ours=$(ip -4 -o addr show scope global | awk '{ split($4, a, "/"); print a[1] }' | sort -u)
  [[ -n $resolved ]] && [[ -z $(comm -23 <(printf '%s\n' "$resolved") <(printf '%s\n' "$ours")) ]]
}

setup_nginx_and_tls() {
  section "nginx vhost and TLS for $FG_DOMAIN"
  if [[ $FG_TLS == letsencrypt && -f /etc/letsencrypt/live/$FG_DOMAIN/fullchain.pem ]]; then
    use_letsencrypt_cert
  else
    self_signed_cert
  fi
  render_nginx
  nginx_apply

  if [[ $FG_TLS == letsencrypt && $FG_TLS_CERT != /etc/letsencrypt/* ]]; then
    if ((!SKIP_DNS_CHECK)) && ! dns_points_here; then
      warn "$FG_DOMAIN does not resolve to this host yet: kept the self-signed placeholder. Re-run once DNS points here."
    elif ((FG_DRY_RUN)); then
      changed "certbot certonly --webroot for $FG_DOMAIN, then switch the vhost to it"
    else
      local -a account=()
      if [[ -n $EMAIL ]]; then
        account=(--email "$EMAIL")
      elif ! compgen -G '/etc/letsencrypt/accounts/*/directory/*' >/dev/null; then
        account=(--register-unsafely-without-email)
      fi
      # --webroot never edits an nginx config. A renewal reloads nginx only if nginx -t passes.
      certbot certonly --webroot -w "$FG_ACME_ROOT" -d "$FG_DOMAIN" --non-interactive --agree-tos \
        --keep-until-expiring "${account[@]}" --deploy-hook 'nginx -t -q && systemctl reload nginx'
      changed "Let's Encrypt certificate for $FG_DOMAIN"
      use_letsencrypt_cert
      render_nginx
      nginx_apply
    fi
  fi

  # host.conf records the live certificate, so the receiver's smoke test can verify it.
  render "$TEMPLATES/host.conf" >"$WORK/host.conf"
  install_file "$WORK/host.conf" "$FG_ETC/host.conf" 0640 root "$FG_USER"
  ok "vhost $NGINX_SITE; certificate $FG_TLS_CERT"
}

# ---- dedicated servers only -----------------------------------------------------------------------
harden_host() {
  [[ $FG_MODE == dedicated ]] || return 0
  section "Host hardening (dedicated)"
  if [[ $(ufw status 2>/dev/null | head -n 1) != 'Status: active' ]]; then
    changed "ufw: deny incoming except SSH, 80 and 443"
    if ((!FG_DRY_RUN)); then
      ufw default deny incoming >/dev/null
      ufw default allow outgoing >/dev/null
      ufw allow OpenSSH >/dev/null
      ufw allow 80/tcp >/dev/null
      ufw allow 443/tcp >/dev/null
      ufw --force enable >/dev/null
    fi
  fi
  if ! systemctl is-active --quiet fail2ban 2>/dev/null; then
    changed "fail2ban (sshd jail)"
    apply systemctl enable --now --quiet fail2ban
  fi
  printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' >"$WORK/auto-upgrades"
  install_file "$WORK/auto-upgrades" /etc/apt/apt.conf.d/20auto-upgrades 0644 root root

  # Key-only SSH, but never lock the administrator out: an authorized key must exist first.
  local admin=${SUDO_USER:-root} admin_home
  admin_home=$(getent passwd "$admin" | cut -d: -f6)
  if [[ ! -s $admin_home/.ssh/authorized_keys ]]; then
    warn "no key in $admin_home/.ssh/authorized_keys: password SSH stays on (add a key, then re-run)"
    return 0
  fi
  local conf=/etc/ssh/sshd_config.d/00-feedgenix-hardening.conf
  render "$TEMPLATES/ssh/00-feedgenix-hardening.conf" >"$WORK/sshd.conf"
  install_file "$WORK/sshd.conf" "$conf" 0644 root root keep-prev
  if ((FG_CHANGED && !FG_DRY_RUN)); then
    if ! sshd -t; then
      if [[ -f $conf.feedgenix-prev ]]; then mv -f "$conf.feedgenix-prev" "$conf"; else rm -f "$conf"; fi
      die "sshd -t failed: the hardening file was reverted"
    fi
    rm -f "$conf.feedgenix-prev"
    systemctl try-reload-or-restart ssh.service
  fi
}

# ---- start ----------------------------------------------------------------------------------------
start_services() {
  section "Start"
  if ((FG_DRY_RUN)); then
    changed "enable and start ${FG_SERVICES[*]}"
    return 0
  fi
  systemctl enable --quiet "${FG_SERVICES[@]}"
  local unit
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    if systemctl is-active --quiet "$unit"; then
      systemctl restart "$unit"
      note "restarted $unit (its files changed)"
    fi
  done < <(printf '%s\n' "${RESTART[@]}" | sort -u)
  systemctl start "${FG_SERVICES[@]}"
  for unit in feedgenix-redis.service feedgenix-php.service; do
    systemctl is-active --quiet "$unit" || die "$unit did not start (journalctl -u $unit)"
  done
  ok "${FG_SERVICES[*]}"
}

main() {
  preflight
  record_legacy_baseline
  install_packages
  verify_php_modules
  setup_user_and_dirs
  setup_postgres
  setup_services
  install_tools
  install_deploy_key
  write_env
  setup_nginx_and_tls
  harden_host
  start_services

  section "Result"
  if ((FG_DRY_RUN)); then
    printf '   %d change(s) would be made. Nothing was changed.\n' "$FG_CHANGES"
    return 0
  fi
  printf '   %d change(s)\n' "$FG_CHANGES"
  section "feedgenix-check"
  /usr/local/sbin/feedgenix-check
}

main
