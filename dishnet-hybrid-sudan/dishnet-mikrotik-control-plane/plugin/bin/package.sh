#!/bin/sh
# Build the installable artifact.
#
#   sh plugin/bin/package.sh [output-directory]
#
# Produces dishnet-mikrotik-<version>.tar.gz containing exactly what the plugin
# needs at runtime and nothing else. Before this existed the "plugin" was a
# subdirectory of a repository: you could read it, but you could not hand it to
# anyone, because nothing said which of the nine top-level directories were part
# of it.
#
# What is deliberately EXCLUDED, and why:
#
#   tests/   the suite needs a BYPASSRLS fixture identity. Shipping it would put
#            a row-level-security bypass into a deployment.
#   tools/   development helpers. The one thing an install needs from it — a
#            server that puts the panel and the API on one origin — is shipped
#            as plugin/bin/serve.php instead.
#   docs/    the decision record. Useful to read, not needed to run.
#
# public/ — the operator app and its API front controller — WAS excluded until
# 2026-09-24, because nothing served it. It ships now, on the operator's
# explicit approval (docs/127 §H H-11), served by plugin/bin/serve-app.php as
# its own process on its own host name; serve.php still serves only the panel
# and the Admin API.
#
# The archive carries SHA256SUMS of every file it contains, so what was built
# and what was extracted can be compared rather than assumed.

set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
out=${1:-"$root/dist"}

version=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"];' "$root/plugin/plugin.json")
id=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["id"];' "$root/plugin/plugin.json")
name="$id-$version"

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
dest="$stage/$name"
mkdir -p "$dest"

# ── contents ────────────────────────────────────────────────────────────────
mkdir -p "$dest/bin" "$dest/migrations"
cp -R "$root/src"    "$dest/src"
cp -R "$root/panel"  "$dest/panel"
cp -R "$root/public" "$dest/public"
cp -R "$root/plugin" "$dest/plugin"
cp "$root/bin/worker.php" "$dest/bin/worker.php"
cp "$root"/migrations/*.sql "$dest/migrations/"

# A package must not carry a configured environment into someone else's server.
rm -f "$dest/plugin/.env"

# ── stamp ───────────────────────────────────────────────────────────────────
printf '%s\n' "$name" > "$dest/VERSION"

( cd "$dest" && find . -type f ! -name SHA256SUMS -print0 \
    | sort -z | xargs -0 sha256sum > SHA256SUMS )

files=$(find "$dest" -type f | wc -l | tr -d ' ')

# ── verify the archive before claiming it exists ────────────────────────────
mkdir -p "$out"
tar -czf "$out/$name.tar.gz" -C "$stage" "$name"

check=$(mktemp -d)
tar -xzf "$out/$name.tar.gz" -C "$check"
( cd "$check/$name" && sha256sum -c SHA256SUMS --quiet ) \
  || { echo "REFUSING: the archive does not match its own checksums" >&2; rm -rf "$check"; exit 1; }
extracted=$(find "$check/$name" -type f | wc -l | tr -d ' ')
check_sums=$(mktemp); cp "$check/$name/SHA256SUMS" "$check_sums"
rm -rf "$check"

[ "$files" = "$extracted" ] \
  || { echo "REFUSING: staged $files files, archive extracts $extracted" >&2; exit 1; }

content=$(sha256sum < "$check_sums")

echo "$out/$name.tar.gz"
echo "  $files files, verified against SHA256SUMS after extraction"
echo "  $(du -h "$out/$name.tar.gz" | cut -f1) compressed"
echo "  archive sha256  $(sha256sum "$out/$name.tar.gz" | cut -d" " -f1)"
echo "  content  sha256 ${content%% *}"
echo
echo "  The archive digest identifies THIS BUILD: tar records modification times,"
echo "  so rebuilding the same source produces different bytes. The content digest"
echo "  is the digest of SHA256SUMS, which lists every file by content, and IS"
echo "  stable across rebuilds. Compare that one when asking whether two"
echo "  artifacts hold the same code."
