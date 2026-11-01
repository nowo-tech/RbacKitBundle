> **Imported for traceability (KIT-46).** Source specification of the application this bundle was extracted from,
> copied as is (application names, paths and tasks are the origin's). What the bundle generalises from it is mapped in
> [`../001-baseline/spec.md`](../001-baseline/spec.md#origin-specifications); the bundle code carries no application name.

# Tasks: 165 Organization area and platform roles

## Block A — roles (FR-001, FR-002, FR-005 – FR-008)

- [x] **T001** `SystemRole::Manager` / `Developer`, `role_hierarchy`, `access_control` for `^/organization` and `^/admin`;
  bundle admin paths and bundle role options to `ROLE_DEVELOPER` (list bundles lacking an option); tests per role.
- [x] **T002** Review every `ROLE_ADMIN` / `SystemRole::Admin` check (88 files at drafting) and set the area role;
  allow-list test of what stays `ROLE_ADMIN`.
- [x] **T003** Global roles field on Users (admin only), audit `user.roles_changed`, elevated-user guard, last-admin
  guard; unit + functional tests.

## Block B — Organization area (FR-003, FR-004, FR-009, FR-011)

- [x] **T004** `/organization` hub, menu (`menus.json`), breadcrumbs, area switcher entry, current matcher, taxonomy
  test; `app:seed-platform` on both stacks.
- [x] **T005** Move Users, Groups, Projects, Support to `/organization/*` (`organization_*` routes) with 301s from
  `/admin/*`; fix tests, fixtures and manual links.
- [x] **T006** Header / manual search and Dashboard tour steps per reachable area; demo manager and developer in the
  seeds; tests.

## Block C — notifications (FR-010)

- [x] **T007** Recipient resolution by audience (developer / manager / admin) for the existing admin notifications;
  tests. At v1.104.0 the only admin-addressed notice is chat support (now managers + admins,
  `UserRepository::findByPlatformRole()`); there is no technical admin-only alert yet — new ones use the same query.

## Block D — specs and docs (FR-012, FR-013)

- [x] **T008** Spec `164` screens target the Organization area (already noted in its tasks); gateway credentials and
  certificate in Administration.
- [x] **T009** Manual: Organization chapter group (en + es, manager audience), Administration as developer audience,
  `docs/product/ROLES.md`, CHANGELOG, UPGRADING (new roles, moved paths, who keeps what), ROADMAP; strings in 7
  locales. Done as chapter `14-organization` (audience `member`: ManualKit has one restricted audience — a third one
  is a bundle proposal for the re-audit); manual screenshots of the moved screens still show the old Administration
  aside until the next `make docs-manual-screenshots`.
