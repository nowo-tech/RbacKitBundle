> **Imported for traceability (KIT-46).** Source specification of the application this bundle was extracted from,
> copied as is (application names, paths and tasks are the origin's). What the bundle generalises from it is mapped in
> [`../001-baseline/spec.md`](../001-baseline/spec.md#origin-specifications); the bundle code carries no application name.

# Tasks: 162 Access rules by role

## Block A — model and enforcement (FR-001 – FR-008)

- [x] **T001** `App\Shared\Access\AccessAreaCatalog` + `AccessArea` value object (key, kind, label key, routes, parent);
  catalogue from the Dashboard menu seed, project tabs, Settings sections, Chat and Manual routes.
- [x] **T002** Coverage test: every route under `/dashboard`, `/projects`, `/chat`, `/manual` maps to an area or to
  the always-open list.
- [x] **T003** Entity `AccessRule` + repository + migration (`access_rule`, unique `area_key` + `project_id`, FK
  cascade on project delete).
- [x] **T004** `AccessRuleVoter` (`access_area:<key>`; ROLE_ADMIN, existing access, global rule, project override,
  parent chain) with a per-request memo (`ResetInterface`).
- [x] **T005** `AccessAreaRequestListener` (route → area → voter; 403 branded page / JSON) after the firewall, before
  controllers.
- [x] **T006** Navigation hiding: DashboardMenu items (the menu checker asks `access_route:<route>`; it is now tagged,
  see below), project tabs, Settings sections; Twig `access_area()`.
- [x] **T006b** The remaining links: breadcrumbs, header search providers, Summary widget catalogue + builder, report
  types, manual edge tabs.
- [x] **T007** Unit tests for the voter matrix (product / project / action × role × override × parent) and functional
  tests for SC-001, SC-003; query-budget tests for SC-002.

## Block B — Administration → Access rules (FR-009 – FR-012)

- [x] **T008** Controller, form and page (`_list_page` layout, grouped by kind, multi-select of instance roles,
  minimum-role select, Reset to default, Changed badge); menu entry + breadcrumb via `app:seed-platform`.
- [x] **T009** Audit `access_rule.updated` (before / after); instance-role delete guard listing the rules that use it.
- [x] **T010** Preview count of users who would lose access (on demand, one grouped query).
- [x] **T011** Functional tests (save, reset, refused role delete, invalid requests); e2e with axe (light / dark) + keyboard (`e2e/admin/access-rules.spec.ts`).

## Block C — per-project restrictions (FR-013 – FR-015)

- [x] **T012** Project Settings → Restrictions section (`project.settings.manage`): global value + stricter override,
  looser values refused; owner bypass for `project.settings.restrictions`.
- [x] **T013** Audit `project.access_rule.updated`; overrides removed with the project; tests (stricter only, owner
  bypass, global tightened above an override).

## Block D — actions (FR-016 – FR-017)

- [x] **T014** Action areas on exports (Summary, Reports, issue PDF / CSV / XLSX), scheduled reports, share-link create,
  issue delete / merge / bulk, project Messenger retry / remove, DSN reveal, event export: hidden control + 403
  before any mutation.
- [x] **T015** Tests per action: hidden, 403, no data changed.

## Block E — docs and manual review (FR-018 – FR-020)

- [x] **T016** Manual: Administration *Access rules*, Project settings *Restrictions* (English + Spanish, stamped).
- [x] **T017** Review every manual chapter in English and Spanish: the role each page needs and what a user without it
  sees; screenshots where a page changes; `nowo:manual:check --strict`.
- [x] **T018** `docs/product/ROLES.md` access-rule layer; strings in 7 locales; CHANGELOG, UPGRADING (migration),
  ROADMAP.
