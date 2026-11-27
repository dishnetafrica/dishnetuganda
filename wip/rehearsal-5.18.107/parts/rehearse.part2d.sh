# ── 107's readers of the live book and its log (rh107q.py, read-only: mode=ro, query_only) and the keep folder every plant
# keeps what it changes in (rh107-seed.php refuses an undo whose keep is not there). The book is checkpointed first, so a
# reader sees what the WAL holds. A reader that fails prints one line: its own unread:<Error> (exit 3), else unread. ──
ledger107() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q107 ledger "$DATA/plugin.sqlite3" 2>/dev/null || { [ $? -eq 3 ] || echo unread; }; }    # mig092=N wa_no_ai=table|renamed|none
ledgerx107() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q107 ledgerx "$DATA/plugin.sqlite3" 2>/dev/null || { [ $? -eq 3 ] || echo unread; }; }  # … cks092= at092= sc092= cks071= sentinel= rows=
log107() { q107 log092 "$DATA/migration.log" 2>/dev/null || { [ $? -eq 3 ] || echo unread; }; }                                                               # MG-CA2's reading of migration.log
bookdig107() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q107 bookdig "$DATA/plugin.sqlite3" 2>/dev/null || { [ $? -eq 3 ] || echo unread; }; }  # the book's logical digest
ms107() { sqx "PRAGMA wal_checkpoint(TRUNCATE)" >/dev/null 2>&1; q107 sched "$DATA/plugin.sqlite3" 2>/dev/null || { [ $? -eq 3 ] || echo unread; }; }   # budget=… entries=… (master's own record)
logsha107() { sha256sum < "$DATA/migration.log" 2>/dev/null | cut -c1-16; }
KEEP107="$R107/kept"; mkdir -p "$KEEP107"; chown 1000:1000 "$KEEP107"; chmod 700 "$KEEP107"
kept107() { find "$KEEP107" -mindepth 1 2>/dev/null | wc -l | tr -d ' '; }   # what is still kept: 0 once every plant is undone
# seedk107 ROOT OP TAG [JSON-FIELDS] — one op of rh107-seed.php with this rehearsal's keep folder and TAG; its line
seedk107() { seed107 "$1" "[{\"op\":\"$2\",\"keep\":\"$KEEP107\",\"tag\":\"$3\"${4:+,$4}}]"; }
B0X_107="$(bookdig107)"; L0X_107="$(logsha107)"
check "$( [ "${#B0X_107}" = 16 ] && echo read)|$( [ "${#L0X_107}" = 16 ] && echo read)|$(kept107)|$(ledger107)" "read|read|0|mig092=0 wa_no_ai=none" \
  "107-infra n this rehearsal's own readers answer on the live book and its log (the book's digest, migration.log's sha256), the keep folder is empty, and the ledger holds neither 092 nor wa_no_ai"
