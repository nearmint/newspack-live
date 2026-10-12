#!/usr/bin/env bash
# Runs WP-CLI against a Local site through Local's PHP 8.2 and the site's MySQL socket.
#
# Copy this file to bin/wp.local.sh (gitignored) and adjust the variables.
# Commands that call the MySQL client binaries (db check, db export, db query, db cli, ...)
# get the --socket argument added automatically.
set -euo pipefail

LOCAL_SITE_ID="${LOCAL_SITE_ID:-your-local-site-id}"
LOCAL_PHP="${LOCAL_PHP:-$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php}"
LOCAL_SOCK="${LOCAL_SOCK:-$HOME/Library/Application Support/Local/run/$LOCAL_SITE_ID/mysql/mysqld.sock}"
WP_CLI_BIN="${WP_CLI_BIN:-/opt/homebrew/bin/wp}"
WP_PATH="${WP_PATH:-$HOME/Local Sites/newspack/app/public}"

extra=()
if [[ "${1:-}" == "db" ]]; then
	case "${2:-}" in
		check|export|import|query|cli|optimize|repair|create|drop|reset)
			extra+=( "--socket=$LOCAL_SOCK" )
			;;
	esac
fi

exec "$LOCAL_PHP" \
	-d mysqli.default_socket="$LOCAL_SOCK" \
	-d pdo_mysql.default_socket="$LOCAL_SOCK" \
	-d memory_limit=1024M \
	"$WP_CLI_BIN" --path="$WP_PATH" "$@" ${extra[@]+"${extra[@]}"}
