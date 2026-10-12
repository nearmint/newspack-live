#!/usr/bin/env bash
# Builds release/newspack-live-{version}.zip and verifies it. Older zips are
# kept, and an existing zip of the same version is never replaced (a rebuild is compared with it).
# The archive is reproducible from a clean checkout: files carry the commit time and fixed modes and
# are stored in a fixed order. The zip only gets its final name once verified.
# Usage: npm run release:archive   (ALLOW_DIRTY=1 to build uncommitted changes, not reproducible)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
PHP="$ROOT/bin/php.local.sh"
OUT="$ROOT/release"
STAGE="$OUT/newspack-live"
# Untracked files count too (rsync would ship them); gitignored build output doesn't.
if [ -z "${ALLOW_DIRTY:-}" ] && [ -n "$(git status --porcelain)" ]; then
	echo "Uncommitted changes: commit first (the archive is stamped with the commit time), or set ALLOW_DIRTY=1." >&2
	git status --porcelain >&2
	exit 1
fi
VERSION="$(node -p "require('./package.json').version")"
ZIP="newspack-live-$VERSION.zip"
# An existing zip of this version is never replaced: the new build is only compared with it.
EXISTING=""
if [ -e "$OUT/$ZIP" ]; then
	EXISTING="$OUT/$ZIP"
	ZIP="newspack-live-$VERSION.rebuild.zip"
fi
FINAL="$ZIP"
ZIP="$ZIP.tmp"
STAMP="$(TZ=UTC git log -1 --format=%cd --date=format-local:%Y%m%d%H%M.%S)"

node bin/check-version.mjs
npm ci
npm run build

restore_dev() { bin/composer.sh install --quiet; }
trap restore_dev EXIT
bin/composer.sh install --no-dev --optimize-autoloader --classmap-authoritative --quiet

rm -rf "$STAGE" "$OUT/$ZIP"
mkdir -p "$STAGE"
# vendor/: only the no-dev autoloader ships (leftover dev folders such as vendor/bin are skipped).
rsync -a --include=/vendor/ --include=/vendor/autoload.php --include='/vendor/composer/***' --exclude='/vendor/*' --exclude-from=.distignore ./ "$STAGE/"
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
find "$STAGE" -exec env TZ=UTC touch -h -t "$STAMP" {} +
(cd "$OUT" && find newspack-live -print | LC_ALL=C sort | TZ=UTC zip -qX -@ "$ZIP")
rm -rf "$STAGE"

# Verify: unzip into $TMPDIR, lint every PHP file, check required and forbidden paths.
CHECK="$(mktemp -d "${TMPDIR:-/tmp}/newspack-live-zip.XXXXXX")"
unzip -q "$OUT/$ZIP" -d "$CHECK"
PLUGIN="$CHECK/newspack-live"
find "$PLUGIN" -name '*.php' -print0 | while IFS= read -r -d '' file; do
	"$PHP" -l "$file" > /dev/null || { echo "PHP syntax error: $file" >&2; exit 1; }
done
for path in build includes templates languages vendor/autoload.php README.md newspack-live.php uninstall.php; do
	[ -e "$PLUGIN/$path" ] || { echo "Missing in zip: $path" >&2; exit 1; }
done
for path in node_modules src tests bin docs release AGENTS.md CLAUDE.md package.json composer.json vendor/bin vendor/squizlabs; do
	[ ! -e "$PLUGIN/$path" ] || { echo "Dev file in zip: $path" >&2; exit 1; }
done
# No dot-files at all (.env*, .git, tool configs).
DOTFILES="$(find "$PLUGIN" -name '.*' -not -name '.' | head -5)"
[ -z "$DOTFILES" ] || { echo "Dot-files in zip: $DOTFILES" >&2; exit 1; }
# Every shipped file is tracked by git (rsync already applied .distignore), or is build output or
# the no-dev Composer autoloader: local, ignored or untracked files never ship.
UNEXPECTED="$(cd "$PLUGIN" && find . -type f | sed 's|^\./||' | LC_ALL=C sort \
	| LC_ALL=C comm -23 - <(git -C "$ROOT" ls-files | LC_ALL=C sort) \
	| grep -vE '^(build/|vendor/autoload\.php$|vendor/composer/)' || true)"
[ -z "$UNEXPECTED" ] || { printf 'Untracked files in zip:\n%s\n' "$UNEXPECTED" >&2; exit 1; }
mv "$OUT/$ZIP" "$OUT/$FINAL"
ZIP="$FINAL"
echo "release/$ZIP OK ($(du -h "$OUT/$ZIP" | cut -f1), $(find "$PLUGIN" -type f | wc -l | tr -d ' ') files, verified in $CHECK, SHA-256 $(shasum -a 256 "$OUT/$ZIP" | cut -d" " -f1))"
if [ -n "$EXISTING" ]; then
	if cmp -s "$EXISTING" "$OUT/$ZIP"; then
		echo "Rebuild matches $EXISTING byte for byte (kept; rebuild removed)."
		rm -f "$OUT/$ZIP"
	else
		echo "Rebuild differs from $EXISTING (kept); the rebuild is $OUT/$ZIP." >&2
		exit 1
	fi
fi
