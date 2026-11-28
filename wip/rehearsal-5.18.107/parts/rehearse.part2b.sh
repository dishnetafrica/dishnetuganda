# ── 107-infra e — what the chain leaves behind, before 107 touches a stand-in (v3, review 2 FM8): 106-10f's leftover reading,
# run here as well as at 107-10, over every trigger the chain arms — none armed, no index lock, no weakened copy newer than
# the script it weakened. ──
LEFT107() {   # LEFT107 — the chain's and this release's triggers still armed, comma-joined, or none
  local f out=""
  for f in "$SB/.ujfail106" "$SB/web/.redirect106" "$SB/web/.redirect106lock" "$SB/evo/rh106-refuse.list" "$SB/.rbfail104" "$SB/.rbfail103" \
           "$SB/web/.redirect105" "$SB/web/.redirect104" "$SB/web/.left104m" \
           "$SB/web/.noboot107" "$SB/.plant092touch107" "$SB/.alter092touch107" "$SB/.boot107" "$SB/.rbfail107" "$SB/web/.redirect107" "$SB/web/.redirect107lock"; do
    [ -e "$f" ] && out="$out,${f#$SB/}"
  done
  [ -e "$REPO/.git/index.lock" ] && out="$out,index.lock"
  echo "${out#,}" | sed 's/^$/none/'
}
# 5.18.106's refusal list (made at 106:966 for 106-6's sender pass) is removed by 106's own rehearsal (106-6s2, 106:2013) and read
# absent by 106-7z; 106-10f's reading of it — removed, then a send for J1's first number answered with a key.id and nothing
# more refused — is cut with 106-10, so it is made here (one call to the stand-in Evolution, outside every run, as 106-10f's).
RLB107="$(if [ -e "$SB/evo/rh106-refuse.list" ]; then grep -c . "$SB/evo/rh106-refuse.list"; else echo absent; fi)"; RFB107="$(refused106)"
RLA107="$(refuse106)"; RQ107="$(evopost106 sendText "${J1A106:-}")"
check "$RLA107:$( [ -e "$SB/evo/rh106-refuse.list" ] && echo listed || echo unlisted):$RQ107:$(( $(refused106) - RFB107 ))" "0:unlisted:id:0" \
  "107-infra e0 $V106's refusal list (read $RLB107 here: 106-6s2 removes it, and 106-10f's reading is cut with 106-10) removed here as 106-10f does: a send for J1's first number answered with a key.id, nothing more refused"
WC0_107="$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY106" 2>/dev/null | grep -c .)"
cp "$DEPLOY106" "$REPO/scripts/.zz107ctl.sh"; WC1_107="$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY106" 2>/dev/null | grep -c .)"; rm -f "$REPO/scripts/.zz107ctl.sh"
check "$(LEFT107)|$WC0_107:$WC1_107:$(find "$REPO/scripts" -maxdepth 1 -name '.*.sh' -newer "$DEPLOY106" 2>/dev/null | grep -c .)" "none|0:1:0" \
  "107-infra e nothing the chain armed is left armed (106-10f's reading, run before 107 touches a stand-in): no trigger, no index lock, no weakened copy of 5.18.106's script left in the clone — its control a copy planted and counted (1), then removed (106-10f's .zz106ctl.sh)"

# ── 107-infra f — A1: the stand-in web server on the rehearsal's clock (v2 A1, v3). The chain starts it without LD_PRELOAD
# (root:285, :930), so a boot through it stamped 092's applied_at (SQLite's datetime('now')) and migration.log's lines with
# the kernel's time, forty days behind COPY_AT and QF_COPY_UNIX. One asserted edit of the stand-in public.php, before its
# boot (each probe answered without booting the live store — v3 FM9): a clock probe (rh107c_probe: PHP time() and SQLite's
# strftime('%s','now')), a boot-hooks probe (rh107b_probe), and H1 (.noboot107: a 503 with a fixed body, no boot, while the
# file exists — never spent). Then the server relaunched as uid 1000 under the clock, appending to web.log as root:930 does;
# idempotent, so a restored shell's old server is replaced too. ──
WL0_107="$(grep -c . "$SB/web.log" 2>/dev/null)"; WF0X_107="$(grep -cE 'Fatal error|Parse error' "$SB/web.log" 2>/dev/null)"
python3 -I - "$SB/web/public.php" <<'PY' || echo "standin:boot107" >> "$SB/anchor-missing"
import sys
p = sys.argv[1]
s = open(p).read()
head = "[$root, $dataDir, $clog, $web] = array_map('trim', array_slice(explode(\"\\n\", (string)@file_get_contents(__DIR__ . '/.installed_plugin')), 0, 4));\n"
boot = "foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . \"/lib/$l.php\";\n$store = SqliteStore::create($dataDir);\n"
assert s.count(head) == 1 and s.count(boot) == 1 and s.index(head) < s.index(boot), (s.count(head), s.count(boot))
assert s[s.index(head) + len(head):].startswith(boot), 'the boot does not follow the head'
assert s.count('rh107') == 0 and s.count('.noboot107') == 0, 'already edited'
new = ("if (($_GET['page'] ?? '') === 'rh107c_probe') { $q = (new PDO('sqlite::memory:'))->query(\"SELECT strftime('%s','now')\")->fetchColumn(); echo 'rh107c ' . time() . ' ' . $q; exit; }\n"
       "if (($_GET['page'] ?? '') === 'rh107b_probe') { echo 'rh107-boot-hooks-ready'; exit; }\n"
       "if (is_file($web . '/.noboot107')) { http_response_code(503); echo 'rh107: the container is not answering (no boot)'; exit; }\n")
i = s.index(boot)
s2 = s[:i] + new + s[i:]
assert len(s2) == len(s) + len(new) and s2.count(boot) == 1 and s2.index('rh107c_probe') < s2.index('rh107b_probe') < s2.index('.noboot107') < s2.index(boot)
open(p, 'w').write(s2)
PY
for i in $(seq 1 60); do grep -q 'rh107-boot-hooks-ready' <<<"$(pubget page=rh107b_probe)" && break; sleep 0.1; done   # PHP re-reads the edited file
cpr107() { local o; o="$(pubget page=rh107c_probe)"; case "$o" in "rh107c "*) echo "${o#rh107c }" ;; *) echo "unread unread" ;; esac; }   # cpr107 — the server's PHP time and SQLite time
C0_107="$(cpr107)"; W0_107="$(date -u +%s)"
kill "$WEB_PID" 2>/dev/null; wait "$WEB_PID" 2>/dev/null
pkill -f -- "-S 127.0.0.1:$WPORT -t $SB/web" 2>/dev/null   # a restored shell's WEB_PID may name a process it did not start: the server found by its own command line
for i in $(seq 1 50); do curl -s --noproxy '*' -o /dev/null "$PLUGIN_BASE?page=rh107b_probe" 2>/dev/null || break; sleep 0.1; done   # the old server gone
# opcache re-reads a changed file only once revalidate_freq (2 s) has passed on the CLOCK, and this rehearsal moves the clock:
# trial 1 (28 Nov) found the server serving public.php as compiled before H5's edit, minutes later (rh106_probe answered,
# rh107_probe two lines below it did not) — and a deploy's copy of lib/ would be read the same way. revalidate_freq=0: every
# request checks the file, whatever the clock does; H5's probe is its observable.
setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -d opcache.revalidate_freq=0 -S "127.0.0.1:$WPORT" -t "$SB/web" >>"$SB/web.log" 2>&1 & WEB_PID=$!; PIDS+=("$WEB_PID")
for i in $(seq 1 100); do grep -q 'rh107-boot-hooks-ready' <<<"$(pubget page=rh107b_probe)" && break; sleep 0.1; done
C1_107="$(cpr107)"; F1_107="$(fnow104)"
check "$(nr106 "${C0_107%% *}" "$W0_107")|$(nr106 "${C0_107##* }" "$W0_107")|$( [ $(( F1_107 - W0_107 )) -ge 3600 ] && echo ahead)|$(nr106 "${C1_107%% *}" "$F1_107")|$(nr106 "${C1_107##* }" "$F1_107")|$(pubget page=rh107b_probe)|$(standin_page customer_login)" \
      "near|near|ahead|near|near|rh107-boot-hooks-ready|200" \
  "107-infra f A1 the stand-in web server relaunched on the rehearsal's clock: before, its PHP time and SQLite time were the wall clock's (the control on the control); after, both within 2 s of the clock, $(( (F1_107 - W0_107) / 86400 )) day(s) ahead — so 092's applied_at and migration.log's stamps share COPY_AT's clock; the sign-in page still answers 200"
: > "$SB/web/.noboot107"; chmod 644 "$SB/web/.noboot107"
H1A_107="$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$PLUGIN_BASE?page=customer_login")|$(pubget page=customer_login | grep -c 'rh107: the container is not answering')|$(pubget page=rh107c_probe | grep -c '^rh107c ')"
rm -f "$SB/web/.noboot107"
check "$H1A_107|$(standin_page customer_login)" "503|1|1|200" \
  "107-infra f H1 .noboot107 armed: the sign-in page answers 503 with the fixed body and no boot, the clock probe still answers (it sits before H1); removed: 200 again"

# ── 107-infra g — W.8 in either shell (v2 A16). A record run's sandbox carries cc6a714's line from the root; a development
# runner's restored one (a snapshot older than 26 Nov) may not: the one line re-applied here, asserted (the fixed line once,
# or the old line once and replaced), then root:949–965's four controls, carried. ──
W8P107="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
W8S107="$(python3 -I - "$SB/bin/docker" "$W8P107" <<'PY'
import sys
p, path = sys.argv[1], sys.argv[2]
s = open(p).read()
new = '--clear-groups -- env PATH=%s); fi' % path
old = '--clear-groups --); fi'
if s.count(new) == 1 and s.count(old) == 0:
    print('carried')
elif s.count(old) == 1 and s.count(new) == 0:
    s2 = s.replace(old, new, 1)
    assert s2.count(new) == 1 and s2.count(old) == 0 and len(s2) == len(s) + len(new) - len(old)
    open(p, 'w').write(s2)
    print('applied')
else:
    print('unread:%d:%d' % (s.count(new), s.count(old)))
PY
)"
W8A107="$SB/u/w8-107-1"; W8B107="$SB/u/w8-107-2"; : > "$W8A107"; : > "$W8B107"; chown 1000:1000 "$W8A107" "$W8B107"
W8C107="$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm printenv PATH)|$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm sh -c 'command -v rm')|$(env PATH="$SB/bin:$PATH" bash "$SB/bin/docker" exec -u 1000:1000 ucrm rm "$W8A107" >/dev/null 2>&1; echo $?)|$([ -e "$W8A107" ] && echo kept || echo gone)"
W8O107="$SB/w8-docker-old-107"; sed "s#--clear-groups -- env PATH=$W8P107); fi#--clear-groups --); fi#" "$SB/bin/docker" > "$W8O107"
W8D107="$(grep -cF -- "env PATH=$W8P107" "$W8O107")|$(env PATH="$SB/bin:$PATH" bash "$W8O107" exec -u 1000:1000 ucrm printenv PATH)"
rm -f "$W8O107" "$W8A107" "$W8B107"
check "$( case "$W8S107" in carried|applied) echo ok ;; *) echo "$W8S107" ;; esac)|$W8C107|$W8D107" "ok|$W8P107|/usr/bin/rm|0|gone|0|$SB/bin:$PATH" \
  "107-infra g W.8 in this shell ($W8S107): -u runs with the container's PATH, rm is /usr/bin/rm, a file of the server's owner deleted as that user (exit 0); the control on the control, W.8 undone in a copy, runs -u with the caller's PATH"

# ── 107-infra h — the boots and the hooks (v2 A5, v3). boot107 is the one way this rehearsal boots the live store on purpose:
# the INSTALLED code's SqliteStore::create over the live data folder, as uid 1000 under the clock — the stand-in's own boot,
# from a CLI — its output to a file of its own (v3 FM7), never to a stream. ──
cat > "$H107/boot.php" <<'PHP'
<?php
// 5.18.107's rehearsal: one boot of a code root's store over a data folder, as the stand-in public.php boots it
// (SqliteStore::create runs the runner over migrations/*.sql); prints booted, or why not
[$root, $data] = array_slice($argv, 1, 2);
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . "/lib/$l.php";
SqliteStore::create($data);
echo "booted\n";
PHP
chmod 644 "$H107/boot.php"
BOOTN107=0
boot107() {   # boot107 LABEL — one boot of the installed store, under the clock; prints booted or the reason; its output kept in $R107/boot/
  local o; BOOTN107=$((BOOTN107 + 1))
  o="$(cd "$H107/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" -d log_errors=0 "$H107/boot.php" "$PD" "$DATA" 2>&1)"
  printf '%s\n' "$o" > "$R107/boot/boot107-$BOOTN107-$(tag106 "$1").out"
  case "$o" in booted) echo booted ;; *) echo "notbooted:$(head -c 80 <<<"$o" | tr -c 'A-Za-z0-9 :._-' '_')" ;; esac
}
# H2 — the plant before stamp_copy (v2 A6, v3): the date folder's touch, inside its RH106_TOUCH branch, one boot while
# $SB/.plant092touch107 exists (consumed), the clock 3 s on (the offset file's integer, forward only, its mode kept; never
# clk_to104 and never date inside the shim), counted; then it stamps as before.
cat > "$SB/touch107.hook" <<'HOOK'
  if [ -f "@SB@/.plant092touch107" ]; then
    rm -f "@SB@/.plant092touch107"
    ( cd "@H107@/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="@CLKPRE@" RH104_CLOCK_FILE="@CLKF@" "@PHP@" -d log_errors=0 "@H107@/boot.php" "@PD@" "@DATA@" ) >> "@R107@/boot/h2.out" 2>&1
    _rh_o7="$(head -n1 "@CLKF@" 2>/dev/null)"
    case "$_rh_o7" in ''|*[!0-9-]*) echo unread >> "@SB@/plant092touch107.refused" ;; *) printf '%s\n' "$((_rh_o7 + 3))" > "@CLKF@.h2" && cat "@CLKF@.h2" > "@CLKF@" && rm -f "@CLKF@.h2" ;; esac
    echo fired >> "@SB@/plant092touch107.fired"
  fi
  if [ -f "@SB@/.alter092touch107" ]; then
    rm -f "@SB@/.alter092touch107"
    if [ -f "@PD@/migrations/092_wa_no_ai.sql" ]; then printf '%s\n' '-- RH107SECRET- a byte the pin does not have (H2, at stamp_copy)' >> "@PD@/migrations/092_wa_no_ai.sql"; echo fired >> "@SB@/alter092touch107.fired"
    else echo nofile >> "@SB@/alter092touch107.fired"; fi
  fi
HOOK
python3 -I - "$SB/datebin/touch" "$SB/touch107.hook" "$SB" "$H107" "$CLKPRE104" "$CLKF104" "$PHPBIN" "$PD" "$DATA" "$R107" <<'PY' || echo "datebin:touch107" >> "$SB/anchor-missing"
import sys
p, hook, sb, h107, clkpre, clkf, php, pd, data, r107 = sys.argv[1:11]
for v in (sb, h107, clkpre, clkf, php, pd, data, r107):
    assert v.startswith('/') and '"' not in v and '@' not in v, v
s = open(p).read()
h = (open(hook).read().replace('@SB@', sb).replace('@H107@', h107).replace('@CLKPRE@', clkpre).replace('@CLKF@', clkf)
     .replace('@PHP@', php).replace('@PD@', pd).replace('@DATA@', data).replace('@R107@', r107))
assert '@' not in h, 'a placeholder left'
anchor = 'if [ -n "${RH106_TOUCH:-}" ] && [ "${1:-}" = -c ] && [ "$#" -ge 2 ]; then\n'
assert s.count(anchor) == 1 and s.count('.plant092touch107') == 0 and s.count('.alter092touch107') == 0, (s.count(anchor), s.count('.plant092touch107'))
i = s.index(anchor) + len(anchor)
s2 = s[:i] + h + s[i:]
assert len(s2) == len(s) + len(h) and s2.count('.plant092touch107') == 2 and s2.count('.alter092touch107') == 2 and s2.index(anchor) < s2.index('.plant092touch107') < s2.index('.alter092touch107') < s2.index('for a in')
open(p, 'w').write(s2)
PY
rm -f "$SB/touch107.hook"; : > "$SB/plant092touch107.fired"; : > "$SB/plant092touch107.refused"; : > "$SB/alter092touch107.fired"
TCF107="$H107/touch.ctl"; : > "$TCF107"
O0_107="$(head -n1 "$CLKF104")"
env PATH="$SB/datebin:$PATH" RH106_TOUCH=1 LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" touch -c "$TCF107"; H2A_107="$(grep -c . "$SB/plant092touch107.fired")|$(head -n1 "$CLKF104")"
: > "$SB/.plant092touch107"
env PATH="$SB/datebin:$PATH" RH106_TOUCH=1 LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" touch -c "$TCF107"; H2B_107="$(grep -c . "$SB/plant092touch107.fired")|$(head -n1 "$CLKF104")|$( [ -e "$SB/.plant092touch107" ] && echo unspent || echo spent)|$(nr106 "$(stat -c %Y "$TCF107")" "$(fnow104)")"
check "$(bash -n "$SB/datebin/touch" && echo parses)|$H2A_107|$H2B_107|$(grep -c . "$SB/plant092touch107.refused")|$(grep -c '^booted$' "$R107/boot/h2.out" 2>/dev/null)|$(stat -c %a "$CLKF104")" \
      "parses|0|$O0_107|1|$(( O0_107 + 3 ))|spent|near|0|1|644" \
  "107-infra h H2 the date folder's touch: unarmed it stamps as before (no boot, the clock unmoved); armed, it boots the installed store once (as uid 1000, under the clock), moves the clock 3 s on (the offset file, mode kept), consumes the trigger, then stamps at the clock's time"
: > "$SB/plant092touch107.fired"; : > "$R107/boot/h2.out"
# H2's second trigger (v3 §A, §T.11:3487 — D8): .alter092touch107 appends one comment line to the INSTALLED 092 at the same touch,
# so V1's boot applies other bytes; only where the file is on disk. Its control here, 5.18.106 serving: nothing created, spent.
: > "$SB/.alter092touch107"
env PATH="$SB/datebin:$PATH" RH106_TOUCH=1 LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" touch -c "$TCF107"
check "$(cat "$SB/alter092touch107.fired")|$( [ -e "$PD/migrations/092_wa_no_ai.sql" ] && echo made || echo none)|$( [ -e "$SB/.alter092touch107" ] && echo unspent || echo spent)|$(grep -c . "$SB/plant092touch107.fired")" "nofile|none|spent|0" \
  "107-infra h H2's second trigger (.alter092touch107) with no 092 on disk ($V106 serving): it creates no file, records nofile, is spent, and boots nothing"
: > "$SB/alter092touch107.fired"; rm -f "$TCF107"
# H4 — .boot107 (v2 A8, v3 review 2 TC2/FM1): one boot at deploy-hybrid's read of .deployed-commit while it names 5.18.106 — the
# exact call, and only when the docker's parent command line names deploy-hybrid.sh (stage A's LIVE_BEFORE makes the same call
# from the script itself). Armed only by csb1run107's action at Type DEPLOY; consumed; counted; its output to a file.
cat > "$SB/docker107.hook" <<'HOOK'
# ── 5.18.107's rehearsal: H4 (boot107, one boot at deploy-hybrid's record read) and H6 (rbfail107, a rollback's record read wrong once) ──
if [ "$*" = "exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit" ]; then
  _rh_r7="$(tail -n1 "@PD@/.deployed-commit" 2>/dev/null | tr -cd '0-9a-f')"
  if [ -f "@SB@/.boot107" ] && [ "$_rh_r7" = "@P106@" ]; then
    case "$(ps -o args= -p "$PPID" 2>/dev/null)" in
      *deploy-hybrid.sh*) rm -f "@SB@/.boot107"
        ( cd "@H107@/v" && setpriv --reuid 1000 --regid 1000 --clear-groups -- env LD_PRELOAD="@CLKPRE@" RH104_CLOCK_FILE="@CLKF@" "@PHP@" -d log_errors=0 "@H107@/boot.php" "@PD@" "@DATA@" ) >> "@R107@/boot/h4.out" 2>&1
        echo fired >> "@SB@/boot107.fired" ;;
      *) echo "notdh" >> "@SB@/boot107.passed" ;;
    esac
  fi
  if [ -f "@SB@/.rbfail107" ]; then
    if [ "$_rh_r7" = "@P107@" ]; then : > "@SB@/.rbfail107.seen"
    elif [ "$_rh_r7" = "@P106@" ] && [ -f "@SB@/.rbfail107.seen" ]; then rm -f "@SB@/.rbfail107" "@SB@/.rbfail107.seen"; echo fired >> "@SB@/rbfail107.fired"; echo 0000000; exit 0; fi
  fi
fi
HOOK
python3 -I - "$SB/bin/docker" "$SB/docker107.hook" "$SB" "$PD" "$P106" "$P107" "$H107" "$CLKPRE104" "$CLKF104" "$PHPBIN" "$DATA" "$R107" <<'PY' || echo "fakedocker:hook107" >> "$SB/anchor-missing"
import sys
p, hook, sb, pd, p106, p107, h107, clkpre, clkf, php, data, r107 = sys.argv[1:13]
assert p106 and p107 and p106 != p107 and all(c in '0123456789abcdef' for c in p106 + p107), (p106, p107)
s = open(p).read()
h = (open(hook).read().replace('@SB@', sb).replace('@PD@', pd).replace('@P106@', p106).replace('@P107@', p107).replace('@H107@', h107)
     .replace('@CLKPRE@', clkpre).replace('@CLKF@', clkf).replace('@PHP@', php).replace('@DATA@', data).replace('@R107@', r107))
assert '@' not in h, 'a placeholder left'
lines = s.split('\n')
at = [i for i, l in enumerate(lines) if l.startswith('row() { grep "^$1|" ')]
assert len(at) == 1, len(at)
assert s.count('.boot107') == 0 and s.count('.rbfail107') == 0 and s.count('.ujfail106') == 2 and s.count('.rbfail104') == 5, (s.count('.ujfail106'), s.count('.rbfail104'))
lines[at[0]:at[0]] = h.rstrip('\n').split('\n')
s2 = '\n'.join(lines)
assert len(s2) == len(s) + len(h.rstrip('\n')) + 1
assert s2.count('.boot107') == 2 and s2.count('.rbfail107') == 5 and s2.count('row() { grep "^$1|" ') == 1
assert s2.rindex('.ujfail106') < s2.index('.boot107') < s2.index('row() { grep "^$1|" '), 'order'
open(p, 'w').write(s2)
PY
rm -f "$SB/docker107.hook"; : > "$SB/boot107.fired"; : > "$SB/boot107.passed"; : > "$SB/rbfail107.fired"
DC107="/data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit"
cp -p "$PD/.deployed-commit" "$SB/dc107.kept"
: > "$SB/.boot107"
H4A_107="$(dk104 exec ucrm cat "$DC107" | tail -n1)|$(grep -c . "$SB/boot107.fired")|$(grep -c . "$SB/boot107.passed")|$( [ -e "$SB/.boot107" ] && echo unspent || echo spent)"   # the rehearsal's own call: not deploy-hybrid
H4B_107="$(bash -c 'exec -a "bash scripts/deploy-hybrid.sh" bash -c "env PATH=\"$1/bin:\$PATH\" bash \"$1/bin/docker\" exec ucrm cat \"$2\" | tail -n1"' _ "$SB" "$DC107")|$(grep -c . "$SB/boot107.fired")|$( [ -e "$SB/.boot107" ] && echo unspent || echo spent)|$(grep -c '^booted$' "$R107/boot/h4.out" 2>/dev/null)"
rm -f "$SB/.boot107"
: > "$SB/.rbfail107"; printf '%s\n' "$P107" > "$PD/.deployed-commit"
H6A_107="$(dk104 exec ucrm cat "$DC107" | tail -n1):$( [ -f "$SB/.rbfail107.seen" ] && echo seen)"
printf '%s\n' "$P106" > "$PD/.deployed-commit"; H6B_107="$(dk104 exec ucrm cat "$DC107" | tail -n1)"; H6C_107="$(dk104 exec ucrm cat "$DC107" | tail -n1)"
cp -p "$SB/dc107.kept" "$PD/.deployed-commit"; rm -f "$SB/dc107.kept" "$SB/.rbfail107" "$SB/.rbfail107.seen"
check "$(bash -n "$SB/bin/docker" && echo parses)|$H4A_107|$H4B_107|$H6A_107|$H6B_107|$H6C_107|$(grep -c . "$SB/rbfail107.fired")|$(live)" \
      "parses|$P106|0|1|unspent|$P106|1|spent|1|$P107:seen|0000000|$P106|1|$P106" \
  "107-infra h H4 and H6 in the stand-in docker: .boot107 armed, the record read from anywhere but deploy-hybrid passes through (counted as passed, still armed); from deploy-hybrid it boots once and is spent; .rbfail107 armed, a read naming $P107 then one naming $P106 answers 0000000 once, then the record again; the record put back"
: > "$SB/boot107.fired"; : > "$SB/boot107.passed"; : > "$SB/rbfail107.fired"; : > "$R107/boot/h4.out"
# H5 — .redirect107 and .redirect107lock (v2 A5, v3): R6's A7 shape for this release — a probe page, and a 302 on the sign-in
# page only while the installed manifest says 5.18.107 and the trigger can be spent; with the lock beside it, the same spend
# creates the clone's .git/index.lock. Inserted after .redirect106's line, before `$page = …`.
arm107() { printf 'x\n' > "$SB/web/.redirect107"; chmod 644 "$SB/web/.redirect107"; [ "${1:-}" != lock ] || { printf 'x\n' > "$SB/web/.redirect107lock"; chmod 644 "$SB/web/.redirect107lock"; }; }   # arm107 [lock]
PB0_107="$(pubget page=rh107_probe | grep -c 'rh107-triggers-ready')"
python3 -I - "$SB/web/public.php" "$V107" "$REPO" <<'PY' || echo "standin:triggers107" >> "$SB/anchor-missing"
import sys
p, v107, repo = sys.argv[1:4]
assert v107 and repo.startswith('/') and "'" not in repo and '\\' not in repo, (v107, repo)
s = open(p).read()
old = "$page = $_GET['page'] ?? '';\n"
assert s.count(old) == 1 and s.count('redirect107') == 0 and s.count("'.redirect106'") == 1 and s.count('rh106_probe') == 1, (s.count(old), s.count('redirect107'))
new = ("if (($_GET['page'] ?? '') === 'rh107_probe') { echo 'rh107-triggers-ready'; exit; }\n"
       "if (($_GET['page'] ?? '') === 'customer_login' && !isset($_GET['rh107v1']) && strpos((string)@file_get_contents($root . '/manifest.json'), '\"" + v107 + "\"') !== false && $spend('.redirect107')) { if ($spend('.redirect107lock')) { @touch('" + repo + "/.git/index.lock'); } header('Location: ?page=customer_login&rh107v1=1', true, 302); exit; }\n")
s2 = s.replace(old, new + old, 1)
assert s2.count("'.redirect107'") == 1 and s2.count("'.redirect107lock'") == 1 and s2.count('rh107_probe') == 1 and s2.count(old) == 1 and len(s2) == len(s) + len(new)
assert s2.count(repo + "/.git/index.lock") == 2 and s2.index("'.redirect106'") < s2.index("'.redirect107'") < s2.index(old), 'order'
open(p, 'w').write(s2)
PY
for i in $(seq 1 60); do grep -q 'rh107-triggers-ready' <<<"$(pubget page=rh107_probe)" && break; sleep 0.1; done
LK0_107="$(lock106)"; arm107 lock
TR107="$(v1read102)|$( [ -e "$SB/web/.redirect107" ] && echo unspent || echo spent)|$( [ -e "$SB/web/.redirect107lock" ] && echo unspent || echo spent)|$(lock106)"
rm -f "$SB/web/.redirect107" "$SB/web/.redirect107lock"
check "$PB0_107|$(pubget page=rh107_probe)|$( [ "$(inst_ver)" != "$V107" ] && echo not107)|$LK0_107|$TR107" "0|rh107-triggers-ready|not107|absent|200:0|unspent|unspent|absent" \
  "107-infra h H5 the stand-in carries .redirect107 and .redirect107lock (its probe answers, as it did not before the edit): armed both while $(inst_ver)'s manifest is installed, the sign-in page does not redirect (200:0), neither is spent, no index lock — they fire only once $V107 is installed"
