import base64, hashlib, hmac, struct, sys, time
key = base64.b32decode(sys.argv[1].replace(' ', '').upper() + '=' * (-len(sys.argv[1].replace(' ', '')) % 8))
step = int(time.time()) // 30 + int(sys.argv[2] if len(sys.argv) > 2 else 0)
h = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
o = h[-1] & 15
print('%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7fffffff) % 1000000))
