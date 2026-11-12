<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Http;

use Nowo\RbacKitBundle\Context\RbacRequest;
use Symfony\Component\HttpFoundation\Request;

use function is_array;
use function is_string;

/**
 * Builds the framework-free {@see RbacRequest} of a Symfony request at the HTTP edge (since 1.2).
 *
 * Copies the method, scheme, host, path, `_route`, the `_route_params`, the request attributes (the VO keeps the
 * scalar ones whose name does not start with `_`) and only the headers of the allowlist (`request_guard.context_headers`,
 * already validated by the configuration). Never the cookies, query string, body or session. Stateless.
 *
 * @internal
 */
final class RbacRequestFactory
{
    /**
     * @param list<string> $headerAllowlist lower-case header names to copy
     */
    public static function fromRequest(Request $request, array $headerAllowlist = []): RbacRequest
    {
        $route       = $request->attributes->get('_route');
        $routeParams = $request->attributes->get('_route_params');

        $headers = [];
        foreach ($headerAllowlist as $name) {
            $value = $request->headers->get($name);
            if (is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return new RbacRequest(
            $request->getMethod(),
            $request->getPathInfo(),
            is_string($route) ? $route : null,
            $request->getScheme(),
            $request->getHost(),
            is_array($routeParams) ? $routeParams : [],
            $request->attributes->all(),
            $headers,
        );
    }
}
