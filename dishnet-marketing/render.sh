#!/usr/bin/env bash
# Re-render the ad set after editing prices/copy in the .html sources.
# Needs Chromium (CHROME env overrides the binary path).
set -euo pipefail
cd "$(dirname "$0")"
SITE="$(cd ../dishnet-web-uganda/site && pwd)"
CHROME="${CHROME:-$(command -v chromium || command -v chromium-browser || command -v google-chrome)}"
render() { # file WxH
  local tmp; tmp=$(mktemp --suffix=.html)
  sed "s|__SITE__|file://$SITE|g" "$1.html" > "$tmp"
  "$CHROME" --headless=new --no-sandbox --disable-gpu --hide-scrollbars \
    --window-size="$2" --screenshot="$1.png" "file://$tmp" 2>/dev/null
  rm -f "$tmp"; echo "rendered $1.png ($2)"
}
render compare-ad    1080,1080
render compare-story 1080,1920
render compare-wide  1200,630
