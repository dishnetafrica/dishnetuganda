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
#   public/  the CUSTOMER API front controller. This package installs the
#            Domain-B control plane and its Admin panel; nothing here serves the
#            customer API, so shipping its entry point would put an unbound HTTP
#            surface on the server.
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
rm -rf "$check"

[ "$files" = "$extracted" ] \
  || { echo "REFUSING: staged $files files, archive extracts $extracted" >&2; exit 1; }

echo "$out/$name.tar.gz"
echo "  $files files, verified against SHA256SUMS after extraction"
echo "  $(du -h "$out/$name.tar.gz" | cut -f1) compressed"
