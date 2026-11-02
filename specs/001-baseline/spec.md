# Feature Specification: RbacKitBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-01
**Status**: Baseline (1.0.0 candidate)
**Input**: Extraction of the Symfony Beacon permission layer (KIT-46 "permission-set catalogue and context voter",
audit row #37 "memoised voter"; origin specs 162 / 163 / 165, imported under [`specs/`](../)), generalised: a context /
subject abstraction instead of the origin's projects, a configurable catalogue, safe defaults.

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/USAGE.md`](../../docs/USAGE.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md),
[`code-inventory.md`](code-inventory.md).

## Summary

The bundle (config root `nowo_rbac_kit`) guarantees: a permission catalogue merged from configuration and tagged
providers; permission sets (roles) from configuration and an optional storage; a context abstraction and a host grant
provider; a memoised, worker-safe voter answering `permission:<key>` and `permission_route:<route>` with the union of
the sets a user holds in the asked context, failing closed; PHP / Twig / menu entry points; an opt-in request guard; a
coverage command; and a UiKit admin UI for the sets behind private access.

## User Scenarios & Testing

### US-1 — Roles as permission sets checked in their context (P1)

1. **Given** alice holds `viewer` and `triage` in context *billing* and `viewer` in *mobile*, **When** a page asks
   `permission:project.issues.triage` with *billing*, **Then** it is granted; with *mobile* it is refused (union per
   context, no level comparison).
2. **Given** a context key asked without a context subject (or with an object that is not a context), **Then** it is
   refused.
3. **Given** bob holds `auditor` globally, **When** a page asks the global `reports.export`, **Then** it is granted; a
   context key in any project is refused.
4. **Given** dave holds a set in context `organization:5`, **When** a page asks a context key on `Project#5`, **Then**
   it is refused (typed context ids, REQ-SEC-004 Appendix CI M1).
5. **Given** carol holds the `owner` set (`all: true`) in project *mobile* only, **When** a global check asks
   `reports.export`, **Then** it is refused: a set held in a context never grants a global key that is not flagged
   `aggregate` (Appendix CI H1).

### US-2 — General pages by aggregation (P2)

1. **Given** `reports.view` flagged `aggregate: true` and one context granting it, **When** a global check asks it,
   **Then** it is granted; asked with a context subject it is decided in that context only, so
   `PermissionChecker::filter()` returns only the granting contexts; a global key without the flag is never granted by
   a context-held set.
2. **Given** `fallback_permission_set: viewer`, **When** a user without any assignment asks a global key of `viewer`,
   **Then** it is granted.

### US-3 — Fail closed (P1)

1. **Given** an empty catalogue, **Then** every `permission:` / `permission_route:` attribute is refused, bypass roles
   included.
2. **Given** an unknown key, an unmapped route (default policy), or a guest, **Then** the attribute is refused.
3. **Given** a redefined key, a route guarded twice or an alias to an unknown key, **Then** reading the catalogue
   throws.

### US-4 — Pages, routes and menus (P1)

1. **Given** `request_guard.enabled` and a member without the route's key in the request's context, **When** the route
   is requested, **Then** a 403 is returned before the controller; strangers and unresolved contexts get 403 too
   (`skip_strangers: true` leaves them to the controller), guests get the firewall entry point, and a route with the
   default `_rbac_guard: false` is left to its controller.
2. **Given** a DashboardMenu item with a route or keys, **Then** it is shown only when the route's key or one of its
   keys is granted.
3. **Given** `nowo:rbac:check --prefix=/projects`, **Then** it fails on unguarded routes, missing catalogue routes and
   uncatalogued set keys.

### US-5 — Administer permission sets (P2)

1. **Given** a manager on a writable storage, **When** they create a custom set, edit a system set or delete a custom
   set with a valid CSRF token, **Then** the change is stored, the registry cache is invalidated, an audit event is
   dispatched and the response redirects to the list path.
2. **Given** a viewer, a guest, a forged token, a token of another set, a locked set, a system set deletion or a set in
   use, **Then** the change is refused without side effects.

### Edge cases

- More assignments than `voter.max_assignments`: the rest are ignored (logged).
- Stored sets naming keys removed from the catalogue: those keys grant nothing.
- A corrupted cache entry: ignored (no stored set applies).
- Worker mode: memos cleared by `kernel.reset`; bounded when not.

## Requirements

### Catalogue

- **FR-CAT-001** `Permission` (key pattern, scope, routes, group, labels) and `PermissionScope`.
- **FR-CAT-002** `PermissionCatalog` merges `ConfigPermissionProvider` and tagged `PermissionProviderInterface`
  services; validates redefinitions, route collisions, aliases and `max_permissions`; route and alias lookups; memoised
  and reset per request.

### Permission sets

- **FR-SET-001** `PermissionSet` view, configured system sets (`all`, `except`, `locked`), `StoredPermissionSet` rows.
- **FR-SET-002** `PermissionSetRegistry`: system sets (`all` = context keys, `include_global`), stored edits (ignored
  for locked sets), custom sets by name, keys intersected with the catalogue, cache pool with `cache_ttl`, `max_sets`
  (overflow throws), reset / invalidate.
- **FR-SET-003** Storages: `ConfigPermissionSetStorage` (read-only), `DoctrinePermissionSetStorage` with the
  `PermissionSetRecord` entity and `TablePrefixListener`, host storage service.
- **FR-SET-004** `PermissionSetManager`: create / update / delete with validation, `max_sets`, usage check
  (`PermissionSetUsageInterface`: delete needs it; still-assigned codes cannot be created), `PermissionSetException`
  reasons and `PermissionSetChangedEvent`.

### Grants and contexts

- **FR-GRT-001** `Assignment` (typed context ids; `global()`, `in()`, `of()`), `GrantProviderInterface`,
  `NullGrantProvider`.
- **FR-GRT-002** `GrantResolver`: union per context, global sets, aggregation helpers, one provider call per user and
  request, bounded, reset.
- **FR-CTX-001** `RbacContextInterface` (type + id), `ContextId`, `ContextResolverInterface`, `DefaultContextResolver`.
- **FR-CTX-002** `RequestContextResolverInterface`, `NullRequestContextResolver`.

### Voter and entry points

- **FR-VOT-001** `PermissionVoter`: decision order (empty catalogue, unknown key / unmapped route / open routes, guest,
  typed context subject, gates, bypass + vetoes, context sets, role grants, global sets, per-key aggregation, fallback),
  memoised per request (typed context id and veto state in the key), bounded, reset.
- **FR-VOT-002** `PermissionGateInterface`, `BypassVetoInterface`.
- **FR-CHK-001** `PermissionChecker` (`can`, `canRoute`, `canArea`, `filter`) and `RbacTwigExtension`.
- **FR-GRD-001** `PermissionRequestListener` (opt-in request guard; guests, strangers and unresolved contexts refused;
  `_rbac_guard: false` opt-out).
- **FR-MNU-001** `RbacMenuPermissionChecker` (DashboardMenu integration).
- **FR-COV-001** `PermissionCoverage`, `CoverageReport`, `CheckCommand`.

### Admin UI

- **FR-UI-001** Route loader and route names (opt-in routes, REQ-SF-004 rule 7).
- **FR-UI-002** `PermissionSetController` (list, catalogue, new, edit, delete; manage area; path-only redirects).
- **FR-UI-003** `PermissionSetType` (FormKit profile) and `ActionForms` (CSRF token ids bound to action and target).
- **FR-UI-004** `RbacPages`, `RbacPageFactory` and the Twig views (UiKit page contract).
- **FR-SEC-001** `AccessGuard`, `RbacAdminAccessCheckerInterface`, `ConfigurableRbacAdminAccessChecker`,
  `AllowAllRbacAdminAccessChecker`, `RbacAdminAccessListener`.
- **FR-SEC-002** `CsrfProtectionPass`, `UnauthenticatedAccessPass`, literal security booleans, empty role lists refused.
- **FR-I18N-001** Translations in 7 locales.

### Bundle

- **FR-BUNDLE-001** `NowoRbacKitBundle`, `Configuration`, `NowoRbacKitExtension`, `TwigPathsPass`.

## Origin specifications

| Origin requirement | Bundle |
| --- | --- |
| 163 FR-001 permission catalogue (key, context, routes) | FR-CAT-001 / FR-CAT-002 (`scope` instead of project / general; routes; aliases replace the spec 162 area keys) |
| 163 FR-002 coverage test | FR-COV-001 (`nowo:rbac:check`, `PermissionCoverage`) |
| 163 FR-003 one voter `permission:<key>`, admin bypass, project union, general by aggregation | FR-VOT-001 (`bypass_roles` empty by default; typed context instead of project; per-key `aggregate`) |
| 163 FR-005 permissions loaded once per request, reset (worker mode) | FR-GRT-002, FR-VOT-001 (`ResetInterface`, bounded memos) |
| 163 FR-006 role table seeded with system roles | FR-SET-001..003 (configured system sets + `rbac_permission_set` edits) |
| 163 FR-007 Administration → roles (create / edit / delete, refused in use, audited) | FR-SET-004, FR-UI-001..004 |
| 163 FR-008 owner holds every permission | `all: true, locked: true` set (every context permission of its context) |
| 163 FR-011 request listener refusing a route before the controller | FR-GRD-001 (opt-in, `skip_strangers` off by default) |
| 163 FR-012 Twig `can()` | FR-CHK-001 (`rbac_can()`) |
| 163 FR-013 menus ask the voter, general pages restrict data to granting projects | FR-MNU-001, `PermissionChecker::filter()` |
| 163 "no project role at all" default | `voter.fallback_permission_set` (off by default) |
| 165 platform roles hold general permissions (role hierarchy) | `role_grants` (global keys only) |
| 180 plan gate (in the origin voter) | FR-VOT-002 (`PermissionGateInterface`) |
| "view as member" (origin admin preview) | FR-VOT-002 (`BypassVetoInterface`) |
| 162 access rules (narrowing layer) | Not extracted: replaced by spec 163 in the origin |
| 163 FR-009 / FR-010 / FR-014..021, 165 FR-002..013 | Host concerns (memberships UI, manual, plans, areas): not part of the bundle |

## Key entities

`Permission`, `PermissionScope`, `PermissionSet`, `StoredPermissionSet`, `PermissionSetRecord`, `Assignment`,
`CoverageReport`, `PermissionSetChangedEvent`, `PermissionSetException`.

## Success Criteria

- **SC-001**: PHPUnit statements and elements coverage of `src/` is 100% (CI gate).
- **SC-002**: PHPStan level 8 with FrankenPHP rulesets, Rector dry-run, PHP-CS-Fixer and Igor are clean.
- **SC-003**: The UiKit page contract test passes for every page and fixture; the editor identifier never reaches the
  HTML.
- **SC-004**: The demo e2e suite passes (union per project, request guard, global set, admin create / delete / edit).
- **SC-005**: The code inventory maps every file under `src/`.

## Non-goals

Storing memberships (the host's grant provider owns them); per-user exceptions; time- or IP-based rules; API token /
share-link scopes; a membership administration UI; comparing roles by level.

## Assumptions

Symfony Security in the host; stable context ids; a cache pool shared by the web workers when sets are edited.

## Validation

```bash
make test-coverage-100 phpstan cs-check rector-dry igor validate-translations
make release-check
make -C demo/symfony8 test-e2e
```
