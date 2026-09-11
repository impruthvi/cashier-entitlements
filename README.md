# Cashier Entitlements

Local entitlement resolution and background billing reconciliation for Laravel Cashier.

**Status: M2 development build, not a released authorization package.** The package
includes typed billing facts, policy/price mapping, complete read-only Stripe intake,
a Cashier local projector, and owner/account diagnostic dry-runs. There is no
applied-grant resolver, automatic repair, usage ledger, production adapter, or package
migration yet. Setting `enabled` to `true` does not implement those capabilities.

See [the M1 API and rules](docs/m1-billing.md) for local, side-effect-free calculations.
Results describe a supplied snapshot; they are not automatically applied access grants.
See [M2 setup, commands and safety boundaries](docs/m2-diagnostics.md) to compare
current Stripe subscriptions with Cashier without changing customer access.

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
```

`test-matrix.sh` accepts Laravel `12|13`, optional dependencies
`all|none|cashier|masterix|pennant`, and dependency preference `lowest|highest`.
Each run resolves into a separate gitignored `build/` directory, preserving your
development dependencies. `PHP_BINARY` and `COMPOSER_BINARY` can select executables.
Lockfiles remain there for reproduction; GitHub CI uploads its matrix lockfiles.

Cashier is optional at installation but required for the M2 command/projector;
its Stripe SDK powers provider reads. Masterix and Pennant remain optional feasibility
dependencies. None is required to boot the package. The consumer smoke test verifies
auto-discovery and config publishing without those packages or Testbench installed.
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

M3: durable refresh work, generation fencing, atomic native apply and local resolution.
Resumable operational sweeps and the full health/doctor surface remain later work.
Production adapters come later, after their safety gates.

MIT licensed; see [LICENSE.md](LICENSE.md).
