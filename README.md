# Cashier Entitlements

Local entitlement resolution and background billing reconciliation for Laravel Cashier.

**Status: M3 development build, not a production release.** The package includes
typed billing rules, complete Stripe intake, Cashier diagnostic dry-runs, durable
refresh requests, fenced queue workers, atomic native application and local-only
access resolution. Application is disabled until explicitly configured. Usage,
overrides, production Masterix/Pennant adapters and account-wide recovery remain later work.

See [the M1 API and rules](docs/m1-billing.md) for local, side-effect-free calculations.
Results describe a supplied snapshot; they are not automatically applied access grants.
See [M2 setup, commands and safety boundaries](docs/m2-diagnostics.md) to compare
current Stripe subscriptions with Cashier without changing customer access.
See [M3 installation, refresh and local resolution](docs/m3-refresh.md) before enabling
native application. You must choose a freshness policy and schedule recovery/refresh work.

The intended purpose is to answer what an organization can access from local,
successfully applied billing facts, and repair that projection in background work.
It does not replace Cashier checkout, manage RBAC, or call Stripe during authorization.

## Development

Requires PHP 8.3+. Integration tests exercise Laravel 12 and 13 using SQLite;
M1's billing calculations run without a Laravel application or database.

```sh
composer install
composer check
bash scripts/test-matrix.sh 12 all lowest
bash scripts/test-matrix.sh 13 all highest
bash scripts/test-matrix.sh 12 none highest
bash scripts/test-consumer.sh
bash scripts/test-postgres.sh # Requires local PostgreSQL binaries and pdo_pgsql.
```

`test-matrix.sh` accepts Laravel `12|13`, optional dependencies
`all|none|cashier|masterix|pennant`, and dependency preference `lowest|highest`.
Each run resolves into a separate gitignored `build/` directory, preserving your
development dependencies. `PHP_BINARY` and `COMPOSER_BINARY` can select executables.
Lockfiles remain there for reproduction; GitHub CI uploads its matrix lockfiles.

Cashier is optional at installation but required for billing diagnostics and refresh;
its Stripe SDK powers provider reads. Masterix and Pennant remain optional feasibility
dependencies. None is required to boot the package. The consumer smoke test verifies
auto-discovery, config/migration publishing, native application and local resolution
without those packages or Testbench installed.
It uses a local Composer path repository; no Packagist release is implied.

Configuration is published under `cashier-entitlements`, not Masterix's `entitlements`:

```sh
php artisan vendor:publish --tag=cashier-entitlements-config
```

## Compatibility boundaries

Read [M0 results and open gates](docs/m0-compatibility.md) before building an adapter.
Passing characterization tests document upstream behavior, including unsafe behavior;
they are not proof of a production-ready integration.

- Masterix assignment and consumption are not idempotent. Over-capacity downgrades
  fail while the previous allowance remains active. Its production adapter remains gated.
- Pennant caches values above a custom driver. Explicit evaluation boundaries must
  flush that cache. Numeric `value()` is distinct from boolean `active()`.
- Cashier's `active()` is not the future entitlement policy.

The test-only driver in `tests/Support` is not exported through runtime autoloading.
No optional provider or Pennant store is registered automatically by this package.

## Next milestone

M4: native usage, billing periods, admission checks and audited overrides.
Resumable operational sweeps and the full health/doctor surface remain later work.
Production adapters come later, after their safety gates.

MIT licensed; see [LICENSE.md](LICENSE.md).
