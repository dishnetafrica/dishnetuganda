Both helpers are copied beside the rehearsal into the sandbox, the way R5 copies rh105q.py into $H105. The wrapper names below are suggestions for Part 1, which defines them; each carries the 106 suffix:
- q106() { python3 -I "$H106/rh106q.py" "$@"; }
- seed106c CODE-ROOT OPS-JSON — modelled on R4's seed104c. It cds to "$H106/v" and runs setpriv --reuid 1000 --regid 1000 --clear-groups -- env DN_VAULT_FILE="$H106/v/seed-vault.json" RH106_RANGE="<the range's ten digits>" LD_PRELOAD="$CLKPRE104" RH104_CLOCK_FILE="$CLKF104" "$PHPBIN" "$H106/rh106-seed.php" "$1" "$DATA" "$2" Africa/Kampala. It then removes the vault file and echoes the line when it begins '{"ops":', else none(<the first 160 bytes of the error file>).
- seed106r — the same as root (no setpriv), for the fx_state_* ops and any fx_file on a file root owns.

rh106q.py
- Readers only. Every mode prints exactly one line of space-separated key=value words. Values never contain spaces, so q105 kv and q105 same work on these lines.
- Exit 0 on success. Exit 2 (to stderr) on an unknown mode. On sqlite3.Error, OSError, IndexError, ValueError or TypeError it prints `unread:<ExceptionName>` and exits 3.
- It writes nothing, and the store is opened as file:…?mode=ro with PRAGMA query_only=ON.

1. `q106 verbs SCRIPT` → `lk=<n> qftool=<n> wrapper=<n> verbs=<n>`. These are rh105q.py's rules unchanged. On deploy-5.18.106.sh at 745e668, a grep check gives the expected `lk=1 qftool=0 wrapper=0 verbs=0`. Part 1's three planted controls (qftool, wrapper, verbs) apply unchanged.

2. `q106 u1mirror BOOK PLUGIN NOW [HELD [UG]]`
   - Arguments:
     - BOOK is $DATA/plugin.sqlite3; the settings files are read from dirname(BOOK).
     - PLUGIN is $PD, for its data/config.json.
     - NOW is an epoch. Use the stage-A line's at= — kv of the state file's last UJ_A= line, which the deploy writes as the raw uj line (S:13776) — so the mirror reads at the same moment.
     - HELD is `-` (the default), `out:ID,ID` or `only:ID,ID`.
     - UG is 1 (the default) or 0.
   - Output: `uj=mirror at= ug= en= hours= cutoff= ledger= out= held= places= place= classes=`.
   - out, held, places, place and classes have the uj line's exact form:
     - out: `label@ISO|none@n;…`
     - place: `i@class@label@failed@f1;…`
     - classes: `held:n,f5:n,closes:n,f2:n,f1:n,waits:n`
   - So `q105 same "$UJA" "$MIRROR" out,held,places,place,classes,en,hours,cutoff,ledger,ug` compares them directly.
   - On failure: `uj=no-table:…`, `out=unread:<E>` or `places=unread:<E>`.

3. `q106 u2u3 BOOK PLUGIN NOW [UG]` → `uj=mirror at= ug= hours= cutoff= last_send= sends7= approved= approved_stale= approved_fresh= approved_gone= failed7= failed7_f1= open= open_age= due105= due106= xs_all= xs_passes= xs_first_expire= xs_first_cancel= f5_multi=`. Each value has the uj line's form. On failure: `u2=unread:<E>` or `u3=unread:<E>`.

4. `q106 rwd BOOK FUID…` → `rwd=<id>@<n3>@<nall>@<refusals>@<deliveries>;… min=<least n3|-> ge3=<k>/<ids>`. An id may instead read `<id>@nofu` or `<id>@nodraft`.
   - since is the follow-up's first approved draft by `ORDER BY decided_at, id`.
   - The channel is followups.channel.
   - n3 is counted to 3, as F1 calls it; nall is uncounted.
   - refusals counts the F1-kind failed events since then; deliveries counts F1's delivery rows on that channel since then.

5. `q106 staleorder BOOK LIMIT_HOURS NOW [LO HI]`
   - Output: `cutoff= stale= stale_gone= stale_present= first400_gone=<g>/<k> gone_first=yes|no|- open= open_gone= open_present= gone_older=yes|no|- planted_open=<n|-> planted_conv=<n|-> xs_all= xs_passes= xs_first_expire= xs_first_cancel= p1_main=cancelled:n,replied:n,human_closed:n,opted_out:n,exhausted:n p1_xs=expired:n,cancelled:n p1_cn=<n> p1_ex=<n>`.
   - It checks design-v2 §A3 at stage A: first400_gone=400/400, gone_first=yes, gone_older=yes, planted_open=400 and planted_conv=0 with LO=9000001 HI=9000400, xs_first_cancel=200, xs_first_expire=0, xs_all≥401.
   - p1_cn and p1_ex forecast the first real close pass at NOW: the main loop first, then expireStale. 5f expects p1_cn≥200 and p1_ex=0.

6. `q106 rblast DIR STATE` → `last=<AS_RB1|none|-> t=<RB_COPY_AT|-> file=<basename|-> records=<n> v1=<STATE's AS_RB1|-> v1guard=pass|stop copy_at=<COPY_AT|-> before=yes|no|- would=offer-if-off|nothing:v1guard|nothing:no-copy-at|nothing:no-record|nothing:not-before|nothing:not-written`. DIR is $OUT106; STATE is $OUT106/state-5.18.106.env.

7. `q106 cfgsha PLUGIN DATA` → `k=<sha256|absent|other|unread> s=<sha256|nodb|absent|null|unread> p=<…> d=<…> all=<16 hex>`. s is the store row's bytes as stored.

8. `q106 kycplaces PLUGIN DATA KEYCSV` → `file= row= pcfg= dcfg= keys=<key>@<place><t>,…;… eff=<key>:on|off;… asplaces=k:x,s:x,p:x,d:x cs=<key@k,s+…|-> pd=<key@p,d;…|-> pre13=ok|file,row,differ differ=<keys|->`.
   - The type letters t: s string, i int, f float, b bool, n null, a array.
   - asplaces has the form of AS_PHP's places= for followup_auto_send.
   - cs mirrors cs_found: replace '+' with ' ' to compare it with CS-G1's printed `<f>`.

9. `q106 closer BOOK COPYUNIX` → `since= sched= fc_last=<epoch|never> fc_last_iso= fc_dur=<ms|-> fc_state=none|ended|running control=held|not|unread on_expired= expired= cancelled= by_closer=cancelled:n,human_closed:n,opted_out:n,exhausted:n replied=`. When control=held it adds `t= on_cancelled_t=`. These are uj's rules (S:7134-7159), restricted to enquiry rows.

rh106-seed.php
- Call: `php rh106-seed.php <code root> <data dir> <ops JSON array> [zone]`.
  - The code root must be the pin bf29cbd's tree (its lib/ at least, e.g. `git archive $P106F $P/lib | tar -x` into $H106/ref, used as $H106/ref/$P). The helper refuses unless FollowUpService has refusedWhileDelivering and expireOtherApproved.
  - The environment must carry DN_VAULT_FILE (non-empty) and RH106_RANGE (`^2567001[0-9]{3}$`).
  - Every phone must match `^\+?<RH106_RANGE>[0-9]{2}$`.
  - Each of name, text, body, body2, by, agent and raw must contain RH106SECRET-.
- Book ops need the effective uid to equal the owner of $DATA/plugin.sqlite3, which is 1000 in the sandbox. The keep folders must be absolute existing directories, writable by the uid that runs the op.
- Output: one line `{"ops":n,"at":<clock epoch>,"uid":<euid>,"done":[…]}`.
- Exit codes:
  - Exit 2 means a refusal before anything was opened, with `refusing: <why>` on stderr.
  - Exit 3 means a refusal after N ops: `refused after N: <Class> <sanitised message>`. A gone op's transaction is rolled back whole.
- Ops and their done entries:
  - **enquiry** `{op,phone,channel?,name,text,lca|lca_ago_s,upto?(open|draft|approve, default approve),drafts?(1|2),body (unless open),body2?,by (approve)}` → `{op,conv,message,id,drafts:[ids],approved,age_s}`.
  - **pair** (J3, two drafts approved) → the same entry.
  - **inbound** `{phone,channel?,name,text,sent_at|ago_s}` → `{op,conv,message(0 if stored already),lca:this|later}`.
  - **delivery** `{phone,channel?,name,text,agent?,wa_id?}` → `{op,conv,message}`. sent_at is the clock's now, and the WhatsApp id is RH106OUT….
  - **gone** `{from,count,phone,channel?,lca|lca_ago_s}` → `{op,opened,first,last,open_before,absent_after}`. from must be ≥ 9000000; design-v2 uses from 9000001, count 400.
  - **fx_store_keys** `{keys:{claimkey:value}}` and **fx_store_keys_undo** `{keys:[…]}` → `{op,fixture:true,what,keys,file:'unchanged',row_keys}`.
  - **fx_type3** and **fx_type3_undo** `{key:'followup_daily_cap'|…}` → `{op,fixture:true,what,key,row:number|string,file:'unchanged'}`.
  - **fx_file** `{place:k|p|d,keep,tag,set:{…}|raw,plugin (for p)}` → `{op,fixture:true,what,place,tag,keys,was,before,after}`.
  - **fx_file_back** `{place,keep,tag,plugin?}` → `{op,fixture:true,place,tag,was,now}`.
  - **fx_state_drop** `{path:…/state-5.18.106.env,key:UJ_A|COPY_AT,keep,tag,expect?}` → `{op,fixture:true,what,key,removed,tag,before,after}`.
  - **fx_state_back** `{path,keep,tag}` → `{op,fixture:true,tag,now}`.
- Design-v2 §A9's fixtures map onto these ops as follows:
  - a store-only claim key is fx_store_keys, and its undo fx_store_keys_undo;
  - a claim key in a config.json is fx_file with place p or d and set {quote_chase_enabled:"1"}, undone by fx_file_back;
  - '3' against 3 is fx_type3 with key followup_daily_cap, undone by fx_type3_undo;
  - an undecodable kyc_config.json is fx_file with place k and raw "{\"RH106SECRET-undecodable\":", undone by fx_file_back;
  - followup_auto_send in a config.json is fx_file with place d or p and set {followup_auto_send:"1"}, undone by fx_file_back;
  - UJ_A and COPY_AT are taken out by fx_state_drop and put back by fx_state_back, both run as root.