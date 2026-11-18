# fix-r4.py — the helpers review (wljo3o1wr, 18 Nov): nothing blocking; four minor points applied. Asserted per edit.
import sys
D = "scripts/harness/deploy-5.18.106/"
E = {
 D + "rh106q.py": [
  # Q106-1: followup_enabled as the jobs read it — empty() (cron/followup_close.php:33, followup_send.php:48), not
  # FILTER_VALIDATE_BOOLEAN (that is followup_auto_send's, lib/FollowUpPolicy.php:101). Same on '0'/'1'.
  ("    eff = ['%s:%s' % (asname(k), 'on' if filter_bool(cfg.get(k, False)) else 'off') for k in keys]",
   "    eff = ['%s:%s' % (asname(k), (('off' if php_empty(cfg.get(k)) else 'on') if k == 'followup_enabled' else ('on' if filter_bool(cfg.get(k, False)) else 'off'))) for k in keys]   # followup_enabled as the jobs read it: empty() (cron/followup_close.php:33, followup_send.php:48); every other key FILTER_VALIDATE_BOOLEAN (followup_auto_send's, lib/FollowUpPolicy.php:101) (review r4 Q106-1)"),
  ("eff=<key>:on|off;… (PluginConfig::read's files, FILTER_VALIDATE_BOOLEAN — the jobs'\n#                         reading)",
   "eff=<key>:on|off;… (PluginConfig::read's files, as the jobs read each key: followup_enabled\n#                         by empty(), the others by FILTER_VALIDATE_BOOLEAN)"),
  # Q106-2
  ("The vault fills only its own keys, and no key these readers use is one (lib/ConfigVault.php).",
   "The vault (not read here) fills only its own keys: none that u1mirror, u2u3 or a followup_* eff= reads is one, but evo_api_key and evo_api_url are (lib/ConfigVault.php VAULT_KEYS), so kycplaces' eff= for those two is not PluginConfig::read's (no call site reads it)."),
 ],
 D + "rh106-seed.php": [
  # S1: a customer message already stored (same id) is a refusal, as the header promises
  ("        'wa_message_id' => 'RH106IN' . $h16($digits((string)$o['phone']) . '|' . $ch . '|' . $lca), 'sent_at' => $lca]);\n    $cv = $conv->getConversation($cid);",
   "        'wa_message_id' => 'RH106IN' . $h16($digits((string)$o['phone']) . '|' . $ch . '|' . $lca), 'sent_at' => $lca]);\n    if ($mid === null) throw new RuntimeException('enquiry: that customer message is stored already');   // review r4 S1\n    $cv = $conv->getConversation($cid);"),
  # S2: the three ops the rehearsal does not send
  ("// The ops that write the book (its plugin.sqlite3 must be there):\n",
   "// The ops that write the book (its plugin.sqlite3 must be there). NOT SENT by rehearse.sh (review r4 S2), kept but unexercised:\n// inbound, fx_state_drop and fx_state_back (the state-file fixture is rehearse.sh's own dstdrop106).\n"),
 ],
}
for p, edits in E.items():
    t = open(p, encoding="utf-8").read()
    for old, new in edits:
        if t.count(old) != 1: sys.exit(f"{p}: anchor found {t.count(old)} times: {old[:80]!r}")
        t = t.replace(old, new, 1)
        if t.count(new) != 1: sys.exit(f"{p}: result check failed")
    open(p, "w", encoding="utf-8").write(t); print(p, len(edits), "edits")
