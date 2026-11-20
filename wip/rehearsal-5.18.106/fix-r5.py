# fix-r5.py — trial 1 (20 Nov, from snap4): three defects of the rehearsal, none of the script. Applied to the installed
# rehearsal and to rehearse.assembled.sh alike, asserted per edit; the two files are asserted equal before and after.
#   F1 106-infra h (A6): the stand-in Evolution runs under PHP's built-in server, whose OPcache re-checks a cached script at
#      most every opcache.revalidate_freq (2) seconds, so the requests right after the edit ran the router as it was. Proved
#      by replaying the section's own edit against snap4's router: right after it a key.id, three seconds later the refusal.
#   F2 106-6d2, 106-6d4: "the book unchanged" compared with data94 taken before the fixtures, but data_dump reads
#      kyc_config.json and config.json, which the fixtures change. The digest is taken after each fixture, before each run.
#   F3 106-7e4/7e4'', 7f6/7f6'', 7g4/7g4'' (A7's lock) and what followed from them: the stand-in's public.php runs as the
#      web user (ubuntu), the clone's .git is root's (755), so its @touch of .git/index.lock failed silently, the rollback's
#      checkout went through and 7835906 served where 5.18.106 should. While the lock is armed, .git is writable by others;
#      its mode is put back at once and asserted.
import sys
FILES = ["scripts/harness/deploy-5.18.106/rehearse.sh", "wip/rehearsal-5.18.106/rehearse.assembled.sh"]
E = [
 # F1
 ("PY\nrefuse106 >/dev/null; C1_106=\"$(evopost106 sendText \"$PA106\")\"   # list absent: a key.id\n",
  "PY\n"
  "# The stand-in runs under PHP's built-in server, whose OPcache (opcache.enable, on by default there: enable_cli covers only the\n"
  "# command line) re-checks a cached script at most every opcache.revalidate_freq seconds, so a request inside that window still\n"
  "# runs the router as it was before the edit. Trial 1 (20 Nov) read the listed number answered with a key.id; replayed against\n"
  "# snap4's router, the request right after the edit got a key.id and the same request three seconds later the refusal. Waited out.\n"
  "sleep \"$(( $(php -r 'echo max(0, (int)ini_get(\"opcache.revalidate_freq\"));') + 1 ))\"\n"
  "refuse106 >/dev/null; C1_106=\"$(evopost106 sendText \"$PA106\")\"   # list absent: a key.id\n"),
 # F2
 ("CF6D0_106=\"$(q106 cfgsha \"$PD\" \"$DATA\")\"; D6D_106=\"$(data94)\"; RB6D_106=",
  "CF6D0_106=\"$(q106 cfgsha \"$PD\" \"$DATA\")\"; RB6D_106="),
 ("fresh106; O6D2_106=\"$(run106c --answer no --rollback)\"; RC6D2_106=\"$(rc106)\"\n",
  "D6D_106=\"$(data94)\"   # the data as the fixture leaves it, just before the run: data_dump reads kyc_config.json (trial 1, 20 Nov)\n"
  "fresh106; O6D2_106=\"$(run106c --answer no --rollback)\"; RC6D2_106=\"$(rc106)\"\n"),
 ("fresh106; O6D4_106=\"$(run106c --answer no --rollback)\"; RC6D4_106=\"$(rc106)\"\n",
  "D6D_106=\"$(data94)\"   # the data as both fixtures leave it, just before the run: data_dump reads config.json too\n"
  "fresh106; O6D4_106=\"$(run106c --answer no --rollback)\"; RC6D4_106=\"$(rc106)\"\n"),
 # F3
 ("  arm106 $lk\n",
  "  arm106 $lk\n"
  "  # The stand-in's public.php runs as the web user (ubuntu) and the clone's .git is root's (755): its @touch of the index lock\n"
  "  # failed silently in trial 1 (20 Nov), and the rollback's checkout went through. While the lock is armed, .git is writable\n"
  "  # by others; its own mode is put back right after the run, and asserted.\n"
  "  local gm=\"\"; if [ -n \"$lk\" ]; then gm=\"$(stat -c %a \"$REPO/.git\")\"; chmod o+w \"$REPO/.git\"; fi\n"),
 ("  if [ -n \"$lk\" ]; then check \"$(unlock106)\" \"absent\" \"$lab' the clone's index lock removed (unlock106; cleanup106 removes it at exit too) — gone\"; fi\n",
  "  if [ -n \"$lk\" ]; then chmod \"$gm\" \"$REPO/.git\"; check \"$(unlock106)|$(stat -c %a \"$REPO/.git\")\" \"absent|$gm\" \"$lab' the clone's index lock removed (unlock106; cleanup106 removes it at exit too) — gone; its .git back to its own mode ($gm)\"; fi\n"),
]
texts = [open(p, encoding="utf-8").read() for p in FILES]
if texts[0] != texts[1]: sys.exit("the installed rehearsal and rehearse.assembled.sh differ before the edits")
t = texts[0]
for old, new in E:
    if t.count(old) != 1: sys.exit(f"anchor found {t.count(old)} times: {old[:90]!r}")
    t = t.replace(old, new, 1)
    if t.count(new) != 1: sys.exit(f"result check failed: {new[:90]!r}")
if t.count('D6D_106="$(data94)"') != 2 or t.count('chmod o+w "$REPO/.git"') != 1 or t.count('opcache.revalidate_freq') != 2:
    sys.exit("final counts wrong")
for p in FILES:
    open(p, "w", encoding="utf-8").write(t)
print("fix-r5:", len(E), "edits, both files")
