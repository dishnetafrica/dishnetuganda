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
    __slots__ = ("s", "e", "subs", "psub_out")

    def __init__(self, s):
        self.s, self.e, self.subs, self.psub_out = s, s, [], False


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
        st = {"ci": None, "case": 0, "paren": 0}

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
                    i += 1
            elif c == ")":
                if st["paren"] > 0:
                    finish()
                    st["paren"] -= 1
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
            if txt == "esac":
                st["case"] = max(0, st["case"] - 1)
            elif txt in KEYWORDS or ASSIGN.match(txt):
                pass
            else:
                st["ci"] = len(words) - 1
                if txt == "case":
                    st["case"] += 1

    def judge(self, words, ci, piped_at):
        """The display spans of one finished command."""
        t = self.t
        if ci is None:
            out = []
            for w in words:
                m = ASSIGN.match(t, w.s)
                if m and t[w.s:m.end()].rstrip("+=") in self.dv:
                    out += self.cut(m.end(), w.e, w.subs)
            return out
        cw = t[words[ci].s:words[ci].e]
        args = words[ci + 1:]
        if cw == "printf":
            if args and t[args[0].s:args[0].e] == "-v":
                return []
        elif cw not in DISPLAY_WORDS:
            return []
        if piped_at is not None:
            m = re.compile(r"[ \t]*(\S+)").match(t, piped_at)
            if not m or m.group(1) != "tee":
                return []
        if any(w.psub_out for w in args):
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
        self.lines = []   # (lineno, raw, code): code keeps only the 'C' characters, every other one a space
        at = 0
        for n, raw in enumerate(t.split("\n"), 1):
            m = self.mask[at:at + len(raw)]
            if raw.lstrip().startswith(("#", "//")):
                code = ""
            else:
                code = "".join(ch if m[k] == 67 else " " for k, ch in enumerate(raw))
            self.lines.append((n, raw, code))
            at += len(raw) + 1

    def code_lines(self):
        return [(n, raw, code) for n, raw, code in self.lines if code.strip()]

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


def k1(v):
    bad = []
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
    if g1 != ['if [ "$MODE" = "after" ]; then sed -n \'s/^MG_G1=//p\' "$STATE" 2>/dev/null | tail -n1; else printf \'%s\' "${MG_G1:-}"; fi;']:
        bad.append(("K4b", "lk_mg_g1 does not read MG-G1's verdict alone (this run's, or the state file's in --after-only)"))
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
    # the named display variables
    for var in DISPLAY_VARS:
        asg = [(n, raw) for n, raw, _ in v.lines if not raw.lstrip().startswith(("#", "//"))
               and re.search(rf"(?<![\w$]){var}=", raw)]
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
            if raw.lstrip().startswith(("#", "//")) or c == "X":
                continue
            if c == "D":
                shown += 1
                continue
            line_code = v.lines[n - 1][2]
            col = m.start() - (t.rfind("\n", 0, m.start()) + 1)
            pre = line_code[:col]
            if re.search(r"(?:^|[;&|({]|\bthen|\bdo|\belse)\s*\"?$", pre):
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
             if not v.lines[t.count("\n", 0, m.start())][1].lstrip().startswith(("#", "//"))]
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


def keep_check(t):
    try:
        v = View(t)
    except ParseError as e:
        return [("PARSE", str(e))]
    return k1(v) + k2(v) + k3(v) + k4(v) + k4b(v) + k5(v) + k6(v)


def run(doc, ctx):
    kb = keep_check(doc.t)
    if kb:
        raise SystemExit("KEEP CHECK: " + "; ".join(f"{a}: {b}" for a, b in kb[:12]))
    n_ctl = [0]

    def kweak(old, new, tag, what):
        if doc.t.count(old) != 1:
            raise SystemExit(f"KEEP CHECK CONTROL ({what}): its anchor is found {doc.t.count(old)} times: {old[:100]!r}")
        got = keep_check(doc.t.replace(old, new))
        tags = sorted({a for a, _ in got})
        if tags != [tag]:
            raise SystemExit(f"KEEP CHECK CONTROL ({what}): refused for {tags or 'nothing'}, not for {tag} alone: {got[:3]}")
        n_ctl[0] += 1

    t = doc.t
    rb0 = [l for l in t.split("\n") if l.startswith("  cih_main || note \"LK-RB0 the run's folder")]
    if len(rb0) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL: rb_pre107's cih_main line found {len(rb0)} times")
    RB0 = rb0[0] + "\n"
    # K1
    kweak("  qf_keep_and_baseline\n", "  qf_clear_and_baseline\n  qf_keep_and_baseline\n", "K1", "the clear call re-inserted")
    kweak(RB0, RB0 + '  sub="$(qf_copy rbx "$P3B_PIN")" && qf_tool "$sub" status\n', "K1", "the switch tool run with another verb")
    kweak(RB0, RB0 + '  ( cd "$REPO" && bash "$QF_WRAPPER" show )\n', "K1", "the wrapper run")
    kweak(RB0, RB0 + '  docker exec -u "$DB_OWNER" "$CONTAINER" php "$IN_CONTAINER/$QF_TOOL" set ai_burst_merge_enabled 0\n', "K1",
          "set ai_burst_merge_enabled 0 outside display text, through a path no writer detector names")
    kweak(RB0, RB0 + '  SC="$IN_CONTAINER/tools/set_config.php"; docker exec -u "$DB_OWNER" -e DN_DATA_DIR="$PDD_IN" "$CONTAINER" '
                     'php "$SC" --key ai_burst_merge_enabled --value 0\n', "K1",
          "--key ai_burst_merge_enabled to set_config outside display text, through a variable")
    kweak(RB0, RB0 + '  bash "$REPO/scripts/sales-followup.sh" --check burst-drain >/dev/null\n', "K1",
          "a live sales-followup.sh --check burst-drain outside display text")
    kweak(RB0, RB0 + '  o="$(qf_php rbx "$PDD_IN" burst-drain)"\n', "K1", "burst-drain handed to code other than qf_tool's check")
    # K2
    ca1 = [l for l in t.split("\n") if re.match(r"  lk_ca1\b", l)]
    if len(ca1) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL: the LK-CA1 call line found {len(ca1)} times")
    kweak(ca1[0] + "\n", "", "K2", "LK-CA1 removed")
    # K3
    V1E = 'esac )"; if do_rollback; then QCV1_RB=ok'
    kweak(V1E, 'esac )"; qf_rb1; if do_rollback; then QCV1_RB=ok', "K3", "QF-RB1 called again in V1")
    kweak(V1E, 'esac )"; sub="$(qf_copy v1x "$P3B_PIN")" && qf_tool "$sub" show >/dev/null; if do_rollback; then QCV1_RB=ok', "K3",
          "the switch tool run in V1 before its put-back")
    # K4
    kweak("$cnt('quote_followup_state', \" WHERE k NOT LIKE 'list_last:%'\")", "$cnt('quote_followup_state')", "K4",
          "quote_followup_state counted over every row")
    # K4b
    one = [l for l in t.split("\n") if l.strip().startswith(K4B_ONE)]
    if len(one) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL (K4b): the one-row acceptance found {len(one)} times")
    ONE = one[0]
    kweak(ONE, ONE.replace("[ \"$(printf '%s' \"$sca\" | tr ',' '\\n' | sort | paste -sd, -)\" = \"$scx\" ]",
                           "[ -n \"$sca\" ]"), "K4b", "any extra row accepted")
    kweak(ONE + "\n", "", "K4b", "the exact equality restored alone (fails on a first deploy)")
    kweak(ONE, ONE.replace('[ "$(lk_mg_g1)" = "absent" ] && ', ""), "K4b", "the one row accepted whatever MG-G1 read")
    # K5: each detector form in code
    kweak(RB0, RB0 + "  printf '%s' \"<?php PluginConfig::saveOverrides(\\$d, ['followup_enabled' => '0']);\" | docker exec -i \"$CONTAINER\" php\n",
          "K5", "a saveOverrides in a printf piped into docker exec php")
    kweak(RB0, RB0 + "  dxo php -r 'require \"/x/lib/KycService.php\"; KycService::writeKycFile(\"/x\", []);'\n", "K5",
          "a writeKycFile call")
    kweak(RB0, RB0 + "  dxo php -r '$s = SqliteStore::create(\"'\"$PDD_IN\"'\"); $s->save(\"kyc_config.json\", []);'\n", "K5",
          "a ->save of kyc_config.json on the live data folder")
    q6 = [l for l in t.split("\n") if "ucrm_quote_organization_id" in l and "--value disabled" in l and 'DN_DATA_DIR="$d"' in l]
    if len(q6) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL (K5): Q-CA6's run found {len(q6)} times")
    kweak(q6[0], q6[0].replace('DN_DATA_DIR="$d"', 'DN_DATA_DIR="$PDD_IN"'), "K5", "Q-CA6's set_config run pointed at $PDD_IN")
    kweak(RB0, RB0 + '  bash "$REPO/scripts/sales-followup.sh" off chase\n', "K5", "the switch tool's off, run live")
    # the tokenizer's own controls: a command substitution inside display text is code, and so is an echo piped into a shell
    kweak(RB0, RB0 + '  note "RB107 $(bash "$REPO/scripts/sales-followup.sh" off chase)"\n', "K5",
          "a writer inside a command substitution in display text")
    kweak('  echo "      $K_ON_CMD"\n', '  echo "      $K_ON_CMD" | sh\n', "K5", "K_ON_CMD echoed into sh")
    # K5: the named display variables
    kq = '      case "$k" in quoted_no_prices_enabled) echo "      $K_OFF_CMD" ;; *) echo "      $Q_OFF_CMD" ;; esac'
    kweak(kq, kq.replace('echo "      $K_OFF_CMD"', '"$K_OFF_CMD"'), "K5", "K_OFF_CMD as a command word")
    kweak(kq, kq.replace('echo "      $Q_OFF_CMD"', 'eval "$Q_OFF_CMD"'), "K5", "Q_OFF_CMD in eval")
    kweak('  echo "      $K_ON_CMD"\n', '  sh -c "$K_ON_CMD"\n', "K5", "K_ON_CMD in sh -c")
    kweak('  echo "      $W1_ON_CMD"\n', '  bash -c "$W1_ON_CMD"\n', "K5", "W1_ON_CMD in bash -c")
    kweak('    echo "      $BD_DRAIN_CMD"   # §K\'s drain reading: printed, never run (RB107)\n',
          '    X="$BD_DRAIN_CMD"; echo "      $X"   # §K\'s drain reading: printed, never run (RB107)\n', "K5",
          "BD_DRAIN_CMD through another assignment")
    bdd = [l for l in t.split("\n") if l.startswith("BD_DRAIN_CMD=")]
    kweak(bdd[0] + "\n", bdd[0] + "\n" + bdd[0] + "\n", "K5", "BD_DRAIN_CMD assigned twice")
    k_on = [l for l in t.split("\n") if l.startswith("  K_ON_CMD=")]
    kweak(k_on[0] + "\n", k_on[0] + "\n" + '  X_OFF_CMD="docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER php '
          '$IN_CONTAINER/tools/set_config.php --key followup_enabled --value 0"\n', "K5",
          "a display variable added to the script but not to the list")
    # K6
    na = [l for l in t.split("\n") if l.startswith('  NA_G1="$(qf_php na "$sub/code" "$sub" ')]
    if len(na) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL (K6): NA-G1's call found {len(na)} times")
    kweak(na[0], na[0].replace('"$sub/code" "$sub"', '"$sub/code" "$PDD_IN"'), "K6",
          "the list probe pointed at the live data folder (its plugin.sqlite3, the live book)")
    kweak("        " + NA_REFUSAL + "\n", "", "K6", "the list probe's path check removed")
    kweak('  o="$(qf_php trial "$sub/code" "$sub" "$MIG107_LAST")"', '  o="$(qf_php trial "$sub/code" "$PDD_IN" "$MIG107_LAST")"',
          "K6", "the trial called on the live book")
    g2 = [l for l in t.split("\n") if l.startswith('  sub="$(qf_copy g2 "$P3B_PIN")" || stop ')]
    if len(g2) != 1:
        raise SystemExit(f"KEEP CHECK CONTROL (K6): QF-G2's copy found {len(g2)} times")
    kweak(g2[0], g2[0].replace('sub="$(qf_copy g2 "$P3B_PIN")"', 'sub="$PDD_IN"'), "K6", "the trial's copy replaced by the live data folder")
    print(f"  keep check: K1–K6 ({len(DISPLAY_VARS)} named display variables); {n_ctl[0]} weakened copies, each refused for its own reason alone")

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
