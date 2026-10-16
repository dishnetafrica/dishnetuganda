#!/usr/bin/env bash
# rehearse.sh — P0 of docs/68 (scripts/bank-recon-p0.sh and scripts/bank-recon-p0.php), against synthetic books written
# by the plugin's own code, in a sandbox. It proves, by running the real files:
#   T1  P0's figures equal CashbookService::cashInHand() and bookBase() — the plugin's own code — and an independent
#       per-row classification (expect.php), on six books: Uganda, South Sudan, three settings edge cases, and a book
#       where the adjustment cannot be picked alone (then named with --adjustment-id);
#   T1  nothing it can reach changes: every file of the data folder, the plugin's data folder and the vault is the same
#       afterwards (content, inode, size, times, owner, mode), and no file appears; and no fictitious secret planted in
#       the book (descriptions, persons, references, 10-digit account and phone numbers, withheld account names) is printed;
#   T2  while the plugin holds the book open (its -wal and -shm present, a committed row only in the -wal), P0 waits,
#       reads after the plugin closes it, and counts that row; held too long, it refuses and changes nothing;
#   T3  each refusal: <plugin>/data, no database, an unknown option (--commit included), a side file left alone, a PHP
#       that will not open an SQLite URI, an unexpected schema, a path SQLite cannot carry;
#   T4  a writer between P0's two readings is caught three ways: the readings differ, the file moved, a side file stayed;
#       an instrumented copy with no writer is the control;
#   T5  the source: no plugin code, one PDO open (immutable, read-only), no statement but PRAGMA query_only/BEGIN/ROLLBACK
#       outside q(), no file-writing or process function — each check with a planted copy as its control;
#   T6  the wrapper through a fake docker: the reviewed sha256, root, a running container, the installed plugin, the data
#       folder from ucrm.json (never <plugin>/data), a non-root owner, P0 run as that owner on standard input;
#   T7/T8  weakened copies of the tool and of the wrapper, each caught by at least one assertion.
# Everything is fictitious. It never touches this checkout's plugin, the server or the network, and refuses to run where
# the server could be.
#
#   bash scripts/harness/bank-recon-p0/rehearse.sh            KEEP=1 keeps the sandbox; MUTANTS=0 skips T7/T8
set -u
for f in /data/ucrm /opt/dishnet /var/run/docker.sock; do
  [ -e "$f" ] && { echo "refusing: $f exists — this looks like the server, and this rehearsal must never run there"; exit 2; }
done
[ "$(id -u)" = 0 ] || { echo "run as root (the sandbox's owner is uid 1000, as the server's database owner is)"; exit 2; }
for c in php setpriv sha256sum find; do command -v "$c" >/dev/null || { echo "missing $c"; exit 2; }; done
H="$(cd "$(dirname "$0")" && pwd)"; R="$(cd "$H/../../.." && pwd)"
TOOL0="$R/scripts/bank-recon-p0.php"; WRAP0="$R/scripts/bank-recon-p0.sh"
SB="$(mktemp -d /tmp/bank-recon-p0.XXXXXX)"; chmod 755 "$SB"
holders=()
cleanup() { for p in "${holders[@]}"; do kill "$p" 2>/dev/null; done; wait 2>/dev/null; [ -n "${KEEP:-}" ] && echo "kept: $SB" || rm -rf "$SB"; }
trap cleanup EXIT
pass=0; fails=0
ok()  { pass=$((pass+1)); echo "  ok   $*"; }
bad() { fails=$((fails+1)); echo "  FAIL $*"; }
chk() { local m="$1"; shift; if "$@"; then ok "$m"; else bad "$m"; fi; }
O=1000:1000
own() { setpriv --reuid=1000 --regid=1000 --clear-groups "$@"; }
SECRETS='RH-MARK|0700123456|7000000001|0772|987 654|\+256|Airtel|MoMo|Ecobank|Cash – |BANK-REF001|BANK-0123456789ab|ADJUSTMENT ENTRY|Approver|Driver|reason|orphan|card purchase|malformed|Uncaught|Fatal'
noleak() { ! grep -Eq "$SECRETS" "$1"; }
has()   { grep -Fq -- "$2" "$1"; }

# ── The sandbox plugin (a copy of lib/ and migrations/; the checkout's plugin is never run) and the master books ─────────
mkdir -p "$SB/plugin/data" "$SB/books"
cp -a "$R/dishnet-hybrid-sudan/lib" "$R/dishnet-hybrid-sudan/migrations" "$SB/plugin/"
chown -R "$O" "$SB/plugin" "$SB/books"
build() {   # build <name> <variant> — a master book and its expected figures, written as the owner
  local d="$SB/books/$1"; mkdir -p "$d/live"; chown -R "$O" "$d"
  own env DN_VAULT_FILE="$d/vault.json" php "$H/build-book.php" "$SB/plugin" "$d/live" "$2" > "$d/ids.json" 2> "$d/build.err" \
    || { echo "build $1 failed:"; cat "$d/build.err"; exit 3; }
  local adj; adj="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["adj"] ?? -1;' "$d/ids.json")"
  [ "$2" = two-adj ] && adj=-1
  own env DN_VAULT_FILE="$d/vault.json" php "$H/expect.php" "$SB/plugin" "$d/live" "$adj" > "$d/exp.json" 2> "$d/exp.err" \
    || { echo "expect $1 failed:"; cat "$d/exp.err"; exit 3; }
}
for v in ug ss cfg-diff cfg-vault cfg-novault two-adj; do build "$v" "$v"; done
ADJ2="$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["adj"];' "$SB/books/two-adj/ids.json")"
cp -a "$SB/books/two-adj" "$SB/books/two-adj-named"
own env DN_VAULT_FILE="$SB/books/two-adj-named/vault.json" php "$H/expect.php" "$SB/plugin" "$SB/books/two-adj-named/live" "$ADJ2" > "$SB/books/two-adj-named/exp.json"
rm -f "$SB/books"/*/live/plugin.sqlite3-wal "$SB/books"/*/live/plugin.sqlite3-shm 2>/dev/null
n=0
mkdir -p "$SB/run"
fresh() {   # fresh <book> — a new working copy of a master book, owned as the master is: echoes its folder
  local d; d="$(mktemp -d "$SB/run/$1.XXXXXX")" || exit 3
  cp -a "$SB/books/$1/." "$d/" && chown "$O" "$d" && chmod 755 "$d" || exit 3
  echo "$d"
}
snap() {    # every file P0 could reach, with its content and identity
  ( cd "$1" && find live vault.json "$SB/plugin/data" -printf '%p|%y|%i|%s|%T@|%C@|%u:%g|%m\n' 2>/dev/null | sort
    find live -type f -exec sha256sum {} + 2>/dev/null | sort; [ -f vault.json ] && sha256sum vault.json )
}
p0() {      # p0 <work dir> <tool> [args…] — P0 as the owner, the tool on standard input; rc in $RC, output in <dir>/out
  local d="$1" t="$2"; shift 2
  own env -i PATH=/usr/bin:/bin DN_VAULT_FILE="$d/vault.json" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- "$d/live" "$SB/plugin" "$@" < "$t" > "$d/out" 2>&1
  RC=$?
}

# ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
t1() {      # t1 <tool> — the six books (and the named adjustment), figures, nothing changed, nothing leaked
  local T="$1" b d aok args
  for b in ug ss cfg-diff cfg-vault cfg-novault two-adj two-adj-named; do
    d="$(fresh "$b")"; snap "$d" > "$d/snap.before"
    args=(); [ "$b" = two-adj-named ] && args=("--adjustment-id=$ADJ2")
    p0 "$d" "$T" "${args[@]}"
    snap "$d" > "$d/snap.after"
    aok=yes; case "$b" in ss|two-adj) aok=no ;; esac
    chk "T1 $b: P0 exits 0" [ "$RC" = 0 ]
    if php "$H/check.php" "$d/out" "$d/exp.json" "$aok" > "$d/check"; then ok "T1 $b: every figure equals the plugin's own and the independent count ($(grep -c '^ok' "$d/check") checks)"
    else bad "T1 $b: figures differ: $(grep FAIL "$d/check" | head -3 | tr '\n' ';')"; fi
    chk "T1 $b: every reachable file is unchanged and none appeared" cmp -s "$d/snap.before" "$d/snap.after"
    chk "T1 $b: no planted description, person, reference, number or withheld name is printed" noleak "$d/out"
  done
  d="$(fresh ug)"; p0 "$d" "$T"
  chk "T1 ug: the adjustment is found alone and placed in September's P&L" has "$d/out" "in the P&L of 2026-09: YES"
  chk "T1 ug: the accounts are listed by id, kind and currency only (no names); a free-text type reads (other)" bash -c "[ \$(grep -cE '^                  [0-9]+ +(bank|cash|momo|director|inventory) +(UGX|USD) +yes +[0-9]+\$' '$d/out') = 10 ] && grep -q 'match the ids on Accounts' '$d/out' && grep -q 'cash and mobile-money accounts by type: .*(other) 1 (1,000.00)' '$d/out'"
  chk "T1 ug: the adjustment line carries codes only (category as capital or not, source (other))" bash -c "grep -q 'category not a capital one, untyped, source (other), approved, project dishnet, currency UGX' '$d/out'"
  chk "T1 ug: a free-text currency code is reported as (other)" bash -c "grep -q 'other currencies in the book, not shown on the hero: .*(other) 1' '$d/out'"
  d="$(fresh two-adj)"; p0 "$d" "$T"
  chk "T1 two-adj: the two candidates are listed by id, direction and status, nothing else" bash -c "grep -qE '2 candidate\(s\) on 2026-09-12 — not identified: ids $ADJ2 \(out, approved\), [0-9]+ \(in, approved\); open them in the Cashbook, then run again with --adjustment-id=N\$' '$d/out'"
  d="$(fresh two-adj-named)"; p0 "$d" "$T" "--adjustment-id=$ADJ2"
  chk "T1 two-adj: --adjustment-id names it" has "$d/out" "named by --adjustment-id: id $ADJ2"
  d="$(fresh ug)"; snap "$d" > "$d/snap.before"; p0 "$d" "$T" "--adjustment-id=1"; snap "$d" > "$d/snap.after"
  chk "T1 ug: --adjustment-id of a row on an account fails (3), nothing changed" bash -c "[ $RC = 3 ] && grep -q 'is not a live, unassigned row' '$d/out' && cmp -s '$d/snap.before' '$d/snap.after'"
}

# ── T2: the plugin holds the book ──────────────────────────────────────────────────────────────────────────────────
hold() {    # hold <dir> <seconds> — as the owner: open, add one committed row that stays in the -wal, keep the book open
  rm -f "$1/held"
  own php "$H/hold.php" "$1/live/plugin.sqlite3" "$1/held" "$2" &
  holders+=($!); HOLDER=$!
  local i=0; while [ ! -f "$1/held" ] && [ $i -lt 100 ]; do sleep 0.05; i=$((i+1)); done
}
waitcopy() {  # a copy of the tool that waits $1 s instead of 120 (anchor asserted)
  local c="$SB/wait$1.php"
  sed "s/^const WAIT_SECONDS       = 120;\$/const WAIT_SECONDS       = $1;/" "$2" > "$c"
  [ "$(grep -c "^const WAIT_SECONDS       = $1;\$" "$c")" = 1 ] || { echo "waitcopy: anchor not found"; exit 3; }
  echo "$c"
}
t2() {
  local T="$1" d d2
  d="$(fresh ug)"; hold "$d" 3
  chk "T2 the holder has the book open: -wal and -shm present, the row only in the -wal" bash -c "[ -f '$d/live/plugin.sqlite3-wal' ] && [ -s '$d/live/plugin.sqlite3-wal' ] && [ -f '$d/live/plugin.sqlite3-shm' ]"
  p0 "$d" "$T"; wait "$HOLDER" 2>/dev/null
  own env DN_VAULT_FILE="$d/vault.json" php "$H/expect.php" "$SB/plugin" "$d/live" "$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["adj"];' "$d/ids.json")" > "$d/exp2.json" 2>/dev/null
  chk "T2 P0 waited for the plugin to close the book, then read it (exit 0)" bash -c "[ $RC = 0 ] && grep -q 'after waiting [0-9]* s for the plugin to close the book' '$d/out'"
  chk "T2 the row that was only in the -wal is counted: figures equal cashInHand() after the close" php "$H/check.php" "$d/out" "$d/exp2.json" yes
  chk "T2 the 4,321 row moved today's UGX figure" bash -c "php -r '\$j=json_decode(file_get_contents(\$argv[1]),true); \$k=json_decode(file_get_contents(\$argv[2]),true); exit(abs(\$j[\"per\"][\"UGX\"][\"all\"][\"today\"]-\$k[\"per\"][\"UGX\"][\"all\"][\"today\"]-4321)<0.001?0:1);' '$d/exp2.json' '$d/exp.json'"
  chk "T2 no side file is left and every file of the book belongs to the owner" bash -c "[ ! -e '$d/live/plugin.sqlite3-wal' ] && [ ! -e '$d/live/plugin.sqlite3-shm' ] && [ -z \"\$(find '$d/live' ! -user 1000)\" ]"
  d2="$(fresh ug)"; snap "$d2" > "$d2/snap.before"; hold "$d2" 6; snap "$d2" > "$d2/snap.held"
  p0 "$d2" "$(waitcopy 2 "$T")"
  snap "$d2" > "$d2/snap.after"; wait "$HOLDER" 2>/dev/null
  chk "T2 held longer than the wait: P0 refuses (2) and names the reason" bash -c "[ $RC = 2 ] && grep -q 'the plugin kept the book open for 2 s' '$d2/out'"
  chk "T2 … and while it waited, it changed nothing (the holder's own -wal and -shm aside, identical)" cmp -s "$d2/snap.held" "$d2/snap.after"
}

# ── T3: refusals ───────────────────────────────────────────────────────────────────────────────────────────────────
t3() {
  local T="$1" d out
  d="$(fresh ug)"; mkdir -p "$d/plg/data"; cp -a "$d/live/plugin.sqlite3" "$d/plg/data/"; chown -R "$O" "$d/plg"; snap "$d" > "$d/s0"
  own php -d display_errors=stderr -- "$d/plg/data" "$d/plg" < "$T" > "$d/out" 2>&1; RC=$?
  chk "T3a <plugin>/data is refused (2) before anything is opened" bash -c "[ $RC = 2 ] && grep -q \"stray store's old place\" '$d/out'"
  d="$(fresh ug)"; mkdir -p "$d/empty"; chown "$O" "$d/empty"
  own php -d display_errors=stderr -- "$d/empty" "$SB/plugin" < "$T" > "$d/out" 2>&1; RC=$?
  chk "T3b no plugin.sqlite3: refused (2), and no database is created" bash -c "[ $RC = 2 ] && grep -q 'no plugin.sqlite3' '$d/out' && [ -z \"\$(ls -A '$d/empty')\" ]"
  for opt in --commit --write --adjustment-id=x; do
    d="$(fresh ug)"; p0 "$d" "$T" "$opt"
    chk "T3c option $opt is refused (2)" bash -c "[ $RC = 2 ] && grep -q 'unknown option' '$d/out'"
  done
  d="$(fresh ug)"; own php -d display_errors=stderr -- "$d/live" < "$T" > "$d/out" 2>&1; RC=$?
  chk "T3d one folder only: usage (2)" bash -c "[ $RC = 2 ] && grep -q 'usage:' '$d/out'"
  for s in -wal -shm -journal; do
    d="$(fresh ug)"; : > "$d/live/plugin.sqlite3$s"; chown "$O" "$d/live/plugin.sqlite3$s"; snap "$d" > "$d/s0"
    p0 "$d" "$(waitcopy 1 "$T")"; snap "$d" > "$d/s1"
    chk "T3e a $s left beside the book with nobody holding it: P0 waits, refuses (2), changes nothing" bash -c "[ $RC = 2 ] && grep -q 'kept the book open' '$d/out' && cmp -s '$d/s0' '$d/s1'"
  done
  d="$(fresh ug)"; snap "$d" > "$d/s0"
  own env DN_VAULT_FILE="$d/vault.json" php -d display_errors=stderr -d open_basedir="$d:$SB" -- "$d/live" "$SB/plugin" < "$T" > "$d/out" 2>&1; RC=$?; snap "$d" > "$d/s1"
  chk "T3f a PHP that will not open an SQLite URI (open_basedir): refused (2), no file made" bash -c "[ $RC = 2 ] && grep -q 'could not be opened read-only and immutable' '$d/out' && cmp -s '$d/s0' '$d/s1'"
  d="$(fresh ug)"; rm -f "$d/live/plugin.sqlite3"; own php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("CREATE TABLE other(x)");' "$d/live/plugin.sqlite3"
  p0 "$d" "$T"; chk "T3g a database without cb_ledger: refused (2)" bash -c "[ $RC = 2 ] && grep -q 'no cb_ledger table' '$d/out'"
  d="$(fresh ug)"; rm -f "$d/live/plugin.sqlite3"   # a cb_ledger with every column P0 reads but ssp_amount
  own php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("CREATE TABLE cb_ledger (id INTEGER PRIMARY KEY, date, direction, amount, currency, status, project, category, description, source, account_id, txn_type, validation_ref)");' "$d/live/plugin.sqlite3"
  p0 "$d" "$T"; chk "T3h cb_ledger without a column it reads: refused (2)" bash -c "[ $RC = 2 ] && grep -q 'has no ssp_amount column' '$d/out'"
  d="$(fresh ug)"; mkdir -p "$d/a?b"; cp -a "$d/live/plugin.sqlite3" "$d/a?b/"; chown -R "$O" "$d/a?b"
  own php -d display_errors=stderr -- "$d/a?b" "$SB/plugin" < "$T" > "$d/out" 2>&1; RC=$?
  chk "T3i a path SQLite cannot carry in a URI: refused (2)" bash -c "[ $RC = 2 ] && grep -q 'cannot carry' '$d/out'"
}

# ── T4: a writer between the two readings ──────────────────────────────────────────────────────────────────────────
pausecopy() {   # a copy that touches <dir>/paused between the readings and waits for <dir>/go (anchor asserted)
  local c="$SB/pause.$(basename "$2").php"
  awk '{ print } /^\$r1 = readBook\(/ { print "touch(dirname($db, 2) . \"/paused\"); for ($i = 0; $i < 200 && !is_file(dirname($db, 2) . \"/go\"); $i++) usleep(50000);" }' "$2" > "$c"
  [ "$(grep -c '/paused"); for' "$c")" = 1 ] || { echo "pausecopy: anchor not found"; exit 3; }
  echo "$c"
}
writer() {      # writer <dir> <mode>: close | keep | other — as the owner, once P0 pauses (hold.php's writer modes)
  own php "$H/hold.php" "$1/live/plugin.sqlite3" "$1/paused" 4 "$2" "$1/go" &
  holders+=($!); WRITER=$!
}
failcopy() {    # a copy whose reading meets a query SQLite cannot answer, after the accounts (anchor asserted)
  local c="$SB/fail.$(basename "$2").php"
  awk '{ print } /^    \/\/ ── The 12 Sep adjustment/ { print "    q($pdo, \"SELECT * FROM rh_no_such_table\");" }' "$2" > "$c"
  [ "$(grep -c 'rh_no_such_table' "$c")" = 1 ] || { echo "failcopy: anchor not found"; exit 3; }
  echo "$c"
}
t4() {
  local T="$1" P d m F i rcs bursts
  d="$(fresh ug)"; F="$(failcopy x "$T")"
  own env -i PATH=/usr/bin:/bin DN_VAULT_FILE="$d/vault.json" php -d display_errors=stderr -d log_errors=1 -d error_log="$d/php-errors.log" -- "$d/live" "$SB/plugin" < "$F" > "$d/out" 2>&1; RC=$?
  chk "T4e a reading SQLite cannot finish ends as 'run again' (3), not an uncaught error; SQLite's words are not printed; even with log_errors on, no error log is written" \
    bash -c "[ $RC = 3 ] && grep -q 'SQLite could not finish reading the book (PDOException, code 1)' '$d/out' && ! grep -qiE 'fatal|uncaught|no such table|malformed' '$d/out' && [ ! -e '$d/php-errors.log' ]"
  d="$(fresh ug)"
  own php "$H/grow.php" "$d/live/plugin.sqlite3" 30000
  rm -f "$d/ready"; own php "$H/stress.php" "$d/live/plugin.sqlite3" 25 "$d/ready" > "$d/bursts" & holders+=($!); local SP=$!
  i=0; while [ ! -f "$d/ready" ] && [ $i -lt 100 ]; do sleep 0.05; i=$((i+1)); done
  rcs=""; local W5; W5="$(waitcopy 5 "$T")"
  for i in 1 2 3 4 5 6 7 8 9 10 11 12; do p0 "$d" "$W5"; rcs="$rcs $RC"; cat "$d/out" >> "$d/all.out"; done
  wait "$SP" 2>/dev/null; bursts="$(cat "$d/bursts" 2>/dev/null)"
  chk "T4f twelve readings against a writer that closes the book every few hundred ms ($bursts bursts): every exit is 0, 2 or 3 (were:$rcs), never an uncaught error" \
    bash -c "! echo '$rcs' | grep -qE '(^| )(1|[4-9]|[0-9]{2,})( |\$)' && ! grep -qiE 'fatal|uncaught|malformed' '$d/all.out'"
  chk "T4f the book is intact afterwards (integrity_check ok)" bash -c "[ \"\$(php -r '\$p=new PDO(\"sqlite:\".\$argv[1]); echo \$p->query(\"PRAGMA integrity_check\")->fetchColumn();' '$d/live/plugin.sqlite3')\" = ok ]"
  echo "  note T4f exits:$rcs (3 = the writer was caught mid-reading; 0 = a clean moment)"
  P="$(pausecopy x "$T")"
  d="$(fresh ug)"; ( sleep 0.5; : > "$d/go" ) & p0 "$d" "$P"
  chk "T4 control: the instrumented copy with no writer finishes (0)" bash -c "[ $RC = 0 ] && grep -q 'P0: DONE' '$d/out'"
  for m in close keep other; do
    d="$(fresh ug)"; writer "$d" "$m"; p0 "$d" "$P"; wait "$WRITER" 2>/dev/null
    case "$m" in
      close) chk "T4 a writer that adds a row and closes between the readings: failed (3), readings DIFFERENT" bash -c "[ $RC = 3 ] && grep -q 'the readings are DIFFERENT' '$d/out' && grep -q 'may not be one moment' '$d/out'" ;;
      keep)  chk "T4 a writer still holding the book at the end: failed (3), side files APPEARED" bash -c "[ $RC = 3 ] && grep -q 'side files APPEARED' '$d/out'" ;;
      other) chk "T4 a writer to another table only: the readings agree, yet the file MOVED: failed (3)" bash -c "[ $RC = 3 ] && grep -q 'the readings are identical' '$d/out' && grep -q 'MOVED during the reading' '$d/out'" ;;
    esac
  done
}

# ── T5: the source ─────────────────────────────────────────────────────────────────────────────────────────────────
src_ok() { php "$H/srccheck.php" "$1"; }   # 0 when every static rule holds
src_bad() {     # src_bad <copy> <tool>: the copy differs from the tool AND srccheck refuses it (exit 1 exactly: a PHP
                # error, exit 255, or a missing command, 127, is never taken for a refusal). A shell function, so it is
                # called directly: inside `bash -c` it would not exist, and `!` would turn "command not found" into a pass
  local rc; ! cmp -s "$1" "$2" || return 1
  php "$H/srccheck.php" "$1" > /dev/null 2>&1; rc=$?; [ "$rc" = 1 ]
}
t5() {
  local T="$1" c
  chk "T5 the tool keeps every static rule" src_ok "$T"
  c="$SB/plant.php"; { cat "$T"; printf '%s\n' '// a comment changes no rule'; } > "$c"
  chk "T5 control on the controls: a copy that differs only by a comment keeps every rule (srccheck exit 0)" src_ok "$c"
  chk "T5 control on the controls: … and src_bad does not take it for a refusal, so a refusal below is srccheck's own" eval "! src_bad '$c' '$T'"
  for plant in 'require "x.php";' 'new CashbookService(1, 2);' '$q = $x->query("SELECT 1");' 'file_put_contents("/tmp/x", "y");' '$y = `id`;' '$z = $w->exec("DELETE FROM cb_ledger");'; do
    c="$SB/plant.php"; { cat "$T"; echo "$plant"; } > "$c"
    chk "T5 control: a copy with  $plant  is caught" src_bad "$c" "$T"
  done
  c="$SB/plant.php"; sed 's/PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,//' "$T" > "$c"
  chk "T5 control: a copy without SQLITE_OPEN_READONLY is caught" src_bad "$c" "$T"
  c="$SB/plant.php"; sed "s/?immutable=1'/'/" "$T" > "$c"
  chk "T5 control: a copy without immutable=1 is caught" src_bad "$c" "$T"
}

# ── T6: the wrapper through a fake docker ──────────────────────────────────────────────────────────────────────────
wsetup() {      # wsetup <book>: a server-shaped /data mount and a checkout copy; sets W, M, PL, PD
  W="$SB/w$((n=n+1))"; M="$W/mnt"; PL="$M/ucrm/data/plugins/dishnet-hybrid-sudan"; PD="$M/ucrm/data/plugins/.dishnet-hybrid-sudan-data"
  mkdir -p "$PL/data" "$W/repo/scripts" "$W/bin"
  cp -a "$SB/plugin/lib" "$SB/plugin/migrations" "$PL/"; echo '{"version":"5.18.94"}' > "$PL/manifest.json"
  printf 'f4b4c96\n' > "$PL/.deployed-commit"
  printf '{"pluginDataDir":"/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/"}\n' > "$PL/ucrm.json"
  cp -a "$SB/books/$1/live" "$PD"
  cp -a "$SB/books/$1/vault.json" "$M/ucrm/data/plugins/.dishnet-sudan.vault.json"
  chown -R "$O" "$M"
  cp "$WRAP_T" "$W/repo/scripts/bank-recon-p0.sh"; cp "$TOOL_T" "$W/repo/scripts/bank-recon-p0.php"
  git -C "$W/repo" init -q && git -C "$W/repo" add -A && git -C "$W/repo" -c user.name=rh -c user.email=rh@example.invalid commit -qm p0 \
    || { echo "wsetup: git failed"; exit 3; }
  cp "$H/fake-docker.sh" "$W/bin/docker"; chmod +x "$W/bin/docker"
}
wrun() {        # wrun [args…]: the wrapper as the operator runs it; rc in $RC, output in $W/out, trace in $W/trace
  : > "$W/trace"
  env PATH="$W/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$W/trace" FAKE_RUNNING="${FAKE_RUNNING:-true}" bash "$W/repo/scripts/bank-recon-p0.sh" "$@" > "$W/out" 2>&1
  RC=$?
}
p0ran()   { grep -q '^exec .* php -d display_errors=stderr' "$W/trace"; }
t6() {
  local WRAP_T="$1" TOOL_T="$2" s0
  wsetup ug; ( cd "$M" && find . -printf '%p|%i|%s|%T@|%u:%g|%m\n' | sort; find . -type f -exec sha256sum {} + | sort ) > "$W/s0"; wrun
  ( cd "$M" && find . -printf '%p|%i|%s|%T@|%u:%g|%m\n' | sort; find . -type f -exec sha256sum {} + | sort ) > "$W/s1"
  chk "T6a the wrapper runs P0 and exits 0" bash -c "[ $RC = 0 ] && grep -q 'P0: DONE' '$W/out' && grep -q 'P0 finished (exit 0)' '$W/out'"
  chk "T6a it names the installed commit, the data folder and the owner" bash -c "grep -q 'installed       f4b4c96 in ucrm; data /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data; database owner 1000:1000\$' '$W/out'"
  chk "T6a it names this checkout's commit, both P0 files as committed, and the wrapper's sha256" bash -c "grep -q \"this checkout   \$(git -C '$W/repo' rev-parse --short HEAD), both P0 files as committed; wrapper sha256 \$(sha256sum '$W/repo/scripts/bank-recon-p0.sh' | cut -c1-16)…\" '$W/out'"
  chk "T6a every docker call was one the strict fake allows; none refused" bash -c "! grep -q '^REFUSED' '$W/trace' && [ \$(grep -c '^inspect ' '$W/trace') = 2 ]"
  chk "T6a its closing line says what the log holds" bash -c "grep -q 'no name, description, person, reference or customer' '$W/out'"
  chk "T6a P0 ran as the owner, on standard input, with no file copied into the container" bash -c "grep -q '^exec 1000:1000 stdin php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- ' '$W/trace' && ! grep -q '^exec root stdin' '$W/trace' && ! grep -q ' cp ' '$W/trace'"
  chk "T6a its figures equal the plugin's own" php "$H/check.php" "$W/out" "$SB/books/ug/exp.json" yes
  chk "T6a nothing under the mount changed" cmp -s "$W/s0" "$W/s1"
  chk "T6a nothing planted is printed" noleak "$W/out"
  chk "T6a control: the trace test the refusals below use ('P0 not run') sees this run of P0" p0ran
  wsetup two-adj; wrun "--adjustment-id=$ADJ2"
  chk "T6b --adjustment-id reaches P0" bash -c "[ $RC = 0 ] && grep -q 'named by --adjustment-id: id $ADJ2' '$W/out'"
  wsetup ug; wrun --commit
  chk "T6c an unknown option (--commit): exit 2, docker never called" bash -c "[ $RC = 2 ] && grep -q 'unknown option: --commit' '$W/out' && [ ! -s '$W/trace' ]"
  wsetup ug; : > "$W/trace"; env PATH="$W/bin:$PATH" FAKE_MNT="$M" FAKE_TRACE="$W/trace" setpriv --reuid=1000 --regid=1000 --clear-groups bash "$W/repo/scripts/bank-recon-p0.sh" > "$W/out" 2>&1; RC=$?
  chk "T6d not root: STOP, P0 not run" bash -c "[ $RC = 1 ] && grep -q 'run this as root' '$W/out' && ! grep -q 'php -d' '$W/trace'"
  wsetup ug; echo '// edited' >> "$W/repo/scripts/bank-recon-p0.php"; wrun
  chk "T6e an edited tool: STOP (sha256), P0 not run" bash -c "[ $RC = 1 ] && grep -q 'not the reviewed version' '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; FAKE_RUNNING=false wrun
  chk "T6f the container not running: STOP" bash -c "[ $RC = 1 ] && grep -q 'is not running' '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; rm -f "$PL/manifest.json"; wrun
  chk "T6g no installed plugin: STOP" bash -c "[ $RC = 1 ] && grep -q 'no installed plugin' '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; printf '{"pluginDataDir":"/data/ucrm/data/plugins/dishnet-hybrid-sudan/data"}\n' > "$PL/ucrm.json"; cp -a "$PD/plugin.sqlite3" "$PL/data/"; chown -R "$O" "$PL"; wrun
  chk "T6h ucrm.json naming <plugin>/data: STOP before P0" bash -c "[ $RC = 1 ] && grep -q \"stray store's old place\" '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; printf '{}\n' > "$PL/ucrm.json"; chown "$O" "$PL/ucrm.json"; wrun
  chk "T6i ucrm.json without a data folder: the folder beside the plugin is used, P0 runs (0)" bash -c "[ $RC = 0 ] && grep -q 'data /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data' '$W/out'"
  wsetup ug; chown 0:0 "$PD/plugin.sqlite3"; wrun
  chk "T6j a root-owned database: STOP, P0 not run" bash -c "[ $RC = 1 ] && grep -q 'owner is 0:0' '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; rm -f "$PD/plugin.sqlite3"; own php -r '$p=new PDO("sqlite:".$argv[1]); $p->exec("CREATE TABLE other(x)");' "$PD/plugin.sqlite3"; wrun
  chk "T6k P0's refusal comes through: exit 2" bash -c "[ $RC = 2 ] && grep -q 'no cb_ledger table' '$W/out' && grep -q 'P0 refused (exit 2)' '$W/out'"
  wsetup ug; rm -f "$PD/plugin.sqlite3"; wrun
  chk "T6m no database in the named data folder: STOP says so, P0 not run" bash -c "[ $RC = 1 ] && grep -q 'no plugin.sqlite3 in /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data' '$W/out' && ! grep -q '^exec .* php -d display_errors=stderr' '$W/trace'"
  wsetup ug; echo '# edited' >> "$W/repo/scripts/bank-recon-p0.sh"; wrun
  chk "T6n an edited wrapper is named in the log" bash -c "[ $RC = 0 ] && grep -q '1 P0 file(s) EDITED' '$W/out'"
  wsetup ug; wrun --adjustment-id=12345678901
  chk "T6o an --adjustment-id of 11 digits: exit 2, docker never called" bash -c "[ $RC = 2 ] && grep -q 'at most 10 digits' '$W/out' && [ ! -s '$W/trace' ]"
}

suite() {       # suite <tool> <wrapper>: everything; returns the failures it added
  local f0=$fails
  t1 "$1"; t2 "$1"; t3 "$1"; t4 "$1"; t5 "$1"; t6 "$2" "$1"
  return $((fails - f0 > 0 ? 1 : 0))
}

echo "== the reviewed files =="
SHA="$(sha256sum "$TOOL0" | cut -c1-64)"
chk "the wrapper pins the tool's sha256 ($SHA)" grep -q "^TOOL_SHA=\"$SHA\"\$" "$WRAP0"
chk "both files pass the syntax checks" bash -c "php -l '$TOOL0' >/dev/null && bash -n '$WRAP0'"
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
echo; echo "== weakened copies =="
mutant write-guards-off tool 's/PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,\n//; s/\$pdo->exec\(\x27PRAGMA query_only = ON\x27\);\n//; s/\x27sqlite:file:\x27 \. \$db \. \x27\?immutable=1\x27/\x27sqlite:\x27 . \$db/; s/(\$pdo->exec\(\x27BEGIN\x27\);)/$1 \$pdo->exec("UPDATE cb_ledger SET updated_at = updated_at");/' "t1 t5"
mutant no-readonly-flag tool 's/PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,\n//' "t1 t5"
mutant no-query-only tool 's/\$pdo->exec\(\x27PRAGMA query_only = ON\x27\);\n//' "t1"
mutant plain-open tool 's/\x27sqlite:file:\x27 \. \$db \. \x27\?immutable=1\x27/\x27sqlite:\x27 . \$db/' "t1 t5"
mutant no-wait tool 's/^while \(sides\(\$db\)\) \{/while (false) {/m' "t2"
mutant pending-excluded tool 's/status NOT IN \(\x27voided\x27,\x27voided_reconcile\x27\)"/status NOT IN (\x27voided\x27,\x27voided_reconcile\x27,\x27pending_approval\x27)"/' "t1"
mutant blank-currency-excluded tool 's/\(l\.currency = :c OR l\.currency IS NULL OR l\.currency = \x27\x27\)/(l.currency = :c)/g' "t1"
mutant case-blind-currency tool 's/: \x27l\.currency = :c\x27;/: \x27UPPER(l.currency) = :c\x27;/g; s/\(l\.currency = :c OR/(UPPER(l.currency) = :c OR/g' "t1"
mutant ssp-uses-amount tool 's/\$cur === \x27SSP\x27 \? \x27COALESCE\(l\.ssp_amount,0\)\x27 : \x27l\.amount\x27/\x27l.amount\x27/g' "t1"
mutant adjustment-unassigned tool 's/CASE WHEN l\.id = :adj THEN \x27ADJ\x27/CASE WHEN l.id = -:adj THEN \x27ADJ\x27/' "t1"
mutant adjustment-description tool 's/(\$o\("  12 Sep adjust\.  \{\$adjHow\}: id \{\$adjId\}, ")/$1 . \$adj[\x27description\x27] . " " /' "t1"
mutant names-printed tool 's/SELECT a\.id, a\.kind, a\.currency, a\.active,/SELECT a.id, a.name, a.kind, a.currency, a.active,/; s/\(int\)\$a\[\x27n\x27\]\)\);/(int)\$a[\x27n\x27]) . \x27  \x27 . \$a[\x27name\x27]);/' "t1"
mutant raw-category tool 's/(\x27, category \x27 \. )\(in_array\(\(string\)\$adj\[\x27category\x27\], CAPITAL_CATEGORIES, true\) \? \x27a capital one\x27 : \x27not a capital one\x27\)/$1\$adj[\x27category\x27]/' "t1"
mutant no-whitelist tool 's/\{ return in_array\(\(string\)\$v, \$allowed, true\) \? \(string\)\$v : \x27\(other\)\x27; \}/{ return (string)\$v; }/' "t1"
mutant no-catch tool 's/\} catch \(Throwable \$e\) \{\n        \$code/} catch (LogicException \$e) {\n        \$code/' "t4"
mutant no-temp-store tool 's/        \$pdo->exec\(\x27PRAGMA temp_store = MEMORY\x27\);\n//; s/    if \(\(int\)\(q\(\$pdo, \x27SELECT \* FROM pragma_temp_store\x27\)[^\n]*\n//' "t1 t5"
mutant no-second-reading tool 's/\$r2 = readBook\(\$db, \$base, \$curs, \$eff, \$cfgFrom, \$adjArg\);/\$r2 = \$r1;/' "t4"
mutant no-file-check tool 's/ \|\| \$after !== \$before \|\| \$sidesNow\) \{/) {/' "t4"
mutant effective-merge tool 's/\$cfg\[\$k\] = \$d\[\$k\]; \$cfgFrom\[\$k\] = basename\(\$p\);/if (!(is_string(\$d[\$k]) \&\& trim(\$d[\$k]) === \x27\x27 \&\& isset(\$cfg[\$k]))) \$cfg[\$k] = \$d[\$k]; \$cfgFrom[\$k] = basename(\$p);/' "t1"
mutant no-vault tool 's/if \(is_file\(\$vaultFile\)\) \{/if (false) {/' "t1"
mutant no-settings-row tool 's/foreach \(\$rowCurs as \$c\) if \(!in_array\(\$c, \$curs, true\)\) \$curs\[\] = \$c;//' "t1"
mutant no-stray-refusal tool 's/if \(\$plugReal !== false && \$dataReal === rtrim\(\$plugReal, \x27\/\x27\) \. \x27\/data\x27\) \{/if (false) {/' "t3"
mutant wrap-no-sha wrap 's/^\[ "\$\(sha256sum "\$TOOL" \| cut -c1-64\)" = "\$TOOL_SHA" \] \|\| stop[^\n]*\n//m' "t6"
mutant wrap-root-owner wrap 's/\x27\x27\|0:\*\|\*:\*:\*\)/\x27\x27|*:*:*)/' "t6"
mutant wrap-stray-folder wrap 's/^\[ "\$\{PDD_IN%\/\}" != "\$IN_CONTAINER\/data" \] \|\| stop[^\n]*\n//m' "t6"
mutant wrap-as-root wrap 's/docker exec -i -u "\$U" "\$CONTAINER" php/docker exec -i "\$CONTAINER" php/' "t6"
mutant wrap-not-root-ok wrap 's/^\[ "\$\(id -u\)" = "0" \] \|\| stop[^\n]*\n//m' "t6"
mutant wrap-log-errors wrap 's/ -d log_errors=0//' "t6"
mutant wrap-no-db-check wrap 's/^dx "\$CONTAINER" test -f [^\n]*\n//m' "t6"
mutant wrap-no-adj-bound wrap 's/^ *\[ "\$\{#ADJ\}" -le 10 \] \|\| \{[^\n]*\n/ ;;\n/m' "t6"
echo; echo "== total: $pass ok, $fails failed (the suite on the reviewed files: $MAIN_FAILS failed) =="
[ "$fails" = 0 ]
