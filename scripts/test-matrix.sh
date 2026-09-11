#!/usr/bin/env bash
set -euo pipefail

# Isolate dependency resolution; never modify the developer's installed graph.
laravel="${1:-13}"
mode="${2:-all}"
preference="${3:-highest}"
case "$laravel" in 12) testbench=10 ;; 13) testbench=11 ;; *) exit 2 ;; esac
case "$mode" in all|none|cashier|masterix|pennant) ;; *) exit 2 ;; esac
case "$preference" in highest|lowest) ;; *) exit 2 ;; esac

package_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php_binary="${PHP_BINARY:-php}"
composer_binary="${COMPOSER_BINARY:-$(command -v composer)}"
mkdir -p "$package_root/build"
matrix_dir="$(mktemp -d "$package_root/build/laravel-${laravel}-${mode}-${preference}.XXXXXX")"
cp -R "$package_root/src" "$package_root/config" "$package_root/tests" "$package_root/database" "$matrix_dir/"
cp "$package_root/composer.json" "$package_root/phpunit.xml.dist" "$matrix_dir/"
cd "$matrix_dir"
export COMPOSER_ROOT_VERSION=dev-main
export ENTITLEMENTS_OPTIONAL_MODE="$mode"

"$php_binary" "$composer_binary" remove --dev --no-update --no-interaction laravel/cashier laravel/pennant masterix21/laravel-entitlements
"$php_binary" "$composer_binary" require --dev --no-update --no-interaction "laravel/framework:^${laravel}.0" "orchestra/testbench:^${testbench}.0"
test_paths=(tests/Core tests/Billing tests/Resolution)
if [[ "$mode" == all || "$mode" == cashier ]]; then
    "$php_binary" "$composer_binary" require --dev --no-update --no-interaction 'laravel/cashier:^16.8'
    test_paths+=(tests/Compatibility/CashierTest.php tests/Stripe tests/Reconciliation tests/Commands tests/Integration)
fi
if [[ "$mode" == all || "$mode" == masterix ]]; then
    "$php_binary" "$composer_binary" require --dev --no-update --no-interaction 'masterix21/laravel-entitlements:1.3.1'
    test_paths+=(tests/Compatibility/MasterixTest.php)
fi
if [[ "$mode" == all || "$mode" == pennant ]]; then
    "$php_binary" "$composer_binary" require --dev --no-update --no-interaction 'laravel/pennant:^1.26'
    test_paths+=(tests/Compatibility/PennantTest.php)
fi
update_flags=(--no-interaction --prefer-dist --prefer-stable --no-progress)
if [[ "$preference" == lowest ]]; then update_flags+=(--prefer-lowest); fi
"$php_binary" "$composer_binary" update "${update_flags[@]}"
"$php_binary" "$composer_binary" check-platform-reqs
"$php_binary" "$composer_binary" show --direct
"$php_binary" vendor/bin/pest "${test_paths[@]}"
# Keep isolated lockfiles for reproducible investigation; build/ is gitignored.
printf '\nMatrix evidence retained in %s\n' "$matrix_dir"
