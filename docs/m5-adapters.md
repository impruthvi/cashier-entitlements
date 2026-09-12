# M5: Masterix and Pennant adapters

M5 connects the applied entitlement projection to two existing ecosystem packages: it
projects a decision onto [Masterix entitlements](https://github.com/masterix21/laravel-entitlements)
licence assignments, and exposes resolved allowances through a read-only
[Pennant](https://github.com/laravel/pennant) store. Both are optional, both are off by
default, and neither replaces the native resolver as the strict authorization path.

This is a development milestone, not a production release or a live Stripe certification.
Read the supported-behavior tables below before enabling either adapter: each one is
deliberately narrower than the upstream package's full surface.

## Installation

First follow [M3 setup](m3-refresh.md) and [M4 usage](m4-usage.md). M5 adds one migration
to the same tag, so republish and review before running it:

```sh
php artisan vendor:publish --tag=cashier-entitlements-migrations
php artisan migrate
```

`cashier_entitlement_driver_bindings` records which external licence group this package
created for an owner. It is additive and it does not touch the M3 core migration or the
M4 usage tables, so an existing installation upgrades by publishing and migrating only.
The table is created even when no adapter is configured, so enabling one later needs no
second migration. Rolling it back discards the binding and orphans any licence group this
package assigned; treat rollback as destructive and close those groups first.

## Pennant bridge

Register the store against the driver name this package extends, then read entitlements
through it. Nothing else in your Pennant configuration changes, and your default store
keeps working alongside it.

```php
// config/pennant.php
'stores' => [
    'array' => ['driver' => 'array'],
    'billing-access' => ['driver' => 'cashier-entitlements'],
],
```

```php
use Impruthvi\CashierEntitlements\Bridges\Pennant\OwnerScope;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Laravel\Pennant\Feature;

$scope = new OwnerScope(app(OwnerLocator::class)->reference($organization));

Feature::store('billing-access')->for($scope)->active('ai');      // boolean feature
Feature::store('billing-access')->for($scope)->value('projects'); // numeric limit
Feature::store('billing-access')->for($scope)->all();             // whole catalog, one query
```

The scope is always explicit. Pennant's default scope, a bare model, a string and `null`
are all rejected with `explicit_pennant_owner_scope_required`, because an entitlement
answer for an unnamed tenant is never meaningful. An `OwnerScope` built from an
unidentifiable owner is rejected at construction with `invalid_pennant_owner`.

Boolean features answer through `active()`; numeric features answer through `value()`.
Do not use `active()` on a numeric feature: Pennant treats both `0` and `null` as active,
so a customer with zero projects would read as allowed. `null` is the unlimited
representation and survives `value()` unchanged. An owner with no applied state reads
`false` for boolean features and `0` for numeric ones.

`defined()` returns the application catalog, so features come from your `PriceCatalog`
rather than from Pennant definitions. Every write is refused: `define`, `activate`,
`forget`, `activateForEveryone` and `purge` all throw `LogicException`. Billing decides
these values, not application code.

### Snapshot semantics, and where they are not enough

Pennant's decorator caches each value for the life of the process. This bridge does not
change that, so a value read through Pennant can be stale in three ways:

| Situation | What Pennant returns | Boundary that refreshes it |
|---|---|---|
| A refresh applies a new grant mid-request | the older value | `Feature::store(...)->flushCache()` |
| An entitlement expires on elapsed time alone, with no event | the pre-expiry value | `Feature::store(...)->flushCache()` |
| A queued job finishes and the next one starts | the fresh value | automatic, Pennant flushes on `JobProcessed` |

Flush between logical work units, after any mutation that could change billing state, and
around time boundaries inside long-running work. Pennant flushes automatically after each
processed job and on Octane request, task and tick events.

**Use the native resolver, not Pennant, wherever the answer must be strictly fresh.**
`LocalResolver::for($owner)->can()` and `->limit()` read committed state at the current
instant, and `NativeUsage::admit()` remains the only concurrency-safe limit enforcement.
Pennant is for display, gating that tolerates a request-lifetime snapshot, and
interoperability with feature-flag code you already have.

A batch reads once per distinct owner rather than once per feature, so a three-feature
catalog costs one query, and `for([$a, $b])->load(...)` costs two.

## Masterix driver

```php
// config/cashier-entitlements.php
'driver' => 'masterix',
'masterix' => [
    'plans' => ['pro' => 1, 'scale' => 2],
],
```

The map is required and its keys are your application plan keys, the same ones your
`PriceCatalog` price mappings declare. Its values are Masterix plan keys. With the driver
configured, every successful refresh projects its decision onto Masterix inside the same
transaction and connection that commits the native projection.

This package owns exactly one licence group per owner, recorded in its binding row. It
never touches a group it did not assign, so a group your own code assigned to the same
owner survives untouched.

| Operation | Behavior |
|---|---|
| First paid plan | Assigns the mapped plan and records the binding |
| Unchanged decision, applied again | No-op. Upstream assignment is additive, so a retried refresh must not assign twice |
| Upgrade or any other plan change | Transitions the bound group immediately and rebinds to the new anchor, keeping consumed slots |
| Downgrade below current usage | Refused. The exception aborts the refresh, the previous grant stays, nothing is recorded as applied |
| Denied decision, or a free plan | Ends the bound group and deletes the binding. Applying it twice changes nothing further |
| Usage | Never written. The native ledger is the single consumption authority |

Refusals surface as `ReadFailure`: `unmapped_masterix_plan` when the catalog resolves a
plan key the map does not contain, `unknown_masterix_plan` when the mapped plan is missing
or inactive, `masterix_binding_missing` when the bound group has been deleted underneath
this package, and `masterix_connection_mismatch` when the owner, the state store and the
driver do not share one connection. Each one fails the refresh and leaves the request
outstanding, so a fixed configuration converges on the next attempt.

### Narrowed support, and what remains unproven

These limits are deliberate, and each one is a consequence of upstream behavior recorded
in the [M0 compatibility findings](m0-compatibility.md).

- **Integer-keyed owners only.** Upstream's published licences migration declares
  `morphs('subscriber')`, so a UUID owner has no column to bind to. Such an owner is
  refused with `masterix_unsupported_owner_key` rather than silently truncated.
- **One group per owner.** Multiple concurrent paid plans for a single owner are not
  modeled. Add-on prices contribute allowances to the native projection, not to a second
  Masterix assignment.
- **Closure is a scoped validity write, not an upstream API.** Masterix has no
  close-assignment method, so this driver ends the bound group by setting `ends_at` on
  exactly those rows, scoped by subscriber and anchor. It preserves usage history and
  other groups, and it is idempotent. It does not emit a close event, because upstream
  defines none.
- **Upstream events can outlive a rolled-back apply.** `PlanAssigned` and the transition
  events dispatch inside upstream's own transaction, before this package's enclosing
  transaction commits. Database state is atomic, so a rollback leaves no licences and no
  binding, and a retry re-assigns correctly. A listener that performs an external side
  effect may still observe an assignment that never committed. Do not put irreversible
  external work in a Masterix event listener while this driver is enabled.
- **Not certified against custom configured models, custom connections or a concurrency
  test on PostgreSQL or MySQL.** The verification below runs on SQLite with upstream's
  default models. Do not read it as a claim about those configurations.

## Verification

Local as of 12 September 2026: 247 tests / 906 assertions, separate PostgreSQL
multi-process coverage (2 tests / 15 assertions), Larastan level 8, Pint and
`composer validate --strict`.

Evidence for this milestone lives in
[`tests/Compatibility/MasterixDriverTest.php`](../tests/Compatibility/MasterixDriverTest.php)
and [`tests/Compatibility/PennantBridgeTest.php`](../tests/Compatibility/PennantBridgeTest.php).
Both exercise the real upstream packages: upstream's own published migration stubs and
facade for Masterix, Pennant's real manager and decorator for the bridge.

The dependency matrix passed on PHP 8.3 with Laravel 12 and 13 at both lowest and highest
resolvable graphs, and with each optional package installed alone, which proves the
adapters stay absent when their dependency is. A fresh `--no-dev` consumer install still
boots, publishes and migrates with no adapter configured and resolves the native-only
driver.

## What M5 does not include

No account-wide automatic sweeps, no scheduled convergence, no Stripe metered-usage
reporting, no counter pruning and no live Stripe sandbox contract. Those remain M6 work,
along with the dropped-event convergence suite and the release gate.
