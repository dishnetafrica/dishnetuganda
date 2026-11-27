# Part DV — the named display variables (root docs/76 §T.8): one variable per new printed command, each assigned exactly
# once and expanded only inside display text — keepcheck107.py's DISPLAY_VARS is this same list, read by K1 and K5.
#   BD_OFF_CMD, BD_DRAIN_CMD   §T.7 step 4's switch-off and drain reading (rb_pre107, the put-back paragraph, the summary)
#   W1_ON_CMD                  §T.9's owner step 3 (partH prints it)
#   K_OFF_CMD, Q_OFF_CMD       §T.7 step 5's set_config --value 0 commands for K and Q (rb_pre107, the put-back paragraph)
#   K_ON_CMD, Q_ON_CMD         §T.9's owner steps 1 and 2 (partH prints them)
# The first three are assigned at the top level, beside QF_WRAPPER; the four set_config ones by owner_cmds, which replaces
# partR's owner_set_cmd (a printf of the same command) and assigns nothing while the book's owner or the data folder is
# not known. Every printing site expands a variable directly in echo's or printf's arguments: never through another
# assignment, never as a command word.
DISPLAY_VARS = ["BD_OFF_CMD", "BD_DRAIN_CMD", "W1_ON_CMD", "K_OFF_CMD", "Q_OFF_CMD", "K_ON_CMD", "Q_ON_CMD"]

TOP = (
    "# 5.18.107 (root docs/76 §T.8): the named display variables — one per new printed command, each assigned exactly once\n"
    "# and expanded only inside display text (K1 and K5 read the same list): §T.7 step 4's switch-off and drain reading, and\n"
    "# §T.9's owner step 3; owner_cmds (with the rollback's helpers) assigns the four set_config commands once the book's\n"
    "# owner is known. Printed alone on their lines, never run by this script.\n"
    "BD_OFF_CMD=\"cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 0\"\n"
    "BD_DRAIN_CMD=\"cd $REPO && bash $QF_WRAPPER --check burst-drain\"\n"
    "W1_ON_CMD=\"cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 1\"\n")

SC = "php $IN_CONTAINER/tools/set_config.php --key"
CUT = "2>&1 | sed '/^  AI SETTINGS\\$/,\\$d'"
OWNER_CMDS = (
    "# owner_cmds — 5.18.107 (root docs/76 §T.7 step 5, §T.8, §T.9 steps 1 and 2): the owner's own set_config commands, as\n"
    "# the book's owner on the live data folder, each with its output cut where the settings listing begins and its own log:\n"
    "# K_OFF_CMD and Q_OFF_CMD turn K's and Q's switches off (--value 0), K_ON_CMD and Q_ON_CMD turn them on (--value 1).\n"
    "# Named display variables, each assigned here and nowhere else; printed alone on their lines, never run by this script.\n"
    "# Returns 1, assigning nothing, while the book's owner or the data folder is not known.\n"
    "owner_cmds() {\n"
    "  [ -n \"${DB_OWNER:-}\" ] && [ -n \"${PDD_IN:-}\" ] || return 1\n"
    f"  K_OFF_CMD=\"docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER {SC} quoted_no_prices_enabled --value 0 {CUT} $(LOGW setoff-quoted_no_prices_enabled)\"\n"
    f"  Q_OFF_CMD=\"docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER {SC} connection_quote_enabled --value 0 {CUT} $(LOGW setoff-connection_quote_enabled)\"\n"
    f"  K_ON_CMD=\"docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER {SC} quoted_no_prices_enabled --value 1 {CUT} $(LOGW seton-quoted_no_prices_enabled)\"\n"
    f"  Q_ON_CMD=\"docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER {SC} connection_quote_enabled --value 1 {CUT} $(LOGW seton-connection_quote_enabled)\"\n"
    "}\n")

OLD_OSC_HEAD = ("# owner_set_cmd KEY — the owner's own command turning KEY off with the installed tools/set_config.php (--value 0), as the\n"
                "# book's owner on the live data folder, its output cut where the settings listing begins, with its own log (root docs/76\n"
                "# §T.9's owner-command form). Printed alone on its line; never run by this script.\n")
OLD_OSC_FN = ("owner_set_cmd() { printf \"docker exec -u %s -e DN_DATA_DIR=%s %s php %s/tools/set_config.php --key %s --value 0 2>&1 | "
              "sed '/^  AI SETTINGS\\$/,\\$d' %s\" \"$DB_OWNER\" \"$PDD_IN\" \"$CONTAINER\" \"$IN_CONTAINER\" \"$1\" \"$(LOGW \"setoff-$1\")\"; }\n")


def apply(doc, ctx):
    # the top-level three, right after QF_WRAPPER's definition
    doc.insert_after('QF_TOOL="tools/sales_followup.php"; QF_REPORT="tools/sales_followup_report.php"; QF_WRAPPER="scripts/sales-followup.sh"\n',
                     TOP, why="DV: the top-level display variables")
    # owner_set_cmd → owner_cmds (same command text for the two off forms, the same log labels)
    doc.sub(OLD_OSC_HEAD + OLD_OSC_FN, OWNER_CMDS, why="DV: owner_cmds replaces owner_set_cmd")

    # rb_pre107: the switch-off and drain reading, K's and Q's off commands
    doc.sub('    [ "$s2" = "off" ] || echo "      cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 0"   # §K\'s switch-off: printed, never run (RB107)\n'
            '    echo "      cd $REPO && bash $QF_WRAPPER --check burst-drain"   # §K\'s drain reading: printed, never run (RB107)\n',
            '    [ "$s2" = "off" ] || echo "      $BD_OFF_CMD"   # §K\'s switch-off: printed, never run (RB107)\n'
            '    echo "  the drain reading, its own command, alone, again until (a) and (b) both read 0 twice:"\n'
            '    echo "      $BD_DRAIN_CMD"   # §K\'s drain reading: printed, never run (RB107)\n', why="DV: rb_pre107's switch-off and drain")
    doc.sub('    if [ -n "$DB_OWNER" ]; then\n      note "$k $( [ "$q" = "on" ] && echo "READS ON" || echo "COULD NOT BE READ") (NS-G1\'S RULE, ON LK-RB0):',
            '    if owner_cmds; then\n      note "$k $( [ "$q" = "on" ] && echo "READS ON" || echo "COULD NOT BE READ") (NS-G1\'S RULE, ON LK-RB0):',
            why="DV: rb_pre107's K/Q condition")
    doc.sub('      echo "      $(owner_set_cmd "$k")"   # printed, never run (RB107)\n',
            '      case "$k" in quoted_no_prices_enabled) echo "      $K_OFF_CMD" ;; *) echo "      $Q_OFF_CMD" ;; esac   # printed, never run (RB107)\n',
            why="DV: rb_pre107's K/Q commands")
    doc.sub("TOOLS CANNOT TURN IT OFF AFTERWARDS — NO COMMAND CAN BE PRINTED: THE OWNER OF plugin.sqlite3 IS NOT KNOWN\"; fi",
            "TOOLS CANNOT TURN IT OFF AFTERWARDS — NO COMMAND CAN BE PRINTED: THE OWNER OF plugin.sqlite3 OR THE DATA FOLDER IS NOT KNOWN\"; fi",
            why="DV: rb_pre107's no-command text")

    # pb_para: the same three, as printf arguments
    doc.sub("  printf '\\n  The switch-off — it %s:\\n      cd %s && bash %s set ai_burst_merge_enabled 0' \"$wl\" \"$REPO\" \"$QF_WRAPPER\"\n"
            "  printf '\\n  The drain reading — it %s:\\n      cd %s && bash %s --check burst-drain' \"$wd\" \"$REPO\" \"$QF_WRAPPER\"\n",
            "  printf '\\n  The switch-off — it %s:\\n      %s' \"$wl\" \"$BD_OFF_CMD\"\n"
            "  printf '\\n  The drain reading — it %s:\\n      %s' \"$wd\" \"$BD_DRAIN_CMD\"\n", why="DV: pb_para's switch-off and drain")
    doc.sub("    if [ -n \"${DB_OWNER:-}\" ]; then\n      printf '\\n  %s %s (the run",
            "    if owner_cmds; then\n      printf '\\n  %s %s (the run", why="DV: pb_para's K/Q condition")
    doc.sub("Its own command, alone, with its own log — it %s:\\n      %s\\n  It prints the line \"  %s = 0\" once it has saved; "
            "without that line take nothing as saved.' \"$k\" \"$kq\" \"$BASELINE_VERSION\" \"$ws\" \"$(owner_set_cmd \"$k\")\" \"$k\"\n",
            "Its own command, alone, with its own log — it %s:' \"$k\" \"$kq\" \"$BASELINE_VERSION\" \"$ws\"\n"
            "      case \"$k\" in quoted_no_prices_enabled) printf '\\n      %s' \"$K_OFF_CMD\" ;; *) printf '\\n      %s' \"$Q_OFF_CMD\" ;; esac\n"
            "      printf '\\n  It prints the line \"  %s = 0\" once it has saved; without that line take nothing as saved.' \"$k\"\n",
            why="DV: pb_para's K/Q commands")
    doc.sub("none can write it — no command can be printed: the owner of plugin.sqlite3 is not known.'",
            "none can write it — no command can be printed: the owner of plugin.sqlite3 or the data folder is not known.'",
            why="DV: pb_para's no-command text")

    # the summary's rollback paragraph
    doc.sub('  echo "      cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 0"\n',
            '  echo "      $BD_OFF_CMD"\n', why="DV: the summary's switch-off")
    doc.sub('  echo "      $BD_OFF_CMD"\n  echo\n  echo "      cd $REPO && bash $QF_WRAPPER --check burst-drain"\n',
            '  echo "      $BD_OFF_CMD"\n  echo "  then the drain reading, its own command, alone:"\n  echo "      $BD_DRAIN_CMD"\n',
            why="DV: the summary's drain reading, a line of text after the switch-off")

    # ── the result: no command of these left as literal text, owner_set_cmd gone, each variable assigned once ──
    import re
    t = doc.t
    for s in ("owner_set_cmd", "bash $QF_WRAPPER set ai_burst_merge_enabled 0\"\n  echo", "%s set ai_burst_merge_enabled 0'",
              "%s --check burst-drain'"):
        if s in t:
            raise SystemExit(f"partDV: {s!r} is still in the script")
    for v in DISPLAY_VARS:
        n = len(re.findall(rf"(?m)^\s*{v}=", t))
        if n != 1:
            raise SystemExit(f"partDV: {v} is assigned {n} times, not once")
    for v, n in (("BD_OFF_CMD", 3), ("BD_DRAIN_CMD", 3), ("K_OFF_CMD", 2), ("Q_OFF_CMD", 2)):
        got = len(re.findall(rf"\$\{{?{v}\}}?\"", t)) - (0)
        if got != n:
            raise SystemExit(f"partDV: {v} is expanded {got} times, expected {n} before partH")
    if len(re.findall(r"(?m)^\s*if owner_cmds; then$", t)) != 2:
        raise SystemExit("partDV: owner_cmds is not the condition of exactly two sites before partH")
