# 0006. Plain Symfony controllers with DTOs and RFC 9457 errors

Status: proposed (2026-11-03)

## Context

The API is small (about 40 routes), project-scoped, and its authorization rules (membership, blind mode, locked
rubrics, closed evaluations) are the most important part to get right and to test. Error responses must be structured
enough for the import-errors screen.

## Decision

- Write plain Symfony controllers. Request bodies map to DTOs with `#[MapRequestPayload]` and are validated with Symfony
  Validator; unknown fields are rejected.
- Responses come from explicit serializer groups or response DTOs.
- All errors use `application/problem+json` (RFC 9457) with a stable `code` and, for validation and import errors, an
  `errors` array (see the architecture doc, §5).
- Do not use API Platform for the MVP.

## Consequences

- Each endpoint's behaviour and access check is visible in one place, which keeps the authorization matrix test easy
  to reason about.
- More hand-written code than API Platform would generate (pagination, filters, OpenAPI). The API reference is written
  in Markdown first. An OpenAPI document (for example with NelmioApiDocBundle) can be added later if it proves useful.
