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

echo "unfinished_packages"
dpkg-query() { # stub: "${Package} ${Status}" lines, as on the beta VPS plus every other state
  cat <<'EOF'
nginx install ok installed
cloudpanel install ok half-configured
cloud-init hold ok installed
systemd-timesyncd deinstall ok config-files
gone purge ok not-installed
half-thing install ok half-installed
unpacked-thing install ok unpacked
awaiting-thing install ok triggers-awaited
pending-thing install ok triggers-pending
broken-thing install reinstreq installed
EOF
}
check "half-configured (the VPS's cloudpanel), half-installed, unpacked, trigger states and reinstreq are unfinished" \
  equals $'awaiting-thing\nbroken-thing\ncloudpanel\nhalf-thing\npending-thing\nunpacked-thing' "$(unfinished_packages)"
dpkg-query() { printf 'nginx install ok installed\ncloud-init hold ok installed\nold deinstall ok config-files\n'; }
check "installed, held, removed and purged packages are not unfinished" equals "" "$(unfinished_packages)"
unset -f dpkg-query

echo "not_in_list (the --allow-unfinished check)"
check "every unfinished package must be named" equals other "$(printf 'cloudpanel\nother\n' | not_in_list cloudpanel)"
check "an empty allowance allows nothing" equals $'cloudpanel\nother' "$(printf 'cloudpanel\nother\n' | not_in_list '')"
check "names match whole, never as a prefix" equals cloudpanel "$(printf 'cloudpanel\n' | not_in_list cloud)"
check "a list of several names" equals "" "$(printf 'b\na\n' | not_in_list a,b)"

echo "php_modules"
mkdir -p "$WORK/bin"
cat >"$WORK/bin/php9.9" <<'EOF'
#!/bin/sh
printf '[PHP Modules]\nCore\nSimpleXML\npdo_pgsql\n\n[Zend Modules]\nZend OPcache\n\n'
EOF
chmod +x "$WORK/bin/php9.9"
check "lower case, one per line, without section headers or blank lines" \
  equals $'core\nsimplexml\npdo_pgsql\nzend opcache' "$(PATH="$WORK/bin:$PATH" php_modules 9.9 cli)"
check "a missing binary loads nothing" equals "" "$(PATH="$WORK/bin:$PATH" php_modules 9.9 fpm)"
printf '#!/bin/sh\necho "[PHP Modules]"\nexit 1\n' >"$WORK/bin/php9.8"
chmod +x "$WORK/bin/php9.8"
check "a PHP that lists nothing and fails is not a failure under errexit and pipefail" \
  bash -c "set -Eeuo pipefail; PATH='$WORK/bin':\$PATH; $(declare -f php_modules); m=\$(php_modules 9.8 cli); [[ -z \$m ]]"

echo "php_packages_needed"
all_modules='core pdo_mysql redis intl mbstring dom simplexml xmlreader xmlwriter zip curl bcmath'
php_modules() { tr ' ' '\n' <<<"$all_modules"; } # stub: CloudPanel's PHP 8.4 (redis built in, no pgsql)
check "CloudPanel's PHP needs only php8.4-pgsql, never the php8.4-redis it does not have" \
  equals php8.4-pgsql "$(php_packages_needed 8.4)"
php_modules() { if [[ $2 == fpm ]]; then tr ' ' '\n' <<<"${all_modules/ intl/}"; else tr ' ' '\n' <<<"$all_modules"; fi; }
check "a module PHP-FPM lacks is needed even when the CLI loads it" \
  equals $'php8.4-intl\nphp8.4-pgsql' "$(php_packages_needed 8.4)"
php_modules() { if [[ $2 == cli ]]; then tr ' ' '\n' <<<"${all_modules/ zip/}"; else tr ' ' '\n' <<<"$all_modules"; fi; }
check "a module the CLI lacks (artisan, the worker) is needed even when PHP-FPM loads it" \
  equals $'php8.4-pgsql\nphp8.4-zip' "$(php_packages_needed 8.4)"
php_modules() { :; }
check "without PHP every package is needed, and the CLI" \
  equals $'php8.4-bcmath\nphp8.4-cli\nphp8.4-curl\nphp8.4-intl\nphp8.4-mbstring\nphp8.4-pgsql\nphp8.4-redis\nphp8.4-xml\nphp8.4-zip' \
  "$(php_packages_needed 8.4)"
php_modules() { tr ' ' '\n' <<<"$all_modules pdo_pgsql"; }
check "a PHP that loads every module needs nothing" equals "" "$(php_packages_needed 8.4)"
unset -f php_modules
check "survey.sh simulates the same PHP modules provision.sh needs" \
  equals "${FG_PHP_MODULES[*]}" "$(sed -n 's/^PHP_MODULES=(\(.*\))$/\1/p' "$OPS/survey.sh")"

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
check "feed URLs (/f/) reach Laravel, not the SPA shell" \
  bash -c "grep -A2 'location ^~ /f/ {' '$WORK/site.conf' | grep -q 'include /etc/nginx/snippets/feedgenix-php.conf;'"
render "$OPS/templates/nginx/feedgenix-php.conf" >"$WORK/php.conf"
check "PHP runs the resolved release path (\$realpath_root) on FeedGenix's own socket" \
  bash -c "grep -q 'SCRIPT_FILENAME \$realpath_root/index.php;' '$WORK/php.conf' && grep -q 'fastcgi_pass unix:/run/feedgenix-php/php-fpm.sock;' '$WORK/php.conf'"
check "PHP gets FeedGenix's own FastCGI parameters, never the host's fastcgi_params (CloudPanel adds buffers there)" \
  bash -c "! grep -Eq '^[[:space:]]*include[[:space:]]' '$WORK/php.conf' && [[ \$(grep -c '^fastcgi_buffers ' '$WORK/php.conf') == 1 ]]"
check "every FastCGI parameter is set once" \
  bash -c "[[ -z \$(grep -o '^fastcgi_param [A-Z_]*' '$WORK/php.conf' | sort | uniq -d) ]] && grep -q '^fastcgi_param REQUEST_METHOD ' '$WORK/php.conf' && grep -q '^fastcgi_param QUERY_STRING ' '$WORK/php.conf'"
render "$OPS/templates/systemd/sandbox.conf" >"$WORK/sandbox.conf"
check "the sandbox denies loopback and the host's own addresses, allowing only the DNS stub" \
  bash -c "grep -qx 'IPAddressDeny=localhost 203.0.113.7/32' '$WORK/sandbox.conf' && grep -qx 'IPAddressAllow=127.0.0.53/32' '$WORK/sandbox.conf'"
render "$OPS/templates/env.template" >"$WORK/env"
check "the .env reaches PostgreSQL and Redis over unix sockets only" \
  bash -c "grep -qx 'DB_HOST=/var/run/postgresql' '$WORK/env' && grep -qx 'REDIS_HOST=/run/feedgenix-redis/redis.sock' '$WORK/env' && grep -qx 'DB_PORT=5433' '$WORK/env'"
check "the .env never enables debug output" grep -qx 'APP_DEBUG=false' "$WORK/env"
check "a new server starts with AI switched off and never the fake provider" \
  bash -c "grep -qx 'FEEDGENIX_AI_ENABLED=false' '$WORK/env' && ! grep -q 'FEEDGENIX_AI_FAKE' '$WORK/env'"

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
check "a free port is not a failure under errexit and pipefail (a fresh server's preflight)" \
  bash -c "set -Eeuo pipefail; ss() { :; }; $(declare -f port_owners); owners=\$(port_owners 80); [[ -z \$owners ]]"

echo "sudo_allowed"
cat >"$WORK/sudo-listing" <<'EOF'
Matching Defaults entries for feedgenix on srv1560229:
    env_reset, mail_badpass, use_pty

User feedgenix may run the following commands on srv1560229:
    (ALL) NOPASSWD: /usr/bin/clpctlWrapper
EOF
sudo_stub() { # the beta VPS: CloudPanel's "ALL ALL=(ALL) NOPASSWD: /usr/bin/clpctlWrapper"; $1 = deny-all drop-in present
  printf 'sudo() { if [[ $# -eq 4 ]]; then cat %q; elif [[ $5 == /usr/bin/clpctlWrapper ]]; then %s; else return 1; fi; }' \
    "$WORK/sudo-listing" "$([[ $1 == denied ]] && echo 'return 1' || echo 'echo /usr/bin/clpctlWrapper')"
}
check "the VPS's CloudPanel rule lets every user, feedgenix included, run its wrapper as root" \
  bash -c "$(sudo_stub allowed); $(declare -f sudo_allowed); [[ \$(sudo_allowed feedgenix) == /usr/bin/clpctlWrapper ]]"
check "with the deny-all drop-in nothing is allowed, although the listing still shows the rule" \
  bash -c "$(sudo_stub denied); $(declare -f sudo_allowed); [[ -z \$(sudo_allowed feedgenix) ]]"
check "a user without any rule may run nothing" \
  bash -c "sudo() { echo 'User feedgenix is not allowed to run sudo on host.'; }; $(declare -f sudo_allowed); [[ -z \$(sudo_allowed feedgenix) ]]"
check "a rule for ALL commands is caught through /bin/sh" \
  bash -c "sudo() { if [[ \$# -eq 4 ]]; then printf 'User feedgenix may run the following commands on h:\n    (ALL) NOPASSWD: ALL\n'; else echo \$5; fi; }; $(declare -f sudo_allowed); [[ \$(sudo_allowed feedgenix) == /bin/sh ]]"

echo "host_has_ipv6"
check "a global IPv6 address (the beta VPS) means nginx listens on [::] too" \
  bash -c "ip() { [[ \$* == '-6 -o addr show scope global' ]] && echo '2: eth0 inet6 2a02:4780:41:9ed5::1/48 scope global'; }; $(declare -f host_has_ipv6); host_has_ipv6"
check "only link-local or loopback IPv6 (nothing global) means IPv4 only" \
  bash -c "ip() { :; }; $(declare -f host_has_ipv6); ! host_has_ipv6"

printf '\n%d passed, %d failed\n' "$passed" "$failed"
((failed == 0))
