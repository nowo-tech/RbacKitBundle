<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\RbacKitBundle\Form\PermissionSetType;
use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\PermissionSet\PermissionSet;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;
use Nowo\RbacKitBundle\Ui\ActionForms;
use Nowo\RbacKitBundle\Ui\RbacPageFactory;
use Nowo\RbacKitBundle\Ui\RbacPages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

use function array_map;
use function http_build_query;

/**
 * Page contexts of the REQ-UI-003 contract test, built with {@see RbacPageFactory} (the service the controller uses)
 * and the real form type. The editor identifier is an obvious placeholder the pages must never print.
 */
final class PageFixtures
{
    /** Identifier of the last editor; never printed by the pages. */
    public const EDITOR = 'jane.doe@example.test';

    /**
     * @return array<string, mixed>
     */
    public static function index(bool $manage = true, bool $writable = true, bool $empty = false): array
    {
        return ['page' => self::factory()->index($empty ? [] : self::sets(), $writable ? ['viewer' => 3] : null, $manage, $writable)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function catalogue(bool $empty = false): array
    {
        return ['page' => self::factory()->catalogue($empty ? [] : Catalogues::catalog()->all(), self::sets())];
    }

    /**
     * @return array<string, mixed>
     */
    public static function form(?string $code = null): array
    {
        $set  = $code === null ? null : self::sets()[$code];
        $form = FormFactories::factory()->createNamed(ActionForms::formName('edit', $code ?? 'new'), PermissionSetType::class, [
            'code'        => '',
            'name'        => $set->name ?? '',
            'description' => $set?->description,
            'permissions' => $set->permissions ?? [],
        ], [
            'action'            => '/form',
            'csrf_token_id'     => ActionForms::tokenId('edit', $code ?? 'new'),
            'with_code'         => $set === null,
            'permission_labels' => array_map(static fn (Permission $permission): string => $permission->labelKey(), Catalogues::catalog()->all()),
        ]);

        return ['page' => self::factory()->form($form->createView(), $set)];
    }

    public static function factory(?RbacPages $pages = null): RbacPageFactory
    {
        return new RbacPageFactory(self::urlGenerator(), new ActionForms(FormFactories::factory(), self::urlGenerator()), $pages ?? new RbacPages());
    }

    /**
     * @return array<string, PermissionSet>
     */
    public static function sets(): array
    {
        $registry = Catalogues::registry(new MemoryStorage([
            new StoredPermissionSet('reporting', 'Reporting', 'Exports', false, ['reports.export', 'project.view'], self::EDITOR, new DateTimeImmutable('2026-10-30')),
            new StoredPermissionSet('triage', 'Triage team', null, true, ['project.issues.triage'], self::EDITOR),
        ]));

        return $registry->all();
    }

    private static function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters));
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
    }
}
