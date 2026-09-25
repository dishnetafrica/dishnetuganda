# 33 — Links with `:8443`: where they came from, and what changed (5.18.34)

**25 September 2026.** Plugin `dishnet-hybrid-sudan` 5.18.33 → 5.18.34, and the
website. The operator asked:

> *"https://crm.dishnetuganda.com:8443/crm/ — why is it creating links with the
> port, do we really need it? Check everywhere, even customer login creates it
> with the port, so it doesn't work."*

## 1. Do we need the port?

- **Routers: yes.** UISP listens on 8443, and every router keeps its connection
  to that port ([05](05-domain-and-tls-plan.md)). It stays exactly as it is.
- **People: no.** A browser should use `https://crm.dishnetuganda.com` — port
  443, through Traefik, with a real certificate. Port 8443 has UISP's
  self-signed certificate, so a browser shows a security warning, and a customer
  stops there.

## 2. Where the port came from

| Where | Why it had `:8443` | Fixed by |
|---|---|---|
| The website's **Customer Login** button: 176 links on 57 pages | Each linked to `https://crm.dishnetuganda.com/crm`, with no slash at the end. The web server inside UISP answers that with a permanent redirect to `/crm/`, and a server of its kind adds the port it listens on: `https://crm.dishnetuganda.com:8443/crm/`, the address in the question | The website: every button now opens `https://crm.dishnetuganda.com/crm/login`, the sign-in page, with no redirect first |
| The plugin's links on the admin screens, the customer portal, the API — including **DPO's return, push and test addresses** | The setting that removes the port, `crm_public_url`, was set in September ([09](09-mail-and-pdf-delivery.md)). Only the scheduled jobs and the webhook read the file it is kept in. The screens, the portal, the API and about a hundred other places read another copy of the settings, which never had it | 5.18.34: the link builder reads the install's setting whichever copy the code holds |
| Twelve files that built an address without the link builder | Built from uCRM's own address, or from whatever host the request arrived on | 5.18.34: each now goes through the link builder |
| **uCRM's own e-mails and redirects**: the client zone invitation, uCRM's invoice e-mails, where uCRM sends a browser after signing in | uCRM believes its own address is `https://crm.dishnetuganda.com:8443/crm/` | Not the plugin's to change; see §6 |

Those twelve, and who sees them:

- **Customers:** the WhatsApp media links sent through Evolution; the overdue
  reminders' pay link from the workbench (switched off on Uganda; see §7).
- **Staff and app users:** the password-reset link sent on WhatsApp; the app's
  install page, download link, QR code and update link; the API documentation's
  addresses; the customer lookup's uCRM links, which also doubled `/crm` in the
  path; the Orders screen's links to uCRM clients; Settings' *View in UCRM*.
- **Nobody yet:** one unused variable in the portal, fixed so that it cannot
  come back into use with the port.

## 3. The setting is kept safe

- `crm_public_url` is now also kept in the **vault**, the backup of the settings
  that survives a re-install. An ordinary scheduled job copies it there after
  the deploy.
- `tools/crm_url_check.php --set` writes the setting to its file and to the
  vault, and removes any second copy elsewhere, so there is exactly one value.
  `--clear` removes it from both.
- The check tool now shows where the setting is kept, the links the scheduled
  jobs build and the links the screens build, side by side. It also shows where
  `/crm`, `/crm/` and `/crm/login` each lead.

**Sudan:** no `crm_public_url` is set there, so its links keep their host and
port. The one change there is the customer lookup's uCRM links: they doubled
`/crm` in the path on any install, and now do not.

## 4. What to do

**1. Deploy 5.18.34:**

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && bash scripts/deploy-hybrid.sh
```

**2. Check:**

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/crm_url_check.php
```

Expect:

- **config.json** `https://crm.dishnetuganda.com`.
- The *crons, webhook* lines and the *screens, portal, API* lines all start with
  `https://crm.dishnetuganda.com/`, with no `:8443` anywhere.
- `/crm` → `301` → `…:8443/crm/`. That is UISP's redirect. It stays, but
  nothing links there any more.
- `/crm/login` → `200`.

If **config.json** shows `—`, the setting is not there. Set it, and check again:

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/crm_url_check.php --set https://crm.dishnetuganda.com
```

The tool asks from inside the uCRM container. To see what a customer's browser
gets, from the server or from any computer:

```
curl -sI https://crm.dishnetuganda.com/crm | grep -i '^location'
curl -s -o /dev/null -w '%{http_code}\n' https://crm.dishnetuganda.com/crm/login
```

The first should print the `:8443` redirect of the table in §2. The second should
print `200`.

**3. Open `https://crm.dishnetuganda.com/crm/login` in a browser.** Expect uCRM's
client zone sign-in page. Only then redeploy the website in EasyPanel (project
`web`, app `web-uganda`), as before.
That publishes the two website corrections still waiting too
([07](07-change-control.md)).

**4. DPO:** copy the test link and the push address from the DPO Pay screen
**after** step 1. Both must start with
`https://crm.dishnetuganda.com/crm/_plugins/`, with no `:8443`.

## 5. What stays as it is

- **Routers on `:8443`.** Unchanged, as [05](05-domain-and-tls-plan.md) requires.
- **UISP's redirect of the bare `/crm`.** It is UISP's, and a plugin cannot
  change it. Nothing we publish links there now.
- **Browsers that already followed it.** A `301` is permanent, so a browser
  that saw it goes straight to `:8443` from its own memory when that address is
  typed or bookmarked. The new button is a different address, so it is not
  affected. Anyone with an old bookmark should clear it, or use the button.

## 6. uCRM's own links

The client zone invitation e-mail, uCRM's own invoice e-mails and the page uCRM
sends a browser to after signing in are all built by uCRM, from the address it
believes it has. **The plugin cannot change them.** There are two ways, and
neither is done:

1. **uCRM:** Settings → System → Application → server port **443**, if uCRM lets
   it be edited. If the field is greyed out as managed by UISP, stop there:
   UISP's own HTTPS port is the port routers connect on, and **must not be
   changed** ([05](05-domain-and-tls-plan.md)).
2. **A real certificate on 8443** ([05](05-domain-and-tls-plan.md), Option B). uCRM's
   links would keep the port but open without a warning. It needs a renewal to
   be built and maintained.

First see whether it matters. After step 1, send a client zone invitation to a
test client — the DPO test client, with DishNet's own e-mail — and look at the
link in it.

## 7. Found while checking — not changed

- **The overdue reminders send customers to uCRM's staff page** for their
  account, `/crm/client/<id>`, where a customer cannot sign in, port or no port.
  The nine-stage reminder ladder is switched off on Uganda (prepaid billing:
  `dishnet-hybrid-sudan/docs/UGANDA-EMAIL-OWNERSHIP.md`), so this reaches Sudan
  customers only. It is Sudan's to decide, and is unchanged.
- **The workbench's bulk overdue send** looks for uCRM's settings file one
  folder too high, so its pay link is empty unless `crm_base_url` is set. Same
  ladder, same status: only the port was fixed there.
- **Customer Login opens uCRM's client zone, not the plugin's portal**, and Pay
  Now is only in the portal. `dishnet-web-uganda/README-DEPLOY.md` records the
  plan to point it at the portal; [32](32-dpo-pay-review.md) §7 asks for that
  decision before DPO goes live.

## 8. Tests

- `tests/test_links_without_port.php`, **47 checks**, on a copy of the plugin
  laid out as on the server, with uCRM reporting `:8443`:
  - A page holding the admin screens' copy of the settings builds every link
    without `:8443`, including DPO's return, push and test addresses.
  - With no setting anywhere, every link is exactly as before (the Sudan case).
  - A value in the caller's own settings wins over the install's value.
  - The setting survives losing its file, through the vault.
  - The check tool sets, clears and reports correctly, against a stand-in for
    UISP's server that redirects `/crm` to `:8443`.
  - A scan fails if any code builds an address from uCRM's raw address or the
    request's host without the link builder. Every exception is named with its
    reason, and the list must still match the code.
- **11 weakened copies**, each caught by counted failures.
- **The whole plugin suite: 190 test files, 8,454 checks, 0 failed, twice.**
  - The first run found the setting leaking between tests through one shared
    vault, so the runner now gives each test its own. Its guard was rewritten to
    say so, and the new check rejects the old runner.
  - One test file renders the Orders tab on its own, and showed the tab running
    without the link builder loaded (4 checks). Each changed tab and include now
    loads it itself, as `dpo_payments.php` already did.
- **The website check** `verify-site.sh`: PASS. It now fails if any page links
  the bare `/crm` — shown by planting one back.
