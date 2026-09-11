#!/usr/bin/env bash
set -euo pipefail

package_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php_binary="${PHP_BINARY:-php}"
pg_bin="${PG_BINDIR:-$(pg_config --bindir)}"
m3_pg_dir="$(mktemp -d /tmp/cashier-m3-pg.XXXXXX)"
"$pg_bin/initdb" -D "$m3_pg_dir/data" --auth=trust --no-locale >"$m3_pg_dir/init.log"
# Private Unix socket only; no listener on a shared database or TCP port.
"$pg_bin/pg_ctl" -D "$m3_pg_dir/data" -l "$m3_pg_dir/server.log" -o "-k $m3_pg_dir -h ''" -w start
trap '"$pg_bin/pg_ctl" -D "$m3_pg_dir/data" -m fast -w stop >/dev/null' EXIT
cd "$package_root"
ENTITLEMENTS_PG_SOCKET="$m3_pg_dir" "$php_binary" vendor/bin/pest tests/Concurrency --compact
printf '\nStopped PostgreSQL test cluster; evidence retained in %s\n' "$m3_pg_dir"
