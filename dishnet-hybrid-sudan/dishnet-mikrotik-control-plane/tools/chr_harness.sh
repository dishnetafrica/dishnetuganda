#!/usr/bin/env bash
#
# THE REAL CHECK — AND IT HAS NOT BEEN RUN.
#
# docs/30 Artifact 13 rule 2 requires the provisioner to be driven against a
# real RouterOS CHR instance, factory-reset between runs, because a fake
# MikroTik passes while the real one rejects the command. The unit tests in
# this repository run against tests/fake_routeros.php and therefore prove our
# logic and nothing about RouterOS's acceptance of it.
#
# This script exists so that check is a command someone can run rather than an
# intention. It requires, and the development environment did not have:
#
#   * hardware virtualisation (/dev/kvm, or vmx/svm in /proc/cpuinfo)
#   * qemu-system-x86_64 or a working Docker daemon
#   * network access to download a CHR image
#
# Even a green run here is NOT the Phase 0 gate. docs/31 §1.1 is explicit that
# CHR has no radio, no RouterBOARD serial and no factory-reset behaviour to
# speak of, so the bootstrap flow can only be proven on metal. This narrows
# what is unknown; it does not close it.
#
set -euo pipefail

CHR_VERSION="${CHR_VERSION:-}"          # read from mikrotik.com on the day; do not pin here
CHR_IMG="${CHR_IMG:-chr.img}"
SSH_PORT="${SSH_PORT:-2222}"
API_PORT="${API_PORT:-8729}"
MGMT_USER="${MGMT_USER:-dn-mgmt}"

need() { command -v "$1" >/dev/null || { echo "missing: $1"; exit 2; }; }

preflight() {
  need qemu-system-x86_64
  [ -e /dev/kvm ] || echo "WARNING: no /dev/kvm — CHR will be slow or will not boot"
  [ -f "$CHR_IMG" ] || { echo "no $CHR_IMG. Download the CHR raw image for the version you"
                         echo "are standardising on, per docs/31 §2, and record that version"
                         echo "in the compatibility matrix."; exit 2; }
}

boot() {
  qemu-system-x86_64 -m 256 -smp 1 -drive file="$CHR_IMG",format=raw,if=virtio \
    -nic user,hostfwd=tcp::"$SSH_PORT"-:22,hostfwd=tcp::"$API_PORT"-:443 \
    -nographic -daemonize
  echo "waiting for CHR ..."
  for _ in $(seq 1 60); do
    nc -z 127.0.0.1 "$SSH_PORT" && return 0
    sleep 2
  done
  echo "CHR did not come up"; exit 1
}

# The checks that matter, each mapping to a row docs/31 §2.1 says to CONFIRM
# rather than assume.
checks() {
  cat <<'EOF'
  1. /interface/wireguard/print does not error        (WireGuard present)
  2. /ip/service/print shows www-ssl; enable with a certificate
  3. GET /rest/system/resource returns JSON           (REST reachable)
  4. REST over a SELF-SIGNED certificate is accepted  (docs/30 3b: UNVERIFIED)
  5. /ip/hotspot/profile PATCH use-radius=yes is accepted
  6. Read back confirms it applied — not just that it was accepted
  7. The exact maximum Mikrotik-Rate-Limit the unit will take
     (PlanValidator::MAX_RATE_BPS is a conservative guess until this is read)
  8. /export completeness after each step
EOF
}

case "${1:-help}" in
  preflight) preflight ;;
  boot)      preflight; boot ;;
  checks)    checks ;;
  *) echo "usage: $0 {preflight|boot|checks}"; checks ;;
esac
