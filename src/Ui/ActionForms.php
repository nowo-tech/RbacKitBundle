<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Ui;

use InvalidArgumentException;
use Nowo\FormKitBundle\Form\Type\CsrfOnlyType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function hash;
use function sprintf;
use function str_contains;
use function substr;

/**
 * CSRF token ids and CSRF-only forms (FormKit {@see CsrfOnlyType}) of the admin actions, bound to one action and one
 * target: `nowo_rbac/<action>/<target>`. The separator `/` is outside the alphabet of actions and set codes
 * (`^[a-z][a-z0-9_]{1,39}$`), so two different targets never share a token id and a token rendered for "delete
 * auditor" is refused by "delete viewer" or by "edit auditor". Stateless.
 */
final readonly class ActionForms
{
    /** Prefix of every token id. */
    public const TOKEN_PREFIX = 'nowo_rbac';

    /** Separator of the token id parts. */
    public const SEPARATOR = '/';

    /** Action of the create form (target {@see self::NEW_TARGET}). */
    public const CREATE = 'create';

    /** Action of an edit form. */
    public const EDIT = 'edit';

    /** Action of a delete button. */
    public const DELETE = 'delete';

    /** Target of the create form. */
    public const NEW_TARGET = 'new';

    /**
     * @param FormFactoryInterface $formFactory Symfony form factory
     * @param UrlGeneratorInterface $urls route URLs (form action)
     */
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Token id of an action on a target; a part containing the separator is refused.
     */
    public static function tokenId(string $action, string $target): string
    {
        foreach ([$action, $target] as $part) {
            if ($part === '' || str_contains($part, self::SEPARATOR)) {
                throw new InvalidArgumentException(sprintf('RbacKit CSRF token id part "%s" must be non-empty and must not contain "%s".', $part, self::SEPARATOR));
            }
        }

        return self::TOKEN_PREFIX . self::SEPARATOR . $action . self::SEPARATOR . $target;
    }

    /**
     * Form name of an action on a target (unique per token id, so a page can render many action forms).
     */
    public static function formName(string $action, string $target): string
    {
        return 'rbac_' . substr(hash('xxh128', self::tokenId($action, $target)), 0, 16);
    }

    /**
     * The POST delete form of a set (action URL generated from the route).
     *
     * @return FormInterface<mixed>
     */
    public function delete(string $route, string $code): FormInterface
    {
        return $this->formFactory->createNamed(self::formName(self::DELETE, $code), CsrfOnlyType::class, null, [
            'action'          => $this->urls->generate($route, ['code' => $code]),
            'method'          => 'POST',
            'csrf_token_id'   => self::tokenId(self::DELETE, $code),
            'csrf_field_name' => '_token',
        ]);
    }
}
