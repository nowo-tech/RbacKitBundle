# Configuration

Root key: `nowo_rbac_kit` (strict tree, REQ-SF-006). Unknown keys are rejected; invalid values fail the container
build with the path and the allowed values. Safe defaults: an empty catalogue denies every permission, nobody bypasses
it, nobody holds a set, the admin UI and the request guard are off.

## Table of contents

- [Full reference](#full-reference)
- [Catalogue](#catalogue)
- [Permission sets](#permission-sets)
- [Role grants](#role-grants)
- [Voter](#voter)
- [Grant provider and contexts](#grant-provider-and-contexts)
- [Storage](#storage)
- [Request guard](#request-guard)
- [Coverage](#coverage)
- [Admin UI](#admin-ui)
  - [Layout, CSS framework and row actions](#layout-css-framework-and-row-actions)
  - [FormKit profile](#formkit-profile)
- [Security](#security)
- [Profiles](#profiles)
- [Timeouts and blocking work](#timeouts-and-blocking-work)
- [Translations](#translations)

## Full reference

```yaml
nowo_rbac_kit:
    catalogue:
        translation_domain: messages      # labels: permission.<key>.label / .description, permission_set.<code>.label
        max_permissions: 2000             # 1..20000; more fails the catalogue (configuration + providers)
        permissions:                      # key => definition; key: lowercase segments separated by . - :
            reports.view:
                scope: global             # global | context
                routes: [app_reports]     # routes guarded by this key (a route belongs to one key only)
                group: general            # admin catalogue column (optional)
                label: ~                  # translation key (default permission.<key>.label)
                description: ~            # translation key (default permission.<key>.description)
                aggregate: false          # global keys only: also granted when one of the user's contexts grants it
        aliases: {}                       # area alias => key (canArea(), rbac_can_area())
        open_routes: []                   # routes permission_route: always grants and coverage ignores
        unmapped_routes: deny             # deny | allow: permission_route: on a route no key guards
    permission_sets:                      # system sets: code => definition (code ^[a-z][a-z0-9_]{1,39}$)
        viewer:
            label: ~                      # translation key (default permission_set.<code>.label)
            description: ~
            permissions: [reports.view]
            all: false                    # true = every catalogued *context* key (minus except)
            include_global: false         # with all: true, also every global key (sets assigned globally only)
            except: []
            locked: false                 # true = not editable from the admin UI; stored edits ignored
    role_grants: {}                       # ROLE_* => global keys the role holds (after the role hierarchy)
    voter:
        bypass_roles: []                  # ROLE_* that pass every permission (default: nobody)
        fallback_permission_set: ~        # set whose global keys a user without any assignment holds
        max_assignments: 5000             # assignments read per user and request (1..100000)
    grant_provider: ~                     # service id (GrantProviderInterface); null = nobody holds a set
    context_resolver: ~                   # service id (ContextResolverInterface); null = RbacContextInterface subjects
    storage:
        type: config                      # config (read-only) | doctrine | service
        service: ~                        # PermissionSetStorageInterface service id (type: service)
        cache_pool: cache.app             # pool of the stored rows (null = read the storage once per request)
        cache_ttl: 300                    # seconds the cached rows live (1..86400)
        max_sets: 200                     # stored rows (1..5000); more rows in the storage is an error
        usage_provider: ~                 # PermissionSetUsageInterface service id; needed to delete custom sets
    doctrine:
        table_prefix: ''                  # letters, digits, underscores (REQ-ORM-002)
        entity_manager: ~                 # 1.1: dedicated manager of PermissionSetRecord (null: default manager)
    request_guard:
        enabled: false                    # 403 before the controller on a catalogued route without its key
        context_resolver: ~               # RbacRequestContextResolverInterface service id (1.2; or the deprecated RequestContextResolverInterface)
        skip_strangers: false             # true = strangers / unresolved contexts left to the controller
        context_headers: []               # 1.2: headers copied into the RbacRequest (e.g. x-tenant); credential headers refused
    coverage:
        prefixes: []                      # default path prefixes of nowo:rbac:check
        ignore_routes: []
    web_ui:
        enabled: false                    # register the admin controllers (routes still need the route import)
        path_prefix: /admin/rbac
        layout_template: ~                # null = nowo_ui_kit.layouts.admin
        templates: {}                     # index | catalogue | form => template
    security:
        access_roles: [ROLE_ADMIN]        # read-only pages (any of them); never empty without access_checker
        manage_roles: [ROLE_ADMIN]        # create / edit / delete (independent of access_roles); never empty
        access_checker: ~                 # RbacAdminAccessCheckerInterface service id
        allow_unauthenticated: false      # DEV / DEMO only: refused without kernel.debug
```

## Catalogue

Every permission a page, a page resource (tab, card, button, report type) or a menu link can ask for. The bundle merges
`catalogue.permissions` with every service implementing `PermissionProviderInterface` (autoconfigured with the
`nowo_rbac_kit.permission_provider` tag), so modules can contribute their own keys. The merged catalogue is checked when
it is first read in a request: a key defined twice with different definitions, a route guarded by two keys, an alias to
an unknown key or more than `max_permissions` entries throw a `LogicException` — a catalogue that cannot be trusted
never grants.

- `scope: global` — asked without a subject (dashboards, reports, chat, global actions).
- `scope: context` — asked with the context subject (`isGranted('permission:project.view', $project)`); asked without
  one, it is refused.
- `aggregate: true` (global keys only) — asked without a subject, the key is also granted when a set the user holds in
  **any** context contains it (the general page opens); asked with a context subject — `PermissionChecker::filter()`,
  `rbac_can(key, project)` — only the sets held in **that** context count, so the page shows the data of the granting
  contexts only. A subject that does not resolve to a typed context grants nothing through aggregation.
  Default `false`: a set held in a context never grants a global key. Never flag platform keys (user administration,
  billing, exports): every member of any context would hold them.

`open_routes` are the always-open pages (home, account, logout): `permission_route:` grants them and the coverage
check ignores them. `unmapped_routes: deny` (default) hides menu links to routes the catalogue does not know; set
`allow` only if you check those routes elsewhere.

## Permission sets

A permission set is a "role": a named collection of keys. System sets come from the configuration; with a writable
storage the admin UI can edit them (the first edit stores a row that replaces the configured keys) and create custom
sets. `locked` sets are never edited (an owner set, for example `all: true, locked: true`). `all: true` holds every
catalogued **context** key (minus `except`), so an owner set held in a project never carries platform-wide keys;
`include_global: true` adds the global keys for a set meant to be assigned globally. Keys that are not
catalogued are dropped when the sets are read (they grant nothing; a warning is logged once per request) and reported
by `nowo:rbac:check`.

## Role grants

`role_grants` maps a Symfony role (after `security.role_hierarchy`) to **global** keys it holds whatever the user's sets
— for platform roles such as `ROLE_MANAGER` managing the wiki. Each entry must list at least one key. Context keys are
never granted by a role: only sets held in the context grant them.

## Voter

| Option | Default | Effect |
| --- | --- | --- |
| `bypass_roles` | `[]` | Superuser roles that pass every catalogued key (unknown keys and gates still deny). A `BypassVetoInterface` service can suspend it for one decision ("view as member"). |
| `fallback_permission_set` | `null` | The global keys of this set are held by users without any assignment (a brand-new account keeps the general pages). Must name a configured set. |
| `max_assignments` | `5000` | Assignments read per user and request; the rest are ignored and logged (bounded memo). |

## Grant provider and contexts

`grant_provider` names your `GrantProviderInterface` service: it returns the `Assignment`s a user holds — direct
memberships, group links — built with `Assignment::in('owner', 'project', $id)`, `Assignment::of('owner', $project)`
or `Assignment::global('auditor')`. It is called once per user and request.

Context ids are **typed**: `<type>:<id>` (`project:5`, `organization:5`), so two context classes sharing an id are two
contexts. `context_resolver` maps a voter subject to its typed id; the default accepts objects implementing
`RbacContextInterface` (type `^[a-z][a-z0-9_]{0,39}$`, a stable alias — never a class name; non-empty id of at most 191
characters) and refuses everything else. A custom resolver must return `ContextId::of($type, $id)`; an untyped id is
refused by the voter, and `Assignment` throws on one.

## Storage

| Type | Behaviour |
| --- | --- |
| `config` (default) | Read-only: only the configured sets; the admin UI lists them without create / edit / delete. |
| `doctrine` | Entity `PermissionSetRecord`, table `rbac_permission_set` (+ `doctrine.table_prefix`, unique code). Needs `doctrine/orm` and `doctrine/doctrine-bundle`; the mapping is prepended (on `doctrine.entity_manager` when set, 1.1: that manager only). Writes are DBAL statements (no `flush()`, SAVEPOINT inside an open transaction). Create the table with a migration. |
| `service` | Your `PermissionSetStorageInterface` service (`storage.service`). |

The stored rows are read once per request and cached in `cache_pool` (whole table, invalidated after every change on
the host that made it, expiring after `cache_ttl` seconds); use a pool shared by every web host so a change is seen at
once, otherwise within `cache_ttl`. A storage holding more than `max_sets` rows is an error (`OverflowException`,
logged `critical`): rows are never dropped silently. `usage_provider` returns the assignment count of each set: the
admin list shows it, a set in use cannot be deleted and a code that is still assigned cannot be created. Without a
usage provider custom sets cannot be deleted (a deleted code's old assignments would otherwise inherit a re-created
set).

## Request guard

`request_guard.enabled: true` registers a `kernel.controller` listener: a main request to a catalogued route is refused
with the firewall's 403 when the user lacks the route's key, and a guest gets the firewall's entry point (login / 401).
For a context key the `request_guard.context_resolver` service returns the request's context; users holding no set in
it — and requests whose context is not resolved — are refused too. Implement `RbacRequestContextResolverInterface`
(1.2, framework-free `RbacRequest`); the deprecated `RequestContextResolverInterface` (Symfony `Request`) still works
until 2.0, and a service implementing both is called through the new one. A service implementing neither fails the
container build.

- `context_headers` (1.2, default `[]`): request headers copied into the `RbacRequest` (lower-cased, e.g. `x-tenant`
  for a tenant chosen by header). `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `Forwarded`,
  `X-Forwarded-*`, `X-Real-IP`, `X-Original-URL`, `X-Rewrite-URL`, `Referer`, `SSL-Client-Cert` and any name containing
  token, secret, key, auth, session, signature, pass, pwd, cookie, csrf, jwt, bearer, credential, cert, hmac, otp or
  oidc are refused (configuration error). Cookies, the query string and the body are never copied. Header values are
  client-controlled: never let one override the context your controller reads from the route.

- `skip_strangers: true` leaves strangers and unresolved contexts to the controller (its 404 existence mask);
  members without the key are still refused.
- The route default `_rbac_guard: false` leaves one route to its controller (share links, public pages):
  `#[Route('/share/{token}', defaults: ['_rbac_guard' => false])]`. That controller must check access itself.

Requires SecurityBundle.

## Coverage

`nowo:rbac:check [--prefix=/dashboard ...] [--ignore=route ...]` fails (exit 1) when a route under the prefixes has no
key (and is not an open route), a catalogue route does not exist, a configured set names an uncatalogued key, or the
catalogue is empty. `coverage.prefixes` / `coverage.ignore_routes` are its defaults. Use `PermissionCoverage::report()`
in a host test for the same check.

## Admin UI

`web_ui.enabled: true` registers the controllers; the routes are opt-in (REQ-SF-004 rule 7): import them with the
`nowo_rbac_kit` loader (the Flex recipe adds `config/routes/nowo_rbac_kit.yaml`). Pages: permission sets (list),
catalogue (read-only), create / edit form. Mutations need the manage area and a writable storage; on `config` storage
they answer 404.

### Layout, CSS framework and row actions

The pages extend UiKit scaffolds and use the UiKit layout `nowo_ui_kit.layouts.admin` (or `web_ui.layout_template`).
CSS framework, icons and row action display are UiKit settings (`nowo_ui_kit.css_framework`, `icon_set`,
`row_actions_display`); per-bundle exceptions go under `nowo_ui_kit.theme.bundles.NowoRbacKitBundle`. See
[TEMPLATES.md](TEMPLATES.md).

### FormKit profile

`PermissionSetType` uses the FormKit profile `rbac_kit` (bundle translation domain, no auto help / placeholder, no
framework classes), prepended unless the host defines it; the delete buttons are FormKit CSRF-only forms. Override the
profile in the host:

```yaml
nowo_form_kit:
    profiles:
        rbac_kit:
            translation_domain: NowoRbacKitBundle
            defaults: { attr: { class: 'form-control' }, row_attr: {} }
```

## Security

REQ-UI-002: `security.access_roles` (default `[ROLE_ADMIN]`) opens the read-only pages, `security.manage_roles` (default
`[ROLE_ADMIN]`, independent of the access roles) the mutations — the manage area is root-equivalent over the catalogue,
so keep it for platform administrators; an empty role list is refused at compile time; `security.access_checker` replaces the
role check with your `RbacAdminAccessCheckerInterface`. Protect `web_ui.path_prefix` with `access_control` too.
`allow_unauthenticated: true` is for demos only (refused unless `kernel.debug`). `web_ui.enabled`,
`allow_unauthenticated`, `request_guard.enabled` and `request_guard.skip_strangers` must be literal booleans (an `%env(bool:…)%` placeholder is refused: it would read as true while the container is built).

## Profiles

Not applicable (REQ-CFG-001): the bundle has one catalogue and one set of sets per application.

## Timeouts and blocking work

The bundle starts no subprocess and makes no outbound HTTP call. Its work is bounded per request:

| Work | Bound |
| --- | --- |
| Catalogue | `catalogue.max_permissions` entries, built once per request. |
| Stored sets | `storage.max_sets` rows, one query (or one cache read) per request. |
| Grants | one provider call per user, `voter.max_assignments` assignments, 64 users memoised. |
| Decisions | 2048 memoised per request (cleared when full). |
| Admin pages | the list and the catalogue are bounded by `max_sets` and `max_permissions`. |

## Translations

UI copy uses the `NowoRbacKitBundle` domain in 7 locales (en, es, fr, de, it, pt, nl). Permission and system set labels
use `catalogue.translation_domain` (default `messages`): `permission.<key>.label`, `permission.<key>.description`,
`permission_set.<code>.label`. Override any bundle key in `translations/NowoRbacKitBundle.<locale>.yaml`.
