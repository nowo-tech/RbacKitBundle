# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.1.0](#to-110)
- [To 1.0.0](#to-100)
  - [Requirements](#requirements)
  - [Adopting the kit from an in-app permission layer](#adopting-the-kit-from-an-in-app-permission-layer)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)
  - [From the audited candidate 8bd695a](#from-the-audited-candidate-8bd695a)

## Unreleased

Target: 1.2.0. **No configuration change and no change of any permission decision.** Two adapter SPIs gained a
framework-free twin (REQ-ARCH-001 rule 3); the old ones are deprecated and removed in 2.0.

| Deprecated (removed in 2.0) | Replacement |
|---|---|
| `Context\RequestContextResolverInterface::fromRequest(Request $request): ?object` | `Context\RbacRequestContextResolverInterface::contextOf(RbacRequest $request): ?object` |
| `Security\BypassVetoInterface::vetoes(TokenInterface $token, ?object $subject): bool` | `Security\RbacBypassVetoInterface::vetoesBypass(RbacActor $actor, ?object $subject): bool` |

Migration:

1. Request context resolver (`request_guard.context_resolver`): implement `RbacRequestContextResolverInterface`.
   `$request->attributes->get('id')` becomes `$request->routeParam('id')` (or `attribute('id')`); a header becomes
   `$request->header('x-tenant')` after listing it in `request_guard.context_headers`. Object attributes, cookies, the
   query string and the session are not available: resolve from an id.
2. Bypass vetoes: implement `RbacBypassVetoInterface`. `$token->getUserIdentifier()` → `$actor->userIdentifier`,
   `$token->getRoleNames()` → `$actor->roles` (`$actor->reachableRoles` after the hierarchy), `$token->getUser()` →
   `$actor->user()`, `$token->getAttribute('x')` → `$actor->tokenAttribute('x')`, a `SwitchUserToken` original user →
   `$actor->impersonatorIdentifier`. Autoconfiguration tags it as before.
3. During the transition a class may implement both interfaces; the bundle then calls only the new method. Remove the
   deprecated one before 2.0.
4. A configured resolver or a tagged veto implementing neither interface now fails the container build (before, it
   failed with a type error at the first request).

`symfony/deprecation-contracts` is now a direct requirement (it was already installed through Symfony).

## To 1.1.0

Standard hosts: **no configuration change**. Permission-set edits no longer flush your default entity manager (they
are DBAL statements on its connection).

- Audit listeners: prefer `$event->actorId` over `$event->user` (deprecated, removed in 2.0).
- Type-hint `PermissionCheckerInterface` / `PermissionSetManagerInterface` instead of the final classes (optional).
- New optional `doctrine.entity_manager: <name>`: declare that manager under `doctrine.orm.entity_managers` (no
  mappings needed), keep `default_entity_manager` on your own default, and create or migrate `rbac_permission_set`
  on its connection. An undeclared name fails the container build.
- When your code wraps an edit in its own transaction, the row now follows that transaction (SAVEPOINT); the event is
  dispatched when the manager call returns, i.e. before your commit. If you roll back, the permissions are not kept:
  the registry is not memoised nor cached after a write, and the cache entry is dropped again at the end of the
  request / message / command.
- Worker mode: the authorization memos are now cleared at every request / message / command boundary by the bundle
  itself (no reliance on `kernel.reset`). Nothing to change.

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

None (first release). Changes relative to the pre-release candidate: [Unreleased](#unreleased).

### From the audited candidate 8bd695a

Hosts that installed `dev-main` before the REQ-SEC-004 fixes (Appendix CI) must apply these
changes. No database migration is needed: the bundle's table (`rbac_permission_set`) is unchanged and assignments live
in the host.

1. **Typed context ids (M1)**. Add `getRbacContextType()` to every `RbacContextInterface` class — a stable alias such as
   `project` or `organization` (`^[a-z][a-z0-9_]{0,39}$`), never a class name. Build assignments with
   `Assignment::in('owner', 'project', (string) $projectId)`, `Assignment::of('owner', $project)` or
   `Assignment::global('auditor')`; `new Assignment('owner', '5')` now throws. A custom `ContextResolverInterface` must
   return `ContextId::of($type, $id)`. Stored assignments in the host keep their raw ids: the grant provider adds the
   type when it builds the `Assignment` (no data migration). If the host stores pre-built composite ids, migrate them
   to `<type>:<id>` in its own migration.
2. **Global aggregation (H1)**. Remove `voter.global_aggregation`. For each global key that fronts a "general page
   showing the data of my contexts" (reports, summary, chat directory…), add `aggregate: true` to its catalogue entry
   and keep filtering its data with `PermissionChecker::filter()`. Do not flag platform keys (user administration,
   billing, exports). Asked with a context subject, an aggregate key is decided in that context
   only (so `filter()` keeps the granting contexts); asked without one, any context of the user grants it.
3. **Owner sets (H1)**. `all: true` now means every **context** key. A set meant to hold every key platform-wide (and
   assigned globally) needs `include_global: true`. Drop `except: [<global keys>]` entries that only existed to keep
   global keys out of an owner set.
4. **Request guard (L1)**. Guests on catalogued routes get the firewall's entry point; strangers and unresolved
   contexts get 403. Keep the old behaviour with `request_guard.skip_strangers: true`, or opt single routes out with
   the route default `_rbac_guard: false` (share links, public pages) — those controllers must check access
   themselves. Enabling the guard without `request_guard.context_resolver` now refuses every context route unless
   `skip_strangers: true`.
5. **Admin managers (L6)**. `security.manage_roles` defaults to `[ROLE_ADMIN]`. Set it explicitly if another role
   managed sets through `access_roles`.
6. **Deleting sets (L2)**. Configure `storage.usage_provider` to keep the delete action; without it custom sets cannot
   be deleted. A code that is still assigned cannot be re-created until its assignments are removed. The usage provider
   must count assignments by code even when no set with that code exists any more.
7. **Cache and bounds (L3, L4)**. `storage.cache_ttl` (default 300) bounds how long a per-host cache serves a revoked
   key. If the table holds more rows than `storage.max_sets`, the registry throws: raise the bound or delete rows.
8. **Gates and vetoes (L5)**. Vetoes are now asked on every decision of a bypass role; keep them cheap. Gates receiving
   a non-context subject are no longer memoised.

