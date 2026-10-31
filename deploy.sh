#!/usr/bin/env bash
set -euo pipefail

cd "${FORGE_SITE_PATH:-$(dirname "$0")}"
SCHOOLDAY_PHP="${FORGE_PHP:-php8.4}"
SCHOOLDAY_BRANCH="${FORGE_SITE_BRANCH:-codex/initial-deploy}"

if [ -f vendor/autoload.php ]; then
    "$SCHOOLDAY_PHP" artisan down --retry=60 --no-interaction
fi
trap '"$SCHOOLDAY_PHP" artisan up --no-interaction >/dev/null 2>&1 || true' EXIT

git pull --ff-only origin "$SCHOOLDAY_BRANCH"
"$SCHOOLDAY_PHP" artisan config:clear --no-interaction

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --no-audit --no-fund
NODE_OPTIONS=--max-old-space-size=256 npm run build

mkdir -p database
if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
    chmod 600 database/database.sqlite
fi

"$SCHOOLDAY_PHP" artisan migrate --force --no-interaction
"$SCHOOLDAY_PHP" artisan config:cache --no-interaction
"$SCHOOLDAY_PHP" artisan view:cache --no-interaction
"$SCHOOLDAY_PHP" artisan up --no-interaction
trap - EXIT
