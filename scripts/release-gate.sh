#!/usr/bin/env bash
set -euo pipefail

# Everything a release candidate must pass. Support claims follow passing jobs, so this
# runs the full dependency matrix rather than one convenient lane.
package_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php_binary="${PHP_BINARY:-php}"
composer_binary="${COMPOSER_BINARY:-$(command -v composer)}"
cd "$package_root"

fail() { echo "RELEASE GATE FAILED: $1" >&2; exit 1; }

echo "== documentation and licensing =="
for required in README.md CHANGELOG.md LICENSE.md docs/m0-compatibility.md docs/m1-billing.md \
    docs/m2-diagnostics.md docs/m3-refresh.md docs/m4-usage.md docs/m5-adapters.md docs/m6-operations.md; do
    [[ -s "$required" ]] || fail "missing or empty $required"
done
grep -q '## Unreleased' CHANGELOG.md || fail "CHANGELOG has no Unreleased section to promote"

echo "== no credential literals in shipped code =="
if grep -rnE "(sk|rk)_(live|test)_[A-Za-z0-9]{8,}" src config database README.md docs; then
    fail "a credential-shaped literal is present in shipped files"
fi

echo "== packaging =="
"$php_binary" "$composer_binary" validate --strict || fail "composer.json is not valid"

echo "== quality =="
"$php_binary" "$composer_binary" analyse || fail "static analysis"
"$php_binary" "$composer_binary" format:check || fail "formatting"

echo "== dependency matrix =="
for row in "12 all lowest" "12 all highest" "13 all lowest" "13 all highest" \
    "12 none highest" "12 cashier highest" "12 masterix highest" "12 pennant highest"; do
    # shellcheck disable=SC2086
    bash scripts/test-matrix.sh $row || fail "matrix row: $row"
done

echo "== real locking and a fresh install =="
bash scripts/test-postgres.sh || fail "PostgreSQL concurrency"
bash scripts/test-consumer.sh || fail "fresh consumer install"

echo
echo "Release gate passed. The live Stripe contract suite is opt-in:"
echo "  CASHIER_ENTITLEMENTS_SANDBOX_KEY=sk_test_... bash scripts/test-sandbox.sh"
