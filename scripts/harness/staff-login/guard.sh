# Sourced by every harness entry point. The harness writes to the SAME paths as
# the staging server (/opt/dnb-staging, /etc/easypanel/traefik/config,
# /root/dnb-staging-evidence), so it refuses anywhere that could be that server.
# Four independent checks; any one refuses.
HSIM_HOME=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HSIM_HOME/../../.." && pwd)
export HSIM="${HSIM:-/var/tmp/dnb-hsim}"
refuse() { echo "REFUSING: $*" >&2; exit 2; }
[ "${HSIM_SANDBOX:-}" = "yes-this-is-not-the-server" ] \
  || refuse "set HSIM_SANDBOX=yes-this-is-not-the-server. This harness writes to /opt/dnb-staging, /etc/easypanel/traefik/config and /root/dnb-staging-evidence — the staging server's own paths"
for f in traefik-mail.yml main.yaml uisp.yaml; do
  [ ! -e "/etc/easypanel/traefik/config/$f" ] || refuse "/etc/easypanel/traefik/config/$f exists — this looks like the DishNet server"
done
real_docker=$(PATH=$(printf '%s' "$PATH" | tr ':' '\n' | grep -vF "$HSIM_HOME/bin" | paste -sd: -) command -v docker 2>/dev/null || true)
if [ -n "$real_docker" ] && "$real_docker" info >/dev/null 2>&1; then refuse "a real Docker daemon answers here"; fi
if [ -e /opt/dnb-staging ] && [ ! -e /opt/dnb-staging/.hsim-sandbox ]; then
  refuse "/opt/dnb-staging exists and was not created by this harness"
fi
mkdir -p /opt/dnb-staging "$HSIM/state"; touch /opt/dnb-staging/.hsim-sandbox
export PATH="$HSIM_HOME/bin:$PATH"
unset https_proxy HTTPS_PROXY http_proxy HTTP_PROXY no_proxy NO_PROXY all_proxy ALL_PROXY || true
