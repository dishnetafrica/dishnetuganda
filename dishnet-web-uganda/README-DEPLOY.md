# DishNet Uganda — website deployment

Static site for `dishnetuganda.com`, served by nginx behind the EasyPanel
Traefik. 56 pages: homepage, 13 city pages, kits/services/coverage, 4 blog
posts, FAQ/legal, and 19 app tutorials. Same container pattern as
`dishnet-web` (the Sudan site).

Built from the "Full Site Build v2" drop, then localised in-repo:

| Fixed | Detail |
| --- | --- |
| Domain | All canonicals, OG tags, JSON-LD, sitemap and robots now `dishnetuganda.com` (the drop targeted `uganda.dishnetafrica.com`; tutorial pages pointed at `dishnetafrica.com`) |
| WhatsApp | `wa.me/256705993348` / `+256 705 993 348` everywhere (was the `256700000000` placeholder; one stray South Sudan number in a tutorial fixed too) |
| Customer portal | `https://crm.dishnetuganda.com/crm` (was the South Sudan plugin URL) |
| Images | The drop hotlinked one logo PNG from `portal.dishnetss.com` in 110 places, including favicon and og:image. Now local: `assets/img/favicon.svg` (header + icon), `og-dishnet.png` (social + JSON-LD), kit photos on starlink-kits.html |
| Fonts | `Outfit` / `DM Sans` / `Barlow` woff2 now shipped and linked on every page — the drop named them with no font files, so every visitor got fallbacks |
| 404 | Branded `404.html` added (the drop had none, so nginx would have served its default page) |

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
```

Serves `site/` with the real `nginx.conf` and checks: every page 200, every
internal link and asset resolves (no masked 404s), security headers and exactly
one Cache-Control on every path type, real branded 404, canonicals all on
`dishnetuganda.com`, sitemap well-formed with every URL resolving, no
placeholder or other-country remnants, one WhatsApp number, one portal URL —
and the house rule: **no price anywhere outside tutorial mock screenshots.**
Prices and the product list live in uCRM only.

## Still placeholder — replace when known

| Item | Where |
| --- | --- |
| Email `uganda@dishnetafrica.com` | 37 files — confirm this mailbox exists, or send the real one |
| Office address | `contact.html` says "Street address coming soon" |
| APK | drop the signed app at `site/dishnet-africa.apk` (get-the-app.html links it; nginx already serves `.apk` with the right MIME type) |
| Testimonials / gallery | deliberately absent — send real Ugandan quotes and photos and the pages get built; invented ones are fake reviews |
| Portal deep-link | once the DishNet Hybrid plugin is installed on the Uganda uCRM, point "Customer Login" at its `public.php?page=customer_portal` instead of the bare `/crm` zone |
| Website AI chat | the Sudan site's `chat.js` widget can be added once the plugin (and its `web_chat.php` + spend guard) is live on the Uganda uCRM |

## Content caveat

The site advertises **Starlink, Fiber, WiFi/hotspot zones, and CCTV/security**
(`index.html`, `services.html`, `fiber.html`, `hotspot.html`, `security.html`).
If any of these is not actually sold in Uganda yet, remove the page and its
nav/footer links before launch — an advertised service nobody can buy costs
trust.
