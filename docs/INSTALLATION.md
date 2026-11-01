# Installation

## Table of contents

- [Requirements](#requirements)
- [Install with Composer](#install-with-composer)
- [Register the bundles](#register-the-bundles)
- [Catalogue, sets and grants](#catalogue-sets-and-grants)
- [Permission set table (Doctrine storage)](#permission-set-table-doctrine-storage)
- [Admin routes and security](#admin-routes-and-security)
- [Twig Extra, UiKit assets and FormKit](#twig-extra-uikit-assets-and-formkit)
- [Flex recipe](#flex-recipe)

## Requirements

- PHP 8.2–8.5, Symfony 7.4 / 8.0 / 8.1 (Symfony 8 needs PHP 8.4+).
- `symfony/security-bundle` in the application (the voter answers through Symfony Security; the admin UI and the
  request guard require it).
- [UiKitBundle](https://github.com/nowo-tech/UiKitBundle) and [FormKitBundle](https://github.com/nowo-tech/FormKitBundle)
  (dependencies, used by the admin UI).
- Optional: Doctrine ORM (`doctrine/doctrine-bundle`, `doctrine/orm`) for `storage.type: doctrine`;
  nowo-tech/dashboard-menu-bundle for the menu checker.

## Install with Composer

```bash
composer require nowo-tech/rbac-kit-bundle
composer require doctrine/doctrine-bundle doctrine/orm   # editable permission sets (storage.type: doctrine)
```

## Register the bundles

With Flex the recipe registers the bundle. Otherwise, in `config/bundles.php`:

```php
return [
    // ...
    Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
    Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
    Nowo\RbacKitBundle\NowoRbacKitBundle::class => ['all' => true],
];
```

`Twig\Extra\TwigExtraBundle\TwigExtraBundle` must be registered too (REQ-TWIG-004).

## Catalogue, sets and grants

Nothing is granted until you configure it (an empty catalogue denies every permission):

1. Catalogue the keys (`catalogue.permissions`) — or contribute them from `PermissionProviderInterface` services.
2. Define the system sets (`permission_sets`).
3. Implement `GrantProviderInterface` (the sets each user holds per context) and set `grant_provider`.
4. Make your context entities implement `RbacContextInterface` (or set `context_resolver`).
5. Run `php bin/console nowo:rbac:check --prefix=/your/prefix` and fix what it reports.

See [CONFIGURATION.md](CONFIGURATION.md) and [USAGE.md](USAGE.md).

## Permission set table (Doctrine storage)

With `storage.type: doctrine` the bundle maps `Nowo\RbacKitBundle\Entity\PermissionSetRecord` (table
`rbac_permission_set` + `doctrine.table_prefix`, unique `code`, `permissions` JSON, `is_system`, `updated_by`,
`updated_at`). Create it with a migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Admin routes and security

The admin routes are opt-in (REQ-SF-004 rule 7: the controllers are not autoconfigured, so `routing.controllers` never
exposes them). Enable the UI and import the routes:

```yaml
# config/packages/nowo_rbac_kit.yaml
nowo_rbac_kit:
    storage: { type: doctrine }
    web_ui: { enabled: true }

# config/routes/nowo_rbac_kit.yaml
nowo_rbac_kit:
    resource: .
    type: nowo_rbac_kit
```

Protect the prefix in the host firewall (layer 1 of REQ-UI-002; the bundle checks `security.access_roles` again):

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/rbac, roles: ROLE_ADMIN }
```

## Twig Extra, UiKit assets and FormKit

The pages render with UiKit scaffolds and FormKit forms. Run `php bin/console assets:install` once (UiKit CSS / JS) and
point `nowo_ui_kit.layouts.admin` at the host admin layout (see [TEMPLATES.md](TEMPLATES.md)). `twig/extra-bundle` and
`twig/string-extra` are required by the package and must be enabled in the host (REQ-TWIG-004).

## Flex recipe

The recipe lives in `.symfony/recipe/nowo-tech/rbac-kit-bundle/1.0/` (REQ-RECIPE-001): it registers the bundle and
copies `config/packages/nowo_rbac_kit.yaml` (safe defaults) and `config/routes/nowo_rbac_kit.yaml`. It ships **no
security file**: a recipe file defining `security.access_control` would replace the host's own list (Symfony does not
merge that prototype across files). Its `post-install.txt` prints the next steps (catalogue, grant provider, migration,
`access_control` line).
