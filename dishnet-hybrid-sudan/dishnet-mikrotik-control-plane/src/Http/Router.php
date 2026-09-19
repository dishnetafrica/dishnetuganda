<?php
declare(strict_types=1);
namespace Dn\Http;

final class Router
{
    /** @var list<array{string,string,callable,bool}> */
    private array $routes = [];

    /** $auth = does this route require a bearer token */
    public function add(string $method, string $pattern, callable $handler, bool $auth = true): void
    {
        $this->routes[] = [$method, $pattern, $handler, $auth];
    }

    public function get(string $pattern, callable $handler, bool $auth = true): void
    { $this->add('GET', $pattern, $handler, $auth); }

    public function post(string $pattern, callable $handler, bool $auth = true): void
    { $this->add('POST', $pattern, $handler, $auth); }

    /** Route patterns, so a test can assert none accepts a customer id. */
    public function patterns(): array
    {
        return array_map(static fn(array $r): string => $r[1], $this->routes);
    }

    /** @return array{callable,array,bool}|null */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as [$m, $pattern, $handler, $auth]) {
            if ($m !== $method) { continue; }
            $rx = '#^' . preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (preg_match($rx, $path, $mm)) {
                $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
                return [$handler, $params, $auth];
            }
        }
        return null;
    }
}
