#!/usr/bin/env bash

# Choose the existing Pest execution path without changing the caller's test scope.
# TIA means test-impact analysis: coverage links changed code to affected tests.
# CI runs without that local cache; developer runs require a coverage-capable PHP.
# Shell files use native # comments because PHP block syntax is not valid here.
set -euo pipefail

# Let CI's selected PHP/database matrix run the requested tests directly. Forward
# arguments unchanged so filtering or a fresh invocation retains its original meaning.
if [[ "${CI:-}" =~ ^(1|true)$ ]]; then
    exec php ./vendor/bin/pest --no-tia "$@"
fi

# Pest cannot anchor TIA until a repository has its first commit.
# This diagnosed bootstrap case remains exhaustive; it never reports cached proof.
if ! git rev-parse --verify HEAD >/dev/null 2>&1; then
    echo 'TIA baseline unavailable before the first commit; running exhaustive bootstrap verification.' >&2
    arguments=(--no-tia)
    for argument in "$@"; do
        case "$argument" in --fresh|--filtered) ;; *) arguments+=("$argument");; esac
    done
    exec php ./vendor/bin/pest "${arguments[@]}"
fi

if command -v herd >/dev/null 2>&1 && herd list --raw 2>/dev/null | grep -q '^coverage '; then
    # Match the coverage process to the active PHP major/minor configuration. Herd's
    # separate coverage runtime must load the same extension settings as this version.
    php_version="$(php -r 'echo PHP_MAJOR_VERSION.PHP_MINOR_VERSION;')"
    herd_ini="${HOME}/Library/Application Support/Herd/config/php/${php_version}/php.ini"

    if [[ -f "${herd_ini}" ]]; then
        PHP_INI_SCAN_DIR="$(dirname "${herd_ini}")"
        export PHP_INI_SCAN_DIR
        exec herd coverage -d memory_limit=512M ./vendor/bin/pest --tia --locally "$@"
    fi

    exec herd coverage -d memory_limit=512M ./vendor/bin/pest --tia --locally "$@"
fi

if php -r 'exit(extension_loaded("pcov") || extension_loaded("xdebug") ? 0 : 1);'; then
    # Outside Herd, use an already installed coverage driver. XDEBUG_MODE enables
    # collection for this child process; it does not alter the machine's PHP setup.
    export XDEBUG_MODE=coverage
    exec php -d memory_limit=512M ./vendor/bin/pest --tia --locally "$@"
fi

echo 'Pest TIA requires PCOV or Xdebug coverage. Install a coverage driver or run through Laravel Herd.' >&2
exit 1
