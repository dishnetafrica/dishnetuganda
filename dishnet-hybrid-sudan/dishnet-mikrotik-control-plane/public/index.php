<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Tenancy\TenantContext;

$db = Database::app();
$kernel = new Kernel(
    Routes::build(new Authenticator($db)),
    $db,
    new Authenticator($db),
    new TenantContext($db),
);
$kernel->handle(Request::fromGlobals())->send();
