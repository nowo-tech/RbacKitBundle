> **Imported for traceability (KIT-46).** Source specification of the application this bundle was extracted from,
> copied as is (application names, paths and tasks are the origin's). What the bundle generalises from it is mapped in
> [`../001-baseline/spec.md`](../001-baseline/spec.md#origin-specifications); the bundle code carries no application name.

# Feature Specification: Access rules by role (product areas, project sections and actions)

**Feature Branch**: `162-access-rules`  
**Created**: 2026-10-23  
**Status**: Implemented (A + B v1.98.0, C – E v1.99.0)  
**Roadmap**: ROADMAP *Next* — "Access rules by role" (user request 2026-10-23, translated from Spanish: "in symfony beacon I need to restrict access to some parts by role (both ROLE_* and the user's role per project), ok? let's write the specification first"; follow-up: "careful, after roles you may have to review the whole manual.")  
**Cross-refs**: spec `087` (project permissions, `ProjectPermission`, `ProjectPermissionVoter`), spec `088` (`full` role), `App\Identity\Security\InstancePermissionVoter`, `App\Identity\Entity\InstanceRole`, `App\Project\Service\ProjectAccessService`, `docs/product/ROLES.md`, spec `135` (listings), spec `157` (Summary widgets), spec `158` (reports and exports), spec `161` (manual), OTHER REQ-RBAC-003 / REQ-RBAC-007

## Is it viable?

Yes, without changing who can see a project. Today an instance has two firewall roles (`ROLE_USER`, `ROLE_ADMIN`), a
catalog of instance roles (`InstanceRole`, Administration → Roles) that only documents the project matrices, and five
project membership roles (owner, full, admin, member, viewer) over nine `project.*` permissions. `project.view` opens
every project section (Issues, Performance, Analytics, Releases…), and every signed-in user sees every Dashboard area.
This spec adds one layer on top: **access rules** that can only *narrow* what the current model already allows, so the
defaults reproduce today's behaviour exactly and nothing new is ever granted.

## Clarifications (2026-10-23, user answers and design defaults)

| Question | Answer / proposal |
|---|---|
| What can be restricted | **Product areas** (Dashboard entries, Chat, Manual…) by instance `ROLE_*`; **project sections** (Issues, Performance, Analytics, Releases, the project Messenger monitor, each Settings section) by project role; **actions** (exports, share links, issue delete / merge, Messenger retry, DSN reveal…) — user answer. Delegated Administration (non-admin roles opening parts of `/admin`) is **not** in scope. |
| Where rules are configured | Administration UI (an area × role matrix stored in the database, audited); defaults = current behaviour — user answer. |
| Scope of project rules | **Global + per project**: one instance policy; a project's admins can make it **stricter** for their project, never looser — user answer. |
| `ROLE_*` and project role together | **Both must hold** (AND): project membership with the required role, plus the required instance roles if the rule names any. An instance role never replaces membership — user answer. |
| Rule shape | Product area: a set of instance roles (empty = every signed-in user). Project section / project action: a minimum project role (viewer < member < admin < full < owner) plus an optional set of instance roles. Instance action (e.g. cross-project Summary export): a set of instance roles. |
| Instance roles in a set | "Any of" the listed roles (OR inside the set; the set is ANDed with the project role). |
| Who always passes | `ROLE_ADMIN` (instance administrators) passes every rule — no instance can lock its administrators out. A project's **owner** always passes the project's own *Settings → Restrictions* section, so a per-project rule cannot lock the project out of its own settings. |
| Rules never grant | A rule is evaluated *after* the existing checks (`ROLE_USER`, membership, `ProjectPermission`): an action that needs `project.issues.triage` still needs it, whatever the rule says. |
| Per-project override | Stored only when stricter than the global rule (a higher minimum role and / or more required instance roles); a looser value is refused. When the global rule is later tightened above an override, the global rule wins. |
| Denied experience | Hidden everywhere it is linked (menus, project tabs, breadcrumbs, header search, Summary widgets, report types, manual edge tabs); a direct URL gets the branded 403 page with "Ask a project administrator" (project) or "Ask an instance administrator" (product); JSON endpoints answer 403. |
| Area catalogue | In code (`AccessAreaCatalog`, like `ProjectPermissionCatalog`): key, kind (`product` / `project` / `project_action` / `instance_action`), label key, the routes it covers, and the parent area (a Settings section belongs to `project.settings`). Not editable in the UI. |
| Machine credentials | Read-API tokens, ingest DSNs, share links already issued and webhook / chat-bot callbacks keep their own scopes; rules apply to signed-in users (v1). |

## User scenarios

1. **Hide Performance from viewers.** An instance admin opens Administration → Access rules, finds *Project sections →
   Performance* and sets the minimum project role to *member*. A viewer of any project no longer sees the Performance
   tab, its Summary widgets or its report type; `/projects/{uuid}/performance` answers 403.
2. **Exports only for a reporting role.** The admin creates the instance role `ROLE_REPORTING` and requires it on
   *Actions → Export (CSV / Excel / PDF)*. Members keep the Summary and Reports pages but the export menu disappears
   unless they hold `ROLE_REPORTING`; a forged export URL answers 403.
3. **Chat for staff only.** *Product areas → Chat* requires `ROLE_STAFF`: other users lose the dock and the Chat pages;
   notifications already sent still open, and land on the 403 page.
4. **A stricter project.** In a sensitive project, its admin opens *Settings → Restrictions* and raises *Issues* to
   *member* and *Releases* to *admin* for that project only. The page shows the global value next to the override and
   refuses a looser one.
5. **No lock-out.** An admin tries to require `ROLE_X` on *Administration → Access rules* itself: it is not in the
   catalogue (Administration stays `ROLE_ADMIN`). A project owner can always reopen *Settings → Restrictions*.

## Requirements

### Block A — model and enforcement

- **FR-001** `App\Shared\Access\AccessAreaCatalog` lists every restrictable area with its key (`product.summary`,
  `project.performance`, `action.export`…), kind, label key, covered route names and parent; a test fails when a
  route under `/dashboard`, `/projects`, `/chat` or `/manual` is covered by no area (except the always-open ones:
  project home, account, logout).
- **FR-002** Table `access_rule` (`area_key`, nullable `project_id`, nullable `min_project_role`, JSON
  `instance_roles`, `updated_by`, `updated_at`; unique `area_key` + `project_id`). No row = the catalogue default =
  today's behaviour.
- **FR-003** `AccessRuleVoter` decides the attribute `access_area:<key>` (subject: the `Project` for project kinds, null
  otherwise): ROLE_ADMIN passes; otherwise the existing access must already hold, then the global rule, then the
  project override (AND).
- **FR-004** A child area is denied whenever its parent is (Settings → Alerts under Settings).
- **FR-005** A request listener denies a covered route before the controller runs (403, branded page / JSON); controllers
  keep their existing `project.*` checks.
- **FR-006** Rules for a request are loaded once (global rows + the current project's rows, one query) and reset between
  requests (FrankenPHP worker mode).
- **FR-007** Navigation hides denied areas: Dashboard menu entries, project tabs, Settings sections, breadcrumbs,
  header search groups / results, Summary widgets and the Customize catalogue, report types and scheduled-report
  types, manual edge tabs pointing at a denied page; a denied Summary widget does not run its query.
- **FR-008** Twig `access_area('key', project = null)` for templates; the same voter behind it.

### Block B — Administration → Access rules

- **FR-009** Administration → Access rules (ROLE_ADMIN): the catalogue grouped by kind, each row with its current rule
  (instance-role multi-select from the role catalog; minimum project role select for project kinds), *Reset to
  default*, and a "Changed" badge on non-default rows.
- **FR-010** Saving writes one `UserAction` (`access_rule.updated`, before / after) per changed row; the audit log
  and the e-mail log stay readable to admins.
- **FR-011** Deleting an instance role used by a rule is refused with the list of rules that use it.
- **FR-012** The page shows, per row, how many users would lose access compared with the current rule (a preview
  count, computed on demand).

### Block C — per-project restrictions

- **FR-013** Project Settings → *Restrictions* (needs `project.settings.manage`): project sections and project actions
  with the global value and an optional stricter override; a looser value is refused with a message.
- **FR-014** A project owner always passes `project.settings.restrictions`; the section cannot be restricted.
- **FR-015** Overrides are audited (`project.access_rule.updated`) and removed with the project.

### Block D — actions

- **FR-016** Action areas wrap the existing endpoints: Summary / Reports / issue exports (CSV, Excel, PDF), scheduled
  reports, share-link creation, issue delete / merge / bulk actions, project Messenger retry / remove, DSN reveal and
  event export; the control is hidden and the endpoint answers 403 when denied.
- **FR-017** A denied action never changes data: the check runs before CSRF-protected handlers mutate anything.

### Block E — docs and manual review

- **FR-018** Manual: Administration chapter section *Access rules*, Project settings chapter section *Restrictions*, and
  a review of **every** chapter (English and Spanish, kept in parallel) so each page says which role it needs and
  what a user without it sees (user follow-up 2026-10-23).
- **FR-019** `docs/product/ROLES.md` gains the access-rule layer; CHANGELOG, UPGRADING (migration), ROADMAP.
- **FR-020** Every new string in the 7 locales.

## Success criteria

- **SC-001** With no rule stored, every existing functional and e2e test passes unchanged (defaults = today).
- **SC-002** Rule evaluation adds at most **1** query per request (budget tests on the Dashboard, a project's issue list
  and the Summary).
- **SC-003** For every catalogue area, a test proves: hidden in navigation, 403 on the direct URL (HTML and JSON), and
  allowed again after *Reset to default*.
- **SC-004** No rule can deny ROLE_ADMIN or lock a project owner out of *Settings → Restrictions* (tests).
- **SC-005** The Administration page and the Restrictions section pass axe (no serious violations) and work by
  keyboard only.
- **SC-006** 100% statement coverage kept; `manual-translations` green (English and Spanish chapters updated together).

## Out of scope (v1)

- Delegated Administration (non-admin roles opening parts of `/admin`).
- New project roles or editing the `ProjectPermission` matrix; rules only narrow it.
- Rules for Read-API tokens, ingest, share links already issued, webhook / bot callbacks.
- Time-based or IP-based rules; per-user exceptions.
