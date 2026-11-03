# 0001. Modular monolith on Symfony 7.4 LTS, PostgreSQL 18 and a React SPA

Status: proposed (2026-11-03)

## Context

The MVP is small, built by one developer and must start with one command from a clean clone. The requirements ask for
a modular monolith and rule out microservices without a concrete need. Versions must be supported for the life of the
project.

## Decision

- One Symfony **7.4 LTS** application on **PHP 8.5**, split into modules by directory (Users, Projects, Datasets,
  Evaluations, Analytics) with one-way dependencies checked by a test.
- **PostgreSQL 18** is the only data store, and holds the job queue too (see ADR 0003).
- A **React 19 + TypeScript 6.0 + Vite** single-page app, built to static files and served from the same origin as
  the API by FrankenPHP.
- **TypeScript 7.0** is not adopted yet: typescript-eslint still requires `typescript <6.1.0`. Revisit when it supports 7.

## Consequences

- One deployable image and one database: simple to run, test and explain.
- Module boundaries depend on discipline plus the architecture test, not on process isolation.
- Upgrades to plan: PHP 8.5 active support ends 2027-12-31; Symfony 7.4 bug fixes end in Nov 2028. Move to the next
  Symfony LTS when it is released and the dependencies support it.
