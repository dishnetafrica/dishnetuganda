<?php
declare(strict_types=1);
namespace Dn\Admin;

/** An onboarding write the database refused on purpose: a reused key, an inactive operator or service, a missing service, an invalid field. */
final class OnboardingRefused extends \RuntimeException {}
