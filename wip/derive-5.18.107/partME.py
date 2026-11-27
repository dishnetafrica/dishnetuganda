# Part ME — after the copy, and --after-only (root docs/76 §T.6, §W.7):
#   ME-CA1 reads master's own record: master_schedule in the live store, read-only, as the book's owner, as R19's
#   slr_last_run reads it (MS_PHP) — PASS when each of the four follow-up jobs started after the copy and returned to
#   master; FAIL when one is at (L, -1) after the copy and a later cycle has begun; in --after-only, a poll for master's
#   next cycle when one is at (L, -1) and no entry is later; NOT YET otherwise (a note in the deploy run, the summary's
#   NOT YET in --after-only); an unreadable schedule a NOT ESTABLISHED note, never a PASS. 5.18.106's plugin.log count
#   (ME_PHP) is carried unchanged as the caught-error half: its FAIL stays a FAIL; its NOT ESTABLISHED becomes the
#   verdict line's clause.
#   ME-CA2 (new): seven other entries whose code 5.18.107 changes, in the same read, FAIL-only.
#   The NOT YET summary state is carried (TD3), re-pointed from UJ-ON to ME-CA1 (UJ_NY_* → ME_NY_*). UJ-CA and UJ-ON
#   themselves went with partSA (one block with U1–U4).
import re
from d107lib import git

ME_HEAD = "# ── 5.18.106: master's errors from the cycle the copy straddled (ME-CA1; root docs/75 §L.5, DL10) ─────────────────────"
ME_JOBS = ["followup_close", "followup_scan", "followup_run", "followup_send"]
ME_INTERVALS = {"followup_close": 600, "followup_scan": 600, "followup_run": 600, "followup_send": 300}
# ME-CA2's seven entries, each with the changed file that puts it in (root docs/76 §T.6); the derivation asserts each entry
# is in master's table at the pin and each named file is in 5.18.107's delta — it does not compute the criterion
ME2 = [("event_processor", ["cron/event_processor.php"]), ("ai_reply", ["workers/AiReplyWorker.php"]),
       ("wa_watchdog", ["cron/wa_watchdog.php"]), ("customer_reminders", ["cron/customer_reminders.php", "lib/WinBack.php"]),
       ("quote_chase", ["lib/QuoteChase.php"]), ("ai_media", ["workers/MediaWorker.php"]),
       ("offers_send", ["lib/OfferAudience.php"])]

NEW_HEAD = r"""# ── 5.18.107: master's own record after the copy (ME-CA1, ME-CA2; root docs/76 §T.6, §W.7) ─────────────────────────────
# 5.18.106's ME-CA1 looked for [master] lines in <plugin>/data/plugin.log, where master writes none (master_log() only
# echoes; an admin page that runs master discards its output): both server runs read NOT ESTABLISHED (22 and 23 Nov).
# Since 5.18.107 its verdict is master's own record — master_schedule in the live store, read-only, as the book's owner,
# as R19's slr_last_run reads it, never a copy (MS_PHP). Master writes a job's entry (last_run, the start of ITS process,
# a UTC epoch; duration_ms -1) before it includes the job and the duration after the job returns, saving after each, and
# holds its lock while it runs: so an entry later than L means the process that wrote L has ended.
#   ME-CA1, the four follow-up jobs (followup_close, followup_scan, followup_run, followup_send). Master dispatches all
#   four whether or not followup_enabled is on — they return early inside — so PASS does not depend on that setting:
#     PASS     each with last_run later than COPY_AT and duration_ms 0 or more: each started in a cycle that began after
#              the copy and returned to master. The cycle the copy straddled is excluded by construction;
#     FAIL     one at (L, -1), L later than COPY_AT, once an entry later than L shows a later cycle has begun;
#     the poll, in --after-only only: one at (L, -1), L later than COPY_AT and no entry later than L — re-read every 5 s,
#              read-only, until an entry later than L appears or master_budget_seconds (as master reads it: the store's
#              kyc_config row, (int)(… ?? 300)) + 300 s (one heartbeat, documented, not measured) + 60 s pass. At the
#              read where it stops: still exactly (L, -1), a new cycle begun — FAIL; still so with none — NOT YET, master
#              did not start one; a newer last_run — NOT YET, the poll missed the window; 0 or more at L — the PASS
#              rules. Whether the window before followup_send's next dispatch is always longer than 5 s is NOT
#              ESTABLISHED, so the check is never called conclusive;
#     NOT YET  otherwise: a note in the deploy run (no full cycle may have begun, and no poll is made); in --after-only the
#              summary's NOT YET (exit status 3), with the time to run it again — the later of COPY_AT + 1,200 s and the
#              newest last_run seen + 900 s (the 600-s interval plus one heartbeat). A -1 at or before COPY_AT, in the
#              cycle the copy straddled, is a note, as in 5.18.106. An unreadable schedule is a NOT ESTABLISHED note in
#              capitals, never a PASS (root docs/75 DL10).
#   The caught-error half, 5.18.106's plugin.log count carried unchanged (ME_PHP, below): master's try/catch records
#   duration_ms 0 or more for a job that threw, so where the log's control does not hold the verdict line says errors
#   master caught are not judged, and why; where it holds, an ERROR after the straddling cycle is a FAIL of its own.
#   ME-CA2, in the same read and in both modes, FAIL-only: seven other entries whose code 5.18.107 changes —
#   event_processor (its script), ai_reply (workers/AiReplyWorker.php), wa_watchdog (its script), customer_reminders (its
#   script and lib/WinBack.php), quote_chase (lib/QuoteChase.php), ai_media (workers/MediaWorker.php) and offers_send (its
#   unchanged lib/OfferSender.php requires lib/OfferAudience.php, which changes and decides who an offer goes to). FAIL
#   when one is exactly (L, -1), L later than COPY_AT, and an entry is later than L; every other reading is a note — no
#   poll and no NOT YET. The 29 more entries that load a changed file are left out (known limits): most only through
#   lib/NotificationService.php or lib/EvolutionApiService.php, and they reach uCRM, Splynx, the LTE and fibre APIs,
#   Google Drive and mail — a death 5.18.107 did not cause would roll back a correct deploy.
# The caught-error half, as 5.18.106 wrote it:
"""

NEW_FUNCS = r"""# MS_PHP — ME-CA1's and ME-CA2's read of master's own record: PDD JOB… — master_schedule's row in the live store, opened
# read-only (SQLITE_OPEN_READONLY, query_only) as R19's slr_last_run opens it, and master_budget_seconds as master reads it
# (the store's kyc_config row, (int)(… ?? 300)). One line: "ms=read newest=<the newest last_run over every entry>
# budget=<s> entries=<n>", then each JOB "=<last_run>:<duration_ms>" ("-" without an entry, "?" for a value that is not a
# number); or "ms=absent", or "ms=?<why>". Epochs and durations only, never a job's output. PHP 7.4 syntax.
MS_PHP="$(cat <<'PHP'
try {
    $p = new PDO('sqlite:' . rtrim((string)($argv[1] ?? ''), '/') . '/plugin.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $p->exec('PRAGMA query_only = ON');
    $has = function (string $t) use ($p): bool { $s = $p->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"); $s->execute([$t]); return $s->fetchColumn() !== false; };
    if (!$has('master_schedule')) { echo 'ms=absent'; exit(0); }
    $j = $p->query('SELECT data FROM master_schedule WHERE id = 0 LIMIT 1')->fetchColumn();
    if (!is_string($j)) { echo 'ms=absent'; exit(0); }
    $d = json_decode($j, true);
    if (!is_array($d)) { echo 'ms=?json'; exit(0); }
    $newest = 0;
    foreach ($d as $e) if (is_array($e) && is_numeric($e['last_run'] ?? null)) $newest = max($newest, (int)$e['last_run']);
    $b = 300;
    if ($has('kyc_config')) {
        $c = $p->query('SELECT data FROM kyc_config WHERE id = 0 LIMIT 1')->fetchColumn();
        $c = is_string($c) ? json_decode($c, true) : null;
        if (is_array($c)) $b = (int)($c['master_budget_seconds'] ?? 300);
    }
    $o = ['ms=read', 'newest=' . $newest, 'budget=' . $b, 'entries=' . count($d)];
    foreach (array_slice($argv, 2) as $job) {
        $e = $d[$job] ?? null;
        if (!is_array($e)) { $o[] = $job . '=-'; continue; }
        $l = is_numeric($e['last_run'] ?? null) ? (string)(int)$e['last_run'] : '?';
        $u = is_numeric($e['duration_ms'] ?? null) ? (string)(int)round((float)$e['duration_ms']) : '?';
        $o[] = $job . '=' . $l . ':' . $u;
    }
    echo implode(' ', $o);
} catch (Throwable $e) { echo 'ms=?', get_class($e); }
PHP
)"
ME_JOBS="followup_close followup_scan followup_run followup_send"   # ME-CA1's four
ME2_JOBS="event_processor ai_reply wa_watchdog customer_reminders quote_chase ai_media offers_send"   # ME-CA2's seven
ME_LOG=""; ME_LOG_ERR=""; ME_LOG_WHY=""; ME_NY_AT=""; ME_NY_SAY=""
me_num() { case "$1" in ''|*[!0-9]*) return 1 ;; esac; return 0; }   # me_num X — status 0 when X is a whole number
# me_sched — MS_PHP's line for ME-CA1's four and ME-CA2's seven (the job lists are plain words, split on purpose)
me_sched() {
  [ -n "$DB_OWNER" ] || { echo "ms=?owner"; return 0; }
  docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -r "$MS_PHP" "$PDD_IN" $ME_JOBS $ME2_JOBS 2>/dev/null </dev/null || echo "ms=?exec"
}
# me_entry LINE JOB — one entry as text: "<job> <last_run, UTC> (<duration_ms> ms)", "<job> no entry" or "<job> unreadable"
me_entry() {
  local e; e="$(kv "$1" "$2")"
  case "$e" in
    -|'') printf '%s no entry' "$2" ;;
    *:*) if me_num "${e%%:*}"; then printf '%s %s (%s ms)' "$2" "$(utc_of "${e%%:*}")" "${e#*:}"; else printf '%s unreadable' "$2"; fi ;;
    *) printf '%s unreadable' "$2" ;;
  esac
}
me_entries() { local j s=""; for j in $2; do s="$s${s:+, }$(me_entry "$1" "$j")"; done; printf '%s' "$s"; }   # me_entries LINE JOBS
# me_judge LINE COPYUNIX — ME-CA1 on one read: "pass"; "fail <jobs>"; "wait <L>" (a job at (L, -1) after the copy and no
# entry later than L: what the poll waits on); "notyet" (a job not started since the copy, or without an entry); or
# "unread <why>"
me_judge() {
  local o="$1" cu="$2" n j e l d fail="" wait="" ny=""
  n="$(kv "$o" newest)"; me_num "$n" || { echo "unread newest"; return 0; }
  for j in $ME_JOBS; do
    e="$(kv "$o" "$j")"
    case "$e" in -|'') ny=1; continue ;; *:*) ;; *) echo "unread $j"; return 0 ;; esac
    l="${e%%:*}"; d="${e#*:}"
    me_num "$l" || { echo "unread $j"; return 0; }
    [ "$d" = "-1" ] || me_num "$d" || { echo "unread $j"; return 0; }
    if [ "$l" -le "$cu" ]; then ny=1
    elif [ "$d" = "-1" ]; then if [ "$n" -gt "$l" ]; then fail="$fail $j"; else wait="$l"; fi; fi
  done
  if [ -n "$fail" ]; then echo "fail$fail"; elif [ -n "$wait" ]; then echo "wait $wait"; elif [ -n "$ny" ]; then echo "notyet"; else echo "pass"; fi
}
# me_after_poll FIRST LAST COPYUNIX L END — the verdict at the read where the poll stopped (END "cycle": an entry later
# than L appeared; "bound": none within the bound). Each job that read exactly (L, -1) at the first read: still exactly
# that with a new cycle begun — FAIL (me_judge says it); still so with none — NOT YET, master did not start one; a newer
# last_run — NOT YET, the poll missed the window; 0 or more at L — the PASS rules, the other jobs as the last read has them.
me_after_poll() {
  local a="$1" s="$2" cu="$3" l="$4" end="$5" j e mi="" nc="" v
  for j in $ME_JOBS; do
    [ "$(kv "$a" "$j")" = "$l:-1" ] || continue
    e="$(kv "$s" "$j")"
    if [ "$e" = "$l:-1" ]; then [ "$end" = "cycle" ] || nc="$nc $j"
    elif me_num "${e%%:*}" && [ "${e%%:*}" -gt "$l" ]; then mi="$mi $j"; fi
  done
  v="$(me_judge "$s" "$cu")"
  case "$v" in fail*|unread*) echo "$v"; return 0 ;; esac
  [ -z "$mi" ] || { echo "missed$mi"; return 0; }
  [ -z "$nc" ] || { echo "nocycle$nc"; return 0; }
  case "$v" in wait*) echo "nocycle" ;; *) echo "$v" ;; esac
}
# me_log LOG COPYUNIX — the caught-error half: 5.18.106's plugin.log reading (ME_PHP), carried. ME_LOG "held" (its control
# held; ME_LOG_ERR the ERROR lines of the four jobs after the straddling cycle — above 0 a FAIL of its own, printed here)
# or "na" (NOT ESTABLISHED: ME_LOG_WHY says why, for the verdict line). The straddle's own errors are a note.
me_log() {
  local w="$1" cu="$2" o
  ME_LOG="na"; ME_LOG_ERR=""; ME_LOG_WHY=""
  o="$(me_php "$w" "$cu" "$IN_CONTAINER")"
  case "$o" in "log=read "*) ;; *) ME_LOG_WHY="$w not read: $(printf '%s' "${o:-no answer}" | cut -c1-60)"; return 0 ;; esac
  case "$(kv "$o" err_in):$(kv "$o" err_after):$(kv "$o" err_24h)" in
    *[!0-9:]*|:*|*::*|*:) ME_LOG_WHY="$w could not be counted"; return 0 ;;
  esac
  if [ "$(kv "$o" control)" != "held" ]; then
    ME_LOG_WHY="its control did not hold in $w — $(kv "$o" before) [master] line(s) at or before the copy and $(kv "$o" run_after) RUN followup_ after it, read in the plugin's zone $(kv "$o" tz)"
    return 0
  fi
  ME_LOG="held"; ME_LOG_ERR="$(kv "$o" err_after)"
  [ "$(kv "$o" err_in)" = "0" ] || note "ME-CA1 $(kv "$o" err_in) ERROR line(s) of followup_close, followup_scan, followup_run or followup_send stamped after the copy inside the cycle it straddled ($(kv "$o" straddle) [master] line(s), up to $(case "$(kv "$o" end)" in complete) echo "its own Cycle complete" ;; newcycle) echo "the next cycle's first RUN of a job it had run" ;; *) echo "the end of the file" ;; esac)) — a job of that cycle met the old class beside the new; a note"
  [ "$ME_LOG_ERR" = "0" ] || bad "ME-CA1 $(kv "$o" err_after) ERROR line(s) of the four follow-up jobs in $w after the cycle the copy straddled (by job: $(kv "$o" err_after_by | tr ',:' '  ')) — in the 24 h before the copy, for comparison: $(kv "$o" err_24h); counts only, the lines are in that file"
  return 0
}
# me_clause — the caught-error clause every ME-CA1 verdict line ends with (root docs/76 §W.7)
me_clause() {
  if [ "$ME_LOG" = "held" ]; then
    if [ "$ME_LOG_ERR" = "0" ]; then printf 'caught errors judged from plugin.log: 0 ERROR after the straddling cycle'
    else printf 'caught errors judged from plugin.log: %s ERROR after the straddling cycle — its FAIL above' "$ME_LOG_ERR"; fi
  else printf "errors master caught are not judged (plugin.log: %s) — master records duration_ms 0 or more for a job that threw, so a caught throw reads PASS here" "${ME_LOG_WHY:-not read}"; fi
}
# me_say VERDICT LINE COPYUNIX POLLED — ME-CA1's line from its verdict on LINE; POLLED non-empty after a poll
me_say() {
  local v="$1" s="$2" cu="$3" polled="$4" c n w="" at j e why strad=""
  c="$(me_clause)"; n="$(kv "$s" newest)"
  [ -z "$polled" ] || w="; whether the window before followup_send's next dispatch is always longer than the poll's 5 s is NOT ESTABLISHED, so this check is not called conclusive"
  for j in $ME_JOBS; do e="$(kv "$s" "$j")"; me_num "${e%%:*}" && [ "${e%%:*}" -le "$cu" ] && [ "${e#*:}" = "-1" ] && strad="$strad $j"; done
  [ -z "$strad" ] || note "ME-CA1$strad read duration_ms -1 at or before the copy ($(utc_of "$cu")), in the cycle the copy straddled — a note, as in 5.18.106 (a straddling master may have included a new file before its class was extracted)"
  case "$v" in
    pass) ok "ME-CA1 PASS — each follow-up job started in a cycle that began after the copy and returned to master: $(me_entries "$s" "$ME_JOBS") (master's own record, read-only; the copy at $(utc_of "$cu"))$w; $c"; return 0 ;;
    fail*) bad "ME-CA1 FAIL —${v#fail} started after the copy and never returned to master (duration_ms -1), and a later cycle has begun (an entry at $(utc_of "$n")): master holds its lock while it runs, so that process has ended — $(me_entries "$s" "$ME_JOBS")$w; $c"; return 0 ;;
    unread*) note "ME-CA1 NOT ESTABLISHED — MASTER'S OWN RECORD COULD NOT BE READ FROM THE LIVE STORE (${v#unread }): NEVER A PASS (root docs/75 DL10); $c; a note, which holds back nothing"; return 0 ;;
    wait*) why="a follow-up job reads duration_ms -1 at $(utc_of "${v#wait }"), after the copy, and no later cycle has begun yet" ;;
    missed*) why="${v#missed} ran again between two reads of the poll (a newer last_run): the poll missed the window" ;;
    nocycle*) why="master did not start a new cycle within the poll's bound:${v#nocycle} still read duration_ms -1" ;;
    *) why="not every follow-up job has started and returned since the copy" ;;
  esac
  why="$why — $(me_entries "$s" "$ME_JOBS")"
  if [ "$MODE" = "after" ]; then
    at=$(( cu + 1200 )); me_num "$n" && [ $(( n + 900 )) -gt "$at" ] && at=$(( n + 900 ))
    ME_NY_AT="$(utc_of "$at")"; ME_NY_SAY="ME-CA1: $why"
    notyet "ME-CA1 NOT YET — $why$w; $c; run it again after $ME_NY_AT"
  else note "ME-CA1 NOT YET — $why; the deploy run makes no poll, and no full cycle may have begun yet: --after-only judges it; $c; a note"; fi
  return 0
}
# me_ca2 LINE COPYUNIX — ME-CA2 on ME-CA1's read: FAIL-only, every other reading a note
me_ca2() {
  local s="$1" cu="$2" n j e l d f="" say=""
  case "$s" in "ms=read "*) ;; *) note "ME-CA2 NOT ESTABLISHED — master's own record could not be read (ME-CA1's read: ${s:-no answer}); the seven other jobs are not judged; a note"; return 0 ;; esac
  n="$(kv "$s" newest)"; me_num "$n" || n=0
  for j in $ME2_JOBS; do
    e="$(kv "$s" "$j")"; l="${e%%:*}"; d="${e#*:}"
    if [ "$e" = "-" ] || [ -z "$e" ]; then say="$say, $j no entry"
    elif ! me_num "$l" || { [ "$d" != "-1" ] && ! me_num "$d"; }; then say="$say, $j unreadable"
    elif [ "$l" -le "$cu" ]; then say="$say, $j not started since the copy"
    elif [ "$d" != "-1" ]; then say="$say, $j returned after the copy"
    elif [ "$n" -gt "$l" ]; then f="$f $j"
    else say="$say, $j running since $(utc_of "$l") (no later cycle yet)"; fi
  done
  if [ -n "$f" ]; then bad "ME-CA2 FAIL —$f started after the copy and never returned to master (duration_ms -1), and a later cycle has begun (an entry at $(utc_of "$n")): each runs code $EXPECTED_VERSION changes — $(me_entries "$s" "$f")"
  else note "ME-CA2 the seven other jobs whose code $EXPECTED_VERSION changes, in ME-CA1's read: ${say#, } — none at duration_ms -1 after the copy with a later cycle begun (FAIL-only)"; fi
  return 0
}
# me_ca1 — ME-CA1 and ME-CA2, after the copy (stage R: the deploy run and --after-only), as described above. Reports;
# never stops — the poll only waits, read-only, in --after-only.
me_ca1() {
  local w cu s v l b bound t0 end first r n polled=""
  w="$IN_CONTAINER/data/plugin.log"
  cu="${COPY_AT:-}"; [ "$MODE" = "after" ] && cu="$(sed -n 's/^COPY_AT=//p' "$STATE" 2>/dev/null | tail -n1)"
  case "$cu" in ''|*[!0-9]*) note "ME-CA1 NOT ESTABLISHED — THE COPY'S TIME IS NOT KNOWN (${cu:-unset}), SO MASTER'S OWN RECORD IS NOT READ AGAINST IT; ME-CA2 likewise; a note, which holds back nothing"; return 0 ;; esac
  me_log "$w" "$cu"
  s="$(me_sched)"
  case "$s" in "ms=read "*) v="$(me_judge "$s" "$cu")" ;; *) v="unread ${s:-no answer}" ;; esac
  if [ "${v%% *}" = "wait" ] && [ "$MODE" = "after" ]; then
    l="${v#wait }"; b="$(kv "$s" budget)"
    case "${b#-}" in ''|*[!0-9]*) b=300 ;; esac
    [ "$b" -ge 0 ] || b=0
    bound=$(( b + 300 + 60 ))
    echo "  ME-CA1 waiting up to $bound s for master's next cycle — a follow-up job reads duration_ms -1 at $(utc_of "$l"), after the copy, and no entry is later (master_budget_seconds $b as master reads it, plus 300 s for one heartbeat, plus 60 s; read again every 5 s, read-only)"
    t0="$(date -u +%s)"; end="bound"; first="$s"
    while [ $(( $(date -u +%s) - t0 )) -lt "$bound" ]; do
      sleep 5
      r="$(me_sched)"
      case "$r" in "ms=read "*) s="$r" ;; *) continue ;; esac
      n="$(kv "$s" newest)"
      if me_num "$n" && [ "$n" -gt "$l" ]; then end="cycle"; break; fi
    done
    v="$(me_after_poll "$first" "$s" "$cu" "$l" "$end")"; polled=1
  fi
  me_say "$v" "$s" "$cu" "$polled"
  me_ca2 "$s" "$cu"
}
"""


def apply(doc, ctx):
    repo, pin, base, plugin = ctx["repo"], ctx["pin_full"], ctx["base_full"], ctx["plugin"]
    m = git(repo, "show", f"{pin}:{plugin}/cron/master.php").decode()
    for s in ["$_m_config  = $_m_store->load('kyc_config.json') ?? [];",
              "$_m_schedule = $_m_store->load('master_schedule.json') ?? [];",
              "$_m_now      = time();",
              "$_m_budget_sec = (int)($_m_config['master_budget_seconds'] ?? 300);",
              "if (!flock($_m_lockFp, LOCK_EX | LOCK_NB)) {",
              "master_log(\"ERROR {$_m_name}: \" . $e->getMessage());"]:
        if m.count(s) != 1:
            raise SystemExit(f"partME: the pin's cron/master.php does not carry, once: {s!r}")
    if m.count("'duration_ms' => -1,") != 1 or m.count("'last_run'    => $_m_now,") != 2:
        raise SystemExit("partME: master's entry is not written (last_run, -1) before the job and (last_run, ms) after it")
    i, j, k = m.index("'duration_ms' => -1,"), m.index("include $_m_scriptPath;"), m.index("'duration_ms' => $_m_elapsed_ms,")
    if not i < j < k:
        raise SystemExit("partME: master's -1 entry, the include and the duration entry are not in that order")
    for job, iv in ME_INTERVALS.items():
        if not re.search(r"^    '" + job + r"' *=> \['interval' => " + str(iv) + r",", m, re.M):
            raise SystemExit(f"partME: {job} is not in master's table at the pin with interval {iv}")
    for job, files in ME2:
        if not re.search(r"^    '" + job + r"' *=> ?\[", m, re.M):
            raise SystemExit(f"partME: ME-CA2's {job} is not in master's table at the pin")
    delta = set(git(repo, "diff", "--name-only", base, pin, "--", plugin).decode().split())
    for job, files in ME2:
        for f in files:
            if f"{plugin}/{f}" not in delta:
                raise SystemExit(f"partME: ME-CA2's {job}: {f} is not in 5.18.107's delta")
    if f"{plugin}/lib/OfferSender.php" in delta or \
            "OfferAudience.php" not in git(repo, "show", f"{pin}:{plugin}/lib/OfferSender.php").decode():
        raise SystemExit("partME: offers_send's reason no longer holds (lib/OfferSender.php changed, or does not require "
                         "lib/OfferAudience.php)")

    # ── the block's head: 5.18.107's description, then 5.18.106's for the caught-error half ──
    doc.sub(ME_HEAD + "\n", NEW_HEAD, why="ME block's head (§T.6, §W.7)")
    doc.sub("#   The positive control: a [master] line stamped at or before the copy and a \"[master] RUN followup_\" line stamped\n"
            "#   after it, in that file. Without both: NOT ESTABLISHED, a note in capitals naming where master's output was looked\n"
            "#   for, which never holds back PASSED (DL10: a host whose master writes elsewhere would otherwise never pass).\n",
            "#   The positive control: a [master] line stamped at or before the copy and a \"[master] RUN followup_\" line stamped\n"
            "#   after it, in that file. Without both: NOT ESTABLISHED — since 5.18.107 said in ME-CA1's verdict line (\"errors\n"
            "#   master caught are not judged\"), which never holds back PASSED (DL10: a host whose master writes elsewhere would\n"
            "#   otherwise never pass).\n", why="the log's control, as 5.18.107 says it")
    # ── me_ca1: master's own record, the poll, ME-CA2; ME_PHP and me_php carried unchanged ──
    a = "# me_ca1 — ME-CA1, after the copy (stage R: the deploy run and --after-only), as described above. Reports; never stops.\nme_ca1() {\n"
    b = "# ── 5.18.107 (root docs/76 §T.3 items 6 and 7): migration 092 after the copy"
    i, j = doc.t.index(a), doc.t.index(b)
    if doc.t.count(a) != 1 or doc.t.count(b) != 1 or not i < j:
        raise SystemExit("partME: me_ca1's bounds are not each once, in order")
    if set(re.findall(r"^([a-z_0-9]+)\(\) \{", doc.t[i:j], re.M)) != {"me_ca1"}:
        raise SystemExit("partME: the slice holds a function other than me_ca1")
    doc.between(a, b, NEW_FUNCS + b, keep_ends=False, why="me_ca1 → master's own record, the poll, ME-CA2 (§T.6)")

    # ── stage R's heading ──
    doc.sub("# ME-CA1 — 5.18.106 (root docs/75 §L.5), after the copy: master's ERROR lines of the four follow-up jobs after the cycle\n"
            "# the copy straddled, in <plugin>/data/plugin.log, counted; NOT ESTABLISHED is a note (DL10). Reports; never stops.\n"
            "hdr \"R. Master's errors after the copy (5.18.106, ME-CA1; read-only — plugin.log counted, never a line)\"\n",
            "# ME-CA1 and ME-CA2 — 5.18.107 (root docs/76 §T.6, §W.7), after the copy: master's own record (master_schedule, read-only,\n"
            "# in the live store) — the four follow-up jobs PASS, FAIL or NOT YET (in --after-only, a poll for master's next cycle),\n"
            "# seven other jobs FAIL-only; 5.18.106's plugin.log count carried as the caught-error half. Reports; never stops.\n"
            "hdr \"R. Master's own record after the copy (5.18.107, ME-CA1 and ME-CA2; read-only — master_schedule read, plugin.log counted, never a line)\"\n",
            why="stage R's ME heading")

    # ── the NOT YET state, carried and re-pointed (TD3) ──
    doc.sub("notyet() { NOT_YET=$((NOT_YET+1)); printf '  wait  %s\\n' \"$*\"; }   # 5.18.106 (root docs/75 §L.5): UJ-ON's NOT YET — withholds PASSED, never a FAIL",
            "notyet() { NOT_YET=$((NOT_YET+1)); printf '  wait  %s\\n' \"$*\"; }   # 5.18.106 (root docs/75 §L.5), carried (root docs/76 §T.6, TD3): ME-CA1's NOT YET in --after-only — withholds PASSED, never a FAIL",
            why="notyet's owner")
    doc.sub("# 5.18.106 (root docs/75 §L.5, DL3): three states, FAIL first — PASSED; NOT YET (UJ-ON's, --after-only only: PASSED and\n",
            "# 5.18.106 (root docs/75 §L.5, DL3): three states, FAIL first — PASSED; NOT YET (ME-CA1's since 5.18.107, root docs/76\n"
            "# §T.6; --after-only only: PASSED and\n", why="the summary's three states")
    doc.sub('  echo; echo "  $EXPECTED_VERSION (after-only): NOT YET — ${UJ_NY_SAY:-UJ-ON above}; run it again after ${UJ_NY_AT:-the next close pass}"',
            '  echo; echo "  $EXPECTED_VERSION (after-only): NOT YET — ${ME_NY_SAY:-ME-CA1 above}; run it again after ${ME_NY_AT:-the next master cycle}"',
            why="the summary's NOT YET line")

    # ── results ──
    for f in ["me_ca1", "me_ca2", "me_sched", "me_judge", "me_after_poll", "me_log", "me_clause", "me_say", "me_entry",
              "me_entries", "me_num", "me_php"]:
        doc.must(f + "() {", 1, why=f"{f} defined once")
    doc.must('ME_PHP="$(cat <<\'PHP\'', 1); doc.must('MS_PHP="$(cat <<\'PHP\'', 1)
    doc.must("me_ca1\n", 1, why="stage R calls me_ca1 once")
    doc.must('  me_ca2 "$s" "$cu"\n', 1); doc.must('notyet "ME-CA1 NOT YET', 1); doc.must("notyet ", 1, why="notyet called once, by ME-CA1 (its definition reads notyet())")
    doc.must('ME_JOBS="' + " ".join(ME_JOBS) + '"', 1); doc.must('ME2_JOBS="' + " ".join(j for j, _ in ME2) + '"', 1)
    doc.mustnot("UJ_NY_"); doc.mustnot("UJ-ON's NOT YET")
    if doc.t.count("${ME_NY_SAY:-ME-CA1 above}") != 1 or doc.t.count("ME_NY_AT=\"$(utc_of \"$at\")\"") != 1:
        raise SystemExit("partME: ME_NY_SAY / ME_NY_AT are not set once and read once")
