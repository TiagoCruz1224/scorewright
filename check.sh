#!/bin/sh
# Runs every check in the containers: PHP-CS-Fixer, PHPStan, PHPUnit, composer audit, ESLint, the production build
# and npm audit. Used locally (from Git Bash on Windows) and by the CI workflow.
set -e

docker compose run --rm app sh -c 'composer install --no-progress --no-interaction && composer check'
docker compose run --rm --no-deps frontend sh -c 'npm install --no-audit --no-fund && npm run check'
