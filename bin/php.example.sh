#!/usr/bin/env bash
# Runs PHP with the same binary as the Local site (PHP 8.2), not Homebrew's PHP.
#
# Copy this file to bin/php.local.sh (gitignored) and adjust LOCAL_SITE_ID and LOCAL_PHP.
# Every PHP tool (Composer, PHPCS, PHPUnit, git hooks) goes through this wrapper.
set -euo pipefail

LOCAL_SITE_ID="${LOCAL_SITE_ID:-your-local-site-id}"
LOCAL_PHP="${LOCAL_PHP:-$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php}"
LOCAL_SOCK="${LOCAL_SOCK:-$HOME/Library/Application Support/Local/run/$LOCAL_SITE_ID/mysql/mysqld.sock}"

exec "$LOCAL_PHP" \
	-d mysqli.default_socket="$LOCAL_SOCK" \
	-d pdo_mysql.default_socket="$LOCAL_SOCK" \
	-d memory_limit=1024M \
	"$@"
