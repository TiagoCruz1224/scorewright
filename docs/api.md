# API reference

The design and the full list of planned endpoints are in [architecture.md](architecture.md) §5. This page documents
the endpoints that exist. Every route is also listed in the authorization matrix test
(`backend/tests/AuthorizationMatrixTest.php`), which fails when a route is missing from it.

## Conventions

- JSON over HTTPS under `/api`, same origin as the SPA. Timestamps are ISO 8601 in UTC.
- **Session:** signing in sets a session cookie (`HttpOnly`, `Secure`, `SameSite=Lax`). The session is stored in
  PostgreSQL and ends after 8 hours without a request, or at logout.
- **CSRF:** every `POST`, `PUT`, `PATCH` and `DELETE` under `/api` needs a `csrf-token` header with a random value of
  at least 24 characters (the SPA generates one per page load). A request without it, or whose `Origin`, `Referer` or
  `Sec-Fetch-Site` header shows another site, answers `403 csrf_failed`. Login and register are covered too.
- **Request bodies** are validated; unknown fields are rejected.
- **Errors** are `application/problem+json` ([RFC 9457](https://www.rfc-editor.org/rfc/rfc9457)) with a stable `code`:

```json
{
  "type": "/problems/validation-failed",
  "title": "The request is not valid",
  "status": 422,
  "code": "validation_failed",
  "errors": [
    { "field": "password", "code": "too_short", "message": "This value is too short. It should have 12 characters or more." }
  ]
}
```

| Status | `code` |
|---|---|
| 400 | `malformed_request` (invalid JSON, or login without a JSON body) |
| 401 | `unauthenticated` (not signed in), `invalid_credentials` (login) |
| 403 | `forbidden`, `csrf_failed` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 422 | `validation_failed`, with `errors[]`; each error has a `field` path and a `code` such as `is_blank`, `too_short`, `too_long`, `invalid_format`, `invalid_type`, `unknown_field` or `email_taken` |
| 429 | `too_many_login_attempts` |
| 500 | `internal_error` (details are only in the server log) |
| 503 | `database_unavailable` (health check) |

## Endpoints

### `GET /api/health`

Public. `200 {"status":"ok"}` when the API can reach the database, otherwise `503 database_unavailable`.

### `POST /api/auth/register`

Public. Creates an account; it does not sign in.

```json
{ "email": "ana@example.com", "password": "at least 12 characters", "displayName": "Ana" }
```

- `email`: valid address, at most 254 characters, stored lower-case, unique (`email_taken` otherwise, whatever the case).
- `password`: 12 to 128 characters, no composition rules.
- `displayName`: 1 to 100 characters (blank is rejected).

`201` with the user:

```json
{ "id": "01a1a803-d231-7613-8d09-90acccd35cc6", "email": "ana@example.com", "displayName": "Ana", "createdAt": "2026-11-04T19:23:05+00:00" }
```

### `POST /api/auth/login`

Public. Body `{"email": "…", "password": "…"}`; the email is matched case-insensitively.

- `200` with the user (same shape as above) and a new session cookie. Any session id the browser had before is
  replaced.
- `401 invalid_credentials` for a wrong password or an unknown email (the same answer for both).
- `429 too_many_login_attempts` after 5 failed attempts in a minute for the same email and IP, or 25 for the same IP.

### `POST /api/auth/logout`

Public. Ends the session; `204` (also when nobody was signed in).

### `GET /api/me`

Signed in. `200` with the current user (same shape as above); `401 unauthenticated` otherwise.
