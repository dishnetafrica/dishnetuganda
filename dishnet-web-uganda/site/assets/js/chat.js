/* DishNet website chat — talks to the plugin's web_chat endpoint, the same
 * brain that answers WhatsApp, so both quote the same live uCRM prices.
 * No prices, no plan names, no API keys live in this file.
 *
 * <script src="assets/js/chat.js" defer
 *         data-endpoint="https://…/public.php?page=web_chat"
 *         data-whatsapp="2567…"></script>
 */
(function () {
  'use strict';
  var tag = document.currentScript;
  if (!tag) { var s = document.getElementsByTagName('script'); tag = s[s.length - 1]; }
  var ENDPOINT = tag.getAttribute('data-endpoint') || '';
  var WA = (tag.getAttribute('data-whatsapp') || '').replace(/\D+/g, '');
  if (!ENDPOINT) return;

  var store = {
    get: function (k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };
  var session = store.get('dn_chat_session');
  var leadMode = 'after', haveLead = store.get('dn_chat_lead') === '1', leadShown = false;
  var handoffUrl = WA ? 'https://wa.me/' + WA : '';

  var css = '' +
    '.dnc-launch{position:fixed;bottom:92px;right:24px;z-index:95;width:56px;height:56px;border-radius:50%;background:#0B1120;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(11,17,32,.35);transition:transform .15s}' +
    '.dnc-launch:hover{transform:scale(1.08)}' +
    '.dnc-launch svg{width:26px;height:26px;fill:#fff}' +
    '.dnc-teaser{position:fixed;bottom:100px;right:92px;z-index:95;background:#fff;color:#1A1A1A;border:1px solid #E5E4E0;border-radius:12px;padding:10px 14px;font:14px/1.4 system-ui,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.12);max-width:240px;cursor:pointer}' +
    '.dnc-panel{position:fixed;bottom:160px;right:24px;z-index:96;width:360px;max-width:calc(100vw - 32px);max-height:min(560px,calc(100vh - 180px));display:flex;flex-direction:column;background:#fff;border:1px solid #E5E4E0;border-radius:16px;box-shadow:0 12px 48px rgba(0,0,0,.18);overflow:hidden;font-family:system-ui,-apple-system,sans-serif}' +
    '.dnc-head{background:#0B1120;color:#fff;padding:14px 16px}' +
    '.dnc-head b{font-size:15px;display:block}' +
    '.dnc-head span{font-size:12px;opacity:.75}' +
    '.dnc-x{position:absolute;top:10px;right:10px;background:none;border:none;color:#fff;font-size:20px;cursor:pointer;opacity:.7;line-height:1}' +
    '.dnc-x:hover{opacity:1}' +
    '.dnc-log{flex:1;overflow-y:auto;padding:14px;background:#FAFAF8;display:flex;flex-direction:column;gap:8px}' +
    '.dnc-msg{max-width:85%;padding:9px 12px;border-radius:12px;font-size:14px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word}' +
    '.dnc-bot{background:#F3F2EE;color:#1A1A1A;align-self:flex-start;border-bottom-left-radius:4px}' +
    '.dnc-me{background:#C8102E;color:#fff;align-self:flex-end;border-bottom-right-radius:4px}' +
    '.dnc-wa{align-self:flex-start;display:inline-flex;align-items:center;gap:6px;background:#25D366;color:#fff;text-decoration:none;font-size:13px;font-weight:600;padding:8px 14px;border-radius:20px}' +
    '.dnc-lead{background:#fff;border:1px solid #E5E4E0;border-radius:12px;padding:10px;align-self:stretch}' +
    '.dnc-lead p{margin:0 0 8px;font-size:13px;color:#5A5A58}' +
    '.dnc-lead input{width:100%;box-sizing:border-box;margin-bottom:6px;padding:8px 10px;border:1px solid #E5E4E0;border-radius:8px;font-size:13px}' +
    '.dnc-lead select{box-sizing:border-box;margin-bottom:6px;padding:8px 6px;border:1px solid #E5E4E0;border-radius:8px;font-size:13px;background:#fff;max-width:120px}' +
    '.dnc-lead .dnc-phone{display:flex;gap:6px}' +
    '.dnc-lead .dnc-phone input{margin-bottom:6px}' +
    '.dnc-lead .dnc-row{display:flex;gap:6px}' +
    '.dnc-lead button{flex:1;padding:8px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer}' +
    '.dnc-lead .dnc-save{background:#C8102E;color:#fff}' +
    '.dnc-lead .dnc-skip{background:#F3F2EE;color:#5A5A58}' +
    '.dnc-foot{display:flex;gap:8px;padding:10px;border-top:1px solid #E5E4E0;background:#fff}' +
    '.dnc-foot input{flex:1;padding:10px 12px;border:1px solid #E5E4E0;border-radius:10px;font-size:14px;outline:none}' +
    '.dnc-foot input:focus{border-color:#C8102E}' +
    '.dnc-send{background:#C8102E;color:#fff;border:none;border-radius:10px;padding:0 16px;font-size:14px;font-weight:600;cursor:pointer}' +
    '.dnc-send:disabled{opacity:.5;cursor:default}' +
    '.dnc-typing{align-self:flex-start;color:#5A5A58;font-size:13px;padding:4px 2px}' +
    '@media (max-width:480px){.dnc-panel{right:16px;left:16px;width:auto;bottom:160px}.dnc-launch{bottom:92px;right:24px}}';

  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  function el(t, cls, txt) {
    var e = document.createElement(t);
    if (cls) e.className = cls;
    if (txt) e.textContent = txt;
    return e;
  }

  var launch = el('button', 'dnc-launch');
  launch.setAttribute('aria-label', 'Chat with DishNet');
  launch.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 3C6.5 3 2 6.9 2 11.7c0 2.6 1.3 4.9 3.4 6.5-.1 1-.6 2.4-1.6 3.4 1.9-.1 3.6-.8 4.8-1.6 1.1.3 2.2.5 3.4.5 5.5 0 10-3.9 10-8.8S17.5 3 12 3zm-4.2 9.9c-.7 0-1.2-.5-1.2-1.2s.5-1.2 1.2-1.2 1.2.5 1.2 1.2-.5 1.2-1.2 1.2zm4.2 0c-.7 0-1.2-.5-1.2-1.2s.5-1.2 1.2-1.2 1.2.5 1.2 1.2-.5 1.2-1.2 1.2zm4.2 0c-.7 0-1.2-.5-1.2-1.2s.5-1.2 1.2-1.2 1.2.5 1.2 1.2-.5 1.2-1.2 1.2z"/></svg>';

  var panel = el('div', 'dnc-panel');
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'DishNet chat');
  var head = el('div', 'dnc-head');
  head.style.position = 'relative';
  head.appendChild(el('b', '', 'DishNet Assistant'));
  head.appendChild(el('span', '', 'AI answers instantly — our team takes over anytime'));
  var closeBtn = el('button', 'dnc-x', '×');
  closeBtn.setAttribute('aria-label', 'Close chat');
  head.appendChild(closeBtn);
  var log = el('div', 'dnc-log');
  var foot = el('div', 'dnc-foot');
  var input = el('input');
  input.type = 'text';
  input.placeholder = 'Ask about plans, prices, coverage…';
  input.setAttribute('aria-label', 'Your message');
  var send = el('button', 'dnc-send', 'Send');
  foot.appendChild(input); foot.appendChild(send);
  panel.appendChild(head); panel.appendChild(log); panel.appendChild(foot);

  function scroll() { log.scrollTop = log.scrollHeight; }
  function bot(t) { log.appendChild(el('div', 'dnc-msg dnc-bot', t)); scroll(); }
  function mine(t) { log.appendChild(el('div', 'dnc-msg dnc-me', t)); scroll(); }
  function waBtn(label) {
    if (!handoffUrl) return;
    var a = el('a', 'dnc-wa', label || 'Continue on WhatsApp');
    a.href = handoffUrl; a.target = '_blank'; a.rel = 'noopener';
    log.appendChild(a); scroll();
  }

  function saveLog() {
    var items = [];
    var kids = log.querySelectorAll('.dnc-msg');
    for (var i = Math.max(0, kids.length - 20); i < kids.length; i++) {
      items.push({ me: kids[i].className.indexOf('dnc-me') >= 0, t: kids[i].textContent });
    }
    store.set('dn_chat_log', JSON.stringify(items));
  }
  function restoreLog() {
    try {
      var items = JSON.parse(store.get('dn_chat_log') || '[]');
      for (var i = 0; i < items.length; i++) (items[i].me ? mine : bot)(items[i].t);
      return items.length > 0;
    } catch (e) { return false; }
  }

  function leadCard() {
    if (leadShown || haveLead || leadMode === 'off') return;
    leadShown = true;
    var card = el('div', 'dnc-lead');
    card.appendChild(el('p', '', 'Want our team to follow up? Leave your WhatsApp number (optional).'));
    var name = el('input'); name.placeholder = 'Name';
    var codes = [['+256', '🇺🇬 +256'], ['+254', '🇰🇪 +254'], ['+255', '🇹🇿 +255'],
                 ['+250', '🇷🇼 +250'], ['+211', '🇸🇸 +211'], ['+243', '🇨🇩 +243'],
                 ['+257', '🇧🇮 +257'], ['+251', '🇪🇹 +251'], ['+249', '🇸🇩 +249'],
                 ['other', 'Other']];
    var cc = el('select');
    cc.setAttribute('aria-label', 'Country code');
    for (var ci = 0; ci < codes.length; ci++) {
      var o = el('option', '', codes[ci][1]); o.value = codes[ci][0]; cc.appendChild(o);
    }
    var phone = el('input'); phone.placeholder = '7xx xxx xxx'; phone.type = 'tel';
    cc.onchange = function () {
      phone.placeholder = cc.value === 'other' ? 'Full number e.g. +44 7…' : '7xx xxx xxx';
    };
    var pr = el('div', 'dnc-phone');
    pr.appendChild(cc); pr.appendChild(phone);
    var row = el('div', 'dnc-row');
    var save = el('button', 'dnc-save', 'Send');
    var skip = el('button', 'dnc-skip', 'No thanks');
    row.appendChild(save); row.appendChild(skip);
    card.appendChild(name); card.appendChild(pr); card.appendChild(row);
    log.appendChild(card); scroll();
    skip.onclick = function () { card.remove(); };
    save.onclick = function () {
      var raw = phone.value.trim();
      if (!raw && !name.value.trim()) { card.remove(); return; }
      var full = raw;
      if (raw && cc.value !== 'other') {
        // National part: digits only, without the trunk 0 people dial locally.
        full = cc.value + raw.replace(/\D+/g, '').replace(/^0+/, '');
      }
      post('', { name: name.value.trim(), phone: full });
      haveLead = true; store.set('dn_chat_lead', '1');
      card.remove(); bot('Thank you! Our team will reach out.');
    };
  }

  var busy = false;
  function post(message, lead) {
    var body = { session: session, message: message };
    if (lead) body.lead = lead;
    return fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function ask(text) {
    if (busy || !text) return;
    busy = true; send.disabled = true;
    mine(text); saveLog();
    input.value = '';
    var typing = el('div', 'dnc-typing', 'DishNet is typing…');
    log.appendChild(typing); scroll();
    post(text).then(function (d) {
      typing.remove();
      if (d && d.session) { session = d.session; store.set('dn_chat_session', session); }
      if (d && d.handoff) handoffUrl = d.handoff;
      if (d && d.lead_mode) leadMode = d.lead_mode;
      if (d && d.have_lead) { haveLead = true; store.set('dn_chat_lead', '1'); }
      if (d && d.reply) bot(d.reply);
      if (d && d.ok === false && !d.reply) bot('Sorry, the chat hit a snag. Message us on WhatsApp and we will help right away.');
      if (d && (d.ok === false || d.escalate)) waBtn(d.escalate ? 'A teammate will help on WhatsApp' : 'Continue on WhatsApp');
      if (d && d.ok !== false && d.reply) leadCard();
      saveLog();
    }).catch(function () {
      typing.remove();
      bot('The chat is unreachable right now. Message us on WhatsApp and a human will help.');
      waBtn();
    }).then(function () { busy = false; send.disabled = false; input.focus(); });
  }

  send.onclick = function () { ask(input.value.trim()); };
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter') ask(input.value.trim()); });
  closeBtn.onclick = function () { panel.hidden = true; };
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') panel.hidden = true; });

  var opened = false;
  launch.onclick = function () {
    panel.hidden = !panel.hidden;
    if (!panel.hidden) {
      if (!opened) {
        opened = true;
        if (!restoreLog()) bot('Hello! 👋 Ask me anything about Starlink plans, prices, or coverage in Uganda.');
        if (leadMode === 'before') leadCard();
      }
      input.focus();
    }
  };

  function boot() {
    document.body.appendChild(launch);
    document.body.appendChild(panel);
    // The probe answers whether chat is on at all; if it is off or the
    // endpoint is unreachable the launcher is removed and the WhatsApp
    // float remains the only door — never a dead button.
    fetch(ENDPOINT + '&probe=1').then(function (r) { return r.json(); }).then(function (d) {
      if (!d || d.ok === false || d.enabled === false) { launch.remove(); panel.remove(); return; }
      if (d.handoff) handoffUrl = d.handoff;
      if (d.lead_mode) leadMode = d.lead_mode;
      if (d.teaser && !sessionStorage.getItem('dn_chat_teased')) {
        setTimeout(function () {
          if (opened) return;
          var tz = el('div', 'dnc-teaser', d.teaser);
          tz.onclick = function () { tz.remove(); launch.onclick(); };
          document.body.appendChild(tz);
          setTimeout(function () { tz.remove(); }, 15000);
          try { sessionStorage.setItem('dn_chat_teased', '1'); } catch (e) {}
        }, (d.teaser_delay || 6) * 1000);
      }
    }).catch(function () { launch.remove(); panel.remove(); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
