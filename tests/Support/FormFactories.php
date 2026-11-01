<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\FormKitBundle\Form\FormTypeMap;
use Nowo\FormKitBundle\Form\Type\CsrfOnlyType;
use Nowo\RbacKitBundle\DependencyInjection\NowoRbacKitExtension;
use Nowo\RbacKitBundle\Form\PermissionSetType;
use Nowo\RbacKitBundle\NowoRbacKitBundle;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;

/**
 * Form factory with the bundle form type (FormKit merger with the bundle profile) and a CSRF extension.
 */
final class FormFactories
{
    public static function factory(): FormFactoryInterface
    {
        $profile = NowoRbacKitExtension::formKitProfile();
        unset($profile['alias']);
        /** @var array<string, array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>}> $profiles */
        $profiles = [NowoRbacKitBundle::FORM_KIT_PROFILE => $profile + ['by_form' => []]];
        $merger   = new FormOptionsMerger($profiles, NowoRbacKitBundle::FORM_KIT_PROFILE, new ConstraintDefinitionFactory());
        $type     = new PermissionSetType();
        $type->setFormOptionsMerger($merger);

        return Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([$type, new CsrfOnlyType($merger, new FormTypeMap())], []))
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new CsrfExtension(new class implements CsrfTokenManagerInterface {
                public function getToken(string $tokenId): CsrfToken
                {
                    return new CsrfToken($tokenId, 'token-' . $tokenId);
                }

                public function refreshToken(string $tokenId): CsrfToken
                {
                    return $this->getToken($tokenId);
                }

                public function removeToken(string $tokenId): ?string
                {
                    return null;
                }

                public function isTokenValid(CsrfToken $token): bool
                {
                    return true;
                }
            }))
            ->getFormFactory();
    }
}
