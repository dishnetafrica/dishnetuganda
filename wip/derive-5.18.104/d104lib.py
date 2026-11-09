# d104lib — the asserted-edit helpers derive104.py's parts share (5.18.104's deploy script, derived from 5.18.103's).
# A copy of wip/derive-5.18.103/d103lib.py, extended only with the same discipline: insert_after and insert_before
# (an anchor found exactly n times, the inserted text found exactly n times more afterwards), and slot()/fill() for the
# marked insertion points later stages (DS2-DS4) fill without restructuring.
# DS3-fix1 adds folder_check (with shell_units and scan, the reading it rests on): a check on the derived script as a
# whole that raises — as every edit here does — when the run's folder could be made in a subshell or read in one before
# the main shell made it.
# Every edit asserts its anchor exists, the occurrence count, the replacement count, and that the result holds the text.
import hashlib, re, subprocess, sys

class Doc:
    def __init__(self, text):
        self.t = text
        self.log = []

    def count(self, s):
        return self.t.count(s)

    def sub(self, old, new, n=1, why=""):
        """Replace exactly n occurrences of old (and assert there were exactly n) by new."""
        c = self.t.count(old)
        if c != n:
            raise SystemExit(f"ANCHOR COUNT: expected {n}, found {c}: {old[:160]!r} ({why})")
        before_new = self.t.count(new) if new else 0
        self.t = self.t.replace(old, new)
        if old not in new and self.t.count(old) != 0:
            raise SystemExit(f"RESIDUE: {old[:120]!r} still present ({why})")
        if new and old not in new and new not in old:   # (new inside old: the residue check above is the proof)
            got = self.t.count(new) - before_new
            if got != n:
                raise SystemExit(f"REPLACEMENT COUNT: expected {n} new, got {got}: {new[:120]!r} ({why})")
        self.log.append((why or old[:60], n))

    def subs(self, pairs, why=""):
        for p in pairs:
            if len(p) == 2:
                self.sub(p[0], p[1], 1, why)
            else:
                self.sub(p[0], p[1], p[2], why)

    def between(self, start, end, new, keep_ends=True, why=""):
        """Replace what lies between two unique anchors (the anchors kept when keep_ends)."""
        c = self.t.count(start)
        if c != 1:
            raise SystemExit(f"BETWEEN ANCHOR: expected 1, found {c}: {start[:120]!r} ({why})")
        i = self.t.index(start)
        j = self.t.find(end, i + len(start))   # the end: its first occurrence after the start
        if j < 0:
            raise SystemExit(f"BETWEEN END: not found after the start: {end[:120]!r} ({why})")
        if keep_ends:
            self.t = self.t[: i + len(start)] + new + self.t[j:]
        else:
            self.t = self.t[:i] + new + self.t[j + len(end):]
        self.log.append((why or start[:60], 1))

    def line(self, prefix, new, n=1, why=""):
        """Replace each whole line that starts with prefix (exactly n of them) by new (no newline in new)."""
        lines = self.t.split("\n")
        hits = [i for i, l in enumerate(lines) if l.startswith(prefix)]
        if len(hits) != n:
            raise SystemExit(f"LINE PREFIX COUNT: expected {n}, found {len(hits)}: {prefix[:120]!r} ({why})")
        for i in hits:
            lines[i] = new
        self.t = "\n".join(lines)
        self.log.append((why or prefix[:60], n))

    def lines_between(self, first_prefix, last_prefix, new, why=""):
        """Replace the block of whole lines from the one starting with first_prefix to the next one starting with
        last_prefix (both unique starts), inclusive, by new (a string of whole lines, no trailing newline)."""
        lines = self.t.split("\n")
        a = [i for i, l in enumerate(lines) if l.startswith(first_prefix)]
        if len(a) != 1:
            raise SystemExit(f"BLOCK START: expected 1, found {len(a)}: {first_prefix[:120]!r} ({why})")
        b = [i for i, l in enumerate(lines) if i >= a[0] and l.startswith(last_prefix)]
        if not b:
            raise SystemExit(f"BLOCK END: not found after start: {last_prefix[:120]!r} ({why})")
        lines[a[0]:b[0] + 1] = new.split("\n") if new != "" else []
        self.t = "\n".join(lines)
        self.log.append((why or first_prefix[:60], 1))

    def must(self, s, n=None, why=""):
        c = self.t.count(s)
        if (n is None and c < 1) or (n is not None and c != n):
            raise SystemExit(f"RESULT CHECK: {s[:120]!r} found {c}, expected {n if n is not None else '>=1'} ({why})")

    def mustnot(self, s, why=""):
        c = self.t.count(s)
        if c:
            raise SystemExit(f"RESULT CHECK: {s[:120]!r} still present {c}x ({why})")


    def insert_after(self, anchor, text, n=1, why=""):
        """Insert text right after each of exactly n occurrences of anchor; assert text then occurs exactly n more times."""
        c = self.t.count(anchor)
        if c != n:
            raise SystemExit(f"INSERT ANCHOR COUNT: expected {n}, found {c}: {anchor[:160]!r} ({why})")
        if not text:
            raise SystemExit(f"INSERT: empty text ({why})")
        before = self.t.count(text)
        self.t = self.t.replace(anchor, anchor + text)
        got = self.t.count(text) - before
        if got != n:
            raise SystemExit(f"INSERT COUNT: expected {n} new, got {got}: {text[:120]!r} ({why})")
        self.log.append((why or anchor[:60], n))

    def insert_before(self, anchor, text, n=1, why=""):
        """Insert text right before each of exactly n occurrences of anchor; assert text then occurs exactly n more times."""
        c = self.t.count(anchor)
        if c != n:
            raise SystemExit(f"INSERT ANCHOR COUNT: expected {n}, found {c}: {anchor[:160]!r} ({why})")
        if not text:
            raise SystemExit(f"INSERT: empty text ({why})")
        before = self.t.count(text)
        self.t = self.t.replace(anchor, text + anchor)
        got = self.t.count(text) - before
        if got != n:
            raise SystemExit(f"INSERT COUNT: expected {n} new, got {got}: {text[:120]!r} ({why})")
        self.log.append((why or anchor[:60], n))


# The marked insertion points: one comment line each, "# ── SLOT <name> ── <what goes here> ──", placed by DS1 where a
# later stage adds its codes. A later stage fills a slot with fill(doc, name, text): the slot line must occur exactly once,
# its text is inserted before it (the slot line stays, so the order of several fills is the order of the calls), and the
# result is asserted. A slot stays while later stages may fill it; DS4's final pass (partZ) closes every one with
# close(doc, name, before): the slot line must occur exactly once, the line just above it must be the one named (the
# filling call, or the comment that says why the slot was left empty), and the line is removed — no marker survives.
def slot_line(name, what):
    return f"# ── SLOT {name} ── {what} ──"


def fill(doc, name, text, why=""):
    marker = f"# ── SLOT {name} ── "
    lines = [l for l in doc.t.split("\n") if l.lstrip().startswith(marker)]   # a slot may be indented
    if len(lines) != 1:
        raise SystemExit(f"SLOT {name}: expected 1 marker line, found {len(lines)} ({why})")
    doc.insert_before(lines[0], text if text.endswith("\n") else text + "\n", 1, why or f"slot {name}")


def close(doc, name, before, why=""):
    marker = f"# ── SLOT {name} ── "
    lines = doc.t.split("\n")
    hits = [i for i, l in enumerate(lines) if l.lstrip().startswith(marker)]
    if len(hits) != 1:
        raise SystemExit(f"SLOT {name}: expected 1 marker line to close, found {len(hits)} ({why})")
    i = hits[0]
    if i == 0 or not lines[i - 1].lstrip().startswith(before):
        raise SystemExit(f"SLOT {name}: the line above it is not {before[:80]!r}: {lines[i - 1][:120]!r} ({why})")
    n_before = len(lines)
    del lines[i]
    doc.t = "\n".join(lines)
    if len(doc.t.split("\n")) != n_before - 1 or doc.t.count(marker) != 0:
        raise SystemExit(f"SLOT {name}: not removed exactly once ({why})")
    doc.log.append((why or f"close slot {name}", 1))


def md5_at(repo, commit, path):
    return hashlib.md5(git(repo, "show", f"{commit}:{path}")).hexdigest()


def git(repo, *args):
    return subprocess.run(["git", "-C", repo, *args], check=True, capture_output=True).stdout


def sha_at(repo, commit, path):
    return hashlib.sha256(git(repo, "show", f"{commit}:{path}")).hexdigest()


def delta(repo, base, pin, plugin):
    out = git(repo, "diff", "--name-status", "--no-renames", base, pin, "--", plugin).decode()
    added, changed, other = [], [], []
    for ln in out.splitlines():
        st, p = ln.split("\t", 1)
        rel = p[len(plugin) + 1:]
        (added if st == "A" else changed if st == "M" else other).append(rel)
    if other:
        raise SystemExit(f"DELTA: unexpected status {other}")
    return sorted(added), sorted(changed)

# ── DS3-fix1: the run's folder (cih_init, CIH_BASE) is made in the main shell, before any subshell reads it ─────────────
# The script is read as bash reads it, closely enough for this one question: its functions (nested in main or not, one-
# liners included) and main's own lines, heredoc bodies and comments set aside; each unit's bare words (a call is one),
# and every subshell it opens — $( ), <( ), >( ), backquotes, ( ) groups, the members of a pipeline, a background job —
# with the bare words it runs. folder_check(text) raises SystemExit unless (A) no function that may make the folder
# (cih_init, and every function that calls one that may) runs in a subshell, and (B) every subshell that reads it
# (CIH_BASE, directly or through a function) is reached, on every call path from main, only after a main-shell cih_init
# or cih_main on an earlier line of a function on that path. Its control on the control is derive104.py's: three
# weakened copies of the result, each of which it must refuse.
HEAD = re.compile(r"^([a-z_][a-z0-9_]*)\(\) \{(.*)$")
HDOC = re.compile(r"(?<!<)<<(?!<)(-?)\s*(['\"]?)([A-Za-z_][A-Za-z0-9_]*)\2")
KW_OPEN = {"if": "fi", "while": "done", "until": "done", "for": "done", "select": "done", "{": "}"}
KW_NEXT = {"then", "do", "else", "elif", "!", "time"}


def shell_units(text):
    """The script read as units: every function it defines (nested in main or not, one-liners included) and "top", main's
    own lines outside them — each a list of (line number, line). Heredoc bodies are dropped (they are data: PHP, sums,
    words), and so are whole comment lines."""
    lines = text.split("\n")
    hd = [False] * len(lines)
    i = 0
    while i < len(lines):
        if not hd[i] and not lines[i].lstrip().startswith("#"):
            for m in HDOC.finditer(lines[i]):
                word, strip = m.group(3), m.group(1) == "-"
                j = i + 1
                while j < len(lines) and (lines[j].lstrip("\t") if strip else lines[j]) != word:
                    hd[j] = True
                    j += 1
                if j == len(lines):
                    raise SystemExit(f"FOLDER CHECK: the heredoc {word} opened at line {i + 1} never ends")
                hd[j] = True
                i = j
                break
        i += 1
    units, owner, i, main = {}, [None] * len(lines), 0, None
    while i < len(lines):
        m = None if hd[i] else HEAD.match(lines[i])
        if not m:
            i += 1
            continue
        name, rest = m.group(1), m.group(2)
        if re.search(r"\}\s*(#.*)?$", rest) and rest.count("{") < rest.count("}"):
            j = i
        else:
            j = i + 1
            while j < len(lines) and (hd[j] or (lines[j] != "}" and not HEAD.match(lines[j]))):
                j += 1
            if j == len(lines):
                raise SystemExit(f"FOLDER CHECK: function {name} has no closing brace at column 0")
            if lines[j] != "}":          # a function that holds others: main
                if main is not None:
                    raise SystemExit(f"FOLDER CHECK: two functions hold others ({main[0]}, {name})")
                main = (name, i)
                i += 1
                continue
        if name in units:
            raise SystemExit(f"FOLDER CHECK: function {name} is defined twice")
        body = [(i + 1, rest)] if j == i else [(k + 1, lines[k]) for k in range(i + 1, j) if not hd[k]]
        units[name] = [(n, l) for n, l in body if not l.lstrip().startswith("#")]
        for k in range(i, j + 1):
            owner[k] = name
        i = j + 1
    if main is None:
        raise SystemExit("FOLDER CHECK: no main() holds the script")
    end = max(k for k, l in enumerate(lines) if l == "}" and owner[k] is None and not hd[k])
    units["top"] = [(k + 1, lines[k]) for k in range(main[1] + 1, end)
                    if owner[k] is None and not hd[k] and not lines[k].lstrip().startswith("#")]
    return units


def scan(unit, trace=None):
    """A unit's bare words (outside quotes and comments) — [(line, word)] — and its subshell sites — [(line, kind, words)]:
    every $( ), <( ), >( ), backquote and ( ) group, every pipeline of two or more commands (bash runs each member in a
    subshell) and every background job, each with every bare word it runs (nested ones included)."""
    src = "\n".join(l for _, l in unit) + "\n"
    lno = []
    for n_, l in unit:
        lno += [n_] * (len(l) + 1)
    words, sites = [], []
    st = []
    cur = {"w": "", "ws": 0}

    def push(kind, at, **kw):
        f = {"kind": kind, "at": at, "words": [], "pipe": [], "cur": [], "case": [], "cmd": True, "dq": False,
             "close": None, "saved": (cur["w"], cur["ws"])}
        f.update(kw)
        cur["w"], cur["ws"] = "", 0
        st.append(f)
        return f

    def pop():
        f = st.pop()
        cur["w"], cur["ws"] = f["saved"]
        return f

    push("unit", 0)
    i, n = 0, len(src)

    def add(ch, at):
        if not cur["w"]:
            cur["ws"] = at
        cur["w"] += ch

    def end_word():
        w = cur["w"]
        if not w:
            return
        at = cur["ws"]
        cur["w"] = ""
        f = st[-1]
        cs = f["case"]
        if f["cmd"] and cs and cs[-1] == "pat" and w != "esac":
            return                               # a case pattern: data
        if cs and cs[-1] == "head":
            if w == "in":
                cs[-1] = "pat"
            return
        if f["cmd"] and w == "case":
            cs.append("head")
            return
        if f["cmd"] and w == "esac" and cs:
            cs.pop()
            f["cmd"] = False
            return
        if f["cmd"] and w in KW_OPEN:
            push("compound", at, close=KW_OPEN[w])
            return
        if f["cmd"] and f["kind"] == "compound" and w == f["close"]:
            end_cmd()
            g = pop()
            p = st[-1]
            p["cur"] += g["words"]
            p["cmd"] = False
            return
        if f["cmd"] and w in KW_NEXT:
            return
        rec = (lno[at], w)
        words.append(rec)
        for g in st:
            g["words"].append(rec)
        f["cur"].append(rec)
        if f["cmd"] and not re.match(r"^[A-Za-z_][A-Za-z0-9_]*(\[[^]]*\])?\+?=", w):
            f["cmd"] = False

    def end_cmd():
        f = st[-1]
        if f["pipe"]:
            ws = [r for m in f["pipe"] + [f["cur"]] for r in m]
            sites.append((ws[0][0] if ws else lno[f["at"]], "pipeline", ws))
        f["pipe"], f["cur"], f["cmd"] = [], [], True

    def close_sub():
        end_word()
        end_cmd()
        g = pop()
        sites.append((lno[g["at"]], g["kind"], g["words"]))
        st[-1]["cur"] += g["words"]
        st[-1]["cmd"] = False

    while i < n:
        c = src[i]
        f = st[-1]
        if f["dq"]:
            if c == "\\":
                i += 2
            elif c == '"':
                f["dq"] = False
                i += 1
            elif src.startswith("$((", i):
                push("arith", i, depth=0)
                i += 3
            elif src.startswith("$(", i):
                push("sub", i)
                i += 2
            elif c == "`":
                push("sub", i, bq=True)
                i += 1
            else:
                i += 1
            continue
        if f["kind"] == "arith":
            if c == "(":
                f["depth"] += 1
            elif c == ")":
                if f["depth"] == 0 and src.startswith("))", i):
                    pop()
                    i += 2
                    continue
                f["depth"] -= 1
            i += 1
            continue
        if c == "\\":
            add(src[i:i + 2], i)
            i += 2
            continue
        if c == "'":
            j = src.find("'", i + 1)
            if j < 0:
                raise SystemExit(f"FOLDER CHECK: an unmatched quote at line {lno[i]}")
            add("\x00", i)                       # a quoted part: the word is not a bare name
            i = j + 1
            continue
        if c == '"':
            add("\x00", i)
            f["dq"] = True
            i += 1
            continue
        if c == "#" and not cur["w"]:
            i = src.index("\n", i)
            continue
        if c == "`":
            if f.get("bq"):
                close_sub()
            else:
                add("\x00", i)
                push("sub", i, bq=True)
            i += 1
            continue
        if src.startswith("$((", i):
            add("\x00", i)
            push("arith", i, depth=0)
            i += 3
            continue
        if src.startswith("$(", i):
            add("\x00", i)
            push("sub", i)
            i += 2
            continue
        if c in "<>" and src.startswith("(", i + 1):
            end_word()
            push("sub", i)
            i += 2
            continue
        if c == "(":
            end_word()
            f = st[-1]
            cs = f["case"]
            if cs and cs[-1] == "pat":
                i += 1
            elif src.startswith("((", i):
                push("arith", i, depth=0)
                i += 2
            elif f["cmd"]:
                push("group", i)
                i += 1
            else:
                push("paren", i)                  # name() or a=( … ): no subshell
                i += 1
            continue
        if c == ")":
            end_word()
            f = st[-1]
            cs = f["case"]
            if cs and cs[-1] == "pat":
                cs[-1] = "body"
                f["cmd"] = True
            elif f["kind"] in ("sub", "group") and not f.get("bq"):
                close_sub()
            elif f["kind"] == "paren":
                pop()
            i += 1
            continue
        if c == "|":
            end_word()
            f = st[-1]
            if f["case"] and f["case"][-1] == "pat" and f["cmd"]:
                i += 1                            # a case pattern's alternatives
                continue
            if src.startswith("||", i):
                end_cmd()
                i += 2
                while i < n and src[i] in " \t\n":
                    i += 1
                continue
            f["pipe"].append(f["cur"])
            f["cur"] = []
            f["cmd"] = True
            i += 2 if src.startswith("|&", i) else 1
            while i < n and src[i] in " \t\n":   # a newline after | continues the pipeline
                i += 1
            continue
        if c == ";":
            end_word()
            f = st[-1]
            cs = f["case"]
            if cs and cs[-1] == "body" and (src.startswith(";;", i) or src.startswith(";&", i)):
                end_cmd()
                cs[-1] = "pat"
                i += 3 if src.startswith(";;&", i) else 2
                continue
            end_cmd()
            i += 1
            continue
        if c == "&":
            if src.startswith("&&", i):
                end_word()
                end_cmd()
                i += 2
                while i < n and src[i] in " \t\n":
                    i += 1
                continue
            if src.startswith("&>", i) or (cur["w"] and cur["w"][-1] in "<>") or (i > 0 and src[i - 1] in "<>"):
                add(c, i)                         # a redirection: >&2, 2>&1, &>
                i += 1
                continue
            end_word()
            f = st[-1]
            sites.append((lno[i], "background", list(f["cur"])))
            end_cmd()
            i += 1
            continue
        if c == "\n":
            end_word()
            f = st[-1]
            if trace is not None:
                trace.append((lno[i], [x["kind"] + ("/dq" if x["dq"] else "") for x in st[1:]]))
            if not (f["case"] and f["case"][-1] == "head"):
                end_cmd()
            i += 1
            continue
        if c in " \t":
            end_word()
            i += 1
            continue
        if c in "<>":
            end_word()
            i += 1
            continue
        add(c, i)
        i += 1
    end_word()
    end_cmd()
    if len(st) != 1 and trace is None:
        raise SystemExit(f"FOLDER CHECK: a unit ends inside {[x['kind'] for x in st[1:]]} (line {lno[st[-1]['at']]})")
    return words, sites

def folder_check(text):
    """DS3-fix1: (A) no function that may make the run's folder (cih_init, and every one that calls it) ever runs in a
    subshell; (B) every subshell that reads the folder (CIH_BASE, directly or through a function) is reached, on every call
    path, only after a main-shell cih_init or cih_main. Returns a summary; raises SystemExit on any breach."""
    units = shell_units(text)
    names = set(units) - {"top"}
    scans = {u: scan(b) for u, b in units.items()}
    insub = {u: {id(r) for _, _, ws in scans[u][1] for r in ws} for u in units}
    calls = {u: [(r[0], r[1], id(r) in insub[u]) for r in scans[u][0] if r[1] in names] for u in units}
    S = {"cih_init"}
    R = {u for u in names if re.search(r"\$\{?CIH_BASE\b", "\n".join(l for _, l in units[u]))}
    for X in (S, R):
        ch = True
        while ch:
            ch = False
            for u in names:
                if u not in X and any(w in X for _, w, _ in calls[u]):
                    X.add(u)
                    ch = True
    guards = {u: [ln for ln, w, sb in calls[u] if w in ("cih_init", "cih_main") and not sb] for u in units}
    callers = {u: [] for u in units}
    for c in units:
        for ln, w, _ in calls[c]:
            if w != c:
                callers[w].append((c, ln))
    memo = {}

    def covered(u, ln, seen=()):
        if any(g < ln for g in guards[u]):
            return True
        if u == "top" or not callers[u] or u in seen:
            return False
        key = u
        if key in memo:
            return memo[key]
        r = all(covered(c, l2, seen + (u,)) for c, l2 in callers[u])
        memo[key] = r
        return r

    bad_a, bad_b, n_b = [], [], 0
    for u in sorted(units):
        for ln, kind, ws in scans[u][1]:
            hit = {w for _, w in ws}
            if hit & S:
                bad_a.append(f"{u}:{ln} ({kind}: {', '.join(sorted(hit & S))})")
            if hit & R:
                n_b += 1
                memo.clear()
                if not covered(u, ln):
                    bad_b.append(f"{u}:{ln} ({kind}: {', '.join(sorted(hit & R))})")
    if bad_a:
        raise SystemExit("FOLDER CHECK (A): a function that may make the run's folder runs in a subshell: " + "; ".join(bad_a))
    if bad_b:
        raise SystemExit("FOLDER CHECK (B): a reading under the run's folder runs in a subshell that no main-shell cih_init or "
                         "cih_main precedes on every path: " + "; ".join(bad_b))
    return f"{len(units) - 1} functions, {sum(len(scans[u][1]) for u in units)} subshells; {len(S)} may make the folder, none in a subshell; {n_b} read it, each after the folder is made"
