<?php
/**
 * refjoin.php — the third method for the twin rules (the specification's §1.5.2, §5.3): each rule as one SQL self-join,
 * run on a small book (quadratic, so never by R0 itself). The window W is written into the SQL as an integer literal
 * from a fixed list: bound through execute(), it would be TEXT, and SQLite orders every number before every text value,
 * so the predicate would hold at any distance — the last two lines prove that pitfall on the planted 8-day pair.
 *
 *   php refjoin.php <data dir> <client of the 7-day pair> <client of the 8-day pair>   → "T1 <pairs>" … one per line
 */
declare(strict_types=1);
[$_, $data, $c7, $c8] = $argv + [null, '', '0', '0'];
$p = new PDO('sqlite:file:' . $data . '/plugin.sqlite3?immutable=1', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$hasPc = (bool)$p->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='payment_collections'")->fetchColumn();
$kycRule = $hasPc
    ? "CASE WHEN kyc AND cli0 > 0
             AND NOT EXISTS (SELECT 1 FROM payment_collections pc WHERE json_valid(pc.data) AND json_extract(pc.data,'$.source')='kyc_cash_sale' AND CAST(json_extract(pc.data,'$.crm_customer_id') AS INTEGER)=cli0)
             AND EXISTS (SELECT 1 FROM payment_collections pc WHERE json_valid(pc.data) AND json_extract(pc.data,'$.source')='kyc_cash_sale' AND CAST(json_extract(pc.data,'$.kyc_app_id') AS INTEGER)=cli0)
           THEN 0 ELSE cli0 END"
    : 'cli0';
$cte = "WITH B AS (
  SELECT l.id, substr(l.date,1,10) AS d, COALESCE(l.direction,'') AS dir, UPPER(TRIM(COALESCE(l.currency,''))) AS cur,
         CAST(ROUND(100 * (CASE WHEN UPPER(TRIM(COALESCE(l.currency,'')))='SSP' THEN COALESCE(l.ssp_amount,0) ELSE COALESCE(l.amount,0) END)) AS INTEGER) AS cents,
         CAST(COALESCE(l.crm_payment_id,0) AS INTEGER) AS pay, CAST(COALESCE(l.crm_client_id,0) AS INTEGER) AS cli0,
         TRIM(COALESCE(l.validation_ref,'')) AS vref, COALESCE(l.source,'') AS src,
         CASE WHEN substr(TRIM(COALESCE(l.validation_ref,'')),1,8)='CRM-PAY-' THEN 'PAY-' || substr(TRIM(l.validation_ref),9) ELSE TRIM(COALESCE(l.validation_ref,'')) END AS nref,
         (COALESCE(l.direction,'')='in' AND COALESCE(l.source,'')<>'crm_webhook_reversal' AND UPPER(TRIM(COALESCE(l.txn_type,'')))<>'REFUND'
          AND COALESCE(l.category,'') NOT IN ('Refund','Customer Refund','Customer Commission')) AS rf,
         (COALESCE(l.source,'')='kyc_cash_sale' OR substr(TRIM(COALESCE(l.validation_ref,'')),1,4)='CRM#') AS kyc
    FROM cb_ledger l),
L1 AS (SELECT B.*, {$kycRule} AS cli, CASE WHEN date(d) IS d THEN 1 ELSE 0 END AS dok FROM B),
G AS (SELECT vref, src FROM L1 WHERE vref<>'' GROUP BY vref, src
       HAVING COUNT(*)=2 AND (src IN ('account_transfer','funding','fx_exchange') OR (MIN(dir)='in' AND MAX(dir)='out')))";
$leg = "NOT (a.vref=b.vref AND a.src=b.src AND EXISTS (SELECT 1 FROM G WHERE G.vref=a.vref AND G.src=a.src))";
$win = static fn(int $W): string => "a.dok=1 AND b.dok=1 AND ABS(julianday(b.d)-julianday(a.d)) <= " . $W;
$q = static function (string $sql) use ($p, $cte): int { return (int)$p->query($cte . ' ' . $sql)->fetchColumn(); };
$out = [];
$out['T1'] = $q("SELECT COUNT(*) FROM L1 a JOIN L1 b ON b.id>a.id AND a.rf AND b.rf AND a.pay>0 AND b.pay=a.pay AND {$leg}");
$out['T2'] = $q("SELECT COUNT(*) FROM L1 a JOIN L1 b ON b.id>a.id AND a.nref<>'' AND b.nref=a.nref AND {$leg}");
foreach ([0, 7] as $W) {
    $same = 'b.dir=a.dir AND b.cur=a.cur AND b.cents=a.cents';
    $out["T4$W"] = $q("SELECT COUNT(*) FROM L1 a JOIN L1 b ON b.id>a.id AND a.cli>0 AND b.cli=a.cli AND {$same} AND " . $win($W) . " AND {$leg}");
    $out["T5$W"] = $q("SELECT COUNT(*) FROM L1 a JOIN L1 b ON b.id>a.id AND a.cli=0 AND b.cli=0 AND {$same} AND " . $win($W) . " AND {$leg}");
    $out["T7$W"] = $q("SELECT COUNT(DISTINCT MIN(a.id,b.id) || ':' || MAX(a.id,b.id)) FROM L1 a JOIN L1 b ON a.id<>b.id AND a.kyc AND a.cli>0 AND b.rf
                        AND substr(b.nref,1,4)='PAY-' AND b.cli=a.cli AND b.cur=a.cur AND b.cents=a.cents AND " . $win($W) . " AND {$leg}");
}
// controls: the planted 7-day pair is counted at W=7, the 8-day one is not; with W bound as a parameter, the 8-day one is
$ctl = "SELECT COUNT(*) FROM L1 a JOIN L1 b ON b.id>a.id AND a.cli=%d AND b.cli=a.cli AND b.dir=a.dir AND b.cur=a.cur AND b.cents=a.cents AND a.dok=1 AND b.dok=1 AND ABS(julianday(b.d)-julianday(a.d)) <= %s";
$out['ctl7'] = $q(sprintf($ctl, (int)$c7, '7'));
$out['ctl8'] = $q(sprintf($ctl, (int)$c8, '7'));
$st = $p->prepare($cte . ' ' . sprintf($ctl, (int)$c8, ':w')); $st->execute([':w' => 7]); $out['ctl8bound'] = (int)$st->fetchColumn();
if (($argv[4] ?? '') === '') { foreach ($out as $k => $v) echo "$k $v\n"; exit(0); }
// compare with R0's printed pair counts: <R0 output> as the 4th argument
$o = (string)file_get_contents($argv[4]); $got = []; $fails = 0;
if (preg_match('/T1 payment id    pairs (\d+)/', $o, $m)) $got['T1'] = (int)$m[1];
if (preg_match('/T2 reference     pairs (\d+)/', $o, $m)) $got['T2'] = (int)$m[1];
if (preg_match('/T4 client\+amount same day: pairs (\d+) .*\| ±7 d: pairs (\d+)/', $o, $m)) { $got['T40'] = (int)$m[1]; $got['T47'] = (int)$m[2]; }
if (preg_match('/T5 no client     same day: pairs (\d+) .*?\| ±7 d: pairs (\d+)/', $o, $m)) { $got['T50'] = (int)$m[1]; $got['T57'] = (int)$m[2]; }
if (preg_match('/T7 KYC\/PAY       same day: pairs (\d+) .*?\| ±7 d: pairs (\d+)/', $o, $m)) { $got['T70'] = (int)$m[1]; $got['T77'] = (int)$m[2]; }
foreach (['T1', 'T2', 'T40', 'T47', 'T50', 'T57', 'T70', 'T77'] as $k) {
    $ok = ($got[$k] ?? -1) === $out[$k];
    echo ($ok ? 'ok   ' : 'FAIL ') . "$k: R0 " . ($got[$k] ?? '?') . ", the SQL self-join {$out[$k]}\n"; if (!$ok) $fails++;
}
$ok = $out['ctl7'] === 1 && $out['ctl8'] === 0 && $out['ctl8bound'] === 1;
echo ($ok ? 'ok   ' : 'FAIL ') . "control: at W=7 the 7-day pair is counted ({$out['ctl7']}) and the 8-day one is not ({$out['ctl8']}); bound as a parameter, W would let it in ({$out['ctl8bound']})\n";
exit($fails + ($ok ? 0 : 1));
