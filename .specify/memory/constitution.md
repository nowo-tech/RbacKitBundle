# RbacKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit and PHPStan (and Vitest when frontend exists) are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec updates in the same PR.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### V. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges.

### VI. Fail closed, never compare roles
An empty catalogue, an unknown permission key, an unmapped route (unless explicitly open), a guest, and a context
permission asked without a resolvable context subject are denied. Roles are permission sets: a user's permissions in a
context are the union of the sets held there; no code path compares roles by level. Nothing is granted by default (no
bypass role, no fallback set, no grant provider). The admin UI is private (view / manage roles, custom checker); an
empty role list is refused; CSRF is mandatory on every mutation; the container refuses insecure wiring (no form CSRF,
demo switch outside debug, UI without SecurityBundle, non-literal security switches).

### VII. Memoised, worker-safe, bounded
The catalogue, the permission sets, the user grants and the voter decisions are memoised per request and implement
`ResetInterface` (`kernel.reset`). Catalogue size, stored sets, assignments per user, memoised users and memoised
decisions are bounded. Audit events after every mutation carry codes, names and permission keys only.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-11-01 | **Last Amended**: 2026-11-01
