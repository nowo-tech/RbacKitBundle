> **Imported for traceability (KIT-46).** Source specification of the application this bundle was extracted from,
> copied as is (application names, paths and tasks are the origin's). What the bundle generalises from it is mapped in
> [`../001-baseline/spec.md`](../001-baseline/spec.md#origin-specifications); the bundle code carries no application name.

# Feature Specification: Organization area and platform roles (manager / developer)

**Feature Branch**: `165-organization-area`  
**Created**: 2026-10-25  
**Status**: Implemented (2026-10-25, v1.104.0)  
**Roadmap**: ROADMAP *Next* — "Organization area and platform roles" (user requests 2026-10-25, translated from Spanish: "maybe we need another section besides admin, dashboard and account, one for profiles that only manage users, spend, accounts, accounting, subscriptions… because admin has many sensitive development parts that a less trained profile must not touch, right?"; "maybe we should have ROLE_DEVELOPER and ROLE_ADMIN or ROLE_MANAGEMENT"; "do it")  
**Cross-refs**: spec `163` (roles as permission sets — this spec amends its "only ROLE_ADMIN / ROLE_USER" clarification, T009 and T014b / T014c), spec `164` (plans and billing move to the Organization area), `App\Identity\Security\SystemRole`, `config/packages/security.yaml` (`role_hierarchy`, `access_control`), `AdministrationHub`, `src/Setup/Demo/fixtures/menus.json`, OTHER REQ-UIKIT-015 (dialogs)

## Why

Administration mixes two audiences: people who run the business (users, groups, plans, spend, invoices) and people who
run the platform (Messenger, ops, mailer, access rules, project roles, backups, self-test). Giving `ROLE_ADMIN` to
someone from finance or HR so they can invite a user also hands them the sensitive technical screens. Two coarse
Symfony roles, guarded by path in `access_control`, separate them cheaply and keep a second guard below each
controller's own check.

## Clarifications (2026-10-25, design defaults)

| Question | Proposal |
|---|---|
| Symfony roles | `PUBLIC_ACCESS` (guests), `ROLE_USER` (signed in), **`ROLE_MANAGER`** (Organization area), **`ROLE_DEVELOPER`** (Administration area), `ROLE_ADMIN` (both areas + global role changes). Named after the holder, hence `ROLE_MANAGER` rather than "ROLE_MANAGEMENT". |
| Hierarchy | `ROLE_ADMIN: [ROLE_MANAGER, ROLE_DEVELOPER]`, `ROLE_MANAGER: ROLE_USER`, `ROLE_DEVELOPER: ROLE_USER`. Manager and developer are independent (no levels between them); a user may hold both. |
| Project data | Unchanged from spec `163`: inside projects only project roles count. Only `ROLE_ADMIN` keeps the superuser bypass in `PermissionVoter`; manager and developer see a project only through their memberships. |
| Organization area | New `/organization` area next to Dashboard, Account and Administration: **Users**, **Groups**, **Projects** (ownership, transfer, quotas), **Support** requests, and with spec `164` **Plans**, **Billing accounts**, **Subscriptions**, **Usage and spend**, **Invoices**, **Invoicing data** (issuer tax data, series). |
| Administration area | **Development** at `/developer` (user request 2026-10-25: "better move administration to developer … the prefix uses /developer and in the user dropdown call it developer or development"), `ROLE_DEVELOPER`; old `/admin/…` paths 301 there (people screens to `/organization`): Messenger, ops / ops defaults / ops seed, instance config, mailer / Resend / emails log / email templates, Mercure, social login, appearance, cookie consent, UI kit gallery, access rules, project roles, report subscriptions, Beacon self-test, backups, maintenance, API doc / routing / HTTP log, payment gateway credentials and the invoicing certificate (secrets). |
| Legal and privacy | Stays in Administration (spec `163` T014c). |
| View as member | `ROLE_ADMIN` only: it previews a project without the superuser bypass, which only admins hold. |
| Global role changes | Only `ROLE_ADMIN` grants or removes `ROLE_MANAGER`, `ROLE_DEVELOPER` and `ROLE_ADMIN`, from Organization → Users (the field is hidden for managers). The last active `ROLE_ADMIN` cannot lose the role, be blocked or be deleted. |
| What a manager may do to users | Invite, edit profile data, block / unblock, delete, reset 2FA, add to groups, set the plan — for users without an elevated role. A user holding `ROLE_MANAGER`, `ROLE_DEVELOPER` or `ROLE_ADMIN` is read-only for a manager who is not `ROLE_ADMIN` (no lock-out of the technical team, no self-promotion). |
| Instance roles | Spec `163` T009 removes the custom `InstanceRole` / `InstancePermission` catalogue; the three fixed roles above take its place. No organization-role table in v1; finer splits (billing only, users only) later as permissions inside the area. |
| Existing data | Current `ROLE_ADMIN` users keep it (they get both areas). Custom instance roles are dropped by T009's migration, listed first by a dry-run report in UPGRADING. |
| Notifications | Technical alerts (self-test, ops, Messenger failures, deploy / version) go to `ROLE_DEVELOPER`; business ones (new sign-ups, billing failures, quota overages from `164`) to `ROLE_MANAGER`; `ROLE_ADMIN` gets both. |
| Navigation | The header area switcher, the hubs, breadcrumbs and menus show only the areas the user may open; `/admin` and `/organization` answer 403 otherwise. Legacy `/admin/users`, `/admin/groups`, `/admin/projects`, `/admin/support` 301 to `/organization/…` (same guard, no anonymous probing). |
| Manual | New *Organization* chapter group (manager audience: Users, Groups, Projects, Plans and billing); Administration chapters become the developer audience; header and manual search follow (spec `163` T014b). |
| Bundles | Bundle admin UIs that check `ROLE_ADMIN` internally (SiteBackup, MaintenanceMode, RoutingKit, HTTP log, API doc) are configured to `ROLE_DEVELOPER` where the bundle allows it; a bundle without a role option gets an upstream option (approval needed to publish). |

## User scenarios

1. A finance manager holds `ROLE_MANAGER`. They open Organization, invite a user, put them in the *Support* group and
   look at this month's spend. The header has no Administration entry; `/admin/messenger` answers 403.
2. A developer holds `ROLE_DEVELOPER`. They retry failed Messenger messages and edit a project role. The header has no
   Organization entry; `/organization/users` answers 403.
3. A manager tries to block a developer: the row is read-only and the route refuses the change.
4. An admin promotes a manager to `ROLE_DEVELOPER` from Organization → Users; the change is audited. Trying to remove
   their own `ROLE_ADMIN` while they are the last admin is refused with a clear message.

## Requirements

- **FR-001** `SystemRole` gains `Manager = 'ROLE_MANAGER'` and `Developer = 'ROLE_DEVELOPER'`; `security.yaml`
  `role_hierarchy` as in the clarifications.
- **FR-002** `access_control`: `^/organization` → `ROLE_MANAGER`, `^/admin` (and bundle admin paths) →
  `ROLE_DEVELOPER`; every controller keeps its own `#[IsGranted]` on the matching role.
- **FR-003** Organization area shell: `/organization` hub (cards like `AdministrationHub`), its menu and breadcrumbs,
  its manual tab.
- **FR-004** Users, Groups, Projects and Support move from `/admin/*` to `/organization/*` with 301s from the old
  paths (GET). Route names stay `admin_users*`, `admin_groups*`, `admin_projects*`, `admin_support_*` (internal ids,
  used by menus, breadcrumbs, hub cards and ~60 tests); only `organization_hub` is new.
- **FR-005** Global roles on Organization → Users: a multi-select of manager / developer / admin, visible and
  submittable only by `ROLE_ADMIN`; audited (`user.roles_changed` with before / after).
- **FR-006** Elevated-user guard: a non-admin manager cannot change, block, delete or reset a user holding an elevated
  role (hidden controls, 403 on the routes).
- **FR-007** Last-admin guard: removing `ROLE_ADMIN`, blocking or deleting the last active admin is refused.
- **FR-008** Every `is_granted('ROLE_ADMIN')` / `SystemRole::Admin` check outside the superuser bypass is reviewed and
  set to `ROLE_DEVELOPER` or `ROLE_MANAGER` by area; a test lists the remaining `ROLE_ADMIN` checks (allow-list).
- **FR-009** Header area switcher and search show only reachable areas; Dashboard tour steps for the areas follow.
- **FR-010** Notifications routed by audience as in the clarifications.
- **FR-011** Seeds: `app:seed-demo` / `app:seed-ops` create a demo manager and a demo developer; `app:seed-platform`
  seeds the Organization menu.
- **FR-012** Spec `164` screens (plans, billing accounts, subscriptions, usage, invoices, invoicing data) live in the
  Organization area; gateway credentials and the invoicing certificate stay in Administration.
- **FR-013** Manual: Organization chapter group (en + es) for the manager audience; Administration chapters for the
  developer audience; `docs/product/ROLES.md` describes the four roles.

## Success criteria

- **SC-001** A functional test per area × role (guest, user, manager, developer, admin): 302 to login / 403 / 200.
- **SC-002** No Administration link is rendered for a manager and no Organization link for a developer (menu,
  hub, breadcrumbs, search, manual tabs) — tested.
- **SC-003** The `ROLE_ADMIN` allow-list test passes with only the superuser bypass and global role changes left.
- **SC-004** 100% statement coverage kept; check-fast and release-check green.

## Out of scope (v1)

- Organization roles in a table or per-feature permissions inside the Organization area (billing only, users only).
- Multi-tenant organizations (several organizations per instance); spec `164` billing accounts cover who pays.
- Delegated administration per group (a group manager managing only their group).
