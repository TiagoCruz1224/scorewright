#!/bin/sh
# The end-to-end test of MVP criterion 1 (e2e/, README "End-to-end test"): starts an isolated Compose project with its
# own name, ports and volumes, runs Playwright in its official image against it, and removes it all at the end, even
# when a test fails. Used locally (from Git Bash on Windows) and by the CI workflow; ./check.sh does not run it.
set -eu

export COMPOSE_PROJECT_NAME=scorewright-e2e
export APP_PORT="${E2E_APP_PORT:-8481}" FRONTEND_PORT="${E2E_FRONTEND_PORT:-5481}"
compose() { docker compose -f compose.yaml -f compose.e2e.yaml "$@"; }
trap 'compose --profile e2e down -v --remove-orphans --rmi local > /dev/null 2>&1' EXIT

start=$(date +%s)
compose up -d --build --wait
# The frontend has no health check: wait until Vite answers (it installs the packages first).
tries=0
until curl -fs -o /dev/null "http://localhost:$FRONTEND_PORT/"; do
	tries=$((tries + 1))
	if [ "$tries" -gt 150 ]; then
		echo "The frontend did not answer on port $FRONTEND_PORT." >&2
		compose logs frontend >&2
		exit 1
	fi
	sleep 2
done
echo "Stack ready in $(($(date +%s) - start)) s."

compose --profile e2e run --rm e2e
echo "End-to-end test passed in $(($(date +%s) - start)) s (stack included)."
