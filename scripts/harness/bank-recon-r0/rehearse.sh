#!/usr/bin/env bash
# rehearse.sh — R0 of docs/69 §2.3.8 (scripts/bank-recon-r0.sh and scripts/bank-recon-r0.php), against synthetic books
# written by the plugin's own code, in a sandbox: P0's rehearsal (scripts/harness/bank-recon-p0/rehearse.sh), copied for
# R0, with R0's own cases. It proves, by running the real files:
#   T1  on eight books (ug, ug-novoid, ug-mixed, kyc-first, bare, proper, ss, i2), run by the reviewed tool AND by its
#       candidate copy (the adjustment hash replaced by a fictitious figure's): exit 0; the report equals an independent
#       count (expect.php: naive loops, json_decode, no json_extract, no GROUP BY) line for line, and the planted truth;
#       nothing it can reach changes; no planted name, reference, number or PHP diagnostic word and no planted amount is
#       printed; the twin rules equal a third method (one SQL self-join each, refjoin.php), whose 7-day control holds;
#   T2  while the plugin holds the book open, R0 waits, reads after the close, counts the row that was only in the -wal;
#       held too long, it refuses and changes nothing;
#   T3  each refusal; T3b the bare book (absent, never 0); T3c the proper store and the invalid JSON; T3d no JSON
#       functions, and a probe error that is re-thrown; T3e the NULL-column and odd-shape book;
#   T4  a reading SQLite cannot finish ("run again"); R0's own internal error (class and line, "do not run it again"),
#       for a TypeError and for an undefined text key, the key never printed; 12 readings of the 30,000-row book against a
#       busy writer; T4f its time and memory; a writer between the readings caught four ways (close, keep, other, text);
#   T5  the static rules (srccheck.php), each with a planted control; the adjustment hash is docs/07's figure, and no
#       planted amount is it;
#   T6  the command through R0's strict fake docker, P0's cases with R0's texts, the 11th-argument refusal, and the
#       checkout's .git/index left as it was;
#   T7/T8  weakened copies of the tool and of the command, each caught by at least one assertion.
# Everything is fictitious. It never touches this checkout's plugin, the server or the network, refuses to run where the
# server could be, and never runs beside another rehearsal (a lock, and a check for P0's, which takes none).
#
#   bash scripts/harness/bank-recon-r0/rehearse.sh            KEEP=1 keeps the sandbox; MUTANTS=0 skips T7/T8
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
[ "$(id -u)" = 0 ] || { echo "run as root (the sandbox's owner is uid 1000, as the server's database owner is)"; exit 2; }
for c in php setpriv sha256sum find perl flock git; do command -v "$c" >/dev/null || { echo "missing $c"; exit 2; }; done
exec 9>/tmp/bank-recon-rehearsal.lock
flock -n 9 || { echo "refusing: another rehearsal holds /tmp/bank-recon-rehearsal.lock — never two at once"; exit 2; }
for p in /proc/[0-9]*/cmdline; do
  tr '\0' ' ' 2>/dev/null < "$p" | grep -q 'bank-recon-p0/rehearse' && { echo "refusing: a P0 rehearsal is running (${p%/cmdline})"; exit 2; }
done
for d in /tmp/bank-recon-p0.* /tmp/bank-recon-run.*; do
  [ -e "$d" ] && { echo "refusing: $d exists — a P0 rehearsal is running or was kept; never two at once"; exit 2; }
done
H="$(cd "$(dirname "$0")" && pwd)"; R="$(cd "$H/../../.." && pwd)"
TOOL0="$R/scripts/bank-recon-r0.php"; WRAP0="$R/scripts/bank-recon-r0.sh"
SB="$(mktemp -d /tmp/bank-recon-r0.XXXXXX)"; chmod 755 "$SB"
holders=()
cleanup() { for p in "${holders[@]}"; do kill "$p" 2>/dev/null; done; wait 2>/dev/null; [ -n "${KEEP:-}" ] && echo "kept: $SB" || rm -rf "$SB"; }
trap cleanup EXIT
pass=0; fails=0
ok()  { pass=$((pass+1)); echo "  ok   $*"; }
bad() { fails=$((fails+1)); echo "  FAIL $*"; }
chk() { local m="$1"; shift; if "$@"; then ok "$m"; else bad "$m"; fi; }
O=1000:1000
own() { setpriv --reuid=1000 --regid=1000 --clear-groups "$@"; }
# R0's own SECRETS (the specification's §5.0): the planted strings, and PHP's diagnostic words; never P0's ordinary words
PLANTED='RH-MARK|RH-Client-|0772 123 456|0772123456|7000000001|BANK-REF001|RH-MID-0001|RH-Grown'
SECRETS="$PLANTED|Warning|Notice|Deprecated|Fatal|Uncaught|Stack trace|Standard input code"
noleak()  { ! grep -Eq "$SECRETS" "$1"; }
noplant() { ! grep -Eq "$PLANTED" "$1"; }
has()     { grep -Fq -- "$2" "$1"; }
noamount() { [ ! -s "$2" ] || ! grep -Fwq -f "$2" "$1"; }   # noamount <output> <amounts file>
FICT=734000037                                              # the fictitious adjustment, 7340000.37, in cents
FICT_SHA="$(php -r 'echo hash("sha256", "dn-r0-adj|" . $argv[1]);' "$FICT")"

# ── The sandbox plugin (a copy of lib/, migrations/, profiles/ and the records tool; never the checkout's) ──────────
mkdir -p "$SB/plugin/data" "$SB/plugin/tools" "$SB/books"
cp -a "$R/dishnet-hybrid-sudan/lib" "$R/dishnet-hybrid-sudan/migrations" "$R/dishnet-hybrid-sudan/profiles" "$SB/plugin/"
cp "$R/dishnet-hybrid-sudan/tools/staff_records_currency.php" "$SB/plugin/tools/"
chown -R "$O" "$SB/plugin" "$SB/books"
build() {   # build <variant> — a master book, its expected reports (reviewed tool, candidate copy) and its amounts
  local d="$SB/books/$1"; mkdir -p "$d/live"; chown -R "$O" "$d"
  own env DN_VAULT_FILE="$d/vault.json" php "$H/build-book.php" "$SB/plugin" "$d/live" "$1" > "$d/ids.json" 2> "$d/build.err" \
    || { echo "build $1 failed:"; cat "$d/build.err"; exit 3; }
  rm -f "$d/live/plugin.sqlite3-wal" "$d/live/plugin.sqlite3-shm"
  [ "$1" = big ] && return
  own php "$H/expect.php" "$d/live" "$1" none > "$d/exp.tool" 2> "$d/exp.err" || { echo "expect $1 failed:"; cat "$d/exp.err"; exit 3; }
  own php "$H/expect.php" "$d/live" "$1" "$FICT" > "$d/exp.cand" 2>> "$d/exp.err" || { echo "expect $1 (cand) failed:"; cat "$d/exp.err"; exit 3; }
  own php "$H/amounts.php" "$d/live" > "$d/amounts" || exit 3
}
VARIANTS="ug ug-novoid ug-mixed kyc-first bare proper ss i2"
for v in $VARIANTS; do build "$v"; done
n=0
mkdir -p "$SB/run"
fresh() {   # fresh <book> — a new working copy of a master book, owned as the master is: echoes its folder
  local d; d="$(mktemp -d "$SB/run/$1.XXXXXX")" || exit 3
  cp -a "$SB/books/$1/." "$d/" && chown "$O" "$d" && chmod 755 "$d" || exit 3
  echo "$d"
}
snap() {    # every file R0 could reach, with its content and identity
  ( cd "$1" && find live "$SB/plugin/data" -printf '%p|%y|%i|%s|%T@|%C@|%u:%g|%m\n' 2>/dev/null | sort
    find live -type f -exec sha256sum {} + 2>/dev/null | sort )
}
r0() {      # r0 <work dir> <tool> [args…] — R0 as the owner, the tool on standard input; rc in $RC, output in <dir>/out
  local d="$1" t="$2"; shift 2
  own env -i PATH=/usr/bin:/bin php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- "$d/live" "$SB/plugin" "$@" < "$t" > "$d/out" 2>&1
  RC=$?
}
candcopy() {  # candcopy <tool>: the same file with the adjustment hash replaced by the fictitious figure's (one line differs)
  local c="$SB/cand.$(basename "$1")"
  sed "s/^const ADJ_CENTS_SHA = '[0-9a-f]\{64\}';/const ADJ_CENTS_SHA = '$FICT_SHA';/" "$1" > "$c"
  [ "$(diff "$1" "$c" | grep -c '^[<>]')" = 2 ] || { echo "candcopy: the anchor did not apply exactly once" >&2; exit 3; }
  echo "$c"
}

# ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
t1() {      # t1 <tool> — eight books, the tool and its candidate copy: figures, truth, nothing changed, nothing leaked
  local T="$1" TC b d m t
  TC="$(candcopy "$T")"
  for b in $VARIANTS; do
    for m in tool cand; do
      t="$T"; [ "$m" = cand ] && t="$TC"
      d="$(fresh "$b")"; snap "$d" > "$d/snap.before"
      r0 "$d" "$t"
      snap "$d" > "$d/snap.after"
      chk "T1 $b ($m): R0 exits 0" [ "$RC" = 0 ]
      if php "$H/check.php" "$d/out" "$d/exp.$m" "$d/ids.json" "$m" "$b" > "$d/check"; then ok "T1 $b ($m): the report equals the independent count and the planted truth ($(grep -c '^ok' "$d/check") checks)"
      else bad "T1 $b ($m): $(grep FAIL "$d/check" | head -3 | tr '\n' ';' | cut -c1-400)"; fi
      chk "T1 $b ($m): every reachable file is unchanged and none appeared" cmp -s "$d/snap.before" "$d/snap.after"
      chk "T1 $b ($m): no planted name, reference, number or PHP diagnostic word is printed" noleak "$d/out"
      chk "T1 $b ($m): no planted amount is printed, in any form" noamount "$d/out" "$d/amounts"
    done
  done
  for b in ug kyc-first proper; do
    d="$(fresh "$b")"; r0 "$d" "$T"
    if php "$H/refjoin.php" "$d/live" 5015 5016 "$d/out" > "$d/ref"; then ok "T1 $b: the twin rules equal one SQL self-join each, and the 7-day control holds ($(grep -c '^ok' "$d/ref") checks)"
    else bad "T1 $b: the SQL self-joins differ: $(grep FAIL "$d/ref" | head -3 | tr '\n' ';')"; fi
  done
  php -r '$o = ""; foreach (token_get_all(file_get_contents($argv[1])) as $t) if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) $o .= $t[1] . "\n"; echo $o;' "$T" > "$SB/literals"
  chk "T1 control: no fixed label or literal of the tool matches SECRETS, so a match in a report is always a leak" noleak "$SB/literals"
}

# ── T2: the plugin holds the book ──────────────────────────────────────────────────────────────────────────────────
hold() {    # hold <dir> <seconds> — as the owner: open, add one committed row that stays in the -wal, keep the book open
  rm -f "$1/held"
  own php "$H/hold.php" "$1/live/plugin.sqlite3" "$1/held" "$2" &
  holders+=($!); HOLDER=$!
  local i=0; while [ ! -f "$1/held" ] && [ $i -lt 100 ]; do sleep 0.05; i=$((i+1)); done
}
waitcopy() {  # a copy of the tool that waits $1 s instead of 120 (anchor asserted)
  local c="$SB/wait$1.$(basename "$2")"
  sed "s/^const WAIT_SECONDS = 120; /const WAIT_SECONDS = $1; /" "$2" > "$c"
  [ "$(grep -c "^const WAIT_SECONDS = $1; " "$c")" = 1 ] || { echo "waitcopy: anchor not found" >&2; exit 3; }
  echo "$c"
}
t2() {
  local T="$1" d d2
  d="$(fresh ug)"; hold "$d" 3
  chk "T2 the holder has the book open: -wal and -shm present, the row only in the -wal" bash -c "[ -f '$d/live/plugin.sqlite3-wal' ] && [ -s '$d/live/plugin.sqlite3-wal' ] && [ -f '$d/live/plugin.sqlite3-shm' ]"
  r0 "$d" "$T"; wait "$HOLDER" 2>/dev/null
  own php "$H/expect.php" "$d/live" ug none > "$d/exp2" 2>/dev/null
  chk "T2 R0 waited for the plugin to close the book, then read it (exit 0)" bash -c "[ $RC = 0 ] && grep -q 'after waiting [0-9]* s for the plugin to close the book' '$d/out'"
  chk "T2 the row that was only in the -wal is counted: the report equals the count after the close" php "$H/check.php" "$d/out" "$d/exp2" "$d/ids.json" tool ug
  chk "T2 one more row than the master book" bash -c "[ \$(sed -n 's/^  tables          cb_ledger \([0-9]*\) .*/\1/p' '$d/out') = \$((\$(sed -n 's/^  tables          cb_ledger \([0-9]*\) .*/\1/p' '$d/exp.tool') + 1)) ]"
  chk "T2 no side file is left and every file of the book belongs to the owner" bash -c "[ ! -e '$d/live/plugin.sqlite3-wal' ] && [ ! -e '$d/live/plugin.sqlite3-shm' ] && [ -z \"\$(find '$d/live' ! -user 1000)\" ]"
  d2="$(fresh ug)"; hold "$d2" 6; snap "$d2" > "$d2/snap.held"
  r0 "$d2" "$(waitcopy 2 "$T")"
  snap "$d2" > "$d2/snap.after"; wait "$HOLDER" 2>/dev/null
  chk "T2 held longer than the wait: R0 refuses (2) and names the reason" bash -c "[ $RC = 2 ] && grep -q 'the plugin kept the book open for 2 s' '$d2/out'"
  chk "T2 … and while it waited, it changed nothing (the holder's own -wal and -shm aside, identical)" cmp -s "$d2/snap.held" "$d2/snap.after"
}

# ── T3: refusals; the bare, proper, no-JSON and odd-shape books ─────────────────────────────────────────────────────
t3() {
  local T="$1" d opt s c
  d="$(fresh ug)"; mkdir -p "$d/plg/data"; cp -a "$d/live/plugin.sqlite3" "$d/plg/data/"; chown -R "$O" "$d/plg"
  own php -d display_errors=stderr -- "$d/plg/data" "$d/plg" < "$T" > "$d/out" 2>&1; RC=$?
  chk "T3a <plugin>/data is refused (2) before anything is opened" bash -c "[ $RC = 2 ] && grep -q \"stray store's old place\" '$d/out'"
  d="$(fresh ug)"; mkdir -p "$d/empty"; chown "$O" "$d/empty"
  own php -d display_errors=stderr -- "$d/empty" "$SB/plugin" < "$T" > "$d/out" 2>&1; RC=$?
  chk "T3a no plugin.sqlite3: refused (2), and no database is created" bash -c "[ $RC = 2 ] && grep -q 'no plugin.sqlite3' '$d/out' && [ -z \"\$(ls -A '$d/empty')\" ]"
  for opt in --adjustment-id=1 --commit --write -x; do
    d="$(fresh ug)"; snap "$d" > "$d/s0"; r0 "$d" "$T" "$opt"; snap "$d" > "$d/s1"
    chk "T3a option $opt is refused (2), nothing changed" bash -c "[ $RC = 2 ] && grep -q 'unknown option' '$d/out' && cmp -s '$d/s0' '$d/s1'"
  done
  d="$(fresh ug)"; own php -d display_errors=stderr -- "$d/live" < "$T" > "$d/out" 2>&1; RC=$?
  chk "T3a one folder only: usage (2)" bash -c "[ $RC = 2 ] && grep -q 'usage:' '$d/out'"
  for s in -wal -shm -journal; do
    d="$(fresh ug)"; : > "$d/live/plugin.sqlite3$s"; chown "$O" "$d/live/plugin.sqlite3$s"; snap "$d" > "$d/s0"
    r0 "$d" "$(waitcopy 1 "$T")"; snap "$d" > "$d/s1"
    chk "T3a a $s left beside the book with nobody holding it: R0 waits, refuses (2), changes nothing" bash -c "[ $RC = 2 ] && grep -q 'kept the book open' '$d/out' && cmp -s '$d/s0' '$d/s1'"
  done
  d="$(fresh ug)"; snap "$d" > "$d/s0"
  own php -d display_errors=stderr -d open_basedir="$d:$SB" -- "$d/live" "$SB/plugin" < "$T" > "$d/out" 2>&1; RC=$?; snap "$d" > "$d/s1"
  chk "T3a a PHP that will not open an SQLite URI (open_basedir): refused (2), no file made" bash -c "[ $RC = 2 ] && grep -q 'could not be opened read-only and immutable' '$d/out' && cmp -s '$d/s0' '$d/s1'"
  d="$(fresh ug)"; rm -f "$d/live/plugin.sqlite3"; own php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("CREATE TABLE other(x)");' "$d/live/plugin.sqlite3"
  r0 "$d" "$T"; chk "T3a a database without cb_ledger: refused (2)" bash -c "[ $RC = 2 ] && grep -q 'no cb_ledger table' '$d/out'"
  d="$(fresh ug)"; rm -f "$d/live/plugin.sqlite3"   # a cb_ledger with every column R0 reads but crm_client_id
  own php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("CREATE TABLE cb_ledger (id INTEGER PRIMARY KEY, sr, date, direction, amount, ssp_amount, currency, status, description, validation_ref, source, category, txn_type, person, crm_payment_id, account_id, created_at)");' "$d/live/plugin.sqlite3"
  r0 "$d" "$T"; chk "T3a cb_ledger without crm_client_id: refused (2), naming the column" bash -c "[ $RC = 2 ] && grep -q 'cb_ledger has no crm_client_id column' '$d/out'"
  d="$(fresh ug)"; mkdir -p "$d/a?b"; cp -a "$d/live/plugin.sqlite3" "$d/a?b/"; chown -R "$O" "$d/a?b"
  own php -d display_errors=stderr -- "$d/a?b" "$SB/plugin" < "$T" > "$d/out" 2>&1; RC=$?
  chk "T3a a path SQLite cannot carry in a URI: refused (2)" bash -c "[ $RC = 2 ] && grep -q 'cannot carry' '$d/out'"
  # T3b — the bare book: every section that needs a dropped table says absent, never 0
  d="$(fresh bare)"; r0 "$d" "$T"
  chk "T3b bare: exit 0" [ "$RC" = 0 ]
  for s in '  tables          cb_ledger [0-9]+ · cb_accounts absent · fin_audit absent · staff_ledger absent · equipment_assignments absent$' \
           'cash_ins absent · cash_expenses absent · crm_payment_retry absent$' 'single\+finaudit absent \(fin_audit is not in this book\)' \
           'from fin_audit absent \(fin_audit is not in this book\)' '\(not read: cash_expenses absent \(cash_expenses is not in this book\)\)' \
           '^  evidence        absent \(fin_audit is not in this book\)$' '^                  post-void   absent' 'void undone fin_audit void records whose row is now live absent' \
           '^  fin_audit       absent' '^  deleted         absent' 'through a retry item absent \(crm_payment_retry is not in this book\)' \
           '^                  \(f\) two uCRM payments for one collection: absent' 'original deleted \(fin_audit\) absent' \
           'deleted rows of that date \(fin_audit\) absent' 'fin_audit: absent \(fin_audit is not in this book\)' '^                  deleted, edited away: absent' \
           '^                  cash_ins voided by the tool absent \(cash_ins is not in this book\); staff_ledger rows voided by the tool absent' \
           '^                  absent \(cash_ins is not in this book\)$' '^  equipment       absent' '\(no accounts table\)'; do
    chk "T3b bare: «$(printf '%s' "$s" | cut -c1-70)» — absent, not 0" grep -Eq -- "$s" "$d/out"
  done
  chk "T3b bare: no figure of a dropped table reads 0 (no 'fin_audit 0', 'equipment … 0', 'cash_ins … 0')" bash -c "! grep -Eq 'fin_audit 0|staff_ledger 0|equipment_assignments 0|cb_accounts 0|live assignments' '$d/out'"
  # T3c — the proper store, the truncated rows, the invalid and the '{}' snapshots
  d="$(fresh proper)"; r0 "$d" "$T"
  chk "T3c proper: exit 0" [ "$RC" = 0 ]
  chk "T3c proper: relabelled unknown (column audit_log missing) on all three cash-in lines, never no" bash -c "[ \$(grep -c 'relabelled unknown (column audit_log missing)' '$d/out') = 3 ]"
  chk "T3c proper: not valid JSON 1 for cash_expenses and for crm_payment_retry; cash_ins read through its columns" grep -Eq 'cash_ins proper 6 · cash_expenses json 2 \(not valid JSON 1\) · crm_payment_retry json [0-9]+ \(not valid JSON 1\)' "$d/out"
  chk "T3c proper: the invalid before_json and the '{}' snapshot are both unreadable" grep -q 'before_json unreadable 2$' "$d/out"
  # T3d — no JSON functions (an instrumented probe), and a probe error that is not "no such function"
  c="$SB/nojson.$(basename "$T")"; sed "s/SELECT json_valid('{}') AS j/SELECT json_validx('{}') AS j/" "$T" > "$c"
  [ "$(grep -c "json_validx('{}')" "$c")" = 1 ] || bad "T3d: the probe could not be instrumented"
  d="$(fresh ug)"; r0 "$d" "$c"
  chk "T3d no JSON functions: exit 0, 'JSON functions no', every JSON section absent (no JSON functions)" \
    bash -c "[ $RC = 0 ] && grep -q 'JSON functions no$' '$d/out' && grep -q 'not valid JSON: not measured, no JSON functions' '$d/out' && [ \$(grep -c 'absent (no JSON functions)' '$d/out') -ge 8 ] && grep -q '^R0: DONE' '$d/out'"
  c="$SB/proberr.$(basename "$T")"; sed "s/SELECT json_valid('{}') AS j/SELECT json_valid('{}') AS j FROM rh_no_such_table/" "$T" > "$c"
  [ "$(grep -c 'rh_no_such_table' "$c")" = 1 ] || bad "T3d: the probe error could not be planted"
  d="$(fresh ug)"; r0 "$d" "$c"
  chk "T3d a probe error that is not 'no such function' is re-thrown: exit 3, 'run again'" bash -c "[ $RC = 3 ] && grep -q 'SQLite could not finish reading the book (PDOException' '$d/out' && ! grep -q 'JSON functions no' '$d/out'"
  # T3e — NULL columns (kyc-first), the Latin-1 byte, the JSON number method and status, string-typed snapshot numbers
  for b in kyc-first ug; do
    d="$(fresh "$b")"; r0 "$d" "$T"
    chk "T3e $b: exit 0 (never 3) on NULL columns, a Latin-1 byte, JSON numbers, string numbers; the report equals the count" \
      bash -c "[ $RC = 0 ] && php '$H/check.php' '$d/out' '$d/exp.tool' '$d/ids.json' tool $b >/dev/null"
  done
}

# ── T4: a reading that cannot finish; R0's own error; the busy writer; time and memory; a writer between the readings ──
pausecopy() {   # a copy that touches <dir>/paused between the readings and waits for <dir>/go (anchor asserted)
  local c="$SB/pause.$(basename "$2")"
  awk '{ print } /^\$r1 = readBook\(/ { print "touch(dirname($db, 2) . \"/paused\"); for ($i = 0; $i < 200 && !is_file(dirname($db, 2) . \"/go\"); $i++) usleep(50000);" }' "$2" > "$c"
  [ "$(grep -c '/paused"); for' "$c")" = 1 ] || { echo "pausecopy: anchor not found" >&2; exit 3; }
  echo "$c"
}
writer() {      # writer <dir> <mode>: close | keep | other | text — as the owner, once R0 pauses (hold.php's writer modes)
  own php "$H/hold.php" "$1/live/plugin.sqlite3" "$1/paused" 4 "$2" "$1/go" &
  holders+=($!); WRITER=$!
}
inject() {      # inject <name> <tool> <php line>: a copy with one line after the §1.2 anchor (asserted)
  local c="$SB/$1.$(basename "$2")"
  awk -v L="$3" '{ print } /^    \/\/ ── §1\.2 voided rows/ { print L }' "$2" > "$c"
  [ "$(grep -cF -- "$3" "$c")" = 1 ] || { echo "inject $1: anchor not found" >&2; exit 3; }
  echo "$c"
}
nofilecheck() { local c="$SB/nfc.$(basename "$1")"; perl -0pe 's/ \|\| \$after !== \$before \|\| \$sidesNow\) \{/) {/' "$1" > "$c"; cmp -s "$1" "$c" && { echo "nofilecheck: anchor" >&2; exit 3; }; echo "$c"; }
t4() {
  local T="$1" P d m F i rcs bursts SP W5 M12 C2 key
  d="$(fresh ug)"; F="$(inject fail "$T" '    q($pdo, $h, "SELECT * FROM rh_no_such_table");')"
  own env -i PATH=/usr/bin:/bin php -d display_errors=stderr -d log_errors=1 -d error_log="$d/php-errors.log" -- "$d/live" "$SB/plugin" < "$F" > "$d/out" 2>&1; RC=$?
  chk "T4 a reading SQLite cannot finish ends as 'run again' (3); SQLite's words are not printed; even with log_errors on, no error log is written" \
    bash -c "[ $RC = 3 ] && grep -q 'SQLite could not finish reading the book (PDOException, code 1)' '$d/out' && grep -q 'run again' '$d/out' && ! grep -qiE 'fatal|uncaught|no such table|malformed' '$d/out' && [ ! -e '$d/php-errors.log' ]"
  d="$(fresh ug)"; F="$(inject type "$T" '    pick(5, STATUSES);')"
  own env -i PATH=/usr/bin:/bin php -d display_errors=stderr -d log_errors=1 -d error_log="$d/php-errors.log" -- "$d/live" "$SB/plugin" < "$F" > "$d/out" 2>&1; RC=$?
  chk "T4 a TypeError inside the reading: exit 3 with the internal-error text (class and line only), never 'run again', no error log" \
    bash -c "[ $RC = 3 ] && grep -qE '^R0: FAILED — an internal error \(TypeError, line [0-9]+\); this is R0.s own fault, not the plugin.s; do not run it again — send the log\. Nothing was written\.$' '$d/out' && ! grep -q 'run again' '$d/out' && ! grep -qE 'pick|STATUSES|must be of type' '$d/out' && [ ! -e '$d/php-errors.log' ]"
  d="$(fresh ug)"; key="COL-$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["ids"]["manual"][2];' "$d/ids.json")"
  F="$SB/ukey.$(basename "$T")"; sed 's/^            \$rows = \$byRef\[\$ref\] ?? \[\];$/            $rows = $byRef[$ref];/' "$T" > "$F"
  [ "$(diff "$T" "$F" | grep -c '^[<>]')" = 2 ] || bad "T4: the undefined-key copy could not be made"
  r0 "$d" "$F"
  chk "T4 an undefined text key: exit 3, the internal-error text (ErrorException, line), and the key ($key) nowhere in the output" \
    bash -c "[ $RC = 3 ] && grep -qE 'an internal error \(ErrorException, line [0-9]+\)' '$d/out' && ! grep -qF '$key' '$d/out' && ! grep -qE '$SECRETS' '$d/out'"
  # the busy writer, on the 30,000-row book
  d="$(fresh big)"
  rm -f "$d/ready"; own php "$H/stress.php" "$d/live/plugin.sqlite3" 25 "$d/ready" > "$d/bursts" & holders+=($!); SP=$!
  i=0; while [ ! -f "$d/ready" ] && [ $i -lt 100 ]; do sleep 0.05; i=$((i+1)); done
  rcs=""; W5="$(waitcopy 5 "$T")"
  for i in 1 2 3 4 5 6 7 8 9 10 11 12; do r0 "$d" "$W5"; rcs="$rcs $RC"; cat "$d/out" >> "$d/all.out"; done
  wait "$SP" 2>/dev/null; bursts="$(cat "$d/bursts" 2>/dev/null)"
  chk "T4 twelve readings against a writer that closes the book every few hundred ms ($bursts bursts): every exit is 0, 2 or 3 (were:$rcs), never an uncaught error" \
    bash -c "! echo '$rcs' | grep -qE '(^| )(1|[4-9]|[0-9]{2,})( |\$)' && ! grep -qiE 'fatal|uncaught|malformed|internal error' '$d/all.out'"
  chk "T4 the book is intact afterwards (integrity_check ok)" bash -c "[ \"\$(php -r '\$p=new PDO(\"sqlite:\".\$argv[1]); echo \$p->query(\"PRAGMA integrity_check\")->fetchColumn();' '$d/live/plugin.sqlite3')\" = ok ]"
  echo "  note T4 exits:$rcs (3 = the writer was caught mid-reading; 0 = a clean moment)"
  # T4f — one clean reading of the 30,000-row book: time and memory, under PHP's own 128M
  d="$(fresh big)"; M12="$SB/meter.$(basename "$T")"
  sed 's#^out(.R0: DONE — read only; nothing was written..);$#&\nout("METER peak " . memory_get_peak_usage(true) . " wall " . round(microtime(true) - $t0, 1));#' "$T" > "$M12"
  [ "$(grep -c '^out("METER peak' "$M12")" = 1 ] || bad "T4f: the meter could not be planted"
  own env -i PATH=/usr/bin:/bin php -d memory_limit=128M -d display_errors=stderr -d log_errors=0 -- "$d/live" "$SB/plugin" < "$M12" > "$d/out" 2>&1; RC=$?
  local peak wall; peak="$(sed -n 's/^METER peak \([0-9]*\) wall .*/\1/p' "$d/out")"; wall="$(sed -n 's/^METER peak [0-9]* wall \([0-9.]*\)$/\1/p' "$d/out")"
  echo "  note T4f the 30,000-row book: peak memory ${peak:-?} bytes, wall ${wall:-?} s (exit $RC)"
  chk "T4f one clean reading of the 30,000-row book under memory_limit=128M finishes (exit 0), wall time under 60 s, peak memory under 64M" \
    bash -c "[ $RC = 0 ] && [ -n '$peak' ] && [ '$peak' -lt 67108864 ] && php -r 'exit((float)\$argv[1] < 60 ? 0 : 1);' '${wall:-999}'"
  # a writer between the two readings
  P="$(pausecopy x "$T")"
  d="$(fresh ug)"; ( sleep 0.5; : > "$d/go" ) & r0 "$d" "$P"
  chk "T4 control: the instrumented copy with no writer finishes (0)" bash -c "[ $RC = 0 ] && grep -q 'R0: DONE' '$d/out'"
  for m in close keep other; do
    d="$(fresh ug)"; writer "$d" "$m"; r0 "$d" "$P"; wait "$WRITER" 2>/dev/null
    case "$m" in
      close) chk "T4 a writer that adds a row and closes between the readings: failed (3), readings DIFFERENT" bash -c "[ $RC = 3 ] && grep -q 'the readings are DIFFERENT' '$d/out' && grep -q 'may not be one moment' '$d/out'" ;;
      keep)  chk "T4 a writer still holding the book at the end: failed (3), side files APPEARED" bash -c "[ $RC = 3 ] && grep -q 'side files APPEARED' '$d/out'" ;;
      other) chk "T4 a writer to another table only: the readings agree, yet the file MOVED: failed (3)" bash -c "[ $RC = 3 ] && grep -q 'the readings are identical' '$d/out' && grep -q 'MOVED during the reading' '$d/out'" ;;
    esac
  done
  C2="$(pausecopy y "$(nofilecheck "$T")")"
  d="$(fresh ug)"; writer "$d" text; r0 "$d" "$C2"; wait "$WRITER" 2>/dev/null
  chk "T4 a writer that changes one description in place (no count moves), on a copy without the file check: the digest sees it — DIFFERENT (3)" \
    bash -c "[ $RC = 3 ] && grep -q 'the readings are DIFFERENT' '$d/out'"
}

# ── T5: the source ─────────────────────────────────────────────────────────────────────────────────────────────────
src_ok() { php "$H/srccheck.php" "$1"; }   # 0 when every static rule holds
src_bad() {     # src_bad <copy> <tool>: the copy differs from the tool AND srccheck refuses it (exit 1 exactly: a PHP
                # error, exit 255, or a missing command, 127, is never taken for a refusal)
  local rc; ! cmp -s "$1" "$2" || return 1
  php "$H/srccheck.php" "$1" > /dev/null 2>&1; rc=$?; [ "$rc" = 1 ]
}
t5() {
  local T="$1" c plant
  chk "T5 the tool keeps every static rule" src_ok "$T"
  c="$SB/plant.php"; { cat "$T"; printf '%s\n' '// a comment changes no rule'; } > "$c"
  chk "T5 control on the controls: a copy that differs only by a comment keeps every rule (srccheck exit 0)" src_ok "$c"
  chk "T5 control on the controls: … and src_bad does not take it for a refusal, so a refusal below is srccheck's own" eval "! src_bad '$c' '$T'"
  while IFS= read -r plant; do
    [ -n "$plant" ] || continue
    c="$SB/plant.php"; { cat "$T"; printf '%s\n' "$plant"; } > "$c"
    chk "T5 control: a copy with  $plant  is caught" src_bad "$c" "$T"
  done <<'PLANTS'
require "x.php";
new CashbookService(1, 2);
$q = $x->query("SELECT 1");
file_put_contents("/tmp/x", "y");
$y = `id`;
$z = $w->exec("DELETE FROM cb_ledger");
$p2 = new \PDO('sqlite::memory:');
$s3 = new SQLite3('x');
array_map('unlink', []);
error_log('x');
$e1 = getenv('X');
$e2 = file_get_contents('x');
$fn = 'trim'; $fn('x');
$cl = 'ArrayObject'; $ob = new $cl();
$pk = pick($pk0, STATUSES);
$mm = []; $kk = 'a'; $vv = $mm[$kk];
$ss = q($pdo, $h, "SELECT * FROM cash_ins");
$ss = q($pdo, $h, "SELECT {$r} AS x");
try { $x = 1; } catch (Throwable $e) { $x = 2; }
$gm = $e->getMessage();
$o(C($r['description']));
out(ADJ_CENTS_SHA);
$w1 = 'INSERT INTO cb_ledger (x) VALUES (1)';
$w2 = 'PRAGMA journal_mode = DELETE';
PLANTS
  c="$SB/plant.php"; sed 's/PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,//' "$T" > "$c"
  chk "T5 control: a copy without SQLITE_OPEN_READONLY is caught" src_bad "$c" "$T"
  c="$SB/plant.php"; sed "s/?immutable=1'/'/" "$T" > "$c"
  chk "T5 control: a copy without immutable=1 is caught" src_bad "$c" "$T"
  c="$SB/plant.php"; sed 's/LIMIT 2000/LIMIT 3000/' "$T" > "$c"
  chk "T5 control: an L1 page size that is not PAGE_ROWS is caught" src_bad "$c" "$T"
  c="$SB/plant.php"; perl -0pe 's/^set_error_handler\([^\n]*\n//m' "$T" > "$c"
  chk "T5 control: a copy without the silent error handler first is caught" src_bad "$c" "$T"
  c="$SB/plant.php"; perl -0pe 's/get_class\(\$e\), ", line ", \$e->getLine\(\)/\$e->getMessage(), ", line ", \$e->getLine()/' "$T" > "$c"
  chk "T5 control: an exception handler that prints the message is caught" src_bad "$c" "$T"
  c="$SB/plant.php"; perl -0pe 's/if \(strpos\(\$e->getMessage\(\), \x27no such function\x27\) === false\) throw \$e; //' "$T" > "$c"
  chk "T5 control: a probe catch that does not re-throw is caught" src_bad "$c" "$T"
  c="$SB/plant.php"; perl -0pe 's/    foreach \(\$rows as \$row\) hash_update\(\$h, serialize\(\$row\) \. "\\n"\);\n//' "$T" > "$c"
  chk "T5 control: a q() that does not feed every row to the digest is caught" src_bad "$c" "$T"
  c="$SB/plant.php"; perl -0pe 's/\} catch \(Throwable \$e\) \{\n        if \(\$e instanceof PDOException\) \{/} catch (Throwable \$e) {\n        if (true) {/' "$T" > "$c"
  chk "T5 control: a reading catch without its instanceof branch is caught" src_bad "$c" "$T"
  # the adjustment hash is docs/07's recorded figure; no planted amount is that figure (neither is printed here)
  local docsha toolsha
  docsha="$(php -r '$l = file($argv[1])[3539] ?? ""; if (!preg_match("/\\*\\*UGX ([0-9]{1,3}(?:,[0-9]{3})+) \"ADJUSTMENT ENTRY\" of 12 Sep\\*\\*/", $l, $m)) exit(1); echo hash("sha256", "dn-r0-adj|" . ((int)str_replace(",", "", $m[1]) * 100));' "$R/docs/07-change-control.md")"
  toolsha="$(sed -n "s/^const ADJ_CENTS_SHA = '\([0-9a-f]\{64\}\)';.*/\1/p" "$T")"
  chk "T5 the reviewed tool's adjustment hash is the figure recorded in docs/07 (line 3540)" bash -c "[ -n '$docsha' ] && [ '$docsha' = '$toolsha' ]"
  chk "T5 no planted amount, in any book, is the recorded figure" bash -c "! cat '$SB'/books/*/amounts | grep -E '^[0-9]+\$' | php -r '\$h = \$argv[1]; while ((\$l = fgets(STDIN)) !== false) if (hash(\"sha256\", \"dn-r0-adj|\" . trim(\$l)) === \$h) exit(0); exit(1);' '$toolsha'"
}

# ── T6: the command through R0's strict fake docker ────────────────────────────────────────────────────────────────
wsetup() {      # wsetup <book>: a server-shaped /data mount and a checkout copy; sets W, M, PL, PD
  W="$SB/w$((n=n+1))"; M="$W/mnt"; PL="$M/ucrm/data/plugins/dishnet-hybrid-sudan"; PD="$M/ucrm/data/plugins/.dishnet-hybrid-sudan-data"
  mkdir -p "$PL/data" "$W/repo/scripts" "$W/bin"
  cp -a "$SB/plugin/lib" "$SB/plugin/migrations" "$PL/"; echo '{"version":"5.18.94"}' > "$PL/manifest.json"
  printf 'f4b4c96\n' > "$PL/.deployed-commit"
  printf '{"pluginDataDir":"/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/"}\n' > "$PL/ucrm.json"
  cp -a "$SB/books/$1/live" "$PD"
  chown -R "$O" "$M"
  cp "$WRAP_T" "$W/repo/scripts/bank-recon-r0.sh"; cp "$TOOL_T" "$W/repo/scripts/bank-recon-r0.php"
  git -C "$W/repo" init -q && git -C "$W/repo" add -A && git -C "$W/repo" -c user.name=rh -c user.email=rh@example.invalid commit -qm r0 \
    || { echo "wsetup: git failed"; exit 3; }
  touch -d '2026-01-01' "$W/repo/scripts/bank-recon-r0.php"   # a stale stat: a plain `git status` would rewrite the index
  cp "$H/fake-docker.sh" "$W/bin/docker"; chmod +x "$W/bin/docker"
}
wrun() {        # wrun [args…]: the command as the operator runs it; rc in $RC, output in $W/out, trace in $W/trace
  : > "$W/trace"
  env PATH="$W/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$W/trace" FAKE_RUNNING="${FAKE_RUNNING:-true}" bash "$W/repo/scripts/bank-recon-r0.sh" "$@" > "$W/out" 2>&1
  RC=$?
}
r0ran()   { grep -q '^exec .* php -d display_errors=stderr' "$W/trace"; }
idx()     { printf '%s %s' "$(sha256sum < "$W/repo/.git/index")" "$(stat -c '%Y.%y' "$W/repo/.git/index")"; }
t6() {
  local WRAP_T="$1" TOOL_T="$2" i0 opt
  wsetup ug; ( cd "$M" && find . -printf '%p|%i|%s|%T@|%u:%g|%m\n' | sort; find . -type f -exec sha256sum {} + | sort ) > "$W/s0"; i0="$(idx)"; wrun
  ( cd "$M" && find . -printf '%p|%i|%s|%T@|%u:%g|%m\n' | sort; find . -type f -exec sha256sum {} + | sort ) > "$W/s1"
  chk "T6a the command runs R0 and exits 0" bash -c "[ $RC = 0 ] && grep -q 'R0: DONE' '$W/out' && grep -q 'R0 finished (exit 0)' '$W/out'"
  chk "T6a it names the installed commit, the data folder and the owner, exactly as the runner parses it" bash -c "grep -q '^  installed       f4b4c96 in ucrm; data /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data; database owner 1000:1000\$' '$W/out'"
  chk "T6a it names this checkout's commit, both R0 files as committed, and the wrapper's sha256" bash -c "grep -q \"this checkout   \$(git -C '$W/repo' rev-parse --short HEAD), both R0 files as committed; wrapper sha256 \$(sha256sum '$W/repo/scripts/bank-recon-r0.sh' | cut -c1-16)…\" '$W/out'"
  chk "T6a every docker call was one the strict fake allows; none refused" bash -c "! grep -q '^REFUSED' '$W/trace' && [ \$(grep -c '^inspect ' '$W/trace') = 2 ]"
  chk "T6a its closing line says what the log holds" bash -c "grep -q 'no name, description, person,' '$W/out' && grep -q 'reference, customer or amount.' '$W/out'"
  chk "T6a R0 ran as the owner, on standard input, with exactly 10 arguments, no file copied into the container" \
    bash -c "[ \$(grep '^exec 1000:1000 stdin php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- ' '$W/trace' | awk '{print NF-3}') = 10 ] && ! grep -q '^exec root stdin' '$W/trace' && ! grep -q ' cp ' '$W/trace'"
  chk "T6a control: the trace test the refusals below use ('R0 not run') sees this run of R0" r0ran
  chk "T6a its report equals the independent count" bash -c "php '$H/check.php' '$W/out' '$SB/books/ug/exp.tool' '$SB/books/ug/ids.json' tool ug > '$W/check'"
  chk "T6a nothing under the mount changed" cmp -s "$W/s0" "$W/s1"
  chk "T6a nothing planted is printed" noleak "$W/out"
  chk "T6a the checkout's .git/index is left as it was (sha256 and time), though its stat cache is stale" bash -c "[ '$i0' = '$(idx)' ]"
  for opt in --adjustment-id=1 --commit x; do
    wsetup ug; wrun "$opt"
    chk "T6c an option ($opt): exit 2, docker never called" bash -c "[ $RC = 2 ] && grep -q 'unknown option: $opt (R0 takes none)' '$W/out' && [ ! -s '$W/trace' ]"
  done
  wsetup ug; : > "$W/trace"; env PATH="$W/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$W/trace" setpriv --reuid=1000 --regid=1000 --clear-groups bash "$W/repo/scripts/bank-recon-r0.sh" > "$W/out" 2>&1; RC=$?
  chk "T6d not root: STOP, R0 not run" bash -c "[ $RC = 1 ] && grep -q 'run this as root' '$W/out' && ! grep -q 'php -d' '$W/trace'"
  wsetup ug; echo '// edited' >> "$W/repo/scripts/bank-recon-r0.php"; wrun
  chk "T6e an edited tool: STOP (sha256), R0 not run" bash -c "[ $RC = 1 ] && grep -q 'not the reviewed version' '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; FAKE_RUNNING=false wrun
  chk "T6f the container not running: STOP" bash -c "[ $RC = 1 ] && grep -q 'is not running' '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; rm -f "$PL/manifest.json"; wrun
  chk "T6g no installed plugin: STOP" bash -c "[ $RC = 1 ] && grep -q 'no installed plugin' '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; printf '{"pluginDataDir":"/data/ucrm/data/plugins/dishnet-hybrid-sudan/data"}\n' > "$PL/ucrm.json"; cp -a "$PD/plugin.sqlite3" "$PL/data/"; chown -R "$O" "$PL"; wrun
  chk "T6h ucrm.json naming <plugin>/data: STOP before R0" bash -c "[ $RC = 1 ] && grep -q \"stray store's old place\" '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; printf '{}\n' > "$PL/ucrm.json"; chown "$O" "$PL/ucrm.json"; wrun
  chk "T6i ucrm.json without a data folder: the folder beside the plugin is used, R0 runs (0)" bash -c "[ $RC = 0 ] && grep -q 'data /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data' '$W/out'"
  wsetup ug; chown 0:0 "$PD/plugin.sqlite3"; wrun
  chk "T6j a root-owned database: STOP, R0 not run" bash -c "[ $RC = 1 ] && grep -q 'owner is 0:0' '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; rm -f "$PD/plugin.sqlite3"; own php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("CREATE TABLE other(x)");' "$PD/plugin.sqlite3"; wrun
  chk "T6k R0's refusal comes through: exit 2" bash -c "[ $RC = 2 ] && grep -q 'no cb_ledger table' '$W/out' && grep -q 'R0 refused (exit 2)' '$W/out'"
  wsetup ug; rm -f "$PD/plugin.sqlite3"; wrun
  chk "T6m no database in the named data folder: STOP says so, R0 not run" bash -c "[ $RC = 1 ] && grep -q 'no plugin.sqlite3 in /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data' '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; echo '# edited' >> "$W/repo/scripts/bank-recon-r0.sh"; wrun
  chk "T6n an edited wrapper is named in the log" bash -c "[ $RC = 0 ] && grep -q '1 R0 file(s) EDITED' '$W/out'"
  wsetup ug; : > "$W/trace"
  env FAKE_MNT="$M" FAKE_TRACE="$W/trace" "$W/bin/docker" exec -i -u 1000:1000 ucrm php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- /data/x /data/y --adjustment-id=1 < /dev/null > /dev/null 2>&1; RC=$?
  chk "T6p control: the fake docker refuses an 11th argument (exit 99, REFUSED in its trace), so a command that passes one is caught" \
    bash -c "[ $RC = 99 ] && grep -q '^REFUSED exec' '$W/trace' && ! grep -q '^exec ' '$W/trace'"
}

suite() {       # suite <tool> <wrapper>: everything; returns the failures it added
  local f0=$fails
  t1 "$1"; t2 "$1"; t3 "$1"; t4 "$1"; t5 "$1"; t6 "$2" "$1"
  return $((fails - f0 > 0 ? 1 : 0))
}

echo "== the reviewed files =="
SHA="$(sha256sum "$TOOL0" | cut -c1-64)"
chk "the command pins the tool's sha256 ($SHA)" grep -q "^TOOL_SHA=\"$SHA\"\$" "$WRAP0"
chk "both files pass the syntax checks" bash -c "php -l '$TOOL0' >/dev/null && bash -n '$WRAP0'"
build big
suite "$TOOL0" "$WRAP0"
echo; echo "== result: $pass ok, $fails failed =="
MAIN_FAILS=$fails
[ "${MUTANTS:-1}" = 0 ] && { [ "$fails" = 0 ]; exit; }

# ── T7/T8: weakened copies — each must fail at least one assertion ───────────────────────────────────────────────────
mutant() {      # mutant <name> <file: tool|wrap> <perl substitution> [tests]
  local name="$1" which="$2" sub="$3" tests="${4:-all}" src dst T Wr f0 p0_ r
  if [ "$which" = tool ]; then src="$TOOL0"; else src="$WRAP0"; fi
  dst="$SB/mut.$name"; perl -0pe "$sub" "$src" > "$dst"
  if cmp -s "$src" "$dst"; then bad "mutant $name: the substitution did not apply"; return; fi
  T="$TOOL0"; Wr="$WRAP0"
  if [ "$which" = tool ]; then T="$dst"; Wr="$SB/mut.$name.sh"
    sed "s/^TOOL_SHA=\"[0-9a-f]*\"\$/TOOL_SHA=\"$(sha256sum "$dst" | cut -c1-64)\"/" "$WRAP0" > "$Wr"
  else Wr="$dst"; fi
  f0=$fails; p0_=$pass
  { case "$tests" in all) suite "$T" "$Wr" ;; *) for r in $tests; do case "$r" in t6) t6 "$Wr" "$T" ;; *) "$r" "$T" ;; esac; done ;; esac; } > "$SB/mut.$name.log" 2>&1
  local caught=$((fails - f0)); fails=$f0; pass=$p0_
  if [ "$caught" -gt 0 ]; then ok "mutant $name is caught ($caught assertion(s): $(grep -m1 FAIL "$SB/mut.$name.log" | cut -c8-90))"; else bad "mutant $name is NOT caught"; fi
}
echo; echo "== weakened copies of the tool =="
mutant write-guards-off tool 's/PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,\n//; s/\$pdo->exec\(\x27PRAGMA query_only = ON\x27\);\n//; s/\x27sqlite:file:\x27 \. \$db \. \x27\?immutable=1\x27/\x27sqlite:\x27 . \$db/; s/(\$pdo->exec\(\x27BEGIN\x27\);)/$1 \$pdo->exec("UPDATE cb_ledger SET updated_at = updated_at");/' "t1 t5"
mutant no-readonly-flag tool 's/PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,\n//' "t5"
mutant no-query-only tool 's/\$pdo->exec\(\x27PRAGMA query_only = ON\x27\);\n//' "t1"
mutant plain-open tool 's/\x27sqlite:file:\x27 \. \$db \. \x27\?immutable=1\x27/\x27sqlite:\x27 . \$db/' "t1 t5"
mutant no-temp-store tool 's/        \$pdo->exec\(\x27PRAGMA temp_store = MEMORY\x27\);\n//; s/    if \(\(int\)\(q\(\$pdo, \$h, \x27SELECT \* FROM pragma_temp_store\x27\)[^\n]*\n//' "t1 t5"
mutant no-wait tool 's/^while \(sides\(\$db\)\) \{/while (false) {/m' "t2"
mutant no-second-reading tool 's/^\$r2 = readBook\(\$db\);/\$r2 = \$r1;/m' "t4"
mutant no-file-check tool 's/ \|\| \$after !== \$before \|\| \$sidesNow\) \{/) {/' "t4"
mutant no-catch tool 's/\} catch \(Throwable \$e\) \{\n        if \(\$e instanceof PDOException\)/} catch (LogicException \$e) {\n        if (\$e instanceof PDOException)/' "t4"
mutant probe-swallows-all tool 's/if \(strpos\(\$e->getMessage\(\), \x27no such function\x27\) === false\) throw \$e; //' "t3"
mutant json-unguarded-all tool 's/return "CASE WHEN json_valid\(data\) THEN json_extract\(data,\x27\$\." \. \$f \. "\x27\) END";/return "json_extract(data,\x27\$." . \$f . "\x27)";/; s/\$g1 = \x27json_valid\(data\)\x27; \$g2 = "json_type\(data,\x27\$\.audit_log\x27\)=\x27array\x27";/\$g1 = \x271\x27; \$g2 = \x271\x27;/; s/\$g1 = \x27json_valid\("audit_log"\)\x27; \$g2 = "json_type\(\\"audit_log\\"\)=\x27array\x27";/\$g1 = \x271\x27; \$g2 = \x271\x27;/; s/return "CASE WHEN json_valid\(\{\$col\}\) THEN \{\$x\} END AS \{\$side\}_\{\$f\}";/return "{\$x} AS {\$side}_{\$f}";/; s/CASE WHEN json_valid\(before_json\) THEN CASE WHEN CAST\(json_extract\(before_json,\x27\$\.id\x27\) AS INTEGER\) = record_id THEN 1 ELSE 0 END ELSE 0 END AS bok/CASE WHEN CAST(json_extract(before_json,\x27\$.id\x27) AS INTEGER) = record_id THEN 1 ELSE 0 END AS bok/' "t3"
chk "mutant json-unguarded-all: all five guarded JSON sites were unguarded (only the probe and the validity flag still call json_valid)" bash -c "[ \$(grep -o 'json_valid(' '$SB/mut.json-unguarded-all' | wc -l) = 2 ]"
mutant json-unguarded-stores tool 's/return "CASE WHEN json_valid\(data\) THEN json_extract\(data,\x27\$\." \. \$f \. "\x27\) END";/return "json_extract(data,\x27\$." . \$f . "\x27)";/; s/\$g1 = \x27json_valid\(data\)\x27; \$g2 = "json_type\(data,\x27\$\.audit_log\x27\)=\x27array\x27";/\$g1 = \x271\x27; \$g2 = \x271\x27;/' "t3"
mutant absent-fails tool 's/(foreach \(NEED as \$t => \$need\) \{\n        )if \(!isset\(\$cols\[\$t\]\)\) \{ \$ok\[\$t\] = \x27absent\x27; continue; \}/$1if (!isset(\$cols[\$t])) refuse(2, "no \$t table");/' "t3"
mutant prints-amount tool 's/(\. \x27  \x27 \. pick\(\(string\)\(\$C\[\x27status\x27\]\[\$i\] \?\? \x27\x27\), STATUSES\))\)\);/$1 . \x27  \x27 . number_format((\$cents[\$i] ?? 0) \/ 100, 2, \x27.\x27, \x27\x27)));/' "t1"
mutant prints-description tool 's/(            \$desc = \(string\)\$r\[\x27description\x27\];\n)/$1            \$C[\x27desc\x27][] = \$desc;\n/; s/(\. \x27  \x27 \. \(\$C\[\x27kind\x27\]\[\$i\] \?\? \x27\x27\))\)\);\n    \}\n    if \(\$faj\)/$1 . \x27 \x27 . (\$C[\x27desc\x27][\$i] ?? \x27\x27)));\n    }\n    if (\$faj)/' "t1"
mutant voided-by-raw tool 's/(\x27 AS INTEGER\) AS jid, \x27 \. J\(\$sh, \$tc, \x27status\x27\) \. \x27 AS status, \x27 \. )VB\(J\(\$sh, \$tc, \x27voided_by\x27\)\)/$1J(\$sh, \$tc, \x27voided_by\x27)/; s/\(VBY\[\(string\)\$x\[\x27vby\x27\]\] \?\? \x27another value\x27\) \. \x27 · voided_at \x27/(VBY[(string)\$x[\x27vby\x27]] ?? (string)\$x[\x27vby\x27]) . \x27 · voided_at \x27/' "t1"
mutant no-whitelist tool 's/\{ return in_array\(\(string\)\$v, \$allowed, true\) \? \(string\)\$v : \x27\(other\)\x27; \}/{ return (string)\$v; }/' "t1"
mutant drop-T1 tool 's/\$s1 = stat4\(\$p1, \$l1, \$live\);/\$s1 = stat4([], [], \$live);/' "t1"
mutant drop-T3-retry-link tool 's/        foreach \(\[\(int\)\$x\[\x27p1\x27\], \(int\)\$x\[\x27p2\x27\]\] as \$p\) if \(\$p > 0\) \$Pret\[\$c\]\[\$p\] = true;\n//' "t1"
mutant T4-same-day-only tool 's/bucketScan\(\$b4, \$dn, \$live, \$legOf, \$o4, \$W\)/bucketScan(\$b4, \$dn, \$live, \$legOf, \$o4, 0)/' "t1"
mutant T4-off-by-one tool 's/<= \$d \+ \$W\) \$hi\+\+;/< \$d + \$W) \$hi++;/g' "t1"
mutant T5-text-raw tool 's/texthash\(\x27t\|\x27, \$desc, true\)/texthash(\x27t|\x27, \$desc, false)/' "t1"
mutant drop-T6 tool 's/if \(\$hit\) \{ \$matched\+\+;/if (false) { \$matched++;/' "t1"
mutant T6-zero-part tool 's/\$v = \$cents\[\$s\] \?\? 0; if \(\$v > 0 && \$v < \$tgt\) \$vals\[\] = \$v;/\$v = \$cents[\$s] ?? 0; \$vals[] = \$v;/' "t1"
mutant drop-T7 tool 's/                \$pairs\[pkey\(\$kk, \$ss\)\] = \[\$kk, \$ss\];\n//' "t1"
mutant pair-legs-counted tool 's/bucketScan\(\$b, \$dn, \$live, \$legOf, \$bo, -1\); \$s2/bucketScan(\$b, \$dn, \$live, [], \$bo, -1); \$s2/' "t1"
mutant funding-legs-twin tool 's/if \(!\$isPair && \$opp !== \[\x27in\x27, \x27out\x27\]\) continue;/if (\$opp !== [\x27in\x27, \x27out\x27]) continue;/' "t1"
mutant pair-as-single tool 's/elseif \(\$st === \x27voided\x27 && in_array\(\$C\[\x27source\x27\]\[\$i\] \?\? \x27\x27, PAIR_SOURCES, true\)/elseif (false \&\& in_array(\$C[\x27source\x27][\$i] ?? \x27\x27, PAIR_SOURCES, true)/' "t1"
mutant kyc-appid-as-client tool 's/if \(!\$c1 && \$c2\) \{ \$cli\[\$i\] = 0; \$kycAppIds\+\+; \}/if (false) { \$cli[\$i] = 0; \$kycAppIds++; }/' "t1"
mutant catchup-as-a tool 's/if \(\(\$C\[\x27source\x27\]\[\$s\] \?\? \x27\x27\) === \x27catchup_sync\x27 \|\| \(\$C\[\x27srcol\x27\]\[\$s\] \?\? 0\) === \$c \|\| \(\$C\[\x27vcol\x27\]\[\$s\] \?\? 0\) === \$c\) continue;/if (false) continue;/' "t1"
mutant crm-direct-as-cash tool 's/\$nd = array_values\(array_filter\(\$set, static fn\(\$r\) => !\$r\[\x27direct\x27\]\)\);/\$nd = \$set;/' "t1"
mutant unknown-time-after tool 's/        if \(\$t === \x27\x27\) continue;\n        if \(strpos\(\$t, CUTOFF_DAY\) === 0\)/        if (\$t === \x27\x27) { \$vs[\x27after\x27]++; continue; }\n        if (strpos(\$t, CUTOFF_DAY) === 0)/' "t1"
mutant no-finaudit-time tool 's/        foreach \(\$faVoid\[\$id\] \?\? \[\] as \$fv\) \{ \$t = \(string\)\$fv\[\x27t\x27\];[^\n]*\n//' "t1"
mutant latest-void-time tool 's/strcmp\(\$t, \$best\) < 0/strcmp(\$t, \$best) > 0/g; s/\$mins \? ist\(\$pool, \(string\)min\(\$mins\)\)/\$mins ? ist(\$pool, (string)max(\$mins))/' "t1"
# the stamps that are not the row's own (§1.3): a reversal's copied text, pasted text, uCRM's "T" creation time
mutant stamp-before-creation-taken tool 's/if \(\$made !== \x27\x27 && strcmp\(\$mi, \$made\) < 0\)/if (false)/g' "t1"
chk "mutant stamp-before-creation-taken: both stamp loops lost the creation test" bash -c "! grep -q 'strcmp(\$mi, \$made) < 0' '$SB/mut.stamp-before-creation-taken'"
mutant creation-T-literal tool 's/ \? substr_replace\(\$cat, \x27 \x27, 10, 1\) : / ? \$cat : /' "t1"
mutant reversal-marks-counted tool 's/if \(!\(\$C\[\x27rev\x27\]\[\$i\] \?\? false\) && /if (/; s/ \|\| \(\$C\[\x27rev\x27\]\[\$i\] \?\? false\)\) continue;/) continue;/' "t1"
mutant marks-on-whole-text tool 's/substr_count\(\$mine, /substr_count(\$desc, /; s/strpos\(\$mine, /strpos(\$desc, /' "t1"
mutant copied-text-read-as-plain tool 's/(\$plain = preg_match\([^\n]*?), \$mine\) === 1;/$1, \$desc) === 1;/' "t1"
mutant copied-text-line-dropped tool 's/    \$o\(C\(\x27copied text [^\n]*\n[^\n]*\n//' "t1"
mutant void-undone-ignored tool 's/    \$o\(C\(\x27void undone [^\n]*\n[^\n]*\n//' "t1"
mutant post-void-same-minute-out tool 's/strcmp\(\(string\)\$u\[\x27t\x27\], \$t\) >= 0/strcmp((string)\$u[\x27t\x27], \$t) > 0/' "t1"
mutant readable-any-json tool 's/CASE WHEN json_valid\(before_json\) THEN CASE WHEN CAST\(json_extract\(before_json,\x27\$\.id\x27\) AS INTEGER\) = record_id THEN 1 ELSE 0 END ELSE 0 END AS bok/CASE WHEN json_valid(before_json) THEN 1 ELSE 0 END AS bok/' "t3"
mutant tool-by-pattern tool 's/\$t70 = strpos\(\$desc, TOOL_REASON_MARK\) !== false && strpos\(\$desc, TOOL_REASON_END\) !== false;/\$t70 = strpos(\$desc, TOOL_BY) !== false;/' "t1"
mutant tool-like tool 's/(\$C\[\x27tool70\x27\]\[\] = \$t70; \$C\[\x27tool69\x27\]\[\] = \$t69;)/$1 \$C[\x27t70l\x27][] = stripos(\$desc, TOOL_REASON_MARK) !== false \&\& stripos(\$desc, TOOL_REASON_END) !== false;/; s/if \(!\(\$C\[\x27tool70\x27\]\[\$i\] \?\? false\)\) continue;/if (!(\$C[\x27t70l\x27][\$i] ?? false)) continue;/' "t1"
mutant cashin-ids-shifted tool 's/^const CASHINS = \[3, 4, 5\];/const CASHINS = [4, 5, 6];/m' "t1"
mutant relabel-no-for-missing tool 's/\$rel = !\$relCol \? \x27unknown \(column audit_log missing\)\x27 :/\$rel = !\$relCol ? \x27no\x27 :/' "t3"
mutant candidates-live-only tool 's/        if \(!\(\$C\[\x27adj\x27\]\[\$i\] \?\? false\)\) continue;/        if (!(\$C[\x27adj\x27][\$i] ?? false) || !(\$live[\$i] ?? true)) continue;/' "t1"
mutant candidates-no-deleted tool 's/        foreach \(\$faDel as \$f\) \{\n            if \(\(int\)\$f\[\x27bok\x27\] !== 1\) continue;/        foreach ([] as \$f) {\n            if ((int)\$f[\x27bok\x27] !== 1) continue;/' "t1"
mutant vs-candidate-own-date tool 's/vsCand\(\(string\)\(\$C\[\x27id\x27\]\[\$i\] \?\? 0\), \$C\[\x27cat\x27\]\[\$i\] \?\? \x27\x27, \$V, \$vt\)/vsCand((string)(\$C[\x27id\x27][\$i] ?? 0), \$C[\x27d\x27][\$i] ?? \x27\x27, \$V, \$vt)/; s/    if \(!preg_match\(\x27\/\^\\d\{4\}-\\d\{2\}-\\d\{2\}\/\x27, \$cat\)\) return \x27            candidate \x27 \. \$what \. \x27: creation unknown\x27;\n//' "t1"
mutant top5-live-only tool 's/if \(\(\$C\[\x27acc\x27\]\[\$i\] \?\? 1\) === 0\) \$top\[/if ((\$C[\x27acc\x27][\$i] ?? 1) === 0 \&\& (\$live[\$i] ?? false)) \$top[/' "t1"
mutant section-skipped tool 's/        \$o\(L\(\x27equipment\x27, \x27live assignments \x27[^\n]*\n//' "t1"
mutant no-error-handler tool 's/^set_error_handler\([^\n]*\n//m' "t5"
mutant undefined-key-read tool 's/\$rows = \$byRef\[\$ref\] \?\? \[\];/\$rows = \$byRef[\$ref];/' "t1"
mutant undefined-key-unhandled tool 's/^set_error_handler\([^\n]*\n//m; s/\$rows = \$byRef\[\$ref\] \?\? \[\];/\$rows = \$byRef[\$ref];/' "t1"
mutant no-string-cast tool 's/\b(pick|code|src|day)\(\(string\)/$1(/g' "t3 t5"
mutant one-catch-message tool 's/fail\(\x27an internal error \(\x27 \. get_class\(\$e\) \. \x27, line \x27 \. \$e->getLine\(\) \. \x27\); this is R0\\\x27s own fault, not the plugin\\\x27s; do not run it again — send the log\x27\);/fail(\x27SQLite could not finish reading the book (\x27 . get_class(\$e) . \x27, code -). With the book read immutable, that means the plugin rewrote it during the reading; the book itself is not touched by this. Nothing was measured; run again\x27);/' "t4"
mutant digest-not-compared tool 's/\$r1\[\x27lines\x27\] === \$r2\[\x27lines\x27\] && \$r1\[\x27digest\x27\] === \$r2\[\x27digest\x27\]/\$r1[\x27lines\x27] === \$r2[\x27lines\x27]/; s/\$r1\[\x27lines\x27\] !== \$r2\[\x27lines\x27\] \|\| \$r1\[\x27digest\x27\] !== \$r2\[\x27digest\x27\]/\$r1[\x27lines\x27] !== \$r2[\x27lines\x27]/' "t4"
mutant fetch-all-L1 tool 's/ORDER BY l\.id LIMIT 2000"/ORDER BY l.id"/; s/\} while \(\$got === PAGE_ROWS\);/} while (false);/' "t4 t5"
mutant select-star-store tool 's/(        \$S\[\$t\] = q\(\$pdo, \$h, \$sql\);)/        if (\$t === \x27payment_collections\x27) \$sql = "SELECT * FROM payment_collections ORDER BY id";\n$1/' "t5"
echo; echo "== weakened copies of the command =="
mutant wrap-no-sha wrap 's/^\[ "\$\(sha256sum "\$TOOL" \| cut -c1-64\)" = "\$TOOL_SHA" \] \|\| stop[^\n]*\n//m' "t6"
mutant wrap-root-owner wrap 's/\x27\x27\|0:\*\|\*:\*:\*\)/\x27\x27|*:*:*)/' "t6"
mutant wrap-stray-folder wrap 's/^\[ "\$\{PDD_IN%\/\}" != "\$IN_CONTAINER\/data" \] \|\| stop[^\n]*\n//m' "t6"
mutant wrap-as-root wrap 's/docker exec -i -u "\$U" "\$CONTAINER" php/docker exec -i "\$CONTAINER" php/' "t6"
mutant wrap-not-root-ok wrap 's/^\[ "\$\(id -u\)" = "0" \] \|\| stop[^\n]*\n//m' "t6"
mutant wrap-log-errors wrap 's/ -d log_errors=0//' "t6"
mutant wrap-no-db-check wrap 's/^dx "\$CONTAINER" test -f [^\n]*\n//m' "t6"
mutant wrap-accepts-option wrap 's/^\[ \$# -eq 0 \] \|\| \{[^\n]*\n//m; s/"\$PDD_IN" "\$IN_CONTAINER" < "\$TOOL"/"\$PDD_IN" "\$IN_CONTAINER" "\$@" < "\$TOOL"/' "t6"
mutant wrap-opcache-on wrap 's/ -d opcache\.enable_cli=0//' "t6"
mutant wrap-git-locks wrap 's/git --no-optional-locks -C/git -C/g' "t6"
echo; echo "== total: $pass ok, $fails failed (the suite on the reviewed files: $MAIN_FAILS failed) =="
[ "$fails" = 0 ]
