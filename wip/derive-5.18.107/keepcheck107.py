# keepcheck107.py — the whole-text checks of the 5.18.107 derivation (root docs/76 §T.8): K1–K6 over the result, each
# with weakened copies refused for their own reason alone, then folder_check and its weakened copies. derive107.py
# calls run(doc, ctx) after the last part; nothing here edits the result — a weakened copy is a string, judged and dropped.
#
# Display text (§T.8, docs/75 §L.1), defined once for K1 and K5 by a shell tokenizer, per command rather than per line:
#   - the arguments of echo, ok, bad, note, stop and hdr;
#   - a printf's arguments, unless it is printf -v, piped into a command other than tee, or writing into >( … );
#   - the right-hand side of each named display variable (DISPLAY_VARS, the same list as partDV's) when its assignment is
#     the whole command.
# A command substitution inside display text is code again, except for display commands inside it; a command
# substitution inside code is code whatever it holds. Heredoc bodies are code. Comments (# at a word's start) are
# neither; whole lines beginning # or // are skipped as 5.18.106's checks skipped them.
import re
import sys
import os

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from d107lib import folder_check
import partDV

DISPLAY_VARS = ["BD_OFF_CMD", "BD_DRAIN_CMD", "W1_ON_CMD", "K_OFF_CMD", "Q_OFF_CMD", "K_ON_CMD", "Q_ON_CMD"]
DISPLAY_WORDS = {"echo", "ok", "bad", "note", "stop", "hdr"}
KEYWORDS = {"!", "if", "then", "else", "elif", "do", "while", "until", "{", "}", "time", "fi", "done", "esac", "in"}
ASSIGN = re.compile(r"[A-Za-z_][A-Za-z0-9_]*(\[[^\]]*\])?\+?=")
_WORDRUN = re.compile(r"[^ \t\n;&|()<>'\"`$\\]+")
_DQRUN = re.compile(r'[^"\\$`]+')
_BRRUN = re.compile(r"[^}'\"\\$`]+")
V1_TEXT = "ROLLING BACK — the public address must never redirect"
NA_REFUSAL = ("if (!preg_match('#^/tmp/dnb-[0-9.]+-cih-[0-9]{8}T[0-9]{6}Z-[0-9]+/qf-[a-z0-9]+-[0-9]+$#', $dir) "
              "|| realpath($dir) !== $dir || !is_file($dir . '/plugin.sqlite3')) { echo '?notmp'; exit(2); }")


class ParseError(Exception):
    pass


class _Word:
    __slots__ = ("s", "e", "subs", "psub_out", "redir_out")

    def __init__(self, s):
        self.s, self.e, self.subs, self.psub_out, self.redir_out = s, s, [], False, False


class Tok:
    """One pass over the script: mask[i] is 'C' (code), 'D' (display text) or 'X' (comment)."""

    def __init__(self, t, display_vars):
        self.t, self.n, self.dv = t, len(t), set(display_vars)
        self.mask = bytearray(b"C" * self.n)
        self.pending = []   # heredocs waiting for the next newline at command level: (delimiter, strip_tabs)
        end, spans = self.cmds(0, None)
        if self.pending:
            raise ParseError("a heredoc never ends")
        for s, e in spans:
            self.mask[s:e] = b"D" * (e - s)

    # ── command level ──
    def cmds(self, i, closer):
        t, n = self.t, self.n
        words, spans = [], []
        st = {"ci": None, "case": 0, "paren": 0, "grp": [], "spans": spans}   # grp: len(spans) at each open group (review 3)

        def finish(piped_at=None):
            if words:
                spans.extend(self.judge(words, st["ci"], piped_at))
            words.clear()
            st["ci"] = None

        while True:
            if i >= n:
                if closer is not None:
                    raise ParseError(f"unclosed {closer!r} opened before offset {i}")
                finish()
                return i, spans
            c = t[i]
            if c in " \t":
                i += 1
            elif c == "\\" and t.startswith("\\\n", i):
                i += 2
            elif c == "\n":
                finish()
                i += 1
                if self.pending:
                    i = self.bodies(i)
            elif c == "#":
                j = t.find("\n", i)
                j = n if j < 0 else j
                self.mask[i:j] = b"X" * (j - i)
                i = j
            elif c == ";":
                finish()
                i += 3 if t.startswith(";;&", i) else 2 if t.startswith((";;", ";&"), i) else 1
            elif c == "&" and not t.startswith("&>", i):
                finish()
                i += 2 if t.startswith("&&", i) else 1
            elif c == "|":
                if t.startswith("||", i):
                    finish()
                    i += 2
                else:
                    finish(piped_at=i + (2 if t.startswith("|&", i) else 1))
                    i += 2 if t.startswith("|&", i) else 1
            elif c == "(":
                if t.startswith("((", i):
                    w = _Word(i)
                    w.e = self.arith(i + 2)
                    self.add_word(words, w, st)
                    i = w.e
                else:
                    finish()
                    st["paren"] += 1
                    st["grp"].append(len(spans))
                    i += 1
            elif c == ")":
                if st["paren"] > 0:
                    finish()
                    st["paren"] -= 1
                    self.group_end(i + 1, st)
                    i += 1
                elif st["case"] > 0:
                    finish()
                    i += 1
                elif closer == ")":
                    finish()
                    return i + 1, spans
                else:
                    raise ParseError(f"a stray ')' at offset {i}")
            elif c == "`" and closer == "`":
                finish()
                return i + 1, spans
            else:
                w = self.word(i)
                self.add_word(words, w, st)
                i = w.e

    def add_word(self, words, w, st):
        words.append(w)
        if st["ci"] is None:
            txt = self.t[w.s:w.e]
            if txt in ("{", "if", "for", "while", "until", "select"):
                st["grp"].append(len(st["spans"]))
            elif txt in ("}", "fi", "done", "esac"):
                self.group_end(w.e, st)
            if txt == "esac":
                st["case"] = max(0, st["case"] - 1)
            elif txt in KEYWORDS or ASSIGN.match(txt):
                pass
            else:
                st["ci"] = len(words) - 1
                if txt == "case":
                    st["case"] += 1
                    st["grp"].append(len(st["spans"]))

    def group_end(self, pos, st):
        """a group closes: when its output goes to a pipe or a file, the display text inside it is code (review 3)"""
        if not st["grp"]:
            return
        at = st["grp"].pop()
        m = re.compile(r"[ \t]*(\|\|?|&>|1?>>?|2>|>)").match(self.t, pos)
        if not m:
            return
        op = m.group(1)
        if op == "||" or op == "2>":
            return
        if op.endswith(">") and self.t.startswith("&2", m.end()):
            return
        del st["spans"][at:]

    def judge(self, words, ci, piped_at):
        """The display spans of one finished command."""
        t = self.t
        if ci is None:
            out = []
            for w in words:
                m = ASSIGN.match(t, w.s)
                # a listed variable's plain assignment only: "+=" changes a printed command and is code (review 2)
                if m and t[m.end() - 2] != "+" and t[w.s:m.end() - 1] in self.dv:
                    out += self.cut(m.end(), w.e, w.subs)
            return out
        cw = t[words[ci].s:words[ci].e]
        args = words[ci + 1:]
        if cw == "printf":
            if args and t[args[0].s:args[0].e].startswith("-v"):   # printf -v NAME and printf -vNAME assign (review 2)
                return []
        elif cw not in DISPLAY_WORDS:
            return []
        # (review 2) output that goes anywhere but the terminal is code: any pipe (tee included: it can feed a shell), a
        # process substitution, or standard output redirected to a file; a redirection of standard error (2>…, >&2) is not
        if piped_at is not None:
            return []
        if any(w.psub_out for w in args):
            return []
        if any(w.redir_out for w in words):
            return []
        out = []
        for w in args:
            out += self.cut(w.s, w.e, w.subs)
        return out

    @staticmethod
    def cut(s, e, subs):
        """[s, e) as display text, less each command substitution in it, plus that substitution's own display spans."""
        out, at = [], s
        for ss, se, inner in sorted(subs):
            if ss < at or se > e:
                continue
            if ss > at:
                out.append((at, ss))
            out += inner
            at = se
        if at < e:
            out.append((at, e))
        return out

    def bodies(self, i):
        t, n = self.t, self.n
        for delim, strip in self.pending:
            while True:
                if i >= n:
                    raise ParseError(f"heredoc {delim!r} never ends")
                j = t.find("\n", i)
                j = n if j < 0 else j
                line = t[i:j]
                if (line.lstrip("\t") if strip else line) == delim:
                    i = j + 1
                    break
                self.mask[i:j] = b"H" * (j - i)   # a heredoc body: code (PHP, sums, texts)
                i = j + 1
        self.pending = []
        return i

    # ── a word at command level ──
    def word(self, i):
        t, n = self.t, self.n
        w = _Word(i)
        while i < n:
            m = _WORDRUN.match(t, i)
            if m:
                i = m.end()
                continue
            c = t[i]
            if c in " \t\n;|()" or (c == "\\" and t.startswith("\\\n", i)):
                if c == "(" and i > w.s and t[i - 1] == "=":
                    i, _ = self.cmds(i + 1, ")")   # an array assignment: its contents are code
                    continue
                break
            if c == "&":
                if t.startswith("&>", i):   # &> — both streams to a file; never a zero-length word (review 3)
                    w.redir_out = True
                    i += 1
                    continue
                if i > w.s and t[i - 1] in "<>":
                    i += 1
                    continue
                break
            if c == "\\":
                i += 2
            elif c == "'":
                j = t.find("'", i + 1)
                if j < 0:
                    raise ParseError(f"an unclosed ' at offset {i}")
                self.mask[i:j + 1] = b"Q" * (j + 1 - i)   # single-quoted text: code that no shell expands here
                i = j + 1
            elif c == '"':
                i = self.dq(i + 1, w)
            elif c == "`":
                s = i
                i, inner = self.cmds(i + 1, "`")
                w.subs.append((s, i, inner))
            elif c == "$":
                i = self.dollar(i, w)
            elif c in "<>":
                if t.startswith("<<<", i):
                    i += 3
                elif t.startswith("<<", i):
                    i = self.heredoc_op(i)
                elif i + 1 < n and t[i + 1] == "(":
                    s = i
                    if c == ">":
                        w.psub_out = True
                    i, inner = self.cmds(i + 2, ")")
                    w.subs.append((s, i, inner))
                elif c == ">":   # standard output redirected, spaced or glued; 2> and >&2 are standard error (review 3)
                    k = 2 if t.startswith((">>", ">|"), i) else 1
                    if not (t[w.s:i] == "2" or (t.startswith("&2", i + k) and not t[i + k + 2:i + k + 3].isdigit())):
                        w.redir_out = True
                    i += k
                else:
                    i += 1
            else:
                i += 1
        w.e = i
        return w

    def heredoc_op(self, i):
        t = self.t
        i += 2
        strip = t.startswith("-", i)
        if strip:
            i += 1
        while t[i] in " \t":
            i += 1
        m = re.compile(r"'([^']*)'|\"([^\"]*)\"|\\?([A-Za-z_][A-Za-z0-9_]*)").match(t, i)
        if not m:
            raise ParseError(f"a heredoc delimiter could not be read at offset {i}")
        self.pending.append((m.group(1) if m.group(1) is not None else m.group(2) if m.group(2) is not None else m.group(3), strip))
        return m.end()

    def dollar(self, i, w):
        t = self.t
        if t.startswith("$((", i):
            return self.arith(i + 3)
        if t.startswith("$(", i):
            s = i
            i, inner = self.cmds(i + 2, ")")
            w.subs.append((s, i, inner))
            return i
        if t.startswith("${", i):
            return self.brace(i + 2, w)
        if t.startswith("$'", i):
            j = i + 2
            while t[j] != "'":
                j += 2 if t[j] == "\\" else 1
            return j + 1
        if t.startswith('$"', i):
            return self.dq(i + 2, w)
        if i + 1 < self.n and t[i + 1] in "$?#!@*-0123456789":
            return i + 2   # a special parameter, whole: "$$" is never $ then $"…"
        return i + 1

    def dq(self, i, w):
        t, n = self.t, self.n
        while i < n:
            m = _DQRUN.match(t, i)
            if m:
                i = m.end()
                continue
            c = t[i]
            if c == '"':
                return i + 1
            if c == "\\":
                i += 2
            elif c == "`":
                s = i
                i, inner = self.cmds(i + 1, "`")
                w.subs.append((s, i, inner))
            else:
                i = self.dollar(i, w)
        raise ParseError("an unclosed double quote")

    def brace(self, i, w):
        t, n = self.t, self.n
        while i < n:
            m = _BRRUN.match(t, i)
            if m:
                i = m.end()
                continue
            c = t[i]
            if c == "}":
                return i + 1
            if c == "\\":
                i += 2
            elif c == "'":
                i = t.index("'", i + 1) + 1
            elif c == '"':
                i = self.dq(i + 1, w)
            elif c == "`":
                s = i
                i, inner = self.cmds(i + 1, "`")
                w.subs.append((s, i, inner))
            else:
                i = self.dollar(i, w)
        raise ParseError("an unclosed ${")

    def arith(self, i):
        t, n, depth = self.t, self.n, 0
        while i < n:
            c = t[i]
            if c == "(":
                depth += 1
            elif c == ")":
                if depth == 0:
                    if t.startswith("))", i):
                        return i + 2
                    raise ParseError(f"an arithmetic expression not closed by '))' at offset {i}")
                depth -= 1
            i += 1
        raise ParseError("an unclosed arithmetic expression")


# ── the views the checks read ──
class View:
    def __init__(self, t):
        self.t = t
        self.mask = Tok(t, DISPLAY_VARS).mask
        self.lines = []   # (lineno, raw, code): code keeps the code characters (C, Q, H), every other one a space
        self.shell = {}   # lineno -> the unquoted shell code alone (C)
        self.skipped = set()
        at = 0
        for n, raw in enumerate(t.split("\n"), 1):
            m = self.mask[at:at + len(raw)]
            lead = len(raw) - len(raw.lstrip())
            # (review 3) a line is skipped only as a PHP comment — // or # first, inside a heredoc or a quoted string —
            # never by its raw prefix: a shell line beginning //usr/bin/env, or # inside a string, is code
            if raw.lstrip().startswith(("#", "//")) and lead < len(raw) and m[lead] in (72, 81):
                code, sh = "", ""
                self.skipped.add(n)
            else:
                code = "".join(ch if m[k] in (67, 72, 81) else " " for k, ch in enumerate(raw))
                sh = "".join(ch if m[k] == 67 else " " for k, ch in enumerate(raw))
            self.lines.append((n, raw, code))
            self.shell[n] = sh
            at += len(raw) + 1

    def code_lines(self):
        return [(n, raw, code) for n, raw, code in self.lines if code.strip()]

    def skip(self, n):
        return n in self.skipped or not self.lines[n - 1][2].strip()

    def cls_at(self, off):
        return chr(self.mask[off])


def _func(t, name):
    m = re.search(rf"(?ms)^{re.escape(name)}\(\) \{{\n(.*?)^\}}", t)
    return m.group(1) if m else None


def _qf_php(t):
    m = re.search(r"(?ms)^QF_PHP=\"\$\(cat <<'PHP'\n(.*?)^PHP\n", t)
    return (m.group(1), m.start(1)) if m else (None, None)


def _modes(php):
    """QF_PHP's modes: name → its body (from its case label to the next one)."""
    starts = [(m.group(1), m.start()) for m in re.finditer(r"(?m)^    case '([a-z0-9]+)':", php)]
    out = {}
    for k, (name, s) in enumerate(starts):
        e = starts[k + 1][1] if k + 1 < len(starts) else len(php)
        if name in out:
            raise SystemExit(f"KEEP CHECK: QF_PHP mode {name!r} is labelled twice")
        out[name] = php[s:e]
    return out


def _php_code(body):
    return [l for l in body.split("\n") if l.strip() and not l.lstrip().startswith("//")]


# ── K1 — no clear, no by-hand put-back; the switch tool only shown or checked, on copies; the wrapper and the new owner
#    commands only in display text; the burst switch never written; the drain only on a copy ──
ACT_SET = re.compile(r"\b(?:set|on|off|skip|erase)\s+[\"']?ai_burst_merge_enabled\b")
ACT_KEY = re.compile(r"--key(?:\s+|=)[\"']?ai_burst_merge_enabled\b")
ACT_SAVE = re.compile(r"(?:saveOverrides|writeKycFile)\s*\([^;]*ai_burst_merge_enabled")
DRAIN_OK = re.compile(r"qf_tool \"\$[A-Za-z0-9_]+(?:/ss)?\" --check burst-drain\b")


def _func_lines(v, name):
    """the line numbers of a top-level function's body: from its 'name() {' line to the next line that is '}'"""
    start = [n for n, raw, _ in v.lines if raw.startswith(f"{name}() {{")]
    if len(start) != 1:
        return set()
    end = next((n for n, raw, _ in v.lines if n > start[0] and raw == "}"), None)
    return set(range(start[0], (end or start[0]) + 1))


SHA_LINE = re.compile(r"^[0-9a-f]{64}  tools/(?:sales_followup|set_config)\.php$")
EXPECTED_LINE = re.compile(r'^EXPECTED_CHANGED="[^"$`;&|]*"$')   # the whole line one plain assignment, nothing expanded or run
GREP_ARG = re.compile(r"""\bgrep -[A-Za-z]+ (?:'[^']*'|"(?:[^"\\]|\\.)*") "\$DEST/tools/$""")   # the grep's own file argument
Q6_RUN = "sh -c 'php \"$1/tools/"   # Q-CA6's one run, inside its single-quoted sh -c


def _reader(n, raw, code, pos, extra=()):
    """is the tool name at code[pos] in a reading context (§T.14, per occurrence, never per line)?"""
    if SHA_LINE.match(raw):
        return True
    if EXPECTED_LINE.match(raw):
        return True
    if code[max(0, pos - 14):pos] == "pb_tool tools/":
        return True
    return any(f(n, raw, code, pos) for f in extra)
QF_DEF = 'QF_TOOL="tools/sales_followup.php"; QF_REPORT="tools/sales_followup_report.php"; QF_WRAPPER="scripts/sales-followup.sh"'


def k1(v):
    bad = []
    # the switch tool is reached only through qf_tool: $QF_TOOL in code once, in qf_tool's body; its names in code only in
    # the sha256 lists, EXPECTED_CHANGED, their own definition and the put-back's tools reading (§T.14: closed, not shaped)
    body = _func_lines(v, "qf_tool")
    qt = [n for n, raw, code in v.code_lines() for _ in re.finditer(r"\$\{?QF_TOOL\b", code)]
    if len(qt) != 1 or qt[0] not in body:
        bad.append(("K1", f"$QF_TOOL in code {len(qt)} time(s), not once inside qf_tool's body (lines {qt[:4]})"))
    for n, raw, code in v.code_lines():
        for m in re.finditer(r"sales_followup\.php|sales-followup\.sh", code):
            if raw == QF_DEF or _reader(n, raw, code, m.start()):
                continue
            bad.append(("K1", f"line {n}: the switch tool named in code outside its readers ({raw.strip()[:90]})"))
    for n, raw, code in v.code_lines():
        if re.search(r"\b(qf_clear_and_baseline|qf_byhand)\b", code):
            bad.append(("K1", f"line {n}: a clear call ({raw.strip()[:80]})"))
        for m in re.finditer(r'qf_tool "\$[A-Za-z0-9_]+(?:/ss)?" ([A-Za-z-]+)', code):
            if m.group(1) not in ("show", "--check", "doctor"):
                bad.append(("K1", f"line {n}: the switch tool runs {m.group(1)}"))
        if re.search(r"\$\{?QF_WRAPPER\b", code):
            bad.append(("K1", f"line {n}: the wrapper outside display text"))
        for rx, what in ((ACT_SET, "the burst switch set"), (ACT_KEY, "the burst switch given to set_config"),
                         (ACT_SAVE, "the burst switch in a settings write")):
            if rx.search(code):
                bad.append(("K1", f"line {n}: {what} outside display text ({raw.strip()[:90]})"))
        for m in re.finditer(r"burst-drain", code):
            pre = code[:m.end()]
            if re.search(r"--check\s+[\"']?burst-drain$", pre):
                if not DRAIN_OK.search(code, max(0, m.end() - 80)) or not re.search(
                        r"qf_tool \"\$[A-Za-z0-9_]+(?:/ss)?\" --check burst-drain$", pre):
                    bad.append(("K1", f"line {n}: the drain reading run other than through qf_tool on a copy"))
            elif not code.startswith("burst-drain: ", m.start()):
                bad.append(("K1", f"line {n}: burst-drain in code other than qf_tool's check or its output's prefix"))
    return bad


# ── K2 — LK-CA1 defined with its comparison and both verdicts, called once, from qf_after ──
def k2(v):
    bad, t = [], v.t
    body = _func(t, "lk_ca1")
    if not body or 'ok "LK-CA1' not in body or 'bad "LK-CA1' not in body or '"$(lk_map "$LK_B1")"' not in body:
        bad.append(("K2", "lk_ca1 is not defined with its comparison and both verdicts"))
    qa = _func(t, "qf_after")
    calls = [raw for _, raw, code in v.code_lines() if re.match(r"\s+lk_ca1\b", code)]
    if not qa or len(calls) != 1 or not re.search(r"(?m)^\s+lk_ca1\b", qa):
        bad.append(("K2", f"LK-CA1 is not called once from qf_after ({len(calls)} call(s))"))
    return bad


# ── K3 — 5.18.107's V1 runs no QF-RB1 and no switch-tool verb before do_rollback; qf_rb1 is in no code ──
def k3(v):
    bad = []
    v1 = [(n, code) for n, raw, code in v.code_lines() if V1_TEXT in raw]
    if len(v1) != 1:
        return [("K3", f"V1's automatic rollback line found {len(v1)} times")]
    n, code = v1[0]
    if not re.search(r"\bif do_rollback; then QCV1_RB=ok; else QCV1_RB=failed; fi", code):
        bad.append(("K3", f"line {n}: V1 does not run do_rollback and record its outcome"))
    else:
        before = code.split("do_rollback")[0]
        if re.search(r"\bqf_rb1\b|\bqf_tool\b|\$\{?QF_WRAPPER\b|\$\{?QF_TOOL\b|sales-followup\.sh|sales_followup\.php", before):
            bad.append(("K3", f"line {n}: V1 runs QF-RB1 or the switch tool before its put-back"))
    if any(re.search(r"\bqf_rb1\b", code) for _, _, code in v.code_lines()):
        bad.append(("K3", "qf_rb1 is in the code"))
    return bad


# ── K4 — quote_followup_state's never-fell count over its rows NOT LIKE 'list_last:%' (carried) ──
def k4(v):
    bad, t = [], v.t
    kept = [raw for _, raw, code in v.code_lines() if "'quote_followup_state_kept:'" in raw]
    if len(kept) != 1 or "$cnt('quote_followup_state', \" WHERE k NOT LIKE 'list_last:%'\")" not in kept[0]:
        bad.append(("K4", "quote_followup_state's never-fell count is not taken over its rows NOT LIKE 'list_last:%'"))
    lists = re.findall(r"foreach \(\[('quote_tracker'[^\]]*)\] as \$t\)", t)
    if len(lists) != 1 or "'quote_followup_state'" in lists[0]:
        bad.append(("K4", f"the tables that never fall: {len(lists)} list(s), or quote_followup_state counted whole among them"))
    return bad


# ── K4b — lk_rows_judge accepts exactly one extra _schema_checks row, 092_wa_no_ai.sql:5/5, only where MG-G1 read 092
#    absent; the exact equality first ──
K4B_EQ = 'if [ "$sca" = "$scb" ]; then ok "$c every _schema_checks row unchanged $w'
K4B_X = ("scx=\"$(printf '%s\\n' \"$scb\" \"${MIG107_SC%%:*}:${MIG107_SC##*:}/${MIG107_SC##*:}\" | tr ',' '\\n' | "
         "grep -v '^-*$' | sort | paste -sd, -)\"")
K4B_G1 = ('case "$MODE" in after) sed -n \'s/^MG_G1=//p\' "$STATE" 2>/dev/null | tail -n1 ;; rollback) case "${DBS_A:-}" in '
          '*" ledger="*":$MIG104_LAST:"*) printf absent ;; *" ledger="*":$MIG107_LAST:"*) printf present ;; esac ;; '
          '*) printf \'%s\' "${MG_G1:-}" ;; esac;')
K4B_ONE = ("elif [ \"$(lk_mg_g1)\" = \"absent\" ] && [ \"$(printf '%s' \"$sca\" | tr ',' '\\n' | sort | paste -sd, -)\" = "
           "\"$scx\" ]; then ok \"$c every _schema_checks row unchanged $w but one added")


def k4b(v):
    bad, t = [], v.t
    body = _func(t, "lk_rows_judge")
    if body is None:
        return [("K4b", "lk_rows_judge is not defined")]
    lines = [l.strip() for l in body.split("\n") if l.strip() and not l.lstrip().startswith("#")]
    eq = [k for k, l in enumerate(lines) if l.startswith(K4B_EQ)]
    one = [k for k, l in enumerate(lines) if l.startswith(K4B_ONE)]
    sx = [k for k, l in enumerate(lines) if l == K4B_X]
    if len(eq) != 1:
        bad.append(("K4b", f"lk_rows_judge's exact equality of the _schema_checks rows found {len(eq)} times"))
    if len(one) != 1 or len(sx) != 1:
        bad.append(("K4b", "lk_rows_judge does not accept exactly the one row MIG107_SC adds, only where MG-G1 read 092 absent"))
    elif eq and not (sx[0] < eq[0] < one[0] and one[0] + 1 < len(lines) and lines[one[0] + 1].startswith('else bad "$c a _schema_checks row changed $w')):
        bad.append(("K4b", "lk_rows_judge's verdicts are not exact equality, then the one row, then FAIL"))
    if len([l for l in body.split("\n") if "_schema_checks" in l and re.search(r"\bok\b", l)]) != 2:
        bad.append(("K4b", "lk_rows_judge has an acceptance of the _schema_checks rows other than the two"))
    defs = re.findall(r"(?m)^MIG107_SC=(.*)$", t)
    if defs != ['"092_wa_no_ai.sql:5"']:
        bad.append(("K4b", f"MIG107_SC is not defined once as 092_wa_no_ai.sql:5 ({defs})"))
    g1 = re.findall(r"(?m)^lk_mg_g1\(\) \{ (.*) \}$", t)
    if g1 != [K4B_G1]:
        bad.append(("K4b", "lk_mg_g1 does not read MG-G1's verdict (this run's, the state file's in --after-only) or, in --rollback, "
                           "the rollback's own stage-A ledger as R7 reads it"))
    # LK-RB1 and R7 accept 092 applied across a rollback on one condition (§T.3 items 8 and 9): the same DBS_A pattern
    if len(re.findall(r'(?m)^DBS_RB="\$\(db_state\)"; db_judge RB "\$\{DBS_A:-\}" "\$DBS_RB" "across the rollback" strict '
                      r'"\$\(case "\$\{DBS_A:-\}" in \*" ledger="\*":\$MIG104_LAST:"\*\) echo either ;; \*\) echo same ;; esac\)"', t)) != 1:
        bad.append(("K4b", "R7's rollback rule no longer reads 092 absent from DBS_A as lk_mg_g1 does"))
    return bad


# ── K5 — no writer of a key in the code; two known exceptions; the named display variables assigned once, printed,
#    never run ──
WRITER = re.compile(r"saveOverrides\s*\(|writeKycFile\s*\(|->save\(\s*[\"']kyc_config\.json[\"']"
                    r"|(?<![\w./-])php\s+[\"']?[^\s\"']*tools/set_config\.php"
                    r"|(?:qf_tool\s+\"\$[A-Za-z0-9_]+(?:/ss)?\"|sales-followup\.sh[\"']?|sales_followup\.php[\"']?)\s+(?:on|off|skip|set|erase)\b")
DV_RHS = {
    "BD_OFF_CMD": "cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 0",
    "BD_DRAIN_CMD": "cd $REPO && bash $QF_WRAPPER --check burst-drain",
    "W1_ON_CMD": "cd $REPO && bash $QF_WRAPPER set ai_burst_merge_enabled 1",
}
DV_OWNER = {"K_OFF_CMD": ("quoted_no_prices_enabled", "0", "setoff"), "Q_OFF_CMD": ("connection_quote_enabled", "0", "setoff"),
            "K_ON_CMD": ("quoted_no_prices_enabled", "1", "seton"), "Q_ON_CMD": ("connection_quote_enabled", "1", "seton")}


def k5(v):
    bad, t = [], v.t
    if DISPLAY_VARS != partDV.DISPLAY_VARS:
        bad.append(("K5", "keepcheck107's display variables are not partDV's list"))
    hits = []
    for n, raw, code in v.code_lines():
        for m in WRITER.finditer(code):
            hits.append((n, raw, m.group(0)))
    slr = [h for h in hits if '->save("kyc_config.json", ["tenant_profile" => $profile])' in h[1]
           and "SqliteStore::create($__live)" in h[1]]
    qca6 = [h for h in hits if "tools/set_config.php" in h[2] and "ucrm_quote_organization_id" in h[1]
            and "--value disabled" in h[1] and 'DN_DATA_DIR="$d"' in h[1] and 'DN_VAULT_FILE="$d/vault.json"' in h[1]]
    rest = [h for h in hits if h not in slr and h not in qca6]
    if len(slr) != 1:
        bad.append(("K5", f"SLR_PHP's throwaway save found {len(slr)} times"))
    elif '$__live = $tmp . "/plugins/.dishnet-hybrid-sudan-data"' not in t or "[$root, $tmp, $profile, $mode] = array_slice($argv, 1, 4);" not in t:
        bad.append(("K5", "SLR_PHP's save is not shown to be under its throwaway folder"))
    if len(qca6) != 1:
        bad.append(("K5", f"Q-CA6's throwaway set_config run found {len(qca6)} times"))
    else:
        qn = qca6[0][0]
        ctxl = "\n".join(raw for n, raw, _ in v.lines[max(0, qn - 6):qn - 1])
        if not re.search(r'(?m)^\s*d="/tmp/dnb-\$EXPECTED_VERSION-qca6-\$TS-\$\$-\$RANDOM"$', ctxl):
            bad.append(("K5", "Q-CA6's data folder is not shown to be the run's own under /tmp"))
    if rest:
        bad.append(("K5", f"{len(rest)} writer(s) in the code besides the two exceptions — 5.18.107 writes no key: "
                          + "; ".join(f"line {n}: {w}" for n, _, w in rest[:4])))
    # set_config.php named in code only where it is read, never where it could be run (§T.14: closed, not shaped): the
    # sha256 lists, EXPECTED_CHANGED, a grep of the installed file, the put-back's tools reading, and Q-CA6's one run
    q6n = {qca6[0][0]} if len(qca6) == 1 else set()
    for n, raw, code in v.code_lines():
        # RB's failure labels, printed later: a word list with no command in it (review 2: never a "php" or a "docker")
        labels = [x.span() for x in re.finditer(r'\bRB_[A-Z]+="\$RB_[A-Z]+ [^"$`;&|]*"', code)
                  if not re.search(r"(?<![\w./:-])(?:php|docker|sh|bash)\b", x.group(0))]
        for m in re.finditer(r"set_config\.php", code):
            if any(a < m.start() < b for a, b in labels):
                continue
            if _reader(n, raw, code, m.start(), extra=(
                    lambda n, raw, code, pos: bool(GREP_ARG.search(code[:pos])),
                    lambda n, raw, code, pos: n in q6n and code.count("set_config.php") == 1 and code[max(0, pos - len(Q6_RUN)):pos] == Q6_RUN)):
                continue
            bad.append(("K5", f"line {n}: set_config.php named in code outside its readers and Q-CA6's run ({raw.strip()[:90]})"))
    # the named display variables
    for var in DISPLAY_VARS:
        # every form that sets it (review 2): NAME=, NAME+=, printf -v NAME, read/declare/local/export … NAME
        asg = [(n, raw) for n, raw, code in v.code_lines()
               for _ in re.finditer(rf"(?<![\w$]){var}\+?=|\bprintf\s+-v\s*{var}\b|\b(?:read|declare|typeset|local|export|readonly)\b[^;&|\n]*\b{var}\b", code)]
        if len(asg) != 1:
            bad.append(("K5", f"{var} is assigned {len(asg)} times, not once"))
        else:
            n, raw = asg[0]
            m = re.match(rf'^\s*{var}="(.*)"$', raw)
            if not m:
                bad.append(("K5", f"line {n}: {var}'s assignment is not the whole command"))
            elif var in DV_RHS and m.group(1) != DV_RHS[var]:
                bad.append(("K5", f"line {n}: {var} does not hold its command"))
            elif var in DV_OWNER:
                key, val, lab = DV_OWNER[var]
                want = (f"docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER php $IN_CONTAINER/tools/set_config.php "
                        f"--key {key} --value {val} 2>&1 | sed '/^  AI SETTINGS\\$/,\\$d' $(LOGW {lab}-{key})")
                if m.group(1) != want:
                    bad.append(("K5", f"line {n}: {var} does not hold its command"))
            off = sum(len(r) + 1 for _, r, _ in v.lines[:n - 1]) + raw.index(f"{var}=") + len(var) + 2
            if v.cls_at(off) != "D":
                bad.append(("K5", f"line {n}: {var}'s right-hand side is not display text"))
        shown = 0
        for m in re.finditer(rf"\$\{{?{var}\b", t):
            c = v.cls_at(m.start())
            n = t.count("\n", 0, m.start()) + 1
            raw = v.lines[n - 1][1]
            if n in v.skipped or c == "X":
                continue
            if c == "D":
                shown += 1
                continue
            line_code = v.lines[n - 1][2]
            col = m.start() - (t.rfind("\n", 0, m.start()) + 1)
            pre = line_code[:col]
            if re.search(r"(?:^|[;&|(){]|\bthen|\bdo|\belse)\s*\"?$", pre):
                how = "as a command word"
            elif re.search(r"\beval\s+\"?$", pre):
                how = "in eval"
            elif re.search(r"\b(?:sh|bash)\s+-c\s+\"?$", pre):
                how = "in sh -c or bash -c"
            else:
                how = "in code"
            bad.append(("K5", f"line {n}: {var} expanded {how}, not printed"))
        if shown == 0:
            bad.append(("K5", f"{var} is never printed"))
    return bad


# ── K6 — the trial and the list probes touch copies only ──
def _call_dir_ok(v, n, mode):
    """the qf_php call on line n passes "$sub/code" "$sub", sub made by qf_copy earlier in the same function"""
    raw = v.lines[n - 1][1]
    if not re.search(rf'qf_php {mode} "\$sub/code" "\$sub"(?:\s|"|\)|$)', raw):
        return f"line {n}: qf_php {mode} is not given a copy (\"$sub/code\" \"$sub\")"
    for k in range(n, 0, -1):
        prev = v.lines[k - 1][2] if k != n else v.lines[k - 1][2].split(f"qf_php {mode}")[0]
        if re.match(r"^[A-Za-z_][A-Za-z0-9_]*\(\) \{", v.lines[k - 1][1]):
            break
        m = re.search(r'(?<![\w$])sub=("?\$\(qf_copy \S+ [^)]*\)"?|\S*)', prev)
        if m:
            return None if m.group(1).startswith('"$(qf_copy ') else f"line {k}: the copy qf_php {mode} reads is not qf_copy's"
    return f"line {n}: no qf_copy before qf_php {mode} in its function"


def k6(v):
    bad, t = [], v.t
    php, php_at = _qf_php(t)
    if php is None:
        return [("K6", "QF_PHP is not found")]
    modes = _modes(php)
    # every new MigrationRunner( in the script sits in QF_PHP
    mr_at = [m.start() for m in re.finditer(r"new MigrationRunner\(", t)
             if (t.count("\n", 0, m.start()) + 1) not in v.skipped and v.cls_at(m.start()) != "X"]
    outside = [p for p in mr_at if not (php_at <= p < php_at + len(php))]
    if outside:
        bad.append(("K6", f"{len(outside)} new MigrationRunner( outside QF_PHP"))
    mr_modes = sorted(name for name, body in modes.items() if any("new MigrationRunner(" in l for l in _php_code(body)))
    if mr_modes != ["neg", "neg092", "trial"]:
        bad.append(("K6", f"the modes running a MigrationRunner are {mr_modes}, not trial, neg and neg092"))
    for name in mr_modes:
        code = _php_code(modes[name])
        if "[$root, $dir] = $a;" not in [l.strip() for l in code]:
            bad.append(("K6", f"mode {name}: its arguments are not ROOT DIR"))
        ps = [l.strip() for l in code if re.match(r"\s*\$p\s*=", l)]
        if ps != ["$p = $rw($dir . '/plugin.sqlite3');"]:
            bad.append(("K6", f"mode {name}: its database is not DIR's copy alone ({ps[:3]})"))
        for l in code:
            for m in re.finditer(r"new MigrationRunner\(([^,]*),", l):
                if m.group(1).strip() != "$p":
                    bad.append(("K6", f"mode {name}: a MigrationRunner on {m.group(1).strip()}, not DIR's copy"))
    # every write of wa_no_ai's rows or shape: INSERT and ALTER in one mode that refuses a path outside the run's /tmp;
    # DROP and CREATE only in a mode above
    wr = {}
    for name, body in modes.items():
        for l in _php_code(body):
            for m in re.finditer(r"(?i)\b(insert(?:\s+or\s+\w+)?\s+into|alter\s+table|drop\s+table(?:\s+if\s+exists)?|"
                                 r"create\s+table(?:\s+if\s+not\s+exists)?|update|delete\s+from|replace\s+into)\s+\"?wa_no_ai\b", l):
                wr.setdefault(name, []).append(m.group(1).split()[0].upper())
    for n, raw, code in v.code_lines():
        if re.search(r"(?i)\b(insert\s+into|alter\s+table|drop\s+table|create\s+table|update|delete\s+from)\s+\"?wa_no_ai\b", code):
            off = sum(len(r) + 1 for _, r, _ in v.lines[:n - 1])
            if not (php_at <= off < php_at + len(php)):
                bad.append(("K6", f"line {n}: wa_no_ai written outside QF_PHP"))
    ia = sorted(name for name, ks in wr.items() if any(k in ("INSERT", "ALTER") for k in ks))
    if ia != ["na"]:
        bad.append(("K6", f"wa_no_ai's INSERT and ALTER sit in {ia}, not in one mode, na"))
    for name, ks in wr.items():
        if name != "na" and name not in mr_modes:
            bad.append(("K6", f"mode {name} writes wa_no_ai ({', '.join(sorted(set(ks)))}) and is not checked to run on a copy"))
    if "na" in modes:
        code = [l.strip() for l in _php_code(modes["na"])]
        if len(code) < 3 or not code[1].startswith("[$root, $dir, $s1, $s2] = ") or code[2] != NA_REFUSAL:
            bad.append(("K6", "mode na's first statement does not refuse a path outside the run's /tmp"))
        body = "\n".join(code)
        if "$w = $dir . '/na-throwaway.sqlite3';" not in body or "$q = $rw($w);" not in body or \
                "foreach (['', '-wal', '-shm', '-journal'] as $sfx) @unlink($w . $sfx);" not in body or \
                re.search(r"\$(?!q\b)\w+->(?:prepare|exec)\(\s*[\"'](?:INSERT INTO|ALTER TABLE) wa_no_ai", body):
            bad.append(("K6", "mode na's INSERT and ALTER are not on its throwaway copy inside DIR, removed again"))
    else:
        bad.append(("K6", "mode na is not found"))
    # the shell side: each mode above, and na, called only with a copy made by qf_copy; QF_PHP run only by qf_php
    if len([1 for _, _, code in v.code_lines() if re.search(r"\$\{?QF_PHP\b", code)]) != 1 or \
            not re.search(r'(?m)^qf_php\(\) \{ dxo php [^\n]*-r "\$QF_PHP" "\$@"; \}', t):
        bad.append(("K6", "QF_PHP is run other than by qf_php alone"))
    for name in mr_modes + ["na"]:
        calls = [n for n, raw, code in v.code_lines() if re.search(rf"\bqf_php {name}\b", code)]
        if not calls:
            bad.append(("K6", f"qf_php {name} is never called"))
        for n in calls:
            e = _call_dir_ok(v, n, name)
            if e:
                bad.append(("K6", e))
    return bad


# ── K7 — no construct that can hide a run (§T.14, review 2 of the derived script). K1's and K5's readers recognise a tool
#    by its name; a name built by a glob, a variable, an indirect expansion, a string handed to a shell or an eval would pass
#    them. None of these constructs is in the script, so each is refused outright, with one named exception: the documented
#    put-back, bash "$REPO/scripts/deploy-hybrid.sh". A name assembled by concatenation stays beyond any text check; the
#    rehearsal runs the script (§T.11) ──
HIDERS = [   # (review 3) each found anywhere in code, not anchored to a line's start
    (re.compile(r"(?<![\w$./>:-])eval\b"), "eval"),
    (re.compile(r"(?<![\w$./>:-])source\s+[\"$/]"), "a file sourced"),
    (re.compile(r"\b(?:sh|bash|dash|zsh)\s*<(?!<)"), "a shell reading a script from a redirection"),
    (re.compile(r"\|\s*(?:sh|bash|dash|zsh)\b(?!\s+-n)"), "a shell reading a pipe"),
    (re.compile(r"\$\{!"), "an indirect expansion ${!…}"),
    (re.compile(r"\b(?:sh|bash|dash|zsh)\s+-c\s+[\"$]"), "a shell given an expanding command string"),
    (re.compile(r"(?:tools|scripts)/[^\s\"';|&)]*[*?\[]"), "a glob in a tools/ or scripts/ path"),
    (re.compile(r"(?:tools|scripts)/[\"']?\$"), "a file name under tools/ or scripts/ held in a variable"),
    (re.compile(r"\bprintf\s+-v"), "printf -v"),
    (re.compile(r"\bcall_user_func(?:_array)?\b"), "call_user_func"),
    (re.compile(r"[\"'](?:saveOverrides|writeKycFile)[\"']"), "a writer named as a string"),
    (re.compile(r"->save\(\s*\$"), "a ->save( whose file name is a variable"),
    (re.compile(r"\bphp\s+-f\b"), "php -f"),
    (re.compile(r"(?<![\w./-])(?:bash|sh)\s+[\"']?\$"), "a script run from a variable path"),
]
# the dot form of sourcing, on unquoted shell code alone: in PHP (heredocs, quoted strings) "." is concatenation
DOT = re.compile(r"(?:^\s*|[;&|({]\s*|\b(?:then|do|else)\s+|\)\s+)\.\s+[\"$/]")
RUN_OK = 'bash "$REPO/scripts/deploy-hybrid.sh"'


def k7(v):
    bad = []
    for n, raw, code in v.code_lines():
        for rx, what in HIDERS:
            for m in rx.finditer(code):
                if what == "a script run from a variable path" and code.startswith(RUN_OK, m.start()) \
                        and code[m.start() + len(RUN_OK):m.start() + len(RUN_OK) + 1] in ("", " ", "\t", ";", "|", "&", ")"):
                    continue   # the documented put-back, as a whole word (review 3)
                bad.append(("K7", f"line {n}: {what} in code ({raw.strip()[:80]})"))
        if DOT.search(v.shell[n]):
            bad.append(("K7", f"line {n}: a file sourced in code ({raw.strip()[:80]})"))
    return bad


def keep_check(t):
    try:
        v = View(t)
    except ParseError as e:
        return [("PARSE", str(e))]
    return k1(v) + k2(v) + k3(v) + k4(v) + k4b(v) + k5(v) + k6(v) + k7(v)


def run(doc, ctx):
    kb = keep_check(doc.t)
    if kb:
        raise SystemExit("KEEP CHECK: " + "; ".join(f"{a}: {b}" for a, b in kb[:12]))
    n_ctl = [0]

    def kweak(old, new, tag, what, expect):
        """a weakened copy: refused by exactly the checks named in tag (one, or a tuple), and among the messages the one its
        rule gives (expect), so a copy that two rules refuse still proves the rule it is there for (review 2)"""
        if doc.t.count(old) != 1:
            raise SystemExit(f"KEEP CHECK CONTROL ({what}): its anchor is found {doc.t.count(old)} times: {old[:100]!r}")
        got = keep_check(doc.t.replace(old, new))
        tags = sorted({a for a, _ in got})
        want = sorted(tag) if isinstance(tag, tuple) else [tag]
        if tags != want:
            raise SystemExit(f"KEEP CHECK CONTROL ({what}): refused for {tags or 'nothing'}, not for exactly {want}: {got[:3]}")
        if not any(expect in m for _, m in got):
            raise SystemExit(f"KEEP CHECK CONTROL ({what}): refused, but not by the rule it is for ({expect!r}): {got[:3]}")
        n_ctl[0] += 1

    t = doc.t
    rb0 = [l for l in t.split("\n") if l.startswith("  cih_main || note \"LK-RB0 the run's folder")]
    if len(rb0) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL: rb_pre107's cih_main line found {len(rb0)} times")
    RB0 = rb0[0] + "\n"

    def ins(line):   # a line inserted in code, after rb_pre107's cih_main line
        return RB0 + "  " + line + "\n"

    LIVE = 'docker exec -u "$DB_OWNER" -e DN_DATA_DIR="$PDD_IN" "$CONTAINER"'
    W5 = "writer(s) in the code besides the two exceptions"
    N5 = "set_config.php named in code outside its readers"
    # K1
    kweak("  qf_keep_and_baseline\n", "  qf_clear_and_baseline\n  qf_keep_and_baseline\n", "K1", "the clear call re-inserted", "a clear call")
    kweak(RB0, ins('sub="$(qf_copy rbx "$P3B_PIN")" && qf_tool "$sub" status'), "K1", "the switch tool run with another verb",
          "the switch tool runs status")
    kweak(RB0, ins('( cd "$REPO" && bash "$QF_WRAPPER" show )'), ("K1", "K7"), "the wrapper run", "the wrapper outside display text")
    kweak(RB0, ins(f'for f in "$IN_CONTAINER"/tools/sales_f*.php; do {LIVE} php "$f" set ai_burst_merge_enabled 0; done'), ("K1", "K7"),
          "set ai_burst_merge_enabled 0 through a glob (K1's act clause; K7's glob)", "the burst switch set outside display text")
    kweak(RB0, ins(f'for f in "$IN_CONTAINER"/tools/set_c*.php; do {LIVE} php "$f" --key ai_burst_merge_enabled --value 0; done'),
          ("K1", "K7"), "--key ai_burst_merge_enabled through a glob (K1's act clause; K7's glob)", "the burst switch given to set_config")
    kweak(RB0, ins(f'{LIVE} php "$IN_CONTAINER/$QF_TOOL" erase'), "K1", "the switch tool's erase through $QF_TOOL, outside qf_tool",
          "$QF_TOOL in code")
    kweak(RB0, ins('T="$IN_CONTAINER/tools/sales_followup.php"'), "K1", "the switch tool named in code outside its readers",
          "the switch tool named in code outside its readers")
    kweak(RB0, ins('bash "$REPO/scripts/sales-followup.sh" --check burst-drain >/dev/null'), ("K1", "K7"),
          "a live sales-followup.sh --check burst-drain outside display text", "the drain reading run other than through qf_tool on a copy")
    kweak(RB0, ins('o="$(qf_php rbx "$PDD_IN" burst-drain)"'), "K1", "burst-drain handed to code other than qf_tool's check",
          "burst-drain in code other than qf_tool's check")
    # K2
    ca1 = [l for l in t.split("\n") if re.match(r"  lk_ca1\b", l)]
    if len(ca1) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL: the LK-CA1 call line found {len(ca1)} times")
    kweak(ca1[0] + "\n", "", "K2", "LK-CA1 removed", "LK-CA1 is not called once from qf_after")
    # K3
    V1E = 'esac )"; if do_rollback; then QCV1_RB=ok'
    kweak(V1E, 'esac )"; qf_rb1; if do_rollback; then QCV1_RB=ok', "K3", "QF-RB1 called again in V1",
          "V1 runs QF-RB1 or the switch tool before its put-back")
    kweak(V1E, 'esac )"; sub="$(qf_copy v1x "$P3B_PIN")" && qf_tool "$sub" show >/dev/null; if do_rollback; then QCV1_RB=ok', "K3",
          "the switch tool run in V1 before its put-back", "V1 runs QF-RB1 or the switch tool before its put-back")
    # K4
    kweak("$cnt('quote_followup_state', \" WHERE k NOT LIKE 'list_last:%'\")", "$cnt('quote_followup_state')", "K4",
          "quote_followup_state counted over every row", "never-fell count is not taken over its rows")
    # K4b
    one = [l for l in t.split("\n") if l.strip().startswith(K4B_ONE)]
    if len(one) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL (K4b): the one-row acceptance found {len(one)} times")
    ONE = one[0]
    A1 = "does not accept exactly the one row"
    kweak(ONE, ONE.replace("[ \"$(printf '%s' \"$sca\" | tr ',' '\\n' | sort | paste -sd, -)\" = \"$scx\" ]",
                           "[ -n \"$sca\" ]"), "K4b", "any extra row accepted", A1)
    kweak(ONE + "\n", "", "K4b", "the exact equality restored alone (fails on a first deploy)", A1)
    kweak(ONE, ONE.replace('[ "$(lk_mg_g1)" = "absent" ] && ', ""), "K4b", "the one row accepted whatever MG-G1 read", A1)
    kweak('rollback) case "${DBS_A:-}" in *" ledger="*":$MIG104_LAST:"*) printf absent ;; *" ledger="*":$MIG107_LAST:"*) printf present ;; esac ;; ',
          "", "K4b", "lk_mg_g1 in --rollback reading MG_G1, which no rollback sets", "lk_mg_g1 does not read MG-G1's verdict")
    # K5: each detector form in code
    kweak(RB0, ins("printf '%s' \"<?php PluginConfig::saveOverrides(\\$d, ['followup_enabled' => '0']);\" | docker exec -i \"$CONTAINER\" php"),
          "K5", "a saveOverrides in a printf piped into docker exec php", W5)
    kweak(RB0, ins("dxo php -r 'require \"/x/lib/KycService.php\"; KycService::writeKycFile(\"/x\", []);'"), "K5", "a writeKycFile call", W5)
    kweak(RB0, ins("dxo php -r '$s = SqliteStore::create(\"'\"$PDD_IN\"'\"); $s->save(\"kyc_config.json\", []);'"), "K5",
          "a ->save of kyc_config.json on the live data folder", W5)
    q6 = [l for l in t.split("\n") if "ucrm_quote_organization_id" in l and "--value disabled" in l and 'DN_DATA_DIR="$d"' in l]
    if len(q6) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL (K5): Q-CA6's run found {len(q6)} times")
    kweak(q6[0], q6[0].replace('DN_DATA_DIR="$d"', 'DN_DATA_DIR="$PDD_IN"'), "K5", "Q-CA6's set_config run pointed at $PDD_IN",
          "Q-CA6's throwaway set_config run found 0 times")
    kweak(q6[0], q6[0] + f'; {LIVE} php "$IN_CONTAINER/tools/set_config.php" --key followup_enabled --value 0 >/dev/null', "K5",
          "a second set_config run hidden on Q-CA6's line", N5)
    kweak(RB0, ins('bash "$REPO/scripts/sales-followup.sh" off chase'), ("K1", "K5", "K7"),
          "the switch tool's off, run live (K1: named outside its readers; K5: a writer; K7: a script run from a variable path)", W5)
    kweak(RB0, ins(f'SC="$IN_CONTAINER/tools/set_config.php"; {LIVE} php "$SC" --key followup_enabled --value 0'), "K5",
          "set_config run through a variable", N5)
    kweak(RB0, ins(f'( grep -q . /dev/null; DEST="$IN_CONTAINER"; {LIVE} php "$DEST/tools/set_config.php" --key followup_enabled --value 0 )'),
          "K5", "set_config run on a line that also greps (the reader is per occurrence)", N5)
    kweak(RB0, ins(f'EXPECTED_CHANGED="$EXPECTED_CHANGED"; {LIVE} php "$IN_CONTAINER/tools/set_config.php" --key followup_enabled --value 0'),
          "K5", "set_config run on a line that begins as EXPECTED_CHANGED's", N5)
    kweak(RB0, ins('RB_ZZ="$RB_ZZ php -f /x/tools/set_config.php -- --key followup_enabled --value 0"; docker exec "$CONTAINER" $RB_ZZ'),
          ("K5", "K7"), "a run disguised as an RB failure label", N5)
    # the tokenizer's own controls: output that leaves the terminal is code — a substitution, a pipe, tee, a file
    kweak(RB0, ins('note "RB107 $(docker exec "$CONTAINER" php "$IN_CONTAINER/tools/set_config.php" --key followup_enabled --value 0)"'),
          "K5", "a writer inside a command substitution in display text", W5)
    kweak('  echo "      $K_ON_CMD"\n', '  echo "      $K_ON_CMD" | sh\n', ("K5", "K7"), "K_ON_CMD echoed into sh", "K_ON_CMD expanded in code")
    kweak(RB0, ins('echo "$BD_OFF_CMD" | tee -a /dev/null | bash'), ("K5", "K7"), "BD_OFF_CMD echoed through tee into bash",
          "BD_OFF_CMD expanded in code")
    kweak(RB0, ins('echo "$K_OFF_CMD" > "$OUT/x.sh"'), "K5", "K_OFF_CMD echoed into a file", "K_OFF_CMD expanded in code")
    # K5: the named display variables
    kq = '      case "$k" in quoted_no_prices_enabled) echo "      $K_OFF_CMD" ;; *) echo "      $Q_OFF_CMD" ;; esac'
    kweak(kq, kq.replace('echo "      $K_OFF_CMD"', '"$K_OFF_CMD"'), "K5", "K_OFF_CMD as a command word", "K_OFF_CMD expanded as a command word")
    kweak(kq, kq.replace('echo "      $Q_OFF_CMD"', 'eval "$Q_OFF_CMD"'), ("K5", "K7"), "Q_OFF_CMD in eval", "Q_OFF_CMD expanded in eval")
    kweak('  echo "      $K_ON_CMD"\n', '  sh -c "$K_ON_CMD"\n', ("K5", "K7"), "K_ON_CMD in sh -c", "K_ON_CMD expanded in sh -c or bash -c")
    kweak('  echo "      $W1_ON_CMD"\n', '  bash -c "$W1_ON_CMD"\n', ("K5", "K7"), "W1_ON_CMD in bash -c", "W1_ON_CMD expanded in sh -c or bash -c")
    kweak('    echo "      $BD_DRAIN_CMD"   # §K\'s drain reading: printed, never run (RB107)\n',
          '    X="$BD_DRAIN_CMD"; echo "      $X"   # §K\'s drain reading: printed, never run (RB107)\n', "K5",
          "BD_DRAIN_CMD through another assignment", "BD_DRAIN_CMD expanded in code")
    kweak(RB0, ins('printf -vX \'%s\' "$K_OFF_CMD"; $X'), ("K5", "K7"), "K_OFF_CMD through printf -vX, then run",
          "K_OFF_CMD expanded in code")
    # (review 3) a group's output, glued and both-stream redirections: display text that leaves the terminal is code
    kweak(RB0, ins('{ echo "$K_OFF_CMD"; } | sh'), ("K5", "K7"), "K_OFF_CMD echoed in a { } group piped into sh",
          "K_OFF_CMD expanded in code")
    kweak(RB0, ins('if true; then echo "$Q_OFF_CMD"; fi > "$OUT/x.sh"'), "K5", "Q_OFF_CMD echoed in an if whose output goes to a file",
          "Q_OFF_CMD expanded in code")
    kweak(RB0, ins('( echo "$BD_DRAIN_CMD" ) | tee /dev/null'), "K5", "BD_DRAIN_CMD echoed in a subshell piped into tee",
          "BD_DRAIN_CMD expanded in code")
    kweak(RB0, ins('while false; do echo "$K_ON_CMD"; done >> "$OUT/x"'), "K5", "K_ON_CMD echoed in a loop whose output is appended to a file",
          "K_ON_CMD expanded in code")
    kweak(RB0, ins('case "$MODE" in *) echo "$Q_ON_CMD" ;; esac > "$OUT/x"'), "K5", "Q_ON_CMD echoed in a case whose output goes to a file",
          "Q_ON_CMD expanded in code")
    kweak(RB0, ins('echo "$K_OFF_CMD">"$OUT/x.sh"'), "K5", "K_OFF_CMD echoed into a file, the redirection glued", "K_OFF_CMD expanded in code")
    kweak(RB0, ins('echo "$W1_ON_CMD" 1>>"$OUT/x"'), "K5", "W1_ON_CMD appended to a file through 1>>", "W1_ON_CMD expanded in code")
    kweak(RB0, ins('echo "$BD_OFF_CMD" &> "$OUT/x"'), "K5", "BD_OFF_CMD echoed into a file through &>", "BD_OFF_CMD expanded in code")
    kweak(RB0, ins('echo "$Q_OFF_CMD" &>"$OUT/x"'), "K5", "Q_OFF_CMD echoed into a file through &>, glued", "Q_OFF_CMD expanded in code")
    # (review 3) a shell line is skipped by its mask, never by its raw prefix
    kweak(RB0, ins('//usr/bin/env docker exec "$CONTAINER" php "$IN_CONTAINER/tools/set_config.php" --key followup_enabled --value 0'),
          "K5", "set_config run on a line beginning //usr/bin/env", N5)
    bdd = [l for l in t.split("\n") if l.startswith("BD_DRAIN_CMD=")]
    kweak(bdd[0] + "\n", bdd[0] + "\n" + bdd[0] + "\n", "K5", "BD_DRAIN_CMD assigned twice", "BD_DRAIN_CMD is assigned 2 times")
    bdo = [l for l in t.split("\n") if l.startswith("BD_OFF_CMD=")]
    kweak(bdo[0] + "\n", bdo[0] + "\n" + 'BD_OFF_CMD+=" && bash $QF_WRAPPER off chase"\n', ("K1", "K5", "K7"),
          "BD_OFF_CMD changed by +=", "BD_OFF_CMD is assigned 2 times")
    k_on = [l for l in t.split("\n") if l.startswith("  K_ON_CMD=")]
    kweak(k_on[0] + "\n", k_on[0] + "\n" + '  X_OFF_CMD="docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER php '
          '$IN_CONTAINER/tools/set_config.php --key followup_enabled --value 0"\n', "K5",
          "a display variable added to the script but not to the list", W5)
    # K6
    na = [l for l in t.split("\n") if l.startswith('  NA_G1="$(qf_php na "$sub/code" "$sub" ')]
    if len(na) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL (K6): NA-G1's call found {len(na)} times")
    kweak(na[0], na[0].replace('"$sub/code" "$sub"', '"$sub/code" "$PDD_IN"'), "K6",
          "the list probe pointed at the live data folder (its plugin.sqlite3, the live book)", "qf_php na is not given a copy")
    kweak("        " + NA_REFUSAL + "\n", "", "K6", "the list probe's path check removed", "first statement does not refuse")
    kweak('  o="$(qf_php trial "$sub/code" "$sub" "$MIG107_LAST")"', '  o="$(qf_php trial "$sub/code" "$PDD_IN" "$MIG107_LAST")"',
          "K6", "the trial called on the live book", "qf_php trial is not given a copy")
    g2 = [l for l in t.split("\n") if l.startswith('  sub="$(qf_copy g2 "$P3B_PIN")" || stop ')]
    if len(g2) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL (K6): QF-G2's copy found {len(g2)} times")
    kweak(g2[0], g2[0].replace('sub="$(qf_copy g2 "$P3B_PIN")"', 'sub="$PDD_IN"'), "K6", "the trial's copy replaced by the live data folder",
          "the copy qf_php trial reads is not qf_copy's")
    # K7 (review 2): the constructs that hide a run, and the one exception held exact
    kweak(RB0, ins(f'x=QF_TOOL; {LIVE} php "$IN_CONTAINER/${{!x}}" off chase'), "K7", "the switch tool through an indirect expansion",
          "an indirect expansion ${!…} in code")
    kweak(RB0, ins(f"{LIVE} sh -c 'php \"$1\"/tools/sales_f*.php off chase' _ \"$IN_CONTAINER\""), "K7",
          "the switch tool through a glob inside sh -c", "a glob in a tools/ or scripts/ path in code")
    kweak(RB0, ins('v=BD_OFF_CMD; eval "${!v}"'), "K7", "a display variable run through ${!v} and eval", "an indirect expansion ${!…} in code")
    kweak(RB0, ins("dxo php -r '$c = \"PluginConfig\"; call_user_func([$c, base64_decode(\"c2F2ZU92ZXJyaWRlcw==\")], \"/x\", []);'"), "K7",
          "a writer reached through call_user_func, its name encoded", "call_user_func in code")
    kweak(RB0, ins("dxo php -r '$s = SqliteStore::create(\"/x\"); $f = \"kyc_config.json\"; $s->save($f, []);'"), "K7",
          "a ->save of kyc_config.json through a variable", "a ->save( whose file name is a variable in code")
    kweak(RB0, ins('bash "$OUT/x.sh"'), "K7", "a script run from a variable path", "a script run from a variable path in code")
    kweak(RB0, ins('bash "$REPO/scripts/deploy-hybrid2.sh"'), "K7", "the put-back's exception is exact (another script)",
          "a script run from a variable path in code")
    kweak(RB0, ins('bash "$REPO/scripts/deploy-hybrid.sh"2'), "K7", "the put-back's exception is a whole word (a suffix after it)",
          "a script run from a variable path in code")
    kweak(RB0, ins('eval "$(cat /dev/null)"'), "K7", "eval", "eval in code")
    kweak(RB0, ins('if ! command eval "$OUT"; then :; fi'), "K7", "eval after ! and command", "eval in code")
    kweak(RB0, ins('source "$OUT/x.sh"'), "K7", "a file sourced", "a file sourced in code")
    kweak(RB0, ins('case "$MODE" in *) . "$OUT/x.sh" ;; esac'), "K7", "a file sourced with ., after a case pattern",
          "a file sourced in code")
    kweak(RB0, ins('bash < "$OUT/x.sh"'), "K7", "a shell reading a script from a redirection",
          "a shell reading a script from a redirection in code")
    kweak(RB0, ins('cat "$OUT/x.sh" | bash'), "K7", "a shell reading a pipe", "a shell reading a pipe in code")
    kweak(RB0, ins(f'T=x.php; {LIVE} php "$IN_CONTAINER/tools/$T" off chase'), "K7", "a file name under tools/ held in a variable",
          "a file name under tools/ or scripts/ held in a variable in code")
    kweak(RB0, ins(f'{LIVE} php -f /x/tools/x.php'), "K7", "php -f", "php -f in code")
    kweak(RB0, ins("dxo php -r '$m = \"writeKycFile\"; KycService::$m(\"/x\", []);'"), "K7", "a writer named as a string",
          "a writer named as a string in code")
    kweak(RB0, ins("printf -v X '%s' x"), "K7", "printf -v", "printf -v in code")
    print(f"  keep check: K1–K7 ({len(DISPLAY_VARS)} named display variables); {n_ctl[0]} weakened copies, each refused by exactly "
          "the checks named for it and by the rule it is for")

    # the run's folder (DS3-fix1, carried): made in the main shell before any subshell reads it — weakened copies
    print("  folder check: " + folder_check(t))

    def fweak(old, new, tag, name):
        if t.count(old) != 1:
            raise SystemExit(f"FOLDER CHECK CONTROL: its anchor is found {t.count(old)} times: {old[:100]!r}")
        try:
            folder_check(t.replace(old, new))
        except SystemExit as e:
            if tag in str(e) and name in str(e):
                return
            raise SystemExit(f"FOLDER CHECK CONTROL: the weakened copy ({name}) was refused for another reason: {str(e)[:300]}")
        raise SystemExit(f"FOLDER CHECK CONTROL: a weakened copy passed ({tag}, {name})")

    fweak(RB0, "", "(B)", "rb_pre107")
    fweak('  [ -n "$CIH_BASE" ] || return 1   # never cih_init here: vn_book_copy runs in $( … ) (cih_main)\n', "  cih_init || return 1\n",
          "(A)", "vn_book_copy")
    qa = 'qf_after() {\n  local inst="$1" sub o n v bad j\n'
    qa_main = "  cih_main || true   # the run's folder, made in this shell before the readings on copies below, which run in $( … ) (DS3-fix1)\n"
    fweak(qa + qa_main, qa, "(B)", "qf_after")
    print("  folder check control: three weakened copies, each refused for its own reason")
