# Two targets:
# - dev (compose.yaml): the code is bind-mounted, and the entrypoint runs Composer when the container starts;
# - prod (compose.prod.yaml): the code, its dependencies without the dev ones, and the built frontend are in the image.
#   It needs the frontend as a second build context, named "frontend" (additional_contexts in compose.prod.yaml).

FROM node:26-alpine AS frontend-build
WORKDIR /frontend
COPY --from=frontend package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=frontend .env index.html vite.config.js ./
COPY --from=frontend public public
COPY --from=frontend src src
RUN npm run build

FROM dunglas/frankenphp:1.13-php8.5 AS base
RUN install-php-extensions @composer intl pdo_pgsql zip
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
COPY --link frankenphp/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

FROM base AS dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

FROM base AS prod
ENV APP_ENV=prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link frankenphp/prod.ini $PHP_INI_DIR/conf.d/zz-prod.ini
COPY --link frankenphp/Caddyfile.prod /etc/frankenphp/Caddyfile
COPY --link composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction
# .dockerignore keeps the host's vendor/, var/ and tests/ out.
COPY --link . ./
RUN composer dump-autoload --no-dev --classmap-authoritative && php bin/console cache:warmup
COPY --link --from=frontend-build /frontend/dist /app/spa
