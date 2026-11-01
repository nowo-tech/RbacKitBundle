<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures;

use Nowo\RbacKitBundle\Event\PermissionSetChangedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Records the dispatched events (unit tests) and listens to the audit event (kernel tests).
 */
final class EventRecorder implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    public function onChanged(PermissionSetChangedEvent $event): void
    {
        $this->events[] = $event;
    }
}
