// Verification for prototype V2. Run: node prototype/verify-v2.js
// The B1 guard is TWO-SIDED on purpose: neither push nor poll may be asserted
// anywhere in the UI until B1 resolves. A one-sided check missed this once.
const {chromium}=require('/opt/node22/lib/node_modules/playwright');
(async()=>{
  const b=await chromium.launch(); const p=await b.newPage({viewport:{width:1440,height:960}});
  const errs=[]; p.on('pageerror',e=>errs.push(e.message));
  await p.goto('file://'+require('path').resolve(__dirname,'dishnet-mikrotik-management-prototype-v2.html'));
  await p.waitForTimeout(800);
  const ck=async(l,fn)=>{try{console.log('  '+l.padEnd(38)+await fn())}catch(e){console.log('  '+l.padEnd(38)+'FAIL: '+e.message)}};

  // TWO-SIDED B1 GUARD — the point is that NEITHER model may be asserted
  const POLL=/\bchecks? in\b|\bcheck-in\b|\bnext appears\b|\bwhen the router next\b/i;
  const PUSH=/\binstantly\b|\breal-?time control\b|\bimmediately sent\b|\breach(es)? the router at any\b/i;
  await ck('B1 guard — no POLL assertion',async()=>{
    const hits=await p.evaluate(()=>{const o=[];['routers','intents','sessions','settings','dashboard','plans'].forEach(v=>{go(v);
      if(/\bchecks? in\b|\bcheck-in\b|\bnext appears\b/i.test(document.body.innerText))o.push(v);});return o;});
    return hits.length?'LEAK in '+hits.join(','):'clean';});
  await ck('B1 guard — no PUSH assertion',async()=>{
    const hits=await p.evaluate(()=>{const o=[];['routers','intents','sessions','settings','dashboard','plans'].forEach(v=>{go(v);
      if(/\binstantly\b|\breal-?time control\b|\bimmediately sent\b/i.test(document.body.innerText))o.push(v);});return o;});
    return hits.length?'LEAK in '+hits.join(','):'clean';});
  await ck('B1 guard — modals + toasts',async()=>{
    await p.evaluate(()=>go('sessions')); await p.waitForTimeout(150);
    await p.evaluate(()=>modalDisconnect('48210937')); await p.waitForTimeout(150);
    const m=await p.evaluate(()=>document.querySelector('.modal').innerText);
    await p.evaluate(()=>closeModal());
    await p.evaluate(()=>go('intents')); await p.waitForTimeout(150);
    await p.evaluate(()=>retryIntent('IN-4821')); await p.waitForTimeout(200);
    const t=await p.evaluate(()=>document.querySelector('.toast')?.innerText||'');
    const bad=[POLL,PUSH].some(re=>re.test(m)||re.test(t));
    return bad?('LEAK — modal:"'+m.slice(0,50)+'" toast:"'+t.slice(0,50)+'"'):'clean (modal + toast)';});
  await ck('router detail connectivity copy',async()=>{
    await p.evaluate(()=>go('router','MT-0001')); await p.waitForTimeout(200);
    return await p.evaluate(()=>{const m=document.body.innerText.match(/Tunnel established[^.]*\./);return m?m[0]:'NOT FOUND'});});
  await ck('offline router copy',async()=>{
    await p.evaluate(()=>go('router','MT-0003')); await p.waitForTimeout(200);
    return await p.evaluate(()=>{const m=document.body.innerText.match(/No recent handshake[^.]*\./);return m?m[0].slice(0,72):'NOT FOUND'});});
  // regression: core V2 behaviours still work
  await ck('lands on Routers',async()=>{await p.evaluate(()=>location.reload());await p.waitForTimeout(700);
    return await p.evaluate(()=>route.view);});
  await ck('fleet cohorts',async()=>await p.evaluate(()=>document.querySelectorAll('#fleetStrip .fl').length)+' tiles');
  await ck('search grouped',async()=>{await p.evaluate(()=>doSearch('riverside'));await p.waitForTimeout(200);
    return await p.evaluate(()=>document.querySelectorAll('.sres-g').length)+' groups';});
  await ck('bulk queue',async()=>{const b4=await p.evaluate(()=>INTENTS.length);
    await p.evaluate(()=>{toggleSel('MT-0001',true);bulkQueue('Sync configuration')});await p.waitForTimeout(250);
    return b4+'->'+(await p.evaluate(()=>INTENTS.length));});
  console.log(errs.length?'  PAGE ERRORS: '+errs.join(' | '):'  no page errors');
  await b.close();
})();
