<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Context;

use Nowo\RbacKitBundle\Context\RbacRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

use function json_encode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function serialize;
use function unserialize;
use function var_dump;
use function var_export;

final class RbacRequestTest extends TestCase
{
    /** A value standing for a share-link token in a route parameter (not a real credential). */
    private const SHARE = 'share-route-value-7f3a';

    /** A value standing for a tenant header (copied only when allowlisted). */
    private const TENANT = 'tenant-header-value-91c2';

    #[Test]
    public function itKeepsOnlyScalarsWithStringNames(): void
    {
        $request = $this->request();

        self::assertSame('GET', $request->method);
        self::assertSame('/projects/billing', $request->path);
        self::assertSame('project_show', $request->routeName);
        self::assertSame('https', $request->scheme);
        self::assertSame('app.example.test', $request->host);

        self::assertSame('billing', $request->routeParam('id'));
        self::assertSame(3, $request->routeParam('page'));
        self::assertNull($request->routeParam('object'), 'objects are not copied');
        self::assertFalse($request->hasRouteParam('object'));
        self::assertTrue($request->hasRouteParam('optional'), 'a null parameter is kept');
        self::assertNull($request->routeParam('optional'));
        self::assertSame(['id' => 'billing', 'page' => 3, 'share' => self::SHARE, 'optional' => null, 'flag' => true, 'ratio' => 1.5], $request->routeParams());

        self::assertSame('billing', $request->attribute('id'));
        self::assertTrue($request->hasAttribute('id'));
        self::assertNull($request->attribute('_route'), 'internal attributes are not copied');
        self::assertFalse($request->hasAttribute('_controller'));
        self::assertNull($request->attribute('user'), 'objects are not copied');
        self::assertNull($request->attribute('list'), 'arrays are not copied');

        self::assertSame(self::TENANT, $request->header('X-Tenant'));
        self::assertSame('en', $request->header('accept-language'), 'the first value of a list');
        self::assertNull($request->header('x-empty'), 'a list without a string');
        self::assertNull($request->header('x-missing'));
    }

    #[Test]
    public function noDumpExportCastOrSerializationShowsTheHiddenValues(): void
    {
        $request = $this->request();

        ob_start();
        var_dump($request);
        print_r($request);
        $dumped = (string) ob_get_clean();
        $dumper = new CliDumper();
        $dumped .= (string) $dumper->dump((new VarCloner())->cloneVar($request), true);
        $dumped .= var_export($request, true) . json_encode($request) . serialize($request) . json_encode((array) $request);

        self::assertStringNotContainsString(self::SHARE, $dumped);
        self::assertStringNotContainsString(self::TENANT, $dumped);
        self::assertStringContainsString('project_show', $dumped, 'the public properties are shown');
    }

    #[Test]
    public function copiesCarryNoHiddenValues(): void
    {
        $request = $this->request();
        $clone   = clone $request;
        $copy    = unserialize(serialize($request));
        self::assertInstanceOf(RbacRequest::class, $copy);

        foreach ([$clone, $copy] as $other) {
            self::assertSame('/projects/billing', $other->path);
            self::assertNull($other->routeParam('id'));
            self::assertSame([], $other->routeParams());
            self::assertNull($other->attribute('id'));
            self::assertNull($other->header('x-tenant'));
        }
        self::assertSame('billing', $request->routeParam('id'), 'the original keeps them');
    }

    #[Test]
    public function twoRequestsNeverShareValues(): void
    {
        $alice = new RbacRequest('GET', '/projects/a', 'project_show', routeParams: ['id' => 'a']);
        $bob   = new RbacRequest('GET', '/projects/b', 'project_show', routeParams: ['id' => 'b']);

        self::assertSame('a', $alice->routeParam('id'));
        self::assertSame('b', $bob->routeParam('id'));
        self::assertSame('', (new RbacRequest('GET', '/'))->host);
    }

    private function request(): RbacRequest
    {
        return new RbacRequest(
            'GET',
            '/projects/billing',
            'project_show',
            'https',
            'app.example.test',
            ['id' => 'billing', 'page' => 3, 'share' => self::SHARE, 'object' => new stdClass(), 'optional' => null, 'flag' => true, 'ratio' => 1.5, 0 => 'positional'],
            ['id' => 'billing', '_route' => 'project_show', '_controller' => 'x', 'user' => new stdClass(), 'list' => ['a']],
            ['X-Tenant' => self::TENANT, 'accept-language' => ['en', 'fr'], 'x-empty' => [], 0 => 'no-name'],
        );
    }
}
