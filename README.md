<p align="center">
  <img src="art/logo.svg" alt="Cashier Entitlements" width="128">
</p>

<h1 align="center">cashier-entitlements</h1>

<p align="center">
  <strong>Answer what a customer can access from your own database, not from Stripe.</strong>
</p>


[![Latest Version on Packagist](https://img.shields.io/packagist/v/impruthvi/cashier-entitlements.svg?style=flat-square)](https://packagist.org/packages/impruthvi/cashier-entitlements)
[![Tests](https://github.com/impruthvi/cashier-entitlements/actions/workflows/tests.yml/badge.svg)](https://github.com/impruthvi/cashier-entitlements/actions?query=workflow%3ATests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/impruthvi/cashier-entitlements.svg?style=flat-square)](https://packagist.org/packages/impruthvi/cashier-entitlements)

Local entitlement resolution and background billing reconciliation for Laravel Cashier.

Answer "what can this organization do right now?" from your own database — no Stripe call
in the request path — and repair that answer in background work when a webhook never
arrives.

## The problem

Most applications wire access straight to subscription state: read the Stripe row, allow
or deny. Two things go wrong.

**Your features end up living in Stripe.** Feature keys land in price metadata or a JSON
column on a provider-synced plan row, and renaming a capability becomes a dashboard edit.
Stripe knows about prices. It should not know your application has a feature called
`ai.generate`.

**Your local copy silently drifts.** A webhook is missed during a deploy and never
retried. A subscription's renewal was never set up, so nothing ever failed — it just
stopped billing. No event misfired, so no replay or event-driven test will find it. The
only thing that catches this class of bug is periodically comparing what your application
calls active against what the provider calls active.

This package draws the boundary: Stripe's answer is an *input*, your application owns the
features, resolution is local, and a bounded sweep repairs owners no event ever mentioned.

## Installation

```sh
composer require impruthvi/cashier-entitlements
```

Requires PHP 8.3+ and Laravel 12 or 13. [Laravel Cashier](https://laravel.com/docs/billing)
^16.8 is optional at install but required for billing diagnostics and reconciliation —
native storage and resolution run without it.

```sh
php artisan vendor:publish --tag=cashier-entitlements-config
php artisan vendor:publish --tag=cashier-entitlements-migrations
php artisan migrate
```

Review the migration before running it. Tables are never created automatically at boot.
Cashier's tables and these tables must use the same concrete database connection.

## Quickstart

### 1. Own your catalog

Map provider price IDs to *your* feature keys, in your application, in a service provider:

```php
use Illuminate\Database\Eloquent\Relations\Relation;
use Impruthvi\CashierEntitlements\Billing\{PriceCatalog, PriceMapping};
use Laravel\Cashier\Cashier;

// boot(): merge with your application's existing morph map.
Cashier::useCustomerModel(Organization::class);
Relation::morphMap(['organization' => Organization::class]);

// register(): must match the configured Stripe account and mode.
$this->app->singleton(PriceCatalog::class, fn () => new PriceCatalog(
    version: 'v1',
    prices: [
        'price_base' => new PriceMapping('base', ['projects' => 2,  'ai' => false]),
        'price_pro'  => new PriceMapping('pro',  ['projects' => 10, 'ai' => true]),
    ],
    providerContext: 'platform',
    liveMode: false,
));
```

Changing the catalog requires a new `version`, a worker restart and fresh owner requests.

### 2. Choose an outage policy

There is no silent default. Configure one in `config/cashier-entitlements.php`:

```php
'enabled' => true,
'live_mode' => false,
'freshness' => ['max_stale_age' => 3600],
// Alternative: 'freshness' => ['retain_last_known' => true],
```

`max_stale_age` denies paid allowances once the last successful provider read passes that
age in seconds. `retain_last_known` keeps paid access during a provider outage
indefinitely — use it only if that tradeoff is acceptable. Both still enforce known trial
and cancellation expiry, denied states and catalog-version mismatch.

### 3. Resolve access

```php
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;

$owner  = app(OwnerLocator::class)->reference($organization);
$access = app(LocalResolver::class)->for($owner);

$access->can('ai');            // bool — boolean features only
$access->limit('projects');    // int; null is unlimited, zero is none
$access->usage('projects');    // int consumed in the current period
$access->remaining('projects'); // int, or null for unlimited
$access->all();                // the whole allowance map in one query
```

One local query. No Stripe call, no Cashier query, no request-scoped cache. Unknown
feature names throw `UnknownFeature`; calling `can()` on a numeric feature (or `limit()`
on a boolean) throws `FeatureTypeMismatch`.

These are allowances, not permissions. Evaluate membership and RBAC separately.

### 4. Enforce a limit atomically

`remaining()` is informational. For a hard limit, `admit()` decides and measures inside
the same transaction as your domain write:

```php
use Impruthvi\CashierEntitlements\Usage\NativeUsage;

$receipt = app(NativeUsage::class)->admit(
    $owner, 'projects', 1, $requestId, $resolver,
    function (Connection $db, UsageReceipt $receipt) {
        $db->table('projects')->insert(['id' => $receipt->id, /* ... */]);
    },
);
```

Either both commit or neither does. Exceeding the resolved limit throws `LimitExceeded`
before the callback runs. Admission serializes per owner, so concurrent requests queue
rather than each reading a stale total.

Two rules the callback must respect: use only the supplied connection (external effects
are not covered by the rollback), and stay free of non-transactional side effects — the
transaction retries up to three times on deadlock.

Declare a reset rule for every metered feature. There is no default:

```php
'meters' => [
    'projects'  => 'calendar_month',
    'exports'   => 'calendar_day',
    'seats'     => 'billing:price_pro',  // exact Stripe period boundaries
],
```

### 5. Keep it current

Request a refresh whenever billing changes, and let the worker apply it:

```php
app(RefreshManager::class)->request($organization);
```

Requests are durable: they commit before dispatch, survive a failed queue push, and a
killed worker's claim becomes reclaimable after its lease expires. When enabled, the
package also listens to Cashier's `WebhookHandled`, re-verifies the signature against the
actual request, and checks provider account and live mode before requesting work.

Then schedule the two operational commands:

```php
Schedule::command('entitlements:recover --limit=100')->everyMinute()->withoutOverlapping();
```

Plus the sweep, configured in `cashier-entitlements.schedule`. **Recovery and the sweep do
different jobs.** Recovery re-enqueues requests that already exist. The sweep enumerates an
owner scope and requests a refresh for every owner whose observation has gone stale —
that is the part that catches an event you never received. Run it comfortably more often
than `max_stale_age`.

### 6. Check health

```sh
php artisan entitlements:doctor --json
php artisan entitlements:reconcile --owner-type=organization --owner=123 --dry-run --json
```

`doctor` reports configuration, backlog, observation staleness, failure reasons and
schedule health, locally. `reconcile --dry-run` compares live Stripe against Cashier
without changing any customer's access.

## Audited overrides

Time-bound, reasoned, append-only exceptions that outrank the plan:

```php
app(NativeOverrides::class)->grant($owner, 'projects', 25,
    'Enterprise trial extension, ticket 4821', 'admin:'.$actor->id, $from, $until);
```

An expiry is required — there is no permanent override. Off by default; enabling adds one
query per resolve.

## What this does not do

- Replace Cashier checkout, or manage subscriptions.
- Manage RBAC, roles or permissions.
- Call Stripe during authorization, ever.
- Report usage to Stripe metered billing.
- Prune usage counters or receipts (retention is permanent, which is what keeps
  deduplication correct).

## Optional adapters

Both are off by default and neither is registered automatically.

- **[Masterix](https://github.com/masterix21/laravel-entitlements)** — license assignment
  for integer-keyed owners, one licence group per owner. Custom models, connections and
  adapter concurrency are uncertified.
- **[Pennant](https://laravel.com/docs/pennant)** — read-only store over the local
  resolver, reading a request-lifetime snapshot rather than strictly fresh state.

Each is deliberately narrower than its upstream package. Read
[docs/m5-adapters.md](docs/m5-adapters.md) before enabling either.

## Documentation

| Doc | Covers |
|---|---|
| [m0-compatibility.md](docs/m0-compatibility.md) | Dependency matrix and upstream behavior boundaries |
| [m1-billing.md](docs/m1-billing.md) | Typed billing facts, status rules, price mapping |
| [m2-diagnostics.md](docs/m2-diagnostics.md) | Catalog binding, read-only Stripe/Cashier comparison |
| [m3-refresh.md](docs/m3-refresh.md) | Durable refresh, webhooks, local resolution, failure guarantees |
| [m4-usage.md](docs/m4-usage.md) | Meters, periods, atomic admission, override ledger |
| [m5-adapters.md](docs/m5-adapters.md) | Masterix and Pennant supported scope |
| [m6-operations.md](docs/m6-operations.md) | Account sweep, doctor, scheduled convergence |
| [sandbox-validation.md](docs/sandbox-validation.md) | Live Stripe sandbox verification record |

## Verification

The release gate runs the full dependency matrix rather than one convenient lane:
Laravel 12 and 13, PHP 8.3/8.4/8.5, lowest and highest dependency resolution, and
independent no-optional / Cashier / Masterix / Pennant graphs. It also runs a separate
PostgreSQL multi-process test in which competing workers contend for the same owner and a
paused worker is proven unable to overwrite its replacement, plus a fresh-consumer install
that publishes and migrates without Testbench or any optional package.

An opt-in live Stripe contract suite runs against a sandbox; its credentials are excluded
from the default suite and never enter pull-request CI.

```sh
CASHIER_ENTITLEMENTS_SANDBOX_KEY=sk_test_... bash scripts/test-sandbox.sh
```

## Known limits at 0.1.0

Stated plainly so you can judge fit:

- Public webhook delivery to a deployed HTTPS host is unverified. Delivery has been
  validated locally against real signed sandbox events.
- MySQL locking behavior is unverified; PostgreSQL and SQLite are covered.
- Usage is not reported to Stripe metered billing.
- Usage counters and receipts are never pruned.
- Aggregate cross-owner usage listing is not built.
- One active catalog version per billing context. Mixed-version workers are not a
  supported deployment mode.
- Mixed Stripe Connect accounts or live/test modes in one configured connection are not
  partitioned.

## Development

```sh
composer install
composer check                 # test, analyse, format:check
composer release-gate          # everything a release candidate must pass

bash scripts/test-matrix.sh 12 all lowest
bash scripts/test-matrix.sh 13 all highest
bash scripts/test-matrix.sh 12 none highest
bash scripts/test-consumer.sh
bash scripts/test-postgres.sh  # needs local PostgreSQL binaries and pdo_pgsql
```

`test-matrix.sh` takes Laravel `12|13`, optional dependencies
`all|none|cashier|masterix|pennant`, and `lowest|highest`. Each run resolves into a
separate gitignored `build/` directory, preserving your development dependencies.
`PHP_BINARY` and `COMPOSER_BINARY` select executables.

The ten-thousand-subscription scaling test needs more than PHP's 128M default;
`composer test` and the matrix script both run with 256M.

Configuration publishes under `cashier-entitlements`, not Masterix's `entitlements`.

## License

MIT. See [LICENSE.md](LICENSE.md).
