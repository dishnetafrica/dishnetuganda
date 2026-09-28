<?php
declare(strict_types=1);
/**
 * REHEARSAL ONLY — the repository's fake uCRM (tests/fixtures/fake_ucrm_staff_jobs.php, included below unchanged) plus
 * what real uCRM does that it does not: a job created, changed or deleted is followed a moment later by uCRM's webhook
 * to the plugin, and DELETE of a job. The rehearsal copies this file over the fake's name in a scratch copy of the
 * plugin tree and the fake itself beside it as fake_ucrm_staff_jobs_orig.php. The plugin's webhook address is read
 * from webhook_url.txt beside the fake's state file, written once the plugin is up. Everything is fictitious.
 */
$wtState  = (string)getenv('FAKE_UCRM_STATE');
$wtMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$wtPath   = (string)preg_replace('#^/api/v[0-9.]+#', '', (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));

/** uCRM's webhook, as uCRM sends it, one second later and in the background: the API answer does not wait for it. */
function wt_emit(string $change, string $name, int $id, ?array $entity): void
{
    $dir = dirname((string)getenv('FAKE_UCRM_STATE'));
    $url = trim((string)@file_get_contents($dir . '/webhook_url.txt'));
    if ($url === '') return;
    $body = json_encode(['uuid' => bin2hex(random_bytes(8)), 'changeType' => $change, 'entity' => 'job', 'entityId' => (string)$id,
        'eventName' => $name, 'extraData' => ['entity' => $entity, 'entityBeforeEdit' => null]]);
    $f = $dir . '/emit_' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($f, $body);
    exec('(sleep 1; curl -s -m 60 --noproxy "*" -o /dev/null -X POST -H "Content-Type: application/json" -H "X-Ucrm-Key: '
        . 'sj-webhook-secret" --data-binary @' . escapeshellarg($f) . ' ' . escapeshellarg($url) . '; rm -f ' . escapeshellarg($f)
        . ') > /dev/null 2>&1 &');
}

if ($wtMethod === 'DELETE' && preg_match('#^/scheduling/jobs/(\d+)$#', $wtPath, $m)) {
    $st = json_decode((string)@file_get_contents($wtState), true) ?: [];
    $st['requests'][] = ['method' => 'DELETE', 'path' => $wtPath, 'query' => '', 'body' => []];
    $job = $st['jobs'][$m[1]] ?? null;
    if ($job === null) { file_put_contents($wtState, json_encode($st)); http_response_code(404); echo '{"code":404}'; exit; }
    unset($st['jobs'][$m[1]]);
    file_put_contents($wtState, json_encode($st));
    wt_emit('delete', 'job.delete', (int)$m[1], $job);
    http_response_code(200); header('Content-Type: application/json'); echo '{}'; exit;
}
$wtIsCreate = $wtMethod === 'POST' && $wtPath === '/scheduling/jobs';
$wtIsEdit   = $wtMethod === 'PATCH' && preg_match('#^/scheduling/jobs/(\d+)$#', $wtPath, $wtM);
if ($wtIsCreate || $wtIsEdit) {
    $wtBefore = array_keys((array)((json_decode((string)@file_get_contents($wtState), true) ?: [])['jobs'] ?? []));
    register_shutdown_function(function () use ($wtState, $wtIsCreate, $wtBefore, $wtM) {
        if (http_response_code() >= 300) return;
        $jobs = (array)((json_decode((string)@file_get_contents($wtState), true) ?: [])['jobs'] ?? []);
        if ($wtIsCreate) {
            $new = array_values(array_diff(array_keys($jobs), $wtBefore));
            if (count($new) === 1) wt_emit('insert', 'job.add', (int)$new[0], $jobs[$new[0]]);
        } else {
            $id = (string)(int)$wtM[1];
            if (isset($jobs[$id])) wt_emit('edit', 'job.edit', (int)$id, $jobs[$id]);
        }
    });
}
require __DIR__ . '/fake_ucrm_staff_jobs_orig.php';
