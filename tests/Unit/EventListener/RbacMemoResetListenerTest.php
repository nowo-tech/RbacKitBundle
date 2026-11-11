<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\EventListener;

use Nowo\RbacKitBundle\EventListener\RbacMemoResetListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

#[CoversClass(RbacMemoResetListener::class)]
final class RbacMemoResetListenerTest extends TestCase
{
    #[Test]
    public function clearsEveryMemoAtTheMainRequestOnly(): void
    {
        $memo = $this->createMock(ResetInterface::class);
        $memo->expects(self::exactly(2))->method('reset');
        $listener = new RbacMemoResetListener($memo);
        $kernel   = $this->createStub(HttpKernelInterface::class);

        $listener->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        $listener->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        $listener->clear();
    }

    #[Test]
    public function listensToEveryBoundary(): void
    {
        $events = array_column(RbacMemoResetListener::LISTENERS, 0);
        self::assertContains(KernelEvents::REQUEST, $events);
        self::assertContains(KernelEvents::TERMINATE, $events);
        self::assertContains('Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', $events);
        self::assertCount(6, $events);
    }
}
