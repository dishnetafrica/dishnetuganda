# d103lib — the asserted-edit helpers derive103.py's parts share (5.18.103's deploy script, derived from 5.18.102's).
# Every edit asserts its anchor exists, the occurrence count, the replacement count, and that the result holds the text.
import hashlib, subprocess, sys

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
