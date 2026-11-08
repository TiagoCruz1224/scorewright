#!/bin/sh
set -e

# Only the web container installs dependencies, migrates and sets up the app's database role; the worker waits until
# it is healthy.
if [ "$1" = 'frankenphp' ]; then
	if [ "$APP_ENV" = 'prod' ]; then
		# The prod image has its dependencies, and refuses to start with the placeholders of .env.example.
		for value in "$APP_SECRET" "$DATABASE_URL" "$DATABASE_OWNER_URL"; do
			case "$value" in
				'' | *'!ChangeMe!'*)
					echo 'Refusing to start: a secret is empty or a !ChangeMe! placeholder. Set PROD_APP_SECRET, PROD_POSTGRES_PASSWORD and PROD_APP_DATABASE_PASSWORD in .env.prod to your own values (README, "Production").' >&2
					exit 1
					;;
			esac
		done
	else
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# The migrations, and the grants of the app's role, run as the database owner (DATABASE_OWNER_URL); the app runs as
	# its own role (DATABASE_URL), no superuser. The owner's URL stops here: the server never sees it.
	if [ -n "$DATABASE_OWNER_URL" ]; then
		app_url="$DATABASE_URL"
		DATABASE_URL="$DATABASE_OWNER_URL" php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
		DATABASE_URL="$DATABASE_OWNER_URL" php bin/console app:database-role "$app_url"
		unset DATABASE_OWNER_URL app_url
	else
		php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	fi
fi

exec docker-php-entrypoint "$@"
