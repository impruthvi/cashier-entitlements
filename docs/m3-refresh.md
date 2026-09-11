# M3: durable refresh and local access

M3 applies authoritative billing observations to a native local projection. Authorization
reads only that projection: it never falls back to Cashier rows or Stripe. This is a
development milestone, not a production release or a live Stripe contract certification.

## Installation

First follow [M2 setup](m2-diagnostics.md): install Cashier, configure its billable model,
register a stable morph alias and bind an application-owned `PriceCatalog`. Cashier's
tables and the native tables must use the **same concrete database connection name**.
Set `cashier-entitlements.connection` to that name, or leave it null for the default.
Integer and UUID owner keys are supported. Keep customer IDs unique within this context;
the package rejects detected ambiguous ownership, but does not add an index to your model.

Publish and review the migration before running it in your application:

```sh
php artisan vendor:publish --tag=cashier-entitlements-config
php artisan vendor:publish --tag=cashier-entitlements-migrations
php artisan migrate
```

For a non-default connection, run the migration with `--database=<connection>` matching
the billable model and package configuration. Tables are not created automatically at boot.

Explicitly configure one outage policy before enabling refresh:

```php
'enabled' => true,
'connection' => null,
'provider_context' => 'platform',
'live_mode' => false,
'subscription_type' => 'default',
'freshness' => ['max_stale_age' => 3600],
// Alternative: 'freshness' => ['retain_last_known' => true],
```

Neither policy is chosen silently. The age limit is in seconds from the start of the
last successfully applied provider read. At the exact limit, paid allowances are denied.
An explicitly applied free plan does not expire merely because its observation ages.
`retain_last_known` can retain paid access indefinitely during an outage; use it only
if that tradeoff is acceptable. Both policies still enforce known trial/cancellation
expiry, denied states and catalog-version mismatch. Missing state grants no access,
including free allowances until their first successful application.

Changing the catalog requires a new version, worker restart and fresh owner requests.
Deploy one active catalog version per configured billing context; mixed-version workers
are not an independently supported deployment mode.

## Request, apply and recover

```php
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;

// $organization is your persisted, configured Cashier billable model.
app(RefreshManager::class)->request($organization);
```

The request commits durable pending state before dispatch. Inside an application
transaction, dispatch waits for its commit; rollback removes the request. A queue push
failure does not lose the request. Configure a real asynchronous queue and run workers;
Laravel's sync queue is supported for testing but performs the provider read inline.
Refresh itself rejects an already-open database transaction.

```sh
# Existing diagnostics remain read-only, even with enabled=true.
php artisan entitlements:reconcile --owner-type=organization --owner=123 --dry-run --json

# Explicit synchronous, native application for one owner.
php artisan entitlements:reconcile --owner-type=organization --owner=123 --apply --json

# Re-enqueue pending requests after lost pushes, failures or expired claims.
php artisan entitlements:recover --limit=100 --json
```

Apply JSON uses schema version 1, `mode: apply`, completion, result and applied version.
Exit codes are 0 for completed work, 1 for still-pending/superseded work, and 2 for errors.
`--apply` refuses `--all`, `--test-clock` and `--dry-run` combinations. Recovery accepts
limits 1–1000 and returns a dispatched-job count, not a successful-application count.
Per-owner failures remain pending with a safe error and 60-second backoff; recovery can
continue past a deleted owner. Queue delivery errors return exit 2.

Schedule pending recovery, for example in your application's `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('entitlements:recover --limit=100')->everyMinute()->withoutOverlapping();
```

**Pending recovery does not discover missed events or refresh otherwise idle owners.**
Until the M6 account sweep exists, the application must periodically enumerate its known
owners and call `request()` at an interval comfortably shorter than `max_stale_age`.
Size that work for provider limits and queue capacity. Without it, paid observations
will become stale even when billing has not changed. Recovery does not self-register a
scheduler task. Monitor backlog, observation age and errors; M6's doctor is not implemented.

Jobs use a 240-second timeout, three queue attempts, a 60-second retry backoff and a
300-second database lease. Configure the queue's `retry_after`/visibility timeout longer
than the job timeout. A killed worker remains reclaimable after lease expiry; duplicate
deliveries are safe but can add queue load. Bound the Stripe HTTP transport appropriately.

## Webhooks

When enabled, the package listens to Cashier's `WebhookHandled`. It re-verifies the actual
request signature against `cashier.webhook.secret`, matches the request to the event and
checks provider account/live mode before requesting work. Receipts deduplicate by provider
context, mode and event ID, not delivery order. Event payloads never supply applied grants:
the worker retrieves current resources, so an old event cannot directly restore old access.

Supported hooks are subscription created/updated/deleted and invoice payment
succeeded/failed. Stock Cashier does not emit `WebhookHandled` for an invoice-failed event
without a corresponding handler; use the subscription-update path or an application
Cashier handler for that case. Manually dispatching this event outside its verified HTTP
request is refused. Application billing changes can call `request()` directly.
Unknown customers are logged by event ID and never assigned to a newly created owner.
This package does not rewrite Cashier or Stripe; Cashier's own webhook behavior is unchanged.

## Local access

```php
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;

$owner = app(OwnerLocator::class)->reference($organization);
$access = app(LocalResolver::class)->for($owner);
$canUseAi = $access->can('ai');       // Boolean features only.
$projects = $access->limit('projects'); // Integer; null means unlimited, zero means none.
$all = $access->all();                // One local query for the entire applied allowance map.
```

Feature names/types come from the catalog. Unknown names throw `UnknownFeature`; using
`can()` for a numeric feature or `limit()` for a boolean throws `FeatureTypeMismatch`.
An absent known feature resolves false/zero. These are allowances, not usage remaining
or RBAC decisions. Evaluate membership/permissions separately.

Each method uses fresh local state and current time, without process/request caching.
Use `all()` to batch reads. An optional explicit evaluation time exists for deterministic
tests, not to freeze access in a long-lived worker. Native storage/resolution can run
without Cashier, Stripe, Masterix or Pennant; `OwnerLocator` and billing refresh require
Cashier. A caller supplying an `OwnerReference` must select its trusted organization;
the resolver does not authorize arbitrary user-supplied organization identifiers.

## Persistence and failure guarantees

The native implementation deliberately keeps allowances, normalized observations,
canonical hash/version and completion metadata in one owner-state row, rather than
introducing an external-driver abstraction now. Receipts live in a separate table.
Rows are keyed by canonical owner alias/key, connection, provider context and mode.
Only necessary normalized facts/IDs are stored, not full webhook bodies or secrets.
Receipt retention is permanent in M3; no automatic pruning undermines deduplication.

1. A short transaction increments requested sequence and records an optional receipt.
2. A worker claims a generation, request sequence and lease under a row lock.
3. Stripe pages are fetched outside database transactions. Previously applied IDs and
   Cashier IDs missing from the list are individually verified through the M2 source.
4. Application locks the billable row, rechecks customer ownership and checks generation,
   lease and request sequence before atomically replacing native state.
5. A newer request or worker fences a late writer. Identical grants preserve applied
   version while still advancing observation/completion. Failures preserve earlier grants
   subject to the local policy and leave pending work recoverable.

No distributed lock service, cross-connection transaction or external driver write is
claimed. The read is a complete bounded traversal, not an atomic Stripe snapshot.
Normalized observations are stored per owner, not in a separately queryable subscription
ledger; usage-period indexing and external driver contracts remain later milestones.
These transaction/queue boundaries use Laravel's [after-commit dispatch](https://laravel.com/framework/docs/13.x/queues#jobs-and-database-transactions)
and [row locking](https://laravel.com/framework/docs/13.x/queries#pessimistic-locking).

## Verification and remaining gates

Local verification on 11 September 2026: 195 tests / 661 assertions, plus a separate
PostgreSQL multi-process test / 9 assertions. The regular suite skips that PostgreSQL
test unless its isolated runner is used. Pint, Larastan level 8 and strict Composer
validation pass. Tests cover rollback, failed queue push, serialized database-queue jobs,
signed/duplicate deliveries, competing requests, abandoned leases, late success/failure,
provider failure versus confirmed revocation, free/paid freshness and exact expiry.

Existing isolated dependency graphs pass with current source: Laravel 12/13 on PHP
8.3/8.4, Laravel 13 on PHP 8.5, lowest-compatible Stripe 17.4 and highest-installed 21.3,
plus independent no-optional/Cashier/Masterix/Pennant graphs. These are local reruns,
not fresh resolution of every graph or evidence of a GitHub CI run. A fresh Laravel 13
consumer (`build/consumer.hekLbG`) also published and ran the migration and used native
application/resolution without optional integrations or Testbench.

`bash scripts/test-postgres.sh` creates a private temporary PostgreSQL cluster and stops
it automatically. Separate processes contend for requests/receipts and prove that a
paused old worker cannot overwrite a replacement. Logs remain in its reported directory.
The workflow now includes this test, but has not been run remotely for these changes.

No live Stripe account or production database was contacted. MySQL locking, production
load, resumable account apply, full health reporting and unattended missed-event convergence
remain unverified/unimplemented. M4 adds usage/periods/admission/overrides; M5 adds gated
production adapters; M6 covers operational sweeps and release readiness.
