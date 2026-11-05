# Scorewright

Scorewright is an open-source web platform for evaluating the answers of AI systems. You collect test cases in
datasets, import the answers that one or more models gave, score them against explicit criteria (by hand and with
deterministic automatic checks) and compare models on the same data.

Status: early development. The scope and design are in [docs/requirements.md](docs/requirements.md),
[docs/architecture.md](docs/architecture.md) and the [architecture decision records](docs/adr/).

## Stack

Symfony 7.4 LTS on PHP 8.5 (FrankenPHP), PostgreSQL 18, Symfony Messenger on the Doctrine transport, and a React 19
single-page app in JavaScript with React Router 8, Tailwind CSS 4 and Vite 8.

## Run it locally

Requirements: Docker with Compose v2 (on Windows, Docker Desktop with the WSL 2 backend). PHP and Node.js on the host
are not needed: they run in the containers (PHP 8.5, Node.js 26).

```bash
docker compose up
```

The first start builds the PHP image and installs the PHP and npm dependencies: a minute or two once the base images
are downloaded, longer before. Then:

| URL | What |
|---|---|
| http://localhost:5471 | The app (Vite dev server; it forwards `/api` to Symfony, so the browser sees one origin) |
| http://localhost:8471/api/health | The API directly; `{"status":"ok"}` when the API and the database are up |

Services: `app` (FrankenPHP serving Symfony), `worker` (the same image, consuming the Messenger queue), `db`
(PostgreSQL 18, not published on the host) and `frontend` (Vite).

The host ports are deliberately uncommon (8471 and 5471), so they rarely collide with other local stacks. To change
them (`APP_PORT`, `FRONTEND_PORT`) or the local passwords, copy `.env.example` to `.env` and edit it; `.env` is ignored
by Git. Both ports are bound to `127.0.0.1` only, so the stack is not reachable from the local network; the database
port is not published at all.

Compose names the stack after the folder (`scorewright` for a clone in `Scorewright/`), and both `docker compose up`
and `./check.sh` act on that stack and its volumes. To run a second copy next to an existing one, give it its own name
and ports in its `.env`, for example `COMPOSE_PROJECT_NAME=scorewright2`, `APP_PORT=8481` and `FRONTEND_PORT=5481`.

After a change to the backend image (`backend/Dockerfile` or `backend/frankenphp/`), run `docker compose up --build`.
`./check.sh` rebuilds it by itself.

### The first account

There is no sign-in screen yet, so create an account through the API. Outside a browser any `csrf-token` value of 24
characters or more will do:

```bash
curl -X POST http://localhost:8471/api/auth/register -H 'Content-Type: application/json' -H 'csrf-token: any-random-value-of-24-or-more-characters' -d '{"email": "you@example.com", "password": "a long enough passphrase", "displayName": "You"}'
```

To sign in, open http://localhost:5471 and run this in the browser console; the browser then keeps the session cookie:

```js
await fetch('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'csrf-token': crypto.randomUUID() }, body: JSON.stringify({ email: 'you@example.com', password: 'a long enough passphrase' }) });
```

The Vite proxy rewrites the `Host` header to `app`, so the `Origin` that a request to port 5471 carries never matches
it. Browsers still pass the CSRF check, because they also send `Sec-Fetch-Site: same-origin`. A client that sends
`Origin` without `Sec-Fetch-Site` gets `403 csrf_failed` there; it should call the API on port 8471 instead.

### Where the files live

The source code is bind-mounted into the containers, so edits on the host apply at once (PHP on the next request, the
SPA through Vite's hot reload).

The dependency and cache directories are **named Docker volumes**, not part of the bind mount: `backend/vendor`,
`backend/var` and `frontend/node_modules`. Bind mounts from a Windows (or macOS) disk into Linux containers are slow,
and these directories hold most of the files that PHP and Vite read. The consequences:

- The host folders `backend/vendor` and `frontend/node_modules` stay empty. Run Composer and npm through Compose, for
  example `docker compose exec app composer require …` or `docker compose exec frontend npm install …`.
- Dependencies are installed when the containers start (`composer install`, `npm install`), so a changed lock file is
  picked up by `docker compose up`.
- File change events do not cross from a Windows disk into the containers, so the Vite dev server polls for changes
  (`WATCH_POLLING` in `compose.yaml`).
- `docker compose down -v` deletes the volumes, including the database.

## Checks

```bash
./check.sh
```

One command runs every check in the containers, and exits non-zero on the first failure:

- backend (`composer check`): PHP-CS-Fixer, PHPStan (level 8), PHPUnit against PostgreSQL and `composer audit`. The
  tests use a separate `app_test` database in the same `db` server as the development database `app`; the script
  creates and migrates it, and every test is rolled back, so the development data is not touched;
- frontend (`npm run check`): ESLint, the production build, and `npm audit` (any vulnerability in the dependencies that
  ship to the browser fails; for development tooling, high and critical ones fail).

On Windows, run it from Git Bash. The CI workflow ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs the same
script. To fix the PHP formatting: `docker compose run --rm app vendor/bin/php-cs-fixer fix`.

## Licence

[MIT](LICENSE)
