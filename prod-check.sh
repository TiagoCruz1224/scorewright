#!/bin/sh
# A smoke test of the production profile (compose.prod.yaml, README "Production"): builds the prod image, starts it as
# a Compose project of its own with generated secrets, checks that it answers and that it refuses an empty secret and
# the !ChangeMe! placeholders, and removes it all at the end, even when a check fails. Used locally (from Git Bash on Windows) and by
# the CI workflow.
set -eu

project=scorewright-prodcheck
port="${PROD_CHECK_PORT:-8489}"
secret() { od -An -N24 -tx1 /dev/urandom | tr -d ' \n'; }
# From the environment, so no file on disk holds them (the profile reads .env.prod only when given --env-file); the
# other settings are the profile's defaults.
export PROD_APP_SECRET="$(secret)" PROD_POSTGRES_PASSWORD="$(secret)" PROD_APP_DATABASE_PASSWORD="$(secret)" PROD_PORT="$port"
export SERVER_NAME=:80 TRUSTED_HOSTS='^(localhost|127\.0\.0\.1)$' TRUSTED_PROXIES=
compose() { docker compose -p "$project" -f compose.prod.yaml "$@"; }
trap 'compose down -v --remove-orphans --rmi local > /dev/null 2>&1' EXIT

failures=0
expect() { # what, expected, actual
	if [ "$2" = "$3" ]; then
		echo "ok    $1: $3"
	else
		echo "FAIL  $1: expected $2, got $3" >&2
		failures=$((failures + 1))
	fi
}
answer() { curl -s -o /dev/null -w '%{http_code} %{content_type}' "http://127.0.0.1:$port$1"; }

start=$(date +%s)
compose up -d --build --wait
echo "Up and healthy in $(($(date +%s) - start)) s."

expect 'health' '200 application/json' "$(answer /api/health)"
expect 'the app, at one of its own paths' '200 text/html; charset=utf-8' "$(answer /projects/x/evaluations/y)"
expect 'an unknown API path' '404 application/problem+json' "$(answer /api/nothing)"
expect 'workers running' 2 "$(compose ps --status running --format '{{.Service}}' | grep -c '^worker$')"
# Caddy's own errors carry the same headers (SWQ4-03): a missing asset, and a method the file server does not take.
for request in 'GET /assets/missing.css' 'POST /'; do
	headers=$(curl -s -o /dev/null -D - -X "${request% *}" "http://127.0.0.1:$port${request#* }" | tr -d '\r')
	verdict="$(echo "$headers" | head -1 | cut -d' ' -f2)"
	echo "$headers" | grep -qi '^x-content-type-options: nosniff' && verdict="$verdict, nosniff"
	echo "$headers" | grep -qi '^content-security-policy:' && verdict="$verdict, CSP"
	echo "$headers" | grep -qi '^server:' && verdict="$verdict, Server"
	expect "Caddy's error for $request" "$([ "$request" = 'POST /' ] && echo 405 || echo 404), nosniff, CSP" "$verdict"
done
# An empty secret: Compose refuses before it starts anything (no database is created with it).
empty=$(PROD_POSTGRES_PASSWORD= compose config -q 2>&1 || true)
expect 'an empty secret' 'refused by Compose' "$(echo "$empty" | grep -q 'set PROD_POSTGRES_PASSWORD in .env.prod' && echo 'refused by Compose' || echo "$empty" | tail -1)"
# A placeholder: the entrypoint stops before the migrations; --no-deps: nothing else starts.
refusal=$(compose run --rm --no-deps -e APP_SECRET='!ChangeMe!' app frankenphp version 2>&1 || true)
expect 'start with a placeholder secret' 'refused' "$(echo "$refusal" | grep -q 'Refusing to start' && echo refused || echo "$refusal" | tail -1)"

if [ "$failures" -gt 0 ]; then
	compose logs app >&2
	exit 1
fi
echo "Production profile checked in $(($(date +%s) - start)) s."
