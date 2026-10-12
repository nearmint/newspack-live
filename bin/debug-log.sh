#!/usr/bin/env bash
# Definition of Done helper for wp-content/debug.log.
#
#   bin/debug-log.sh mark    Remember the current line count.
#   bin/debug-log.sh check   Print entries added since the mark; exit 1 if any mentions newspack-live.
set -euo pipefail
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
LOG="$DIR/../../debug.log"
MARK="${TMPDIR:-/tmp}/newspack-live-debug-log.mark"

lines() { if [[ -f "$LOG" ]]; then wc -l < "$LOG" | tr -d ' '; else echo 0; fi; }

case "${1:-}" in
	mark)
		lines > "$MARK"
		echo "debug.log mark: $( cat "$MARK" ) lines"
		;;
	check)
		start="$( cat "$MARK" 2>/dev/null || echo 0 )"
		# The log was rotated or truncated since the mark: check it from the start.
		if (( $( lines ) < start )); then
			start=0
		fi
		new="$( tail -n +"$(( start + 1 ))" "$LOG" 2>/dev/null || true )"
		total="$( printf '%s' "$new" | grep -c . || true )"
		ours="$( printf '%s\n' "$new" | grep -i 'newspack-live' || true )"
		echo "debug.log: ${total} new line(s) since mark (${start})."
		if [[ -n "$ours" ]]; then
			echo "Entries caused by newspack-live:"
			printf '%s\n' "$ours"
			exit 1
		fi
		if [[ "$total" != "0" ]]; then
			echo "Third-party entries (not caused by this plugin):"
			printf '%s\n' "$new" | sed -E 's/^\[[^]]*\] //' | sort | uniq -c | sort -rn | head -20
		fi
		;;
	*)
		echo "Usage: $0 mark|check" >&2
		exit 2
		;;
esac
