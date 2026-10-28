#!/usr/bin/env bash
# Dedicated disposable project, no published ports or provider egress. Never
# uses production configuration, host service helpers, release ZIPs or tokens.
set -Eeuo pipefail
repo=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d /tmp/greyrock-wordfence-fixture.XXXXXX)
project="greyrock-upgrade-$(date +%s)-$$"
export GREYROCK_WORDFENCE_FIXTURE="$fixture"
compose=(docker compose -p "$project" -f "$repo/tests/docker/historical-compose.yml")
cleanup() {
  local result=$?
  trap - EXIT
  if ! "${compose[@]}" down --volumes --remove-orphans; then result=1; fi
  rm -rf "$fixture"
  exit "$result"
}
trap cleanup EXIT
curl --fail --silent --show-error --max-time 60 https://downloads.wordpress.org/plugin/wordfence.9.0.2.zip -o "$fixture/wordfence.zip"
unzip -q "$fixture/wordfence.zip" -d "$fixture"
chmod -R a+rX "$fixture"
"${compose[@]}" run --rm --no-deps core
"${compose[@]}" up -d --wait db
wp() { "${compose[@]}" run --rm --no-deps cli "$@"; }
wp config create --dbname=greyrock_task13_historical --dbuser=wordpress --dbpass=disposable-fixture --dbhost=db --skip-check
wp core multisite-install --url=http://greyscale.test --title=GreyRockFixture --admin_user=fixture --admin_password=disposable-admin-fixture --admin_email=fixture@example.test --skip-email
"${compose[@]}" run --rm --no-deps --entrypoint sh cli -c 'cp -R /candidate/src /var/www/html/wp-content/plugins/grey-rock-block-synchroniser-for-wordfence-and-cloudflare; cp -R /wordfence-fixture/wordfence /var/www/html/wp-content/plugins/wordfence; mkdir -p /var/www/html/wp-content/mu-plugins; cp /candidate/tests/fixtures/historical-http-denial.php /var/www/html/wp-content/mu-plugins/historical-http-denial.php'
wp plugin activate wordfence --network
wp plugin activate grey-rock-block-synchroniser-for-wordfence-and-cloudflare --network
wp eval-file --use-include /candidate/tests/integration/historical-upgrade-test.php
