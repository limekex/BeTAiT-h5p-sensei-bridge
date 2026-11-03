#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
profile="${1:-wp71-php82}"
case "$profile" in
  wp69-php82) wp_version=6.9; php_version=8.2 ;;
  wp71-php82) wp_version=7.1.4; php_version=8.2 ;;
  wp71-php84) wp_version=7.1.4; php_version=8.4 ;;
  *) echo "Unknown profile: $profile" >&2; exit 2 ;;
esac
export FKHS_WP_IMAGE="wordpress:7.0.0-php${php_version}-apache"
export FKHS_CLI_IMAGE="wordpress:cli-2.12.0-php${php_version}"
artifacts="$PWD/artifacts/integration/$profile"
mkdir -p "$artifacts"
export FKHS_PACKAGE_DIR="$artifacts/packages"
mkdir -p "$FKHS_PACKAGE_DIR"
candidate_path=$(python3 scripts/build-plugin.py --output "$FKHS_PACKAGE_DIR")
candidate="/packages/$(basename "$candidate_path")"
python3 tests/integration/build-baseline.py "$FKHS_PACKAGE_DIR"
# A distinct project per invocation; cleanup can only remove this run's fixtures.
project="fkhs-$profile-$$"
compose=(docker compose -p "$project" -f tests/integration/compose.yml)
cleanup() {
  result=$?
  trap - EXIT
  "${compose[@]}" logs --no-color > "$artifacts/services.log" 2>&1 || true
  "${compose[@]}" ps -a > "$artifacts/services.txt" 2>&1 || true
  # Keep the original failure, but a cleanup failure must also fail a passing run.
  "${compose[@]}" down --volumes --remove-orphans || result=1
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
printf 'Profile %s: WordPress %s / PHP %s / Sensei 4.26.4 / H5P 1.17.10\n' "$profile" "$wp_version" "$php_version"
"${compose[@]}" up -d --wait --wait-timeout 120
wp() { "${compose[@]}" run --rm -T cli wp "$@"; }
ready=false
for attempt in {1..30}; do
  if wp core version; then ready=true; break; fi
  sleep 1
done
if [ "$ready" != true ]; then echo 'WordPress bootstrap timed out.' >&2; exit 1; fi
# The Apache image supplies PHP/extensions; overwrite core with the tested version.
wp core download --version="$wp_version" --force --skip-content --quiet
wp core verify-checksums --version="$wp_version" --exclude=wp-config-docker.php
wp core install --url=http://wordpress --title='HSB integration fixture' --admin_user=fixture-admin --admin_password=fixture-admin-only --admin_email=fixture@example.test --skip-email
wp plugin install sensei-lms --version=4.26.4 --activate
wp plugin install h5p --version=1.17.10 --activate
wp eval-file /tests/wordpress-package-upload.php "$candidate"
wp plugin activate h5p-sensei-bridge
wp eval-file /tests/wordpress-package.php fresh
wp plugin uninstall h5p-sensei-bridge --deactivate
wp plugin install /packages/historical-develop-test-only.zip --activate
wp eval-file /tests/wordpress-package.php legacy
wp plugin deactivate h5p-sensei-bridge
wp plugin install "$candidate" --force --activate
wp eval-file /tests/wordpress-package.php upgrade
# Actual SQL backup of this disposable fixture only; never a mounted user database.
"${compose[@]}" exec -T db mariadb-dump -uwordpress -pfixture-only --no-tablespaces wordpress > "$artifacts/package-backup.sql"
wp plugin deactivate h5p-sensei-bridge
wp plugin install /packages/historical-develop-test-only.zip --force --activate
wp eval-file /tests/wordpress-package.php rollback
wp plugin deactivate h5p-sensei-bridge
wp plugin install "$candidate" --force --activate
wp eval-file /tests/wordpress-package.php reupgrade
wp eval-file /tests/wordpress-package.php mutate
"${compose[@]}" exec -T db mariadb -uwordpress -pfixture-only wordpress < "$artifacts/package-backup.sql"
wp cache flush
wp eval-file /tests/wordpress-package.php restore
rm "$artifacts/package-backup.sql"
# CLI activation may flush before Sensei's post types are registered. Rebuild in
# a fresh process so quiz/lesson URLs work on every selected WordPress version.
wp rewrite flush
docker image inspect "$FKHS_WP_IMAGE" "$FKHS_CLI_IMAGE" mariadb:10.11.18 --format '{{.Id}} {{json .RepoDigests}}' > "$artifacts/images.txt"
wp eval-file /tests/integration/runtime.php "$wp_version" "$php_version" | tee "$artifacts/runtime.log"
wp eval-file /tests/wordpress-i18n.php
wp eval-file /tests/wordpress-rest.php
wp eval-file /tests/wordpress-durability.php
wp eval-file /tests/wordpress-concurrent.php &
first_worker=$!
wp eval-file /tests/wordpress-concurrent.php &
second_worker=$!
wait "$first_worker"
wait "$second_worker"
wp eval-file /tests/wordpress-concurrent.php verify
wp eval-file /tests/wordpress-sensei.php
wp eval-file /tests/wordpress-sensei.php blocks
for storage in comments dual-comments dual-tables; do
  wp eval-file /tests/integration/configure-progress.php "$storage"
  wp eval-file /tests/wordpress-completion.php "$storage"
done
wp eval-file /tests/wordpress-report.php
wp eval-file /tests/wordpress-privacy.php
wp eval-file /tests/wordpress-editor.php
# One representative profile records the bounded synthetic performance matrix.
if [ "$profile" = wp71-php82 ]; then
  wp eval-file /tests/wordpress-report-benchmark.php | tee "$artifacts/report-benchmark.log"
fi
