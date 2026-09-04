# DishNet Uganda — ad kit

The honest-comparison campaign ("do it yourself, or done for you"), as
reproducible sources. Every figure in these ads is real and payable; the
fine print carries conditions only, never hidden charges.

| File | Size | Where it runs |
| --- | --- | --- |
| compare-ad.png | 1080×1080 | WhatsApp status, Instagram/Facebook feed |
| compare-story.png | 1080×1920 | WhatsApp/Instagram stories, reels covers |
| compare-wide.png | 1200×630 | Link shares, Facebook posts, banners |

## When a price changes

Prices are baked into these images (unlike the website, which reads uCRM
live) — so when uCRM prices change, or Starlink's direct pricing moves:

1. Edit the figures in the three `.html` sources (and the "Sept 2026"
   note in the fine print — the comparison dates itself on purpose).
2. `./render.sh`  (uses the site's own fonts; needs Chromium).
3. Re-post the new PNGs. Old posts keep old prices — delete or expire them.

Rules these ads follow, keep following them: both totals full-size (no
headline price that excludes mandatory charges), the delta is argued in
the bullets not hidden in fine print, and the UGX 0-upfront Flex offer is
the closer. Naming Starlink's own prices in PAID placements should wait
for the reseller channel letter.
