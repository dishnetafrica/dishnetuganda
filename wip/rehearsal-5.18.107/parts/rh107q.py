#!/usr/bin/env python3
# 5.18.107's rehearsal: its own readers — of the script's own text (the static guard), and of the book, the migration
# ledger, migration.log and the state file where a case judges what the script read. Each rule is written here from the
# RELEASE's own code at its pin (771a3c1: lib/MigrationRunner.php, lib/NoAiList.php, lib/BurstMerge.php and the readers
# they call) or from the script's own lines (deploy-5.18.107.sh at d790edf, cited per mode as S:n), in Python — never by
# running either, so one wrong reading cannot confirm itself. READERS ONLY: the store is opened read-only (mode=ro,
# query_only) and every file is read as bytes; nothing is written anywhere (run with python3 -I). Every mode prints ONE
# line of key=value words — counts, ids, fixed codes, UTC times and sha256 hashes; never a value, a name, a phone, a body
# or an event's detail. Exit 2: an unknown mode or a missing argument; exit 3: unread:<Error>. Modes:
#   verbs107 SCRIPT       the static guard: rh106q.py verbs' rules (rh105q.py's, unchanged) — lk= qftool= wrapper= verbs=
#                         — and, because 5.18.107's three wrapper lines are the assignments of the commands it PRINTS
#                         (S:7330–7332), cmds=<the variables those lines assign, comma-joined, in order> and
#                         printed-only=<how many of them are used only in lines that print them: echo or printf, never
#                         eval, never a bare word, never sh -c>. A wrapper line that is not such an assignment, or a use
#                         that runs one, shows in the counts and the guard refuses.
import re
import sys


def code_lines(t):   # rh106q.py's, unchanged: comment lines (# or //) are not code
    for n, l in enumerate(t.split('\n'), 1):
        s = l.lstrip()
        if s.startswith('#') or s.startswith('//'):
            continue
        yield n, l


CMD_ASSIGN = re.compile(r'^([A-Z][A-Z0-9_]*_CMD)="cd \$REPO && bash \$QF_WRAPPER [^"]*"$')
PRINTS = re.compile(r'^\s*(?:\[[^]]*\]\s*(?:\|\||&&)\s*)?(echo|printf)\b')


def verbs107(path):
    t = open(path, encoding='utf-8', errors='replace').read()
    lk_ = sum(1 for l in t.split('\n') if l.startswith("    case 'lk':"))
    qft = wrp = vb = 0
    cmds = []
    for n, l in code_lines(t):
        if 'qf_tool ' in l and 'qf_tool()' not in l:
            for m in re.finditer(r'qf_tool "\$[A-Za-z0-9_]+(?:/ss)?" ([A-Za-z-]+)', l):
                if m.group(1) not in ('show', '--check', 'doctor'):
                    qft += 1
        if '$QF_WRAPPER' in l and not l.lstrip().startswith('echo '):
            wrp += 1
            m = CMD_ASSIGN.match(l)
            if m:
                cmds.append(m.group(1))
        if re.search(r'sales[-_]followup(\.php|\.sh)?"? (on|off|skip|set|erase)\b', l) and not l.lstrip().startswith('echo ') \
                and '--clear-switches)' not in l:
            vb += 1
        if re.search(r'"\$(?:IN_CONTAINER|d/code|sub/code|inst|P3B_PIN|P3B_BASE)/\$QF_TOOL" (on|off|skip|set|erase)\b', l):
            vb += 1
    printed_only = 0
    for v in cmds:
        uses = [l for n, l in code_lines(t) if re.search(r'\$\{?%s\b' % re.escape(v), l)]
        # every use prints it: the line is an echo or printf (optionally behind a [ … ] || / && guard), the variable
        # appears only inside that command's quoted arguments, and nothing on the line runs a string (eval, sh -c, bash -c)
        ok = bool(uses) and all(PRINTS.match(l) and not re.search(r'\b(eval|sh -c|bash -c)\b', l) for l in uses)
        printed_only += 1 if ok else 0
    print('lk=%d qftool=%d wrapper=%d verbs=%d cmds=%s printed-only=%d'
          % (lk_, qft, wrp, vb, ','.join(cmds) or '-', printed_only))


def main(argv):
    if len(argv) < 2:
        print('unknown'); return 2
    cmd = argv[1]
    try:
        if cmd == 'verbs107' and len(argv) == 3:
            verbs107(argv[2]); return 0
    except Exception as e:   # a reader that could not read says so, and never guesses
        print('unread:%s' % type(e).__name__); return 3
    print('unknown'); return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv))
