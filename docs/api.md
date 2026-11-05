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
  project, or it does not exist, the answer is `404 not_found` (the same for both), before any other check. A
  dataset, version, run or rubric of another project is also a `404`, the same as one that does not exist.
- **Uploads** (dataset imports) are `multipart/form-data` with the file in a field named `file`; anything else answers
  `415`.
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
| 409 | `project_archived` (any write to an archived project except restore), `last_owner`, `rubric_locked` (an edit of a rubric that an evaluation uses) |
| 413 | `payload_too_large` (body over 6 MiB, or an import file over 5 MiB) |
| 415 | `unsupported_media_type` (a body that is not `application/json`, or none where one is needed; for uploads, not `multipart/form-data`) |
| 422 | `validation_failed`, with `errors[]`; each error has a `field` path and a `code` such as `is_blank`, `too_short`, `too_long`, `invalid_format`, `invalid_type`, `no_such_choice`, `not_in_range`, `control_character`, `unknown_field`, `email_taken`, `no_account`, `already_member`, `name_taken`, `label_taken`, `not_found` (a dataset version that is not the project's), `required` (a missing upload), and for rubrics `too_few`, `too_many`, `not_allowed`, `invalid_regex` and `max_below_min` |
| 422 | `import_rejected`: the dataset file has errors and nothing was saved (see [Import errors](#import-errors)) |
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

## Datasets

Members read datasets; owners import and delete them. An import is checked as a whole before anything is saved: a
file with any error answers `422 import_rejected` and stores nothing. A version never changes; importing again adds
the next version. `P` is `/api/projects/{projectId}`.

A dataset, and a version:

```json
{ "id": "01a1ab22-2c59-72e1-8ec0-bd9c3387c210", "name": "Support cases", "createdAt": "2026-11-05T09:55:12+00:00" }
```

```json
{ "id": "01a1ab22-443b-7982-8bf8-37503264e149", "number": 1, "caseCount": 5000, "sourceFilename": "cases.csv", "sha256": "1c972c35…", "createdAt": "2026-11-05T09:55:12+00:00" }
```

The file itself is not kept, only its name (without any path or control characters, at most 255 characters) and its
SHA-256.

| Endpoint | Who | Answer |
|---|---|---|
| `GET P/datasets` | member | `200`, a list ordered by name; each dataset has its `latestVersion`. |
| `POST P/datasets` | owner | Multipart: `name` (1 to 200 characters on one line, unique in the project: `422 name_taken` otherwise) and `file`. `201` with the dataset and `"versions": [version 1]`. |
| `GET P/datasets/{datasetId}` | member | `200` with the dataset and all its `versions`, newest first. |
| `DELETE P/datasets/{datasetId}` | owner | `204`; its versions, cases and runs go too. |
| `POST P/datasets/{datasetId}/versions` | owner | Multipart: `file`. `201` with the new version, numbered after the latest. |
| `GET P/dataset-versions` | member | `200`, a list of every version in the project, each with its `dataset` (`id`, `name`), ordered by dataset name and then newest first: to choose a version, as when importing a run. |
| `GET P/dataset-versions/{versionId}/cases` | member | `200`, a list of the version's cases in file order. `?tag=` keeps the cases with exactly that tag; `?q=` keeps those whose input, reference or case key contains the text, ignoring case. |

A case:

```json
{ "id": "01a1ab22-…", "caseKey": "refund-1", "input": "I want a refund.\nMy order is late.", "reference": null, "tags": ["billing"], "metadata": {} }
```

### Import format

The file is UTF-8 (a byte order mark is ignored), named `.csv` or `.json`, with at most **5 MiB** and **5,000 cases**.
Both limits are parameters in `backend/config/services.yaml` (`dataset_import.max_file_bytes`,
`dataset_import.max_cases`); the request body limit (6 MiB) must stay above the file limit.

| Field | | Limit |
|---|---|---|
| `id` | optional: the case key that runs refer to | 100 characters on one line, unique in the file |
| `input` | required: the prompt | 20,000 characters; line breaks and tabs are fine |
| `reference` | optional: the expected answer | 20,000 characters; line breaks and tabs are fine |
| `tags` | optional | 20 per case, 50 characters each, one line; repeated tags are dropped |
| `metadata` | optional JSON object | 10,240 bytes as JSON, 30 levels deep |

No value may contain other control characters, such as NUL or escape (`control_character`).

**Case keys.** If one case has an `id`, every case needs one, and no two may be equal. Without ids, a case's key is
its line number minus one in CSV (the first case after the header is `1`) or its item number in JSON.

**CSV.**
- The first line names the columns, in any order and any case: `input`, and optionally `id`, `reference`, `tags` and
  `metadata`. Other columns are rejected.
- Values are separated by commas, or by semicolons when the header has more semicolons than commas (as spreadsheets
  save CSV in many European locales).
- Quotes follow RFC 4180: a value that contains the separator, a quote or a line break is in double quotes, with each
  quote inside doubled.
- `tags` are comma-separated in one cell; `metadata` is a JSON object in one cell, or empty. An empty `reference`
  means none. Blank lines are skipped.

```csv
id,input,reference,tags,metadata
greeting,Say hello,Hello!,"smalltalk, short","{""lang"": ""en""}"
refund-1,"I want a refund.
My order is late.",,billing,
```

**JSON.** An array of objects with the same fields. `id` is a string or a whole number, `tags` an array of strings.

```json
[
  { "id": "greeting", "input": "Say hello", "reference": "Hello!", "tags": ["smalltalk", "short"], "metadata": { "lang": "en" } },
  { "id": "refund-1", "input": "I want a refund.\nMy order is late.", "tags": ["billing"] }
]
```

### Import errors

```json
{
  "type": "/problems/import-rejected",
  "title": "Import rejected: nothing was saved",
  "status": 422,
  "code": "import_rejected",
  "errorCount": 2,
  "affectedLines": 2,
  "errorCountsByCode": { "required": 1, "duplicate_case_key": 1 },
  "truncated": false,
  "errors": [
    { "line": 3, "field": "input", "code": "required", "message": "The input is empty. Every case needs the prompt text in \"input\".", "excerpt": "" },
    { "line": 4, "field": "id", "code": "duplicate_case_key", "message": "Another case (line 2) already uses this id. Each id must be unique in the file.", "excerpt": "a" }
  ]
}
```

- `line` in CSV, as a spreadsheet numbers it: the header is line 1, and a quoted value over several lines is still one
  line. `item` in JSON, from 1. It is `null` for an error about the whole file.
- `field` is the column or field, or `null`.
- `message` says how to fix the error; `excerpt` shows at most 80 characters of the value. Excerpts appear only in
  this response, never in logs.
- At most 100 errors are listed (`truncated` is then `true`); `errorCount`, `affectedLines` (lines or items with an
  error) and `errorCountsByCode` cover the whole file.

| `code` | When |
|---|---|
| `unsupported_file_type` | the name does not end in `.csv` or `.json` |
| `empty_file` | no bytes, a header but no cases, or `[]` |
| `invalid_utf8` | not UTF-8 (CSV: per value; JSON: the whole file) |
| `invalid_csv` | a quote that is not closed, text after a closing quote, or a line with more or fewer values than the header |
| `invalid_json` | not valid JSON, not an array, or metadata nested deeper than 30 levels |
| `missing_column` | no `input` column |
| `unknown_column` | a column outside the format |
| `duplicate_column` | the same column twice |
| `unknown_field` | (JSON) a field outside the format |
| `invalid_type` | (JSON) a case that is not an object, or a field of the wrong type |
| `too_many_rows` | more than 5,000 cases (reported on the first case over the limit) |
| `required` | an empty input, or no id when other cases have one; in a run, an answer without `id` or `output` |
| `unknown_case_key` | (run) an id that is not a case of the dataset version |
| `too_long` | an id over 100 characters, an input or reference over 20,000, or a run output over 50,000; in CSV also a quoted value too large to read at all (`field` is then `null`) |
| `duplicate_case_key` | an id that an earlier case already uses; in a run, a second answer to the same case |
| `too_many_tags` | more than 20 tags |
| `tag_too_long` | a tag over 50 characters |
| `invalid_metadata` | metadata that is not a JSON object (in CSV, also invalid JSON or nested deeper than 30 levels) |
| `metadata_too_large` | metadata over 10,240 bytes as JSON |
| `control_character` | a control character in a value (tab and line breaks are fine in input and reference), or NUL in metadata |

A file over 5 MiB answers `413 payload_too_large` before it is read.

## Runs

A run is the answers of one model or system configuration to the cases of one dataset version, imported from a file.
Members read runs; owners import and delete them. A run may leave cases unanswered: it shows its coverage,
`answerCount` of `datasetVersion.caseCount` ("198 of 200 answered"), and unanswered cases are not scored.

A run:

```json
{
  "id": "01a1ab5e-…",
  "label": "baseline-v1",
  "modelDescription": "Model X, temperature 0",
  "datasetVersion": { "id": "01a1ab4c-…", "number": 2, "caseCount": 200, "dataset": { "id": "01a1ab4c-…", "name": "Support cases" } },
  "answerCount": 198,
  "sourceFilename": "baseline-v1.csv",
  "sha256": "9f2c…",
  "createdAt": "2026-11-05T11:20:00+00:00"
}
```

**No endpoint returns a run's answers** (architecture §5, "Run data and blind mode"): the run endpoints give metadata
only. Answers will appear only in scoring and in results, where blind mode applies.

| Endpoint | Who | Answer |
|---|---|---|
| `GET P/runs` | member | `200`, a list of runs, newest first. `?datasetVersion=` keeps the runs of one version. |
| `POST P/runs` | owner | Multipart: `datasetVersion` (a version id of this project), `label` (1 to 100 characters on one line, unique in that version: `422 label_taken` otherwise), `modelDescription` (optional, up to 2,000 characters, line breaks allowed) and `file`. `201` with the run. A `datasetVersion` that is not one of the project's (another project's, unknown or malformed) answers `422` with `{"field": "datasetVersion", "code": "not_found"}`, the same for all. |
| `GET P/runs/{runId}` | member | `200` with the run. |
| `DELETE P/runs/{runId}` | owner | `204`; its answers go too. |

### Run files

The same rules as dataset files: UTF-8, `.csv` or `.json`, at most 5 MiB and 5,000 answers, checked as a whole
(`422 import_rejected` with the same error format, and nothing saved).

| Field | | Limit |
|---|---|---|
| `id` | required: the id of the case this answers, a case key of the chosen version | each case at most once |
| `output` | required: the answer | 50,000 characters; line breaks and tabs are fine. **Empty is allowed**: a model that answered nothing gave an answer to score. |

- CSV: the header names both columns, `id` and `output`, in any order. JSON: an array of `{"id": …, "output": …}`,
  where `id` is a string or a whole number and `output` a string.
- An `id` that is not a case of the version is `unknown_case_key`; a second answer to the same case is
  `duplicate_case_key`. Cases the file does not answer are simply unanswered.

```csv
id,output
greeting,Hello! How can I help?
refund-1,"Sorry about the delay.
Your refund is on its way."
```

```json
[
  { "id": "greeting", "output": "Hello! How can I help?" },
  { "id": "refund-1", "output": "" }
]
```

## Rubrics

A rubric is an ordered list of criteria that answers are scored on. Members read rubrics; owners create, edit,
duplicate and delete them. Once an evaluation uses a rubric it is **locked** (`lockedAt` is set, by the evaluation
that first uses it): it never changes again, so existing scores keep their meaning, but it can be duplicated.

A rubric:

```json
{
  "id": "01a1ac2e-…",
  "name": "Support quality",
  "criterionCount": 4,
  "lockedAt": null,
  "duplicatedFrom": null,
  "criteria": [
    { "id": "01a1ac2e-…", "kind": "scale_1_5", "name": "Tone", "guidance": "1: rude or cold.\n5: warm and clear.", "weight": 2, "checkType": null, "checkParams": null },
    { "id": "01a1ac2e-…", "kind": "pass_fail", "name": "Correct", "guidance": "Pass when every fact is right.", "weight": null, "checkType": null, "checkParams": null },
    { "id": "01a1ac2e-…", "kind": "automatic", "name": "Starts with Refund", "guidance": "", "weight": null, "checkType": "regex", "checkParams": { "pattern": "^Refund", "ignoreCase": false } },
    { "id": "01a1ac2e-…", "kind": "automatic", "name": "Mentions the order", "guidance": "", "weight": null, "checkType": "contains", "checkParams": { "text": "order", "ignoreCase": true } }
  ]
}
```

- `duplicatedFrom` is `{"id", "name"}` of the rubric this one was copied from, or `null` (also when that one was
  deleted). In a list, each rubric has only `id`, `name`, `criterionCount` and `lockedAt`.
- The order of `criteria` is the rubric's order. A weight left empty (`null`) counts as 1 in the results; a whole
  weight comes back as a JSON integer (`2`, not `2.0`).

| Endpoint | Who | Answer |
|---|---|---|
| `GET P/rubrics` | member | `200`, a list of rubrics ordered by name. |
| `POST P/rubrics` | owner | `201` with the rubric. Body `{"name", "criteria"}`, below. |
| `GET P/rubrics/{rubricId}` | member | `200` with the rubric. |
| `PUT P/rubrics/{rubricId}` | owner | `200` with the rubric. The same body as `POST`, which **replaces** the name and every criterion (new criterion ids, in the new order). A locked rubric answers `409 rubric_locked`; a body that is not valid answers `422` first. |
| `DELETE P/rubrics/{rubricId}` | owner | `204`; its criteria go too, and its copies stay. |
| `POST P/rubrics/{rubricId}/duplicate` | owner | `201` with the copy: the same criteria, not locked, with `duplicatedFrom` set. Optional body `{"name": "…"}`; without it the name is "<name> (copy)" (shortened to fit 200 characters). Works on a locked rubric too. |

### Rubric body

- `name`: 1 to 200 characters on one line, unique in the project (`422 name_taken`).
- `criteria`: an array of 1 to 30 criteria (`too_few`, `too_many`), in order. Each error's `field` is the path of the
  field, such as `criteria[2].checkParams.pattern`.

Each criterion:

| Field | | Limit |
|---|---|---|
| `kind` | required: `scale_1_5` or `pass_fail` (manual, scored by people) or `automatic` (a check) | |
| `name` | required | 200 characters on one line |
| `guidance` | required for `scale_1_5` and `pass_fail`: how to score; optional for `automatic` | 5,000 characters; line breaks and tabs are fine |
| `weight` | optional number | above 0 and at most 100 |
| `checkType` | required for `automatic`, not allowed otherwise (`not_allowed`) | below |
| `checkParams` | an object for `automatic` (may be left out when the check needs none), not allowed otherwise | below |

The checks of automatic criteria. Each gives pass or fail; they run in the background when an evaluation starts.
Parameters that are not listed answer `unknown_field`, and `ignoreCase` is `false` when left out.

| `checkType` | `checkParams` | Passes when the answer… |
|---|---|---|
| `exact_match` | `{"ignoreCase"?: bool}` | equals the case's reference, both without spaces at the ends. A case without a reference gets a check error (`missing_reference`), not a fail. |
| `contains` | `{"text": "…", "ignoreCase"?: bool}`, text 1 to 500 characters on one line | contains the text |
| `not_contains` | the same | does not contain the text |
| `regex` | `{"pattern": "…", "ignoreCase"?: bool}`, pattern 1 to 500 characters on one line | matches the pattern |
| `length` | `{"min"?: int, "max"?: int}`, at least one, each 0 to 50,000, `min` ≤ `max` (`max_below_min`) | has from `min` to `max` characters |
| `valid_json` | none | is valid JSON as a whole |

**Regex patterns** are PCRE, written **without delimiters or flags**: `^Refund \d+`, not `/^Refund \d+/i`. The server
puts the pattern between delimiters that no pattern can contain, so a `/` needs no escaping, and always adds the `u`
flag (the pattern and the answer are read as UTF-8), plus `i` when `ignoreCase` is true. The pattern must compile when
the rubric is saved: otherwise `invalid_regex`, with PCRE's reason in the message, such as "This pattern does not
compile: missing closing parenthesis at offset 7."
