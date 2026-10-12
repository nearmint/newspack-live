#!/usr/bin/env bash
# Runs Definition of Done items 1–8 and prints a summary.
#
#   bin/dod.sh              all items
#   bin/dod.sh --no-e2e     skip Playwright (and the debug.log check)
#   E2E_ARGS="--grep @smoke" bin/dod.sh
set -uo pipefail
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$DIR"
# shellcheck disable=SC1091
source "$HOME/.nvm/nvm.sh" >/dev/null 2>&1 && nvm use >/dev/null 2>&1

RUN_E2E=1
[[ "${1:-}" == "--no-e2e" ]] && RUN_E2E=0
LOGDIR="${TMPDIR:-/tmp}/newspack-live-dod"
mkdir -p "$LOGDIR"
declare -a RESULTS=()

step() {
	local name="$1"; shift
	local log="$LOGDIR/$( echo "$name" | tr ' /:' '___' ).log"
	if "$@" >"$log" 2>&1; then
		RESULTS+=( "PASS  $name" )
	else
		RESULTS+=( "FAIL  $name  (log: $log)" )
	fi
	tail -n 4 "$log" | sed "s/^/  [$name] /"
}

step "1 composer lint" bin/composer.sh lint
step "2 npm run lint" npm run lint
step "3 typescript:check" npm run typescript:check
step "4 build" npm run build
step "5 phpunit" bin/composer.sh test
step "6 jest" npm test
if [[ "$RUN_E2E" == "1" ]]; then
	bin/debug-log.sh mark >/dev/null
	# shellcheck disable=SC2086
	step "7 e2e" npx playwright test ${E2E_ARGS:-}
	step "8 debug.log" bin/debug-log.sh check
fi

echo
echo "Definition of Done summary ($( git rev-parse --abbrev-ref HEAD ) @ $( git rev-parse --short HEAD )):"
printf '  %s\n' "${RESULTS[@]}"
printf '%s\n' "${RESULTS[@]}" | grep -q '^FAIL' && exit 1
exit 0
