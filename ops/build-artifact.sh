#!/usr/bin/env bash
# Builds the release artifact the deploy receiver installs: production vendor/, the SPA build and
# nothing the server does not run (no tests, no sources, no .env). Built from HEAD, never from
# uncommitted files. Needs PHP 8.4 + Composer 2 + Node 24. Usage: ops/build-artifact.sh [OUT_DIR]
# Prints the artifact path; its sha256 is in <artifact>.sha256.
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
out_dir=$(mkdir -p "${1:-dist}" && cd "${1:-dist}" && pwd)
revision=$(git rev-parse HEAD)
if [[ -n $(git status --porcelain) ]]; then
  echo "note: uncommitted changes are NOT in the artifact (it is built from HEAD $revision)" >&2
fi

stage=$(mktemp -d)
package=$(mktemp -d)
trap 'rm -rf "$stage" "$package"' EXIT

git archive --format=tar HEAD | tar -x -C "$stage"
(cd "$stage" && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader >&2)
(cd "$stage/frontend" && npm ci --no-audit --no-fund >&2 && npm run build >&2)

for path in app bootstrap config database public resources routes vendor artisan composer.json composer.lock; do
  cp -a "$stage/$path" "$package/"
done
mkdir -p "$package/frontend/build"
cp -a "$stage/frontend/build/client" "$package/frontend/build/client"
# Only the package manifests composer wrote belong in bootstrap/cache; the server builds the rest.
find "$package/bootstrap/cache" -type f ! -name .gitignore ! -name packages.php ! -name services.php -delete
printf '%s\n' "$revision" >"$package/REVISION"

artifact=$out_dir/feedgenix-$revision.tar.gz
tar -C "$package" --owner=0 --group=0 --numeric-owner -czf "$artifact" .
sha256sum "$artifact" | cut -c1-64 >"$artifact.sha256"
echo "$artifact"
