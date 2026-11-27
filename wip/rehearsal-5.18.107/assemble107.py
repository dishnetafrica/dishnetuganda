#!/usr/bin/env python3
# Assemble 5.18.107's rehearsal from its parts (wip/rehearsal-5.18.107/parts/) into scripts/harness/deploy-5.18.107/: rehearse.sh
# (the parts in their order, each whole, each once) and its two helpers beside it (rh107q.py, rh107-seed.php), byte for byte.
# Asserted: every part present and ending in a newline; the first part begins with the shebang and no other part carries one;
# each part's first line once in the result; the result's length the parts' sum; each helper's bytes the part's. Exits non-zero
# on any failure, writing nothing. Run with python3 -I from the checkout's root: python3 -I wip/rehearsal-5.18.107/assemble107.py
import hashlib
import os
import sys

ROOT = os.getcwd()
SRC = os.path.join(ROOT, 'wip', 'rehearsal-5.18.107', 'parts')
DST = os.path.join(ROOT, 'scripts', 'harness', 'deploy-5.18.107')
ORDER = ['rehearse.part1.sh', 'rehearse.part2a.sh', 'rehearse.part2b.sh', 'rehearse.part2c.sh', 'rehearse.part2d.sh',
         'rehearse.part3a.sh', 'rehearse.part3b.sh', 'rehearse.part4a.sh', 'rehearse.part4b.sh', 'rehearse.part4c.sh',
         'rehearse.part5.sh', 'rehearse.part6.sh', 'rehearse.part7.sh']
HELPERS = ['rh107q.py', 'rh107-seed.php']


def main():
    have = sorted(f for f in os.listdir(SRC) if f.startswith('rehearse.part'))
    assert have == sorted(ORDER), ('parts', sorted(set(have) ^ set(ORDER)))
    parts = []
    for i, name in enumerate(ORDER):
        b = open(os.path.join(SRC, name), 'rb').read()
        assert b.endswith(b'\n') and b.strip(), name
        assert b.startswith(b'#!/usr/bin/env bash\n') == (i == 0), ('shebang', name)
        parts.append(b)
    out = b''.join(parts)
    assert len(out) == sum(len(p) for p in parts)
    for name, p in zip(ORDER, parts):
        first = p.split(b'\n', 1)[0]
        if first.strip() and not first.startswith(b'# \xe2\x95\x90'):   # the section rules repeat by design
            assert out.count(first + b'\n') == 1, ('first line', name, out.count(first + b'\n'))
    os.makedirs(DST, exist_ok=True)
    tmp = os.path.join(DST, '.rehearse.sh.tmp')
    open(tmp, 'wb').write(out)
    os.chmod(tmp, 0o755)
    os.replace(tmp, os.path.join(DST, 'rehearse.sh'))
    for h in HELPERS:
        b = open(os.path.join(SRC, h), 'rb').read()
        open(os.path.join(DST, h), 'wb').write(b)
        assert open(os.path.join(DST, h), 'rb').read() == b, h
    assert open(os.path.join(DST, 'rehearse.sh'), 'rb').read() == out
    print('assembled rehearse.sh %d lines sha256 %s; helpers %s' % (out.count(b'\n'), hashlib.sha256(out).hexdigest()[:16],
          ' '.join('%s:%s' % (h, hashlib.sha256(open(os.path.join(DST, h), 'rb').read()).hexdigest()[:16]) for h in HELPERS)))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except AssertionError as e:
        print('assemble107: refused: %r' % (e.args,), file=sys.stderr)
        sys.exit(1)
