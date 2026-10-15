#!/usr/bin/env bash
# Creates a disposable real Laravel application; never changes the package source.
set -Eeuo pipefail

usage() {
    cat <<'USAGE'
Usage: bash scripts/smoke-laravel13.sh [--help]

Environment:
  PACKAGE_PATH  Local signdeck/veil checkout (default: parent of this script).
  TEMP_BASE     Existing temporary parent directory (default: TMPDIR or /tmp).
                The generated application must be outside PACKAGE_PATH.
  COMPOSER_BINARY  Composer executable or .phar path (default: composer).
  PHP_BINARY    PHP executable (default: php); needs pdo_sqlite and Laravel 13 requirements.

Downloads laravel/laravel:^13.0 and dependencies using normal Composer platform
and security checks. Installs the local package as dev-smoke via a symlinked
path repository. Requires internet access and compatible package constraints.
All generated application data is deleted on success, failure, or interruption.
Composer's normal download cache is not deleted. No production database is used.
USAGE
}

if [[ ${1:-} == --help ]]; then
    usage
    exit 0
fi
if [[ $# -ne 0 ]]; then
    usage >&2
    exit 2
fi

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
PACKAGE_PATH=$(CDPATH= cd -- "${PACKAGE_PATH:-$SCRIPT_DIR/..}" && pwd -P)
TEMP_BASE=$(CDPATH= cd -- "${TEMP_BASE:-${TMPDIR:-/tmp}}" && pwd -P)
COMPOSER_BINARY=${COMPOSER_BINARY:-composer}
# Composer uses COMPOSER to select a manifest; always use the disposable app's.
unset COMPOSER
PHP_BINARY=${PHP_BINARY:-php}

fail() { printf 'Smoke check failed: %s\n' "$*" >&2; exit 1; }
[[ -f "$PACKAGE_PATH/composer.json" ]] || fail 'PACKAGE_PATH must contain composer.json.'
case "$TEMP_BASE/" in
    "$PACKAGE_PATH/"*) fail 'TEMP_BASE must be outside the package source.' ;;
esac
PHP_BINARY=$(command -v "$PHP_BINARY") || fail "PHP executable not found: $PHP_BINARY"
# Resolve relative executable paths before entering the disposable application.
if [[ "$PHP_BINARY" != /* ]]; then
    PHP_BINARY=$("$PHP_BINARY" -r 'echo realpath($argv[1]);' "$PHP_BINARY")
fi
if [[ "$COMPOSER_BINARY" == *.phar ]]; then
    [[ -f "$COMPOSER_BINARY" ]] || fail "Composer phar not found: $COMPOSER_BINARY"
    COMPOSER_BINARY=$("$PHP_BINARY" -r 'echo realpath($argv[1]);' "$COMPOSER_BINARY")
    composer_cmd() { "$PHP_BINARY" "$COMPOSER_BINARY" "$@"; }
else
    COMPOSER_BINARY=$(command -v "$COMPOSER_BINARY") || fail 'Composer executable not found; set COMPOSER_BINARY.'
    if [[ "$COMPOSER_BINARY" != /* ]]; then
        COMPOSER_BINARY=$("$PHP_BINARY" -r 'echo realpath($argv[1]);' "$COMPOSER_BINARY")
    fi
    composer_cmd() { "$COMPOSER_BINARY" "$@"; }
fi
# Do not silently inherit platform/security bypasses from the caller.
for variable in COMPOSER_IGNORE_PLATFORM_REQ COMPOSER_IGNORE_PLATFORM_REQS COMPOSER_NO_SECURITY_BLOCKING; do
    [[ -z ${!variable:-} ]] || fail "Unset $variable; this smoke check must not bypass Composer checks."
done
"$PHP_BINARY" -r 'if (PHP_VERSION_ID < 80300 || !extension_loaded("pdo_sqlite")) { fwrite(STDERR, "PHP >= 8.3 with pdo_sqlite is required; Composer additionally enforces Laravel 13 requirements.\n"); exit(1); }'

WORK_DIR=$(mktemp -d "$TEMP_BASE/veil-laravel13.XXXXXXXX")
cleanup() {
    local status=$?
    trap - EXIT
    rm -rf -- "$WORK_DIR"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'printf "Smoke check failed at line %s (exit %s).\n" "$LINENO" "$?" >&2' ERR
mkdir "$WORK_DIR/tmp"
export TMPDIR="$WORK_DIR/tmp"
APP_PATH="$WORK_DIR/app"
FIXTURE="$SCRIPT_DIR/smoke-laravel13.php"
# Override any caller database settings, including a production DB_URL.
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite
export DB_DATABASE="$APP_PATH/database/database.sqlite" DB_URL=
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export VEIL_DISK=local VEIL_COMPRESS=false

printf 'Creating Laravel ^13.0 in %s\n' "$APP_PATH"
composer_cmd create-project --no-interaction --prefer-dist 'laravel/laravel' "$APP_PATH" '^13.0'
"$PHP_BINARY" "$FIXTURE" configure "$APP_PATH" "$PACKAGE_PATH"
(
    cd "$APP_PATH"
    composer_cmd update signdeck/veil --with-all-dependencies --no-interaction --prefer-dist
    composer_cmd check-platform-reqs
    "$PHP_BINARY" artisan package:discover --no-interaction
    "$PHP_BINARY" "$FIXTURE" discovery "$APP_PATH" "$PACKAGE_PATH"
    "$PHP_BINARY" artisan vendor:publish --tag=veil-config --no-interaction
    cmp "$PACKAGE_PATH/config/veil.php" config/veil.php
    "$PHP_BINARY" artisan veil:make-table smoke_records --no-interaction
    "$PHP_BINARY" -l app/Veil/VeilSmokeRecordsTable.php
    "$PHP_BINARY" "$FIXTURE" prepare "$APP_PATH"
    "$PHP_BINARY" artisan veil:export --name=smoke-exact --no-interaction
    "$PHP_BINARY" "$FIXTURE" verify "$APP_PATH"
)
printf 'Laravel 13 application smoke checks passed.\n'
