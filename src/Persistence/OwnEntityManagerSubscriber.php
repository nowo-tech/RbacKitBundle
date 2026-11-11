<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps the bundle's OWN entity manager (`doctrine.entity_manager`) usable in a kept-alive kernel (1.1).
 *
 * Registered only when `doctrine.entity_manager` names a manager other than the host default: it maps the bundle entity only, so the bundle
 * may recover it (reset when a failed flush closed it) at the start of the next request or message, and clear it at
 * the end so no managed page survives into the next request. The host's managers are never reset nor cleared.
 *
 * @internal
 */
final readonly class OwnEntityManagerSubscriber
{
    /**
     * Request / message / command boundaries: [event, method, priority] (symfony/messenger is optional: names).
     *
     * @var list<array{string, string, int}>
     */
    public const LISTENERS = [
        [KernelEvents::REQUEST, 'onRequest', 4096],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent', 'recover', 4096],
        [KernelEvents::TERMINATE, 'clear', -1024],
        [ConsoleEvents::TERMINATE, 'clear', -1024],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'clear', -1024],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent', 'clear', -1024],
    ];

    /**
     * @param string $managerName the configured `doctrine.entity_manager`
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $managerName,
    ) {
    }

    /**
     * Recovers the manager at the start of a main request.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->recover();
        }
    }

    /**
     * Resets the bundle manager when a failed write closed it (the lazy manager is re-created in place).
     */
    public function recover(): void
    {
        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->registry->resetManager($this->managerName);
        }
    }

    /**
     * Detaches every managed bundle entity at the end of the request, message or command.
     */
    public function clear(): void
    {
        $manager = $this->registry->getManager($this->managerName);
        if (!$manager instanceof EntityManagerInterface || $manager->isOpen()) {
            // @igor-ignore - This IS the request-boundary reset of the bundle's own manager (no state kept).
            $manager->clear();
        }
    }
}
