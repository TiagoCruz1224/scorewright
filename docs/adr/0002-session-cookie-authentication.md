# 0002. Session cookie authentication instead of JWT

Status: accepted (approved 2026-11-04)

## Context

The SPA and the API share one origin (ADR 0001). The requirements ask for log out, and the data may be sensitive. A
JWT stored in `localStorage` can be read by any injected script and cannot be revoked on log out without a deny list.

## Decision

- Symfony's stateful firewall with `json_login`, and sessions stored in PostgreSQL.
- The cookie is `HttpOnly`, `Secure` and `SameSite=Lax`. A session id from before login is replaced at login (no
  session fixation), and logout invalidates the session.
- Unsafe methods (`POST`, `PUT`, `PATCH`, `DELETE`) are protected against CSRF in three layers (all routes are under
  `/api`; the check applies to every path anyway):
  - a **required `csrf-token` header** with a random value of at least 24 characters. A page on another site cannot
    add a custom header to a request without a CORS preflight, which this API never allows. This is the header
    variant of Symfony's stateless CSRF check (`check_header: true`); there is no double-submit cookie;
  - Symfony's stateless check also rejects the request when its `Origin`, `Referer` or `Sec-Fetch-Site` header shows
    another site. It treats `Origin: null` (sent by sandboxed frames) as no information, so our listener rejects that
    value itself;
  - the `SameSite=Lax` session cookie is not sent with cross-site `POST`, `PUT`, `PATCH` or `DELETE` requests.

  The check runs before the firewall, so login and register are covered too.
- `login_throttling` limits password guessing.

## Consequences

- Tokens never reach JavaScript, and logout is immediate.
- The API is meant for this SPA. Third-party API clients (for example a future CI integration) would need a separate
  token mechanism, decided when that need exists.
- Every unsafe request from the SPA must send the CSRF header; the shared `fetch` client does this in one place.
