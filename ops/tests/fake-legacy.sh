#!/usr/bin/env bash
# CI only. Turns a fresh Ubuntu 24.04 runner into a stand-in for the beta VPS: a "legacy" site on
# nginx + PHP 8.4-FPM (www-data) with a secret .env and a freeze file, a stopped Supervisor worker, a
# Redis holding a pause flag, a root crontab and an nginx drop-in. The rehearsal then proves that
# provisioning and deploys never touch any of it. Usage: sudo ops/tests/fake-legacy.sh
set -Eeuo pipefail
on_error() {
  local src=${BASH_SOURCE[1]:-$0}
  printf '\nERROR: %s line %s: %s (exit %s)\n' "${src##*/}" "$1" "$3" "$2" >&2
}
trap 'on_error "$LINENO" "$?" "$BASH_COMMAND"' ERR
[[ $EUID -eq 0 ]] || {
  echo "run with sudo" >&2
  exit 1
}
export DEBIAN_FRONTEND=noninteractive
legacy=/var/www/feed-intelligence

# The runner's preinstalled web server must not hold port 80.
systemctl disable --now apache2 >/dev/null 2>&1 || true
if [[ ! $(apt-cache policy php8.4-fpm 2>/dev/null) =~ Candidate:\ [0-9] ]]; then
  add-apt-repository -y ppa:ondrej/php >/dev/null
fi
apt-get update -q >/dev/null
apt-get install -y -q nginx php8.4-fpm php8.4-cli supervisor redis-server >/dev/null

# The app: www-data only, like an ordinary Laravel deployment.
install -d -m 0750 -o www-data -g www-data "$legacy" "$legacy/public" "$legacy/storage" "$legacy/storage/app"
printf '<?php echo "legacy ok";\n' >"$legacy/public/index.php"
printf 'ONLY_THE_LEGACY_APP_MAY_READ_THIS=yes\n' >"$legacy/.env"
touch "$legacy/storage/app/PIPELINE_FROZEN"
chown -R www-data:www-data "$legacy"
chmod 0640 "$legacy/.env"

# Like the beta VPS's CloudPanel nginx: its fastcgi_params also sets buffers, so a vhost that includes
# it and sets its own fastcgi_buffers fails nginx -t ("directive is duplicate").
printf 'fastcgi_buffers 256 16k;\nfastcgi_buffer_size 64k;\nfastcgi_max_temp_file_size 0;\n' >>/etc/nginx/fastcgi_params

# Like the beta VPS's CloudPanel: a sudoers rule that lets every user run a wrapper as root
# (CloudPanel's is "ALL ALL=(ALL) NOPASSWD: /usr/bin/clpctlWrapper").
printf '#!/bin/sh\necho "fake panel wrapper ran"\n' >/usr/bin/fake-panel-wrapper
chmod 0755 /usr/bin/fake-panel-wrapper
printf 'ALL ALL=(ALL) NOPASSWD: /usr/bin/fake-panel-wrapper\n' >/etc/sudoers.d/fake-panel
chmod 0440 /etc/sudoers.d/fake-panel
visudo -cq

openssl req -x509 -newkey rsa:2048 -nodes -days 7 -subj /CN=legacy.test -addext subjectAltName=DNS:legacy.test \
  -keyout /etc/ssl/private/legacy.key -out /etc/ssl/certs/legacy.pem 2>/dev/null
rm -f /etc/nginx/sites-enabled/default
cat >/etc/nginx/sites-available/legacy <<'EOF'
# The stand-in legacy app: HTTP/2 on the listen line and a default server, like older configs.
server {
    listen 80 default_server;
    listen 443 ssl http2 default_server;
    listen 127.0.0.1:8443;
    server_name legacy.test;
    ssl_certificate /etc/ssl/certs/legacy.pem;
    ssl_certificate_key /etc/ssl/private/legacy.key;
    root /var/www/feed-intelligence/public;

    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
EOF
ln -sfn /etc/nginx/sites-available/legacy /etc/nginx/sites-enabled/legacy
install -d /etc/systemd/system/nginx.service.d
printf '[Service]\n# stand-in for the legacy self-heal drop-in\nExecStartPre=-/bin/true\n' >/etc/systemd/system/nginx.service.d/self-heal.conf

cat >/etc/supervisor/conf.d/legacy-worker.conf <<'EOF'
[program:legacy-worker]
command=/bin/sleep infinity
user=www-data
autostart=false
autorestart=false
EOF

systemctl daemon-reload
systemctl restart php8.4-fpm nginx redis-server supervisor
# supervisord answers on its socket a moment after systemd starts it (and supervisorctl reports
# errors on stdout): wait for it rather than race it.
wait_for() { # SECONDS DESCRIPTION COMMAND…
  local deadline=$((SECONDS + $1)) what=$2
  shift 2
  until "$@" >/dev/null 2>&1; do
    if ((SECONDS >= deadline)); then
      echo "fake-legacy.sh: timed out waiting for $what" >&2
      "$@" >&2 || true
      exit 1
    fi
    sleep 1
  done
}
wait_for 30 supervisord supervisorctl pid
supervisorctl reread
supervisorctl update
wait_for 15 "Redis" redis-cli ping
redis-cli set legacy:pipeline:paused 1 >/dev/null
printf '# the frozen legacy app: nothing scheduled\n' | crontab -u root -

legacy_ok() { [[ $(curl -sk --max-time 5 --resolve legacy.test:443:127.0.0.1 https://legacy.test/) == 'legacy ok' ]]; }
wait_for 30 "https://legacy.test/ to answer 'legacy ok'" legacy_ok
worker_stopped() { [[ $(supervisorctl status legacy-worker | awk '{ print $2 }') == STOPPED ]]; }
wait_for 15 "legacy-worker to be STOPPED" worker_stopped
echo "legacy stand-in ready: https://legacy.test/ answers, legacy-worker STOPPED, pause flag set"
