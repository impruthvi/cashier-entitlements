# Changelog

## Unreleased

## 0.2.1 — 2026-09-24

- Allow applications to provide their effective numeric allowance resolver to atomic usage admission.

## 0.2.0 — 2026-09-21

- Add a non-resetting `lifetime` usage period for append-only stock limits.
- Validate `lifetime` meter configuration during container resolution.
- Prove lifetime rollover, admission, PostgreSQL contention and fresh-consumer behavior.
- Document that lifetime meters do not release allowance when domain records are deleted.

## 0.1.0 — 2026-09-13

First tagged release. Local entitlement resolution and background billing
reconciliation for Laravel Cashier, verified against a live Stripe sandbox.

- Fix scheduled sweep starvation by automatically resuming unfinished scans in the owner scope.
- Preserve pending initial refreshes, active worker claims and retry backoff during sweeps.
- Validate cron field counts/ranges and reject partially invalid convergence schedules.
- Report failed sweep owners as unhealthy even when no native state row could be created.
- Add scheduled continuation, malformed cron, pending refresh and failed-sweep health regressions.
- M6 bounded, resumable account sweep that requests refreshes for owners whose observation is stale.
- Durable audit runs with cursor and heartbeat; an interrupted scan stays incomplete and proves nothing.
- Local-only `entitlements:doctor` reporting configuration, backlog, staleness and schedule health.
- Optional scheduled convergence registered from cron expressions, ignored rather than fatal when unusable.
- Dropped-event convergence suite against an independent provider oracle, plus scaling and secret-free proof.
- Opt-in live Stripe sandbox contract suite; its credentials never enter pull-request CI.
- M5 optional Masterix driver bound to one licence group per owner, with narrowed and documented support.
- Optional read-only Pennant store over the local resolver, with explicit owner scope and snapshot semantics.
- `EntitlementDriver` seam applied in the same transaction and connection as the native projection.
- M4 calendar and billing-period usage meters with idempotent recording and transactional limit admission.
- Append-only override ledger requiring reason, actor and expiry, consulted only when enabled.
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
