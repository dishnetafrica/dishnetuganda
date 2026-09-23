<?php
declare(strict_types=1);
namespace Dn\Admin;

/** A router write the database refused on purpose: duplicate identity, unknown operator, foreign site, illegal state. */
final class RouterRefused extends \RuntimeException {}
