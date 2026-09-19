<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';
use Dn\Db\Database;
use Dn\Db\Migrator;

$applied = (new Migrator(Database::owner(), __DIR__ . '/../migrations'))->run();
echo $applied ? 'applied: ' . implode(', ', $applied) . "\n" : "nothing to apply\n";
