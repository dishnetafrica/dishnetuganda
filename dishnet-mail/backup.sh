#!/usr/bin/env bash
# Nightly restic backup of the mail store to a DigitalOcean Space.
# Cron:  17 2 * * * /opt/dishnet/dishnet-mail/backup.sh >> /var/log/dishnet-mail-backup.log 2>&1
#
# One-time setup (values live in /root/.dishnet-mail-backup.env, chmod 600,
# NEVER in git):
#   RESTIC_REPOSITORY=s3:https://<region>.digitaloceanspaces.com/<bucket>/mail
#   RESTIC_PASSWORD=<openssl rand -base64 24>       # losing this loses the backups — store it in the company vault
#   AWS_ACCESS_KEY_ID=<spaces key>
#   AWS_SECRET_ACCESS_KEY=<spaces secret>
# then:  . /root/.dishnet-mail-backup.env && restic init
set -euo pipefail
cd "$(dirname "$0")"

. /root/.dishnet-mail-backup.env

restic backup ./data .env docker-compose.yml --tag dishnet-mail
restic forget --tag dishnet-mail --keep-daily 30 --keep-weekly 8 --prune
restic check --read-data-subset=2%
echo "[$(date -u +%FT%TZ)] backup ok"
