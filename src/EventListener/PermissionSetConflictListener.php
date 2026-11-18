<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\EventListener;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_array;
use function is_object;
use function is_string;
use function ltrim;
use function str_starts_with;

/**
 * 1.3 (REQ-RUNTIME-003 rule 6): a lock conflict on a page of the bundle's admin UI answers a conflict instead of a 500.
 *
 * A deadlock, lock wait timeout or serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 /
 * 55P03), anywhere down the chain or wrapped in the contract's {@see TransientStorageFailure}, or a lost savepoint
 * whose commit outcome is unknown, becomes an HTTP exception with the {@see ConflictResponsePolicy} status (**409** by
 * default, 503 when the host configures `conflict_response.status`) rendered by the application's error page:
 *
 * - `X-Conflict-Code: CONFLICT_001` and `Retry-After` when nothing of the request was committed;
 * - `X-Conflict-Code: CONFLICT_002` and no `Retry-After` when part of it may be committed: anything committed since
 *   the request started (transaction-doctrine's request watermark, {@see ConflictCode::forRequest()}), or a commit
 *   outcome the failure leaves unknown.
 *
 * Unique violations and lost connections are not conflicts: they are never mapped (the storage answers a duplicate
 * code as a domain result itself). The message is fixed; the storage error stays the previous exception for the logs,
 * so no SQL or value reaches the client. Only the bundle's controllers: host routes keep the host's own handling.
 * Stateless (FrankenPHP worker mode, kernel reset off).
 */
final readonly class PermissionSetConflictListener
{
    /** kernel.exception priority: after the application's own listeners, before the framework's error listener (-128). */
    public const PRIORITY = -64;

    /** Message of a CONFLICT_001 answer. */
    public const RETRY_SAFE_MESSAGE = 'The permission sets are busy. Retry in a moment.';

    /** Message of a CONFLICT_002 answer. */
    public const COMMITTED_MESSAGE = 'Part of the change may already be saved. Reload before trying again.';

    private ConflictResponsePolicy $policy;

    private TransientFailureClassifier $classifier;

    /**
     * @param ConflictResponsePolicy|null $policy status and default Retry-After (null = 409, 1 s)
     */
    public function __construct(?ConflictResponsePolicy $policy = null)
    {
        $this->policy     = $policy ?? new ConflictResponsePolicy();
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * Replaces a lock conflict of a bundle page by the conflict answer.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $failure = $event->getThrowable();
        if (!$this->isBundleController($request->attributes->get('_controller')) || !$this->isLockConflict($failure)) {
            return;
        }

        $code = ConflictCode::forRequest($request, $failure);
        $event->setThrowable($this->policy->httpExceptionFor($code, $code->retrySafe() ? self::RETRY_SAFE_MESSAGE : self::COMMITTED_MESSAGE, $failure, $this->hint($failure)));
    }

    private function isLockConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return true;
            }
        }

        return $this->classifier->isTransient($failure) || $this->classifier->isCommitOutcomeUnknown($failure);
    }

    /**
     * The first positive Retry-After hint of the chain, or null (the policy's value).
     */
    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                $hint = $current->retryAfterSeconds();

                return $hint !== null && $hint > 0 ? $hint : null;
            }
        }

        return null;
    }

    private function isBundleController(mixed $controller): bool
    {
        if (is_array($controller)) {
            $controller = $controller[0] ?? null;
        }
        if (is_object($controller)) {
            $controller = $controller::class;
        }

        return is_string($controller) && str_starts_with(ltrim($controller, '\\'), RbacAdminAccessListener::CONTROLLER_NAMESPACE);
    }
}
