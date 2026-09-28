# Read-only: for each file 5.18.52 changed, the second it was installed with at 5.18.51 (the deploy's backup) and now.
# PHP's code cache compares whole seconds: a PHP file whose content changed but whose second did not is still run old.
REPO=${REPO:-/opt/dishnet}; DEST=${DEST:-/home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan}
BK=$(ls -td ${BKROOT:-/root/dnb-5.18.52}/backup-*/ 2>/dev/null | head -1); T="${BK}plugin-installed-5.18.51.tar.gz"
[ -f "$T" ] || { echo "no backup of the installed 5.18.51 at $T"; exit 1; }
echo "backup  $T"
LIST=$(tar -tvzf "$T" --full-time 2>/dev/null); same=0
for p in $(git -C "$REPO" diff --name-only 240f2f9 7ad465e -- dishnet-hybrid-sudan | sed 's#^dishnet-hybrid-sudan/##'); do
  git -C "$REPO" cat-file -e "7ad465e:dishnet-hybrid-sudan/$p" 2>/dev/null && [ -f "$DEST/$p" ] || continue
  now=$(stat -c '%y' "$DEST/$p" | cut -c1-19)
  was=$(printf '%s\n' "$LIST" | awk -v f="dishnet-hybrid-sudan/$p" '$NF == f { print $4 " " $5 }' | cut -c1-19)
  case "$p" in tests/*) kind="test, not run by the web server";; *.php) kind="PHP";; *) kind="not PHP code";; esac
  if [ -z "$was" ]; then v="new in 5.18.52"
  elif [ "$was" != "$now" ]; then v="new second"
  elif [ "$kind" = PHP ]; then v="SAME SECOND: the web server may still run the 5.18.51 copy"; same=$((same+1))
  else v="same second ($kind)"; fi
  printf '%-40s was %-19s now %-19s %s\n' "$p" "${was:--}" "$now" "$v"
done
echo "summary: $same PHP file(s) the web server runs have the same second as at 5.18.51"
echo "--- the checkout's moves (git reflog):"
git -C "$REPO" reflog --date=iso -n 40 | grep -E '}: (checkout|pull)' | head -10
