#!/usr/bin/env bash
# shellcheck disable=SC2016,SC2329 # literal ${NAME} templates; stubs are called by the code under test
# Unit tests for ops/lib/common.sh and the templates. No root, no network: runs in CI's lint job and
# on a laptop (Git Bash works). Usage: ops/tests/unit.sh
set -Euo pipefail

OPS=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
# shellcheck source=../lib/common.sh
. "$OPS/lib/common.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# On a Windows laptop (Git Bash) envsubst is a native program: stop MSYS from rewriting /paths in its
# environment, and drop the CRLF it writes. Servers and CI run Linux, where neither happens.
case $(uname -s) in
  MINGW* | MSYS*)
    export MSYS2_ENV_CONV_EXCL='*'
    envsubst() { command envsubst "$@" | tr -d '\r'; }
    export -f envsubst
    ;;
esac

passed=0
failed=0
check() { # LABEL COMMAND…
  local label=$1
  shift
  if "$@" >"$WORK/out" 2>&1; then
    passed=$((passed + 1))
    printf '  ✓ %s\n' "$label"
  else
    failed=$((failed + 1))
    printf '  ✗ %s\n' "$label"
    sed 's/^/      /' "$WORK/out"
  fi
}
equals() { # EXPECTED ACTUAL
  [[ $1 == "$2" ]] || {
    printf 'expected: %s\nactual:   %s\n' "$1" "$2"
    return 1
  }
}

echo "apt_disruptions"
cat >"$WORK/apt-sim" <<'EOF'
NOTE: This is only a simulation!
Reading package lists...
Inst libpq5 [16.4-0ubuntu0.24.04.2] (18.0-1.pgdg24.04+1 PostgreSQL for Debian/Ubuntu repository:noble-pgdg [amd64])
Inst postgresql-18 (18.0-1.pgdg24.04+1 PostgreSQL for Debian/Ubuntu repository:noble-pgdg [amd64])
Remv php8.3-fpm [8.3.6-0ubuntu0.24.04.5]
Conf postgresql-18 (18.0-1.pgdg24.04+1 PostgreSQL for Debian/Ubuntu repository:noble-pgdg [amd64])
EOF
check "an upgrade and a removal are reported, a new install is not" \
  equals $'upgrade libpq5\nremove php8.3-fpm' "$(apt_disruptions <"$WORK/apt-sim")"
check "a simulation with only new installs reports nothing" \
  equals "" "$(grep -v -e '^Inst libpq5' -e '^Remv' "$WORK/apt-sim" | apt_disruptions)"

echo "render"
printf 'server_name ${FG_DOMAIN};\nreturn 301 https://$host$request_uri;\nMAIL_FROM_NAME="${APP_NAME}"\ntry_files $uri =404;\n' >"$WORK/t1"
check "fills \${FG_*} and leaves nginx's and Laravel's own variables alone" \
  equals $'server_name staging.feedgenix.test;\nreturn 301 https://$host$request_uri;\nMAIL_FROM_NAME="${APP_NAME}"\ntry_files $uri =404;' \
  "$(FG_DOMAIN=staging.feedgenix.test render "$WORK/t1")"
printf 'root ${FG_NOT_SET_ANYWHERE};\n' >"$WORK/t2"
check "a placeholder that is not set is an error that names it, not an empty value" \
  bash -c "out=\$( (. '$OPS/lib/common.sh'; render '$WORK/t2') 2>&1 ); [[ \$? -ne 0 && \$out == *'FG_NOT_SET_ANYWHERE}, which is not set'* ]]"
check "an empty value is allowed when it is set on purpose" \
  equals 'x=' "$(FG_EMPTY='' bash -c ". '$OPS/lib/common.sh'; printf 'x=\${FG_EMPTY}\n' >'$WORK/t3'; render '$WORK/t3'")"

echo "every template renders with the variables provision.sh sets"
# The same names provision.sh assigns before it renders; a typo in a template fails here.
export FG_MODE=shared FG_DOMAIN=staging.feedgenix.test FG_TLS=self-signed FG_APP_ENV=production \
  FG_LEGACY_DIR=/var/www/feed-intelligence 'FG_LEGACY_URL=https://legacy.test/?a=1&b=2' \
  FG_LEGACY_FREEZE_FILE=/var/www/feed-intelligence/storage/app/PIPELINE_FROZEN FG_PG_PORT=5433 \
  FG_TLS_CERT=/etc/feedgenix/tls/cert.pem FG_TLS_KEY=/etc/feedgenix/tls/key.pem FG_TLS_CA=/etc/feedgenix/tls/cert.pem \
  FG_NGINX_USER=www-data FG_NGINX_GROUP=www-data FG_APP_KEY=base64:dGVzdA== \
  FG_PG_SHARED_BUFFERS=1GB FG_PG_EFFECTIVE_CACHE_SIZE=4GB FG_CPU_QUOTA=400% FG_MEMORY_HIGH=6G FG_MEMORY_MAX=8G \
  FG_FPM_MAX_CHILDREN=16 'FG_INACCESSIBLE_PATHS=-/var/www -/etc/supervisor' 'FG_HOST_ADDRESSES=203.0.113.7/32' \
  FG_HTTP2_LISTEN=' http2' 'FG_HTTP2_DIRECTIVE=# http2 on the listen lines' 'FG_IPV6_LISTEN_80=listen [::]:80;' \
  'FG_IPV6_LISTEN_443=listen [::]:443 ssl http2;' 'FG_NOINDEX_HEADER=add_header X-Robots-Tag "noindex, nofollow" always;' \
  'FG_ROBOTS_TXT=User-agent: *\nDisallow: /\n'
while IFS= read -r template; do
  name=${template#"$OPS/templates/"}
  check "$name" bash -c ". '$OPS/lib/common.sh'; render '$template' >'$WORK/rendered' && ! grep -n '\${FG_' '$WORK/rendered'"
done < <(find "$OPS/templates" -type f | sort)

render "$OPS/templates/host.conf" >"$WORK/host.conf"
check "host.conf sources back to the same values (a URL with & and ? survives)" \
  bash -c "unset FG_LEGACY_URL FG_PG_PORT; . '$WORK/host.conf'; [[ \$FG_LEGACY_URL == 'https://legacy.test/?a=1&b=2' && \$FG_PG_PORT == 5433 ]]"
render "$OPS/templates/nginx/feedgenix.conf" >"$WORK/site.conf"
check "the vhost serves the release through the current symlink, uncached" \
  bash -c "grep -q 'root /srv/feedgenix/current/frontend/build/client;' '$WORK/site.conf' && grep -q 'open_file_cache off;' '$WORK/site.conf'"
render "$OPS/templates/nginx/feedgenix-php.conf" >"$WORK/php.conf"
check "PHP runs the resolved release path (\$realpath_root) on FeedGenix's own socket" \
  bash -c "grep -q 'SCRIPT_FILENAME \$realpath_root/index.php;' '$WORK/php.conf' && grep -q 'fastcgi_pass unix:/run/feedgenix-php/php-fpm.sock;' '$WORK/php.conf'"
render "$OPS/templates/systemd/sandbox.conf" >"$WORK/sandbox.conf"
check "the sandbox denies loopback and the host's own addresses, allowing only the DNS stub" \
  bash -c "grep -qx 'IPAddressDeny=localhost 203.0.113.7/32' '$WORK/sandbox.conf' && grep -qx 'IPAddressAllow=127.0.0.53/32' '$WORK/sandbox.conf'"
render "$OPS/templates/env.template" >"$WORK/env"
check "the .env reaches PostgreSQL and Redis over unix sockets only" \
  bash -c "grep -qx 'DB_HOST=/var/run/postgresql' '$WORK/env' && grep -qx 'REDIS_HOST=/run/feedgenix-redis/redis.sock' '$WORK/env' && grep -qx 'DB_PORT=5433' '$WORK/env'"
check "the .env never enables debug output" grep -qx 'APP_DEBUG=false' "$WORK/env"

echo "legacy_answers"
curl() { # stub: record the arguments, answer like a live site
  printf '%s\n' "$@" >"$WORK/curl-args"
  printf '302'
}
FG_LEGACY_URL=https://legacy.test/dashboard
check "asks the local web server (127.0.0.1) for the legacy host on 443" \
  bash -c "$(declare -f curl legacy_answers); WORK='$WORK' FG_LEGACY_URL='$FG_LEGACY_URL' legacy_answers && grep -qx 'legacy.test:443:127.0.0.1' '$WORK/curl-args'"
check "an explicit port is kept" \
  bash -c "$(declare -f curl legacy_answers); WORK='$WORK' FG_LEGACY_URL='http://legacy.test:8080/' legacy_answers && grep -qx 'legacy.test:8080:127.0.0.1' '$WORK/curl-args'"
check "a 5xx answer is not 'answering'" \
  bash -c "curl() { printf '502'; }; $(declare -f legacy_answers); FG_LEGACY_URL='https://legacy.test/' ; ! legacy_answers"
check "no answer at all is not 'answering'" \
  bash -c "curl() { printf '000'; return 7; }; $(declare -f legacy_answers); FG_LEGACY_URL='https://legacy.test/'; ! legacy_answers"
unset -f curl

echo "port_owners"
ss() {
  cat <<'EOF'
LISTEN 0      511          0.0.0.0:443        0.0.0.0:*    users:(("nginx",pid=812,fd=9),("nginx",pid=811,fd=9))
LISTEN 0      511             [::]:443           [::]:*    users:(("nginx",pid=812,fd=10),("nginx",pid=811,fd=10))
EOF
}
check "nginx on 443 is reported once" equals nginx "$(port_owners 443)"
ss() { printf 'LISTEN 0 1024 0.0.0.0:80 0.0.0.0:* users:(("varnishd",pid=90,fd=6))\n'; }
check "another process on 80 is named" equals varnishd "$(port_owners 80)"
ss() { :; }
check "a free port has no owner" equals "" "$(port_owners 80)"
unset -f ss

printf '\n%d passed, %d failed\n' "$passed" "$failed"
((failed == 0))
