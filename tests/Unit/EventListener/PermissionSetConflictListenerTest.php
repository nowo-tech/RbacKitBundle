<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\RbacKitBundle\Controller\PermissionSetController;
use Nowo\RbacKitBundle\EventListener\PermissionSetConflictListener;
use Nowo\RbacKitBundle\Tests\Fixtures\Controller\HostController;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * 1.3 (REQ-RUNTIME-003 rule 6): lock conflicts on the bundle's pages answer 409 + X-Conflict-Code; nothing else is
 * mapped.
 */
final class PermissionSetConflictListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function lockConflicts(): iterable
    {
        yield 'MySQL deadlock 1213' => [new DeadlockException(self::driver('Deadlock found', '40001', 1213), null)];
        yield 'MySQL lock wait timeout 1205' => [new LockWaitTimeoutException(self::driver('Lock wait timeout exceeded', 'HY000', 1205), null)];
        yield 'MariaDB 1020' => [new RuntimeException('wrapped', 0, new DeadlockException(self::driver('Record has changed since last read', 'HY000', 1020), null))];
        yield 'PostgreSQL serialization failure 40001' => [new DeadlockException(self::driver('could not serialize access', '40001', 7), null)];
        yield 'PostgreSQL deadlock 40P01' => [new DeadlockException(self::driver('deadlock detected', '40P01', 7), null)];
        yield 'PostgreSQL lock_timeout 55P03' => [new LockWaitTimeoutException(self::driver('canceling statement due to lock timeout', '55P03', 7), null)];
        yield 'runner TransientStorageFailure' => [TransientStorageFailure::fromPrevious(new RuntimeException('deadlock'))];
    }

    #[Test]
    #[DataProvider('lockConflicts')]
    public function aLockConflictWithNothingCommittedAnswersConflict001(Throwable $failure): void
    {
        $event = $this->event($failure);
        (new PermissionSetConflictListener())->onKernelException($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $answer->getHeaders());
        self::assertSame(PermissionSetConflictListener::RETRY_SAFE_MESSAGE, $answer->getMessage());
        self::assertSame($failure, $answer->getPrevious(), 'the storage error stays for the logs');
    }

    #[Test]
    public function aCommitEarlierInTheRequestAnswersConflict002WithoutRetryAfter(): void
    {
        $ledger  = new CommitLedger();
        $request = $this->request(PermissionSetController::class . '::edit');
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();
        $event = $this->event(new DeadlockException(self::driver('Deadlock found', '40001', 1213), null), $request);

        (new PermissionSetConflictListener())->onKernelException($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $answer->getHeaders());
        self::assertSame(PermissionSetConflictListener::COMMITTED_MESSAGE, $answer->getMessage());
    }

    #[Test]
    public function aMarkWithoutCommitsStaysConflict001(): void
    {
        $request = $this->request(PermissionSetController::class . '::edit');
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark(new CommitLedger()));
        $event = $this->event(new DeadlockException(self::driver('Deadlock found', '40001', 1213), null), $request);

        (new PermissionSetConflictListener())->onKernelException($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame('CONFLICT_001', $answer->getHeaders()['X-Conflict-Code'] ?? null);
    }

    #[Test]
    public function theHostPolicyAndTheFailureHintAreUsed(): void
    {
        $event = $this->event(TransientStorageFailure::fromPrevious(new RuntimeException('deadlock'), 7));
        (new PermissionSetConflictListener(new ConflictResponsePolicy(503, 3)))->onKernelException($event);

        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(503, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '7'], $answer->getHeaders());

        $event = $this->event(TransientStorageFailure::fromPrevious(new RuntimeException('deadlock'), 0));
        (new PermissionSetConflictListener(new ConflictResponsePolicy(503, 3)))->onKernelException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame('3', $answer->getHeaders()['Retry-After'] ?? null, 'a zero hint falls back to the policy');
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function notConflicts(): iterable
    {
        yield 'unique violation' => [new UniqueConstraintViolationException(self::driver('Duplicate entry', '23000', 1062), null)];
        yield 'lost connection' => [new ConnectionLost(self::driver('MySQL server has gone away', 'HY000', 2006), null)];
        yield 'domain error' => [new RuntimeException('boom')];
    }

    #[Test]
    #[DataProvider('notConflicts')]
    public function otherFailuresAreNeverMapped(Throwable $failure): void
    {
        $event = $this->event($failure);
        (new PermissionSetConflictListener())->onKernelException($event);

        self::assertSame($failure, $event->getThrowable());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function hostControllers(): iterable
    {
        yield 'host string controller' => [HostController::class . '::home'];
        yield 'host array controller' => [[new HostController(), 'home']];
        yield 'closure' => [static fn (): null => null];
        yield 'none' => [null];
    }

    #[Test]
    #[DataProvider('hostControllers')]
    public function hostRoutesKeepTheirOwnHandling(mixed $controller): void
    {
        $failure = new DeadlockException(self::driver('Deadlock found', '40001', 1213), null);
        $request = new Request();
        $request->attributes->set('_controller', $controller);
        $event = $this->event($failure, $request);

        (new PermissionSetConflictListener())->onKernelException($event);

        self::assertSame($failure, $event->getThrowable());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function bundleControllers(): iterable
    {
        yield 'string' => ['\\' . PermissionSetController::class . '::new'];
        yield 'array of class' => [[PermissionSetController::class, 'new']];
        yield 'array of object' => [[(new ReflectionClass(PermissionSetController::class))->newInstanceWithoutConstructor(), 'new']];
    }

    #[Test]
    #[DataProvider('bundleControllers')]
    public function everyFormOfABundleControllerIsRecognised(mixed $controller): void
    {
        $request = new Request();
        $request->attributes->set('_controller', $controller);
        $event = $this->event(new DeadlockException(self::driver('Deadlock found', '40001', 1213), null), $request);

        (new PermissionSetConflictListener())->onKernelException($event);

        self::assertInstanceOf(HttpException::class, $event->getThrowable());
    }

    private static function driver(string $message, string $sqlState, int $code): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }

    private function request(string $controller): Request
    {
        $request = new Request();
        $request->attributes->set('_controller', $controller);

        return $request;
    }

    private function event(Throwable $failure, ?Request $request = null): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request ?? $this->request(PermissionSetController::class . '::new'), HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
