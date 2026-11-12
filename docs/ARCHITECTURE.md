# Architecture

## Table of contents

- [Overview](#overview)
- [Decision flow](#decision-flow)
- [Admin request flow](#admin-request-flow)
- [Components](#components)
- [Container wiring](#container-wiring)
- [Extension points](#extension-points)

## Overview

```
catalogue.permissions ──► ConfigPermissionProvider ─┐
PermissionProviderInterface services (tagged) ──────┴─► PermissionCatalog (memo, route index, aliases)
                                                              │
permission_sets (config) + PermissionSetStorageInterface ─────┴─► PermissionSetRegistry (memo + cache pool)
                                                                        │ keysOf(code)
GrantProviderInterface (host) ──► GrantResolver (memo per user: global keys, keys per context) ◄─┘
                                        │
isGranted('permission:key', $ctx) ──► PermissionVoter (memo per decision) ◄── gates, bypass vetoes, role_grants
     ▲               ▲                     ▲
PermissionChecker  Twig rbac_can()   PermissionRequestListener (opt-in, kernel.controller)
     ▲
RbacMenuPermissionChecker (DashboardMenu)

HTTP admin (route loader, prefix) ──► RbacAdminAccessListener ──► AccessGuard ──► access checker (view / manage)
   └─ PermissionSetController ──► PermissionSetManager (validate, store, invalidate, PermissionSetChangedEvent)
        └─ RbacPageFactory ──► UiKit view models ──► UiKit scaffolds (@NowoRbacKitBundle/admin/*)

CLI nowo:rbac:check ──► PermissionCoverage (router routes × catalogue × configured sets)
```

## Decision flow

See the diagram in [USAGE.md](USAGE.md#decision-order): empty catalogue, unknown key / unmapped route, guest and a
context key without a resolvable context deny first; then gates; then bypass roles (unless vetoed); then the sets held
in the context (typed context id, context keys) or role grants, global sets, `aggregate`-flagged keys held in a
context and the fallback set (global keys). One provider call per user and request; one decision per (user, roles, key,
typed context id, veto state) and request.

## Admin request flow

1. The host firewall, then `RbacAdminAccessListener` (kernel.request, priority 7) asks `AccessGuard` for the view area
   on every request under the prefix and every bundle controller.
2. Mutations require the manage area and a writable storage, validate the CSRF form bound to the action and the set
   (fail closed), then call `PermissionSetManager`.
3. The manager validates the code, name, description and keys (catalogue), enforces `storage.max_sets`, stores the row,
   invalidates the registry cache and dispatches `PermissionSetChangedEvent`.
4. The controller adds a translated flash and redirects to a generated path.

## Components

| Namespace | Role |
| --- | --- |
| `Permission\` | `Permission`, `PermissionScope`, `PermissionCatalog`, `PermissionProviderInterface`, `ConfigPermissionProvider` |
| `PermissionSet\` | `PermissionSet` (view), `StoredPermissionSet` (row), `PermissionSetRegistry`, `PermissionSetManager`, storages (`Config…`, `Doctrine…`), `PermissionSetUsageInterface`, `PermissionSetException` |
| `Grant\` | `Assignment`, `GrantProviderInterface`, `NullGrantProvider`, `GrantResolver` |
| `Context\` | `RbacContextInterface`, `ContextId` (typed ids `<type>:<id>`), `ContextResolverInterface` (+ default), `RbacRequestContextResolverInterface` + `RbacRequest` VO (1.2), deprecated `RequestContextResolverInterface` (+ null, both ports) |
| `Security\` | `PermissionVoter`, `PermissionChecker`, `PermissionGateInterface`, `RbacBypassVetoInterface` + `RbacActor` VO (1.2), deprecated `BypassVetoInterface`, admin `AccessGuard` and access checkers |
| `EventListener\` | `PermissionRequestListener` (request guard), `RbacAdminAccessListener` |
| `Coverage\`, `Command\` | `PermissionCoverage`, `CoverageReport`, `nowo:rbac:check` |
| `Controller\`, `Form\`, `Routing\`, `Ui\` | Admin UI: controller, `PermissionSetType`, route loader and names, `ActionForms`, `RbacPages`, `RbacPageFactory` |
| `Entity\`, `Doctrine\` | `PermissionSetRecord`, `TablePrefixListener` |
| `Menu\`, `Twig\`, `Event\` | DashboardMenu checker, Twig functions, `PermissionSetChangedEvent` |

## Container wiring

`NowoRbacKitExtension` registers every service explicitly (no resource scan). Memo services carry `kernel.reset`.
Controllers are not autoconfigured (opt-in routes). Compiler passes: `TwigPathsPass` (override directory first),
`CsrfProtectionPass` (UI without form CSRF fails), `UnauthenticatedAccessPass` (demo switch outside debug fails). The
FormKit profile `rbac_kit` and, with Doctrine storage, the entity mapping are prepended.

## Extension points

`PermissionProviderInterface`, `GrantProviderInterface`, `ContextResolverInterface`, `RequestContextResolverInterface`,
`PermissionGateInterface`, `BypassVetoInterface`, `PermissionSetStorageInterface`, `PermissionSetUsageInterface`,
`RbacAdminAccessCheckerInterface`, `PermissionSetChangedEvent`, `web_ui.templates`, Twig overrides.
