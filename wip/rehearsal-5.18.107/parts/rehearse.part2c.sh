# ── 107-infra i — the weakened copies of 5.18.107's script (R6's mechanics on this release's script): each anchor the script's
# own text, read here into a file of its own, its replacement beside it; each copy asserts its anchor once, its replacement
# once, and the result; ancc107 is the control of the controls over every anchor set. ──
AN107="$SB/anchors107"; rm -rf "$AN107"; mkdir -p "$AN107"
anc107() { printf '%s' "$2" > "$AN107/$1.a"; printf '%s' "$3" > "$AN107/$1.b"; }   # anc107 KEY ANCHOR REPLACEMENT
weak107() {   # weak107 NAME KEY… — a copy of the script in the clone's scripts/ with each KEY's anchor replaced, asserted; prints its path
  local name="$1" k dst="$REPO/scripts/.$1.sh"; shift
  cp "$DEPLOY107" "$dst"
  for k in "$@"; do
    python3 -I - "$dst" "$AN107/$k.a" "$AN107/$k.b" <<'PY' || echo "weak107:$k" >> "$SB/anchor-missing"
import sys
f, fa, fb = sys.argv[1:4]
s = open(f, encoding='utf-8').read(); a = open(fa, encoding='utf-8').read(); b = open(fb, encoding='utf-8').read()
assert a and a != b and s.count(a) == 1, (a[:70], s.count(a))
s2 = s.replace(a, b, 1)
assert s2.count(b) == 1 + s.count(b) and len(s2) == len(s) - len(a) + len(b) and (a in b or s2.count(a) == 0)
open(f, 'w', encoding='utf-8').write(s2)
PY
  done
  bash -n "$dst" 2>/dev/null || echo "weak107:$name:syntax" >> "$SB/anchor-missing"
  echo "$dst"
}
ancc107() {   # ancc107 — bad|n|keys over every anchor anc107 has set: each the script's own text, found in it once, its replacement different
  python3 -I - "$DEPLOY107" "$AN107" <<'PY'
import os, sys
s = open(sys.argv[1], encoding='utf-8').read(); d = sys.argv[2]
keys = sorted(f[:-2] for f in os.listdir(d) if f.endswith('.a'))
bad = [k for k in keys if (lambda a, b: not a or a == b or s.count(a) != 1)(open(os.path.join(d, k + '.a'), encoding='utf-8').read(), open(os.path.join(d, k + '.b'), encoding='utf-8').read())]
print('%d|%d|%s' % (len(bad), len(keys), ','.join(bad) or '-'))
PY
}
mutant107() {   # mutant107 NAME KEY… -- RUNNER-ARGS… — a weakened copy run once by run107c (or a held runner named first), its output
                # in MUT107 (kept apart in mutants/), the copy removed after; the caller asserts its case's own observable
  local name="$1" k=() mw; shift
  while [ $# -gt 0 ] && [ "$1" != -- ]; do k+=("$1"); shift; done; [ "${1:-}" = -- ] && shift
  mw="$(weak107 "$name" "${k[@]}")"
  MUT107="$(R107_MUTANT_OUT=1 run107c --script "$mw" "$@")"; MUTT107="$(last107)"; MUTRC107="$(rc107)"
  rm -f "$mw"
}
# ── Every fixture has its undo (R6's keep106/back106/mark106/cut106, reused on this release's files; v3 A9's snapshot). ──
SNAP107="$R107/snap/pre"   # the 107-pre snapshot A9 restores: the book (by 104's dsnap104), migration.log and the config files, each by sha256
snap107() {   # snap107 — taken once, at the end of 107-pre
  rm -rf "$SNAP107"; mkdir -p "$SNAP107"; dsnap104 pre107 >/dev/null 2>&1
  local f; for f in "$DATA/migration.log" "$DATA/config.json" "$DATA/kyc_config.json" "$PD/data/config.json"; do
    printf '%s\n' "$f" >> "$SNAP107/paths"
    if [ -e "$f" ]; then cp -p "$f" "$SNAP107/$(sha256sum <<<"$f" | cut -c1-16)"; sha256sum < "$f" | cut -c1-64 >> "$SNAP107/shas"; else echo absent >> "$SNAP107/shas"; fi
  done
}
snapeq107() {   # snapeq107 — same, or the files that differ from the snapshot (by sha256)
  local i=0 f w out=""
  while IFS= read -r f; do i=$((i+1)); w="$(sed -n "${i}p" "$SNAP107/shas")"
    if [ "$w" = absent ]; then [ -e "$f" ] && out="$out,${f##*/}:present"; else [ "$(sha256sum < "$f" 2>/dev/null | cut -c1-64)" = "$w" ] || out="$out,${f##*/}"; fi
  done < "$SNAP107/paths"
  echo "${out#,}" | sed 's/^$/same/'
}
reset107() {   # reset107 LABEL — v3 A9: 5.18.106 installed as Git has it (P106, asserted the script's baseline), the 107-pre snapshot back
               # (the book by drest104, the files by sha256), the deploy's records moved aside, the quarantine's --check; then asserted:
               # bf29cbd's record, no 092 file on disk, the snapshot's files, MG-G1 absent by a --check of the script
  local lab="$1" i f o dr
  [ "$P106" = "$B107" ] || { check "P106:$P106" "B107:$B107" "$lab A9 the reset installs the script's baseline"; return 1; }
  install_commit "$P106" >/dev/null 2>&1; chown -R 1000:1000 "$PD" 2>/dev/null   # the short form: deploy-hybrid writes %h, and the script reads the record against it
  dr="$(drest104 pre107 2>/dev/null)"
  i=0; while IFS= read -r f; do i=$((i+1))
    if [ "$(sed -n "${i}p" "$SNAP107/shas")" = absent ]; then rm -f "$f"; else cp -p "$SNAP107/$(sha256sum <<<"$f" | cut -c1-16)" "$f"; fi
  done < "$SNAP107/paths"
  tidy107 "$lab"; qcheck106 "$lab q"
  fresh107; o="$(run107c --check)"
  check "$(tail -n1 "$PD/.deployed-commit" | tr -cd '0-9a-f')|$dr|$( [ -e "$PD/migrations/092_wa_no_ai.sql" ] && echo 092-on-disk || echo none)|$(snapeq107)|$(has "$o" "  ok    MG-G1 the copy's migration ledger has no 092")|$(checkgo107 "$o")" \
        "$P106|same|same|same|none|same|yes|yesnono0|0|0|samekey|0" \
    "$lab A9 the reset: $P106 installed as Git has it (its record), no 092 on disk, the 107-pre snapshot's book and files back (by sha256), and a --check of the script reads MG-G1 absent and GO with nothing changed"
}
# ── The plants: rh107-seed.php through the INSTALLED release's own classes where one exists, a fixture writer where none does
# (said so in each case) — as the store's user, under the clock, with RH107_RANGE given to that run alone. ──
seed107() {   # seed107 CODE-ROOT OPS-JSON — rh107-seed.php over the live data folder; one line of JSON, or none(<why>)
  local o v="$H107/v/seed-vault.json"
  rm -f "$v"; [ ! -f "$VAULT" ] || { cp "$VAULT" "$v"; chown 1000:1000 "$v"; chmod 600 "$v"; }
  [ -f "$v" ] || { printf '{}\n' > "$v"; chown 1000:1000 "$v"; chmod 600 "$v"; }
  o="$(cd "$H107/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$v" RH107_RANGE="${PR107#+}" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" \
        http_proxy= https_proxy= HTTP_PROXY= HTTPS_PROXY= NO_PROXY='*' "$PHPBIN" -d log_errors=0 "$H107/rh107-seed.php" "$1" "$DATA" "$2" 2>"$H107/v/seed.err")"
  rm -f "$v"
  case "$o" in '{'*) printf '%s' "$o" ;; *) echo "none($(head -c 160 "$H107/v/seed.err" | tr -c 'A-Za-z0-9 :._(),-' '_'))" ;; esac
}
sd107() { python3 -I -c 'import json,sys; d=json.loads(sys.argv[1]); print(d.get(sys.argv[2], ""))' "$1" "$2" 2>/dev/null; }   # sd107 JSON KEY — one field of a seed's answer
pinroot107() { echo "$H107/pin/$P"; }   # the pin's code root (Git's tree, in this rehearsal's folder): the seed's writer where the live code is 5.18.106's
mkdir -p "$H107/pin"; git -C "$REPO" archive "$P107F" "$P" | tar -xf - -C "$H107/pin"; chown -R 1000:1000 "$H107/pin"
# ── 107's own numbers: +2567001070NN, beside the chain's 104 and 106 ranges. Proved free here, never assumed (R6's reading on
# this range): no harness of this checkout (5.18.107's own folder aside), no test or fixture of this checkout, of the pin's
# tree or the baseline's in Git, no file of the sandbox's data folders or records, and neither pattern file holds any of
# them — each reading's control the chain's own range, found by the same reading. ──
PR107='+2567001070'
RX107='(^|[^0-9])(\+?256|0)?7001070[0-9]{2}([^0-9]|$)|0700 107 0[0-9]{2}'; RXC107='(^|[^0-9])(\+?256|0)?7001060[0-9]{2}([^0-9]|$)'
ph107() { case "${1:-}" in [0-9][0-9]) printf '%s%s\n' "$PR107" "$1" ;; *) echo "ph107:${1:-}" >> "$SB/anchor-missing"; return 1 ;; esac; }   # ph107 NN
sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1
NH107="$(grep -rlE --exclude-dir=deploy-5.18.107 "$RX107" "$H107_ROOT/scripts/harness" 2>/dev/null | wc -l | tr -d ' ')"
NHC107="$(grep -rlE --exclude-dir=deploy-5.18.107 "$RXC107" "$H107_ROOT/scripts/harness" 2>/dev/null | wc -l | tr -d ' ')"
NT107="$(if [ -d "$H107_ROOT/$P/tests" ]; then grep -rlE "$RX107" "$H107_ROOT/$P/tests" 2>/dev/null | wc -l | tr -d ' '; else echo notests; fi)"
NG107="$(for c107 in "$P107F" "$B107F"; do git -C "$REPO" grep -lE "$RX107" "$c107" -- "$P/tests" 2>/dev/null | wc -l | tr -d ' '; done | paste -sd, -)"
ND107="$(grep -rlaE "$RX107" "$DATA" "$PD/data" "$RECS" 2>/dev/null | wc -l | tr -d ' ')"
NDC107="$(grep -rlaE "$RXC107" "$DATA" 2>/dev/null | wc -l | tr -d ' ')"
NP107="$(cat "$NAMES104" "$FORMS106" 2>/dev/null | grep -cE "$RX107")"
check "$NH107|$( [ "$(n103 "$NHC107")" -ge 1 ] && echo read)|$NT107|$NG107|$ND107|$( [ "$(n103 "$NDC107")" -ge 1 ] && echo read)|$NP107" "0|read|0|0,0|0|read|0" \
  "107-infra j 107's own numbers ($PR107<NN>) proved free: in no harness ($NHC107 file(s) of 106's range found by the same reading), no test or fixture of this checkout, none of the pin's or the baseline's in Git, no file of the sandbox's data or records ($NDC107 of 106's range found there), and in neither pattern file"
for i107 in $(seq -w 0 99); do n107="$(ph107 "$i107")"; grep -qxF -- "$n107" "$NAMES104" || printf '%s\n' "$n107" >> "$NAMES104"; done
{ cat "$R106/phones106.txt" 2>/dev/null; for i107 in $(seq -w 0 99); do ph107 "$i107"; done; } | grep . | sort -u > "$R107/phones107.txt"
FORMS107="$R107/forms107.pat"; PH4_107="$(q105 phones4 "$R107/phones107.txt" "$FORMS107")"
NM107F="$R107/names107.pat"; { cat "$NM106F" 2>/dev/null; echo 'RH107SECRET-'; } | grep . | sort -u > "$NM107F"; chmod 600 "$NM107F"
check "$(kv107 "$PH4_107" n)|$( [ "$(kv107 "$PH4_107" forms)" = "$(( 5 * $(n103 "$(kv107 "$PH4_107" n)") ))" ] && echo five)|$(stat -c %a "$FORMS107")|$(grep -cxF 'RH107SECRET-' "$NM107F")|$(grep -cxF 'RH106SECRET-' "$NM107F")" \
      "$(( $(grep -c . "$R106/phones106.txt" 2>/dev/null) + 100 ))|five|600|1|1" \
  "107-infra k the 100 numbers of 107's range beside 106's and the chain's, each in its four written forms and its last nine digits, into a 0600 pattern file; RH107SECRET- added to the names pattern beside RH106SECRET- (v2 A18)"
# NA-CA1's sentinel (NA_SENTINEL_1, S:861) is the one seeded value outside the range: the script's own constant, read here,
# never typed; 107-6 plants a row on it and 107-10 asserts it gone (v2 A18).
NAS107="$(sv107 "" NA_SENTINEL_1)"
check "$( case "$NAS107" in +256[0-9]*|256[0-9]*) echo read ;; *) echo "unread:${#NAS107}" ;; esac)" "read" "107-infra l NA-CA1's sentinel read from the script (the one planted value outside 107's range; swept at 107-10)"

# ── The EXIT trap: this release's triggers first, then the chain's own cleanup (the trap as the chain set it, or as a
# development runner restored it). ──
cleanup107() {
  [ -n "${SB:-}" ] && rm -f "$SB/web/.noboot107" "$SB/.plant092touch107" "$SB/.boot107" "$SB/.rbfail107" "$SB/.rbfail107.seen" "$SB/web/.redirect107" "$SB/web/.redirect107lock"
  [ -n "${DATA:-}" ] && [ -f "$DATA/migration.log" ] && chmod u+w "$DATA/migration.log" 2>/dev/null
  return 0
}
TRAP0_107="$(trap -p EXIT | sed -E "s/^trap -- '(.*)' EXIT\$/\1/")"
case "$TRAP0_107" in ''|trap*|*"'"*) echo "trap107:unread" >> "$SB/anchor-missing" ;; *) trap -- "cleanup107; $TRAP0_107" EXIT ;; esac
check "$( [ -n "$TRAP0_107" ] && trap -p EXIT | grep -cF -- "cleanup107; $TRAP0_107")|$(LEFT107)|$( [ "$(grep -c . "$SB/anchor-missing" 2>/dev/null)" = "$AM0_107" ] && echo noanchormiss)" "1|none|noanchormiss" \
  "107-infra m the EXIT trap runs cleanup107 first, then the chain's (${TRAP0_107:-unread}); every 107 hook inserted with its anchor found (no anchor-missing line since 107-infra began); nothing armed"
EVO0_107="$(evo_calls)"   # Evolution's requests before 107-pre: every later one is a run's or a worker's, each asserted where it is meant
