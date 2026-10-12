#!/usr/bin/env bash
# Runs Composer on Local's PHP 8.2 (through bin/php.local.sh), never Homebrew's PHP.
# Uses the global composer when installed, else bin/composer.phar.
set -euo pipefail
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
COMPOSER_BIN="$( command -v composer || true )"
if [[ -z "$COMPOSER_BIN" ]]; then
	COMPOSER_BIN="$DIR/composer.phar"
fi
exec "$DIR/php.local.sh" "$COMPOSER_BIN" "$@"
