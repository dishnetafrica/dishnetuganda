# DishNet Uganda — website deployment

Static site for `dishnetuganda.com`, served by nginx behind the EasyPanel
Traefik. 63 pages: homepage, 12 town pages, kits/services/coverage, 3 blog
articles, FAQ, company and policy pages, and 19 app tutorials. Same container pattern as
`dishnet-web` (the Sudan site).

Built from the "Full Site Build v2" drop, then localised in-repo:

| Fixed | Detail |
| --- | --- |
| Domain | All canonicals, OG tags, JSON-LD, sitemap and robots now `dishnetuganda.com` (the drop targeted `uganda.dishnetafrica.com`; tutorial pages pointed at `dishnetafrica.com`) |
| WhatsApp | `wa.me/256705993348` / `+256 705 993 348` everywhere (was the `256700000000` placeholder; one stray South Sudan number in a tutorial fixed too) |
| Customer portal | Every "Customer Login" links the site's own `/login` (16 Oct 2026), which `nginx.conf` answers with a 302 to `https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login` — the DishNet portal sign-in (26 Sep 2026; decision 8 of the customer-login remediation plan) — so no page carries the plugin's `dishnet-hybrid-sudan` path in a customer link. The browser's address bar still shows it once the CRM answers; removing it there is a CRM-side change. It was uCRM's own client-zone login `/crm/login` from 25 Sep, and the South Sudan plugin URL before that. Never the bare `/crm`: the server in front of uCRM redirects it to `/crm/` on its own port, `:8443`, which has a self-signed certificate |
| Images | The drop hotlinked one logo PNG from `portal.dishnetss.com` in 110 places, including favicon and og:image. Now local: `assets/img/favicon.svg` (header + icon), `og-dishnet-uganda.png` (social + JSON-LD; `og-uganda.png` retired in `docs/70`), kit photos on starlink-kits.html |
| Fonts | `Outfit` / `DM Sans` / `Barlow` woff2 now shipped and linked on every page — the drop named them with no font files, so every visitor got fallbacks |
| 404 | Branded `404.html` added (the drop had none, so nginx would have served its default page) |
| Address | `4th floor, Acacia Mall, 14-18 Cooper Road, office TT06, Kampala` on every page and in every schema.org record. Was Mawanda Road in 39 places. Same string as the plugin's knowledge base, so the website and the WhatsApp assistant cannot drift |
| 404 country | `404.html` was still the Sudan template: logo and tag read "DishNet SUDAN", `addressCountry` and `geo.region` were `SD`, `areaServed` was `"SD"`, and it had no phone. All corrected from a page that was already right |
| Tutorial phone | `get-help-on-whatsapp.html` said to call `+256 921 443 005` — the South Sudan support number `+211 921 443 002` wearing a Uganda prefix. Now `+256 705 993 348` like everywhere else |

## Deploy on EasyPanel

1. **+ Service → App** in the `web` project, name `web-uganda`.
2. Source: this GitHub repo, branch `main`, **build path `/dishnet-web-uganda`**,
   Build = Dockerfile. (Or upload this folder.)
3. Deploy, then in **Domains**: add `dishnetuganda.com`.
   - `dishnetuganda.com` currently belongs to the `web-sudan` app — remove it
     there first, and give `web-sudan` its real domain (`dishnetsudan.com`)
     in the same sitting so the Sudan site stays reachable.
4. `curl -sI https://dishnetuganda.com | head -1` → `HTTP/2 200`.

## Verify before and after any content change

```bash
./verify-site.sh        # needs nginx on PATH (run on the server or in CI)
python3 verify-address.py   # no nginx needed, runs anywhere
python3 policies/build.py --check   # the nine policy pages match their source
```

The policy pages (`policies.html`, `terms.html`, `terms-of-sale.html`, `warranty.html`, `returns.html`,
`installation.html`, `acceptable-use.html`, `privacy.html`, `complaints.html`) are built by `policies/build.py` from
`policies/bodies/`. Edit the body or a number in its `PARAMS`, then run `python3 policies/build.py`; never edit those
nine files in `site/` by hand. The build stops if the FAQ or the kits page repeats a policy number that no longer
matches. They are not legally approved: see the repository's `docs/67` for the review they still need.

`verify-site.sh` also checks the withdrawn pages (`/fiber.html`, `/fiber` and the fibre article answer 301 with the
security headers and a ten-minute cache; `/app` answers 302 to the app page) and the positioning rules of October
2026: no fibre, LTE, "24/7", "four regions", "every region", "10+ years", "ten years", "two countries" or "thousands
of" on any page. See the repository's `docs/70`.

`verify-address.py` is the one to run after any contact-detail edit: it
asserts the whole site agrees on one address, one phone and one coverage
list, that nothing points at the old office or at Sudan, and that every
JSON-LD block still parses — an address edit that breaks the structured data
silently removes the business from search results.

Serves `site/` with the real `nginx.conf` and checks: every page 200, every
internal link and asset resolves (no masked 404s), security headers and exactly
one Cache-Control on every path type, real branded 404, canonicals all on
`dishnetuganda.com`, sitemap well-formed with every URL resolving, no
placeholder or other-country remnants, one WhatsApp number, no portal URL in
any page (`/login` answers 302 to the CRM sign-in, with the security headers) —
and the house rule: **no price anywhere outside tutorial mock screenshots.**
Prices and the product list live in uCRM only.

## Still placeholder — replace when known

| Item | Where |
| --- | --- |
| Email `info@dishnetafrica.com` | 44 of the 63 HTML files (all but `shop.html` and 18 of the 19 tutorials) — confirmed by the owner as the consultation address (`docs/70`, decision 9) |
| APK | not published (operator, 25 Sep 2026). `get-the-app.html` says *coming soon* with a WhatsApp "tell me" button instead of a download that 404'd. When the signed app exists: drop it at `site/dishnet-africa.apk` and put the download button back (nginx already serves `.apk` with the right MIME type) |
| Testimonials / gallery | deliberately absent — send real Ugandan quotes and photos and the pages get built; invented ones are fake reviews |
| Portal deep-link | DONE 26 Sep 2026: every "Customer Login" opens `public.php?page=customer_login`, which sends a customer with a live session straight to the portal. Since 16 Oct 2026 the 197 links on 64 pages point at `/login` and nginx redirects (302) to that URL; `verify-site.sh` fails on any page that links `page=customer_login` directly and checks the 302 and its `Location`. A short address on the CRM host (`/customer-login`, one Traefik file) is optional and separate |
| Website AI chat | DONE: `assets/js/chat.js` on every page talks to the plugin's `web_chat` endpoint. Since `docs/70` it thanks a visitor for their details only when the endpoint answers that it stored them |

## Content caveat

Since `docs/70` (October 2026) the site is an ICT consultancy focused on **Starlink** (acquisition guidance,
installation and support, own-name registration or a managed DishNet plan), with **CCTV and network security** as a
separate service. DishNet Uganda does not sell fibre: `fiber.html` and the fibre article were removed and `nginx.conf`
redirects their addresses. The **WiFi-zone / hotspot** pages, the **reseller and distributor** programmes and the
**accessories shop** are kept pending the owner's decision (`docs/70` §8.4); an advertised service nobody can buy
costs trust, so settle them before promoting them.
