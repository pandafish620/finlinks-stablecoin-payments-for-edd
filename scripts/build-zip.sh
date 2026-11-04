#!/usr/bin/env bash
# Build the WordPress.org zip from an allowlist.
#
# The archive root directory is finlinks-stablecoin-payments-for-edd/.
# It contains only the main plugin PHP file, readme.txt, uninstall.php,
# LICENSE, assets/, includes/, and languages/.
#
# Usage (from the repository root or from anywhere):
#   scripts/build-zip.sh
# Output:
#   dist/finlinks-stablecoin-payments-for-edd-<version>.zip

set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

slug="finlinks-stablecoin-payments-for-edd"
version="$(awk '/^ \* Version:/{print $3; exit}' "$slug.php")"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Could not read a version from $slug.php" >&2
	exit 1
fi

stage="$(mktemp -d)"
cleanup() {
	rm -rf "$stage"
}
trap cleanup EXIT

dest="$stage/$slug"
mkdir -p "$dest"

copy_file() {
	local rel="$1"
	mkdir -p "$dest/$(dirname "$rel")"
	cp -a "$rel" "$dest/$rel"
}

copy_file "$slug.php"
copy_file readme.txt
copy_file uninstall.php
copy_file LICENSE

while IFS= read -r -d '' file; do
	copy_file "$file"
done < <(find assets includes languages -type f ! -name '.DS_Store' -print0)

mkdir -p dist
out="dist/${slug}-${version}.zip"
rm -f "$out"
(
	cd "$stage"
	zip -r -X "$root/$out" "$slug" -x '*.DS_Store'
)

echo "Wrote $out"
unzip -l "$out"
