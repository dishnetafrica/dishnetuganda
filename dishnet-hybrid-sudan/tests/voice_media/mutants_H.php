<?php
declare(strict_types=1);
/**
 * Weakened copies for section H of tests/test_voice_media.php (5.18.104, root docs/73 §H.3.24: V37, V42's two forms,
 * V43, and the runner's gates). TEST ONLY.
 */
$R  = 'run_media_worker.php';
$MW = 'workers/MediaWorker.php';
$check = "        \$need = \$this->fetchTimeoutS() + 5 + (\$mode === 'fetch_only' ? 0 : \$this->voiceTimeoutS());\n"
       . "        if (time() + \$need > \$this->budgetEnd) {\n            \$this->budgetSpent = true;\n"
       . "            throw new WorkerDefer(30, 'media: not enough time left in this run');\n        }\n";
$M = array_merge($M, [
    ['label' => 'V37 the master-mode budget ignores $_m_deadline', 'file' => $R,
     'old' => '$_rmwDeadline = isset($_m_deadline) ? (int)$_m_deadline : null;', 'new' => '$_rmwDeadline = null;',
     'sections' => 'H', 'cases' => 'H02', 'pattern' => '/H02 /'],
    ['label' => 'V42a the budget check removed', 'file' => $MW,
     'old' => '        if (time() + $need > $this->budgetEnd) {', 'new' => '        if (false) {',
     'sections' => 'H', 'cases' => 'H02', 'pattern' => '/H02 /'],
    ['label' => 'V42b the budget check moved after status => fetching', 'edits' => [
        [$MW, $check, ''],
        [$MW, '        $fetcher = $this->fetcher ?? new MediaFetcher($this->evoClient(), $this->fresh);', $check . '        $fetcher = $this->fetcher ?? new MediaFetcher($this->evoClient(), $this->fresh);']],
     'sections' => 'H', 'cases' => 'H02', 'pattern' => '/H02 /'],
    ['label' => 'V43 batch above 1 in master mode', 'file' => $R,
     'old' => '    $_rmwWorker = new MediaWorker($store, $config, $_rmwBudget, 1, $_rmwDeadline);', 'new' => '    $_rmwWorker = new MediaWorker($store, $config, $_rmwBudget, 5, $_rmwDeadline);',
     'sections' => 'H', 'cases' => 'H03', 'pattern' => '/H03 /'],
    ['label' => 'RMW-GATE the runner without its Uganda gate', 'file' => $R,
     'old' => "if (!StaffJobsGate::applies(\$config, \$dataDir)) return;\n", 'new' => '',
     'sections' => 'H', 'cases' => 'H05', 'pattern' => '/H05 South Sudan/'],
    ['label' => 'RMW-MIN the runner starting with under 10 s left', 'file' => $R,
     'old' => "    if (\$_rmwBudget < 10) return;\n", 'new' => '',
     'sections' => 'H', 'cases' => 'H02', 'pattern' => '/H02 with 5 s/'],
    ['label' => 'RMW-AI the runner ignoring ai_enabled', 'file' => $R,
     'old' => "if (!PluginConfig::toBool(\$config['ai_enabled'] ?? false)) {\n    return;\n}\n", 'new' => '',
     'sections' => 'H', 'cases' => 'H05', 'pattern' => '/H05 the assistant off/'],
]);
