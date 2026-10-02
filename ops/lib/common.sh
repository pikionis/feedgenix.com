# shellcheck shell=bash disable=SC2034 # the FG_* layout is read by the sourcing scripts and templates
# Shared by the FeedGenix ops scripts (provision.sh, check.sh, the receiver). Sourced, never executed.

# ---- fixed layout (ADR 0009, ADR 0011) ------------------------------------------------------------
FG_PHP_VERSION=8.4
FG_PG_VERSION=18
FG_USER=feedgenix
FG_ROOT=/srv/feedgenix
FG_ETC=/etc/feedgenix
FG_LIB=/usr/local/lib/feedgenix
FG_STATE=/var/lib/feedgenix-ops
FG_ACME_ROOT=/var/lib/feedgenix-acme
FG_BASELINE=$FG_STATE/legacy-baseline
FG_REDIS_SOCKET=/run/feedgenix-redis/redis.sock
FG_PHP_SOCKET_DIR=/run/feedgenix-php
FG_PG_SOCKET_DIR=/var/run/postgresql
FG_SERVICES=(feedgenix-redis.service feedgenix-php.service feedgenix-worker@default.service feedgenix-schedule.timer)

# ---- output ---------------------------------------------------------------------------------------
FG_DRY_RUN=${FG_DRY_RUN:-0}
FG_CHANGES=0
FG_CHANGED=0 # 1 when the last install_file / ensure_* call changed (or, in a dry run, would change) something

section() { printf '\n== %s\n' "$*"; }
ok() { printf '   ok      %s\n' "$*"; }
note() { printf '   note    %s\n' "$*"; }
warn() { printf '   WARN    %s\n' "$*"; }
die() {
  printf '\nERROR: %s\n' "$*" >&2
  exit 1
}
changed() {
  FG_CHANGES=$((FG_CHANGES + 1))
  FG_CHANGED=1
  if ((FG_DRY_RUN)); then printf '   WOULD   %s\n' "$*"; else printf '   done    %s\n' "$*"; fi
}

# Run a command that changes the host; in a dry run only show it.
apply() {
  if ((FG_DRY_RUN)); then
    printf '           $ %s\n' "$*"
  else
    "$@"
  fi
}

# ---- templates and files --------------------------------------------------------------------------

# Print TEMPLATE with its ${FG_*} placeholders filled in. Every placeholder must be set: a typo fails
# here instead of leaving an empty value in a live config. envsubst replaces only the listed names,
# so nginx's own $variables pass through untouched.
render() {
  local template=$1 name
  local -a names=()
  [[ -f $template ]] || die "template not found: $template"
  # shellcheck disable=SC2016 # the pattern is a literal ${FG_NAME}
  while IFS= read -r name; do
    [[ -n $name ]] || continue
    [[ -v $name ]] || die "template ${template##*/} uses \${$name}, which is not set"
    names+=("$name")
  done < <(grep -o '\${FG_[A-Z0-9_]*}' "$template" | tr -d '${}' | sort -u)

  if ((${#names[@]} == 0)); then
    cat "$template"
    return
  fi
  (
    for name in "${names[@]}"; do export "${name?}"; done
    # shellcheck disable=SC2016 # envsubst's SHELL-FORMAT is a literal list of ${NAME}s
    envsubst "$(printf '${%s} ' "${names[@]}")" <"$template"
  )
}

# install_file SOURCE DEST MODE OWNER GROUP [keep-prev] — put SOURCE at DEST when the content, mode or
# ownership differ. With keep-prev the old file is kept as DEST.feedgenix-prev for a revert.
# Shows a diff in a dry run. Sets FG_CHANGED.
install_file() {
  local src=$1 dest=$2 mode=$3 owner=$4 group=$5 keep=${6:-}
  FG_CHANGED=0
  if [[ -f $dest ]] && cmp -s "$src" "$dest" &&
    [[ $(stat -c '%a:%U:%G' "$dest") == "${mode#0}:$owner:$group" ]]; then
    return 0
  fi
  if ((FG_DRY_RUN)); then
    changed "$dest"
    if [[ -f $dest ]]; then
      diff -u "$dest" "$src" | sed 's/^/           /' || true
    else
      sed 's/^/           + /' "$src"
    fi
    return 0
  fi
  if [[ -n $keep && -f $dest ]]; then cp -p "$dest" "$dest.feedgenix-prev"; fi
  install -D -m "$mode" -o "$owner" -g "$group" "$src" "$dest.feedgenix-new"
  mv -f "$dest.feedgenix-new" "$dest"
  changed "$dest"
}

# install_secret_file SOURCE DEST MODE OWNER GROUP — like install_file, but only when DEST is missing,
# and never shows the content.
install_secret_file() {
  local src=$1 dest=$2 mode=$3 owner=$4 group=$5
  FG_CHANGED=0
  [[ -e $dest ]] && return 0
  changed "$dest (secrets generated here, never shown)"
  if ((!FG_DRY_RUN)); then
    install -m "$mode" -o "$owner" -g "$group" "$src" "$dest"
  fi
}

# ensure_dir PATH MODE OWNER GROUP
ensure_dir() {
  local path=$1 mode=$2 owner=$3 group=$4
  FG_CHANGED=0
  if [[ -d $path && ! -L $path ]] && [[ $(stat -c '%a:%U:%G' "$path") == "${mode#0}:$owner:$group" ]]; then
    return 0
  fi
  changed "directory $path ($mode $owner:$group)"
  apply install -d -m "$mode" -o "$owner" -g "$group" "$path"
}

# ensure_symlink TARGET LINK
ensure_symlink() {
  local target=$1 link=$2
  FG_CHANGED=0
  if [[ -L $link && $(readlink "$link") == "$target" ]]; then return 0; fi
  changed "link $link -> $target"
  apply ln -sfn "$target" "$link"
}

# ---- apt ------------------------------------------------------------------------------------------

# The packages an `apt-get -s` simulation (on stdin) would upgrade or remove, one per line.
# "Inst pkg [old] (new …)" is an upgrade; "Inst pkg (new …)" a new install; "Remv pkg …" a removal.
apt_disruptions() {
  awk '/^Inst [^ ]+ \[/ { print "upgrade " $2 } /^Remv / { print "remove " $2 }'
}

# Packages dpkg left unfinished (half-installed, unpacked, half-configured, awaiting or pending
# triggers, or flagged for reinstallation), one per line. The next apt run of any kind finishes them:
# their maintainer scripts run again, and a script that calls systemctl itself is beyond policy-rc.d.
unfinished_packages() {
  dpkg-query -W -f='${Package} ${Status}\n' 2>/dev/null |
    awk '$3 != "ok" || ($4 != "installed" && $4 != "not-installed" && $4 != "config-files") { print $1 }' |
    sort -u
}

# The names on stdin that ALLOWED (comma-separated) does not list; whole names only.
not_in_list() {
  awk -v allowed="$1" 'BEGIN { n = split(allowed, a, ","); for (i = 1; i <= n; i++) ok[a[i]] = 1 }
    NF && !($1 in ok) { print $1 }'
}

# ---- PHP ------------------------------------------------------------------------------------------

# The PHP modules FeedGenix needs (module:package suffix). survey.sh keeps the same list.
FG_PHP_MODULES=(pdo_pgsql:pgsql redis:redis intl:intl mbstring:mbstring dom:xml simplexml:xml xmlreader:xml
  xmlwriter:xml zip:zip curl:curl bcmath:bcmath)

# php_modules VERSION cli|fpm — the modules that PHP binary loads, lower case, one per line; nothing
# when the binary is missing.
php_modules() {
  local bin=php$1
  if [[ $2 == fpm ]]; then bin=php-fpm$1; fi
  command -v "$bin" >/dev/null 2>&1 || return 0
  "$bin" -m 2>/dev/null | grep -v -e '^\[' -e '^$' | tr '[:upper:]' '[:lower:]'
}

# php_packages_needed VERSION — the php<VERSION>-* packages to install: the package of every module
# the CLI or PHP-FPM does not load yet, and the CLI itself when it is missing. Chosen by what is
# missing, never by package name: a control panel's PHP build may contain a module without shipping
# the package Ubuntu would put it in (CloudPanel's PHP 8.4 loads redis; php8.4-redis does not exist).
php_packages_needed() {
  local version=$1 cli fpm entry module
  cli=$(php_modules "$version" cli)
  fpm=$(php_modules "$version" fpm)
  {
    if [[ -z $cli ]]; then printf 'php%s-cli\n' "$version"; fi
    for entry in "${FG_PHP_MODULES[@]}"; do
      module=${entry%%:*}
      if ! grep -qx "$module" <<<"$cli" || ! grep -qx "$module" <<<"$fpm"; then
        printf 'php%s-%s\n' "$version" "${entry#*:}"
      fi
    done
  } | sort -u
}

# ---- the legacy app (shared mode) -----------------------------------------------------------------

# 0 when the legacy site answers the local web server with any HTTP status below 500.
legacy_answers() {
  local url=${FG_LEGACY_URL:?} scheme hostport host port
  scheme=${url%%://*}
  hostport=${url#*://}
  hostport=${hostport%%/*}
  host=${hostport%%:*}
  port=${hostport##*:}
  if [[ $port == "$hostport" ]]; then
    if [[ $scheme == https ]]; then port=443; else port=80; fi
  fi
  LEGACY_STATUS=$(curl -s -k -o /dev/null -w '%{http_code}' --max-time 15 \
    --resolve "$host:$port:127.0.0.1" "$url" || true)
  [[ $LEGACY_STATUS =~ ^[1-4][0-9][0-9]$ ]]
}

# Content hashes of everything the frozen legacy app depends on (never its content: secrets are
# hashed, not read out). FeedGenix's own files are skipped by name.
legacy_fingerprint() {
  local f
  local -a files=(
    /etc/nginx/nginx.conf /etc/nginx/sites-enabled/* /etc/nginx/sites-available/* /etc/nginx/conf.d/*
    /etc/nginx/snippets/* /etc/systemd/system/nginx.service.d/*
    /etc/supervisor/supervisord.conf /etc/supervisor/conf.d/*
    /etc/php/*/fpm/php-fpm.conf /etc/php/*/fpm/pool.d/*
    /etc/redis/redis.conf /etc/mysql/my.cnf /etc/mysql/mysql.conf.d/* /etc/varnish/*
    /var/spool/cron/crontabs/root /etc/crontab
    "${FG_LEGACY_DIR:?}/.env" "${FG_LEGACY_FREEZE_FILE:?}"
  )
  for f in "${files[@]}"; do
    [[ -f $f ]] || continue
    case ${f##*/} in feedgenix* | *.feedgenix-prev | *.feedgenix-new) continue ;; esac
    printf '%s  %s\n' "$(sha256sum <"$f" | cut -c1-64)" "$f"
  done | sort -k2
}

# ---- host facts -----------------------------------------------------------------------------------

nginx_version() { nginx -v 2>&1 | sed -n 's|.*nginx/\([0-9.]*\).*|\1|p'; }

# The user nginx workers run as (www-data on Debian/Ubuntu).
nginx_user() {
  local user
  user=$(awk '$1 == "user" { gsub(";", "", $2); print $2; exit }' /etc/nginx/nginx.conf 2>/dev/null)
  printf '%s\n' "${user:-www-data}"
}

# Where site configs go: sites-enabled when nginx.conf includes it, else conf.d. Before nginx is
# installed (a dry run on a fresh server) Ubuntu's package layout: sites-enabled.
nginx_sites_layout() {
  if [[ ! -f /etc/nginx/nginx.conf ]]; then
    echo sites-enabled
  elif grep -Eq '^[[:space:]]*include[[:space:]]+/etc/nginx/sites-enabled/' /etc/nginx/nginx.conf; then
    echo sites-enabled
  elif grep -Eq '^[[:space:]]*include[[:space:]]+/etc/nginx/conf\.d/' /etc/nginx/nginx.conf; then
    echo conf.d
  else
    echo unknown
  fi
}

# The processes listening on a TCP port, e.g. "nginx" or "varnishd,nginx"; empty when free.
port_owners() {
  ss -ltnpH "sport = :$1" 2>/dev/null | grep -o '(("[^"]*"' | tr -d '("' | sort -u | paste -sd, -
}

# Loopback DNS resolvers from /etc/resolv.conf (systemd-resolved: 127.0.0.53).
loopback_resolvers() {
  awk '$1 == "nameserver" && $2 ~ /^127\./ { print $2 }' /etc/resolv.conf 2>/dev/null | sort -u
}
