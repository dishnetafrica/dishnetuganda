<?php
declare(strict_types=1);
namespace Dn\Runtime;

use Dn\Delivery\DeliveryPort;
use Dn\Delivery\NullDelivery;
use Dn\Radius\NullPublisher;
use Dn\Radius\RadiusPublisherPort;
use Dn\Radius\SimulatedPublisher;

/**
 * Which external-system binding this process is running, and the gate that
 * keeps the real ones out of F6-A.
 *
 * F6-A builds the application. F6-B activates it against real MikroTik and
 * real FreeRADIUS, and is NOT authorized. The difference must be a property of
 * the running process that can be read and asserted — not a deployment note
 * somebody remembers.
 *
 * So: a real binding is selectable ONLY when DN_ALLOW_REAL_BINDINGS is set to
 * the exact string 'yes-f6b-authorized'. No default sets it, no test sets it,
 * and asking for a real binding without it throws rather than silently falling
 * back — a silent fallback to a simulator is how a fake ends up believed.
 */
final class Bindings
{
    public const REAL_GATE_ENV   = 'DN_ALLOW_REAL_BINDINGS';
    public const REAL_GATE_VALUE = 'yes-f6b-authorized';

    public function __construct(
        private readonly DeliveryPort $delivery,
        private readonly RadiusPublisherPort $publisher,
    ) {}

    /**
     * The default for every F6-A process: nothing is delivered, nothing is
     * published, and both say so.
     */
    public static function defaults(): self
    {
        return new self(new NullDelivery(), new NullPublisher());
    }

    /** The development/test wiring: deterministic doubles, clearly labelled. */
    public static function simulated(?DeliveryPort $delivery = null): self
    {
        return new self($delivery ?? new NullDelivery(), new SimulatedPublisher());
    }

    /** True only when F6-B has been explicitly authorized in the environment. */
    public static function realBindingsAllowed(): bool
    {
        return (getenv(self::REAL_GATE_ENV) ?: '') === self::REAL_GATE_VALUE;
    }

    /**
     * @throws \RuntimeException when F6-B is not authorized. Deliberately not
     *         a fallback: the caller asked for the real world and must be told
     *         it cannot have it.
     */
    public static function requireRealBindingsAllowed(string $what): void
    {
        if (!self::realBindingsAllowed()) {
            throw new \RuntimeException(
                "{$what} is an F6-B binding and is not authorized: set "
                . self::REAL_GATE_ENV . " to enable it");
        }
    }

    public function delivery(): DeliveryPort { return $this->delivery; }
    public function publisher(): RadiusPublisherPort { return $this->publisher; }

    /**
     * What /api/v1/admin/health reports. Everything here is a fact about this
     * process, so a screen can never imply a router was contacted when it was
     * not.
     */
    public function describe(): array
    {
        return [
            'delivery_binding'      => (new \ReflectionClass($this->delivery))->getShortName(),
            'publisher_binding'     => $this->publisher->bindingName(),
            'publisher_simulated'   => $this->publisher->isSimulated(),
            'real_bindings_allowed' => self::realBindingsAllowed(),
            'phase'                 => self::realBindingsAllowed() ? 'F6-B' : 'F6-A',
        ];
    }
}
