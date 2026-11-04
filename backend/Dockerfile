#syntax=docker/dockerfile:1

# Development image, trimmed from the dunglas/symfony-docker template.
FROM dunglas/frankenphp:1.13-php8.5

RUN install-php-extensions @composer intl pdo_pgsql zip \
	&& mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY --link frankenphp/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
