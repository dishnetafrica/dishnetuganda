<?php
// ═══════════════════════════════════════════════════════════════
// JOB PHOTOS — 5.18.66, Uganda only
// ═══════════════════════════════════════════════════════════════
// Labelled site photos on a uCRM job (My Jobs): the kit, the cable used, the router, anything else. Loaded right after
// api_scheduling.php and reusing its Uganda gate ($_sjUganda), its caller ($sjCaller) and its job-access check
// ($sjMayAct, docs/44 J6): the assignee, a support leader or an admin, decided on the server from the account as stored
// now and the job as uCRM holds it. Off Uganda none of these actions exists — the request falls through to
// "Unknown API action". The completion LOCATION is taken by scheduling_complete in api_scheduling.php; this file
// serves the photos. Files and rows: lib/JobPhotos.php, migrations/084_job_photos.sql.

if (!empty($_sjUganda) && in_array($act, ['job_photo_upload', 'job_photos', 'job_photo_delete'], true)) {
    require_once dirname(__DIR__, 2) . '/lib/JobPhotos.php';
    if (!$crm->isConfigured()) $er2('CRM not configured.', 503);
    $jpData = (string)($dataDir ?? '');
    if ($jpData === '') $er2('Data directory not configured.', 500);   // never write beside the code (SAFETY.md RULE 15)
    $jpPdo    = $store->getPdo();
    $jpCaller = $sjCaller();                                           // the account as stored now; 403 when inactive

    // The job, and the right to act on it, before anything is read or written. The job id comes from the request on
    // upload and list; on delete it comes from the photo's own row, so a photo can never be detached from its job.
    $jpJobOf = function (int $jobId) use ($crm, $er2, $sjMayAct, $jpCaller): array {
        if ($jobId <= 0) $er2('job_id required.', 422);
        $job = $crm->get("scheduling/jobs/{$jobId}");
        if (!$job) $er2('Job not found.', 404);
        $sjMayAct($jpCaller, $job);
        return $job;
    };
    $jpBy = [
        'id'           => (int)($jpCaller['id'] ?? 0),
        'name'         => (string)($jpCaller['name'] ?? ''),
        'ucrm_user_id' => StaffDirectory::linkedUcrmUser($jpCaller),   // the VERIFIED link only (M7), or 0
    ];

    // ── GET job_photos — the job's photos, what is still required, and its completion location ──
    if ($act === 'job_photos' && $met === 'GET') {
        $jobId = (int)($_GET['job_id'] ?? 0);
        $jpJobOf($jobId);
        $ok2([
            'photos'           => JobPhotos::list($jpPdo, $jobId),
            'missing_required' => JobPhotos::missingRequired($jpPdo, $jobId),
            'completion'       => JobPhotos::completion($jpPdo, $jobId),
        ]);
    }

    // ── POST job_photo_upload — multipart/form-data: job_id, label, photo ──
    // The fields arrive in $_POST and the image in $_FILES; $body (the JSON decode) is empty on a multipart request.
    if ($act === 'job_photo_upload' && $met === 'POST') {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $label = strtolower(trim((string)($_POST['label'] ?? '')));
        if (!isset(JobPhotos::LABELS[$label])) $er2('Unknown photo label.', 422);
        $job = $jpJobOf($jobId);
        if ((int)($job['status'] ?? 0) === 2) $er2('This job is closed; its photos can no longer change.', 409);
        if (JobPhotos::count($jpPdo, $jobId) >= JobPhotos::MAX_PER_JOB) {
            $er2('This job already has ' . JobPhotos::MAX_PER_JOB . ' photos.', 422);
        }
        $res = JobPhotos::store($jpPdo, $jpData, $jobId, $label, is_array($_FILES['photo'] ?? null) ? $_FILES['photo'] : [], $jpBy, JobAccess::assigneeOf($job));
        if (!$res['ok']) $er2($res['error'], 422);
        $ok2([
            'photo'            => $res['photo'],
            'photos'           => JobPhotos::list($jpPdo, $jobId),
            'missing_required' => JobPhotos::missingRequired($jpPdo, $jobId),
        ], 'Photo saved.');
    }

    // ── POST job_photo_delete — {photo_id}: the person who took it, a support leader or an admin, while the job is open ──
    if ($act === 'job_photo_delete' && $met === 'POST') {
        $photoId = (int)($body['photo_id'] ?? 0);
        $row = JobPhotos::find($jpPdo, $photoId);
        if (!$row) $er2('Photo not found.', 404);
        $job = $jpJobOf((int)$row['job_id']);
        if ((int)($job['status'] ?? 0) === 2) $er2('This job is closed; its photos can no longer change.', 409);
        if ((int)$row['retailer_id'] !== $jpBy['id'] && !StaffDirectory::isAdmin($jpCaller) && !JobAccess::isLeader($jpCaller)) {
            $er2('Only the person who took this photo, a support leader or an admin can remove it.', 403);
        }
        JobPhotos::delete($jpPdo, $jpData, $row);
        $ok2([
            'photos'           => JobPhotos::list($jpPdo, (int)$row['job_id']),
            'missing_required' => JobPhotos::missingRequired($jpPdo, (int)$row['job_id']),
        ], 'Photo removed.');
    }

    $er2('Method not allowed.', 405);
}
