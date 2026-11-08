# Changelog

The notable changes to Scorewright, by version. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 — 2026-11-08

The MVP: everything in [docs/requirements.md](docs/requirements.md) for the first release.

### Added

- **Accounts and projects:** registration and sign-in with a session cookie, projects with owners, evaluators and
  viewers, invitations of people who already have an account, archiving and restoring.
- **Datasets:** test cases imported from CSV or JSON, all or nothing, with every error by line and field; versions that
  never change, with tags and reference answers; up to 5,000 cases and 5 MB a file.
- **Runs:** the answers of one model or prompt to a dataset version, imported the same way; a run may leave cases
  unanswered.
- **Rubrics:** 1–5 scales and pass/fail criteria with guidance, and automatic checks (exact match, contains, does not
  contain, regex, length, valid JSON), with weights. A rubric that an evaluation uses is locked, and can be duplicated.
- **Evaluations:** one to five runs of one version against a rubric, in blind mode by default. Keyboard-first scoring
  that saves each score as it is given, with a comment per answer. Closing is final.
- **Automatic checks** in two background workers (Symfony Messenger on PostgreSQL), with retries, a time budget per job
  and per criterion, progress at least every second, and a warning on the evaluation page when a job has stalled.
- **Results:** averages, pass rates, distributions and a weighted overall score per run and per tag, computed on read
  (under 500 ms at the MVP limits); the comparison of two runs, with the largest disagreements and a page per case;
  the CSV export, safe from spreadsheet formulas.
- **Accessibility:** WCAG 2.2 AA, checked with axe-core in the unit tests and in a real browser; light and dark themes.
- **Demo data** (`app:demo`) and data at the MVP limits (`app:seed-performance`), for development.
- **Production profile:** `compose.prod.yaml` and the `prod` image target, with its own settings file (`.env.prod`),
  the built frontend served by Caddy, HTTPS and HSTS when Caddy has a domain name, and configurable trusted proxies
  and host names; `./prod-check.sh` and CI check it.
- **Security:** CSRF checks on every unsafe request, rate limits on sign-in, registration, invitations and new
  projects, a `__Host-` session cookie, a database role for the app that is no superuser, and the
  [OWASP ASVS 4.0 level 1 checklist](docs/security.md).
- **Tests:** PHPUnit against PostgreSQL (an authorization matrix of every route and role, the blind-mode rules, the
  results worked out by hand), Vitest, and a Playwright end-to-end test of MVP criterion 1; `./check.sh`, `./e2e.sh`
  and the CI workflow run them. Dependabot proposes the dependency updates every week.
