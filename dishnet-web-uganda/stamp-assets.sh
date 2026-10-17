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
# Every page under site/ is covered, tutorials/ included, and each reference is
# resolved from its own page's directory: ../styles.css in tutorials/ is
# site/styles.css, /assets/js/nav.js on 404.html is site/assets/js/nav.js. The
# first version globbed site/*.html only, so the tutorial pages kept a stale
# styles.css stamp for weeks while --check reported ok.
#
# It edits HTML pages under site/ and nothing else, and refuses a reference
# that resolves outside site/.
set -euo pipefail
# Physical path (pwd -P): every reference below is resolved with realpath, which
# follows symlinks, so the site root must be physical too or the containment
# test refuses every asset when the script is run through a symlinked path.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
SITE="$HERE/site"
CHECK=0; [ "${1:-}" = "--check" ] && CHECK=1
stale=0
REFS="$(mktemp)"; trap 'rm -f "$REFS"' EXIT

# One line per (page, reference): <resolved file>|<page>|<reference as written>|<stamp>
# Discovered from the pages themselves, so a newly linked file is covered
# without editing this script.
while IFS= read -r -d '' page; do
    dir="$(dirname "$page")"
    { grep -oE '[A-Za-z0-9_./-]+\.(js|css)\?v=[a-f0-9]+' "$page" || true; } | sort -u | while IFS= read -r m; do
        ref="${m%%\?v=*}"; stamp="${m##*\?v=}"
        case "$ref" in
            /*) file="$(realpath -m "$SITE$ref")" ;;
            *)  file="$(realpath -m "$dir/$ref")" ;;
        esac
        case "$file" in
            "$SITE"/*) ;;
            *) echo "  ! $(basename "$page") references $ref, which is outside site/ — refused" >&2; exit 3 ;;
        esac
        printf '%s|%s|%s|%s\n' "$file" "$page" "$ref" "$stamp"
    done
done < <(find "$SITE" -name '*.html' -print0 | sort -z) > "$REFS"

for file in $(cut -d'|' -f1 "$REFS" | sort -u); do
    rel="${file#"$SITE"/}"
    if [ ! -f "$file" ]; then echo "  ! referenced but missing: $rel"; stale=1; continue; fi
    want="$(sha1sum "$file" | cut -c1-8)"
    # Every stamp currently on the pages for this asset.
    have="$(awk -F'|' -v f="$file" '$1 == f { print $4 }' "$REFS" | sort -u | tr '\n' ' ' | sed 's/ $//')"
    if [ "$have" = "$want" ]; then
        echo "  ok   $rel  $want"
        continue
    fi
    stale=1
    if [ "$CHECK" = 1 ]; then
        echo "  ✗    $rel  is $have, content says $want"
    else
        awk -F'|' -v f="$file" -v w="$want" '$1 == f && $4 != w { print $2 "|" $3 "|" $4 }' "$REFS" \
        | while IFS='|' read -r page ref old; do
            esc="$(printf '%s' "$ref" | sed 's/[.[\*^$/]/\\&/g')"
            sed -i "s|$esc?v=$old|$ref?v=$want|g" "$page"
        done
        echo "  →    $rel  $have → $want"
    fi
done

if [ "$CHECK" = 1 ] && [ "$stale" = 1 ]; then
    echo; echo "  Stamps are stale — run: bash stamp-assets.sh"; exit 1
fi
exit 0
