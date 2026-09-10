# M0 compatibility findings

Tested locally on 10 September 2026, macOS, Composer 2.10.1, SQLite. M0 scaffolding
and feasibility probes are implemented. **Masterix production acceptance is not passed.**
This package has not been published, and GitHub-hosted CI has not run.

## Actual local matrix

Every full-suite row passed **24 tests / 63 assertions**. Versions below came from
the resolved Composer lockfiles, not just permissive version constraints.

| PHP | Laravel | Preference | Testbench | Pest / Laravel plugin | Package tools |
|---|---|---|---|---|---|
| 8.3.32 | 12.61.1 | lowest | 10.2.0 | 4.3.2 / 4.0.0 | 1.19.0 |
| 8.3.32 | 12.69.2 | highest | 10.11.0 | 4.7.8 / 4.1.0 | 1.93.2 |
| 8.3.32 | 13.12.0 | lowest | 11.0.0 | 4.4.1 / 4.1.0 | 1.93.0 |
| 8.3.32 | 13.31.0 | highest | 11.2.0 | 4.7.8 / 4.1.0 | 1.93.2 |
| 8.4.23 | 12.69.2 | highest | 10.11.0 | 4.7.8 / 4.1.0 | 1.93.2 |
| 8.4.23 | 13.31.0 | highest | 11.2.0 | 4.7.8 / 4.1.0 | 1.93.2 |
| 8.5.8 | 13.31.0 | highest | 11.2.0 | 4.7.8 / 4.1.0 | 1.93.2 |

Full suites installed **Cashier 16.8.0, Masterix 1.3.1 and Pennant 1.26.0**.
The default quality run uses Larastan 3.12.0 (level 8, production `src/`) and
Pint 1.32.1. Pest 5 is not part of the support claim.

Lowest means Composer's lowest *currently resolvable, security-allowed* graph;
it does not mean every historical Laravel 12/13 patch was tested. Security blocking
was not disabled. The Laravel 12 lowest install hit one GitHub archive HTTP 504;
retrying the same lockfile succeeded, followed by a passing suite/platform check.

Independent installs on PHP 8.3.32 / Laravel 12.69.2 also passed:

| Optional packages installed | Tests / assertions |
|---|---|
| None | 3 / 8 |
| Cashier only | 5 / 13 |
| Masterix only | 11 / 30 |
| Pennant only | 14 / 36 |

Each isolated run asserts that the other optional classes really are absent.
A separate Laravel 13.31.0 consumer, installed with `--no-dev`, successfully boots,
auto-discovers this provider and publishes its configuration without any optional
package or Testbench. This is a local path installation, not an archive/Packagist test.

CI defines Linux matrix jobs and one macOS job using the same scripts. Its status
remains **configured, not remotely verified** until the repository is pushed.
PostgreSQL/MySQL locking, concurrency and persistent counters are not exercised in M0.

## Masterix: conditional integration, blocked for production release

Evidence: [real upstream API probes](../tests/Compatibility/MasterixTest.php).
Tests execute Masterix's eight actual migration stubs, not a reconstructed schema.
The tested owner is a custom organization model with an integer key on the default
SQLite connection; boolean and slot strategies are exercised.

| Operation | Observed result | Required package safeguard |
|---|---|---|
| Assign capacity 10 twice | Capacity becomes 20 | Persist provider-to-license-group binding; unique identity and retry/concurrency protection |
| Explicit exclusive category | Second assignment throws | Do not assume exclusivity or mistake its exception for successful idempotency |
| Upgrade 10 → 20 with usage 3 | Capacity 20, available 17 | Bind and transition the exact group |
| Downgrade 10 → 5 with usage 8 | Throws; capacity remains 10 | Must not mark refresh successful or silently preserve paid access at the higher plan |
| Close a bound group twice | Model-level expiry denies its access; other organization and usage history survive | Isolate pinned model operation; prove events, configured models/connections, rollback and unrelated same-owner groups before release |
| Outer transaction fails after assignment | Licenses roll back, but `PlanAssigned` was already emitted once | External effects must wait for successful outer commit |
| Consume same subject twice | Consumes twice | Select one usage authority; no blind dual-write or retry of consumption |

The closure probe uses a directly scoped `License` update because a safe
close-assignment adapter has not been established. It is **not shipped production code**.
It does not prove observer/event delivery, UUID morph columns, custom configured
models, cross-connection behavior, or race safety. UUID/custom-connection support
cannot be claimed from these tests. Raw usage semantics are not interchangeable
with the planned native idempotent usage ledger.

Before M5: prove a durable assignment binding and safe over-capacity downgrade/
closure transaction on the supported database connections, or explicitly narrow
the adapter's supported behavior. The native implementation can proceed independently;
these findings do not justify copying a full competing entitlements engine.

## Pennant: feasible with explicit snapshot boundaries

Evidence: [public store probes](../tests/Compatibility/PennantTest.php) run through
Pennant's real `Feature` manager/decorator with a test-only custom source.

- Named store coexists with the host's default store and isolates explicit owners.
- External enumeration returns only the requested owner's features.
- Numeric zero and null (the proposed unlimited representation) survive `value()`;
  both are considered active by Pennant. Use `active()` only for boolean features.
- Define, activate, forget, activate-for-everyone and purge operations can be rejected
  through the driver. An absent explicit owner is rejected.
- Changing the source within one process still returns the old value until
  `store('entitlements')->flushCache()`.
- Advancing time past expiry also returns cached access until the decorator is flushed.

M5 must document snapshot semantics and establish fresh boundaries between requests,
jobs and logical work units, after successful mutations, and after time boundaries
inside a long-running unit. The future native resolver remains the strict authorization
path. No transparent-freshness guarantee or production Pennant bridge exists today.

## Cashier and core

[Cashier probes](../tests/Compatibility/CashierTest.php) confirm that its owner
relationship can use `organization_id` through the configured customer model. A
`canceled` subscription with null `ends_at` still reports `active() === true` in
16.8.0, supporting the reviewed decision to normalize billing facts independently
of that helper. M1 must add the actual deny policy; M0 does not authorize access.

[Core tests](../tests/Core/PackageBootTest.php) cover provider boot, namespaced
configuration, publish registration and optional dependency absence. Runtime
registration neither installs the test bridges nor replaces `entitlements` config.

## Handoff

- M0 scaffold, dependency matrix, test harness and compatibility findings: implemented.
- Remote CI and production adapter acceptance: still open; green characterization
  tests are not a waived safety gate.
- Start M1 with typed facts and status/price mapping. Retain the reviewed schema
  responsibilities; production migrations belong to the corresponding persistence slice.
- No Stripe requests, webhook handlers, background jobs, shared cache or production
  usage/grant tables are implemented here. No dunning package files were changed.
