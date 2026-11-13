# weakened.py SCRIPT OUTDIR — TEST ONLY: writes the weakened copies of scripts/console/sales-number-on.console.js, one fault
# each, every anchor asserted exactly once; prints "NAME<TAB>the check that must fail" per copy. weakened.sh runs them.
import sys
src = open(sys.argv[1], encoding='utf-8').read(); out = sys.argv[2]
W = [
 ('W01-no-mask', 'C8 the output names nobody',
  "    const clean = t => {\n      let s = String(t);\n", "    const clean = t => {\n      return String(t);\n      let s = String(t);\n"),
 ('W02-no-verify', 'C8 exactly three requests',
  "      row = await press(row.fVerify, { wa_action: 'sn_verify' }, '1 Verify number');\n", "      row.verified = true; row.ending = row.ending || '••••00';\n"),
 ('W03-no-first-question', 'C3 Cancel: nothing sent',
  "    if (!win.confirm(todo)) {", "    if (false) {"),
 ('W04-goes-on-after-not-ok', 'C5 one request',
  "      return ok ? next : null;\n", "      return next;\n"),
 ('W05-retire-form', 'C8 no other field rides along',
  "        fOn:     formOf('button[name=\"status\"][value=\"active\"]'),\n", "        fOn:     formOf('input[name=\"status\"][value=\"retired\"]'),\n"),
 ('W06-no-second-question', 'C9 one request',
  "      if (flagged || row.ending !== was) {\n", "      if (false) {\n"),
 ('W07-owner-not-active-not-read', 'C7 its owner not an active staff member',
  "    if (row.status !== 'active' && row.ownerOff) {", "    if (false) {"),
 ('W08-names-hidden-in-any-order', 'C5 its instance not listed',
  ".sort((a, b) => b[0].length - a[0].length);\n", ".reverse().sort((a, b) => a[0].length - b[0].length);\n"),
 ('W09-evolution-words-printed', 'C17 a connection error',
  "      if (!/^Evolution refused:/.test(t)) return clean(t);\n", "      return clean(t);\n"),
 ('W10-channel-echoed', 'C2 a name typed',
  "what was typed is not shown here.", "what was typed: ${CHANNEL}."),
 ('W11-warning-not-read', 'C11 on, with the card',
  "    if (row.status === 'active' && row.warn) {", "    if (false) {"),
 ('W12-lost-answer-called-refusal', 'C16 Switch on with no answer',
  "      sent++; unsure = true;\n", "      sent++;\n"),
 ('W13-busy-flag-on-the-console-window', 'C4 STOP, already running',
  "    if (win.__dnSalesNumberBusy) {", "    if (window.__dnSalesNumberBusy) {"),
 ('W14-no-phone-warning-read-as-another-phone', 'C9 then Evolution reports its owner with no phone number',
  "    if (row.status !== 'active' && row.noPhone) {", "    if (false) {"),
 ('W15-the-plugins-not-sent-words-withheld', 'C17 a connection error',
  "'Not sent — no connection to Evolution', ", ""),
 ('W16-lost-webhook-answer-sent-to-look', 'C16 Register webhook with no answer',
  "and the card does not show that. Wait a minute, then paste again", "To look, open the page again"),
 ('W17-claims-nothing-is-sent', 'C11 on, with the card',
  "Until it is put right the assistant may still be answering on the phone that is paired now.", "nothing automated is sent on this number until it is put right."),
]
for name, must, old, new in W:
    assert src.count(old) == 1, (name, src.count(old))
    open(f'{out}/{name}.js', 'w', encoding='utf-8').write(src.replace(old, new))
    print(f'{name}\t{must}')
