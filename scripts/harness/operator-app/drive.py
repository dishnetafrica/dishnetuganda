"""Run a command on a real pseudo-terminal, as an operator's SSH session does,
and type answers the way a person does: each one only AFTER its prompt has
appeared on the screen, never ahead of it.

    python3 drive.py <screen-file> '<json [[prompt, answer], ...]>' -- <command...>

Everything the terminal shows is written to <screen-file>: that is what a copy
of the operator's terminal would carry. The command's exit status is ours.
A prompt that never appears within 120 s ends the run with status 124.
"""
import json, os, pty, select, sys, time

screen = sys.argv[1]
answers = json.loads(sys.argv[2])
cmd = sys.argv[sys.argv.index('--') + 1:]

pid, fd = pty.fork()
if pid == 0:
    os.execvp(cmd[0], cmd)

out = open(screen, 'wb')
seen = b''
pending = list(answers)
deadline = time.monotonic() + 120
status = None
while True:
    r, _, _ = select.select([fd], [], [], 0.5)
    if fd in r:
        try:
            data = os.read(fd, 65536)
        except OSError:
            data = b''
        if data:
            out.write(data); out.flush(); seen += data
            while pending and pending[0][0].encode() in seen:
                prompt, answer = pending.pop(0)
                seen = seen.split(prompt.encode(), 1)[1]
                time.sleep(0.4)                     # a person reads the prompt, then types
                os.write(fd, answer.encode() + b'\r')
                deadline = time.monotonic() + 120
        elif status is not None:
            break
    if status is None:
        w, st = os.waitpid(pid, os.WNOHANG)
        if w:
            status = os.waitstatus_to_exitcode(st)
            # drain what is left on the terminal, then stop
            continue
    elif not r:
        break
    if pending and time.monotonic() > deadline:
        out.write(('\n[drive.py: the prompt %r never appeared]\n' % pending[0][0]).encode())
        os.kill(pid, 9); os.waitpid(pid, 0)
        sys.exit(124)
out.close()
sys.exit(status if status is not None else 0)
