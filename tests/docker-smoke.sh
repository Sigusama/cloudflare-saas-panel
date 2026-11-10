#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-only
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
temporary=$(mktemp -d)
environment_file="$temporary/smoke.env"
project="cf-panel-smoke-$(date +%s)-$$"
case "$project" in cf-panel-smoke-*) ;; *) exit 1;; esac
cat > "$environment_file" <<'ENV'
PANEL_PORT=0
MYSQL_DATABASE=cf_panel_docker_preview
MYSQL_USER=cf_panel
MYSQL_PASSWORD=isolated-smoke-user-password
MYSQL_ROOT_PASSWORD=isolated-smoke-root-password
ENV
if [ -n "${PANEL_SMOKE_IMAGE:-}" ]; then
    printf '\nPANEL_IMAGE=%s\n' "$PANEL_SMOKE_IMAGE" >> "$environment_file"
else
    printf '\nPANEL_IMAGE=%s:local\n' "$project" >> "$environment_file"
fi
compose() {
    if [ -n "${PANEL_SMOKE_IMAGE:-}" ]; then
        docker compose --project-name "$project" --env-file "$environment_file" -f "$root/compose.yaml" "$@"
    else
        docker compose --project-name "$project" --env-file "$environment_file" -f "$root/compose.yaml" -f "$root/compose.build.yaml" "$@"
    fi
}
cleanup() {
    result=$?
    if [ "$result" -ne 0 ]; then compose logs --tail=80 || true; fi
    compose down --volumes --remove-orphans || true
    rm -f "$environment_file"
    rmdir "$temporary" || true
}
trap cleanup EXIT HUP INT TERM
compose config --quiet
if [ -n "${PANEL_SMOKE_IMAGE:-}" ]; then
    compose pull
    compose up -d --no-build --wait --wait-timeout 240
else
    compose up -d --build --wait --wait-timeout 240
fi
docker version --format 'Docker Engine {{.Server.Version}}'
docker compose version
compose exec -T web php --version
compose exec -T db mysql --version
host_port=$(compose port web 80 | head -n 1 | awk -F: '{print $NF}')
curl --fail --silent --show-error --max-time 10 "http://127.0.0.1:$host_port/health" > /dev/null
echo 'Published Web port is reachable from the Docker host.'
compose exec -T --user www-data \
    -e PANEL_TEST_WORK=/var/www/panel/storage/test-http \
    -e TEST_HTTP_ISOLATED=1 \
    -e TEST_HTTP_URL=http://127.0.0.1:80 \
    -e TEST_INSTALL_DB_HOST=db -e TEST_INSTALL_DB_PORT=3306 \
    -e TEST_INSTALL_DB_NAME=cf_panel_docker_preview -e TEST_INSTALL_DB_USER=cf_panel \
    -e TEST_INSTALL_DB_PASSWORD=isolated-smoke-user-password \
    web php tests/http.php
before=$(compose exec -T web php -r 'require "app/bootstrap.php"; echo hash("sha256", Panel\Core\Config::read()["key"]);')
heartbeat_before=$(compose exec -T web php -r 'require "app/bootstrap.php"; $db=new Panel\Core\Database(Panel\Core\Config::read()["db"]); echo $db->value("SELECT value FROM settings WHERE name=?",["worker_heartbeat"]) ?: "";')
compose restart web worker
compose up -d --wait --wait-timeout 120
after=$(compose exec -T web php -r 'require "app/bootstrap.php"; echo hash("sha256", Panel\Core\Config::read()["key"]);')
test "$before" = "$after"
attempt=0
while [ "$attempt" -lt 30 ]; do
    heartbeat_after=$(compose exec -T web php -r 'require "app/bootstrap.php"; $db=new Panel\Core\Database(Panel\Core\Config::read()["db"]); echo $db->value("SELECT value FROM settings WHERE name=?",["worker_heartbeat"]) ?: "";')
    if [ -n "$heartbeat_after" ] && [ "$heartbeat_after" != "$heartbeat_before" ]; then break; fi
    attempt=$((attempt+1))
    sleep 1
done
test "$attempt" -lt 30
compose exec -T --user www-data -e PANEL_TEST_WORK=/var/www/panel/storage/test-http -e TEST_HTTP_ISOLATED=1 -e TEST_HTTP_URL=http://127.0.0.1:80 web php tests/http.php
# Replace every container, including MySQL, without removing either named volume.
# Store test login material in private storage so the fresh Web container can log in.
state_before=$(compose exec -T --user www-data -e TEST_HTTP_ISOLATED=1 web php tests/docker-state.php)
compose down
compose up -d --wait --wait-timeout 240
state_after=$(compose exec -T --user www-data -e TEST_HTTP_ISOLATED=1 web php tests/docker-state.php)
test "$state_before" = "$state_after"
compose exec -T worker php bin/console.php health:worker
echo 'Container replacement retained database rows, administrator, encrypted credentials, application key and installation lock.'
compose exec -T --user www-data -e PANEL_TEST_WORK=/var/www/panel/storage/test-http -e TEST_HTTP_ISOLATED=1 -e TEST_HTTP_URL=http://127.0.0.1:80 web php tests/http.php
# The integration suite drives its own fake-cloud Worker; avoid sharing the
# server-wide operational lock with this installation's heartbeat process.
compose stop worker
compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root -e "CREATE DATABASE cf_panel_docker_integration_test CHARACTER SET utf8mb4; GRANT ALL ON cf_panel_docker_integration_test.* TO '\''cf_panel'\''@'\''%'\'';"'
compose exec -T -e TEST_DB_HOST=db -e TEST_DB_PORT=3306 -e TEST_DB_NAME=cf_panel_docker_integration_test -e TEST_DB_USER=cf_panel -e TEST_DB_PASSWORD=isolated-smoke-user-password web php tests/run.php
echo 'Docker build, Web install, API, restart and container replacement persistence, Worker and integration checks passed.'
