# Part P — 5.18.104's Part P checks (AC) made live-aware (root docs/74 §H.4.4):
#   AC-L1's sums re-read from the pin (lib/QuotationService.php and webhook.php are 5.18.105's: their log lines masked);
#   AC-CA1: on() must equal quote_agent_cc_enabled on the copy (read through the installed PluginConfig::read, QF_PHP's new
#   mode cfgkey) — the control and the South Sudan halves kept; P7's collections a note (the first branch deleted);
#   AC-CA2 compares with BASELINE, now 994d660 (P3B_BASE and its webhook.php from Git: its folder named for no release).
import re
from d105lib import sha_at


def apply(doc, ctx):
    repo, plugin, pin = ctx["repo"], ctx["plugin"], ctx["pin_full"]

    # ── AC-L1: the pin's sums, from Git ──
    m = re.search(r'\nAC_L1_FILES="([^"]*)"', doc.t)
    if not m:
        raise SystemExit("PART P: AC_L1_FILES not found")
    files = m.group(1).split()
    if files != ["lib/QuoteAgentCopy.php", "lib/QuotationService.php", "webhook.php", "lib/CustomerEmailDispatcher.php"]:
        raise SystemExit(f"PART P: AC_L1_FILES is {files}")
    sums = " ".join(f"{f}:{sha_at(repo, pin, plugin + '/' + f)}" for f in files)
    doc.line('AC_L1_SUMS="',
             f'AC_L1_SUMS="{sums}"   # AC-L1: each as file:sha256 at the pin, from Git (5.18.105: lib/QuotationService.php and webhook.php carry its masked log lines)')
    doc.must(f'AC_L1_SUMS="{sums}"', 1)

    # ── QF_PHP: cfgkey ROOT DIR KEY — a key's value as the copy's own PluginConfig::read gives it, compared, never printed ──
    doc.sub("if (in_array($mode, ['notes', 'ca4', 'ca6', 'ca8', 'on3', 'lon'], true)) putenv(",
            "if (in_array($mode, ['notes', 'ca4', 'ca6', 'ca8', 'on3', 'lon', 'cfgkey'], true)) putenv(", why="cfgkey vault")
    doc.insert_before("    case 'lkrows': // lkrows DB MAX",
        "    case 'cfgkey': // cfgkey ROOT DIR KEY — AC-CA1 (root docs/74 §H.4.4): whether KEY reads '1' through ROOT's PluginConfig::read\n"
        "                   // on DIR's copy, its own vault read — as QuoteAgentCopy::state() reads quote_agent_cc_enabled; 1 or 0, never a value\n"
        "        [$root, $dir, $k] = $a;\n"
        "        require_once $root . '/lib/PluginConfig.php';\n"
        "        $cfg = PluginConfig::read($root, $dir);\n"
        "        echo 'key=', (string)($cfg[$k] ?? '') === '1' ? 1 : 0;\n"
        "        break;\n\n", why="QF_PHP cfgkey")

    # ── AC-CA1: on() as the copy's key says ──
    doc.sub('    o="$(ac_php ca1 "$sub/code" "$sub")"; cih_drop "$sub"\n',
            '    o="$(ac_php ca1 "$sub/code" "$sub")"; k="$(qf_kv "$(qf_php cfgkey "$sub/code" "$sub" quote_agent_cc_enabled)" key)"; cih_drop "$sub"\n',
            why="AC-CA1 key")
    doc.sub('  local inst="$1" sub o v miss="" w want\n', '  local inst="$1" sub o v miss="" w want k\n', why="ac_after locals")
    doc.lines_between('        if [ "$(qf_kv "$o" live)" = "0" ]; then ok "AC-CA1 through the installed QuoteAgentCopy on a copy, its own vault read: on() false on the live configuration\'s copy;',
                      '        else bad "AC-CA1 the installed QuoteAgentCopy::on() reads TRUE on the live configuration\'s copy after the copy',
        '        # 5.18.105 (root docs/74 §H.4.4): on() must read what quote_agent_cc_enabled says on the copy — agent-cc kept as found\n'
        '        if [ "$k" != "0" ] && [ "$k" != "1" ]; then bad "AC-CA1 quote_agent_cc_enabled could not be read on the copy through the installed PluginConfig (${k:-not read}) — on() ($(qf_kv "$o" live)) has nothing to be compared with"\n'
        '        elif [ "$(qf_kv "$o" live)" = "$k" ]; then ok "AC-CA1 through the installed QuoteAgentCopy on a copy, its own vault read: on() reads $( [ "$k" = "1" ] && echo "true" || echo "false") on the live configuration\'s copy, exactly as quote_agent_cc_enabled says there (agent-cc $( [ "$k" = "1" ] && echo "on" || echo "off"), kept as found); true with the key set on a scratch copy of those settings (the control); false on that scratch with the profile forced to South Sudan in the file and the vault (the second lock)"\n'
        '        else bad "AC-CA1 the installed QuoteAgentCopy::on() reads $(qf_kv "$o" live) on the live configuration\'s copy, but quote_agent_cc_enabled there says $k — P7 is not as its switch says"; fi ;;',
        why="AC-CA1 live")
    i = doc.t.index('        if [ "$MODE" = "deploy" ] && [ "$QF_G1" = "first" ]; then\n          if [ "$(qf_kv "$o" makers)|$(qf_kv "$o" copies)" = "absent|absent" ]')
    j = doc.t.index('        else note "AC-CA1 P7\'s collections on the book, their rows counted and nothing read from them: $v', i)
    j = doc.t.index("\n", j) + 1
    doc.sub(doc.t[i:j],
            '        note "AC-CA1 P7\'s collections on the book, their rows counted and nothing read from them: $v — $BASELINE_VERSION\'s, kept as they are$( [ "$MODE" = "after" ] && echo " (an --after-only run)")"\n',
            why="AC-CA1 collections")
    doc.mustnot('[ "$QF_G1" = "first" ]')
    # ── AC-CA2: BASELINE, now 994d660 — its tree's folder named for no release ──
    doc.sub('  if dxo cp -R "$P3B_BASE" "$sub/b103" \\\n', '  if dxo cp -R "$P3B_BASE" "$sub/bbase" \\\n', why="AC-CA2 folder")
    doc.sub('sh -c \'cat > "$1"\' _ "$sub/b103/webhook.php" 2>/dev/null \\\n', 'sh -c \'cat > "$1"\' _ "$sub/bbase/webhook.php" 2>/dev/null \\\n', why="AC-CA2 folder 2")
    doc.sub('    o="$(ac_php_ca2 "$sub/code" "$sub/b103" "$sub" "$sub/drive.php")"\n', '    o="$(ac_php_ca2 "$sub/code" "$sub/bbase" "$sub" "$sub/drive.php")"\n', why="AC-CA2 folder 3")
    doc.mustnot('"$sub/b103')
