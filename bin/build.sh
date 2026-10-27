#!/usr/bin/env bash
#
# Rebuild the minified front-end files after editing assets/widget.js or
# assets/widget.css. Needs Node (npx fetches esbuild on first run).
#
# Both are printed inline in the footer of every page (the CSS inside the
# script, added only when the widget loads), so keep them small: the sizes
# are printed at the end.
#
# Usage: bin/build.sh

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../assets"

ESBUILD="npx --yes esbuild@0.24.0"

$ESBUILD widget.js --minify --target=es2017 --outfile=widget.min.js
$ESBUILD widget.css --minify --target=chrome80,safari13,firefox78 --outfile=widget.min.css

# PHP swaps these placeholders for the settings and the CSS, so they must
# survive minifying.
for token in __TUW_CONFIG__ __TUW_CSS__; do
	grep -q "$token" widget.min.js || { echo "Error: $token is missing from widget.min.js" >&2; exit 1; }
done

echo
for file in widget.min.js widget.min.css; do
	raw=$(wc -c < "$file" | tr -d ' ')
	gz=$(gzip -9 -c "$file" | wc -c | tr -d ' ')
	printf '%-15s %5d bytes, %5d gzipped\n' "$file" "$raw" "$gz"
done
