# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/rbac-kit-bundle` (`symfony-bundle`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.
Only contracts that add clear interoperability or maintainability value are **Adopted**.
Others are **N/A** (or already covered by Symfony) so the decision stays auditable.

## Table of contents

- [Baseline (always)](#baseline-always)
- [Interface / contract PSRs](#interface--contract-psrs)
- [Summary](#summary)

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (Nowo REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` `autoload` / `autoload-dev` PSR-4 map for package sources and tests. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **Adopted** | `PermissionSetRegistry` (uncatalogued keys in sets) and `GrantResolver` (assignments over `voter.max_assignments`) take an optional `Psr\Log\LoggerInterface`; they log set codes, keys and limits only — never users or contexts. |
| PSR-6 / PSR-16 Cache | **N/A (Symfony Cache Contracts)** | The stored permission sets are cached through `Symfony\Contracts\Cache\CacheInterface` (`storage.cache_pool`): its `get()` callback computes and stores the whole table once (stampede protection) — the contract Symfony pools implement; PSR-6 adds nothing here. |
| PSR-7 / PSR-17 HTTP messages | **N/A** | Symfony HttpFoundation is the correct surface for an admin UI and a kernel listener. |
| PSR-18 HTTP client | **N/A** | No outbound HTTP client. |
| PSR-11 Container | **N/A** | No container locator in the public API; constructor injection and tagged iterators only. |
| PSR-14 Event dispatcher | **Adopted** | `PermissionSetManager` dispatches `PermissionSetChangedEvent` through `Psr\EventDispatcher\EventDispatcherInterface` after every create / update / delete so hosts can audit them. |
| PSR-15 HTTP middleware | **N/A** | Symfony kernel listeners (request guard, admin access); no middleware pipeline. |
| PSR-20 Clock | **Adopted** | `PermissionSetManager` and `DoctrinePermissionSetStorage` stamp `updated_at` with an injected `Psr\Clock\ClockInterface` (framework `clock`, optional); tests use a fixed clock. |

## Summary

- **Adopted beyond baseline:** PSR-3 (logger), PSR-14 (event dispatcher) and PSR-20 (clock), all type-hinted and wired.
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.
- **Re-evaluate** when the package gains HTTP or other SPIs.

---

_REQ-CS-007 evaluation date: 2026-11-01._
