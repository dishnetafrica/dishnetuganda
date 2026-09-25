<?php
declare(strict_types=1);
namespace Dn\Plugin;

/**
 * The plugin manifest, read rather than assumed.
 *
 * Every field a caller can ask for is one the JSON actually declares. A missing
 * required key throws here rather than becoming a null three layers away.
 */
final class Manifest
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $domain,
        public readonly bool   $consumesDomainA,
        public readonly string $apiBase,
        public readonly string $apiEntrypoint,
        public readonly string $apiSurface,
        public readonly array  $routes,
        public readonly array  $writeRoutes,
        public readonly array  $unboundWrites,
        /** The login boundary. Declared apart from routes because these are
         *  the ONLY paths with no capability: a capability is what
         *  authenticating grants. */
        public readonly array  $sessionRoutes,
        /** The DishNet staff roster (migration 026): the one capability-gated
         *  write block, Admin only. Declared apart from estate writes because
         *  it changes identity state, never estate state. */
        public readonly array  $staffRoutes,
        /** SMS for sign-in codes (migration 033, docs/128): Admin only, and
         *  declared apart because it is deployment configuration — where every
         *  sign-in code goes — not estate or identity state. */
        public readonly array  $settingsRoutes,
        public readonly array  $config,
        public readonly array  $gates,
        public readonly array  $requires,
    ) {}

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("plugin manifest not found: {$path}");
        }
        $j = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $need = static function (array $a, string $k, string $where) {
            if (!array_key_exists($k, $a)) {
                throw new \RuntimeException("plugin manifest: missing {$where}{$k}");
            }
            return $a[$k];
        };

        $api = $need($j, 'api', '');
        return new self(
            id:              (string) $need($j, 'id', ''),
            name:            (string) $need($j, 'name', ''),
            version:         (string) $need($j, 'version', ''),
            domain:          (string) $need($j, 'domain', ''),
            consumesDomainA: (bool) ($j['boundary']['consumes_domain_a'] ?? true),
            apiBase:         (string) $need($api, 'base', 'api.'),
            apiEntrypoint:   (string) $need($api, 'entrypoint', 'api.'),
            apiSurface:      (string) $need($api, 'surface', 'api.'),
            routes:          (array) $need($api, 'routes', 'api.'),
            writeRoutes:     (array) ($api['writes']['bound'] ?? []),
            unboundWrites:   (array) ($api['writes']['declared_unbound'] ?? []),
            sessionRoutes:   (array) ($api['session']['routes'] ?? []),
            staffRoutes:     (array) ($api['staff']['routes'] ?? []),
            settingsRoutes:  (array) ($api['settings']['routes'] ?? []),
            config:          (array) $need($j, 'config', ''),
            gates:           (array) $need($j, 'gates', ''),
            requires:        (array) $need($j, 'requires', ''),
        );
    }

    /** Config keys the manifest marks required that the environment lacks. */
    public function missingConfig(): array
    {
        $missing = [];
        foreach ($this->config as $key => $spec) {
            if (($spec['required'] ?? false) && (getenv($key) ?: '') === '') { $missing[] = $key; }
        }
        return $missing;
    }

    /** A gate is open only when the manifest says so. Unknown gates are closed. */
    public function gateIsOpen(string $name): bool
    {
        return ($this->gates[$name]['state'] ?? 'NOT AUTHORIZED') === 'OPEN';
    }
}
