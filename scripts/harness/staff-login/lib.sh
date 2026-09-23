# Assertions for the harness. check "<what>" <command…> records PASS/FAIL.
check() {
  local what=$1; shift
  if "$@" >/dev/null 2>&1; then echo "  PASS  $what"; echo P >> "$HSIM/state/results"
  else echo "  FAIL  $what"; echo F >> "$HSIM/state/results"; fi
}
has()    { grep -qE -- "$2" "$1"; }            # has <file> <regex>
hasnt()  { ! grep -qE -- "$2" "$1"; }
hasnt_f(){ ! grep -qF -- "$2" "$1"; }          # hasnt_f <file> <fixed string>
eq()     { [ "$1" = "$2" ]; }
sql()    { psql -h "${HSIM_PGHOST:-/var/tmp}" -p "${HSIM_PGPORT:-55432}" -U postgres -d "${HSIM_DB:-dnb_hsim}" -Atc "$1"; }
ROUTE=/etc/easypanel/traefik/config/dnb-staging.yml
api_env() { grep -E '^DN_' "$HSIM/state/api.env" | sort | tr '\n' ' '; }
via() { curl -sk --resolve portal-staging.dishnetuganda.com:443:127.0.0.1 "$@"; }
