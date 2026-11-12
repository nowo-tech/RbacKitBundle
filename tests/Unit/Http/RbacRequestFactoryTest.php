<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Http;

use Nowo\RbacKitBundle\Http\RbacRequestFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RbacRequestFactoryTest extends TestCase
{
    #[Test]
    public function itCopiesTheRouteTheAttributesAndOnlyTheAllowlistedHeaders(): void
    {
        $request = Request::create('http://app.example.test/projects/billing?q=search-term', 'POST', cookies: ['SESS' => 'cookie-value'], server: ['HTTP_X_TENANT' => 'acme', 'HTTP_AUTHORIZATION' => 'Bearer placeholder', 'HTTP_X_OTHER' => 'other']);
        $request->attributes->add(['_route' => 'project_show', '_route_params' => ['id' => 'billing'], 'id' => 'billing', 'resolved' => 'yes']);

        $context = RbacRequestFactory::fromRequest($request, ['x-tenant', 'x-missing']);

        self::assertSame('POST', $context->method);
        self::assertSame('http', $context->scheme);
        self::assertSame('app.example.test', $context->host);
        self::assertSame('/projects/billing', $context->path, 'no query string');
        self::assertSame('project_show', $context->routeName);
        self::assertSame(['id' => 'billing'], $context->routeParams());
        self::assertSame('yes', $context->attribute('resolved'));
        self::assertSame('acme', $context->header('x-tenant'));
        self::assertNull($context->header('x-missing'));
        self::assertNull($context->header('authorization'), 'only allowlisted headers');
        self::assertNull($context->header('x-other'));
        self::assertNull($context->header('cookie'));
    }

    #[Test]
    public function anUnroutedRequestHasNoRoute(): void
    {
        $request = Request::create('/');
        $request->attributes->set('_route_params', 'not-an-array');

        $context = RbacRequestFactory::fromRequest($request);

        self::assertNull($context->routeName);
        self::assertSame([], $context->routeParams());
        self::assertSame('GET', $context->method);
    }
}
