<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

use Nowo\RbacKitBundle\Internal\HiddenValues;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function str_starts_with;
use function strtolower;

/**
 * Framework-free view of the main request the request guard checks, given to a
 * {@see RbacRequestContextResolverInterface} (since 1.2, REQ-ARCH-001 rule 3: no Symfony `Request` in a public port).
 *
 * Its properties hold only the method, scheme, host, path and route name. The values a resolver reads to find the
 * context are **not** properties: the scalar route parameters ({@see routeParam()}), the scalar request attributes
 * whose name does not start with `_` ({@see attribute()}) and the headers named in `request_guard.context_headers`
 * ({@see header()}, default none; credential and cookie headers are refused by the configuration). They live in an
 * internal {@see \WeakMap} keyed by this object, so a dump, `var_export()`, `(array)` cast, `json_encode()` or
 * `serialize()` of the VO never shows them, and a `clone` or an unserialized copy has none. Cookies, the query string,
 * the body, the session and object attributes are never copied: resolve the context from an id.
 *
 * Built by the bundle per request and never stored (FrankenPHP worker mode, kernel reset false).
 */
final readonly class RbacRequest
{
    /** Bag of the scalar route parameters. */
    private const ROUTE = 'route';

    /** Bag of the scalar request attributes. */
    private const ATTRIBUTES = 'attributes';

    /** Bag of the allowlisted headers. */
    private const HEADERS = 'headers';

    /**
     * @param string $method HTTP method (upper case)
     * @param string $path path info (no query string)
     * @param string|null $routeName the matched route (`_route`)
     * @param array<array-key, mixed> $routeParams route parameters; only scalar values with a string name are kept
     * @param array<array-key, mixed> $attributes request attributes; only scalar values whose string name does not start with `_` are kept
     * @param array<array-key, mixed> $headers header name => value (string, or a list whose first string is kept); the bundle passes only the allowlisted ones
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $routeName = null,
        public string $scheme = 'https',
        public string $host = '',
        array $routeParams = [],
        array $attributes = [],
        array $headers = [],
    ) {
        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $first = is_array($value) ? ($value[0] ?? null) : $value;
            if (is_string($name) && is_string($first)) {
                $normalizedHeaders[strtolower($name)] = $first;
            }
        }

        HiddenValues::put($this, [
            self::ROUTE      => $this->scalars($routeParams, false),
            self::ATTRIBUTES => $this->scalars($attributes, true),
            self::HEADERS    => $normalizedHeaders,
        ]);
    }

    /** A scalar route parameter (`/projects/{id}` → `routeParam('id')`), or null. */
    public function routeParam(string $name): bool|float|int|string|null
    {
        return $this->scalar(HiddenValues::get($this, self::ROUTE, $name));
    }

    /** True when the route has this scalar parameter. */
    public function hasRouteParam(string $name): bool
    {
        return HiddenValues::has($this, self::ROUTE, $name);
    }

    /**
     * Every scalar route parameter.
     *
     * @return array<string, bool|float|int|string|null>
     */
    public function routeParams(): array
    {
        $params = [];
        foreach (HiddenValues::all($this, self::ROUTE) as $name => $value) {
            $params[$name] = $this->scalar($value);
        }

        return $params;
    }

    /** A scalar request attribute whose name does not start with `_` (route parameters included), or null. */
    public function attribute(string $name): bool|float|int|string|null
    {
        return $this->scalar(HiddenValues::get($this, self::ATTRIBUTES, $name));
    }

    /** True when the request has this scalar attribute. */
    public function hasAttribute(string $name): bool
    {
        return HiddenValues::has($this, self::ATTRIBUTES, $name);
    }

    /** The first value of an allowlisted header (case-insensitive name), or null. */
    public function header(string $name): ?string
    {
        $value = HiddenValues::get($this, self::HEADERS, strtolower($name));

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, bool|float|int|string|null>
     */
    private function scalars(array $values, bool $skipInternal): array
    {
        $kept = [];
        foreach ($values as $name => $value) {
            if (!is_string($name) || ($skipInternal && str_starts_with($name, '_'))) {
                continue;
            }
            if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    private function scalar(mixed $value): bool|float|int|string|null
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) ? $value : null;
    }
}
