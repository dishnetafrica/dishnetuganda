<?php
declare(strict_types=1);
namespace Dn\Admin;

/** A settings change the database refused on purpose: a missing key, a username change without its key, an invalid field. The message is the function's fixed text. */
final class SettingsRefused extends \RuntimeException {}
