<?php
declare(strict_types=1);
namespace Dn\Auth;

/** Thrown when a credential endpoint is being hit too often. Maps to 429. */
final class RateLimited extends \RuntimeException {}
