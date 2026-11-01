# RBAC Kit Bundle — Symfony 8 demo

FrankenPHP (worker mode by default) + MySQL. What it shows:

- two projects (`App\Demo\ProjectDirectory`, contexts implementing `RbacContextInterface`) and four users whose
  permission sets come from `App\Demo\DemoGrantProvider` (memberships of a real application);
- the catalogue and the system sets in `config/packages/nowo_rbac_kit.yaml`;
- the home page: what the signed-in user may do in each project (`rbac_can()`), the union of two incomparable sets for
  alice in Billing;
- the request guard (403 on Billing settings for alice) and a controller check for users outside a project (bob);
- the admin UI under `/admin/rbac` (Doctrine storage): system, edited, locked and custom sets, the catalogue, create /
  edit / delete with CSRF and a confirm dialog.

Commands (from this directory):

- `make up` / `make down` – start / stop (prints `Demo started at: http://localhost:8096`)
- `make setup` – schema + `nowo:rbac:check`
- `php bin/console nowo:rbac:check` – coverage of `/projects` and `/reports`
- `make test-e2e` / `make demo-screenshots` – Playwright (official image)

Users (HTTP Basic, **demo only**, password = user name): `admin` (bypass role), `alice`, `bob`, `carol`. The port is
bound to 127.0.0.1.
