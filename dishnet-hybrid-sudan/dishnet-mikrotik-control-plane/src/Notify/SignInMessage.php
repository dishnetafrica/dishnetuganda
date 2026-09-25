<?php
declare(strict_types=1);
namespace Dn\Notify;

use Dn\Auth\Authenticator;

/**
 * The one message this system sends (docs/127 S-7): the code, how long it is
 * valid, and a warning. No operator name, no link — nothing a forwarded or
 * intercepted message could use beyond the code itself.
 */
final class SignInMessage
{
    public static function text(string $code): string
    {
        return 'DishNet sign-in code: ' . $code . '. Valid ' . Authenticator::CODE_TTL_MINUTES
             . ' minutes. Do not share it.';
    }
}
