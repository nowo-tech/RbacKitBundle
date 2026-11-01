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
| Context confusion (permission of project A used in project B) | Context keys are granted only by sets held **in that context** (context id from `RbacContextInterface` / the resolver); objects that are not contexts are refused; global assignments and role grants never grant context keys. |
| Stale or cross-user grants in worker mode | Memos keyed by user class + identifier (+ reachable roles for decisions), cleared by `kernel.reset` every request, bounded (see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)). |
| Tampered stored sets / cache | Stored keys are intersected with the catalogue on every read (uncatalogued keys grant nothing); malformed cache rows are dropped. |
| Unauthenticated / unauthorised admin access | Default `access_roles: [ROLE_ADMIN]`; a kernel request listener (priority 7, after the firewall) denies every request under the prefix and every bundle controller; mutations also need `manage_roles`; empty role lists are refused at compile time; the host adds `access_control` (two layers, REQ-UI-002). Tokens are trusted only when a firewall matched the current main request. |
| Admin routes exposed by `routing.controllers` | The controller is not autoconfigured; routes exist only through the `nowo_rbac_kit` loader (REQ-SF-004 rule 7, kernel test). |
| CSRF on mutations | Every POST is a Symfony Form with CSRF: `PermissionSetType` (token id `nowo_rbac/create/new` or `nowo_rbac/edit/<code>`) and FormKit CSRF-only delete forms (`nowo_rbac/delete/<code>`); `/` cannot appear in a code, so a token of one action or set is refused by every other; invalid tokens answer 422 (forms) or 403 (delete) without writing; `CsrfProtectionPass` refuses to compile the UI without form CSRF (P2). |
| Destructive actions | System sets cannot be deleted; locked sets cannot be changed; a set in use (usage provider) cannot be deleted; a deleted custom set grants nothing any more (fail closed). |
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
| AI security audit passed (REQ-SEC-004) | ⏳ Pending — independent audit requested before the 1.0.0 tag |

## AI security audit (REQ-SEC-004)

The bundle is not self-graded. The independent AI audit will be recorded in the monorepo `BUNDLES_SECURITY_ANALYSIS.md`
and summarised here before the 1.0.0 tag.

| Pass | Date | Revision | Grade | Findings | Fixed in |
| --- | --- | --- | --- | --- | --- |
| First pass | — | — | Pending | — | — |

## Residual risks and host guidance

- The voter is only as good as the grant provider: return active memberships only, and keep the context id stable
  (the same value in `Assignment::$contextId` and `getRbacContextId()`).
- Gates and bypass vetoes are memoised per request for (user, key, context): do not make them depend on anything else.
- With `global_aggregation: true` a general page is open when one context grants its key: filter its data with
  `PermissionChecker::filter()` so it shows only the granting contexts.
- `unmapped_routes: allow` and `catalogue.open_routes` open routes to everybody on `permission_route:` (menus): use
  them only for public pages.
- With several web hosts, use a shared `storage.cache_pool`, or an edit is seen per host when its cache entry expires.

## Reporting a vulnerability

Email **hectorfranco@nowo.tech** (see [`.github/SECURITY.md`](../.github/SECURITY.md)). Do not open a public issue.
