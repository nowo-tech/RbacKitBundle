# RBAC Kit Bundle demos

## Symfony 8 (FrankenPHP + MySQL)

```bash
cd demo/symfony8
cp .env.example .env   # if needed
make up      # prints "Demo started at: http://localhost:8096"
make setup   # schema + nowo:rbac:check
```

- App: http://localhost:8096 — "What can I do?" matrix (`rbac_can()` per project); **Sign in** with `admin`, `alice`,
  `bob` or `carol` (HTTP Basic, **demo only**: the password is the user name)
- Admin: http://localhost:8096/admin/rbac (permission sets, catalogue; `admin` only)
- Default `FRANKENPHP_MODE=worker` (change in `.env` and recreate)
- `make test-e2e` / `make demo-screenshots` (Playwright, REQ-DEMO-013)

The port is bound to 127.0.0.1: the demo users have well-known passwords. Never copy them to an application.

See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).
