# Changelog

## Unreleased

- M3 durable owner refresh requests, permanent event receipts and pending-work recovery.
- Generation/lease fencing, after-commit queue dispatch and atomic native state/version application.
- Local-only boolean/finite/unlimited resolution with explicit freshness and exact expiry checks.
- Verified Cashier webhook integration and opt-in single-owner `--apply`; dry-run remains the default.
- Publishable native migration, real database-queue and PostgreSQL multi-process tests.
- Fresh consumer migration/application/resolver smoke without optional integrations.
- M2 complete, context-scoped Stripe reads with nested pagination, bounded retries and safe failures.
- Read-only Cashier projection through custom model relationships and UUID owner keys.
- Owner/account diagnostic dry-runs, test-clock scoping, unknown-owner reporting and versioned JSON.
- Current provider versus local billing comparison; dry-runs do not mutate access.
- M2 SDK-transport/SQLite tests and independent optional-dependency matrix coverage.
- M1 immutable owner, subscription/item facts and versioned, context-scoped price catalogs.
- Pure status policy and price mapping with allowed/denied/invalid results and expiry boundaries.
- Boolean, finite and unlimited allowances; explicit per-unit scaling and overflow rejection.
- Owner/customer isolation, incomplete-snapshot refusal, malformed-input and mapping checks.
- Billing tests in every dependency-matrix lane and the dependency-free consumer smoke test.
- M0 package skeleton and namespaced configuration.
- Pest/Testbench compatibility probes for Cashier, Masterix and Pennant.
- Isolated dependency matrix, fresh consumer smoke test, Pint and Larastan checks.
- Recorded production adapter safety gates; no production authorization API released.
