#!/bin/sh
set -e

# Only the web container installs dependencies and migrates; the worker waits until it is healthy.
if [ "$1" = 'frankenphp' ]; then
	composer install --prefer-dist --no-progress --no-interaction
	php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

exec docker-php-entrypoint "$@"
