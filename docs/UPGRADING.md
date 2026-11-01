# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.0.0](#to-100)
  - [Requirements](#requirements)
  - [Adopting the kit from an in-app permission layer](#adopting-the-kit-from-an-in-app-permission-layer)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

## To 1.0.0

First release.

### Requirements

PHP 8.2+, Symfony 7.4 / 8.0 / 8.1, `symfony/security-bundle` in the application, UiKit ^2.21, FormKit ^2.17; Doctrine
ORM 3.3+ for `storage.type: doctrine`.

### Adopting the kit from an in-app permission layer

Applications that already have a permission catalogue, role tables and a voter (the layer this kit was extracted
from):

1. **Catalogue**: move the key list (keys, scope, guarded routes, area aliases) to `catalogue.permissions` /
   `catalogue.aliases`, or keep it in PHP behind a `PermissionProviderInterface` service. Always-open routes go to
   `catalogue.open_routes`.
2. **Roles**: the seeded roles become `permission_sets` (an "all minus explicit keys" role is `all: true, except: […]`;
   an owner role that must never change is `locked: true`). Platform-role grants become `role_grants`.
3. **Stored roles**: with `storage.type: doctrine` the kit uses its own table `rbac_permission_set` (code, name,
   description, `is_system`, permissions JSON, `updated_by` identifier, `updated_at`). Copy the rows of your role
   table with a migration (`INSERT … SELECT`), or implement `PermissionSetStorageInterface` over your table
   (`storage.type: service`).
4. **Memberships**: implement `GrantProviderInterface` over your membership query (one query per user and request);
   your context entity implements `RbacContextInterface` (or set `context_resolver`).
5. **Voter**: keep the attributes `permission:<key>` and `permission_route:<route>` (same prefixes); delete your voter,
   resolver and catalogue classes. A superuser bypass becomes `voter.bypass_roles`; a "view as member" switch becomes a
   `BypassVetoInterface`; a plan / module gate becomes a `PermissionGateInterface`; a new-account fallback becomes
   `voter.fallback_permission_set`.
6. **Request listener**: enable `request_guard` with your `RequestContextResolverInterface`.
7. **Menus**: replace a custom DashboardMenu checker with `RbacMenuPermissionChecker`.
8. **Coverage test**: replace it with `PermissionCoverage::report()` or `nowo:rbac:check`.
9. **Twig**: `can()` calls become `rbac_can()` (or register your own alias function calling `PermissionChecker`).
10. **Admin**: the kit's admin UI (`web_ui.enabled`) replaces an in-app role administration page; map your audit log
    to `PermissionSetChangedEvent`.

Behaviour differences to check: an empty catalogue denies everything; `permission_route:` on an unmapped route is
denied unless the route is open or `unmapped_routes: allow`; a context key is always refused without its context
subject; role grants apply to global keys only; no role bypasses the catalogue unless `voter.bypass_roles` lists it.

### Template contract

New: `admin/index.html.twig`, `admin/catalogue.html.twig`, `admin/form.html.twig`, `admin/_cells.html.twig`, page ids
`index`, `catalogue`, `form`, `data-testid` hooks `rbac-*` ([TEMPLATES.md](TEMPLATES.md)).

### Breaking changes

None (first release).
