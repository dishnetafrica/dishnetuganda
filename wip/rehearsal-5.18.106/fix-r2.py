# fix-r2.py — the assembled rehearsal's first static review (wxr9v0fjv, two read-only reviewers, 18 Nov): four defects,
# each found by both, each confirmed against rh106q.py, the rehearsal and deploy-5.18.106.sh before this edit.
# Asserted: every anchor once in the assembled file; applied once; the result holds the new text and not the old.
# The parts (5, 6) get the same edit where the anchor stands once; the assembled file is the one installed.
import sys
A = "rehearse.assembled.sh"
E = []
# F1 / RV106-2 — 7f5: STATE106 is still 7e4's V1 deploy (AS_RB1=written); a typed rollback never writes STATE; rblast's guard
# comes first (rh106q.py rblast; deploy-5.18.106.sh:8829), so the reading is nothing:v1guard.
E.append(('dpre106 "106-7f5" "$B106" on - "on:$LIM106" "written:nothing:not-before"',
          'dpre106 "106-7f5" "$B106" on - "on:$LIM106" "written:nothing:v1guard"   # STATE106 still 106-7e4\'s V1 deploy (AS_RB1=written): the guard first (S:8829); a typed rollback never writes STATE (review r2 F1)'))
# F2 / RV106-3 — 7g3: STATE106 still 7f6's V1 deploy (AS_RB1=not-written:differ), the same.
E.append(('dpre106 "106-7g3" "$B106" off - "on:$LIM106" "written:nothing:not-before"',
          'dpre106 "106-7g3" "$B106" off - "on:$LIM106" "written:nothing:v1guard"   # STATE106 still 106-7f6\'s V1 deploy (AS_RB1=not-written:differ): the guard first (S:8829) (review r2 F2)'))
# F3 / RV106-1 — 6f4'': DG6F_106 was taken before the store-only offers fixture (both keys are ladder keys), so the digest
# can never read equal while the fixture stands; rbx106 already asserts the ladder digest across the rollback (its own k0).
# Assert instead the three claim switches still where 106-6f3 found them.
E.append(('''$( [ "$(digest105)" = "$DG6F_106" ] && echo kept)" \\
      "ordered|1,1,1|yes|written|kept" \\''',
          '''$(kv106 "$(q106 kycplaces "$PD" "$DATA" offers_live_enabled,offers_test_enabled,quote_chase_enabled)" cs)" \\
      "ordered|1,1,1|yes|written|offers_live_enabled@s+offers_test_enabled@s+quote_chase_enabled@k,s" \\'''))
E.append(('''the key on and the five conditions holding, AS-RB1 wrote (its record AS_RB1=written); every ladder key, the offers keys and chase among them, as before"''',
          '''the key on and the five conditions holding, AS-RB1 wrote (its record AS_RB1=written); the three claim switches still where 106-6f3 found them (every ladder key across the rollback is rbx106's own check, against its k0 taken with the fixture in place)"'''))
# F4 / RV106-4 — 10a: Part 5's own set_evolution --url "$EVF106" plants (6b2, 6b7, 7d3, 7f4) ask the stand-in on
# /rh106-file-only/ (set_evolution's status → listInstances); accept that second base path, which this rehearsal planted.
E.append(('''EVP106="${EVO_URL:-}"; EVP106="/${EVP106#http://*/}"
''',
          '''EVP106="${EVO_URL:-}"; EVP106="/${EVP106#http://*/}"
EVQ106="${EVF106:-}"; EVQ106="/${EVQ106#http://*/}"   # Part 5's file-only base path (106-6b2, 6b7, 7d3, 7f4: set_evolution's status asks it) (review r2 F4)
'''))
E.append(('''grep -cvE "^(GET|POST) $EVP106/")''',
          '''grep -cvE "^(GET|POST) ($EVP106|$EVQ106)/")'''))
E.append(('''the stand-in asked only on its own base path ($EVP106) since $V105's sections began;''',
          '''the stand-in asked only on its own base path ($EVP106), or on the file-only path this rehearsal planted ($EVQ106), since $V105's sections began;'''))

def apply(path, edits, strict):
    t = open(path, encoding='utf-8').read(); done = 0
    for old, new in edits:
        n = t.count(old)
        if n != 1:
            if strict: sys.exit(f"{path}: anchor found {n} times, not once: {old[:100]!r}")
            continue
        t = t.replace(old, new, 1); done += 1
        if t.count(new) != 1 or (old not in new and t.count(old) != 0): sys.exit(f"{path}: result check failed: {new[:100]!r}")
    open(path, 'w', encoding='utf-8').write(t)
    return done
n = apply(A, E, True)
assert n == len(E), n
print(f"{A}: {n} edits applied")
for p in ("parts/rehearse.part5.sh", "parts/rehearse.part6.sh"):
    print(f"{p}: {apply(p, E, False)} edits applied")
