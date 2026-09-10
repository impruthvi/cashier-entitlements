#!/usr/bin/env bash
set -euo pipefail

package_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php_binary="${PHP_BINARY:-php}"
composer_binary="${COMPOSER_BINARY:-$(command -v composer)}"
mkdir -p "$package_root/build"
consumer_dir="$(mktemp -d "$package_root/build/consumer.XXXXXX")"
cp "$package_root/tests/Fixtures/consumer/composer.json" "$package_root/tests/Fixtures/consumer/boot.php" "$consumer_dir/"
mkdir -p "$consumer_dir/bootstrap/cache" "$consumer_dir/config" "$consumer_dir/storage/logs"
cd "$consumer_dir"
export COMPOSER_ROOT_VERSION=dev-main
"$php_binary" "$composer_binary" update --no-dev --no-interaction --prefer-dist --no-progress
"$php_binary" boot.php
printf '\nConsumer evidence retained in %s\n' "$consumer_dir"
