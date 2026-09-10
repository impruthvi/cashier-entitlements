# M1: billing facts and decision rules

Implemented as pure PHP calculations under `src/Billing/`. This is an unreleased
development API, not the production applied-grant resolver planned for M3.

## Public boundary

```text
Caller supplies owner + complete facts + catalog + evaluation time
  -> validate context and catalog
  -> status policy for the selected subscription type
       invalid -> return Invalid, never infer cancellation
       denied  -> exclude its paid contribution
       allowed -> validate items and map explicit prices
  -> require exactly one eligible base, combine add-ons
  -> Allowed(allowances, base plan, earliest known expiry)
     or Denied(no eligible paid subscription)
     or Invalid(reason)
```

`StatusPolicy::evaluate(SubscriptionFacts, DateTimeImmutable)` answers eligibility
only. `PriceMapper::map(OwnerReference, array $subscriptions, PriceCatalog,
DateTimeImmutable $at, bool $complete, string $subscriptionType = 'default')`
combines eligibility and mapping. Neither method reads configuration, Cashier,
Stripe, a database, shared cache or the current clock. No new dependency was added.

The caller must supply normalized facts from a trusted source, not request input.
`complete: true` is an explicit assertion that the snapshot is complete, not a
pagination verifier. M2 must earn that assertion by fetching every page successfully.

## Example

```php
use Impruthvi\CashierEntitlements\Billing\{DecisionStatus, OwnerReference,
    PriceCatalog, PriceMapper, PriceMapping, SubscriptionFacts, SubscriptionItem};

$at = new DateTimeImmutable('2026-09-10T12:00:00Z');
$owner = new OwnerReference('organization', 42);
$facts = new SubscriptionFacts(
    owner: $owner,
    id: 'sub_123',
    customerId: 'cus_123',
    type: 'default',
    status: 'active',
    observedAt: $at,
    items: [
        new SubscriptionItem('si_base', 'price_pro', 1),
        new SubscriptionItem('si_extra', 'price_projects', 3),
    ],
);
$catalog = new PriceCatalog('catalog-v1', [
    'price_pro' => new PriceMapping('pro', ['ai' => true, 'projects' => 10]),
    'price_projects' => new PriceMapping('extra_projects', ['projects' => 5],
        isBase: false, perUnit: true),
]);
$decision = (new PriceMapper)->map($owner, [$facts], $catalog, $at, complete: true);

// Allowed; planKey = 'pro'; allowances = ['ai' => true, 'projects' => 25].
// This is a proposed calculation, not a persisted grant or automatic authorization.
if ($decision->status === DecisionStatus::Invalid) {
    // Diagnose $decision->reason. Do not interpret Invalid as an empty paid plan.
}
```

## Facts and isolation

- Readonly values use `DateTimeImmutable`; evaluation never changes input data.
- `OwnerReference` contains morph alias, string-normalized key, connection,
  provider context and live/test mode. Integer `42` equals string `"42"`, but
  `"042"` is a different key. UUIDs are retained as strings. No model is loaded.
- Every subscription in a snapshot must match the explicit owner/context, including
  subscriptions outside the selected type. Subscription identities must be unique.
- Only the selected subscription type contributes. Eligible subscriptions must use
  one customer identity. Historical denied subscriptions can reference an old customer.
- The catalog is versioned and scoped to the same provider context and live/test mode.
- Subscription items, not a nullable parent price/quantity, carry price, quantity
  and optional billing-period boundaries. Parent fields are deliberately absent.
- Both period dates may be absent when calculating allowances. If one is supplied,
  both must be supplied and start must precede end. No billing period is guessed;
  future billing-aligned usage must require these facts separately.
- These are in-memory identity checks, not proof of custom-model database migrations
  or cross-connection transaction support. Those remain later integration gates.

## Status and time rules

| Facts | Result |
|---|---|
| Invalid owner/identity, observation in the future, unsupported status | Invalid |
| `canceled`, `incomplete_expired`, `unpaid`, `paused` | Denied even with future trial/end dates |
| `past_due` or `incomplete` | Denied unless that exact state is explicitly opted in |
| An otherwise eligible subscription reaches its scheduled end | Denied at that instant |
| `trialing` without a trial end | Invalid unless a known scheduled end already denies access |
| `trialing` at/past trial end | Denied |
| Eligible trial | Allowed until the earlier trial/scheduled end |
| `active` | Allowed until its scheduled end, if one is known |

Opt-ins are explicit constructor arguments:
`new PriceMapper(new StatusPolicy(allowPastDue: true, allowIncomplete: false))`.
Defaults deny both. They cannot override terminal statuses or a reached scheduled end.

`scheduledEndsAt` means a confirmed cancellation/end, **not** the ordinary renewal
period boundary or the time cancellation was requested. Old trial dates do not limit
an active paid subscription. Instants are compared across time zones; expiry is
exclusive (`at >= end` denies), including microsecond precision.

`validUntil` is the earliest known boundary among contributing subscriptions. The
result is a snapshot at `$at`; callers must evaluate again when time/facts change.
A null boundary is not a promise of indefinite freshness. M3 owns freshness policy
and applied-state reads; M1 deliberately does not cache or auto-apply anything.

## Mapping and value rules

- One base price across eligible items; even two copies of the same base are an error.
  Denied history is ignored before price lookup, so removed historical prices do not
  poison the current plan. Unknown prices on eligible items return Invalid.
- Every allowance has a stable feature type across the entire catalog and free
  allowances: boolean or numeric. Numeric means nonnegative integer or null (unlimited).
  Floats, numeric strings, negative values and mixed types are rejected, not coerced.
- Boolean contributions combine with OR. Numeric contributions add. Unlimited wins
  regardless of input ordering; finite arithmetic is not evaluated for that feature.
- Quantities must be positive integers; missing, zero and negative quantities are
  unsupported and return Invalid. For quantity other than one, `perUnit: true` must
  be explicitly configured. It scales numeric allowances, not boolean truth values.
- Multiplication and addition are checked before PHP can turn integer overflow into
  a float. Overflow returns Invalid with no partial allowance map.
- Eligible add-ons without a base return Invalid. They never fall back to a free plan.
- With no eligible paid subscription, explicitly configured `freeAllowances` are
  returned as `Allowed/free_plan`; otherwise the result is Denied. This includes
  payment-denied subscriptions, but never incomplete snapshots or invalid facts.
- Free allowances are a fallback, not automatically merged into paid allowances.
  They need no Stripe customer when the complete snapshot is empty.
- Allowed does not mean every feature is enabled. Boolean false and numeric zero
  remain distinct, and null only means unlimited for a numeric allowance.
- Denied and Invalid results both carry an empty allowance map, but must be handled
  differently. Invalid means the calculation failed, not that paid grants should be
  revoked. No feature-level `can()`/`limit()` resolver ships in M1.

## Diagnostics

`BillingDecision` exposes the status enum, a stable machine-readable `reason`,
`validUntil`, `allowances`, and the base `planKey` when mapped. It does not echo raw
payloads or credentials. Typical invalid reasons include `incomplete_snapshot`,
`owner_mismatch`, `catalog_context_mismatch`, `unknown_status`, `unknown_price`,
`conflicting_bases`, `conflicting_customers`, `duplicate_item`, `missing_base`,
`invalid_quantity`, `quantity_requires_per_unit`, `feature_type_mismatch`, and
`allowance_overflow`. Only the first detected error is returned; no aggregate
diagnostic service is introduced here.

Incorrect PHP argument types can still throw `TypeError`: these are typed APIs,
not JSON parsers. Supported but invalid facts/configuration produce Invalid results.

## Verification and boundaries

Local verification on 10 September 2026: **129 tests / 292 assertions** in the
full suite, including **105 M1 tests / 229 assertions**. Pint and Larastan level 8
pass. These are scenario/assertion counts, not a measured line-coverage percentage.

| PHP | Laravel | Dependency graph | Result |
|---|---|---|---|
| 8.3.32 | 12.61.1 / 13.12.0 | Fresh lowest-compatible resolution | Full suite passed on both |
| 8.3.32 | 12.69.2 / 13.31.0 | Installed highest-compatible graphs | Full suite passed on both |
| 8.4.23 | 12.69.2 / 13.31.0 | Installed highest-compatible graphs | Full suite passed on both |
| 8.5.8 | 13.31.0 | Installed highest-compatible graph | Full suite passed |

Latest M1 source/tests were copied into the isolated installed graphs before testing;
their lockfiles were retained. The no-optional-dependency lane was freshly resolved.
Cashier-only, Masterix-only and Pennant-only lanes also exercise the new Billing suite.
The fresh Laravel 13 consumer smoke test verifies an M1 calculation with no optional
dependencies or Testbench. No database migrations/locking guarantees follow from it.

GitHub's M0 workflow passed at `f6731ad`; these M1 results are local and are not a
claim that M1 has been pushed or verified on GitHub-hosted runners.

Pest covers status defaults/opt-ins, terminal contradictions, exact expiry, malformed
facts, explicit owner/context isolation, UUID keys, mapping conflicts, removed history,
per-unit add-ons, zero/unlimited, overflow, deterministic reordering and time-only changes.
An architecture test forbids integration dependencies and ambient I/O/time helpers.
Matrix jobs include `tests/Billing` even when optional dependencies are absent.

No Stripe intake, Cashier row projector, HTTP route, database migration, job, usage
counter, override, production adapter or Packagist release is included. Next: M2's
complete provider reads and diagnostic comparison; M3 applies and resolves grants.
