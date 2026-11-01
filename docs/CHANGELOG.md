# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Permission catalogue from `catalogue.permissions` and tagged `PermissionProviderInterface` services: key, scope
  (`global` / `context`), guarded routes, group, labels, area aliases, open routes; validated (no redefinition, no route
  guarded twice, aliases to known keys, `max_permissions`); memoised per request.
- Permission sets (roles) from `permission_sets` (`all` / `except`, `locked`) and stored edits / custom sets
  (`storage.type`: `config`, `doctrine` with the `rbac_permission_set` table and `doctrine.table_prefix`, or `service`),
  cached in `storage.cache_pool`; uncatalogued keys grant nothing.
- Context abstraction (`RbacContextInterface`, `ContextResolverInterface`) and `GrantProviderInterface` (assignments
  per user, global or per context), resolved once per user and request by `GrantResolver` (union of the sets held).
- `PermissionVoter` (`permission:<key>`, `permission_route:<route>`): fail closed (empty catalogue, unknown key,
  unmapped route, guest, context key without context), deny-first `PermissionGateInterface`, `voter.bypass_roles` with
  `BypassVetoInterface`, `role_grants`, `voter.global_aggregation`, `voter.fallback_permission_set`; memoised per
  request, `ResetInterface`, bounded.
- `PermissionChecker` (`can`, `canRoute`, `canArea`, `filter`) and Twig `rbac_can()`, `rbac_can_route()`,
  `rbac_can_area()`.
- Opt-in request guard (`request_guard`) refusing catalogued routes before the controller, with
  `RequestContextResolverInterface` and `skip_strangers`.
- DashboardMenu permission checker (`RbacMenuPermissionChecker`) when nowo-tech/dashboard-menu-bundle is installed.
- `nowo:rbac:check` and `PermissionCoverage`: unguarded routes under prefixes, missing catalogue routes, uncatalogued
  set keys, empty catalogue.
- Admin UI (`web_ui.enabled`, opt-in routes): permission sets list, catalogue, create / edit / delete on the UiKit page
  contract with a FormKit type and profile `rbac_kit`, CSRF token ids bound to action and set
  (`nowo_rbac/<action>/<target>`), `PermissionSetChangedEvent` after every mutation, `PermissionSetUsageInterface`
  (assignment counts; deletion of a set in use refused), REQ-UI-002 access (view / manage roles, custom checker,
  debug-only `allow_unauthenticated`).
- Translations in en, es, fr, de, it, pt and nl.
- Symfony 8.1 FrankenPHP demo (MySQL, worker mode, loopback port 8096) with Playwright e2e and WebP screenshots.

### Security

- Safe defaults: an empty catalogue denies every permission; no bypass role, no fallback set, no grant provider; the
  admin UI and the request guard are off; empty admin role lists and non-literal security switches are refused.
