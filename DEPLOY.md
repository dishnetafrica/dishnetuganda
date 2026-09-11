# Deploying

**A `git pull` in this checkout deploys nothing.**

This repository is not what uCRM runs. The `ucrm` container mounts its own
data directory, and the installed plugin lives under that mount. Pulling here
updates files on disk and leaves the running plugin exactly as it was.

```
bash scripts/deploy-hybrid.sh          # deploy, then prove the container sees it
bash scripts/deploy-hybrid.sh --check  # say what is live, change nothing
```

The usual sequence:

```
cd /opt/dishnet && git pull origin <branch> && bash scripts/deploy-hybrid.sh
```

## Why there is a script for a copy

On the night of 9 September 2026 a scheduling fix was pulled, committed and
called deployed three times. Each pull reported success. The container went on
running code from hours earlier the whole time.

What hid it: a tool was run with a flag the deployed version did not have. An
unrecognised flag is not an error — the tool ignored it and ran its default
path, which produced plausible output. Nothing anywhere said "stale". The
Starlink session it was supposed to keep alive expired while the status screen
read `state ACTIVE` and `failures 0`, both true, because nothing had run to
learn otherwise.

So the script does two things a copy command does not:

- **It asks Docker for the destination** instead of assuming one. The path was
  guessed from the repository layout, and the guess was wrong.
- **It reads the deployed commit back through the container.** `.deployed-commit`
  is written into the served tree and then read with `docker exec`. If the
  container cannot see it, the script fails. A copy that exits zero is not
  evidence.

It never deletes, and it never touches `data/` — plugin runtime state lives
there and in the sibling `.dishnet-hybrid-sudan-data` directory, and neither
belongs to this repository.

## Ownership

The served directory keeps the owner and mode it already had. The first
version of this script did not, and that broke the plugin worse than the bug
it was deploying: extracting as root applied the archive's own `./` entry to
the destination, turning `unms:unms 775` into `root:root 755`. uCRM's user
could no longer write into its own plugin directory, so uCRM stopped calling
`main.php` — silently, at that exact minute. Nothing logged an error; the
heartbeat log simply stopped gaining lines.

If it happens again:

```
D=/home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan
chown -R unms:unms "$D" && chmod 775 "$D"
```

`unms` on the host is the uid the container calls `nginx`.

## plugins_staging is not where the plugin runs

uCRM unpacks uploaded zips into `plugins_staging` before installing them into
`plugins`. Deploying into staging looks like it worked and changes nothing.
The script refuses to write there.

## When to build a zip instead

Direct deploys are for iteration. Anything that changes `manifest.json` —
configuration fields, the plugin version, permissions — should go through
`dishnet-hybrid-sudan/build-zip.sh` and be installed by uCRM, because uCRM
reads the manifest at install time and will not notice it otherwise.

## After deploying

Files land owned by whoever ran the script. If uCRM or the crons start
reporting permission errors:

```
docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/fix_file_permissions.php
```
