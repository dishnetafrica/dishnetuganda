<?php
/**
 * Drives the real JobNotifier in-process — no server, no network — so the same run can be compared under the server's
 * PHP 8.1 and the development PHP 8.4. Fakes: uCRM (jobs, clients), WhatsApp (records the send). The store is the real
 * SqliteStore on a fresh directory, so migrations 075 and 076 run as they would on the server.
 */
$root = $argv[1];
$dir  = $argv[2];
@mkdir($dir, 0700, true);
foreach (['StoreInterface', 'SqliteStore', 'JobNotifier'] as $c) require_once "{$root}/lib/{$c}.php";

final class FakeCrm {
    public $jobs = []; public $last = [];
    public function get(string $path) {
        if (preg_match('#^scheduling/jobs/(\d+)$#', $path, $m)) {
            if (!isset($this->jobs[(int)$m[1]])) { $this->last = ['http_code' => 404]; return null; }
            $this->last = []; return $this->jobs[(int)$m[1]];
        }
        if ($path === 'clients/15') return ['id' => 15, 'firstName' => 'Sandbox', 'lastName' => 'Customer', 'contacts' => [['phone' => '+256700000915']]];
        return null;
    }
    public function getLastError() { return $this->last; }
}
final class FakeNotify {
    public $sent = []; private $ok = true;
    public function failNext() { $this->ok = false; }
    public function sendVia($acct, $phone, $text, $log, $extra = [], $class = null) { $this->sent[] = [$acct, $phone, $log, md5($text)]; }
    public function lastSendResult() { $ok = $this->ok; $this->ok = true; return $ok ? ['success' => true, 'http_code' => 200, 'error' => null] : ['success' => false, 'http_code' => 500, 'error' => 'x']; }
}

$store = SqliteStore::create($dir);
$cfg = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'crm_base_url' => 'http://crm.invalid', 'email_reply_to' => 'jobs-reply@example.test'];
$link = function (int $id, string $email) { return ['ucrm_user_id' => $id, 'ucrm_link' => ['user_id' => $id, 'email' => $email, 'verified_at' => '2026-09-27T00:00:00Z', 'verified_by' => 1]]; };
$store->save('retailers.json', [
    ['id' => 3, 'name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '+256700000111', 'is_active' => true] + $link(1099, 'tech@example.test'),
    ['id' => 4, 'name' => 'Tech Two', 'email' => 'tech2@example.test', 'role' => 'support', 'phone' => 'call me', 'is_active' => true] + $link(1100, 'tech2@example.test'),
    ['id' => 5, 'name' => 'Odd', 'email' => 'not-an-address', 'role' => 'support', 'phone' => '+256700000125', 'is_active' => true] + $link(1700, 'not-an-address'),
]);
$crm = new FakeCrm(); $wa = new FakeNotify();
$out = [];
$job = function (int $id, array $f) use ($crm) { $crm->jobs[$id] = $f + ['id' => $id, 'title' => "Job {$id}", 'clientId' => 15, 'assignedUserId' => 1099, 'date' => '2026-10-07T09:00:00+0300', 'status' => 0, 'address' => 'Plot 9']; };
$n = function () use ($crm, $store, $wa, $cfg, $dir) { return new JobNotifier($crm, $store, $wa, $cfg, $dir); };
$pick = function (array $r) { return ['event' => $r['event'], 'outcome' => $r['outcome'], 'messages' => array_map(function ($m) { return [$m['message'], $m['outcome'], $m['email'] ?? null]; }, $r['messages'])]; };

// 1. no mail server set up: WhatsApp sent, e-mail not_configured
$job(5, []);
$r = $n()->observe(5, 'my_jobs'); $out['assigned, no mail server'] = $pick($r) + ['note' => JobNotifier::note($r), 'log' => JobNotifier::logLines(5, $r)];
$out['redelivered'] = $pick($n()->observe(5, 'ucrm_webhook'));
// 2. reassigned to an account with no usable number: its e-mail still tried
$job(5, ['assignedUserId' => 1100]);
$r = $n()->observe(5, 'ucrm_webhook'); $out['reassigned'] = $pick($r) + ['log' => JobNotifier::logLines(5, $r)];
// 3. an account whose e-mail is not an address
$job(6, ['assignedUserId' => 1700]);
$out['odd address'] = $pick($n()->observe(6, 'ucrm_webhook'));
// 4. a mail server that refuses (nothing listens on port 1): the first fails, the next is not tried
file_put_contents($dir . '/email_settings.json', json_encode(['use_ucrm_email' => false, 'smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_user' => '', 'smtp_pass' => '', 'smtp_enc' => '', 'smtp_from' => 'accounts@example.test']));
$one = $n();
$job(7, []); $job(8, []);
$a = $one->observe(7, 'bulk'); $b = $one->observe(8, 'bulk');
$out['mail server down'] = [$pick($a), $pick($b), 'second_detail' => $b['messages'][0]['email_detail'] ?? null, 'notes' => JobNotifier::notes([7 => $a, 8 => $b])];
// 5. Accept, then the history
$before = $crm->jobs[7]; $after = ['status' => 1] + $before;
$out['accepted'] = $pick($n()->accepted(7, $before, $after));
$out['recordedSince'] = $pick((array)$n()->recordedSince(5, 'assigned', 0));
$out['history'] = $store->getPdo()->query('SELECT job_id, event, message, outcome, email_outcome FROM job_notify_events ORDER BY id')->fetchAll(PDO::FETCH_NUM);
$out['whatsapp'] = array_map(function ($s) { return [$s[0], $s[1], $s[2]]; }, $wa->sent);
$out['migrations'] = $store->getPdo()->query("SELECT filename FROM _migrations WHERE filename LIKE '07%' ORDER BY filename")->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
