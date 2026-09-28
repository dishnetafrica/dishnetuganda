# The fix: give each file 5.18.52 installed a new timestamp, so PHP's code cache compiles it again. No content changes.
REPO=${REPO:-/opt/dishnet}; DEST=${DEST:-/home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan}; n=0
for p in $(git -C "$REPO" diff --name-only 240f2f9 7ad465e -- dishnet-hybrid-sudan | sed 's#^dishnet-hybrid-sudan/##'); do
  git -C "$REPO" cat-file -e "7ad465e:dishnet-hybrid-sudan/$p" 2>/dev/null && [ -f "$DEST/$p" ] || continue
  [ "$(git -C "$REPO" show "7ad465e:dishnet-hybrid-sudan/$p" | sha256sum | cut -c1-64)" = "$(sha256sum "$DEST/$p" | cut -c1-64)" ] \
    || { echo "NOT TOUCHED, differs from 7ad465e: $p"; continue; }
  touch "$DEST/$p" && n=$((n+1))
done
echo "gave $n file(s) a new timestamp; their content is unchanged and is exactly 7ad465e's"
