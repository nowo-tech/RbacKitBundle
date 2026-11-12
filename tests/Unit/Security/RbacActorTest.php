<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Security;

use LogicException;
use Nowo\RbacKitBundle\Security\RbacActor;
use Nowo\RbacKitBundle\Security\RbacActorFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
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

final class RbacActorTest extends TestCase
{
    /** Stands for a password hash on the user object (not a real one). */
    private const HASH = 'hash-placeholder-5d1e';

    /** Stands for a credential a host keeps as a token attribute (not a real one). */
    private const ATTRIBUTE = 'attribute-placeholder-c47b';

    #[Test]
    public function theFactoryMapsTheToken(): void
    {
        $user  = new InMemoryUser('root', self::HASH, ['ROLE_ADMIN']);
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_ADMIN']);
        $token->setAttribute('api_credential', self::ATTRIBUTE);
        $token->setAttribute('view_as_member', null);

        $actor = RbacActorFactory::fromToken($token, $user, ['ROLE_ADMIN', 'ROLE_SUPER']);

        self::assertSame('root', $actor->userIdentifier);
        self::assertSame(['ROLE_ADMIN'], $actor->roles);
        self::assertSame(['ROLE_ADMIN', 'ROLE_SUPER'], $actor->reachableRoles);
        self::assertTrue($actor->hasRole('ROLE_SUPER'));
        self::assertFalse($actor->hasRole('ROLE_USER'));
        self::assertSame('main', $actor->firewallName);
        self::assertNull($actor->impersonatorIdentifier);
        self::assertFalse($actor->isImpersonated());
        self::assertSame($user, $actor->user());
        self::assertSame(self::ATTRIBUTE, $actor->tokenAttribute('api_credential'));
        self::assertTrue($actor->hasTokenAttribute('view_as_member'), 'a null attribute is kept');
        self::assertNull($actor->tokenAttribute('view_as_member'));
        self::assertFalse($actor->hasTokenAttribute('missing'));
    }

    #[Test]
    public function aSwitchUserTokenNamesTheImpersonator(): void
    {
        $user     = new InMemoryUser('alice', null, ['ROLE_USER']);
        $original = new UsernamePasswordToken(new InMemoryUser('root', null, ['ROLE_SUPER']), 'main', ['ROLE_SUPER']);
        $actor    = RbacActorFactory::fromToken(new SwitchUserToken($user, 'main', ['ROLE_USER'], $original), $user, ['ROLE_USER']);

        self::assertSame('alice', $actor->userIdentifier);
        self::assertSame('root', $actor->impersonatorIdentifier);
        self::assertTrue($actor->isImpersonated());
    }

    #[Test]
    public function aTokenWithoutAFirewallNameHasNone(): void
    {
        $user  = new InMemoryUser('svc', null, ['ROLE_USER']);
        $token = new class($user) extends AbstractToken {
            public function __construct(InMemoryUser $user)
            {
                parent::__construct(['ROLE_USER']);
                $this->setUser($user);
            }
        };
        self::assertNull(RbacActorFactory::fromToken($token, $user, ['ROLE_USER'])->firewallName);
    }

    #[Test]
    public function noDumpExportCastOrSerializationShowsTheUserOrTheTokenAttributes(): void
    {
        $actor = new RbacActor('root', ['ROLE_ADMIN'], ['ROLE_ADMIN'], new InMemoryUser('root', self::HASH), ['api_credential' => self::ATTRIBUTE]);

        ob_start();
        var_dump($actor);
        print_r($actor);
        $dumped = (string) ob_get_clean();
        $dumped .= (string) (new CliDumper())->dump((new VarCloner())->cloneVar($actor), true);
        $dumped .= var_export($actor, true) . json_encode($actor) . serialize($actor) . json_encode((array) $actor);

        self::assertStringNotContainsString(self::HASH, $dumped);
        self::assertStringNotContainsString(self::ATTRIBUTE, $dumped);
        self::assertStringContainsString('ROLE_ADMIN', $dumped, 'the public properties are shown');
    }

    #[Test]
    public function copiesCarryNoUser(): void
    {
        $actor = new RbacActor('root', [1 => 'ROLE_ADMIN'], [], new InMemoryUser('root', null));
        self::assertSame(['ROLE_ADMIN'], $actor->roles, 'a list');
        $copy = unserialize(serialize($actor));
        self::assertInstanceOf(RbacActor::class, $copy);
        self::assertNull($copy->tokenAttribute('x'));
        self::assertSame('root', $copy->userIdentifier);

        $this->expectException(LogicException::class);
        $copy->user();
    }
}
