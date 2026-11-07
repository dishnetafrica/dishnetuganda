<?php
declare(strict_types=1);
/**
 * Weakened copies judged by section L (5.18.104, root docs/73 §H.4.16 L): a phone in the run line (OM64), a fixture name
 * and a wording written to the plugin log. TEST ONLY.
 */
$M = array_merge($M, [
    ['label' => 'OM64 a phone printed in the run line (the queue\'s last recipient, on every coded run)', 'edits' => [
        ['lib/OfferSender.php', "        \$this->run['code'] = \$code;\n",
         "        \$this->run['code'] = \$code;\n        \$this->run['last'] = (string)\$this->pdo->query('SELECT phone FROM wa_offer_recipients ORDER BY id DESC LIMIT 1')->fetchColumn();\n"],
        ['cron/offers_send.php', "\$r['handsets'], \$r['code']);", "\$r['handsets'], \$r['code'] . ' to=' . (\$r['last'] ?? ''));"]],
     'sections' => 'L', 'cases' => 'L01', 'pattern' => '/L01 /'],
    ['label' => 'OM-L1 a fixed-code log line carries the offer\'s title', 'file' => 'lib/OfferSender.php',
     'old' => "        if (\$code !== '') \$this->sayOnceToday(\$code);\n",
     'new' => "        if (\$code !== '') { \$this->sayOnceToday(\$code); PluginLog::write('offers_send', \$code . ' ' . (string)(OfferStore::live(\$this->pdo)['title'] ?? '')); }\n",
     'sections' => 'L', 'cases' => 'L02', 'pattern' => '/L02 /'],
    ['label' => 'OM-L2 a failed send writes its wording to the plugin log', 'file' => 'lib/OfferSender.php',
     'old' => "        \$sentAt = UtcClock::stamp(\$this->now());\n        // 9. the ledger's settle",
     'new' => "        \$sentAt = UtcClock::stamp(\$this->now());\n        if (\$res->outcome === OfferSendResult::NOT_SENT) PluginLog::write('offers_send', 'not sent: ' . \$text);\n        // 9. the ledger's settle",
     'sections' => 'L', 'cases' => 'L02', 'pattern' => '/L02 /'],
]);
