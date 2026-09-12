# Stripe sandbox verification — 13 September 2026

Verified the current M6 development build plus operational review fixes against a real
Stripe sandbox using the package's pinned `2025-06-30.basil` API version. This is evidence
for the scenarios below, not a production certification or verification of live-mode billing.

## Results

The first read-only run authenticated successfully but found no subscriptions, so two
checks skipped. After creating isolated test resources, all three existing sandbox
contracts passed with 27 assertions and no skips: list envelope, subscription fields,
and subscription-item billing periods.

A separate isolated Laravel Testbench host used the actual Stripe source and native
resolver with an in-memory SQLite database and serialized Laravel database-queue jobs.
It verified:

- An active test subscription grants the mapped allowance after a missed creation event.
- The registered scheduled command resumes beyond its first two-owner batch and reaches
  the third owner, preserving the same scan ID.
- Reapplying unchanged provider facts leaves the applied grant version unchanged.
- Usage admission enforces the live-derived plan limit; retrying the same operation
  creates exactly one local domain row and exceeding the limit creates none.
- An upgrade with its webhook omitted converges through the bounded sweep; an explicit
  queued refresh applies a subsequent native downgrade.
- Scheduled cancellation preserves access until the provider's cancellation timestamp.
  Evaluating the native resolver at that timestamp denies access without another webhook.
- Immediate cancellation with its event omitted revokes both numeric and boolean access
  after the sweep and queue worker execute.
- A durable request with dispatch deliberately omitted is found by recovery, queued and
  completed. Doctor then reports healthy convergence for the scope.
- The package does not rewrite Cashier subscription rows during reconciliation.

The pre-existing local release gate also passed: 293 tests / 1147 assertions, PostgreSQL
concurrency (2 tests / 15 assertions), all eight dependency-matrix rows, Larastan, Pint,
Composer validation and a fresh no-dev consumer installation.

## Cleanup and credentials

Only resources tagged for this validation run were used. The temporary subscription was
canceled, the temporary customer was deleted, and both prices and their product were
archived. Stripe's historical sandbox records remain. No real customer email address or
live-mode resource was used. The key was supplied through the process environment and
was not written into source files or the saved validation output.

The local operator harness and sanitized output are retained in the workspace's ignored
`.context/stripe-sandbox-lifecycle.php` and `.context/stripe-sandbox-validation-output.log`.
The harness creates and cleans up sandbox resources; it is not part of the read-only
`scripts/test-sandbox.sh` contract suite or ordinary CI.

## Remaining boundaries

This did not verify Stripe's delivery to a publicly reachable application webhook URL.
The omitted-event scenarios intentionally delivered no webhook; signed HTTP webhook
handling is covered by the local integration suite. No deployed application, live-mode
payments, renewal driven by a Stripe test clock, or optional-adapter certification was
included in this sandbox run. The [Masterix and Pennant support limits](m5-adapters.md)
remain unchanged. Publication and version tagging remain separate release actions.

The resource lifecycle follows Stripe's [subscription creation](https://docs.stripe.com/api/subscriptions/create),
[updates](https://docs.stripe.com/api/subscriptions/update) and
[cancellation](https://docs.stripe.com/api/subscriptions/cancel) APIs.
