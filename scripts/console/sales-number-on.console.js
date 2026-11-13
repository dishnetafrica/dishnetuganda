// sales-number-on.console.js — switch ONE salesperson's WhatsApp number on, from Chrome's console (plugin 5.18.89+).
//
// Where:  uCRM, signed in as an administrator, on the plugin's WhatsApp AI page — the page that shows the card
//         "Salesperson numbers". Press F12, open Console, paste all of this, press Enter.
// What:   for one number it presses the card's own three buttons, in the card's order, each exactly once:
//         Verify number → Register webhook → Switch on. It stops at the first answer that is not OK and sends nothing more.
//         It never adds, pairs, pauses, retires or re-owns a number, and it changes no setting.
// Prints: the plugin's own answers, with the number's name, its instance name and its owner's name taken out and every
//         run of digits cut to its last two. Safe to copy back.
// Not while a deploy, an --after-only or a rollback is running.
(async () => {
  const CHANNEL   = '';        // '' = the only number that is not switched on yet; or name one, e.g. 'sales-003'
  const ASSISTANT = 'leave';   // 'on' = also press "Let the assistant answer on this number" (only if it is off)

  const say = (...a) => console.log('%c[sales number]', 'font-weight:bold;color:#0a7', ...a);
  if (window.__dnSalesNumberBusy) { say('STOP — this script is already running here. Wait for its DONE or STOP line.'); return; }
  window.__dnSalesNumberBusy = true;
  try {
    // The plugin's page: this document, or the frame uCRM shows it in.
    const docs = [];
    (function walk(w) { try { docs.push(w.document); for (let i = 0; i < w.frames.length; i++) walk(w.frames[i]); } catch (e) {} })(window);
    const live = docs.find(d => d && d.querySelector('#sales-numbers'));
    if (!live) { say('STOP — the card "Salesperson numbers" is not on this page. Open the plugin\'s WhatsApp AI page as an administrator, then paste again. Nothing was sent.'); return; }
    const win = live.defaultView, url = live.location.href;
    const ask = async init => {   // the plugin's page for this address, as the plugin answers it now
      const res = await win.fetch(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, init || {}));
      return { res, doc: new DOMParser().parseFromString(await res.text(), 'text/html') };
    };

    // One row of the card, read from a page the plugin answered with.
    const rowsOf = doc => [...doc.querySelectorAll('#sales-numbers .wa-row')].map(el => {
      const formOf = sel => { const b = el.querySelector(sel); return b ? b.closest('form') : null; };
      const id   = (el.querySelector('input[name="channel_id"]') || {}).value || '';
      const head = el.querySelector('div'), spans = head ? head.querySelectorAll(':scope > span') : [];
      const bits = ((spans[1] || {}).textContent || '').split('·').map(s => s.replace(/\s+/g, ' ').trim());
      const pills = [...(head ? head.querySelectorAll('.wa-pill') : [])].map(p => p.textContent.replace(/\s+/g, ' ').trim());
      const ai = pills.find(p => /^assistant (on|off)$/.test(p)) || '';
      return {
        id, pills,
        name: ((spans[0] || {}).textContent || '').trim(),
        instance: bits[1] || '', owner: (bits[3] || '').replace(/\s*\(not active\)$/, ''),
        verified: /\(verified /.test((spans[1] || {}).textContent || ''),
        status: pills[0] || '', assistant: ai.replace('assistant ', ''), link: pills[2] || '',
        fVerify: formOf('button[name="wa_action"][value="sn_verify"]'),
        fHook:   formOf('button[name="wa_action"][value="sn_webhook"]'),
        fOn:     formOf('button[name="status"][value="active"]'),
        fAi:     formOf('input[name="wa_action"][value="sn_ai"]'),
      };
    }).filter(r => r.id !== '' && r.fVerify);   // a salesperson's number that is not retired (a department has no such button)

    // Nothing a person could be known by leaves this script: names out, digits cut to the last two.
    let hide = [];
    const clean = t => {
      let s = String(t);
      for (const [word, as] of hide) if (word.length > 1) s = s.split(word).join(as);
      return s.replace(/\+?\d[\d\s().-]{3,}\d/g, m => { const d = m.replace(/\D/g, ''); return d.length < 5 ? m : '••••' + d.slice(-2); });
    };
    const state = r => `${r.id}: ${r.status || '?'} · assistant ${r.assistant || '?'} · ${r.link || '?'} · number ${r.verified ? 'verified' : 'NOT verified'}`;

    // The card as it is NOW, read again (nothing is changed by reading): a page left open cannot mislead.
    const now = (await ask()).doc;
    if (!now.querySelector('#sales-numbers')) { say('STOP — asked again, the plugin did not show the card: sign in again and reload the page, then paste again. Nothing was sent.'); return; }
    const note = ((now.querySelector('#sales-numbers .wa-note') || {}).textContent || '').replace(/\s+/g, ' ');
    if (/The channel registry is off/.test(note)) { say('STOP — the card says the channel registry is off (multi_number_channels_enabled), so no number can be switched on. Nothing was sent.'); return; }
    if (/Verify the department numbers first/.test(note)) { say('STOP — the card says a department number is not verified yet. Press Verify number on that department\'s row first. Nothing was sent.'); return; }
    const rows = rowsOf(now);
    if (!rows.length) { say('STOP — the card lists no salesperson number that can be switched on. Add the number on the card first (it is added switched off). Nothing was sent.'); return; }
    let row = null;
    if (CHANNEL !== '') {
      row = rows.find(r => r.id === CHANNEL) || null;
      if (!row) { say(`STOP — there is no number "${CHANNEL}" on the card (or it is retired). The card has: ${rows.map(r => r.id).join(', ')}. Nothing was sent.`); return; }
    } else {
      const off = rows.filter(r => r.status !== 'active');
      if (off.length === 1) row = off[0];
      else {
        say(off.length ? 'STOP — more than one number is not switched on. Put the one you mean in CHANNEL (first line of the script) and paste again. Nothing was sent.'
                       : 'Every salesperson number on the card is already switched on. To turn an assistant on, set CHANNEL and ASSISTANT = \'on\'. Nothing was sent.');
        rows.forEach(r => say('   ' + state(r)));
        return;
      }
    }
    hide = [[row.name, '[this number]'], [row.instance, '[its instance]'], [row.owner, '[its owner]']];
    say('number: ' + state(row));
    const todo = row.status !== 'active'
      ? 'Switch ON this salesperson number?\n\n' + row.name + '  (' + row.id + ')\n\nIts assistant is ' + (row.assistant === 'on' || ASSISTANT === 'on'
          ? 'ON: once the number is on, the assistant answers customers on it.' : 'OFF: messages are kept, nothing is answered.')
      : (ASSISTANT === 'on' && row.assistant === 'off' ? 'Let the assistant answer customers on this number?\n\n' + row.name + '  (' + row.id + ')' : '');
    if (todo === '') { say('Nothing to do: it is already switched on' + (row.assistant === 'on' ? ' and its assistant is on.' : '; to turn its assistant on, set ASSISTANT = \'on\'.') + ' Nothing was sent.'); return; }
    if (!win.confirm(todo)) { say('STOP — you pressed Cancel. Nothing was sent.'); return; }

    // One button, pressed once: the form's own fields plus the button's own name and value, posted to the page's address.
    let sent = 0;
    const press = async (form, button, label) => {
      const body = new URLSearchParams();
      form.querySelectorAll('input[name]').forEach(i => { if ((i.type === 'checkbox' || i.type === 'radio') && !i.checked) return; body.append(i.name, i.value); });
      for (const k of Object.keys(button)) body.set(k, button[k]);
      if (body.get('channel_id') !== row.id || !body.get('_csrf')) throw new Error('the form is not this number\'s — nothing sent');
      sent++;
      const { res, doc } = await ask({ method: 'POST', body });
      const box  = doc.querySelector('.wa-msg');
      const ok   = !!box && box.classList.contains('wa-good');
      const next = rowsOf(doc).find(r => r.id === row.id) || null;
      say(`${label}: ${ok ? 'OK' : 'NOT OK'} — ${box ? clean(box.textContent.trim()) : 'the plugin gave no answer (HTTP ' + res.status + ') — are you still signed in?'}`);
      if (ok && !next) { say('STOP — the number is no longer on the card the plugin answered with. Reload the page and look.'); return null; }
      return ok ? next : null;
    };

    if (row.status !== 'active') {
      row = await press(row.fVerify, { wa_action: 'sn_verify' }, '1 Verify number');
      if (!row) { say(`STOP after ${sent} request(s). If it says "not connected": press Show QR code on the card, scroll to the top of the plugin's page, pair the phone, then paste again.`); return; }
      if (!row.verified) { say('STOP — the card still shows the number as not verified. Reload the page and look.'); return; }
      row = await press(row.fHook, { wa_action: 'sn_webhook' }, '2 Register webhook');
      if (!row) { say(`STOP after ${sent} request(s). The number is verified but NOT switched on.`); return; }
      if (!row.fOn) { say('STOP — the card offers no "Switch on" for this number. Reload the page and look.'); return; }
      row = await press(row.fOn, { status: 'active' }, '3 Switch on');
      if (!row) { say(`STOP after ${sent} request(s). The number is NOT switched on.`); return; }
      if (row.status !== 'active') { say('STOP — the plugin said OK but the card does not show the number active. Reload the page and look.'); return; }
    } else {
      say('it is already switched on — the three buttons are not pressed again.');
    }
    if (ASSISTANT === 'on' && row.assistant === 'off') {
      const v = row.fAi && row.fAi.querySelector('input[name="value"]');
      if (!v || v.value !== '1') { say('STOP — the card offers no "Let the assistant answer" for this number. Reload the page and look.'); return; }
      row = await press(row.fAi, {}, '4 Let the assistant answer');
      if (!row) { say(`STOP after ${sent} request(s). The assistant is NOT on.`); return; }
    }
    say(`DONE, ${sent} request(s) sent. Now: ${state(row)}`);
    if (row.assistant === 'off') say('The assistant is OFF on this number: messages are kept for the person, nothing is answered. To turn it on, set ASSISTANT = \'on\' and paste again.');
    say('Reload the page to see the card as it now is.');
  } catch (e) {
    say('STOP — ' + (e && e.message ? e.message : e) + '. Reload the page and look at the card before trying again.');
  } finally {
    window.__dnSalesNumberBusy = false;
  }
})();
