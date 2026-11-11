<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\EventListener;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

use function array_values;

/**
 * Clears the authorization memos (permission catalogue, permission-set registry, grant resolver, voter decisions) at
 * every request, message and command boundary (1.1, LH-RBAC-M1: FrankenPHP worker mode without kernel reset).
 *
 * Before 1.1 they were cleared only by `kernel.reset`: a kept-alive worker without the services resetter kept granting
 * a revoked permission, or a user's old assignments, until it restarted. The bundle now clears them itself: at the
 * start of every main request and Messenger message, and at the end of every request, message and command.
 *
 * @internal
 */
final readonly class RbacMemoResetListener
{
    /**
     * Boundaries: [event, method, priority] (symfony/messenger is optional: event names as strings). Before the
     * firewall and the voters at the start, after everything else at the end.
     *
     * @var list<array{string, string, int}>
     */
    public const LISTENERS = [
        [KernelEvents::REQUEST, 'onRequest', 4096],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent', 'clear', 4096],
        [KernelEvents::TERMINATE, 'clear', -1024],
        [ConsoleEvents::TERMINATE, 'clear', -1024],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'clear', -1024],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent', 'clear', -1024],
    ];

    /** @var list<ResetInterface> */
    private array $memos;

    /**
     * @param ResetInterface ...$memos the memoising services, cleared in this order
     */
    public function __construct(ResetInterface ...$memos)
    {
        $this->memos = array_values($memos);
    }

    /**
     * Start of a main request: nothing memoised by an earlier request of this worker is used.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->clear();
        }
    }

    /**
     * Clears every memo.
     */
    public function clear(): void
    {
        foreach ($this->memos as $memo) {
            $memo->reset();
        }
    }
}
