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
    /*
     * Both methods take the Database the WORKER is currently scoped to.
     *
     * They used to use a connection captured at construction. That worked only
     * while the worker and the delivery happened to share one object: the
     * worker sets app.customer_id on ITS connection, so a delivery holding a
     * different one sees nothing and reports "device not found". Role
     * separation (docs/57 §10) split those connections and the coupling
     * surfaced immediately. Passing it makes the requirement impossible to get
     * wrong rather than merely documented.
     */
    /**
     * Attempt to carry out an intent.
     *
     * @return DeliveryResult
     * @throws \RuntimeException on a transport failure the caller should retry
     */
    public function deliver(\Dn\Db\Database $db, array $intent): DeliveryResult;

    /**
     * Read back actual state to decide whether the intent really took effect.
     *
     * Confirmation is a READ, never the return value of the write. A router
     * that accepts a command and does not apply it is a real failure mode,
     * and trusting the write's own success is how it goes unnoticed.
     */
    public function confirm(\Dn\Db\Database $db, array $intent): bool;
}
