<?php
declare(strict_types=1);
namespace Dn\Delivery;

/**
 * The boundary at which the platform touches a router.
 *
 * ONLY Dn\Jobs may call an implementation of this. Nothing in Dn\Http or
 * Dn\Api may reference it, so an HTTP request cannot reach a router within
 * one process. That is F2 as a code boundary rather than a convention, and
 * tests/test_frozen_guards.php asserts it.
 *
 * The real RouterOS REST implementation lands in step 7. Until then the only
 * implementations are test doubles, which is why no device credentials exist
 * anywhere in this codebase yet.
 */
interface DeliveryPort
{
    /**
     * Attempt to carry out an intent.
     *
     * @return DeliveryResult
     * @throws \RuntimeException on a transport failure the caller should retry
     */
    public function deliver(array $intent): DeliveryResult;

    /**
     * Read back actual state to decide whether the intent really took effect.
     *
     * Confirmation is a READ, never the return value of the write. A router
     * that accepts a command and does not apply it is a real failure mode,
     * and trusting the write's own success is how it goes unnoticed.
     */
    public function confirm(array $intent): bool;
}
