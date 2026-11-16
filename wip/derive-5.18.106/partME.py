# Part ME — ME-CA1, master's errors from the cycle the copy straddled (root docs/75 §L.5; DL10). Counts only — never a
# line, a message or a job's output — from <plugin>/data/plugin.log, the installed tree's (lib/PluginLog.php: the log uCRM
# shows on the plugin's page, where the master cron's own lines go; QF-N5 reads it as master's log), read in the container
# as the book's owner by LM-CA2's reader (LM_PHP's "sinks": the file's last 32 MiB; its six lines carried, asserted), its
# stamps read in the plugin's own zone — dn_tz() of the installed tree's lib/timezone.php, called as cron/master.php calls
# it (dn_tz_apply() with no config: the same disk read) — never as UTC.
#   The positive control: a [master] line stamped at or before the copy and a "[master] RUN followup_" line stamped after
#   it. Without both, NOT ESTABLISHED: a note in capitals naming where master's output was looked for, which never holds
#   back PASSED (DL10). The straddling cycle: the [master] lines after the last "Cycle complete" stamped at or before the
#   copy, holding a line stamped at or before it, up to its own "Cycle complete" or the next RUN of a job it already ran,
#   whichever comes first. A followup_(close|scan|run|send) ERROR inside it is a note; one after it a FAIL; ERROR lines
#   stamped at or before the copy are not counted; the count in the 24 h before the copy is printed beside any FAIL.
#   Called in stage R right after LM's readings (the deploy run, after the copy, and --after-only), before UJ-CA (partUJ's
#   call): §L.5's order. New names: ME_PHP, me_php, me_ca1 (code ME-CA1).
# Asserted from Git, at the pin (cron/master.php, lib/PluginLog.php and lib/timezone.php kept byte for byte at 7835906):
# master's line forms in order, its dn_tz_apply() at the top, the RUN line before the job's include, the four follow-up
# jobs registered (followup_close every 600 s), PluginLog::path() and dn_tz()'s signatures. On the source: LM_PHP's reader,
# lm_after's reading of COPY_AT, QF-N5's reading of the same file, and the two insertion points.
import re, textwrap
from d106lib import git, sha_at


def cw(text, lead="# ", cont=None):
    """A comment paragraph, wrapped to 120 characters, every line starting with lead (cont after the first)."""
    out = textwrap.wrap(text, width=120, initial_indent=lead, subsequent_indent=cont if cont is not None else lead,
                        break_long_words=False, break_on_hyphens=False)
    return "\n".join(out) + "\n"


def rule(title):
    head = "# ── " + title + " "
    return head + "─" * (120 - len(head))


ME_RULE = rule("5.18.106: master's errors from the cycle the copy straddled (ME-CA1; root docs/75 §L.5, DL10)")
ME_HEAD = (
    cw("Counts only — never a line, a message or a job's output — from <plugin>/data/plugin.log, the installed tree's: the "
       "log uCRM shows on the plugin's page, where the master cron's own lines go (lib/PluginLog.php), and where QF-N5 "
       "reads them. Read in the container as the book's owner, by LM-CA2's reader (the file's last 32 MiB). cron/master.php "
       "writes \"[Y-m-d H:i:s] [master] RUN <job>\", \"… ERROR <job>: …\" and \"… Cycle complete …\" by date() after "
       "dn_tz_apply(), so each stamp is read in the plugin's own zone — dn_tz() of the installed tree's lib/timezone.php, "
       "called with no config as master calls it — never as UTC.")
    + cw("The positive control: a [master] line stamped at or before the copy and a \"[master] RUN followup_\" line stamped "
         "after it, in that file. Without both: NOT ESTABLISHED, a note in capitals naming where master's output was "
         "looked for, which never holds back PASSED (DL10: a host whose master writes elsewhere would otherwise never pass).",
         "#   ")
    + cw("The straddling cycle: the [master] lines after the last \"Cycle complete\" stamped at or before the copy, holding "
         "a line stamped at or before the copy, up to its own \"Cycle complete\" or the next RUN of a job it already ran (a "
         "new cycle), whichever comes first. A followup_close, followup_scan, followup_run or followup_send ERROR inside "
         "it is a note — a new followup_send or followup_run with the old class loaded throws before it sends or judges, a "
         "new followup_close after its CASE closes — and one after it is a FAIL. ERROR lines stamped at or before the copy "
         "are not counted; the count in the 24 h before the copy is printed beside any FAIL. The deploy run (after the "
         "copy) and --after-only alike.", "#   "))
ME_CODE = r'''ME_PHP="$(cat <<'PHP'
// ME_PHP — 5.18.106's ME-CA1 (root docs/75 §L.5), as the database's owner in the container: LOG COPYUNIX ROOT — LOG's
// last 32 MiB, read by LM_PHP's reader (the "sinks" mode's), each [master] line's stamp read in dn_tz() of ROOT's
// lib/timezone.php, called with no config as cron/master.php calls it (dn_tz_apply(), then date()). One line of counts;
// never a line, a message or a job's output. PHP 7.4 syntax.
$log = (string)($argv[1] ?? ''); $cu = (int)($argv[2] ?? 0); $root = (string)($argv[3] ?? '');
try {
    require_once $root . '/lib/timezone.php';
    $tz = dn_tz();
    $z = new DateTimeZone($tz);
    if ($log === '' || !file_exists($log)) { echo 'log=absent tz=', $tz; exit(0); }
⟦TAIL⟧
    $t = $tail($log);
    if ($t === null) { echo 'log=unreadable tz=', $tz; exit(0); }
    $L = []; $und = 0;   // [stamp, kind, job] for each [master] line, in the file's order
    foreach (explode("\n", $t) as $ln) {
        if (strpos($ln, '] [master] ') === false) continue;
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[master\] (.*)$/', $ln, $m)) { $und++; continue; }
        $d = DateTime::createFromFormat('!Y-m-d H:i:s', $m[1], $z);
        if ($d === false) { $und++; continue; }
        $ts = $d->getTimestamp();
        if (preg_match('/^RUN ([A-Za-z0-9_.-]+)\s*$/', $m[2], $x)) $L[] = [$ts, 'run', $x[1]];
        elseif (preg_match('/^ERROR ([A-Za-z0-9_.-]+): /', $m[2], $x)) $L[] = [$ts, 'error', $x[1]];
        elseif (strpos($m[2], 'Cycle complete ') === 0) $L[] = [$ts, 'complete', ''];
        else $L[] = [$ts, 'other', ''];
    }
    $n = count($L); $before = 0; $runAfter = 0;
    foreach ($L as $r) { if ($r[0] <= $cu) $before++; elseif ($r[1] === 'run' && strpos($r[2], 'followup_') === 0) $runAfter++; }
    // the straddling cycle, lines [$s, $se): after the last "Cycle complete" at or before the copy, when its first
    // line is at or before the copy, up to its own "Cycle complete" (kept) or the next RUN of a job it already ran
    // (a new cycle)
    $c0 = -1;
    for ($i = 0; $i < $n; $i++) if ($L[$i][1] === 'complete' && $L[$i][0] <= $cu) $c0 = $i;
    $s = $c0 + 1; $se = $s; $end = 'none';
    if ($s < $n && $L[$s][0] <= $cu) {
        $ran = [];
        for ($i = $s; $i < $n; $i++) {
            if ($L[$i][1] === 'run') { if (isset($ran[$L[$i][2]])) { $end = 'newcycle'; break; } $ran[$L[$i][2]] = true; }
            $se = $i + 1;
            if ($L[$i][1] === 'complete') { $end = 'complete'; break; }
        }
        if ($end === 'none') $end = 'eof';
    }
    $in = 0; $after = 0; $d24 = 0; $by = ['close' => 0, 'scan' => 0, 'run' => 0, 'send' => 0];
    for ($i = 0; $i < $n; $i++) {
        if ($L[$i][1] !== 'error' || !preg_match('/^followup_(close|scan|run|send)$/', $L[$i][2], $mm)) continue;
        $ts = $L[$i][0];
        if ($ts <= $cu) { if ($ts > $cu - 86400) $d24++; continue; }   // at or before the copy: never counted
        if ($i >= $s && $i < $se) $in++; else { $after++; $by[$mm[1]]++; }
    }
    $bs = []; foreach ($by as $k => $v) $bs[] = $k . ':' . $v;
    echo 'log=read tz=', $tz, ' lines=', $n, ' undated=', $und, ' before=', $before, ' run_after=', $runAfter,
         ' control=', ($before > 0 && $runAfter > 0) ? 'held' : 'not', ' straddle=', $se - $s, ' end=', $end,
         ' err_in=', $in, ' err_after=', $after, ' err_after_by=', implode(',', $bs), ' err_24h=', $d24;
} catch (Throwable $e) { echo '?', get_class($e); exit(3); }
PHP
)"
me_php() { docker exec -u "$DB_OWNER" "$CONTAINER" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -r "$ME_PHP" "$@" 2>/dev/null </dev/null; }   # me_php LOG COPYUNIX ROOT — one line
# me_ca1 — ME-CA1, after the copy (stage R: the deploy run and --after-only), as described above. Reports; never stops.
me_ca1() {
  local w cu o
  w="$IN_CONTAINER/data/plugin.log"
  cu="${COPY_AT:-}"; [ "$MODE" = "after" ] && cu="$(sed -n 's/^COPY_AT=//p' "$STATE" 2>/dev/null | tail -n1)"
  case "$cu" in ''|*[!0-9]*) note "ME-CA1 NOT ESTABLISHED — THE COPY'S TIME IS NOT KNOWN (${cu:-unset}), SO MASTER'S OUTPUT, LOOKED FOR IN $w, IS NOT READ AGAINST IT; a note, which holds back nothing"; return 0 ;; esac
  o="$(me_php "$w" "$cu" "$IN_CONTAINER")"
  case "$o" in
    "log=read "*) ;;
    *) note "ME-CA1 NOT ESTABLISHED — MASTER'S OUTPUT WAS LOOKED FOR IN $w AND COULD NOT BE READ THERE (${o:-no answer}): a host whose master writes elsewhere is not judged here; a note, which holds back nothing"; return 0 ;;
  esac
  case "$(kv "$o" err_in):$(kv "$o" err_after):$(kv "$o" err_24h)" in
    *[!0-9:]*|:*|*::*|*:) note "ME-CA1 NOT ESTABLISHED — MASTER'S OUTPUT IN $w COULD NOT BE COUNTED ($(printf '%s' "$o" | cut -c1-120)); a note, which holds back nothing"; return 0 ;;
  esac
  if [ "$(kv "$o" control)" != "held" ]; then
    note "ME-CA1 NOT ESTABLISHED — THE POSITIVE CONTROL DID NOT HOLD IN $w, WHERE MASTER'S OUTPUT WAS LOOKED FOR: it needs a [master] line stamped at or before the copy ($(kv "$o" before) read) and a [master] RUN followup_ line stamped after it ($(kv "$o" run_after) read), the stamps read in the plugin's zone ($(kv "$o" tz)); master's errors after the copy are not judged here — $( [ "$MODE" = "after" ] && echo "run --after-only again once master has run a follow-up job since the copy" || echo "the --after-only reads them again"); a note, which holds back nothing"
    return 0
  fi
  [ "$(kv "$o" err_in)" = "0" ] || note "ME-CA1 $(kv "$o" err_in) ERROR line(s) of followup_close, followup_scan, followup_run or followup_send stamped after the copy inside the cycle it straddled ($(kv "$o" straddle) [master] line(s), up to $(case "$(kv "$o" end)" in complete) echo "its own Cycle complete" ;; newcycle) echo "the next cycle's first RUN of a job it had run" ;; *) echo "the end of the file" ;; esac)) — a job of that cycle met the old class beside the new; a note"
  if [ "$(kv "$o" err_after)" = "0" ]; then ok "ME-CA1 no ERROR line of followup_close, followup_scan, followup_run or followup_send in $w after the cycle the copy straddled ($(kv "$o" lines) [master] lines read, stamped in the plugin's zone $(kv "$o" tz)$( [ "$(kv "$o" undated)" = "0" ] || echo ", $(kv "$o" undated) undated"); the control: $(kv "$o" before) at or before the copy, $(kv "$o" run_after) RUN followup_ after it)"
  else bad "ME-CA1 $(kv "$o" err_after) ERROR line(s) of the four follow-up jobs in $w after the cycle the copy straddled (by job: $(kv "$o" err_after_by | tr ',:' '  ')) — in the 24 h before the copy, for comparison: $(kv "$o" err_24h); counts only, the lines are in that file"; fi
}
'''
CALL = (cw("ME-CA1 — 5.18.106 (root docs/75 §L.5), after the copy: master's ERROR lines of the four follow-up jobs after the "
           "cycle the copy straddled, in <plugin>/data/plugin.log, counted; NOT ESTABLISHED is a note (DL10). Reports; never stops.")
        + "hdr \"R. Master's errors after the copy (5.18.106, ME-CA1; read-only — plugin.log counted, never a line)\"\n"
        + "me_ca1\n")
LM_TAIL = ["$tail = function (string $f) {",
           "if ($f === '' || !is_file($f) || !is_readable($f)) return null;",
           "$h = @fopen($f, 'rb'); if (!$h) return null;",
           "$s = (int)filesize($f); if ($s > 33554432) fseek($h, $s - 33554432);",
           "$t = (string)stream_get_contents($h); fclose($h); return $t;",
           "};"]
MASTER = ["require_once dirname(__DIR__) . '/lib/timezone.php'; dn_tz_apply();\n",
          "    master_log(\"RUN {$_m_name}\");\n",
          "        include $_m_scriptPath;\n",
          "        master_log(\"ERROR {$_m_name}: \" . $e->getMessage());\n",
          "    master_log(\"Cycle complete in {$_m_total_sec}s. Jobs run: \" . implode(', ', $_m_ran));\n",
          "    echo '[' . date('Y-m-d H:i:s') . '] [master] ' . $msg . \"\\n\";\n"]
JOBS = ["    'followup_close' => ['interval' => 600,                'script' => __DIR__ . '/followup_close.php'],\n",
        "    'followup_scan'  => ['interval' => 600,                'script' => __DIR__ . '/followup_scan.php'],\n",
        "    'followup_run'   => ['interval' => 600,                'script' => __DIR__ . '/followup_run.php'],\n",
        "    'followup_send'  => ['interval' => 300,                'script' => __DIR__ . '/followup_send.php'],\n"]
LM_AFTER = "lm_after   # LM-CA1 and LM-CA2 (the deploy run) or LM-ON (--after-only) — root docs/74 §H.4.5\n"
QF_STAGE = "# qf_stage — QF-G1–QF-G3, LK-G1 and QF-N1–QF-N7, stage A, before anything changes"


def need(text, s, what, n=1):
    c = text.count(s)
    if c != n:
        raise SystemExit(f"PART ME: {what}: {s[:110]!r} found {c} times, expected {n}")


def apply(doc, ctx):
    repo, plugin, base, pin, kept = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"], ctx["kept"]
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()

    # ── Git: master's line forms and the zone it stamps them in; the file they go to ──
    for f in ("cron/master.php", "lib/PluginLog.php", "lib/timezone.php"):
        if f not in kept or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART ME: {f} is not kept byte for byte at 7835906 and the pin")
    ms = show(pin, "cron/master.php")
    at = -1
    for s in MASTER:
        need(ms, s, "cron/master.php")
        if ms.index(s) < at:
            raise SystemExit(f"PART ME: cron/master.php: {s.strip()[:60]!r} is out of the order ME-CA1 reads")
        at = ms.index(s)
    for s in JOBS:
        need(ms, s, "cron/master.php's follow-up jobs")
    if not ms.index(MASTER[0]) < 200:
        raise SystemExit("PART ME: cron/master.php does not call dn_tz_apply() at its top")
    pl = show(pin, "lib/PluginLog.php")
    need(pl, "        return dirname(__DIR__) . '/data/plugin.log';\n", "PluginLog::path()")
    need(pl, " * The file is data/plugin.log beside the code: the log uCRM shows on the plugin's page, where the master cron's own\n",
         "PluginLog's account of master's lines")
    tz = show(pin, "lib/timezone.php")
    for s in ("    function dn_tz(?array $config = null): string\n", "    function dn_tz_apply(?array $config = null): void\n",
              "        date_default_timezone_set(dn_tz($config));\n"):
        need(tz, s, "lib/timezone.php")

    # ── the source: LM-CA2's reader (carried into ME_PHP, indentation aside), the copy's time, QF-N5's file ──
    lmp = doc.t[doc.t.index("LM_PHP=\"$(cat <<'PHP'\n"):]
    lmp = lmp[:lmp.index("\nPHP\n)\"\n")]
    lines = lmp.split("\n")
    hit = [i for i, l in enumerate(lines) if l.strip() == LM_TAIL[0]]
    if len(hit) != 1 or [l.strip() for l in lines[hit[0]:hit[0] + len(LM_TAIL)]] != LM_TAIL:
        raise SystemExit("PART ME: LM_PHP's reader of a log's last 32 MiB is not the six lines ME_PHP carries")
    tail = "\n".join("    " + l.strip() if i not in (0, len(LM_TAIL) - 1) else l.strip() for i, l in enumerate(LM_TAIL))
    tail = "\n".join("    " + l for l in tail.split("\n"))
    for s in ("  cu=\"${COPY_AT:-}\"; [ \"$MODE\" = \"after\" ] && cu=\"$(sed -n 's/^COPY_AT=//p' \"$STATE\" 2>/dev/null | tail -n1)\"\n",
              "qf_php n5 \"$sub\" \"$IN_CONTAINER/data/plugin.log\"",
              "lm_php() { docker exec -i -u \"$DB_OWNER\" \"$CONTAINER\" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -r \"$LM_PHP\" \"$@\" 2>/dev/null; }",
              LM_AFTER, QF_STAGE):
        need(doc.t, s, "the source ME stands on")
    for s in ("me_ca1", "ME_PHP", "me_php", "ME-CA1"):
        doc.mustnot(s, why="ME's names are new")
    need(doc.t, "\nuj_after   # UJ-CA (notes) and, in --after-only, UJ-ON (judged)\n", "partUJ's call in stage R (partME runs after partUJ)")

    # ── the edits: ME's block before qf_stage (after partUJ's), its call right after lm_after (before UJ-CA) ──
    code = ME_CODE
    if code.count("⟦TAIL⟧") != 1:
        raise SystemExit("PART ME: ME_PHP's text does not hold the reader's place once")
    code = code.replace("⟦TAIL⟧", tail)
    block = ME_RULE + "\n" + ME_HEAD + code
    doc.insert_before(QF_STAGE, block, why="ME functions")
    doc.insert_after(LM_AFTER, CALL, why="ME-CA1 in stage R")

    # ── the result ──
    for s, n in (("ME_PHP=\"$(cat <<'PHP'\n", 1), ("me_php() {", 1), ("me_ca1() {\n", 1), ("\nme_ca1\n", 1), (CALL, 1),
                 (tail + "\n", 1), ("ME-CA1 NOT ESTABLISHED — ", 4)):
        doc.must(s, n, why="ME as written")
    t = doc.t
    if not (t.index(LM_AFTER) < t.index(CALL) < t.index("\nuj_after   # ") < t.index("  qf_on \"${ICODE_R:-}\"\n")):
        raise SystemExit("PART ME: ME-CA1 is not called after LM's readings and before UJ-CA in stage R")
    blk = t[t.index(ME_RULE):t.index(QF_STAGE)]
    if blk.count("\nPHP\n") != 1 or "uj_" in blk:
        raise SystemExit("PART ME: ME's block does not hold one heredoc of its own, or reaches into UJ's")
    for l in (block + CALL).split("\n"):
        if re.search(r"saveOverrides|writeKycFile|->save\(|set_config\.php|sales-followup\.sh|qf_tool|\$QF_WRAPPER|CIH_BASE", l):
            raise SystemExit(f"PART ME: a line ME adds names a writer, the switch tool or the run's folder: {l.strip()[:100]}")
        s = l.lstrip()
        if s.startswith(("#", "//")) and not l.startswith("# ── ") and len(l) > 120:
            raise SystemExit(f"PART ME: a comment line ME adds is longer than 120 characters: {l[:80]!r}")
    if len(ME_RULE) != 120:
        raise SystemExit("PART ME: the rule line is not 120 characters")
    ctx.update(me_rule=ME_RULE)
