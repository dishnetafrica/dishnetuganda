# Panel fallback patch — cookie-first, official-API-shadow (FLAG-OFF)

`public_php_official_api_fallback.patch` adds a **cookie-first, official-API-fallback** to the
dishnet-data-report dashboard: where a kit has cookie usage it shows the cookie value (unchanged);
where a kit has **no** cookie usage (e.g. a stale/expired cookie session), it fills the gap from the
parallel official-API shadow (`data/api_shadow/`) so the customer sees real data instead of
"no usage yet". **Default OFF — byte-identical behaviour until you enable it.**

- **Canonical source:** `dishnetafrica/datareport` branch `claude/official-api-shadow-adapter`
  (`public.php`). This patch is the `public.php` diff only, for easy server apply.
- **Verified:** applies cleanly to the deployed v2.8.80 `public.php` (md5 `233fe68e…`, commit
  `012810d`); the patched file passes `php -l`.

## Prerequisite
The shadow files must exist first — run `official_api/official_api_sync.php` (see `probe/README.md`)
so `data/api_shadow/{usage,service_lines,...}.json` are present.

## Apply (on the server, with a backup + dry-run first)
```bash
PLUGIN=/home/unms/data/ucrm/ucrm/data/plugins/dishnet-data-report
cd "$PLUGIN"
cp public.php "public.php.bak.$(date +%Y%m%d%H%M%S)"            # backup first
curl -fsSL -o /tmp/dr_fallback.patch \
  "https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/<COMMIT>/docs/starlink-api-integration/patches/public_php_official_api_fallback.patch"
patch -p1 --dry-run < /tmp/dr_fallback.patch                    # must say it will apply cleanly
patch -p1          < /tmp/dr_fallback.patch                     # apply
php -l public.php                                               # must say: No syntax errors
```
If `--dry-run` reports any hunk failure, STOP — your `public.php` differs from v2.8.80; restore the
backup and tell me, and I'll regenerate against your exact version.

## Enable / disable (the flag)
```bash
touch "$PLUGIN/data/api_shadow/VIEW_ENABLED"    # ENABLE the fallback (web-safe)
rm -f "$PLUGIN/data/api_shadow/VIEW_ENABLED"    # DISABLE (reverts to cookie-only behaviour)
```
(Alternatively set env `DR_OFFICIAL_API_VIEW=yes` for the web process.) With the flag absent, the
patched code path is a no-op — the dashboard behaves exactly as before.

## Revert
- Quick: `rm -f data/api_shadow/VIEW_ENABLED` (fallback goes inert; behaviour identical to original).
- Full: restore the backup — `cp public.php.bak.<ts> public.php`.

## Scope / limitation
- Cookie stays primary; the official API only fills kits with no cookie usage. Finance is untouched.
- Daily trend chart is empty for shadow-only kits (the adapter stores cycle totals, not daily arrays
  yet) — the hero total, priority/standard split, and history total all populate. Daily is a small
  follow-up enhancement.
- Not deployed by anyone but you; reversible; no customer-facing change until the flag is on.

---

# Finance warning-silence patch (separate plugin; pre-existing bugs)

`finance_public_php_warning_silence.patch` is for the **`dishnet-starlink-finance`** plugin (NOT
data-report). It silences two **pre-existing** PHP warnings that are unrelated to the Starlink-API
work — they live in finance's own `public.php` and were present in the shipped v7.3.9 source:

- **`Undefined variable $apiUrl` (line 3253)** — inside `autoEnrichKitData()` (called early at line 560),
  which uses the global `$apiUrl`/`$appKey` without importing them. Guarded with `?? ''` (short-circuits
  to the same falsy result → **no behavior change**; the UCRM-email fallback stays exactly as before —
  it never fired, because `$apiUrl` was never in scope there).
- **`session_start() after headers already sent` (line 1210)** — triggered by the early `$apiUrl`
  warning's output. Guarded with `session_status()`/`headers_sent()` (standard; **no behavior change**).

Verified: applies cleanly to the shipped finance `public.php` (**md5 `c948afa5…`**, 13,692 lines) and
the result passes `php -l`. Silence-only — fixes the symptom, changes no logic.

### Apply (on the server, with backup + md5 check + dry-run)
```bash
PLUGIN=/home/unms/data/ucrm/ucrm/data/plugins/dishnet-starlink-finance
cd "$PLUGIN"
md5sum public.php                                               # should be c948afa528b7a8ac669fb00e1518f512
cp public.php "public.php.bak.$(date +%s)"
curl -fsSL -o /tmp/fin_warn.patch \
  "https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/<COMMIT>/docs/starlink-api-integration/patches/finance_public_php_warning_silence.patch"
patch -p1 --dry-run < /tmp/fin_warn.patch                       # must apply cleanly
patch -p1          < /tmp/fin_warn.patch
php -l public.php                                               # No syntax errors
```
If the md5 differs, your deployed finance plugin isn't the v7.3.9 I have — STOP and tell me; I'll
regenerate against your exact file. Revert = restore the backup.

> Deeper finding (not fixed by the silence-only patch): because `$apiUrl` was never in scope inside
> `autoEnrichKitData()`, the UCRM **email fallback** for missing-invoice alerts has effectively never
> sent. If you want that repaired, that's the "Option B" behavior change (add `global $apiUrl, $appKey;`).
