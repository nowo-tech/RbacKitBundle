<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

/**
 * Host service (`request_guard.context_resolver`): the context a request is about (the project of
 * `/projects/{projectId}/issues/{id}`), for the request guard. Framework-free twin of the deprecated
 * {@see RequestContextResolverInterface} (since 1.2, REQ-ARCH-001 rule 3).
 *
 * When the configured service implements both interfaces, the guard calls this one. Return the subject the voter is
 * asked with (an object your `context_resolver` maps to a typed context id), or null when the request has no context:
 * a context route is then refused (or left to the controller with `skip_strangers`). Stateless: memoise a lookup per
 * request on your own request-scoped state, never in a service property (FrankenPHP worker mode, kernel reset false).
 */
interface RbacRequestContextResolverInterface
{
    /**
     * The context subject of the request, or null.
     */
    public function contextOf(RbacRequest $request): ?object;
}
