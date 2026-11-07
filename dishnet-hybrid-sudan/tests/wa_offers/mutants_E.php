<?php
declare(strict_types=1);
/** Weakened copies judged by section E (5.18.104, root docs/73 §H.4.16): STOP in every scope. TEST ONLY. */
$M = array_merge($M, [
    ['label' => 'OM9-E the opt-out read for the channel or * alone (ContactOptOut::find)', 'file' => 'lib/OfferAudience.php',
     'old' => "'SELECT phone FROM contact_optouts WHERE active = 1'", 'new' => "\"SELECT phone FROM contact_optouts WHERE active = 1 AND channel IN ('*', 'sales')\"",
     'sections' => 'E', 'cases' => 'E03', 'pattern' => '/E03 /'],
    ['label' => 'OM66 the offers-only stop word after an offer not read', 'file' => 'lib/OfferStore.php',
     'old' => "if (\$stop === null && OfferText::stopWordIn((string)(\$m['body'] ?? '')) !== '') \$stop = (string)\$m['sent_at'];", 'new' => ';',
     'sections' => 'E', 'cases' => 'E02', 'pattern' => '/E02 /'],
    ['label' => 'OM-E1 the gateway without the opt-out reader on its own book (the send\'s refusal reads another book)', 'file' => 'lib/EvolutionOfferGateway.php',
     'old' => "        \$this->evo->setOptOut(new \\ContactOptOut(\$pdo));\n", 'new' => "        \$this->evo->setOptOut(new \\ContactOptOut(new \\PDO('sqlite::memory:')));\n",
     'sections' => 'E', 'cases' => 'E01', 'pattern' => '/E01 \(2\) /'],   // E01's half (2): the send's own refusal, judge()'s read out of the way
]);
