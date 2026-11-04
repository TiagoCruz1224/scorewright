# Scorewright — Requirements and MVP scope

Status: **approved 2026-11-04** (TASK-019), together with the architecture (TASK-020–026), which builds on this
document.

## 1. Purpose

Scorewright is an open-source web platform for evaluating the answers of AI systems. A team collects test cases in
datasets, imports the answers that one or more models gave to those cases, scores them with explicit criteria (by hand
and with deterministic automatic checks) and compares models on the same data.

It is a portfolio project: the goal is a small product that is correct, secure, tested and well documented, not a large
one. It also gives a concrete place to practise AI-security evaluation (prompt injection, unsafe answers) without
claiming expertise in it.

## 2. Users

| Role | Wants to |
|---|---|
| **Project owner** | create a project, invite members, decide what is measured, see the comparison between models |
| **Evaluator** | score answers quickly and consistently against clear criteria |
| **Viewer** | read results and dashboards without changing anything |

One person can have different roles in different projects. There is no organisation/tenant level in the MVP.

## 3. Glossary

| Term | Meaning |
|---|---|
| Project | Workspace that owns datasets, runs, rubrics, evaluations and members. |
| Dataset | Named collection of test cases. Every import creates a new immutable **dataset version**. |
| Test case | One input (prompt), with optional reference answer, tags and metadata. |
| Run | The answers of one model/system configuration (label, model name, parameters as free text) to one dataset version. Imported, not generated, in the MVP. |
| Rubric | Ordered list of **criteria**. A criterion is manual (scale 1–5 or pass/fail, with guidance text) or automatic (deterministic check). |
| Evaluation | A rubric applied to one or more runs of the same dataset version. Produces one score per answer and criterion. |

## 4. MVP scope

### In scope

**Users and access (TASK-024, 029)**
- Register, log in, log out. Passwords hashed with the framework's current algorithm. No public user listing.
- Per-project roles:
  - **owner**: manages members, imports datasets and runs, creates rubrics and evaluations, closes evaluations,
    deletes things;
  - **evaluator**: scores answers and adds comments; reads everything else in the project, except the results of a
    blind evaluation, which stay hidden from evaluators until the owner closes it;
  - **viewer**: read-only.
- Every API call checks project membership; resources of other projects answer 404.

**Projects (TASK-030)**
- Create, rename, archive a project; invite an existing user by email; change or remove a member's role.

**Datasets (TASK-030, 031)**
- Import test cases from CSV or JSON (documented schema with optional `id`, `input`, optional `reference`, `tags`,
  `metadata`). `id` is the case key that runs refer to; it must be unique in the file and is generated (row number)
  when absent.
- Validation before anything is stored: encoding UTF-8, required fields, field lengths, maximum file size and row count
  (initial limits: 5 MB, 5,000 rows; configurable). The whole import is rejected with line-level errors if any row is
  invalid. Nothing partial is saved.
- Each import creates a new dataset version; existing versions never change.
- Browse and filter cases by tag and text.

**Runs (TASK-030, 031)**
- Import a run (CSV/JSON) for a dataset version: one answer per test case id, plus run label and model description.
- Same validation and all-or-nothing rules as datasets. Unknown or duplicate case ids are errors.

**Rubrics and scoring (TASK-032, 033)**
- Create rubrics with manual criteria (1–5 scale or pass/fail, guidance text, optional weight). A rubric that has been
  used by an evaluation can no longer be edited, so existing scores keep their meaning; it can be duplicated.
- Automatic deterministic criteria: exact match with the reference, contains/does-not-contain text, regular expression,
  length limits, valid JSON. They run as background jobs and can be re-run.
- Manual scoring screen: one answer at a time, keyboard friendly, shows input, reference and answer, saves each score
  immediately, optional comment per answer (independent of the scores). "Next answer" means the next one that still has
  missing scores; there is no locking between evaluators in the MVP. Blind mode hides the run label to reduce bias.
- Progress per evaluation (scored / total).
- The owner can delete an evaluation (with its scores); datasets, runs and rubrics that no evaluation uses can then be
  deleted. An archived project is read-only and hidden from the project list; the owner can restore it.

**Results (TASK-034, 035)**
- Dashboard per evaluation: average and distribution per criterion and per run; breakdown by tag.
- Comparison of two runs on the same dataset version: score difference per criterion, per tag and per case, and a list
  of the cases where they disagree most.
- Export results as CSV, with spreadsheet formula injection neutralised.

**Demo (TASK-036, 043)**
- Command that loads synthetic, realistic demo data (one project, a dataset of about 50 cases including
  prompt-injection and refusal cases, two runs, one rubric, partial scores). No real user data.
- Local start with Docker Compose and a documented one-command setup.

### Out of scope for the MVP (possible later)

- Calling AI provider APIs, LLM-as-judge, or any paid service.
- Python workers or automated AI evaluation (planned as a later extension, behind the same job interface).
- Organisations, billing, SSO/OAuth login, email delivery (invites only add existing users).
- Real-time collaboration, inter-rater agreement statistics, dataset editing in the browser.
- Public sharing links.

## 5. Non-functional requirements

| Area | Requirement |
|---|---|
| Security | OWASP ASVS level 1 as the reference. Authorization tested for every endpoint (member, non-member, each role). Input validated at the API boundary. No secrets in the repository; `.env.example` with placeholders only. Security headers and CORS restricted to the frontend origin. Uploaded files parsed, never executed or served back as files. |
| Privacy | Datasets may contain sensitive text: project data is never visible outside the project, logs never contain case contents. |
| Reliability | Imports are transactional. Background jobs are idempotent and can be retried. |
| Performance | With the demo limits (5,000 cases, 5 runs) list and dashboard requests answer in under 500 ms on a developer laptop; heavy work runs in jobs. |
| Quality | Automated backend and frontend tests for the critical flows, static analysis and formatting in CI, all reproducible locally (TASK-037–042). |
| Accessibility | WCAG 2.2 AA for the scoring screen and dashboards: keyboard use, visible focus, contrast, charts with a text/table alternative. |
| Documentation | English README with setup, architecture overview, API reference and ADRs. |
| Licence | Open source, MIT ([LICENSE](../LICENSE)). |

## 6. Technology constraints

Proposed in the original plan and to be confirmed against current official documentation in the architecture
(TASK-020): PHP 8.3+, current supported Symfony LTS, Doctrine with PostgreSQL, Symfony Messenger (Redis or Doctrine
transport), React with TypeScript, Docker Compose, PHPUnit, PHPStan, frontend tests, GitHub Actions. Modular monolith;
no microservices without a concrete requirement.

Approved on 2026-11-04 with one change: the frontend is React 19 in **JavaScript** (no TypeScript) with Tailwind CSS 4.
The confirmed versions are in the [architecture](architecture.md) §1.

## 7. MVP acceptance criteria

1. A new user can register, create a project, import a dataset and two runs, create a rubric with one manual and one
   automatic criterion, start an evaluation, score answers and see the comparison between the two runs.
2. A user who is not a member cannot read or change anything in that project (tested for every endpoint).
3. An invalid import file is rejected with clear line-level errors and nothing is stored.
4. The demo command plus Docker Compose gives a working instance from a clean clone, following only the README.
5. CI runs the backend tests, frontend tests and static analysis, and all pass.

## 8. Decisions

Decided by Tiago on 2026-11-04:

1. Licence: **MIT**.
2. Name: **Scorewright** (replaces the working name).
3. No hosted demo for now: the first public version is run locally with Docker Compose only.
