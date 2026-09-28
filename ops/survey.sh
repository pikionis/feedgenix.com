#!/usr/bin/env bash
# shellcheck disable=SC2012 # ls lists names for a human to read, nothing parses them
# FeedGenix host survey: READ-ONLY. Prints the facts provision.sh depends on, before anything is
# changed. It prints no secrets: no file content from the legacy app, no crontab commands, no keys.
# Usage (as root): survey.sh [--legacy-dir=/var/www/feed-intelligence] [--domain=staging.feedgenix.com]
set -uo pipefail
shopt -s nullglob

legacy_dir=/var/www/feed-intelligence
domain=staging.feedgenix.com
for arg in "$@"; do
  case $arg in
    --legacy-dir=*) legacy_dir=${arg#*=} ;;
    --domain=*) domain=${arg#*=} ;;
    *)
      echo "usage: survey.sh [--legacy-dir=PATH] [--domain=HOST]" >&2
      exit 2
      ;;
  esac
done
[[ $EUID -eq 0 ]] || {
  echo "run as root (it only reads)" >&2
  exit 1
}

h() { printf '\n## %s\n' "$*"; }
run() { "$@" 2>&1 | sed 's/^/  /'; }
installed() { dpkg-query -W -f='${Status} ${Version}' "$1" 2>/dev/null | awk '/install ok installed/ { print $4 }'; }

h "System"
# shellcheck source=/dev/null
. /etc/os-release
echo "  $PRETTY_NAME, kernel $(uname -r), $(nproc) vCPU, $(awk '/^MemTotal:/ { printf "%.1f GB RAM", $2 / 1048576 }' /proc/meminfo)"
echo "  systemd $(systemctl --version | awk 'NR == 1 { print $2 }'), cgroup v2: $([[ -f /sys/fs/cgroup/cgroup.controllers ]] && echo yes || echo NO)"
run df -h / /srv /var
run uptime

h "Network and DNS"
run ip -o addr show scope global
echo "  resolv.conf nameservers: $(awk '$1 == "nameserver" { print $2 }' /etc/resolv.conf | paste -sd' ' -)"
echo "  $domain resolves to: $(getent ahosts "$domain" | awk '{ print $1 }' | sort -u | paste -sd' ' -)"

h "Listening TCP ports (address, process)"
ss -ltnpH | awk '{ match($0, /users:\(\("[^"]*"/); print "  " $4 "  " substr($0, RSTART + 9, RLENGTH - 10) }' | sort -u

h "nginx"
echo "  version: $(nginx -v 2>&1 | sed 's/.*nginx\///'), active: $(systemctl is-active nginx)"
echo "  nginx -t: $(nginx -t 2>&1 | tail -n 1)"
echo "  worker user: $(awk '$1 == "user" { gsub(";", "", $2); print $2 }' /etc/nginx/nginx.conf)"
echo "  includes: $(grep -Eo '^[[:space:]]*include[[:space:]]+[^;]+' /etc/nginx/nginx.conf | awk '{ print $2 }' | paste -sd' ' -)"
for f in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
  echo "  $f"
  grep -Eh '^[[:space:]]*(listen|server_name)[[:space:]]' "$f" | sed 's/^[[:space:]]*/      /' | sort -u
done
echo "  nginx.service drop-ins: $(ls /etc/systemd/system/nginx.service.d/ 2>/dev/null | paste -sd' ' -)"

h "PHP"
echo "  versions: $(ls /etc/php 2>/dev/null | paste -sd' ' -)"
for v in /etc/php/*; do
  v=${v##*/}
  command -v "php$v" >/dev/null || continue
  echo "  php$v: $("php$v" -r 'echo PHP_VERSION;'), modules: $("php$v" -m 2>/dev/null | grep -v '^\[' | paste -sd' ' -)"
  grep -EH '^[[:space:]]*(user|group|listen)[[:space:]]*=' /etc/php/"$v"/fpm/pool.d/*.conf 2>/dev/null | sed 's/^/    /'
  echo "    php$v-fpm: $(systemctl is-active "php$v-fpm" 2>/dev/null)"
done
echo "  would installing FeedGenix's PHP extensions upgrade or remove anything? (simulation)"
apt-get -s install --no-install-recommends php8.4-cli php8.4-pgsql php8.4-redis php8.4-intl php8.4-mbstring \
  php8.4-xml php8.4-zip php8.4-curl php8.4-bcmath 2>&1 | grep -E '^(Inst|Remv|E:)' | sed 's/^/    /'

h "Packages and apt sources"
for p in nginx php8.4-fpm postgresql-common postgresql-18 redis-server mysql-server mariadb-server supervisor varnish certbot libpq5 needrestart unattended-upgrades fail2ban ufw; do
  printf '  %-22s %s\n' "$p" "$(installed "$p")"
done
echo "  sources: $(ls /etc/apt/sources.list.d/ | paste -sd' ' -)"
echo "  PGDG configured: $(grep -rqs apt.postgresql.org /etc/apt/sources.list /etc/apt/sources.list.d/ && echo yes || echo no)"
# shellcheck disable=SC2016 # a literal $nrconf{restart} is the pattern
echo "  needrestart: $(grep -hE '^\$nrconf\{restart\}' /etc/needrestart/needrestart.conf /etc/needrestart/conf.d/*.conf 2>/dev/null | paste -sd' ' -)"
echo "  unattended upgrades: $(apt-config dump 2>/dev/null | grep -E 'APT::Periodic::Unattended-Upgrade ' | paste -sd' ' -)"

h "PostgreSQL and Redis"
command -v pg_lsclusters >/dev/null && run pg_lsclusters || echo "  no PostgreSQL"
run systemctl list-units --no-legend --all 'redis*' 'postgresql*'
command -v redis-server >/dev/null && echo "  $(redis-server --version)"

h "Supervisor (names and states only)"
if command -v supervisorctl >/dev/null; then
  supervisorctl status 2>&1 | awk '{ print "  " $1, $2 }'
  echo "  autostart:"
  supervisorctl avail 2>&1 | sed 's/^/    /'
  echo "  supervisor.service caps: $(systemctl show supervisor -p CPUQuotaPerSecUSec -p MemoryMax 2>/dev/null | paste -sd' ' -)"
else
  echo "  not installed"
fi

h "Legacy app ($legacy_dir): ownership and modes only"
for p in "$legacy_dir" "$legacy_dir/.env" "$legacy_dir/storage" "$legacy_dir/storage/app/PIPELINE_FROZEN" /var/www; do
  if [[ -e $p ]]; then stat -c '  %A %U:%G  %n' "$p"; else echo "  (missing) $p"; fi
done
echo "  users in www-data's group: $(getent group www-data | cut -d: -f4)"
echo "  a 'feedgenix' user exists already: $(id feedgenix >/dev/null 2>&1 && echo YES || echo no)"

h "SSH (effective settings)"
sshd -T 2>/dev/null | grep -Ei '^(port|permitrootlogin|passwordauthentication|kbdinteractiveauthentication|usepam|allowusers|allowgroups|denyusers|denygroups|authorizedkeysfile) ' | sed 's/^/  /'
echo "  sshd_config.d: $(ls /etc/ssh/sshd_config.d/ 2>/dev/null | paste -sd' ' -)"

h "Certificates (names and expiry only)"
if command -v certbot >/dev/null; then
  certbot certificates 2>/dev/null | grep -E 'Certificate Name|Domains|Expiry' | sed 's/^ */  /'
  echo "  certbot accounts: $(ls /etc/letsencrypt/accounts/*/directory/ 2>/dev/null | wc -l), timer: $(systemctl is-enabled certbot.timer 2>/dev/null)"
else
  echo "  certbot is not installed"
fi

h "Scheduled jobs (counts, no commands)"
echo "  root crontab: $(crontab -l -u root 2>/dev/null | grep -cvE '^[[:space:]]*(#|$)') entries ($(crontab -l -u root 2>/dev/null | grep -c artisan) mention artisan)"
echo "  /etc/cron.d: $(ls /etc/cron.d | paste -sd' ' -)"

h "Firewall"
if command -v ufw >/dev/null; then ufw status 2>&1 | head -n 12 | sed 's/^/  /'; else echo "  no ufw"; fi

h "Failed units"
systemctl --failed --no-legend | sed 's/^/  /'
echo
echo "Survey done: nothing was changed."
