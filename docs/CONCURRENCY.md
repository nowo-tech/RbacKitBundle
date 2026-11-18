# Concurrency

How RBAC Kit stays correct when two managers, two workers or a retried request write the same permission set at the
same time (REQ-RUNTIME-003 of the Nowo bundle specs). Since 1.3.

## Table of contents

- [Write paths](#write-paths)
- [What each control prevents](#what-each-control-prevents)
- [Conflict codes](#conflict-codes)
- [Host options](#host-options)
- [Custom storages](#custom-storages)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Write paths

Only `storage.type: doctrine` (or a host `storage.type: service`) writes. `storage.type: config` is read-only. The
bundle has no Messenger handler and no console command that writes. Every write is a DBAL statement on the
connection of the manager that maps `PermissionSetRecord`. No entity manager is ever flushed, cleared or reset (the
host's least of all).

| Path | Transaction | Control (rule 4) | Answer under contention |
| --- | --- | --- | --- |
| Create a custom set (`PermissionSetManager::create()`, `POST /new`) | One unit: transaction-doctrine's runner (`DoctrineTransactionRunner::forConnection()`), or a SAVEPOINT inside the host's open transaction | `INSERT` against the unique code `uniq_rbac_permission_set_code`; the violation is caught as the domain result | Concurrent create of the same code: `CODE_TAKEN` (form 422, never a 500 and never an overwrite). Lock conflict: 409 `CONFLICT_001` |
| Edit a set (`PermissionSetManager::update()` / `updateRevision()`, `POST /{code}/edit`) | Same unit | `SELECT … FOR UPDATE` on the row, compare its revision with the one the editor saw, then `UPDATE`. First edit of a system set: `INSERT` against the unique code | Row changed meanwhile: `STALE`, a domain **409** with `X-Error-Code: RBAC_PERMISSION_SET_STALE`, showing the current version. Row deleted meanwhile: `NOT_FOUND`, never re-created. Lock conflict: 409 `CONFLICT_001` |
| Delete a custom set (`PermissionSetManager::delete()`, `POST /{code}/delete`) | Same unit | One `DELETE` by code (no-op when missing) | Lock conflict: 409 `CONFLICT_001` |
| `storage.max_sets` overflow repair (`enforceBound()`) | Same unit per statement | Deletes only the row this request wrote, compared field by field | Over the bound after concurrent creates: `LIMIT`, the other rows are kept |
| `DoctrinePermissionSetStorage::save()` (the 1.2 upsert, kept for hosts that call it) | Same unit | `INSERT`, and on the unique violation an `UPDATE` | Never a 500 on a concurrent create; last writer wins (it is an upsert by contract) |

The revision (`StoredPermissionSet::revision()`, exposed as `PermissionSet::$revision`) is a SHA-256 of everything
the row stores: name, description, system flag, permissions, editor and edit time to the second. The edit form
carries it in a hidden `revision` field. A system set without a stored edit sends `unstored`
(`PermissionSet::UNSTORED`). A POST with no revision (a 1.2 client) is compared with the set as that request reads it.

Side effects run after the write: the registry and the decision memos are invalidated and `PermissionSetChangedEvent`
is dispatched only when the write happened. A refused write dispatches nothing.

## What each control prevents

Before 1.3 `save()` read the row (`SELECT 1`) and then inserted or updated it. That gave these races:

- **Two creates of one code.** One request failed with a 500 (unique violation). Or the second create silently
  updated the first one's row, while its `CREATED` event reported its own permissions.
- **A lost edit.** Admin A revokes a permission while admin B saves a form opened earlier. B's save wrote the old list
  back, so the revoked permission was granted again. When the two saves were truly concurrent, the `UPDATED` event of
  B did not even show it as added.
- **A resurrected set.** An edit racing a delete inserted the row again, with its permissions, for any assignment that
  still referenced the code.

The unique code, the row lock and the revision close all three.

## Conflict codes

REQ-RUNTIME-003 rule 6, through transaction-doctrine ^1.6 (`ConflictCode::forRequest()`,
`ConflictResponsePolicy::httpExceptionFor()`):

| Failure on an admin page | Status | `X-Conflict-Code` | `Retry-After` |
| --- | --- | --- | --- |
| Deadlock, lock wait timeout, serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03), nothing committed | 409 (or 503) | `CONFLICT_001` | yes (policy value, or the failure's hint) |
| The same, when the request already committed something (transaction-doctrine's request watermark), or a lost savepoint leaves the commit outcome unknown | 409 (or 503) | `CONFLICT_002` | no: read again, do not repeat |
| The set changed since the form was loaded | 409 | none | none; `X-Error-Code: RBAC_PERMISSION_SET_STALE` |
| A concurrent create took the code | 422 (form error `code_taken`) | none | none |
| Unique violation anywhere else, lost connection, any other database error | not mapped (the host's error handling) | none | none |

The conflict pages are rendered by the application's error page with a fixed message. The storage error stays the
previous exception for the logs. Only the bundle's controllers are mapped (`PermissionSetConflictListener`, class
prefix `Nowo\RbacKitBundle\Controller\`). Host routes that call `PermissionSetManagerInterface` keep their own
handling: catch `TransientStorageFailure` (nowo-tech/transaction-contract) for the lock conflicts and
`PermissionSetException::STALE` for the domain conflict.

## Host options

| Option | Effect |
| --- | --- |
| `conflict_response.status` | `409` (default) or `503`. Null follows `nowo_transaction_doctrine.conflict_response` when that bundle is registered, else 409. |
| `conflict_response.retry_after` | `Retry-After` seconds of a `CONFLICT_001` answer. Null follows transaction-doctrine, else 1. |
| `NowoTransactionDoctrineBundle` with `commit_detection` | Optional. With it, a conflict after an earlier commit in the same request answers `CONFLICT_002`. Without it, only the failure decides. |
| `doctrine.entity_manager` | The manager whose connection the writes use. The bundle never flushes it. |

The PostgreSQL `lock_timeout` of the bundle's own transactions is 5 s (`DoctrinePermissionSetStorage::LOCK_TIMEOUT_MS`,
the third constructor argument). Inside the host's transaction the host's session settings apply. MySQL and MariaDB
use the server's `innodb_lock_wait_timeout` (50 s by default; lower it for admin connections if you want faster
answers).

## Custom storages

A `storage.type: service` storage that implements `ConditionalPermissionSetStorageInterface` (`insert()`,
`replace()`) gets the same guarantees. Make `insert()` rely on a unique key and `replace()` compare the revision under a
lock or in one conditional statement. A plain `PermissionSetStorageInterface` keeps the 1.2 behaviour: the manager
still compares the revision the request read, but the write itself is `save()`. See [Residual risks](#residual-risks).

## Tests and CI

- `tests/Integration/Persistence/PermissionSetRaceDbTest.php`: two connections on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16. Covered races: two creates of one code, two edits from one revision, an edit racing a delete, two
  first edits of a system set, and two managers editing from one read. Connection B waits on A's real lock, times
  out within 1 s as a `TransientStorageFailure` (409 `CONFLICT_001`) and stays usable. Once A commits, B is refused:
  no duplicate, no lost update, no re-created set.
- `tests/Integration/Persistence/DoctrinePermissionSetStorageDbTest.php`: conditional writes, savepoints inside the
  host's transaction and the PostgreSQL abort repair, on SQLite and the three servers.
- `tests/Unit/EventListener/PermissionSetConflictListenerTest.php`: the mapping of every vendor code to `CONFLICT_001`,
  `CONFLICT_002` after an earlier commit, the 503 option, and no mapping for unique violations, lost connections and
  host routes.
- `tests/Integration/Functional/AdminConcurrencyTest.php`: the stale-edit 409 with the current version, and the lock
  conflict 409 and 503 through the kernel.
- CI job **Databases (MySQL 8.4, MariaDB 11.4, PostgreSQL 16)** on DBAL 3 and DBAL 4. It is a required check on `main`.

## Residual risks

- **Custom storages without `ConditionalPermissionSetStorageInterface`.** Between the manager's revision check and
  `save()` a concurrent edit can still be overwritten (the 1.2 behaviour). Implement the interface.
- **Assignments are the host's.** `CODE_ASSIGNED` on create and `IN_USE` on delete ask the host's usage provider. The
  check is not atomic with the host's assignment writes. A set deleted while the host assigns it in the same instant
  leaves an assignment to a missing code. It grants nothing, and a later create of that code is refused with
  `CODE_ASSIGNED`.
- **The `max_sets` bound is checked without a lock.** Concurrent creates can briefly exceed it. The request that
  went over removes its own row and answers `LIMIT` (unchanged since 1.0).
- **Cache.** The edit form's revision comes from the registry. With a per-host cache pool (not shared), a form can
  show a version up to `storage.cache_ttl` old. The save is then refused as `STALE` and the page shows the database
  version, so nothing is lost; use a shared pool (see [CONFIGURATION.md](CONFIGURATION.md#storage)).
- **Inside a host transaction** the bundle's row lock lasts until the host commits, and a deadlock rolls back the
  host's whole transaction on MySQL and MariaDB. The original error is rethrown; the host decides.
