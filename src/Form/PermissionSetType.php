<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\RbacKitBundle\NowoRbacKitBundle;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

use function array_combine;
use function array_keys;

/**
 * A permission set (POST + CSRF): code (create only), name, description and its permissions (checkboxes in catalogue
 * order, labels in the catalogue translation domain). Data: `array{code?: string, name: string, description:
 * string|null, permissions: list<string>}`. The controller passes the catalogue (`permission_labels`) and a CSRF
 * token id bound to the action and the set (`nowo_rbac/create/new`, `nowo_rbac/edit/<code>`).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig(NowoRbacKitBundle::FORM_KIT_PROFILE)]
final class PermissionSetType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Code (with `with_code`), name, description, permissions.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $labels */
        $labels = $options['permission_labels'];
        if ($options['with_code'] === true) {
            $this->addWithDefaults($builder, 'code', TextType::class, [
                'label'       => 'form.code',
                'help'        => 'form.code_help',
                'constraints' => [new NotBlank(), new Length(max: 40), new Regex(PermissionSetManager::CODE_PATTERN)],
                'attr'        => ['autocomplete' => 'off', 'maxlength' => 40, 'pattern' => '[a-z][a-z0-9_]{1,39}', 'data-testid' => 'rbac-code'],
            ]);
        }
        $this->addWithDefaults($builder, 'name', TextType::class, [
            'label'       => 'form.name',
            'constraints' => [new NotBlank(), new Length(max: PermissionSetManager::NAME_MAX_LENGTH)],
            'attr'        => ['maxlength' => PermissionSetManager::NAME_MAX_LENGTH, 'data-testid' => 'rbac-name'],
        ]);
        $this->addWithDefaults($builder, 'description', TextareaType::class, [
            'label'       => 'form.description',
            'required'    => false,
            'constraints' => [new Length(max: PermissionSetManager::DESCRIPTION_MAX_LENGTH)],
            'attr'        => ['rows' => 2, 'maxlength' => PermissionSetManager::DESCRIPTION_MAX_LENGTH],
        ]);
        $this->addWithDefaults($builder, 'permissions', ChoiceType::class, [
            'label'                     => 'form.permissions',
            'help'                      => 'form.permissions_help',
            'required'                  => false,
            'multiple'                  => true,
            'expanded'                  => true,
            'choices'                   => array_combine(array_keys($labels), array_keys($labels)),
            'choice_label'              => static fn (string $key): string => $labels[$key] ?? $key,
            'choice_translation_domain' => $options['permission_translation_domain'],
        ]);
    }

    /**
     * POST with CSRF, bundle translation domain; `permission_labels` (key => label translation key) and
     * `permission_translation_domain` describe the catalogue.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'                    => null,
            'csrf_protection'               => true,
            'csrf_field_name'               => '_token',
            'translation_domain'            => NowoRbacKitBundle::TRANSLATION_DOMAIN,
            'with_code'                     => false,
            'permission_labels'             => [],
            'permission_translation_domain' => 'messages',
        ]);
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('with_code', 'bool');
        $resolver->setAllowedTypes('permission_labels', 'array');
        $resolver->setAllowedTypes('permission_translation_domain', 'string');
    }

    /**
     * `nowo_rbac_permission_set`.
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_rbac_permission_set';
    }
}
