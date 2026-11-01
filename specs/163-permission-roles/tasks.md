> **Imported for traceability (KIT-46).** Source specification of the application this bundle was extracted from,
> copied as is (application names, paths and tasks are the origin's). What the bundle generalises from it is mapped in
> [`../001-baseline/spec.md`](../001-baseline/spec.md#origin-specifications); the bundle code carries no application name.

# Tasks: 163 Roles as permission sets

## Block A — permission catalogue and voter (FR-001 – FR-005)

- [x] **T001** `App\Project\Permission\PermissionCatalog` (kept in the Project module; was planned as `App\Access`) from `AccessAreaCatalog` + `ProjectPermission`: keys, context, labels,
  descriptions, routes, menu items and resources; per-section view keys (`project.issues.view`,
  `project.performance.view`, `project.analytics.view`, `project.releases.view`, Settings sections) and general keys
  (`summary.view`, `reports.view`, `chat.use`, `manual.view`, …).
- [x] **T002** Coverage test: routes, Dashboard menu items and `can()` calls all name catalogued keys.
- [x] **T003** `PermissionVoter` (`permission:<key>`, project subject optional): ROLE_ADMIN bypass, union of the user's
  roles in the project (direct + groups, deactivated membership blocks), general keys aggregated over projects;
  per-request memo (`ResetInterface`) loading memberships + roles in one query.
- [x] **T004** Unit tests (union, disjoint roles, aggregation, blocked membership, admin bypass) and query-budget tests.

## Block B — roles and memberships (FR-006 – FR-010)

- [ ] **T005** Entity `ProjectRole` (table `project_role`) + seed (`viewer`, `member`, `admin`, `full` with today's
  permissions); migration of `project_membership.role` / `project_group_access.role` to `role_id`.
- [x] **T006** Administration → Project roles (list, dialog create / edit with grouped permissions, delete guard,
  audit); menu entry + breadcrumb via `app:seed-platform`.
- [x] **T007** Primary owner flag with every permission; transfer / last-owner guards on the flag
  (`project_membership.primary_owner`, stored role `full`; groups may hold `full`).
- [x] **T008** Members list: every role with its source and the resulting permissions; role pickers from the table.
  - [x] **T008a** (2026-10-26) the list: every role held (custom roles by name), sources, the union of their
    permissions; fix: a user in two linked groups showed one.
  - [x] **T008b** (2026-10-26) role pickers with custom roles (anti-escalation: assign only a role whose permissions
    you hold; administrators any), managers / adapter on role codes.
- [x] **T009** Remove instance roles / permissions (entities, pages, voters, menu entries) with a migration; tests. Spec
  `165` replaces them with the fixed `ROLE_MANAGER` / `ROLE_DEVELOPER`: ship T009 with spec `165` block A.

## Block C — pages, resources and menu links (FR-011 – FR-014)

- [ ] **T010** Request listener on `PermissionCatalog`; Twig `can()`; replace role / `ProjectPermission` checks in
  controllers and templates. *Done:* `PermissionRequestListener` (403 for a member lacking the route's key), `can()`
  (permission + the spec 162 rule until T012), the templates' `access_area()` calls moved to `can()`, the voter keeps
  the general keys for a user with no project role. *Left:* controllers that still test roles directly.
- [x] **T011** Menu checker, breadcrumbs, header search, Summary / Reports builders and chat directory on the voter,
  data scoped to the projects that grant the key. *Done:* `permission_route:<route>` for menu items, `AreaGate`
  (spec 162 rule + permission) for search, Summary widgets / report types and share links, `PermissionScope` for the
  Summary / Reports / scheduled reports / chat directory projects, crumbs without a link when the permission refuses.
- [x] **T012** Migrate spec 162 rules, drop `access_rule`, Administration → Access rules and Settings → Restrictions;
  tests per catalogued permission (hidden, 403, granted again). *Done:* `Version20261027120000` (instance-wide
  minimum-role rules → permissions removed from the lower system roles, a stored row for a seeded system role; project
  overrides and instance-role-only rules dropped), rule classes / pages / `access_area()` removed, `can()` / `AreaGate`
  / menus / crumbs on permissions only, `PermissionPerPageTest`.

## Block D — admin / developer manual (FR-015 – FR-017)

- [x] **T013** *(moved to spec `166`, done there)* ManualKit: `developer` audience and a second page-edge tab (*Technical notes*); Mermaid fenced blocks
  rendered to SVG at build (light / dark), no runtime script.
- [x] **T014** *(replaced by spec `166`: generated permissions per page in the developer-notes drawer)* Generated *Permissions* chapter from `PermissionCatalog` + test; business-logic sections with decision
  diagrams (access resolution, ingest / grouping, alerts, retention).

- [ ] **T014b** Search: header and manual search return only chapters / sections of the audiences the user may open
  (`member` + `manual.view`, `manager` for ROLE_MANAGER, `developer` for ROLE_DEVELOPER — spec `165`); tests per audience.
- [ ] **T014c** Manual structure: *Legal and privacy* to the Administration group (admin audience); Administration
  chapters Roles and permissions; Users, Projects, Plans and Billing go to the Organization group (spec `165`); en + es, anchors kept.

## Block F — account plans (FR-019 – FR-021)

- [ ] **T016** Entity `AccountPlan` + migration (seed *Standard*, `user.account_plan_id` default) and the plan guard on
  *New project* (dialog hidden, route 403, owned-projects count, ROLE_ADMIN never limited); tests.
- [ ] **T017** Organization → Plans (spec `165`; was Administration → Plans) (dialogs, delete guard, audit), plan field on Administration → Users, plan in
  Account → Profile; strings in 7 locales; tests.

## Block E — docs (FR-018)

- [ ] **T015** Manual review (en + es), `docs/product/ROLES.md`, CHANGELOG, UPGRADING (migration, removed instance roles
  and access rules), ROADMAP, strings in 7 locales.
