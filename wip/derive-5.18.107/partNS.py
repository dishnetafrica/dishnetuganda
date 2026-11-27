# Part NS — the switches before the copy (root docs/76 §T.4):
#   CS-G1 and CS-B1 retire (TD1): their premise, the claim switches off "until 5.18.106 serves", ended on 22 Nov, and the
#   owner took `on chase` that evening; carried, CS-G1 would refuse every 5.18.107 deploy. cs_found, cs_has, cs_check_cmd
#   and cs_check go (their uses in rb_pre106 go with partR). FE-G1 retires with U1–U5 (partSA).
#   NS-G1 (stage A, and so --check, on LK-G1's reading) and NS-B1 (after DEPLOY, on LK-B1's): K's, Q's and W.1's switches —
#   quoted_no_prices_enabled, connection_quote_enabled, ai_burst_merge_enabled — judged where their readers read them
#   (PluginConfig::load: <plugin>/data/config.json, <data>/config.json and <data>/kyc_config.json — p, d, k), each place's
#   raw value against the off values (absent, null, '', '0', 0, false, 'false', 'off', 'no'): not presence (DL1, TD2 — W.1's
#   only off form leaves '0'), not the gated readers alone. The store row is reported, never judged. The lk mode's own PHP
#   prints, per switch, the places reading on, never a value (ns=, nss=); its positive control in the same reading (an
#   in-memory '1' for each must read on; else NOT ESTABLISHED, and NS-G1 refuses); the pin's own readers as notes (nsread=);
#   the texts set or unset per place (nstexts=). The lk key list gains K's and Q's keys and texts, so LK-B1, LK-CA1, LK-AO,
#   LK-RB0 and LK-RB1 compare them too with no new reader.
#   lk_read reads the pin alone (TD8): lk=nopin without it, so every LK reading in every mode reads one ladder — the
#   installed ladder lacks the two W settings before the copy and after a rollback.
import re

NS = ["quoted_no_prices_enabled", "connection_quote_enabled", "ai_burst_merge_enabled"]
NS_TEXTS = ["quoted_reply_text", "quoted_alert_cooldown_hours", "connection_quote_transport", "connection_quote_tax_line",
            "connection_quote_closing", "no_ai_alert_cooldown_hours"]
LK_EXTRA = NS[:2] + NS_TEXTS          # §T.4: what the lk list gains (W.1's switch and window are ladder settings already)

LK_PHP_ADD = r"""        // 5.18.107 (root docs/76 §T.4): NS-G1 and NS-B1 — K's, Q's and W.1's switches judged where their readers read them
        // (p, d, k; the store row reported, never judged), each place's raw value against the off values: absent, null, '',
        // '0', 0, false, 'false', 'off', 'no'. ns= the places reading on in p, d or k, nss= in the store row, by key and place
        // letter, never a value; nsctl= the rule's positive control, an in-memory '1' for each switch, which must read on;
        // nsread= the pin's own readers over PluginConfig::read (QuoteInPlay::on, ConnectionQuote::on, BurstMerge::on), notes;
        // nstexts= the texts and W.1's window set, per place, by key name
        $NS = ['quoted_no_prices_enabled', 'connection_quote_enabled', 'ai_burst_merge_enabled'];
        $nsOff = function ($v): bool { return $v === null || $v === '' || $v === '0' || $v === 0 || $v === false || $v === 'false' || $v === 'off' || $v === 'no'; };
        $nsOn = function (array $docs, array $places) use ($NS, $nsOff): array {
            $o = [];
            foreach ($NS as $k) {
                $pl = [];
                foreach ($places as $c) if (is_array($docs[$c] ?? null) && array_key_exists($k, $docs[$c]) && !$nsOff($docs[$c][$k])) $pl[] = $c;
                if ($pl !== []) $o[] = $k . ':' . implode('+', $pl);
            }
            return $o;
        };
        $ns = $nsOn($doc, ['p', 'd', 'k']); $nss = $nsOn($doc, ['s']);
        $nsctl = 'ok'; foreach ($NS as $k) if ($nsOn(['k' => [$k => '1']], ['k']) !== [$k . ':k']) $nsctl = 'bad';
        if ($nsOn(['k' => [$NS[0] => '0', $NS[1] => false, $NS[2] => 'off']], ['k']) !== []) $nsctl = 'bad';
        $nsr = [];
        foreach ([['qip', 'QuoteInPlay', true], ['cq', 'ConnectionQuote', true], ['bm', 'BurstMerge', false]] as [$t, $c, $dd]) {
            try {
                require_once $root . '/lib/' . $c . '.php';
                $nsr[] = $t . ':' . (($dd ? $c::on($cfg, $pdd) : $c::on($cfg)) ? 1 : 0);
            } catch (Throwable $x) { $nsr[] = $t . ':?' . get_class($x); }
        }
        $nst = [];
        foreach (['quoted_reply_text', 'quoted_alert_cooldown_hours', 'connection_quote_transport', 'connection_quote_tax_line',
                  'connection_quote_closing', 'no_ai_alert_cooldown_hours', 'ai_burst_merge_window_minutes'] as $k) {
            $pl = []; foreach (['k', 's', 'p', 'd'] as $c) if (array_key_exists($k, $doc[$c])) $pl[] = $c;
            if ($pl !== []) $nst[] = $k . ':' . implode('+', $pl);
        }
"""


def apply(doc, ctx):
    repo, plugin, pin = ctx["repo"], ctx["plugin"], ctx["pin_full"]
    from d107lib import git
    lad = git(repo, "show", f"{pin}:{plugin}/lib/SalesFollowupLadder.php").decode()
    base_lad = git(repo, "show", f"{ctx['base_full']}:{plugin}/lib/SalesFollowupLadder.php").decode()
    for k in ("ai_burst_merge_enabled", "ai_burst_merge_window_minutes"):
        if f"'{k}'" not in lad or f"'{k}'" in base_lad:
            raise SystemExit(f"PART NS: {k} is not a ladder setting of the pin's alone (§T.4: the two W settings)")
    for k in LK_EXTRA:
        if f"'{k}'" in lad:
            raise SystemExit(f"PART NS: {k} is already a ladder key — the lk list would count it twice")
    for c, rx in (("QuoteInPlay", r"public static function on\(array \$config, \?string \$dataDir\): bool"),
                  ("ConnectionQuote", r"public static function on\(array \$config, \?string \$dataDir\): bool"),
                  ("BurstMerge", r"public static function on\(array \$config\): bool")):
        if not re.search(rx, git(repo, "show", f"{pin}:{plugin}/lib/{c}.php").decode()):
            raise SystemExit(f"PART NS: the pin's {c}::on is not the signature the lk mode calls")
    import subprocess
    for k in NS + NS_TEXTS:
        r = subprocess.run(["git", "-C", repo, "grep", "-l", "-F", f"'{k}'", pin, "--", f"{plugin}/lib", f"{plugin}/workers",
                            f"{plugin}/cron", f"{plugin}/tools"], capture_output=True)
        if r.returncode != 0 or not r.stdout.strip():
            raise SystemExit(f"PART NS: no runtime file of the pin names {k}")

    # ── the lk mode: K's and Q's keys and texts in its list; the switches' verdicts, their control, the readers, the texts ──
    doc.sub("        foreach (array_values(array_unique(array_merge($L['all'], $L['settings']))) as $k) {\n",
            "        // 5.18.107 (root docs/76 §T.4): K's and Q's switches and texts beside the ladder's own, as settings (never allKeys'\n"
            "        // held rule): LK-B1, LK-CA1, LK-AO, LK-RB0 and LK-RB1 compare them with no new reader\n"
            "        $X = ['" + "', '".join(LK_EXTRA) + "'];\n"
            "        foreach (array_values(array_unique(array_merge($L['all'], $L['settings'], $X))) as $k) {\n",
            why="the lk list gains K's and Q's keys and texts")
    doc.sub("             ' unread=', $unread === [] ? '-' : implode(',', $unread), ' map=', $map === [] ? '-' : implode(',', $map);\n",
            "             ' unread=', $unread === [] ? '-' : implode(',', $unread),\n"
            "             ' extra=', count($X), ' ns=', $ns === [] ? '-' : implode(',', $ns), ' nss=', $nss === [] ? '-' : implode(',', $nss),\n"
            "             ' nsctl=', $nsctl, ' nsread=', implode(',', $nsr), ' nstexts=', $nst === [] ? '-' : implode(',', $nst),\n"
            "             ' map=', $map === [] ? '-' : implode(',', $map);\n",
            why="the lk mode prints NS's fields")
    doc.sub("        echo 'keys=', count($L['all']), ' settings=', count($L['settings']), ' file=', $n['k'], ' store=', $n['s'],\n",
            LK_PHP_ADD + "        echo 'keys=', count($L['all']), ' settings=', count($L['settings']), ' file=', $n['k'], ' store=', $n['s'],\n",
            why="the lk mode computes NS's fields")
    doc.sub("    case 'lk':     // lk ROOT LIVEROOT PDD KEYFILE — 5.18.105's ladder rule (LK; root docs/74 §H.4.3), read-only: every key\n"
            "                   // of ROOT's SalesFollowupLadder::allKeys() and settingKeys() in the four places — <data>/kyc_config.json\n",
            "    case 'lk':     // lk ROOT LIVEROOT PDD KEYFILE — 5.18.105's ladder rule (LK; root docs/74 §H.4.3), read-only: every key\n"
            "                   // of ROOT's SalesFollowupLadder::allKeys() and settingKeys(), and since 5.18.107 K's and Q's switches and\n"
            "                   // texts (root docs/76 §T.4), in the four places — <data>/kyc_config.json\n",
            why="the lk mode's header")
    doc.lines_between("# The ladder's keys — the pin's SalesFollowupLadder::allKeys() and settingKeys(), lib/SalesFollowupLadder.php being",
                      "# 994d660's bytes in both releases (PREV_KEPT) — read read-only in the four places: <data>/kyc_config.json (k), its",
        "# The ladder's keys — the pin's SalesFollowupLadder::allKeys() and settingKeys(), read through the pin's code alone\n"
        "# (P3B_PIN; lk=nopin without it): 5.18.107 changes lib/SalesFollowupLadder.php (two W settings), so every LK reading in\n"
        "# every mode reads one ladder (root docs/76 §T.4, TD8) — and since 5.18.107 K's and Q's switches and texts beside them,\n"
        "# as settings — read read-only in the four places: <data>/kyc_config.json (k), its", why="LK's comment (S106:7279-7280)")
    # lk_read: the pin alone (TD8)
    doc.sub("# lk_read — one LK reading of the live places (QF_PHP lk, through the pin's ladder where the pin's code is copied, else\n"
            "# the installed one — the same file), its key put in the run's folder through stdin (never on a command line) and removed\n",
            "# lk_read — one LK reading of the live places (QF_PHP lk, through the pin's ladder alone — lk=nopin where the pin's code\n"
            "# is not copied: 5.18.107's ladder differs from bf29cbd's, root docs/76 §T.4, TD8 — never the installed one), its key put\n"
            "# in the run's folder through stdin (never on a command line) and removed\n",
            why="lk_read's comment")
    doc.sub('  [ -n "$LK_KEY" ] || { printf \'lk=nokey\'; return 1; }\n  f="$CIH_BASE/lk-$$.key"\n',
            '  [ -n "$LK_KEY" ] || { printf \'lk=nokey\'; return 1; }\n  [ -n "${P3B_PIN:-}" ] || { printf \'lk=nopin\'; return 1; }   # 5.18.107 (TD8): one ladder, the pin\'s, in every mode\n  f="$CIH_BASE/lk-$$.key"\n',
            why="lk_read refuses without the pin")
    doc.sub('  o="$(qf_php lk "${P3B_PIN:-$IN_CONTAINER}" "$IN_CONTAINER" "$PDD_IN" "$f")"\n',
            '  o="$(qf_php lk "$P3B_PIN" "$IN_CONTAINER" "$PDD_IN" "$f")"\n', why="lk_read reads the pin")

    # ── CS-G1 and CS-B1 retire; NS-G1 and NS-B1 in their place ──
    s = doc.t.index("# ── 5.18.106: the three claim switches must be off (CS-G1, CS-B1; root docs/75 §L.3, §F) ──")
    e = doc.t.index("# lk_stage_a SUB — stage A's readings for QF-CA5 and LK-ON, through the PIN's tool on SUB, QF-G1's copy (the tool is\n")
    old = doc.t[s:e]
    if old.count("cs_check() {") != 1 or old.count("cs_found() {") != 1 or "lk_stage_a()" in old:
        raise SystemExit("PART NS: the CS block is not the one expected")
    ns_block = (
        "# ── 5.18.107: K's, Q's and W.1's switches must be off at the deploy (NS-G1, NS-B1; root docs/76 §T.4, §K) ──────────────\n"
        "# quoted_no_prices_enabled (K), connection_quote_enabled (Q) and ai_burst_merge_enabled (W.1) stay off at the deploy and\n"
        "# are turned on one at a time after a PASSED --after-only — K, then Q with v10, then W.1 (§K). NS-G1 (stage A — a deploy\n"
        "# and --check — on LK-G1's reading, right after it) and NS-B1 (after DEPLOY is typed, before the copy, on LK-B1's reading\n"
        "# before it is compared with LK-G1's) read the lk mode's own verdicts (ns=): NO-GO when a switch holds, in any place its\n"
        "# reader reads (p, d, k — PluginConfig::load, k read last and winning), a value other than an off value. Not presence\n"
        "# (DL1, TD2): `set ai_burst_merge_enabled 0`, §K's own pre-rollback step, leaves '0' present. Not the gated readers alone:\n"
        "# QuoteInPlay::on and ConnectionQuote::on also need StaffJobsGate, which may doubt. The store row is reported (nss=), never\n"
        "# judged: no reader reads it. The rule's positive control is in the same reading (nsctl=): without it a reading of off\n"
        "# proves nothing, and NS-G1 refuses. The refusal names keys and places, never a value; it prints no command — once\n"
        "# 5.18.106 serves, none of its tools can write these keys (§T.10, known limits).\n"
        "ns_say() { printf '%s' \"$1\" | tr ',' '\\n' | sed -E 's/^([a-z_]+):(.*)$/\\1 in \\2/; s/\\+/, /g' | paste -sd';' - | sed 's/;/; /g'; }   # key:k+d,… → \"key in k, d; …\"\n"
        "# ns_check CODE READING — NS-G1 or NS-B1 on an lk reading: NOT ESTABLISHED (a refusal) when the control did not hold; NO-GO\n"
        "# when a switch reads on in p, d or k; else ok, with the store row's reading, the pin's readers and the texts as notes\n"
        "ns_check() {\n"
        "  local c=\"$1\" o=\"$2\" n done\n"
        "  done=\"$( [ \"$c\" = \"NS-B1\" ] && echo \"Nothing was copied\" || echo \"Nothing was changed\")\"\n"
        "  [ \"$(qf_kv \"$o\" nsctl)\" = \"ok\" ] || stop \"NO-GO ($c): NOT ESTABLISHED — THE SWITCH RULE'S POSITIVE CONTROL DID NOT HOLD (nsctl=$(qf_kv \"$o\" nsctl)): an in-memory '1' for each of quoted_no_prices_enabled, connection_quote_enabled and ai_burst_merge_enabled must read on, so a reading of off proves nothing. $done. Send the log file\"\n"
        "  n=\"$(qf_kv \"$o\" ns)\"\n"
        "  [ -n \"$n\" ] || stop \"NO-GO ($c): NOT ESTABLISHED — the switches' verdicts were not read ($(printf '%s' \"${o%% map=*}\" | cut -c1-160)). $done. Send the log file\"\n"
        "  if [ \"$n\" != \"-\" ]; then\n"
        "    stop \"NO-GO ($c): $(ns_say \"$n\") reads on (p <plugin>/data/config.json, d <data>/config.json, k <data>/kyc_config.json; no value is read out). $EXPECTED_VERSION's switches stay off at the deploy and are turned on one at a time after a PASSED --after-only — K, then Q with v10, then W.1 (root docs/76 §K). $BASELINE_VERSION's tools cannot change this key: set_config refuses it as unmanaged, and the switch tool's set as undeclared. Its repair is a separate step the owner authorises (root docs/76 §T.10, known limits), never this script$( [ \"$c\" = \"NS-B1\" ] && echo \"; it was turned on during this run, after stage A\"). $done. Send the log file\"\n"
        "  fi\n"
        "  ok \"$c quoted_no_prices_enabled (K), connection_quote_enabled (Q) and ai_burst_merge_enabled (W.1) read off in every place their readers read — <plugin>/data/config.json, <data>/config.json and <data>/kyc_config.json — each place's own value against the off values, the rule's positive control holding (an in-memory '1' reads on)$( [ \"$c\" = \"NS-B1\" ] && echo \", read again after DEPLOY, before the copy\")\"\n"
        "  [ \"$(qf_kv \"$o\" nss)\" = \"-\" ] || note \"$c the store row holds $(ns_say \"$(qf_kv \"$o\" nss)\") reading on — reported, never judged: no reader reads the store row for these keys\"\n"
        "  note \"$c the pin's own readers over PluginConfig::read, as the jobs read them: QuoteInPlay::on, ConnectionQuote::on, BurstMerge::on — $(qf_kv \"$o\" nsread) (1 on, 0 off; a note)\"\n"
        "  [ \"$c\" = \"NS-G1\" ] && note \"NS-G1 the texts and W.1's window, set per place, by key name (none acts while its switch is off; no_ai_alert_cooldown_hours is Part X's, live at the deploy, and acts only once a number is listed — 092 creates the list empty): $(ns_say \"$(qf_kv \"$o\" nstexts)\" | sed 's/^$/none set/')\"\n"
        "  return 0\n"
        "}\n")
    doc.t = doc.t[:s] + ns_block + doc.t[e:]
    doc.log.append(("CS-G1/CS-B1 → NS-G1/NS-B1", 1))
    doc.must(ns_block, 1, why="the NS block as written")
    doc.sub('  cs_check CS-G1 "$LK_G1"   # CS-G1 — the three claim switches absent from k and s, on LK-G1\'s reading (root docs/75 §L.3): a refusal stops here, nothing changed\n',
            '  ns_check NS-G1 "$LK_G1"   # NS-G1 — K\'s, Q\'s and W.1\'s switches off where their readers read them, on LK-G1\'s reading (root docs/76 §T.4): a refusal stops here, nothing changed\n',
            why="NS-G1 in place of CS-G1")
    doc.sub('  cs_check CS-B1 "$o"   # CS-B1 — the same on this reading, before it is compared with LK-G1\'s (root docs/75 §L.3): a refusal stops here, nothing copied\n',
            '  ns_check NS-B1 "$o"   # NS-B1 — the same on this reading, before it is compared with LK-G1\'s (root docs/76 §T.4): a refusal stops here, nothing copied\n',
            why="NS-B1 in place of CS-B1")
    doc.subs([
        ("# replaces 5.18.104's qf_clear_and_baseline and runs no tool): LK-B1 — the same reading as LK-G1, judged first by CS-B1\n"
         "# (5.18.106, root docs/75 §L.3: none of the three claim switches present in k or s), then required equal to LK-G1's key\n",
         "# replaces 5.18.104's qf_clear_and_baseline and runs no tool): LK-B1 — the same reading as LK-G1, judged first by NS-B1\n"
         "# (5.18.107, root docs/76 §T.4: K's, Q's and W.1's switches off where their readers read them), then required equal to LK-G1's key\n"),
        ("# 5.18.106 a claim switch present in k or s is CS-G1's refusal, on this reading, right after it (cs_check; root docs/75\n",
         "# 5.18.107 K's, Q's or W.1's switch reading on is NS-G1's refusal, on this reading, right after it (ns_check; root docs/76\n"),
    ], why="LK texts name NS")
    m = re.search(r"# 5\.18\.107 K's, Q's or W\.1's switch reading on is NS-G1's refusal, on this reading, right after it \(ns_check; root docs/76\n# ([^\n]*)\n", doc.t)
    if not m:
        raise SystemExit("PART NS: LK-G1's comment does not go on after naming NS-G1")
    doc.sub("# 5.18.107 K's, Q's or W.1's switch reading on is NS-G1's refusal, on this reading, right after it (ns_check; root docs/76\n# " + m.group(1) + "\n",
            "# 5.18.107 K's, Q's or W.1's switch reading on is NS-G1's refusal, on this reading, right after it (ns_check; root docs/76\n"
            "# §T.4); 5.18.106's claim-switch refusal retired (TD1): the claim switches stay as the owner has them.\n", why="LK-G1's comment ends on NS")
    for s in ("cs_check CS-G1", "cs_check CS-B1", "cs_check() {", "cs_found() {", "cs_check_cmd()", "cs_has() {"):
        doc.mustnot(s, why="CS-G1 and CS-B1 retired")
    doc.must('ns_check NS-G1 "$LK_G1"', 1)
    doc.must('ns_check NS-B1 "$o"', 1)
