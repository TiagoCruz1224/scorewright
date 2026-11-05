# API reference

The design and the full list of planned endpoints are in [architecture.md](architecture.md) §5. This page documents
the endpoints that exist. Every route is also listed in the authorization matrix test
(`backend/tests/AuthorizationMatrixTest.php`), which fails when a route is missing from it.

## Conventions

- JSON over HTTPS under `/api`, same origin as the SPA. Timestamps are ISO 8601 in UTC.
- **Session:** signing in sets a session cookie (`HttpOnly`, `Secure`, `SameSite=Lax`). The session is stored in
  PostgreSQL and ends after 8 hours without a request, or at logout.
- **CSRF:** every `POST`, `PUT`, `PATCH` and `DELETE` needs a `csrf-token` header with a random value of at least 24
  characters (the SPA generates one per page load). A request without it, or whose `Origin`, `Referer` or
  `Sec-Fetch-Site` header shows another site, or with `Origin: null`, answers `403 csrf_failed`. Login and register
  are covered too.
- **Request bodies** are JSON only: send `Content-Type: application/json`, otherwise the answer is
  `415 unsupported_media_type`. A body larger than 6 MiB answers `413 payload_too_large` before it is read. Bodies
  and query strings are validated; unknown fields are rejected.
- **Text fields** reject control characters (`control_character`), such as NUL or escape. Fields that can span lines
  (a project description) accept tab, line feed and carriage return.
- **Lists** take `?page=` (1 to 10,000) and `?perPage=` (default 25, at most 100) and answer
  `{ "items": [...], "page": 1, "perPage": 25, "total": 42 }`.
- **Rate limits** answer `429` with a `Retry-After` header: the number of seconds to wait.
- **Caching:** every response has `Cache-Control: no-store`.
- **Projects:** every project resource lives under `/api/projects/{projectId}`. If you are not a member of that
  project, or it does not exist, the answer is `404 not_found` (the same for both), before any other check.
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
| 400 | `malformed_request` (invalid JSON, or a body cut off on the way) |
| 401 | `unauthenticated` (not signed in), `invalid_credentials` (login) |
| 403 | `forbidden` (a member without the needed role), `csrf_failed` |
| 404 | `not_found` (also: a project you are not a member of, and any path that does not exist, `/api` included) |
| 405 | `method_not_allowed` |
| 409 | `project_archived` (any write to an archived project except restore), `last_owner` |
| 413 | `payload_too_large` (body over 6 MiB) |
| 415 | `unsupported_media_type` (a body that is not `application/json`, or none where one is needed) |
| 422 | `validation_failed`, with `errors[]`; each error has a `field` path and a `code` such as `is_blank`, `too_short`, `too_long`, `invalid_format`, `invalid_type`, `no_such_choice`, `not_in_range`, `control_character`, `unknown_field`, `email_taken`, `no_account` or `already_member` |
| 429 | `too_many_login_attempts`, `too_many_registrations`, `too_many_invites`, `too_many_projects`; with `Retry-After` |
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
- `displayName`: 1 to 100 characters on one line (blank is rejected).
- At most 10 registrations per hour from one client IP (`429 too_many_registrations`), because `email_taken` reveals
  that an account exists.

`201` with the user:

```json
{ "id": "01a1a803-d231-7613-8d09-90acccd35cc6", "email": "ana@example.com", "displayName": "Ana", "createdAt": "2026-11-04T19:23:05+00:00" }
```

### `POST /api/auth/login`

Public. Body `{"email": "…", "password": "…"}`; the email is matched case-insensitively.

- `200` with the user (same shape as above). Signing in from no session, from someone else's session or with a
  session id planted beforehand starts a new session with a new id (no session fixation). Signing in again as the
  user who is already signed in keeps the current session and its id.
- `401 invalid_credentials` for a wrong password or an unknown email (the same answer for both).
- `415 unsupported_media_type` when the body is not `application/json`.
- `429 too_many_login_attempts` after 5 failed attempts in a minute for the same email and IP, or 25 for the same IP.

### `POST /api/auth/logout`

Public. Ends the session; `204` (also when nobody was signed in).

### `GET /api/me`

Signed in. `200` with the current user (same shape as above); `401 unauthenticated` otherwise.

## Projects

Roles per project: **owner**, **evaluator**, **viewer**. Members can read the project; only owners change it or
manage its members. An archived project is read-only (`409 project_archived` on every write except restore) and is
left out of the project list.

A project:

```json
{ "id": "01a1a812-9f7d-78b6-8bcf-f3273e397f53", "name": "Support bot", "description": "", "archivedAt": null, "role": "owner" }
```

`role` is the caller's role in the project. `archivedAt` is the archive time, or `null`.

| Endpoint | Who | Answer |
|---|---|---|
| `GET /api/projects` | signed in | `200`, a list of the caller's projects ordered by name. Active ones only; `?archived=true` lists only the archived ones. |
| `POST /api/projects` | signed in | `201` with the project; the caller becomes its owner. Body `{"name": "…", "description": "…"}`: name 1 to 200 characters on one line, description optional, up to 2,000 (line breaks allowed). At most 30 new projects per hour per user (`429 too_many_projects`). |
| `GET /api/projects/{projectId}` | member | `200` with the project. |
| `PATCH /api/projects/{projectId}` | owner | `200` with the project. Body with `name` and/or `description`; fields that are left out do not change. |
| `POST /api/projects/{projectId}/archive` | owner | `200` with the project (`archivedAt` set). |
| `POST /api/projects/{projectId}/restore` | owner | `200` with the project (`archivedAt` is `null`); also when it was not archived. |

## Members

Owners only. A member:

```json
{ "userId": "01a1a803-d231-7613-8d09-90acccd35cc6", "email": "ana@example.com", "displayName": "Ana", "role": "evaluator" }
```

| Endpoint | Answer |
|---|---|
| `GET /api/projects/{projectId}/members` | `200`, a list of members ordered by display name. |
| `POST /api/projects/{projectId}/members` | `201` with the member. Body `{"email": "…", "role": "owner\|evaluator\|viewer"}`. Only users who already have an account can be added (`422` with `no_account` otherwise; `already_member` if they are in the project). At most 20 invites per hour per inviting user, across all their projects, failed ones included (`429 too_many_invites`): `no_account` tells whether an account exists. |
| `PATCH /api/projects/{projectId}/members/{userId}` | `200` with the member. Body `{"role": "…"}`. `409 last_owner` if this would leave the project without an owner; `404` if the user is not a member. |
| `DELETE /api/projects/{projectId}/members/{userId}` | `204`. An owner can remove themselves when another owner exists; `409 last_owner` otherwise. |
