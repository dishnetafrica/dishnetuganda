<?php
// Fake uCRM for the AI-check rehearsal: service plans and products from the JSON files beside it (CATALOGUE_DIR).
$dir  = getenv('CATALOGUE_DIR') ?: __DIR__;
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
header('Content-Type: application/json');
if ($path === '/__ping')                    { echo '{"fake":"ucrm-aicheck"}'; exit; }
if (preg_match('#/service-plans$#', $path)) { readfile($dir . '/plans.json'); exit; }
if (preg_match('#/products$#', $path))      { readfile($dir . '/products.json'); exit; }
http_response_code(404); echo '[]';
