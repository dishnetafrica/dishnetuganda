# Backup and rollback

## The three things that need backing up

They are not interchangeable.

| What | How | Covers |
| --- | --- | --- |
| **UISP application data** | UISP UI → Settings → Maintenance → Backup | Devices, clients, CRM data, invoices, settings. **This is the one that matters.** |
| Host configuration | `sudo ./scripts/backup-configs.sh` | `unms.conf`, compose files, Traefik config, UFW rules |
| EasyPanel | EasyPanel's own backup settings, plus `/etc/easypanel` | Panel config and deployed app definitions |

`backup-configs.sh` does **not** back up UISP's databases and cannot restore
UISP. Only the UISP backup can. Take one before any UISP-affecting change, and
**download it off the server** — a backup that only exists on the droplet does
not survive the failure mode you most need it for.

## Before any change

```bash
sudo ./scripts/verify-uisp-health.sh | tee /root/health-before.txt
sudo ./scripts/backup-configs.sh
# plus a UISP backup from the UI, downloaded locally, if UISP is affected
```

## Rollback by change type

| Change | Rollback | Time |
| --- | --- | --- |
| Traefik dynamic route (`05`) | Delete the `.yml`; Traefik reloads by itself | seconds |
| UFW rule | Re-add the rule from the saved `ufw status numbered` | seconds |
| EasyPanel panel domain | Clear the domain in Settings; reach the panel on `:3000` again — **confirm `:3000` is still open before you clear it** | minutes |
| UISP hostname setting | Set it back to `178.62.83.12`; allow time for devices to re-register | minutes |
| DNS record | Delete or repoint; TTL applies | up to the TTL |
| Anything that broke UISP data | Restore the UISP backup through UISP's own restore | longer — plan for downtime |

## What has no easy rollback

Be deliberate about these, because "undo" is not available:

- **A UISP major version update.** Restoring means restoring the whole backup.
  Take one immediately before, and read the release notes.
- **uCRM currency, once invoices exist.** See `03`.
- **Invoice numbering, once issued.**
- **A reinstall of UISP.** Avoid entirely. See
  [07-change-control.md](07-change-control.md#the-one-way-this-setup-gets-broken).

## Droplet-level safety net

DigitalOcean snapshots and backups cover the whole droplet — both stacks at
once — and are the only thing that recovers from a mistake that damages both.
Worth enabling before the domain migration in `05`. A snapshot taken while
services run is crash-consistent, so it complements the UISP backup rather than
replacing it.

## Which plugin build is installed, and how to rebuild its ZIP

The ZIP uploaded through uCRM → Settings → Plugins is a build of one git
commit, nothing more. Losing the file loses nothing: the commit is on GitHub
(`dishnetafrica/dishnetuganda`, branch `claude/study-this-jhe2eg` until it is
merged), and `build-zip.sh` rebuilds a byte-identical archive from it.

**What is installed right now.** From 5.18.11 onward every ZIP carries a
`build.json` at its root, written by `build-zip.sh` at build time and never
committed, naming the version, the full commit, the branch and the build
time, plus `tree_dirty` if the build was made from uncommitted changes (a
build like that is not reproducible, and the script warns when it makes one):

```
docker exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/build.json
```

For 5.18.9 and 5.18.10, built before the stamp existed, the installed version
is the manifest, and the version-to-commit map is the table in
[07](07-change-control.md), which names the commit each release was built
from (5.18.9 → `c8e3ab6`, 5.18.10 → `0e39c4f`):

```
docker exec ucrm grep '"version": "5' /data/ucrm/data/plugins/dishnet-hybrid-sudan/manifest.json
```

**Rebuilding the ZIP for a given commit**, on any machine with git, php and
zip:

```
git clone https://github.com/dishnetafrica/dishnetuganda.git && cd dishnetuganda
git checkout 0e39c4f            # the commit named in the table, or a tag once one exists
bash dishnet-hybrid-sudan/build-zip.sh /tmp/dishnet-hybrid-sudan-5.18.10.zip
```

**Tags.** The session that builds these releases can push to its branch
only; pushing tags is refused (HTTP 403), so no release tags exist yet. When
the branch is merged, tagging each release commit makes the map part of the
repository itself rather than of a table:

```
git tag -a v5.18.9  c8e3ab6 -m "Plugin 5.18.9"
git tag -a v5.18.10 0e39c4f -m "Plugin 5.18.10"
git push origin v5.18.9 v5.18.10
```
