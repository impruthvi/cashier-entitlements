# M6: operations, convergence and the release gate

Webhooks repair the owners Stripe told you about. M6 repairs the ones it did not. It adds a
bounded account scan, a local health report, optional scheduling for both, and the evidence
a release candidate has to produce.

This is a development milestone, not a production release or a live Stripe certification.

## Installation

First follow [M3 setup](m3-refresh.md), [M4 usage](m4-usage.md) and, if you enable either
adapter, [M5 adapters](m5-adapters.md). M6 adds one migration to the same tag:

```sh
php artisan vendor:publish --tag=cashier-entitlements-migrations
php artisan migrate
```

`cashier_entitlement_audit_runs` records the progress of each account scan. It is additive
and touches no existing table, so an existing installation upgrades by publishing and
migrating only.

## The account sweep

```sh
php artisan entitlements:sweep --owner-type=organization --limit=100 --stale-after=3600
```

The scan walks one owner scope in primary-key order and requests a refresh for every owner
whose observation is missing or older than the threshold. It requests work; the existing
queue, generation fencing and apply path do the rest, so a sweep never writes a grant
itself and never calls Stripe on the scanning process.

Three rules matter more than the flags:

- **An incomplete scan proves nothing.** When a pass hits its limit, the run keeps its
  cursor, stays incomplete and exits non-zero. The next invocation automatically resumes
  the oldest unfinished scan for that owner scope. Once all unfinished scans complete,
  the following invocation starts a new scan. `--resume=<run id>` selects a specific run.
- **Owners with outstanding work are skipped.** `entitlements:recover` already owns
  re-delivery for them, including owners with no successful observation yet. The sweep
  preserves their active claims and retry backoff instead of queuing another request.
- **One bad owner does not end the scan.** An owner that cannot be mapped, most often two
  local records sharing one Stripe customer, is counted in `failed`, its reason code is
  reported, and the scan continues.

Without `--stale-after` the threshold comes from your freshness policy. Under
`retain_last_known` there is no age at which state expires by itself, so the sweep refuses
to guess and asks for an explicit threshold instead.

## The doctor

```sh
php artisan entitlements:doctor --owner-type=organization
```

Local only. It reads configuration and committed state and never calls Stripe, so it
answers whether this installation is wired correctly and converging. Whether the provider
agrees is `entitlements:reconcile`'s question.

| Exit | Meaning |
|---|---|
| 0 | Configuration resolves and nothing is pending, failing, stale or mismatched |
| 1 | Working, with something worth attention: backlog, failures, staleness, an old catalog version, no schedule, or no completed scan |
| 2 | A configuration fault: no catalog bound, an unusable freshness policy, meter rules or driver |

Every field is a count, a timestamp or an existing sanitized reason code, so a report is
safe to paste into an issue. Failure reasons are grouped by code with their counts.
With `--owner-type`, a completed scan with failed owners still returns exit 1, including
failures that prevented native state creation. Top-level `errors.sweep_owner_failed`
counts failed scan attempts; `sweep.last_error` identifies the last reason. That reason
is not attributed to every failed owner, since the scan does not retain per-reason counts.

## Scheduling convergence

```php
// config/cashier-entitlements.php
'schedule' => [
    'owner_type' => 'organization',
    'sweep' => '0 * * * *',
    'sweep_limit' => 100,
    'stale_after' => 3600,
    'recover' => '*/5 * * * *',
],
```

Cron expressions only, so the schedule is explicit rather than inferred from a method name.
Both tasks run `withoutOverlapping`, because two scans would fight over one cursor and
duplicate provider reads.
Every scheduled sweep continues its scope's unfinished scan; it does not restart at the
first owner each time. Manual invocations for the same scope should also run serially.

An unusable schedule is **ignored, not fatal**. Booting must not throw on a partial
configuration key, or one typo would take the whole application down. The doctor reports
the exact reason the schedule was skipped, so a silently missing sweep is still visible:
`schedule_requires_owner_type`, `invalid_cron_expression`, `invalid_sweep_limit`,
`invalid_stale_age` or `no_scheduled_convergence`.
Expressions are validated with the cron parser used by Laravel's scheduler, including
field counts and ranges. If either supplied expression is invalid, neither task is
registered and doctor reports `invalid_cron_expression`.

## Verification

Local after the operational review fixes on 12 September 2026: 293 tests / 1147 assertions, separate PostgreSQL
multi-process coverage (2 tests / 15 assertions), Larastan level 8, Pint and
`composer validate --strict`. The full release gate passed all eight dependency-matrix
rows and the fresh consumer install. These fixes require no additional migration.

Convergence evidence is in
[`tests/Integration/ConvergenceTest.php`](../tests/Integration/ConvergenceTest.php). It
omits creation, update and deletion events individually, repeats a signed notification,
delivers a stale update through the webhook endpoint and worker, and takes its expected
values from provider fixtures rather than the Cashier
rows under test, so a shared projection bug cannot make both sides agree. After a scan
converges, the next pass requests nothing and the applied version does not move. Cashier
drift is still reported afterwards, because this package never repairs Cashier rows.

[`tests/Integration/ScheduledConvergenceTest.php`](../tests/Integration/ScheduledConvergenceTest.php)
executes the registered sweep command across multiple batches and into the next scan.
Schedule and doctor regressions also cover invalid cron ranges, partially invalid
configuration and completed scans whose owners all failed before creating native state.

Scaling and secret-free evidence is in
[`tests/Performance/ScalingTest.php`](../tests/Performance/ScalingTest.php): one feature
read and a hundred cost the same single query, ten thousand synthetic remote subscriptions
read within their page and memory bounds, the record and page ceilings refuse an unbounded
read, a rate-limited owner stays queued with only a sanitized reason recorded, and the
pending backlog respects its requested limit.

The ten-thousand-subscription scan needs more than PHP's 128M default. `composer test` and
the matrix script both run with 256M.

### Release gate

```sh
composer release-gate
```

It checks that every milestone document and the licence exist, that no credential-shaped
literal appears in shipped files, that packaging validates, that static analysis and
formatting pass, that all eight dependency-matrix rows pass, and that PostgreSQL locking
and a fresh consumer install still work.

The live Stripe contract suite stays outside it, because it needs real credentials:

```sh
CASHIER_ENTITLEMENTS_SANDBOX_KEY=sk_test_... bash scripts/test-sandbox.sh
```

It is read-only, creates no Stripe resource, refuses anything but a test key, and is
excluded from the default suite so credentials never enter pull-request CI. It answers the
one question recorded fixtures cannot: does the pinned API version still return the shape
this package reads.

The populated sandbox suite passed on 13 September 2026: 3 tests / 27 assertions, no skips.
Separate native lifecycle verification also passed, including real provider changes,
missed-event convergence, queue recovery and local usage admission. See the
[verification record](sandbox-validation.md) for cleanup and the remaining proof boundary.

## What M6 does not include

No Stripe metered-usage reporting, no counter pruning and no aggregate usage listing. The
sweep scans one owner scope per run rather than every registered morph alias at once. The
Masterix adapter remains uncertified against custom configured models, custom connections
and adapter concurrency, as [M5](m5-adapters.md) records.
