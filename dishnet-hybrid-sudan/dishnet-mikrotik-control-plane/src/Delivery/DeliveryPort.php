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
 * Three implementations exist (docs/80 §4, docs/118 §B):
 *
 *   NullDelivery       the default — delivers nothing, confirms nothing, says so
 *   SimulatedRouterOs  a deterministic in-memory router; every result tagged simulated
 *   RouterOsDelivery   the real adapter; opens a socket only behind the F6-B gate
 *
 * Which one a process runs is decided by Dn\Runtime\Bindings and reported
 * at /api/v1/admin/health. It is never decided by falling back.
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

    /** The name /health and the worker report for this binding. */
    public function bindingName(): string;

    /**
     * True for every double. A confirmation from a simulated binding is a
     * statement about the simulator's memory and never about a router.
     */
    public function isSimulated(): bool;
}
