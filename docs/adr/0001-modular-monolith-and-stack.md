# 0001. Modular monolith on Symfony 7.4 LTS, PostgreSQL 18 and a React SPA

Status: accepted (approved 2026-11-04)

## Context

The MVP is small, built by one developer and must start with one command from a clean clone. The requirements ask for
a modular monolith and rule out microservices without a concrete need. Versions must be supported for the life of the
project. It is a portfolio project, so its author must be able to explain every layer in an interview.

## Decision

- One Symfony **7.4 LTS** application on **PHP 8.5**, split into modules by directory (Users, Projects, Datasets,
  Evaluations, Analytics) with one-way dependencies checked by a test.
- **PostgreSQL 18** is the only data store, and holds the job queue too (see ADR 0003).
- A **React 19 + JavaScript + Vite 8** single-page app with **React Router 8** and **Tailwind CSS 4** (through the
  `@tailwindcss/vite` plugin), built to static files and served from the same origin as the API by FrankenPHP.
- **No TypeScript.** The original plan proposed it, but the author works in PHP and JavaScript and must be able to
  explain every line; TypeScript would add a compiler and type tooling that a small SPA does not need. The frontend is
  plain JavaScript (ES modules and JSX), linted with ESLint.

## Consequences

- One deployable image and one database: simple to run, test and explain.
- Module boundaries depend on discipline plus the architecture test, not on process isolation.
- Without TypeScript there are no compile-time checks on the shape of API responses. The API contract is enforced on
  the server (DTO validation, Problem Details) and covered by API tests; the frontend relies on Vitest tests and ESLint.
  The API client is one small module, so adding types later (JSDoc or TypeScript) touches few files.
- Upgrades to plan: PHP 8.5 active support ends 2027-12-31; Symfony 7.4 bug fixes end in Nov 2028. Move to the next
  Symfony LTS when it is released and the dependencies support it.
