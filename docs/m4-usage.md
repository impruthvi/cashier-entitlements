# M4: usage, limits and audited overrides

M4 adds metered usage, plan-limit admission and an audited override ledger on top of the
M3 local projection. Limit checks read only local state: no Stripe call, no Cashier query.
This is a development milestone, not a production release or a live Stripe certification.

## Installation

First follow [M3 setup](m3-refresh.md): install Cashier, bind an application-owned
`PriceCatalog`, choose a freshness policy and enable native application. M4 adds three
migrations to the same tag, so republish and review before running them:

```sh
php artisan vendor:publish --tag=cashier-entitlements-migrations
php artisan migrate
```

Four tables are added. `cashier_entitlement_usage_counters` holds one total per owner,
feature and period. `cashier_entitlement_usage_events` holds one immutable row per
accepted idempotency key. `cashier_entitlement_overrides` holds the append-only ledger.
Billing-period boundaries live in `cashier_entitlement_billing_periods`, created by a
separate additive migration. The original M3 core migration is unchanged. Existing M3
installations must publish and run the new migrations before restarting refresh workers.
Then request a refresh for billing-meter owners to populate their observed periods.
The billing-period migration preserves a table already created by an early M4 install.
Do not force-republish or re-run old migrations. Rolling back the new billing-period
migration deletes period history; treat rollback as destructive and back up first.

Declare a period rule for every metered feature, and opt into the override ledger only if
you use it:

```php
'meters' => [
    'projects' => 'lifetime',
    'api_calls' => 'calendar_month',
    'exports' => 'calendar_day',
    'seats' => 'billing:price_pro',
],
'overrides' => false,
```

There is no default rule. Recording usage for a feature with no rule throws `ReadFailure`
with `missing_meter_period`, rather than guessing a period and mismeasuring the customer.
An invalid rule is rejected when `MeterPeriods` is resolved, not at the first increment.

`lifetime` maps every occurrence to one fixed period, so its total never resets. It is
appropriate for append-only stock only while every creation is metered and deletion does
not return allowance. The ledger remains append-only: there is no decrement or release
operation in this version.

Calendar periods are computed in UTC, so a customer in another timezone sees their quota
reset at UTC midnight or on the first UTC day of the month. A `billing:<price_id>` rule
uses the exact boundaries observed from Stripe for that price. It throws `ReadFailure`
with `missing_or_ambiguous_billing_period` when no applied observation covers the instant,
or when two distinct subscription items disagree. It never falls back to a calendar month.

Enabling `overrides` adds one query per resolve. Leave it false where you do not grant
exceptions, and resolution stays a single local query.

## Recording usage

The owner-scoped API shares the same catalog, connection and configured meter rules:

```php
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;

$access = app(LocalResolver::class)->for($owner);
$access->usage('api_calls');     // int, in the current period
$access->remaining('api_calls'); // int (possibly negative), or null for unlimited
$access->record('api_calls', 1, idempotencyKey: $requestId);
```

Denied numeric access has limit zero; its remaining value is zero minus recorded usage.
Unknown features and boolean meters throw named exceptions. Meter rules are required
even for unlimited features. Each call on a retained access object evaluates the current
time; `for($owner, $at)` pins reads and records to that explicit instant. Late records can
pass `occurredAt:` without changing the idempotency key. `remaining()` is informational,
not an atomic admission decision: use `NativeUsage::admit()` for hard limits.

```php
use Impruthvi\CashierEntitlements\Usage\NativeUsage;

$receipt = app(NativeUsage::class)->record($owner, 'api_calls', 1, $requestId);
$receipt->total;  // Total for the period after this increment.
$receipt->period; // Resolved UsagePeriod, in UTC.
```

The idempotency key is yours to choose and must be stable for the operation being counted,
unique per owner and feature, non-empty and at most 255 characters. A repeat of the same
key returns the original receipt with its original period, even across a period boundary:
a retry at 00:00:01 still counts against the previous day. A repeat of the same key with a
different quantity or occurrence time throws `IdempotencyConflict` and changes nothing.

Pass an explicit occurrence time to backdate a late-delivered event into the period it
actually belongs to. The occurrence may not be in the future. Quantities must be one or
greater, and a total that would exceed `PHP_INT_MAX` throws `OverflowException` rather
than wrapping. Metering a boolean feature throws `FeatureTypeMismatch`.

## Enforcing a limit

`record()` measures. `admit()` decides and measures in one transaction:

```php
$receipt = app(NativeUsage::class)->admit($owner, 'projects', 1, $requestId, $resolver,
    function (Connection $db, UsageReceipt $receipt) {
        $db->table('projects')->insert(['id' => $receipt->id, /* ... */]);
    });
```

The callback performs your domain write on the supplied connection, inside the same
transaction that reserves the usage. Either both commit or neither does. Exceeding the
resolved limit throws `LimitExceeded` before the callback runs. A null limit is unlimited.
Missing local state resolves to zero, so an owner with no applied projection is denied.

Two rules make this safe and must be respected by the callback:

1. Use only the supplied connection. External side effects such as HTTP calls, mail and
   queue pushes are not covered by the rollback. Dispatch them after the call returns.
2. The callback may run more than once. The enclosing transaction retries up to three
   times on a database deadlock, so keep the callback free of non-transactional effects.

Admission serializes per owner on the state row, so concurrent workers queue rather than
each reading a stale total. `assertConnection` rejects a resolver pointed at a different
connection, because a limit read outside the transaction would not be serialized.

## Audited overrides

```php
use Impruthvi\CashierEntitlements\Overrides\NativeOverrides;

$grantId = app(NativeOverrides::class)->grant($owner, 'projects', 25,
    'Enterprise trial extension, ticket 4821', 'admin:'.$actor->id, $from, $until);
```

Grants are append-only and always bounded: an expiry is required, and it must be after the
effective time. The allowance must match the catalog type for that feature, so granting a
boolean value for a numeric feature throws `FeatureTypeMismatch` and an unknown feature
throws `UnknownFeature`. A reason and an actor are mandatory and cannot be blank.
The same nonblank audit requirement applies to revocations; rejected revocations leave
both the active allowance and the immutable ledger unchanged.

At any instant the active grant for a feature is the one with the latest effective time,
breaking ties by insertion order. Revoking it uncovers the grant it superseded rather than
falling back to the plan, and expiry restores the plan allowance without a write.
`revoke()` records a second row; it never edits or deletes the original. `history()` reads
the ledger in recorded order with bounded cursor pagination.

The package stores who, why and when. It does not authenticate or authorize the actor, and
does not decide who may grant an override. That belongs in your application.

## Verification and remaining gates

Local verification after review fixes on 12 September 2026: 214 tests / 798 assertions, plus two separate
PostgreSQL multi-process tests / 15 assertions. The regular suite skips the PostgreSQL
tests unless `bash scripts/test-postgres.sh` is used. Pint and Larastan level 8 pass.
A fresh Laravel 13 consumer published and ran all four migrations, then exercised a
denied admission, an override grant, an admitted operation and a revocation without
optional integrations or Testbench. It also exercised the owner-scoped usage and negative
remaining API. Frozen M3 and early M4 migration fixtures verify upgrading without
re-running the core migration or losing applied state and existing billing-period history.
An isolated Laravel 12.69.2 install without Cashier, Masterix or Pennant passes
132 tests / 399 assertions. The complete PHP/optional-dependency matrix was not rerun
for this focused fix.

Tests cover transactional rollback of usage and domain work together, repeat and
conflicting retries, midnight and cross-period retries, backdated occurrences in UTC,
checked overflow, missing and ambiguous billing periods, catalog type mismatches, override
precedence, expiry, revocation and history. Rejected blank-reason/actor revocations leave
the ledger unchanged. Owner-scoped tests cover denied, negative and unlimited remaining,
unknown/boolean features, missing meter rules and long-lived-object rollover/retries.
The PostgreSQL test proves that two competing
processes cannot both consume a one-unit limit.

No live Stripe account or production database was contacted. MySQL locking, production
load and long-run counter growth remain unverified. Usage is not reported to Stripe metered
billing, counters are never pruned, and there is no aggregate reporting or per-feature
usage listing yet. M5 adds gated production Masterix and Pennant adapters;
[M6](m6-operations.md) adds the account sweep, the local doctor, scheduled convergence and
the release gate. Stripe metered-usage reporting and counter pruning remain open after it.
