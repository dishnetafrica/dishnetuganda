<?php
declare(strict_types=1);
/** Weakened copies for section I of tests/test_catalogue_direct.php (5.18.103, root docs/72 §G.13): each a one-fault copy
 * of the plugin that section must catch. Appended to $QFU_MUTANTS by the runner. TEST ONLY. */
$QFU_MUTANTS = array_merge($QFU_MUTANTS, [
    ['label' => 'MI1 webhook.php changed (§G.12 keeps it byte for byte)', 'file' => 'webhook.php',
     'old' => '<?php',
     'new' => '<?php
// changed', 'sections' => 'I', 'pattern' => '/I2 /'],
    ['label' => 'MI2 a migration changed', 'file' => 'migrations/001_event_queue.sql',
     'old' => '-- Migration 001: Event Queue',
     'new' => '-- Migration 001: Event Queue (changed)', 'sections' => 'I', 'pattern' => '/I2 /'],
    ['label' => 'MI3 an undeclared runtime file changed', 'file' => 'lib/currency.php',
     'old' => '<?php',
     'new' => '<?php
// 5.18.103 changed', 'sections' => 'I', 'pattern' => '/I1 /'],
    ['label' => 'MI4 a runtime hunk with no 5.18.103 marker', 'file' => 'includes/navigation.php',
     'old' => '<?php /*  SALES / GENERAL  */ ?>',
     'new' => '<?php /*  SALES / GENERAL  */ ?>
<?php $__x = 1; ?>', 'sections' => 'I', 'pattern' => '/I3 /'],
    ['label' => 'MI5 PHP 8 syntax on an added line', 'file' => 'lib/UcrmCatalogue.php',
     'old' => '    public function open(): bool
    {
        return $this->open;',
     'new' => '    public function open(): bool
    {
        if (str_contains(\'a\', \'b\')) return false;
        return $this->open;', 'sections' => 'I', 'pattern' => '/I4 /'],
    ['label' => 'MI6 lib/UcrmCatalogue.php requiring a file at file scope', 'file' => 'lib/UcrmCatalogue.php',
     'old' => 'final class UcrmCatalogue
{',
     'new' => 'require_once __DIR__ . \'/QuoteOrganization.php\';
final class UcrmCatalogue
{', 'sections' => 'I', 'pattern' => '/I5 /'],
]);
