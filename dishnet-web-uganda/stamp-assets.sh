#!/usr/bin/env bash
#
# stamp-assets.sh — re-stamp ?v= cache-busters from each asset's own content.
#
# The site links its CSS and JS with ?v=<first 8 of sha1>. That is a good
# scheme and it was applied by hand, which means the failure mode is silent:
# edit prices.js, forget the stamp, and every returning visitor keeps the
# cached copy. The site looks deployed, the change is live on disk, and the
# people it was written for never see it.
#
#   bash stamp-assets.sh          re-stamp, report what changed
#   bash stamp-assets.sh --check  report only, change nothing (exit 1 if stale)
#
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE="$HERE/site"
CHECK=0; [ "${1:-}" = "--check" ] && CHECK=1
stale=0

# Every asset referenced with a ?v=, discovered from the pages themselves so a
# newly linked file is covered without editing this script.
ASSETS="$(grep -rhoE '[A-Za-z0-9_./-]+\.(js|css)\?v=[a-f0-9]+' "$SITE"/*.html \
          | sed -E 's/\?v=.*//' | sort -u)"

for rel in $ASSETS; do
    file="$SITE/$rel"
    if [ ! -f "$file" ]; then echo "  ! referenced but missing: $rel"; stale=1; continue; fi
    want="$(sha1sum "$file" | cut -c1-8)"
    # Every stamp currently on the pages for this asset.
    have="$(grep -rhoE "$(printf '%s' "$rel" | sed 's/[.[\*^$/]/\\&/g')\?v=[a-f0-9]+" "$SITE"/*.html \
            | sed -E 's/.*\?v=//' | sort -u)"
    if [ "$have" = "$want" ]; then
        echo "  ok   $rel  $want"
        continue
    fi
    stale=1
    if [ "$CHECK" = 1 ]; then
        echo "  ✗    $rel  is $have, content says $want"
    else
        for old in $have; do
            grep -rlF "$rel?v=$old" "$SITE"/*.html | while read -r page; do
                sed -i "s|$rel?v=$old|$rel?v=$want|g" "$page"
            done
        done
        echo "  →    $rel  $have → $want"
    fi
done

if [ "$CHECK" = 1 ] && [ "$stale" = 1 ]; then
    echo; echo "  Stamps are stale — run: bash stamp-assets.sh"; exit 1
fi
exit 0
