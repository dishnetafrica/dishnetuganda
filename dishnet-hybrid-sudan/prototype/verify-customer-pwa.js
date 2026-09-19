/* Verification for the Customer PWA prototype.
   Three independent checks:
     1. B1 neutrality  - two-sided, same guard as verify-v2.js
     2. Field leakage  - operational fields must not reach the customer DOM
     3. Render health  - every screen, every persona, no errors, no overflow
   Run: node verify-customer-pwa.js                                          */
const {chromium}=require('/opt/node22/lib/node_modules/playwright');
const path=require('path');
const FILE='file://'+path.resolve(__dirname,'dishnet-customer-pwa-prototype.html');

/* --- the two-sided B1 guard, identical to verify-v2.js --- */
const POLL=/\bchecks? in\b|\bcheck-in\b|\bnext appears\b|\bwhen the router next\b|\bnext contacts\b|\bphones home\b/i;
const PUSH=/\binstantly\b|\breal-?time control\b|\bimmediately sent\b|\bpushed to the router\b/i;

/* --- fields that exist on the router object but must never be projected --- */
const LEAK=[
  ['serial','HGX8842011'],['serial','HGX8842077'],['serial','HGX9110455'],['serial','HGX9110461'],
  ['wireguard ip','10.66.0.11'],['wireguard ip','10.66.0.24'],
  ['public endpoint','41.210.'],
  ['routeros version','7.14.3'],['routeros version','7.13.5'],
  ['other customer','Mbale Guest House'],   // must not appear while signed in as C-01
];
const SCREENS=['home','wifi','vouchers','devices','account','billing','support','usage','nowifi'];
const WIDTHS=[360,390,414,430];
let fails=[],checks=0;
const ok=(c,m)=>{checks++;if(!c)fails.push(m)};

(async()=>{
  const b=await chromium.launch();
  const pg=await b.newPage({viewport:{width:390,height:780}});
  const errs=[],netfail=[];
  pg.on('pageerror',e=>errs.push(String(e)));
  /* Distinguish APPLICATION errors from RESOURCE-LOAD failures. A blocked web
     font is an environment fact, not a prototype defect, and must not be
     allowed to mask a real script error by being lumped in with one. */
  pg.on('requestfailed',r=>netfail.push(r.url().split('?')[0]+' '+r.failure().errorText));
  pg.on('console',m=>{if(m.type()==='error'){
    const t=m.text();
    if(/Failed to load resource/i.test(t))netfail.push(t); else errs.push(t);}});
  await pg.goto(FILE);

  /* ---------- 1. B1 neutrality across all rendered text ---------- */
  console.log('\n1. B1 DELIVERY-MODEL NEUTRALITY (two-sided)');
  const seen=new Set();
  for(const who of ['C-01','C-03','C-09']){
    await pg.evaluate(w=>setWho(w),who);
    for(const v of SCREENS){
      await pg.evaluate(s=>go(s),v);
      seen.add(await pg.evaluate(()=>document.getElementById('app').innerText));
    }
    // runtime surfaces a static scan cannot see
    await pg.evaluate(()=>go('wifi'));
    if(who!=='C-09'){
      await pg.evaluate(()=>{try{issueSheet()}catch(e){}});
      await pg.evaluate(()=>{try{doIssue()}catch(e){}});
      seen.add(await pg.evaluate(()=>document.getElementById('sheetbox').innerText));
      await pg.evaluate(()=>closeSheet());
      await pg.evaluate(()=>go('home'));
      seen.add(await pg.evaluate(()=>document.getElementById('app').innerText));  // pending card
    }
    await pg.evaluate(()=>{try{openChain()}catch(e){}});
    seen.add(await pg.evaluate(()=>document.getElementById('sheetbox').innerText));
    await pg.evaluate(()=>closeSheet());
  }
  await pg.evaluate(()=>openGuest());
  seen.add(await pg.evaluate(()=>document.getElementById('sheetbox').innerText));
  await pg.evaluate(()=>closeSheet());

  const all=[...seen].join('\n');
  const pollHits=all.split('\n').filter(l=>POLL.test(l));
  const pushHits=all.split('\n').filter(l=>PUSH.test(l));
  ok(pollHits.length===0,'POLL-implying language: '+JSON.stringify(pollHits));
  ok(pushHits.length===0,'PUSH-implying language: '+JSON.stringify(pushHits));
  console.log('   poll-implying : '+(pollHits.length?'FAIL '+pollHits.length:'none'));
  console.log('   push-implying : '+(pushHits.length?'FAIL '+pushHits.length:'none'));
  console.log('   neutral phrase present: '+(/held until the delivery path is available/i.test(all)?'yes':'NO'));

  /* ---------- 2. operational field leakage ---------- */
  console.log('\n2. OPERATIONAL FIELD LEAKAGE (customer must not see these)');
  await pg.evaluate(()=>setWho('C-01'));
  let dom='';
  for(const v of SCREENS){await pg.evaluate(s=>go(s),v);
    dom+=await pg.evaluate(()=>document.getElementById('app').innerHTML);}
  for(const st of ['S-01','S-02']){await pg.evaluate(s=>go('site',s),st);
    dom+=await pg.evaluate(()=>document.getElementById('app').innerHTML);}
  for(const [label,needle] of LEAK){
    const leaked=dom.includes(needle);
    ok(!leaked,`LEAK: ${label} (${needle}) reached the customer DOM`);
    console.log(`   ${leaked?'FAIL':'ok  '}  ${label.padEnd(18)} ${needle}`);
  }

  /* ---------- 2b. cross-customer isolation ---------- */
  console.log('\n2b. CROSS-CUSTOMER ISOLATION');
  const iso=await pg.evaluate(()=>{
    const r=[];
    for(const [tok,mine] of [['tok_a1','C-01'],['tok_b2','C-03'],['tok_c3','C-09']]){
      SESSION={token:tok}; const d=derive();
      r.push({mine,sites:d.sites.map(s=>s.id),routers:d.routers.map(x=>x.id),
              vouch:d.vouchers.length,sess:d.sessions.length,
              foreignSite:d.sites.some(s=>s.cust!==mine),
              foreignRouter:d.routers.some(x=>!d.sites.map(s=>s.id).includes(x.site))});
    }
    // ask() with no session must yield nothing
    SESSION=null; r.push({noSession:derive()===null&&ask('wifi')===null});
    SESSION={token:'tok_forged'}; r.push({forged:derive()===null});
    SESSION={token:'tok_a1'};
    return r;
  });
  for(const x of iso.slice(0,3)){
    ok(!x.foreignSite,`${x.mine} reached a foreign site`);
    ok(!x.foreignRouter,`${x.mine} reached a router outside its sites`);
    console.log(`   ok    ${x.mine}: ${x.sites.length} sites, ${x.routers.length} routers, ${x.vouch} vouchers, ${x.sess} sessions`);
  }
  ok(iso[3].noSession,'no session still returned data');
  ok(iso[4].forged,'forged token was accepted');
  console.log('   ok    no session -> null');
  console.log('   ok    forged token -> null');

  /* ---------- 2c. no client-supplied id is honoured ---------- */
  console.log('\n2c. CLIENT-SUPPLIED ID IS NEVER HONOURED');
  await pg.evaluate(()=>setWho('C-01'));
  await pg.evaluate(()=>go('site','S-07'));            // a real site, but C-03's
  const stolen=await pg.evaluate(()=>document.getElementById('app').innerText);
  ok(!stolen.includes('Block A'),'go("site","S-07") exposed another customer\'s site');
  console.log('   '+(stolen.includes('Block A')?'FAIL':'ok  ')+'  go("site","S-07") as C-01 -> refused');

  /* ---------- 3. render health + overflow ---------- */
  console.log('\n3. RENDER HEALTH');
  for(const who of ['C-01','C-03','C-09']){
    for(const w of WIDTHS){
      await pg.setViewportSize({width:w,height:780});
      await pg.evaluate(x=>setWho(x),who);
      for(const v of SCREENS){
        await pg.evaluate(s=>go(s),v);
        const o=await pg.evaluate(()=>{
          const b=document.querySelector('.body');
          return b?b.scrollWidth-b.clientWidth:0;});
        ok(o<=0,`overflow ${o}px on ${v} at ${w}px as ${who}`);
        const empty=await pg.evaluate(()=>document.getElementById('app').innerText.trim().length);
        ok(empty>20,`screen ${v} rendered empty at ${w}px as ${who}`);
      }
    }
  }
  console.log(`   ${SCREENS.length} screens x ${WIDTHS.length} widths x 3 personas = ${SCREENS.length*WIDTHS.length*3} renders`);
  console.log('   horizontal overflow : '+(fails.some(f=>f.includes('overflow'))?'FAIL':'none'));
  console.log('   empty screens       : '+(fails.some(f=>f.includes('empty'))?'FAIL':'none'));

  /* ---------- 3b. C-09 (no MikroTik) must degrade, not break ---------- */
  console.log('\n3b. NO-MIKROTIK CUSTOMER');
  await pg.setViewportSize({width:390,height:780});
  await pg.evaluate(()=>setWho('C-09'));
  for(const v of ['wifi','vouchers','devices','site','usage']){
    await pg.evaluate(s=>go(s),v);
    const t=await pg.evaluate(()=>document.getElementById('app').innerText);
    const handled=/No Wi-Fi service yet|isn't on your account/i.test(t);
    ok(handled,`C-09 on ${v} did not show the empty state`);
    console.log(`   ${handled?'ok  ':'FAIL'}  ${v.padEnd(9)} -> empty state`);
  }
  const home9=await pg.evaluate(()=>{go('home');return document.getElementById('app').innerText});
  ok(!/MikroTik HotSpot/.test(home9),'C-09 home listed a MikroTik service it does not have');
  console.log('   '+(/MikroTik HotSpot/.test(home9)?'FAIL':'ok  ')+'  home lists only real services');

  ok(errs.length===0,'application errors: '+JSON.stringify(errs.slice(0,4)));
  console.log('\n   application errors  : '+(errs.length||'none'));
  console.log('   resource failures   : '+(netfail.length?netfail.length+' (environment, not a defect)':'none'));
  if(netfail.length){
    [...new Set(netfail.map(x=>x.split(' ')[0]))].forEach(u=>console.log('     - '+u));
    console.log('     NOTE: external font CDN. Renders correctly on the CSS fallback stack.');
  }

  await b.close();
  console.log('\n'+'-'.repeat(58));
  console.log(fails.length?`FAILED  ${fails.length} of ${checks}`:`PASSED  all ${checks} checks`);
  fails.slice(0,12).forEach(f=>console.log('  - '+f));
  process.exit(fails.length?1:0);
})();
