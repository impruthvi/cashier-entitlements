# M2: read-only provider comparison

This records the M2 diagnostic milestone. In the current M3 build, invocations without
`--apply` retain these read-only guarantees; explicit owner application, jobs and storage
are documented in [M3 refresh](m3-refresh.md). References below to apply refusal or
missing migrations describe M2, not the current package.

M2 reads current Stripe subscriptions, normalizes M1 billing facts, reads the local
Cashier projection and reports differences. It does **not** apply grants, repair
Cashier, mutate Stripe, persist audit metadata, register webhooks/jobs, or change
customer access. All command invocations are dry-runs, independent of `enabled`.

The comparison is between Cashier-derived allowances and Stripe-proposed allowances.
Neither is an observation of actual applied access. That resolver/storage is M3.

## Install and configure

The core still boots without optional dependencies. For diagnostics, install the
supported Cashier Stripe integration (`laravel/cashier:^16.8`), configure its customer,
subscription and item models, migrate **your application's** Cashier tables, and set
its credentials. M2 supplies no migrations. Standard/restricted `sk_test_`/`rk_test_`
or `sk_live_`/`rk_live_` credentials are supported, not arbitrary OAuth token formats.
Use only the read permissions needed by this package when testing diagnostics.

Register the configured billable model under a stable morph alias and bind a typed
catalog in your application provider. Example (adapt the organization model):

```php
use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\Relation;
use Impruthvi\CashierEntitlements\Billing\{PriceCatalog, PriceMapping};
use Laravel\Cashier\Cashier;

// In boot(): merge this alias with your application's existing morph map.
Cashier::useCustomerModel(Organization::class);
Relation::morphMap(['organization' => Organization::class]);

// In register(): the catalog must match the configured Stripe account and mode.
$this->app->singleton(PriceCatalog::class, fn () => new PriceCatalog(
    version: 'v1',
    prices: ['price_pro' => new PriceMapping('pro', ['projects' => 10, 'ai' => true])],
    providerContext: 'platform',
    liveMode: false,
));
```

Published `cashier-entitlements` configuration:

```php
'enabled' => false,                  // Reserved for future automatic application.
'provider_context' => 'platform',     // Or an explicit Stripe Connect acct_… ID.
'live_mode' => false,                 // Must match the credentials and catalog.
'subscription_type' => 'default',     // Which billing domain contributes allowances.
```

One configured billable model/database connection belongs to this selected provider
context/mode. M2 does not infer per-row Connect accounts or partition mixed-mode rows.
Use separate correctly scoped model/connection configurations for those installations.
Integer and UUID keys work; the command accepts registered aliases, never a caller's
arbitrary model class name. It uses the configured model's connection and relationships.

For a custom Cashier dashboard subscription-type fallback or different bounds, bind
`StripeSubscriptionSource` yourself. Its constructor accepts a Stripe client, context,
mode, `maxPages` (default 100), `defaultSubscriptionType` (default `default`), and
`maxRecords` (default 10,000). The selected billing domain and dashboard fallback are
different concepts: configure the fallback to match your Cashier webhook customization.
Bind `PriceMapper` with an explicit `StatusPolicy` to change M1's payment-status opt-ins.

The source makes a dedicated SDK client using the supplied client's API key and API
base. Account/context defaults and client subclasses are **not inherited**: the source's
explicit context is authoritative. This works on old SDKs without context getters and
prevents an injected client's hidden Connect defaults from changing the read scope.
Do not change the API base except for a controlled test server. SDK HTTP transport is
the external test seam; no real credentials or Stripe calls are used in the test suite.

## Commands

```sh
# Default and explicit dry-run have identical behavior.
php artisan entitlements:reconcile --owner-type=organization --owner=123 --json
php artisan entitlements:reconcile --owner-type=organization --owner=123 --dry-run

# Live-mode account discovery plus local-owner comparison.
php artisan entitlements:reconcile --owner-type=organization --all --json

# Test-mode account discovery MUST name a test clock.
php artisan entitlements:reconcile --owner-type=organization --all --test-clock=clock_123 --json
```

An owner read is always customer-scoped, including test-clock subscriptions.
Test-clock audits restrict discovery and local customer matching to that clock;
customerless free owners are excluded from a clock-specific audit. Live account audits
also include local customerless owners. Unknown remote customers are reported, never
created or bound automatically. Owners hidden by application scopes remain unknown.
Duplicate customer ownership is an error, including duplicate rows hidden by a global
scope when projecting a selected owner.

`--apply` always fails before any provider request. `--owner` cannot be combined with
`--all`; `--test-clock` is only supported with `--all`. There is no `--resume`, durable
audit run, scheduled sweep or doctor command yet. Re-run a failed audit from the start.
These bounded, synchronous commands target diagnostics, not production-scale recovery.

Exit codes: **0** clean (or only expected drift), **1** drift/unknown customer,
**2** configuration, input, read or mapping error. Without `--json`, the same JSON is
pretty-printed. Every response has `schema_version: 1`, `mode: dry-run`, `complete`,
`errors` and `exit_code`. Owner success adds identity, observation time, catalog version,
local/proposed decisions and differences. Account success adds scope, owner reports and
unknown customer IDs. Mapping errors can have `complete: true`: intake succeeded but
the proposed decision is invalid. Read failures return no proposed decision, no partial
differences and no partial owner reports. Provider/SQL exception bodies are not printed.

## Read and normalization guarantees

- Requests use SDK services, `status=all`, customer or explicit test-clock scoping,
  explicit `starting_after` cursors, and every subscription/item page. Nested embedded
  item lists are not assumed complete: each subscription's item collection is listed.
  Endpoints are fixed in the adapter, never taken from a response URL.
- API version is pinned to **2025-06-30.basil**, independent of the SDK's latest default.
  Fixtures use that normalized field shape and explicit context/mode. SDK/network
  retries are set to two per request; exhaustion fails the snapshot. Transport timeouts
  remain the SDK/application HTTP-client configuration, not a whole-command deadline.
- Provider/customer/subscription identity, live mode, page shape, unique subscription
  IDs, item parentage, types and dates are checked. Unknown status, malformed data,
  missing trial/cancellation boundary, a repeated page, or a partial read fails closed.
- `cancel_at` is the scheduled boundary. `canceled_at` is not used as a scheduled end,
  and a normal item renewal end is not cancellation. A true cancel-at-period-end flag
  without an explicit cancel boundary is rejected rather than guessed. Trial/terminal
  eligibility and quantity/price rules remain M1's responsibility.
- Subscription type comes from `metadata.type`, then `metadata.name`, then the explicit
  dashboard fallback. Deleted/invalid metadata is not silently treated as a valid map.
- Local IDs missing from a complete remote list are retrieved individually in the same
  provider context. Only a 404 with `resource_missing` confirms absence. A recovered
  resource must still match the exact subscription/customer/mode. Permission errors
  never mean cancellation. Remote `incomplete_expired` with no local row is expected
  Cashier drift and does not by itself produce exit 1.
- Cashier is read through fresh configured relationships, including canceled rows and
  custom owner/subscription/item models. Cached relations and nullable parent price/
  quantity are ignored. No `active()`, `valid()` or `recurring()` call determines access.
- Stock Cashier has no item-period columns. Its unknown periods are not reported as
  contradictory values or guessed from the calendar. Available provider item periods
  are preserved in `BillingSnapshot`; this milestone does not persist them for usage.
- All pages must succeed before returning `BillingSnapshot`. Completeness means a
  successful bounded traversal, **not** atomic isolation across multiple DB/API reads.
  Concurrent changes may require another run. `observed_at` is read-start time, not a
  guarantee that an observation is permanently fresh.
- Default bounds are 100 pages per collection (100 records per page), 10,000 combined
  subscription/item facts per owner, 10,000 local subscriptions/items, and 10,000 owners
  per account audit. Hitting a bound fails instead of treating unscanned data as absent.
  Account reports are buffered until success; use owner reads for large installations.

## Verification

Local verification on 11 September 2026: **177 tests / 569 assertions**, plus Pint,
Larastan level 8 and strict Composer validation. These are scenario counts, not measured
line coverage. The matrix below uses existing isolated lockfiles with current M2 source
and tests copied in; it is not a claim of fresh dependency resolution or GitHub CI.

| PHP | Laravel | Stripe SDK | Graph |
|---|---|---|---|
| 8.3 | 12.61.1 / 13.12.0 | 17.4.0 | Lowest-compatible installed graphs |
| 8.3 | 12.69.2 / 13.31.0 | 21.3.2 | Highest-compatible installed graphs |
| 8.4 | 12.69.2 / 13.31.0 | 21.3.2 | Highest-compatible installed graphs |
| 8.5 | 13.31.0 | 21.3.2 | Highest-compatible installed graph |

Cashier-only, no-optional, Masterix-only and Pennant-only installed graphs are checked
independently. The fresh Laravel 13 consumer (`build/consumer.AfdIdA`) booted, discovered
the provider, published config and calculated M1 allowances without Cashier/Stripe,
Masterix, Pennant or Testbench. These results are local verification, not GitHub CI evidence.

Tests exercise the real Stripe SDK with a fake HTTP transport and real SQLite Cashier
relationships. Coverage includes omitted creation/update/cancellation, both comparison
directions, confirmed absence, nested pagination, 10,000-record discovery, rate-limit/
permission/timeout/malformed responses, context isolation, UUID/custom models, unknown
owners, failed scans, invalid mappings, and read-only SQL/HTTP assertions. TDD's confirmed
boundaries were the source, local projector and diagnostic command.

No live Stripe sandbox contract or production database was contacted. Passing mocked
SDK tests does not establish a live API contract, measured production performance,
transactional locking safety, or applied-grant convergence. Those remain explicit gates.

Primary references: [Stripe subscription listing and test-clock scope](https://docs.stripe.com/api/subscriptions/list),
[item pagination](https://docs.stripe.com/api/subscription_items/list),
[subscription fields](https://docs.stripe.com/api/subscriptions/object),
[Cashier webhook projection](https://github.com/laravel/cashier-stripe/blob/16.x/src/Http/Controllers/WebhookController.php).

Next: M3's durable refresh requests, generation fencing, atomic native grants and
local-only applied-state resolution. Masterix/Pennant production gates remain open.
