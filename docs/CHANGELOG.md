# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Permission catalogue from `catalogue.permissions` and tagged `PermissionProviderInterface` services: key, scope
  (`global` / `context`), guarded routes, group, labels, area aliases, open routes; validated (no redefinition, no route
  guarded twice, aliases to known keys, `max_permissions`); memoised per request.
- Permission sets (roles) from `permission_sets` (`all` / `except` / `include_global`, `locked`) and stored edits /
  custom sets (`storage.type`: `config`, `doctrine` with the `rbac_permission_set` table and `doctrine.table_prefix`,
  or `service`), cached in `storage.cache_pool` for `storage.cache_ttl` seconds; uncatalogued keys grant nothing.
- Typed contexts: `RbacContextInterface` (`getRbacContextType()` + `getRbacContextId()`), `ContextId`
  (`<type>:<id>`), `ContextResolverInterface`, and `GrantProviderInterface` returning `Assignment::global()` /
  `Assignment::in()` / `Assignment::of()`, resolved once per user and request by `GrantResolver` (union of the sets
  held).
- `PermissionVoter` (`permission:<key>`, `permission_route:<route>`): fail closed (empty catalogue, unknown key,
  unmapped route, guest, context key without a typed context), deny-first `PermissionGateInterface`,
  `voter.bypass_roles` with `BypassVetoInterface`, `role_grants`, per-key aggregation (`catalogue.permissions.<key>.
  aggregate`), `voter.fallback_permission_set`; memoised per request, `ResetInterface`, bounded.
- `PermissionChecker` (`can`, `canRoute`, `canArea`, `filter`) and Twig `rbac_can()`, `rbac_can_route()`,
  `rbac_can_area()`.
- Opt-in request guard (`request_guard`) refusing catalogued routes before the controller (guests, strangers and
  unresolved contexts included), with `RequestContextResolverInterface`, `skip_strangers` and the `_rbac_guard: false`
  route opt-out.
- DashboardMenu permission checker (`RbacMenuPermissionChecker`) when nowo-tech/dashboard-menu-bundle is installed.
- `nowo:rbac:check` and `PermissionCoverage`: unguarded routes under prefixes, missing catalogue routes, uncatalogued
  set keys, empty catalogue.
- Admin UI (`web_ui.enabled`, opt-in routes): permission sets list, catalogue, create / edit / delete on the UiKit page
  contract with a FormKit type and profile `rbac_kit`, CSRF token ids bound to action and set
  (`nowo_rbac/<action>/<target>`), `PermissionSetChangedEvent` after every mutation, `PermissionSetUsageInterface`
  (assignment counts; deletion needs it and is refused for a set in use; a still-assigned code cannot be re-created),
  REQ-UI-002 access (view / manage roles, custom checker, debug-only `allow_unauthenticated`).
- Translations in en, es, fr, de, it, pt and nl.
- Symfony 8.1 FrankenPHP demo (MySQL, worker mode, loopback port 8096) with Playwright e2e and WebP screenshots.

### Changed (breaking, compared with the audited candidate `8bd695a`)

- `voter.global_aggregation` is **removed**: a global key is granted from the user's contexts only when the catalogue
  flags it `aggregate: true` (context keys cannot aggregate). Configurations that still set it fail with
  `Unrecognized option "global_aggregation"`.
- `permission_sets.<code>.all: true` expands to the catalogued **context** keys only; add `include_global: true`
  (only with `all: true`) for a platform-wide set assigned globally.
- `RbacContextInterface` gains `getRbacContextType()`; the default resolver returns typed ids `<type>:<id>`;
  `Assignment` refuses an untyped context id (`InvalidArgumentException`) and offers `global()` / `in()` / `of()`;
  the voter refuses untyped ids from a custom `ContextResolverInterface`.
- `request_guard.skip_strangers` defaults to `false`; guests on catalogued routes are refused (firewall entry point);
  unresolved contexts are refused unless `skip_strangers: true`; new route default `_rbac_guard: false`.
- `security.manage_roles` defaults to `[ROLE_ADMIN]` (no longer `access_roles`) and may not be empty.
- Deleting a custom set needs `storage.usage_provider` (`error.usage_unknown`); creating a code the usage provider
  still reports as assigned is refused (`error.code_assigned`); the delete action is hidden without a usage provider.
- New `storage.cache_ttl` (default 300 s); a storage holding more than `storage.max_sets` rows throws
  `OverflowException` (logged `critical`) instead of dropping rows.
- An `aggregate` global key asked with a context subject (`isGranted('permission:reports.view', $project)`,
  `rbac_can(key, project)`, `PermissionChecker::filter()`) is decided in that context only; asked without a subject
  it still aggregates every context of the user; asked with a subject that does not resolve to a typed context it
  grants nothing through aggregation (fail closed).
- A create (or first edit of a system set) that a concurrent request pushed past `storage.max_sets` is rolled back
  and refused (`error.limit`).
- The decision memo includes the subject's typed context id (global keys too) and the bypass veto state; subjects that
  are not contexts are not memoised.

### Security

- Safe defaults: an empty catalogue denies every permission; no bypass role, no fallback set, no grant provider; the
  admin UI and the request guard are off; empty admin role lists and non-literal security switches are refused.
- REQ-SEC-004 Appendix CI (first pass on `8bd695a`, Fail / High) fixed: H1 (context-held sets, owner `all: true`
  included, promoted their global keys platform-wide), M1 (`Organization#5` and `Project#5` shared grants), L1 (guard
  let guests and strangers through), L2 (re-created codes inherited stale assignments), L3 (stored-sets cache never
  expired), L4 (rows beyond `max_sets` silently dropped), L5 (memo ignored the subject of global keys and the veto
  state), L6 (`manage_roles` defaulted to `access_roles`). Re-verification on `570b756`: N1 (an `aggregate` key ignored the context
  subject, so `filter()` returned non-granting contexts), N2 (concurrent creates could exceed `max_sets` and make
  every read throw), N3 (usage-provider contract for `code_assigned` documented); final pass on `199d0b4`: N7 (an
  `aggregate` key asked with an unresolvable subject fell back to any context), N8 (the `max_sets` rollback could
  delete another manager's same-code row; it now removes only the row the request wrote). Residuals in [SECURITY.md](SECURITY.md#residual-risks-and-host-guidance).
