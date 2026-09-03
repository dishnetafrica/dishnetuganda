# DishNet Mail — deployment runbook

Self-hosted mail for `dishnetuganda.com` on a dedicated droplet:
**Stalwart** (SMTP + IMAP + JMAP + spam filter + REST API) and **Roundcube**
webmail. The uCRM plugin provisions customer mailboxes through Stalwart's API
and reads the Starlink intake mailbox over JMAP.

**Approved architecture — see the internal "DishNet Mail Architecture"
document. Nothing below may be run until the §13 verification outputs
(port 25, memory, region) have been reviewed and the go-ahead given.**

## 0. Pre-flight (on the EXISTING app droplet — read-only)

```bash
# outbound port 25 (tests DigitalOcean's account policy, informational only —
# the design relays outbound through SES/Brevo either way)
timeout 8 bash -c 'exec 3<>/dev/tcp/gmail-smtp-in.l.google.com/25 && head -1 <&3' \
  && echo "PORT 25 OUTBOUND: OPEN" || echo "PORT 25 OUTBOUND: BLOCKED"
free -h
curl -s http://169.254.169.254/metadata/v1/region; echo
```

## 1. Create the mail droplet

- 2 GB / 1 vCPU, same region as the app droplet, Ubuntu LTS.
- **Name it `mail.dishnetuganda.com`** — DigitalOcean derives the PTR
  (reverse DNS) from the droplet name; correct rDNS is a deliverability
  requirement, not a nicety.
- DO cloud firewall for this droplet:

| Port | Source | Purpose |
| --- | --- | --- |
| 25 | anywhere | inbound MX |
| 465, 587 | anywhere | mail submission |
| 993 | anywhere | IMAP |
| 443 | anywhere | JMAP + admin API + ACME |
| 8081 | app droplet IP only | Roundcube behind the app droplet's Traefik |
| 22 | office/home IPs only | SSH |

## 2. Deploy the stack

```bash
apt-get update && apt-get install -y docker.io docker-compose-v2 git restic
git clone https://github.com/dishnetafrica/dishnetuganda /opt/dishnet && cd /opt/dishnet/dishnet-mail
cp .env.example .env && nano .env          # set STALWART_ADMIN
docker compose up -d
docker compose logs stalwart | head -30    # confirm clean start
# Pin the image: record `docker inspect --format '{{index .RepoDigests 0}}' stalwart`
# and replace the :latest tag in docker-compose.yml with that digest.
```

DNS record **A `mail` → this droplet's IP** must exist before start so
Stalwart's ACME can issue the TLS certificate.

## 3. First-run configuration (admin UI at https://mail.dishnetuganda.com)

1. Add domain `dishnetuganda.com`; let Stalwart generate the **DKIM** key and
   copy the DNS record it shows (goes into GoDaddy in §7).
2. Create staff mailboxes: `bhavin@`, `kishan@`, `billing@`, plus the system
   accounts `starlink@` (Starlink intake), `dmarc@` (reports), `postmaster@`
   and `abuse@` (aliases to billing@ are fine — RFC-expected addresses).
3. **Global copy rule**: any message from `*@starlink.com` delivers to the
   recipient AND a copy to `starlink@`. (Settings → sieve/global scripts.)
   This is what lets customers keep their mail while the AI processes a
   central copy.
4. **Quotas**: customer default 250 MB (the plugin sets it per mailbox);
   staff 5 GB.
5. **Rate limits / abuse**: keep Stalwart's auth-failure banning on; cap
   authenticated submissions for customer accounts (they exist to receive) —
   e.g. 50/day — so 10,000 identities can never become a spam cannon. Staff
   accounts get a working limit (e.g. 1,000/day).
6. **API key** for the plugin: create a Stalwart API key/administrator
   restricted to principal management. This value becomes
   `stalwart_api_token` in the plugin's `kyc_config.json` — never in git,
   never in a browser page.
7. **App password** for `starlink@` → `starlink_mail_password` in
   `kyc_config.json` (the JMAP poller's credential).

## 4. Admin access & audit

- Admin UI/API only over HTTPS; the 8080 listener stays bound to 127.0.0.1.
- One named admin account per human — no shared logins; the recovery admin
  from `.env` is break-glass only.
- Stalwart's own logs plus the plugin's `customer_identities` /
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
  then relay `smtp-relay: email-smtp.<region>.amazonaws.com:587` with the
  SMTP credentials. ~$0.10 per 1,000 messages.
- **Brevo** (no AWS): relay `smtp-relay.brevo.com:587` with the API SMTP key;
  free tier 300/day covers early operation.

Direct outbound on port 25 stays disabled even if the pre-flight showed it
open — reputation of a fresh droplet IP is not worth burning; the relay's is
established.

## 7. DNS cutover at GoDaddy (one sitting, after §3 tests pass)

| Action | Type | Name | Value |
| --- | --- | --- | --- |
| add | A | `mail` | mail droplet IP |
| add | A | `webmail` | **app** droplet IP (Traefik terminates TLS there) |
| replace | MX | `@` | `10 mail.dishnetuganda.com` (delete both secureserver rows) |
| replace | TXT | `@` | `v=spf1 mx include:amazonses.com -all` *(swap the include for Brevo's if chosen)* |
| add | TXT | `default._domainkey` | the DKIM record from §3.1 |
| add | CNAME ×3 | SES/Brevo DKIM names | as shown by the relay |
| add | TXT | `_dmarc` | `v=DMARC1; p=quarantine; rua=mailto:dmarc@dishnetuganda.com` |
| delete | CNAME | `email`, `secureserver1._domainkey`, `secureserver2._domainkey` | GoDaddy leftovers |

Then on the app droplet, drop `traefik-webmail.yml` (in this directory,
with the mail droplet IP filled in) into `/etc/easypanel/traefik/config/` —
same mechanism as the proven `uisp.yaml` route.

Verify before announcing:

```bash
curl -sI https://webmail.dishnetuganda.com | head -1     # 200, valid cert
# send from billing@ to a Gmail you control and to https://www.mail-tester.com
# — do not go live under 10/10.
```

Finally point uCRM's own mailer at the new system: UISP → CRM → Settings →
Mailer → `mail.dishnetuganda.com:465`, login `billing@dishnetuganda.com`.

## 8. Backups & restore

```bash
# nightly (root crontab on the mail droplet):
#   17 2 * * * /opt/dishnet/dishnet-mail/backup.sh >> /var/log/dishnet-mail-backup.log 2>&1
# weekly: enable DigitalOcean droplet snapshots in the DO panel.
```

`backup.sh` snapshots `./data` (mail store, accounts, DKIM keys, Roundcube
db) with restic to a DigitalOcean Space, encrypted, 30-day retention.
**Restore drill (do once before go-live):** create a throwaway droplet,
`restic restore latest`, `docker compose up -d`, log into a mailbox, destroy
the droplet. Write the date of the successful drill here: ______

## 9. Retention & deletion policy

- Termination/archival in uCRM → the plugin **suspends** the mailbox (login
  off, delivery held, all mail kept). Nothing is deleted automatically.
- After the retention period (recommend 12 months suspended), an admin may
  delete the principal in Stalwart manually and set the identity row to
  `disabled`. The email address itself is **never reissued to another
  customer** — the UNIQUE row in `customer_identities` persists forever.

## 10. Capacity marks

| Customers | Droplet | Disk | Notes |
| --- | --- | --- | --- |
| 100 | 2 GB | included 50 GB | — |
| 1,000 | 2 GB | included | watch billing-day inbound bursts |
| 10,000 | 4 GB | +100 GB volume | raise the plugin's poll batch; consider Stalwart's webhook delivery instead of polling |
