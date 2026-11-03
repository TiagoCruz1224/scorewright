# 0002. Session cookie authentication instead of JWT

Status: proposed (2026-11-03)

## Context

The SPA and the API share one origin (ADR 0001). The requirements ask for log out, and the data may be sensitive. A
JWT stored in `localStorage` can be read by any injected script and cannot be revoked on log out without a deny list.

## Decision

- Symfony's stateful firewall with `json_login`, and sessions stored in PostgreSQL.
- The cookie is `HttpOnly`, `Secure` and `SameSite=Lax`. The session id is regenerated on login, and logout
  invalidates the session.
- Unsafe methods are protected with Symfony's stateless CSRF check (Origin/Referer) plus the double-submit
  `csrf-token` header.
- `login_throttling` limits password guessing.

## Consequences

- Tokens never reach JavaScript, and logout is immediate.
- The API is meant for this SPA. Third-party API clients (for example a future CI integration) would need a separate
  token mechanism, decided when that need exists.
- Every unsafe request from the SPA must send the CSRF header; the shared `fetch` client does this in one place.
