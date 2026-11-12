# Security

Bundle-specific security guide (threat model, mitigations, release checklist). The short GitHub policy (supported
versions, private reporting) is [`.github/SECURITY.md`](../.github/SECURITY.md).

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Admin UI exposure and allow_unauthenticated](#admin-ui-exposure-and-allow_unauthenticated)
- [Personal data (RGPD / GDPR)](#personal-data-rgpd--gdpr)
- [CSRF](#csrf)
- [Logging](#logging)
- [Resource limits](#resource-limits)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Dependencies and updates](#dependencies-and-updates)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)
- [Residual risks and host guidance](#residual-risks-and-host-guidance)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## Scope

Covered: the permission catalogue, the permission set registry and storages, the grant resolver, the voter and its
extension points, the request guard, the DashboardMenu checker, the coverage command and the admin UI (list,
catalogue, create / edit / delete). Not covered: the host's grant provider, context resolvers, gates and vetoes (host
code), the host firewall and `access_control`.

## Attack surface

| Input | Where | Notes |
| --- | --- | --- |
| Voter attributes and subjects | `isGranted()`, Twig, menu checker, request guard | Attributes come from host code; subjects are host objects. |
| HTTP GET under `web_ui.path_prefix` | list, catalogue, forms | No query parameter is read; route `code` constrained by `^[a-z][a-z0-9_]{1,39}$`. |
| HTTP POST under the prefix | create, edit, delete | Session-authenticated mutations: code, name, description, permission keys. |
| Configuration | `nowo_rbac_kit` | Validated tree; security switches must be literal booleans. |
| Storage / cache | `rbac_permission_set`, `storage.cache_pool` | Rows written by the admin UI; cache entries validated when read. |
| CLI | `nowo:rbac:check` | Read-only; operator only. |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| Privilege escalation through a misconfigured catalogue | Fail closed (P1): an empty catalogue denies every attribute (bypass roles included); unknown keys, unmapped routes (`unmapped_routes: deny` default), guests and context keys without a resolvable context are denied; a redefined key, a route guarded twice, an alias to an unknown key or an oversized catalogue throw instead of granting. Defaults grant nothing: no bypass role, no fallback set, no grant provider. |
| Context confusion (permission of project A used in project B) | Context keys are granted only by sets held **in that context**; objects that are not contexts are refused; global assignments and role grants never grant context keys. |
| Context type confusion (`Organization#5` vs `Project#5`) | Context ids are **typed** (`<type>:<id>`, `ContextId`): the default resolver builds `getRbacContextType():getRbacContextId()`, `Assignment` refuses an untyped context id when it is built, and the voter refuses an untyped id returned by a custom resolver. Two context classes sharing an id are two contexts (Appendix CI M1). |
| Context → global promotion (a tenant-level owner gaining platform keys) | A set held in a context never grants a **global** key, except keys the catalogue flags `aggregate: true` (general pages that show the data of the granting contexts); asked with a context subject, an aggregate key is decided in that context only, so `PermissionChecker::filter()` keeps only the granting contexts (re-verification N1). `all: true` expands to **context** keys only (`include_global: true` is an explicit opt-in for sets assigned globally). The former `voter.global_aggregation` switch, which promoted every global key of any context-held set, is removed (Appendix CI H1). |
| Request guard bypass by guests and strangers | With `request_guard.enabled`, guests on a catalogued route get the firewall entry point, and users holding no set in the request's context — or requests whose context cannot be resolved — get 403 (`skip_strangers: false` default). Per-route opt-out: route default `_rbac_guard: false` (Appendix CI L1). |
| Stale or cross-user grants in worker mode | Memos keyed by user class + identifier (+ reachable roles, the subject's typed context id and the bypass veto state for decisions; subjects that are not contexts are not memoised), cleared by `kernel.reset` every request, bounded (see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)). |
| Revocation not seen by other web hosts | The cache entry of the stored sets expires after `storage.cache_ttl` (default 300 s), so a per-host pool sees an edit or a deletion within the TTL; a shared pool sees it at once (Appendix CI L3). |
| Silent loss of stored rows | A storage holding more than `storage.max_sets` rows is an error (logged `critical`, `OverflowException`), never a silent drop that could restore a narrowed system set to its broader configuration (Appendix CI L4). The manager re-counts after storing a new row and removes it again when a concurrent create pushed the table past the bound (refused with `error.limit`), so a race cannot leave the table above `max_sets` (re-verification N2). |
| Tampered stored sets / cache | Stored keys are intersected with the catalogue on every read (uncatalogued keys grant nothing); malformed cache rows are dropped. |
| Unauthenticated / unauthorised admin access | Default `access_roles: [ROLE_ADMIN]`; a kernel request listener (priority 7, after the firewall) denies every request under the prefix and every bundle controller; mutations need `manage_roles` (default `[ROLE_ADMIN]`, **independent** of `access_roles`, so a read-only auditor role added to `access_roles` cannot edit — Appendix CI L6); empty role lists are refused at compile time; the host adds `access_control` (two layers, REQ-UI-002). Tokens are trusted only when a firewall matched the current main request. |
| Admin routes exposed by `routing.controllers` | The controller is not autoconfigured; routes exist only through the `nowo_rbac_kit` loader (REQ-SF-004 rule 7, kernel test). |
| CSRF on mutations | Every POST is a Symfony Form with CSRF: `PermissionSetType` (token id `nowo_rbac/create/new` or `nowo_rbac/edit/<code>`) and FormKit CSRF-only delete forms (`nowo_rbac/delete/<code>`); `/` cannot appear in a code, so a token of one action or set is refused by every other; invalid tokens answer 422 (forms) or 403 (delete) without writing; `CsrfProtectionPass` refuses to compile the UI without form CSRF (P2). |
| Destructive actions and resurrected codes | System sets cannot be deleted; locked sets cannot be changed. Deleting a custom set needs the usage provider (`storage.usage_provider`) and is refused while the set is assigned; a code that the usage provider still reports as assigned cannot be created, so stale host assignments never inherit a re-created set (Appendix CI L2). |
| Open redirects | Redirects are generated route paths only (`redirectToRoute`); no URL is read from the request. |
| XSS | Every value is printed with Twig auto-escaping (no `|raw`); labels through `nowo_ui_trans`; contract test asserts no inline handlers or `confirm(`. |
| SQL injection | Doctrine repository methods with bound parameters only. |
| DoS | Bounded catalogue, stored sets, assignments, memoised users and decisions; lists are bounded by those limits. |
| Demo config in production | `allow_unauthenticated: true` is refused without `kernel.debug`; env placeholders are refused for security switches. |

## Admin UI exposure and allow_unauthenticated

`security.allow_unauthenticated: true` lets anybody change who may do what: it exists for demos only, needs
`kernel.debug`, and should be combined with a loopback-bound port. Production hosts keep it `false`, set
`access_roles` / `manage_roles` (or `access_checker`), and add `access_control` for the prefix. The demo itself uses
HTTP Basic with demo-only users and binds to 127.0.0.1.

## Personal data (RGPD / GDPR)

The bundle stores no personal data except the **identifier of the last editor** of a stored set (`updated_by`, at most
180 characters, never shown in the admin pages — the contract test asserts it). Audit events carry the user object of
the request for the host's audit log. Assignments (who holds what) live in the host.

## CSRF

See the threat table: every session mutation validates a CSRF token before side effects, fail-closed (REQ-SEC-005).
No Bearer / API-token endpoint exists.

## Logging

Warnings only: sets that reference uncatalogued keys (set codes and keys) and users over `voter.max_assignments` (the
limit). Never logged: users, contexts, tokens, decisions.

## Resource limits

`catalogue.max_permissions` (≤ 20 000), `storage.max_sets` (≤ 5 000), `voter.max_assignments` (≤ 100 000), 64 users
and 2048 decisions memoised per request. No subprocess, no outbound HTTP (REQ-RUNTIME-001: see
[CONFIGURATION.md](CONFIGURATION.md#timeouts-and-blocking-work)).

## Secrets and cryptography

The bundle stores no secret and uses no cryptography. Demo passwords are documented placeholders (password = user
name) for a loopback-only demo.

## Dependencies and updates

`composer audit` runs in CI on every matrix cell; Dependabot groups Composer and GitHub Actions updates. Security fixes
are released as patch versions.

## Release security checklist (12.4.1)

| Item | Status for 1.0.0 |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` present | ✅ |
| `.env` files ignored; no secrets in the repository | ✅ (demo uses documented placeholders; root and demo `.gitignore`) |
| Safe recipe / config defaults | ✅ (empty catalogue denies; no bypass; UI and guard off; `ROLE_ADMIN`; `allow_unauthenticated: false`; the recipe ships no `security.access_control` file) |
| Input validation / output escaping | ✅ (form constraints + manager validation, route requirements, Twig auto-escape, contract test) |
| `composer audit` clean | ✅ (CI) |
| No secrets / personal data in logs | ✅ |
| Cryptography | — (none) |
| Permissions / exposure | ✅ (two layers, manage area, opt-in routes) |
| Limits / DoS | ✅ |
| AI security audit passed (REQ-SEC-004) | ⏳ First pass (Appendix CI, `8bd695a`): Fail / High; H1, M1 and L1–L6 fixed in code — audited (batch Appendix MX, Pass) before the 1.0.0 tag |

## AI security audit (REQ-SEC-004)

The bundle is not self-graded. The independent AI audit is recorded in the monorepo `BUNDLES_SECURITY_ANALYSIS.md`
(Appendix CI) and summarised here.

| Pass | Date | Revision | Grade | Findings | Fixed in |
| --- | --- | --- | --- | --- | --- |
| First pass (Appendix CI) | 2026-11-01 | `8bd695a` | Fail (High open) / High | H1 global aggregation promoted the global keys of any context-held set (owner `all: true` included); M1 untyped context ids; L1 guard let guests / strangers through; L2 re-created codes inherited stale assignments; L3 cache entry never expired; L4 rows beyond `max_sets` dropped silently; L5 memo ignored the subject of global keys and the veto state; L6 `manage_roles` defaulted to `access_roles`; I1–I7 informational | Unreleased (this branch): every High / Medium / Low fixed in code (see the threat table); informational notes below |
| Re-verification | 2026-11-02 | `570b756` | H1, M1, L1–L6 confirmed fixed; new findings open | N1 (Medium) an `aggregate` key ignored the context subject, so `filter()` / `rbac_can(key, project)` returned non-granting contexts; N2 (Low) concurrent creates could exceed `storage.max_sets` and make every check throw; N3 (Info) usage-provider contract for `code_assigned` | Unreleased (this branch): N1 the aggregate branch is decided in the subject's context; N2 the manager re-counts after saving a new row and rolls it back (`error.limit`); N3 documented on `PermissionSetUsageInterface` |
| Final re-verification | 2026-11-02 | `199d0b4` | Pass | N7 (Low) an `aggregate` key asked with a subject that does not resolve to a typed context fell back to any of the user's contexts; N8 (Info) the `max_sets` rollback deleted by code, so it could remove another manager's same-code row | Unreleased (this branch): N7 such a subject grants nothing through aggregation; N8 the rollback removes only the row the request wrote (same content) |

## Residual risks and host guidance

- The voter is only as good as the grant provider: return active memberships only, and build their context ids with
  `Assignment::in()` / `Assignment::of()` so they match the resolver (`<type>:<id>`; types are stable aliases, never
  class names).
- Gates are memoised per request for (user, roles, key, the subject's typed context id, veto state): do not make them
  depend on mutable request state, or call `PermissionVoter::reset()` when it changes. Vetoes are asked on every
  decision of a bypass role and must be cheap.
- A key flagged `aggregate: true` opens a general page when one context grants it: filter its data with
  `PermissionChecker::filter()` (or ask the key with each context as subject), which keeps only the contexts where a
  held set grants the key — the voter decides the aggregate branch in the subject's context (re-verification N1), and a
  subject that does not resolve to a typed context grants nothing through it (N7); pass context objects —
  and never flag a platform-level key (user administration, billing, exports) as aggregatable.
- `unmapped_routes: allow` and `catalogue.open_routes` open routes to everybody on `permission_route:` (menus): use
  them only for public pages (Appendix CI I5, accepted).
- With several web hosts, use a shared `storage.cache_pool` (a change is then seen at once); with a per-host pool a
  change is seen within `storage.cache_ttl` seconds.
- The manage area (`manage_roles`) is **root-equivalent over the catalogue**: a manager can add any catalogued key to
  any unlocked set, including the set they hold and `voter.fallback_permission_set`. There is no "grant only what you
  hold" rule (residual, by design for a super-admin screen): give `manage_roles` to platform administrators only, and
  mark the fallback set and every set carrying platform keys `locked: true` (Appendix CI L6).
- The request guard's `_rbac_guard: false` opt-out and `skip_strangers: true` leave those requests to the controller,
  which must then run its own `denyAccessUnlessGranted()`.
- **Cache trust (I1, residual)**: whoever can write the cache pool can grant any catalogued key (uncatalogued keys and
  malformed rows are still dropped). The cache key `nowo_rbac_kit.permission_sets.v1` is constant: give the bundle a
  dedicated pool or namespace when several applications share one Redis / Memcached.
- **Same-request memos after a change (I2, residual)**: a create / edit / delete clears the set registry, not the grant
  and decision memos of the request that made it; the next request (`kernel.reset`) sees the change.
- **User memo key (I3, residual)**: users are memoised by class + identifier; CLI / batch hosts that load two
  different users with the same class and identifier in one run must call `GrantResolver::reset()` between them.
- **Controller instances (I4, residual)**: the admin access listener recognises string and `[class, method]`
  controllers; a host route built by hand on a controller *instance* outside the prefix would skip the view check
  (mutations are re-checked by the controller). The bundle loader never builds such a route.

## Reporting a vulnerability

Email **hectorfranco@nowo.tech** (see [`.github/SECURITY.md`](../.github/SECURITY.md)). Do not open a public issue.
