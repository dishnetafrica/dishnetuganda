#!/usr/bin/env python3
"""Minimal RADIUS PAP client that BINDS a chosen source address.

The source address is the whole point: FreeRADIUS matches a client by the
packet's source, which is what makes it server-derived rather than asserted.
radclient cannot bind, so this exists.  Synthetic use only.
"""
import hashlib, os, socket, struct, sys

def encode_password(password: bytes, secret: bytes, auth: bytes) -> bytes:
    if len(password) % 16:
        password += b"\0" * (16 - len(password) % 16)
    out, last = b"", auth
    for i in range(0, len(password), 16):
        b = hashlib.md5(secret + last).digest()
        chunk = bytes(x ^ y for x, y in zip(password[i:i+16], b))
        out += chunk
        last = chunk
    return out

def avp(t: int, v: bytes) -> bytes:
    return struct.pack("!BB", t, len(v) + 2) + v

def request(src, dst, port, secret, user, password, extra_avps=b""):
    secret = secret.encode(); auth = os.urandom(16)
    body = (avp(1, user.encode())
            + avp(2, encode_password(password.encode(), secret, auth))
            + extra_avps)
    pkt = struct.pack("!BBH", 1, 7, 20 + len(body)) + auth + body
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.bind((src, 0)); s.settimeout(5)
    s.sendto(pkt, (dst, port))
    try:
        data, _ = s.recvfrom(4096)
    except socket.timeout:
        return "TIMEOUT"
    finally:
        s.close()
    return {2: "Access-Accept", 3: "Access-Reject", 11: "Access-Challenge"}.get(data[0], f"code {data[0]}")

if __name__ == "__main__":
    src, dst, port, secret, user, pw = sys.argv[1:7]
    extra = b""
    for kv in sys.argv[7:]:                      # e.g. NAS-IP-Address=10.0.0.1
        k, v = kv.split("=", 1)
        if k == "NAS-IP-Address":
            extra += avp(4, socket.inet_aton(v))
        elif k == "NAS-Identifier":
            extra += avp(32, v.encode())
        elif k == "Called-Station-Id":
            extra += avp(30, v.encode())
    print(request(src, dst, int(port), secret, user, pw, extra))
