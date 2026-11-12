# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/rbac-kit-bundle` (`symfony-bundle`) |
| Audited revision | `v1.2.0` (first audit `1.0.0`; kernel-reset-false remediation in 1.1.0, framework-free ports in 1.2.0) |
| Audit date | 2026-11-01; re-audited for REQ-RUNTIME-002 on 2026-11-12 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`, 0 KO / 0 WARN); kernel test proving one grant load per request and a reload after `kernel.reset`; demo run in worker mode |
| **Verdict** | ✅ **Viable under scenario B (kernel reset false)** — the four memo services (catalogue, permission sets, grants, decisions) are cleared at every request / Messenger / console boundary by `RbacMemoResetListener` (since 1.1), and also implement `ResetInterface` (`kernel.reset`); every memo is keyed by user (never shared between users) and bounded; the Doctrine storage re-reads its rows with `HINT_REFRESH` and the bundle's own entity manager (when configured) is cleared by `OwnEntityManagerSubscriber`; every other service is stateless |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once and serves many requests with the same container. Two scenarios are
evaluated:

- **A — kernel not rebooted, `services_resetter` still runs** (Symfony Runtime / FrankenPHP runtime default).
- **B — no reset at all:** any state kept in a service survives into the next request.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `PermissionCatalog`, `PermissionSetRegistry`, `GrantResolver`, `PermissionVoter` memoise per request (`@igor-ignore` with justification); route loader load-once flag (cache warm-up) |
| Request boundary (no `kernel.reset` needed) | ✅ | `RbacMemoResetListener` clears the four memos on the main `kernel.request` and `WorkerMessageReceivedEvent` (priority 4096) and on `kernel.terminate`, `console.terminate`, `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` (-1024); a permission-set write drops the grant and voter memos at once. Proven by `RbacWorkerMemoTest` |
| `ResetInterface` / `kernel.reset` | ✅ | The four memo services also implement it (never relied on alone); proven by `VoterIntegrationTest::grantsAreLoadedOncePerRequestAndResetBetweenRequests` |
| Cross-user leakage | ✅ | Grants and decisions are keyed by user class + identifier (+ roles, typed context id and veto state for decisions; non-context subjects are not memoised); a user never reads another user's memo |
| Memory growth | ✅ | Bounded: catalogue `max_permissions`, stored sets `max_sets`, 64 users and `max_assignments` per user, 2048 decisions (memos are cleared when full) |
| Static properties / `static` locals | ✅ | One (1.2): `Internal\HiddenValues::$values`, a `WeakMap` keyed by the per-request `RbacRequest` / per-decision `RbacActor`; an entry is collected with its VO, so nothing crosses requests (`@igor-ignore` / PHPStan ignore with justification) |
| Request / user captured in services | ✅ | `AccessGuard` reads `RequestStack::getMainRequest()` and the token per call and trusts the token only with a firewall context; the voter receives the token per call |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None |
| Doctrine | ✅ | The manager is resolved per call (`doctrine.entity_manager`, else `getManagerForClass()`). Writes are single DBAL statements (never a `flush()`, 1.1); `DoctrinePermissionSetStorage::load()` reads with `Query::HINT_REFRESH`, so a `PermissionSetRecord` already in the identity map is re-hydrated from the row, not served stale, without relying on the services resetter. With a dedicated `doctrine.entity_manager`, `Persistence\OwnEntityManagerSubscriber` resets that manager when closed (main `kernel.request`, `WorkerMessageReceivedEvent`) and clears it at `kernel.terminate`, `console.terminate` and the Messenger handled / failed events. The host's own managers are never cleared: see rule 7 below |
| Resources held open | ✅ | None |
| Output, headers, `exit` | ✅ | Responses / console output only |
| PHPStan FrankenPHP rulesets / Igor | ✅ | 0 errors / 0 KO |

## Services reviewed

| Service | State | Scenario A | Scenario B |
|---------|-------|------------|------------|
| `PermissionCatalog` | catalogue memo + route index | ✅ reset | ✅ cleared at every boundary by `RbacMemoResetListener` (1.1) |
| `PermissionSetRegistry` | sets memo, key sets | ✅ reset; the cache pool carries edits between workers (entry expires after `storage.cache_ttl`) | ✅ cleared at every boundary (1.1); an edit made in another worker is seen on the next request once the shared cache entry is dropped or expires |
| `GrantResolver` | grants per user (bounded) | ✅ reset | ✅ cleared at every boundary (1.1) and on every permission-set write |
| `PermissionVoter` | decisions (bounded) | ✅ reset | ✅ cleared at every boundary (1.1) and on every permission-set write |
| `EventListener\RbacMemoResetListener` (1.1) | none (holds the four memo services) | ✅ | ✅ |
| `Persistence\OwnEntityManagerSubscriber` (1.1, only with a dedicated `doctrine.entity_manager`) | none (`readonly` registry + manager name) | ✅ | ✅ clears / recovers the bundle's own manager at every boundary (`RbacPersistenceKernelTest::testKeptAliveKernelClearsTheOwnManagerAndKeepsMemoryFlat`) |
| `Internal\HiddenValues` (1.2) | static `WeakMap` keyed by the per-request `RbacRequest` / per-decision `RbacActor` | ✅ | ✅ entries are collected with their VO |
| `PermissionSetManager`, storages, `PermissionChecker`, `PermissionCoverage`, listeners, Twig / menu checkers, admin services, controller | none (`readonly` / stateless) | ✅ | ✅ |
| `RbacRouteLoader` | `loaded` flag (cache warm-up only) | ✅ | ✅ |

## Findings

No worker-mode finding under scenario **A**. Under scenario **B** (kernel reset off), before 1.1 permission changes and
a user's assignments were seen only after the worker restarted (LH-RBAC-M1, authorization). Since 1.1
`RbacMemoResetListener` clears the four memos at the start of every main request / Messenger message and at the end of
every request, message and command, and a permission-set write drops them at once. Proven by
`RbacWorkerMemoTest` (two kept-alive kernels on one database driven through `http_kernel`: a permission revoked on
worker A is refused by worker B on its next request; 200 requests alternating alice and bob, each seeing only their
own grants, with flat memory; a rolled-back edit is not memoised).

1.2 (framework-free ports): the guard builds one `RbacRequest` per request and the voter one `RbacActor` per decision
(only when a veto implements `RbacBypassVetoInterface`); neither is stored in a service. Proven by
`DddHostPortsKernelTest::aKeptAliveWorkerAnswersEveryUserTheSameWay` (one kernel, no services resetter, 300 main
requests through `http_kernel` as alice, the superuser admin, bob and a guest in alternation, the same status every
time, memory growth under 4 MiB).

## Usage recommendations in worker mode

- The bundle is correct with kernel reset false (REQ-RUNTIME-002): its memos and its own entity manager are cleared at
  the request, Messenger and console boundaries whether or not `services_resetter` runs.
- **Host entity managers (REQ-RUNTIME-002 rule 7).** A host that disables or bypasses the services resetter must clear
  its own entity managers at every boundary (main request, Messenger message, console command). The bundle only clears
  the dedicated `doctrine.entity_manager` it owns; without that option `PermissionSetRecord` lives in the host's manager,
  which the bundle never clears. Its own reads use `HINT_REFRESH`, but other host entities (users, assignments read by
  your `GrantProviderInterface`) would otherwise be served stale from the identity map.
- Use a `storage.cache_pool` shared by every worker (filesystem on one host, Redis / Doctrine otherwise) so an edit made
  in one worker reaches the others after their next reset; with a per-host pool the cached rows expire after
  `storage.cache_ttl` (default 300 s).
- Your `GrantProviderInterface`, `PermissionGateInterface`, `RbacBypassVetoInterface` (or deprecated
  `BypassVetoInterface`) and request context resolver services must be stateless too (or clear their state at the
  request boundary; `ResetInterface` alone is not enough with kernel reset false).

## Re-audit triggers

A new service with state, a new memo, a static property, a new integration (cache, Doctrine, request data), or a change
of the decision memo key.
