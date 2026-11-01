> **Imported for traceability (KIT-46).** Source specification of the application this bundle was extracted from,
> copied as is (application names, paths and tasks are the origin's). What the bundle generalises from it is mapped in
> [`../001-baseline/spec.md`](../001-baseline/spec.md#origin-specifications); the bundle code carries no application name.

# Feature Specification: Roles as permission sets, checked on every page, resource and menu link

**Feature Branch**: `163-permission-roles`  
**Created**: 2026-10-24  
**Status**: Approved (2026-10-24)  
**Roadmap**: ROADMAP *Next* — "Roles as permission sets" (user requests 2026-10-24, translated from Spanish: "roles should be collections of permissions; two roles may not be comparable by access level"; "every page, page resource or menu link must check whether the user's role in that location, general or project, has that permission enabled or not"; "we would use only 3 Symfony ROLE_*: ROLE_ADMIN, ROLE_USER and ROLE_PUBLIC … ROLE_USER users that can have projects hold different roles, defined in a table, for one specific project, and those roles are a collection of permissions"; "B, although they see the summary if at least one project allows it (and they only see that project), the same for reports, chat, Manual"; manual tabs: "we should have manual tabs for administrators and/or developers stating which permissions are used and where, plus business logic … including Mermaid decision diagrams")  
**Cross-refs**: spec `162` (access rules — replaced), spec `087` (`ProjectPermission`), spec `088` (`full` role), spec `161` (in-app manual), `App\Project\Enum\ProjectRole`, `App\Project\Security\ProjectPermission`, `App\Project\Security\ProjectPermissionVoter`, `App\Project\AccessRule\AccessAreaCatalog`, `App\Identity\Entity\InstanceRole`, `App\Identity\Security\InstancePermissionVoter`, `App\Shared\Menu\SecurityIsGrantedMenuPermissionChecker`, OTHER REQ-RBAC-003 / REQ-RBAC-007, REQ-UIKIT-015

## Is it viable?

Yes. Every route already belongs to an access area (`AccessAreaCatalog`, with a coverage test), navigation already asks
one voter per area (spec 162), and project permissions already exist as keys (`ProjectPermission`). What changes is the
source of truth: a **role is a row with a set of permission keys**, a user's permissions in a project are the **union**
of the roles they hold there (direct membership + linked groups), and nothing compares roles by level any more. The
access-rule layer of spec 162 becomes unnecessary: restricting *Performance* to some people is removing
`project.performance.view` from the roles that should not see it.

## Clarifications (2026-10-24, user answers and design defaults)

| Question | Answer / proposal |
|---|---|
| Symfony roles | Only `ROLE_ADMIN` (instance administrators), `ROLE_USER` (signed in) and `PUBLIC_ACCESS` (Symfony's built-in attribute for guests — the user's "ROLE_PUBLIC"; already used in `security.yaml`) — user answer. Custom instance `ROLE_*` roles (Administration → Roles, `InstanceRole`, `InstancePermission`) are removed. **Amended 2026-10-25 by spec `165`**: two more platform roles, `ROLE_MANAGER` (Organization area) and `ROLE_DEVELOPER` (Administration area), with `ROLE_ADMIN` inheriting both; project data still only through project roles. |
| What a role is | A row of a `project_role` table: code, name, description, `system` flag, and a set of permission keys. Two roles need not be comparable — user answer. |
| Where roles apply | Per project: a membership (a user, or a group linked to the project) holds a role in that project — user answer. A user's permissions in a project = the union of the permissions of every role they hold there (direct + each group). No "highest role wins". |
| General pages (outside a project) | Option **B** with aggregation — user answer: Summary, Reports, Chat and Manual (and the other Dashboard areas) are shown when **at least one** of the user's projects grants the matching permission, and they show data of **those projects only**. Instance administrators see everything. |
| Instance administrators | `ROLE_ADMIN` passes every permission everywhere (proposal; *view as member* keeps previewing a project with the user's own roles). Administration stays `ROLE_ADMIN` (spec `165`: Administration becomes `ROLE_DEVELOPER`, Users / Groups / Projects move to the Organization area, `ROLE_MANAGER`; only `ROLE_ADMIN` keeps the bypass). |
| Owner | Default (2026-10-24, not objected): "primary owner" becomes a **flag on the direct membership** (transfer ownership, last-owner guard, always reaches the project's settings), not a role; the owner holds every project permission. |
| Who creates projects | Any signed-in user **according to their account plan** — user answer 2026-10-24 ("Any user, but depending on their membership" → "A plan or account level of the user"). A plan says whether its users may create projects and how many projects they may own (empty = unlimited). Seeded plan *Standard* (create allowed, unlimited) for every existing and new user, so nothing changes until an administrator adds plans. No billing. |
| Seeded roles | `viewer`, `member`, `admin`, `full` as `system` roles with exactly today's permissions (+ the new per-section view keys), so nothing changes after the migration. System roles can be edited, not deleted. |
| Permission catalogue | In code, one key per page, page resource (tab, widget, report type, button) and menu link — the `AccessAreaCatalog` areas become permission keys (`project.performance.view`, `project.export.issues`, `summary.view`…). A test fails when a route, menu item or catalogued resource has no key. |
| Groups | Default (2026-10-24, not objected): a group linked to a project may hold any project role (today only admin / member / viewer). |
| Spec 162 access rules | Replaced: rules with a minimum project role migrate to "remove the permission from the roles below it"; instance-role conditions cannot map and are dropped with an UPGRADING note. Settings → Restrictions is **dropped** (default 2026-10-24, not objected): roles are defined once for the instance; a project that needs something else gets another role. |
| No project role at all (T010) | Default (2026-10-26): a user who holds no role in any project (a new account, or every membership deactivated) keeps the general pages (Summary, Reports, Chat, Manual, New project…), which show no project data; once they hold a role, the roles decide. Without it a new account could not even create its first project. |
| Strangers on a project route (T010) | Default (2026-10-26): the request listener only answers 403 to a user who holds a role in the route's project; strangers, share-link visitors and administrators outside their projects keep the controller's answer (the 404 existence mask, the share-link scope, administrator access). |
| Admin / developer manual | A second page-edge tab (*Technical notes*, administrators + GitHub) per page: the permissions the page and each of its resources use (a table **generated from the catalogue**), business logic where it matters, and Mermaid decision diagrams rendered to SVG when the manual is built (no runtime script; CSP-safe; light / dark). |

## User scenarios

1. **A reporting role.** An administrator creates the project role *Reporting* with `project.view`,
   `project.reports.view` and `project.export.issues`, and gives it to Ana in project *Billing*. Ana sees *Billing* in
   the dashboard, its Issues list without bulk actions, the Summary and Reports with data of *Billing* only, and the
   export menus; she has no Settings tab.
2. **Incomparable roles.** Luis is *Triage* (status / assignee / priority, no exports) in *Billing* through a group and
   *Reporting* directly: his permissions in *Billing* are the union — he can triage **and** export.
3. **Hide Performance.** Removing `project.performance.view` from the `viewer` role hides the Performance tab, its
   Summary widgets and report type, its breadcrumb links and search results for every viewer; the URL answers 403.
4. **General pages by aggregation.** Marta's only project role that grants `chat.use` is in *Mobile*: she has the chat,
   and can talk with people of *Mobile* only. Without any project granting `summary.view` she has no Summary entry.
5. **Developer notes.** On *Settings → Access*, an administrator opens the *Technical notes* tab: a table lists every
   permission the page uses (page, each card, each button) and a diagram shows how a user's access is resolved.

## Requirements

### Block A — permission catalogue and voter

- **FR-001** `App\Access\PermissionCatalog` (replacing `AccessAreaCatalog` + the access part of `ProjectPermission`):
  every permission key with its context (`project` / `general`), label key, description key, the routes it guards and
  the menu items and page resources (tab, widget, report type, action) that use it.
- **FR-002** Coverage test: every route under `/dashboard`, `/projects`, `/chat`, `/manual` and every Dashboard menu
  item maps to a permission (or to the always-open list: home, project overview, account); every template call to
  `can()` names a catalogued key.
- **FR-003** One voter, `permission:<key>` (subject: the `Project` for project keys; none for general keys):
  `ROLE_ADMIN` passes; a project key passes when any role the user holds in that project (direct + groups, active
  membership only — a deactivated direct membership blocks, as today) has the key; a general key passes when it does in
  at least one of the user's projects.
- **FR-004** `ProjectPermissionVoter`, `AccessRuleVoter` and `InstancePermissionVoter` are replaced by this voter;
  `is_granted('ROLE_ADMIN')` stays only for Administration and the superuser bypass.
- **FR-005** Permissions of a request are loaded once (the user's roles in every project + the role table, cached and
  reset between requests, FrankenPHP worker mode); a page with N projects checks N projects without N queries.

### Block B — roles and memberships

- **FR-006** Table `project_role` (code, name, description, system, permissions JSON) seeded with `viewer`, `member`,
  `admin`, `full` (today's permissions + the view keys); `project_membership.role_id` and `project_group_access.role_id`
  replace the `ProjectRole` enum column (migration keeps every member's current role).
- **FR-007** Administration → Project roles: list, create, edit (name, description, permissions grouped by area, with
  each key's description), delete (refused while a membership uses it); system roles cannot be deleted. Audited.
- **FR-008** Primary owner as a membership flag holding every project permission; transfer and last-owner guards on the
  flag.
- **FR-009** Settings → Access → Members shows every role a person holds in the project with its source and the
  resulting permissions (no "effective role" by level); role pickers list the project roles.
- **FR-010** Instance roles and instance permissions (`InstanceRole`, `InstancePermission`, Administration → Roles /
  Permissions, `role_user`) are removed; UPGRADING lists what an instance loses.

### Block C — every page, resource and menu link

- **FR-011** Routes: the request listener refuses a route whose permission the user lacks (403 page / JSON), before the
  controller.
- **FR-012** Twig `can('key', project = null)` for every resource: project tabs, Settings sections, cards, buttons and
  menus of actions, Summary widgets, report types; templates no longer test roles.
- **FR-013** Menus: the DashboardMenu permission checker asks `permission:<key>` for every item (general keys
  aggregated); breadcrumbs, header search, the Summary / Reports builders and the chat directory restrict their data to
  the projects that grant the key.
- **FR-014** Migration of spec 162 rules (see *Clarifications*), then the `access_rule` table and its pages go.

### Block D — admin / developer manual

- **FR-015** A manual audience `developer` (administrators in-app, always on GitHub) and a *Technical notes* page-edge
  tab next to the manual tab on every page that has notes.
- **FR-016** A generated *Permissions* chapter: per page, the permissions the page and each catalogued resource use,
  their context and the seeded roles that hold them — built from `PermissionCatalog`, checked by a test.
- **FR-016b** The header search and the manual's own search only return chapters and sections the user may open —
  user request 2026-10-24 ("the search must only find the manuals we have access to"): audience
  `member` with `manual.view`, `admin` / `developer` (Administration, Legal and privacy, Technical notes) for
  `ROLE_ADMIN` only; a test per audience.
- **FR-016c** Manual structure (user request 2026-10-24): *Legal and privacy* moves to the Administration group
  (administrators only); the Administration group gets its own chapters — Users, Roles and permissions, Projects,
  Plans and membership, Billing (the last two with spec `164`) — besides Administration and operations.
- **FR-017** Business-logic sections where needed (access resolution, ingest and grouping, alert life cycle,
  retention), with Mermaid decision diagrams rendered to SVG at manual build time (ManualKit feature), light and dark.

### Block F — account plans (hook into spec `164`)

- **FR-019** Table `account_plan` (code, name, description, `can_create_projects`, `max_owned_projects` nullable) seeded
  with *Standard* (allowed, unlimited, default); every user has a plan (`user.account_plan_id`, default *Standard*).
- **FR-020** *New project* (dashboard dialog and route) is offered and accepted only when the user's plan allows it and
  they own fewer projects than its limit; otherwise the button is hidden and the route answers 403 with a message naming
  the plan. `ROLE_ADMIN` is never limited.
- **FR-021** Administration → Plans (list, dialog create / edit, delete refused while users have it, audited) and a
  *Plan* field on Administration → Users (dialog); Account → Profile shows the user's plan and its limits.

### Block E — docs

- **FR-018** Manual (en + es) review of every chapter for the new model, `docs/product/ROLES.md` rewritten, CHANGELOG,
  UPGRADING (migration, removed instance roles and access rules), ROADMAP; strings in 7 locales.

## Success criteria

- **SC-001** After the migration every existing functional and e2e test passes with the seeded roles (behaviour
  unchanged).
- **SC-002** Permission checks add at most **1** query per request (budget tests on the dashboard, an issue list and the
  Summary).
- **SC-003** For every catalogued permission: hidden where it is linked, 403 on its route, shown again once granted.
- **SC-004** Two roles with disjoint permissions held by one user give the union (tests); no code path compares roles by
  level (`rank()` removed).
- **SC-005** The permissions chapter matches the catalogue (test); Mermaid diagrams render in-app without a script.
- **SC-006** 100% statement coverage; `manual-translations` green.
- **SC-007** A user whose plan forbids creating projects, or who reached its limit, has no *New project* control and
  gets 403 on the route; the seeded *Standard* plan changes nothing for existing users (tests).

## Out of scope (v1)

- Per-user permission exceptions; time- or IP-based rules.
- Delegated Administration (non-admins opening parts of `/admin`).
- Permissions for Read-API tokens, ingest DSNs and share links (they keep their own scopes).
