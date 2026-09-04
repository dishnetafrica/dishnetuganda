# DishNet Mail — deployment runbook (same-server edition)

Self-hosted mail for `dishnetuganda.com` — **Stalwart** (SMTP + IMAP + JMAP +
spam filter + REST API) and **Roundcube** webmail — running **on the existing
app droplet (209.97.137.203)** beside UISP and EasyPanel, per the owner's
decision to keep one server and resize it. The uCRM plugin provisions
customer mailboxes through Stalwart's API and reads the Starlink intake
mailbox over JMAP.

**The trade this accepts, eyes open:** mail now shares the droplet with the
network controller. A full disk or memory pressure from one affects the
other, and any droplet resize takes UISP down for the resize window. The
mitigations below (quotas, monitoring alerts, backups, the resize done first
in a maintenance window) are not optional.

**Nothing below may be run until the §0 verification outputs have been
reviewed and the go-ahead given.**

## 0. Pre-flight (read-only, run and report before anything else)

```bash
free -h                                        # current memory headroom
df -h /                                        # disk headroom
ss -tlnp | grep -E ':(25|465|587|993|8081|8090)\b' || echo "mail ports free"
timeout 8 bash -c 'exec 3<>/dev/tcp/gmail-smtp-in.l.google.com/25 && head -1 <&3' \
  && echo "PORT 25 OUTBOUND: OPEN" || echo "PORT 25 OUTBOUND: BLOCKED"   # informational — outbound uses the relay either way
ls -la /etc/easypanel/traefik/*.json           # confirm the acme.json path for certs-dumper
```

## 1. Resize the droplet (maintenance window — UISP goes down briefly)

- Target: enough RAM that **at least 2 GB is free after** the mail stack
  (Stalwart + Roundcube + dumper ≈ 0.7 GB). If `free -h` shows less than
  3 GB available today, resize one step up.
- Use the **CPU/RAM-only** resize (reversible); avoid the disk resize
  (permanent) unless disk is also tight.
- Announce the window: UISP, uCRM, the websites and Evolution are all down
  for the few minutes of the resize. Devices reconnect on their own —
  connection keys are untouched.
- Afterwards, set DO monitoring alerts: memory > 85%, disk > 80%.
- In the DO panel, rename the droplet to `mail.dishnetuganda.com` so the
  IP's reverse DNS (PTR) matches the mail host name. Cosmetic for
  relay-outbound mail, but correct.

## 2. Deploy the stack

```bash
cd /opt && git clone https://github.com/dishnetafrica/dishnetuganda dishnet 2>/dev/null || (cd /opt/dishnet && git pull)
cd /opt/dishnet/dishnet-mail
cp .env.example .env && nano .env              # set STALWART_ADMIN
# DNS first: A `mail` → 209.97.137.203 must exist, then the Traefik route so
# Traefik obtains the certificate BEFORE Stalwart needs it:
cp traefik-mail.yml /etc/easypanel/traefik/config/
sleep 90 && curl -sI https://mail.dishnetuganda.com | head -1    # expect 200/401, valid cert
docker compose up -d
docker compose logs stalwart | head -30        # confirm clean start
# Pin the image: record `docker inspect --format '{{index .RepoDigests 0}}' stalwart`
# and replace the :latest tag in docker-compose.yml with that digest.
```

Open **25, 465, 587, 993** in the DO cloud firewall for this droplet. Do NOT
open 8081/8090 — they are bound to the docker bridge address and reachable
only through Traefik or an SSH tunnel.

## 3. First-run configuration (admin UI at https://mail.dishnetuganda.com)

1. **Disable Stalwart's ACME** and point its TLS at the dumper-exported
   files: `/opt/stalwart-certs/mail.dishnetuganda.com/fullchain.pem` and
   `privkey.pem`. Traefik is the one certificate owner on this box; the
   dumper keeps these files current when Traefik renews. Set the server
   hostname to `mail.dishnetuganda.com`.
2. Add domain `dishnetuganda.com`; let Stalwart generate the **DKIM** key and
   copy the DNS record it shows (goes into GoDaddy in §7).
3. Create staff mailboxes: `bhavin@`, `kishan@`, `billing@`, plus the system
   accounts `starlink@` (Starlink intake), `dmarc@` (reports), and
   `postmaster@`/`abuse@` (aliases to billing@ — RFC-expected addresses).
4. **Global copy rule**: any message from `*@starlink.com` delivers to the
   recipient AND a copy to `starlink@` (Settings → sieve/global scripts).
   This is what lets customers keep their mail while the AI processes a
   central copy.
5. **Quotas**: customer default 250 MB (the plugin sets it per mailbox);
   staff 5 GB. On a shared droplet the quotas are what caps mail's disk use.
6. **Rate limits / abuse**: keep Stalwart's auth-failure banning on; cap
   authenticated submissions for customer accounts (they exist to receive) —
   e.g. 50/day — so 10,000 identities can never become a spam cannon. Staff
   accounts get a working limit (e.g. 1,000/day).
7. **API key** for the plugin: create a Stalwart API key restricted to
   principal management → `stalwart_api_token` in the plugin's
   `kyc_config.json`. Never in git, never in a browser page.
8. **App password** for `starlink@` → `starlink_mail_password` in
   `kyc_config.json` (the JMAP poller's credential). The plugin's
   `stalwart_api_url` and `jmap_url` are both `https://mail.dishnetuganda.com`.
9. Verify the API against this Stalwart version before enabling anything:
   `php tools/test_stalwart_api.php` (from the plugin directory).

## 4. Admin access & audit

- Admin UI/API only via `https://mail.dishnetuganda.com` (Traefik) or an SSH
  tunnel to `172.17.0.1:8090`; nothing HTTP is exposed publicly.
- One named admin account per human — no shared logins; the recovery admin
  from `.env` is break-glass only.
- Stalwart's logs plus the plugin's `customer_identities` /
  `starlink_events` tables (and the EventBus history rows) form the audit
  trail: every mailbox creation, suspension, password reset and AI
  classification is attributable and timestamped.

## 5. Customer privacy (write this into staff practice)

- Customer mailboxes are the customer's. Staff access goes through the
  admin API for lifecycle actions only (create / suspend / reset); reading a
  customer's mailbox content requires the customer's request or a documented
  operational need, logged in uCRM.
- The AI worker reads ONLY `starlink@` — the central copies of Starlink
  service mail — never customer mailboxes.
- Passwords are never stored by DishNet: reset shows the new password once,
  it is relayed on WhatsApp, and only the customer knows it afterwards.

## 6. Outbound relay (SES or Brevo)

Configure the smarthost in Stalwart (Settings → SMTP → routing/relay):

- **Amazon SES** (cheapest at scale, needs AWS account): verify the domain,
  add the three DKIM CNAMEs SES shows to GoDaddy, request production access,
  then relay `email-smtp.<region>.amazonaws.com:587` with the SMTP
  credentials. ~$0.10 per 1,000 messages.
- **Brevo** (no AWS): relay `smtp-relay.brevo.com:587` with the SMTP key;
  free tier 300/day covers early operation.

Direct outbound on port 25 stays disabled even if pre-flight showed it open:
this IP now carries the whole business, and its sending reputation is not a
thing to gamble — the relay's is established.

## 7. DNS cutover at GoDaddy (one sitting, after §3 tests pass)

| Action | Type | Name | Value |
| --- | --- | --- | --- |
| add | A | `mail` | `209.97.137.203` |
| add | A | `webmail` | `209.97.137.203` |
| replace | MX | `@` | `10 mail.dishnetuganda.com` (delete both secureserver rows) |
| replace | TXT | `@` | `v=spf1 mx include:amazonses.com -all` *(swap the include for Brevo's if chosen)* |
| add | TXT | `default._domainkey` | the DKIM record from §3.2 |
| add | CNAME ×3 | SES/Brevo DKIM names | as shown by the relay |
| add | TXT | `_dmarc` | `v=DMARC1; p=quarantine; rua=mailto:dmarc@dishnetuganda.com` |
| delete | CNAME | `email`, `secureserver1._domainkey`, `secureserver2._domainkey` | GoDaddy leftovers |

Verify before announcing:

```bash
curl -sI https://webmail.dishnetuganda.com | head -1     # 200, valid cert
# send from billing@ to a Gmail you control and to https://www.mail-tester.com
# — do not go live under 10/10.
```

Finally point uCRM's own mailer at the new system: UISP → CRM → Settings →
Mailer → `mail.dishnetuganda.com:465`, login `billing@dishnetuganda.com`.
(The SPF `-all` means mail sent any other way hard-fails — intended.)

## 8. Backups & restore

```bash
# nightly (root crontab):
#   17 2 * * * /opt/dishnet/dishnet-mail/backup.sh >> /var/log/dishnet-mail-backup.log 2>&1
# weekly: DigitalOcean droplet snapshots stay on (they now cover mail too).
```

`backup.sh` snapshots `./data` (mail store, accounts, Roundcube db — the
dumper certs are reproducible and excluded from concern) with restic to a
DigitalOcean Space, encrypted, 30-day retention.
**Restore drill (do once before go-live):** create a throwaway droplet,
`restic restore latest`, `docker compose up -d` (with a temp self-signed
cert), log into a mailbox, destroy the droplet. Date of successful drill: ______

## 9. Retention & deletion policy

- Termination/archival in uCRM → the plugin **suspends** the mailbox (login
  off, delivery held, all mail kept). Nothing is deleted automatically.
- After the retention period (recommend 12 months suspended), an admin may
  delete the principal in Stalwart manually and set the identity row to
  `disabled`. The email address itself is **never reissued to another
  customer** — the UNIQUE row in `customer_identities` persists forever.

## 10. Capacity marks (shared droplet)

| Customers | RAM for mail | Disk for mail | Notes |
| --- | --- | --- | --- |
| 100 | ~0.7 GB | < 1 GB | — |
| 1,000 | ~0.8 GB | ~5–10 GB | watch billing-day inbound bursts; keep the 85% memory alert honest |
| 10,000 | ~1–1.5 GB | ~50–100 GB | resize again or REVISIT the dedicated-droplet split — at this scale isolation starts paying for itself |
