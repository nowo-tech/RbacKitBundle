# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

REQ-ARCH-001 rule 3: framework-free twins of the two adapter SPIs that took Symfony types. Permission decisions are
unchanged (exhaustive equivalence matrix against the 1.1.0 voter). See [UPGRADING](UPGRADING.md#unreleased).

### Added

- `RbacRequestContextResolverInterface::contextOf(RbacRequest)`: the request guard's context resolver without the
  Symfony `Request`. `RbacRequest` carries the method, route name, scheme and host as properties; the path, scalar
  route parameters, scalar non-internal attributes and the allowlisted headers are kept in an internal `WeakMap`
  (never shown by a dump, `var_export()`, `(array)`, `json_encode()` or `serialize()`).
- `RbacBypassVetoInterface::vetoesBypass(RbacActor, ?object)`: the bypass veto without the Symfony `TokenInterface`.
  `RbacActor` carries the user identifier, token roles, reachable roles, impersonator and firewall name; the user
  object and the token attributes are kept in the same internal `WeakMap`.
- `request_guard.context_headers` (default `[]`): headers copied into the `RbacRequest`; credential, cookie, client-IP
  and forwarding headers are refused.
- `RbacPortsPass`: the configured context resolver and every tagged bypass veto must implement one of their two ports
  (the build fails otherwise); a service implementing both is called through the new one. No interface alias is
  registered for these SPIs, so `lint:container` passes for new-only, deprecated-only and dual services (kernel test).
- Tests: old-vs-new equivalence matrices for the voter (106,920 decisions against a verbatim copy of the 1.1.0 voter,
  with deny-by-default invariants) and the request guard (8,400 requests); a DDD-host kernel test using only the new
  ports; `RbacWorkerMemoTest` now alternates alice and bob.

### Deprecated

- `Context\RequestContextResolverInterface` (use `RbacRequestContextResolverInterface`) and
  `Security\BypassVetoInterface` (use `RbacBypassVetoInterface`), removed in 2.0. A service implementing only the
  deprecated interface triggers a build-time deprecation and keeps working as in 1.1.

### Changed

- A service tagged `nowo_rbac_kit.bypass_veto` by hand that implements neither veto interface (a duck-typed `vetoes()`
  method) now fails the container build, or throws at the first decision when its class is unknown at build time
  (fail closed). Implement `RbacBypassVetoInterface`.

## [1.1.0] - 2026-11-11

Security: authorization memos are cleared at every request, message and command boundary, so a revoked permission is refused in kept-alive workers (GHSA); permission-set writes use DBAL and never flush the host's entity manager. See [UPGRADING](https://github.com/nowo-tech/RbacKitBundle/blob/v1.1.0/docs/UPGRADING.md#to-110).


### Security

- Worker mode (authorization): the permission catalogue, permission-set registry, grant resolver and voter memos were
  cleared only by `kernel.reset`. A kept-alive worker without the services resetter (FrankenPHP worker mode with kernel
  reset off) kept granting a revoked permission, and a user's old assignments, until it restarted. `RbacMemoResetListener`
  now clears them at the start of every main request and Messenger message and at the end of every request, message and
  command, and every permission-set write also drops the grant and voter memos. Affected: 1.0.0. Advisory: GitHub Security Advisory (Moderate) for >= 1.0.0, < 1.1.0.

### Fixed

- Rule 6: `storage.type: doctrine` flushed the entity manager that maps `PermissionSetRecord` (the host's default one)
  on every save and delete, committing whatever the host had left pending in it. Writes are now single DBAL
  statements built from the entity metadata (insert / update by code, delete by code), inside a SAVEPOINT when a
  transaction is open (the original error is rethrown if the write fails); reads re-hydrate the records
  (`HINT_REFRESH`). Affected: 1.0.0.
- After a permission-set write the registry reads the storage on every call until the end of the request, message or
  command, without memoising it or writing the cache pool, and drops the cache entry again at that end: an edit the
  host rolls back no longer stays in the memo or, with `cache.app`, in the shared cache for `cache_ttl`.
- `doctrine.entity_manager: default` in a single-manager (Flex shorthand) host no longer fails with "does not map".

### Added

- `doctrine.entity_manager` (default `null`): map `PermissionSetRecord` on a dedicated entity manager only
  (`RbacEntityManagerPass`, also off an `auto_mapping: true` default); the host's `default_entity_manager` stays the
  host's, an undeclared manager fails the build, and the bundle clears that manager at the end of every request,
  message and command (`OwnEntityManagerSubscriber`).
- `PermissionSetChangedEvent::$actorId` (rule 4: the user identifier, the value stored as `updated_by`). `$user` stays
  for standard hosts and is deprecated (removed in 2.0).
- `PermissionCheckerInterface` and `PermissionSetManagerInterface` (rule 1), aliased to the services.
- CI job running the storage tests on MySQL 8.4 and PostgreSQL 17 (plus SQLite).

## [1.0.0] - 2026-11-02

First release: permission catalogue, permission sets, typed contexts, memoised voter and admin UI (REQ-SEC-004 Appendix CI fixes included).

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
