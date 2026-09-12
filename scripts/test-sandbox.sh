#!/usr/bin/env bash
set -euo pipefail

# Opt-in live Stripe contract suite. Never wired into pull-request CI: it needs a real
# sandbox key, so it runs on demand or from a manually dispatched workflow.
if [[ -z "${CASHIER_ENTITLEMENTS_SANDBOX_KEY:-}" ]]; then
    echo "CASHIER_ENTITLEMENTS_SANDBOX_KEY is not set; nothing to verify." >&2
    exit 2
fi
if [[ "$CASHIER_ENTITLEMENTS_SANDBOX_KEY" != sk_test_* && "$CASHIER_ENTITLEMENTS_SANDBOX_KEY" != rk_test_* ]]; then
    echo "Refusing to run against anything but a Stripe test key." >&2
    exit 2
fi
package_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
"${PHP_BINARY:-php}" "$package_root/vendor/bin/pest" "$package_root/tests/Sandbox"
