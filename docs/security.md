# Security: OWASP ASVS 4.0.3, level 1

Scorewright is checked against the [OWASP Application Security Verification Standard 4.0.3](https://owasp.org/www-project-application-security-verification-standard/),
level 1. This page lists every level 1 requirement of the chapters that apply, in the standard's order. For each
one: how the app meets it, and the test that shows it. The design behind it is in
[architecture §6 and §9](architecture.md#6-authentication-and-authorization), the production setup in the
[README](../README.md#production).

- ✅ met: how, and where it is tested;
- ➖ does not apply, with the reason;
- ⏳ not met yet: left for after the MVP, with the reason.

**Chapters left out:** V1 (architecture) has no level 1 requirements. In the other chapters, the requirements for
features Scorewright does not have (SOAP, GraphQL, out-of-band and one-time-password authenticators, multi-factor
authentication) are grouped as ➖ in one row.

**The tests** are in `backend/tests` (PHPUnit; `./check.sh`), `frontend/src` (Vitest; `./check.sh`) and `e2e/tests`
(Playwright; `./e2e.sh`). "Prod profile" means checked by hand with `compose.prod.yaml`, as described [at the end](#how-this-was-checked).

## V2 Authentication

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 2.1.1 | Passwords of at least 12 characters | ✅ | `RegisterRequest`: 12 to 128 characters | `Users/AuthTest`: `testPasswordsOf12To128CharactersHaveNoCompositionRules`, `testRegisterRejectsInvalidBodiesFieldByField` |
| 2.1.2 | At least 64 characters allowed, more than 128 refused | ✅ | The same rule | The same tests |
| 2.1.3 | No truncation | ✅ | Symfony's `auto` hasher; a password is taken whole, also past bcrypt's 72 bytes | `Users/AuthTest::testAPasswordIsTakenWholeWhateverItsCharacters` |
| 2.1.4 | Any printable Unicode character, spaces included | ✅ | No character rules | The same test (umlauts, an emoji, spaces) |
| 2.1.5 | Users can change their password | ⏳ | There is no account settings page in the MVP: the account page shows the profile only (requirements, out of scope). Until then the operator resets a password in the database. | — |
| 2.1.6 | Changing the password asks for the current one | ⏳ | As 2.1.5 | — |
| 2.1.7 | Passwords checked against breached passwords | ⏳ | Not done: the external lookup was declined (a third-party call, architecture §6). A local list of the 10,000 most common passwords is the planned way. | — |
| 2.1.8 | A password strength meter | ⏳ | Not in the UX of the MVP | — |
| 2.1.9 | No composition rules | ✅ | Length only | `Users/AuthTest::testPasswordsOf12To128CharactersHaveNoCompositionRules` |
| 2.1.10 | No periodic rotation or password history | ✅ | Neither exists | — (nothing to test) |
| 2.1.11 | Paste, browser helpers and password managers work | ✅ | Plain `<input type="password">` with `autocomplete="current-password"` / `"new-password"`; nothing blocks paste | — (`frontend/src/routes/auth.jsx` has no paste or autocomplete blocking) |
| 2.1.12 | The user can show the masked password | ⏳ | No show-password button yet; some browsers (Edge) have their own | — |
| 2.2.1 | Anti-automation against credential attacks | ✅ | `login_throttling`: 5 failed attempts a minute per email and IP, 25 per IP; 10 registrations an hour per IP; `429` with `Retry-After`. Behind a trusted proxy the IP is the client's. | `Users/AuthTest`: `testLoginIsThrottledAfterFiveFailedAttempts`, `testRegistrationsAreLimitedTo10PerHourForAnAddress`, `testTheLimitByAddressSeesTheClientBehindATrustedProxy` |
| 2.2.2 | Weak authenticators (SMS, email) limited | ➖ | There are none: a password only | — |
| 2.2.3 | Notification after a change of authentication details | ➖ | Nothing can change them (2.1.5), and the MVP sends no email | — |
| 2.3.1 | System-generated initial passwords are random and expire | ➖ | Users choose their password at registration. The demo accounts' public password exists only in `dev` and `test`: `app:demo` refuses to run in `prod`. | `Analytics/DemoCommandTest::testItRefusesToRunOutsideDevAndTest` |
| 2.5.1–2.5.6 | Credential recovery | ➖ | There is no recovery path (no email in the MVP), so no hint, secret question or recovery secret; there is no shared or default account in production (see 2.3.1). | — |
| 2.7.x, 2.8.1 | Out-of-band and one-time-password authenticators | ➖ | Not used | — |

## V3 Session management

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 3.1.1 | No session token in URLs | ✅ | The session is a cookie only | `Users/AuthTest::testTheSessionCookieIsSecureHttpOnlySameSiteLaxAndExpiresAfterEightIdleHours` |
| 3.2.1 | A new session token at sign-in | ✅ | Symfony's session migration on login; a planted id is replaced | `Users/AuthTest::testLoginReplacesASessionIdPlantedBeforehand` |
| 3.2.2 | At least 64 bits of entropy | ✅ | PHP's session ids: 32 hexadecimal characters, 128 bits | Prod profile (the `Set-Cookie` of a login) |
| 3.2.3 | Tokens kept in the browser in a safe way | ✅ | An `HttpOnly` cookie; nothing in `localStorage` but the theme and the shortcut setting | `Users/AuthTest::testTheSessionCookieIsSecureHttpOnlySameSiteLaxAndExpiresAfterEightIdleHours` |
| 3.3.1 | Logout and expiry end the session | ✅ | Logout invalidates the session on the server; 8 hours idle ends it | `Users/AuthTest::testLogoutEndsTheSession`, `testTheSessionCookieIsSecureHttpOnlySameSiteLaxAndExpiresAfterEightIdleHours` (8 h) |
| 3.3.2 | Re-authentication at least every 30 days, also when in use | ⏳ | Sessions end after 8 idle hours, but a session in constant use has no absolute limit yet. Planned: end sessions 30 days after sign-in. | — |
| 3.4.1 | `Secure` cookie | ✅ | `cookie_secure: true` | `Users/AuthTest::testTheSessionCookieIsSecureHttpOnlySameSiteLaxAndExpiresAfterEightIdleHours`; `e2e/tests/mvp.spec.js` |
| 3.4.2 | `HttpOnly` cookie | ✅ | `cookie_httponly: true` | The same tests |
| 3.4.3 | `SameSite` cookie | ✅ | `SameSite=Lax`, with the CSRF checks of 4.2.2 | The same tests |
| 3.4.4 | `__Host-` prefix | ✅ | The cookie is `__Host-scorewright`, with `Path=/` and no `Domain`. Chromium and Firefox accept it on `http://localhost`, a secure context, so development works there; WebKit (Safari) keeps no `Secure` cookie over plain HTTP, prefix or not (README). | `Users/AuthTest::testTheSessionCookieIsSecureHttpOnlySameSiteLaxAndExpiresAfterEightIdleHours` (name, path, no domain); `e2e/tests/mvp.spec.js` (the cookie Chromium keeps) |
| 3.4.5 | `Path` as narrow as possible when the host is shared | ✅ | `Path=/`: Scorewright owns its host name (the `__Host-` prefix demands it) | As 3.4.4 |
| 3.7.1 | A valid session before sensitive actions | ✅ | Every route but health, register and login needs a signed-in user; every write also needs the right role | `AuthorizationMatrixTest::testEveryPathButThePublicOnesNeedsASignedInUser`, `testRouteAnswersTheExpectedStatusForEachActor` |

## V4 Access control

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 4.1.1 | Access control enforced on the server | ✅ | `ProjectVoter`, `EvaluationVoter` and the project membership listener; the SPA only hides what the API refuses anyway | `AuthorizationMatrixTest` (every route × anonymous, outsider, viewer, evaluator, owner, and each evaluation state) |
| 4.1.2 | The attributes it uses cannot be changed by the user | ✅ | The role comes from the membership row, the user from the session; request bodies carry neither | `AuthorizationMatrixTest`; `Projects/MemberTest::testRemovingAMemberEndsTheirAccess` |
| 4.1.3 | Least privilege | ✅ | Three roles (architecture §6); evaluators never see a blind evaluation's results while it is open | `Evaluations/EvaluationVoterTest`; `Analytics/ResultsTest::testAnEvaluatorReadsTheResultsOfABlindEvaluationOnlyOnceItIsClosed` |
| 4.1.5 | Access control fails securely | ✅ | Deny by default (`access_control` ends with `^/`); an error answers Problem Details without data | `AuthorizationMatrixTest::testEveryRouteIsInTheMatrix`, `testEveryPathButThePublicOnesNeedsASignedInUser` |
| 4.2.1 | No insecure direct object references | ✅ | Every lookup is scoped to the project (`findInProject`); another project's object answers the same `404` as a missing one | `…Test::test…OfAnotherProjectLooksExactlyLikeOneThatDoesNotExist` (projects, datasets, runs, rubrics, evaluations) |
| 4.2.2 | Anti-CSRF | ✅ | The `csrf-token` header on every unsafe request, plus the same-origin check of `Origin`, `Referer` and `Sec-Fetch-Site`, and `Origin: null` refused (`App\Http\CsrfListener`) | `Users/AuthTest`: `testUnsafeRequestsWithoutTheCsrfHeaderAreRejected`, `testCrossSiteRequestsAreRejectedEvenWithTheCsrfHeader`, `testSameOriginRequestsWithTheCsrfHeaderPass`; `frontend/src/api/client.test.js` |
| 4.3.1 | Administrative interfaces use MFA | ➖ | There is no administrative interface; project owners manage only their projects | — |
| 4.3.2 | No directory listing or metadata files | ✅ | Caddy's `file_server` without `browse`; in production it serves only the built frontend (`/app/spa`), and every `/api` path goes to Symfony | `Http/HttpTest::testUnknownPathsAnswerProblemDetailsNeverADebugPage` (`/.env`, `/composer.json`); prod profile |

## V5 Validation, sanitization and encoding

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 5.1.1 | HTTP parameter pollution | ✅ | Bodies are JSON or multipart mapped to typed DTOs; query strings to typed objects (`PageQuery`), so a repeated or array parameter is a type error | `AuthorizationMatrixTest::testRoutesRefuseABodyOfTheWrongType`; `Users/AuthTest::testRegisterRejectsInvalidBodiesFieldByField` |
| 5.1.2 | Mass assignment | ✅ | DTOs per request; unknown fields answer `422 unknown_field` (`allow_extra_attributes: false`) | `Datasets/DatasetTest` (`unknown_field`), `Users/AuthTest::testRegisterRejectsInvalidBodiesFieldByField` |
| 5.1.3 | All input validated, allow-lists | ✅ | Symfony Validator on every DTO; enums for kinds, roles and check types; the import format field by field | The validation tests of each module; `Datasets/CaseFileReaderTest` |
| 5.1.4 | Structured data typed and validated | ✅ | As 5.1.3, with lengths, ranges and formats; text fields refuse control characters | `Projects/ProjectTest::testNamesAndDescriptionsRejectControlCharacters`, `Datasets/RunFileReaderTest::testControlCharactersAreRejectedButLineBreaksAndTabsAreFine` |
| 5.1.5 | Redirects only to allowed places | ✅ | The server never redirects. The SPA's `?next=` after sign-in goes only to a path on the same site (`safeNext`). | `frontend/src/api/client.test.js` ("only goes back to a path on this site") |
| 5.2.1 | HTML from editors sanitized | ➖ | No input is HTML; every text is shown as text | — |
| 5.2.2 | Unstructured data sanitized | ✅ | Lengths, control characters and UTF-8 checked; answers kept as given and only ever shown as text | `Datasets/CaseFileReaderTest::testTooLongAtOneCharacterOverEachLimit` |
| 5.2.3 | Input to mail systems | ➖ | The app sends no email | — |
| 5.2.4 | No `eval()` or dynamic code | ✅ | None in PHP or JavaScript; the regex check runs user patterns with PCRE's backtrack limit and a time budget | `Evaluations/ChecksTest::testCatastrophicBacktrackingIsARegexErrorInMilliseconds` (the limit); `Evaluations/CheckJobTest::testAJobPastItsTimeBudgetLeavesTheRestAsCheckErrors`, `Evaluations/CheckJobTest::testACriterionPastItsTimeBudgetStopsForTheItemsLeft` (the budget) |
| 5.2.5 | Template injection | ➖ | No server-side templates: the API returns JSON | — |
| 5.2.6 | SSRF | ➖ | The app makes no outgoing requests | — |
| 5.2.7 | SVG content | ➖ | No SVG uploads; the icons are part of the build | — |
| 5.2.8 | Markdown, CSS or similar content | ➖ | None is accepted | — |
| 5.3.1 | Output encoding for the context | ✅ | JSON from `json_encode`; React escapes text; CSV per RFC 4180 | `Analytics/CsvExportTest::testQuotesCommasAndLineBreaksAreQuotedAsRfc4180Says` |
| 5.3.2 | Character set kept | ✅ | UTF-8 everywhere; `<bdi>` around user text so a bidi control cannot reorder the page | `frontend/src/routes/datasets.test.jsx` ("isolates what people typed…") |
| 5.3.3 | Escaping against XSS | ✅ | React, with no `dangerouslySetInnerHTML`; answers in `pre-wrap` text; a CSP without `unsafe-inline` | `frontend/src/routes/datasets.test.jsx` ("shows the cases as text, never as HTML"), `compare.test.jsx` |
| 5.3.4 | Parameterized queries | ✅ | Doctrine with bound parameters; the few raw SQL queries bind too | Every functional test runs them; static review |
| 5.3.5 | Encoding where parameters are not possible | ✅ | `ALTER ROLE … PASSWORD` takes no parameter: `DatabaseRoleCommand` quotes the identifier and the literal | `Command/DatabaseRoleCommandTest` (a password with `@` and `'`) |
| 5.3.6 | JSON injection | ✅ | JSON is only built with `json_encode` and the serializer | — (no string-built JSON) |
| 5.3.7–5.3.10 | LDAP, OS command, file inclusion, XPath and XML injection | ➖ | None of these is used: no LDAP, no shell, no file path from users, no XML | — |
| 5.5.1 | Serialized objects protected | ➖ | No serialized object reaches a client. Messenger's messages are serialized in the database, which only the app reaches. | — |
| 5.5.2 | XML parsers restricted | ➖ | No XML | — |
| 5.5.3 | No deserialization of untrusted data | ✅ | User input is JSON or CSV, decoded into arrays and DTOs, never `unserialize()` | — |
| 5.5.4 | `JSON.parse`, never `eval` | ✅ | `response.json()` in the API client | `frontend/src/api/client.test.js` |

## V6 Stored cryptography

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 6.2.1 | Cryptographic modules fail securely (no padding oracles) | ➖ | The app encrypts nothing itself: passwords are hashed by PHP (`password_hash`), sessions are random ids, and TLS is Caddy's or the proxy's | — |

## V7 Error handling and logging

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 7.1.1 | No credentials or session tokens in logs | ✅ | Nothing logs a request body or a cookie; Symfony logs the exception class, message and route | `Users/AuthTest::testTheLogHasNoPasswordAndNoSessionId` (a failed and a successful login, then a signed-in request: neither the password nor the session id is in the log, which in the tests keeps every level) |
| 7.1.2 | No other sensitive data in logs | ✅ | Cases, answers, comments and import excerpts never go into a log message | The same test |
| 7.4.1 | A generic message on unexpected errors | ✅ | `500 internal_error` with a fixed title; `503 database_unavailable` when the database cannot be reached; never a stack trace, also in development | `Http/HttpTest::testUnknownPathsAnswerProblemDetailsNeverADebugPage`, `Http/ProblemDetailsListenerTest`, `Controller/HealthControllerTest` |

## V8 Data protection

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 8.2.1 | No caching of sensitive data | ✅ | `Cache-Control: no-store` on every API response | `Http/HttpTest::testResponsesAreNeverStored` |
| 8.2.2 | Nothing sensitive in browser storage | ✅ | `localStorage` holds the theme and the scoring-shortcut setting only | `frontend/src/components/layout.jsx`, `routes/scoring.jsx` (the two uses) |
| 8.2.3 | Client data cleared when the session ends | ✅ | Nothing to clear (8.2.2); a `401` sends the SPA to Sign in | `frontend/src/api/client.test.js` ("answers a 401 with a redirect to Sign in…") |
| 8.3.1 | Sensitive data in bodies or headers, never in the URL | ✅ | Passwords and answers only travel in request bodies; ids and filters in URLs | `AuthorizationMatrixTest` (the routes) |
| 8.3.2 | Users can export or remove their data | ⏳ | Owners export an evaluation as CSV and delete datasets, runs, rubrics and evaluations; a user cannot yet delete their own account (an operator can). | `Analytics/ResultsTest::testTheExportHasEveryAnswerWithItsScoresAsCsv` |
| 8.3.3 | Clear terms for personal data, with consent | ⏳ | Left to the operator of each installation: the app has no privacy notice of its own yet | — |
| 8.3.4 | Sensitive data identified, with a policy | ✅ | Personal data: email and display name. The datasets, answers and comments may hold anything a team imports, so they are visible to project members only, never logged, and deleted with their project's data. | This page; architecture §9 |

## V9 Communication

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 9.1.1 | TLS for every connection, no fallback to plain HTTP | ✅ | In production, either Caddy terminates TLS (`SERVER_NAME` set: a certificate, port 80 redirects to HTTPS, HSTS) or a proxy in front does (README). Development is plain HTTP on `127.0.0.1` only. | Prod profile with `SERVER_NAME` (redirect and HSTS) |
| 9.1.2 | Only strong cipher suites | ✅ | Caddy's defaults (TLS 1.2 and 1.3, AEAD suites); behind a proxy, the proxy's | Caddy's documented defaults; the prod profile negotiated TLS 1.3 with `TLS_AES_128_GCM_SHA256` |
| 9.1.3 | Only TLS 1.2 and 1.3 | ✅ | Caddy's default minimum is TLS 1.2 | Caddy's documented default (not tested here) |

## V10 Malicious code

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 10.3.1 | Auto-updates signed and over a secure channel | ➖ | The app does not update itself | — |
| 10.3.2 | Integrity of code; nothing loaded from untrusted sources | ✅ | Every script, style and font is part of the build and served from the same origin (CSP `default-src 'self'`); dependencies are pinned by lock files, the CI action by commit SHA, the Playwright image by digest | Prod profile (the CSP of `/`); `.github/workflows/ci.yml` |
| 10.3.3 | Protection from subdomain takeover | ➖ | The DNS of an installation is its operator's | — |

## V11 Business logic

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 11.1.1 | Business flows in order | ✅ | Closing is final; a rubric in use is locked; a check job runs once at a time per evaluation | `Evaluations/CheckJobTest::testAnEvaluationClosesOnlyOnceItsChecksAreNoLongerActive`, the `rubric_locked` and `evaluation_closed` tests |
| 11.1.2 | Limits at a human pace | ✅ | Rate limits on login, registration, invitations and new projects | `Users/AuthTest`, `Projects/MemberTest::testInvitesAreLimitedTo20PerHourForTheInvitingUserAcrossProjects`, `Projects/ProjectTest::testProjectCreationIsLimitedTo30PerHourForAUser` |
| 11.1.3 | Limits per user for business actions | ✅ | As 11.1.2, plus 5 runs per evaluation, 5,000 cases and 5 MB per import | `Datasets/DatasetTest::testAFileOverTheLimitIsRefusedBeforeItIsRead` |
| 11.1.4 | Anti-automation against excessive calls | ✅ | The limits above, body limits (6 MiB, 8 MiB), list pages of at most 100, and time budgets on the checks. Reads and scoring have no rate limit: they need a member's session. | `Http/HttpTest::testABodyOverThePhpLimitIsRejectedWith413`, `Evaluations/CheckJobTest` (time budgets) |
| 11.1.5 | Limits against the likely threats | ✅ | Blind mode: no response pairs an answer with its run for an evaluator (architecture §5) | `Datasets/RunTest::testOnlyTheScoringRoutesAndTheCaseAndExportReturnAnAnswer`, `Evaluations/EvaluationTest::testABlindItemNeverSaysWhichRunItIsFrom` |

## V12 Files and resources

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 12.1.1 | No large files that fill the storage | ✅ | 5 MB per import file, 6 MiB per body in PHP, 8 MiB in Caddy | `Datasets/DatasetTest::testAFileOverTheLimitIsRefusedBeforeItIsRead`, `Http/HttpTest::testABodyOverThePhpLimitIsRejectedWith413` |
| 12.3.1–12.3.3, 12.3.5 | File names from users never reach the file system | ✅ | An uploaded file is read in memory and never written; its name is stored as text only | `Datasets/DatasetTest::testImportsNeedAMultipartRequestWithACsvOrJsonFile` |
| 12.3.4 | Reflective file download | ✅ | Every API response has `Content-Disposition: attachment; filename="api.json"` (the CSV export its own, `<evaluation>-results.csv`), and `nosniff` | `Http/HttpTest::testResponsesAreNeverStored`, `Analytics/ResultsTest::testTheExportHasEveryAnswerWithItsScoresAsCsv` |
| 12.4.1 | Untrusted files stored outside the web root | ✅ | Not stored as files at all ([ADR 0004](adr/0004-synchronous-all-or-nothing-imports.md)) | As 12.3.1 |
| 12.4.2 | Untrusted files scanned for malware | ➖ | Imports are parsed into rows of text and never stored or served as files; the export cannot carry spreadsheet formulas | `Analytics/CsvExportTest::testTextThatLooksLikeAFormulaGetsAQuoteButTheAppsNumbersStayNumbers` |
| 12.5.1 | Only the expected file types are served | ✅ | Production serves `/app/spa` (the build) and runs only `public/index.php`; no backup or source file is reachable | Prod profile; `Http/HttpTest::testUnknownPathsAnswerProblemDetailsNeverADebugPage` |
| 12.5.2 | Uploaded files never run as HTML or JavaScript | ➖ | Uploaded files are never served | — |
| 12.6.1 | Allow-list for outgoing requests | ➖ | The app makes none | — |

## V13 API and web service

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 13.1.1 | One encoding and parser everywhere | ✅ | UTF-8 JSON in and out; Symfony's serializer and validator for every body | `AuthorizationMatrixTest::testRoutesRefuseABodyOfTheWrongType` |
| 13.1.3 | No secrets in API URLs | ✅ | No keys or tokens in URLs; the session is a cookie | `ApiReferenceTest` (the documented routes) |
| 13.2.1 | Only the HTTP methods each route allows | ✅ | Every route declares its methods; any other answers `405` | `AuthorizationMatrixTest`; `ApiReferenceTest` |
| 13.2.2 | JSON validated before it is accepted | ✅ | DTO validation, unknown fields refused, a depth limit | The validation tests of each module |
| 13.2.3 | Cookie-based APIs protected from CSRF | ✅ | 4.2.2 | As 4.2.2 |
| 13.3.1 | SOAP schema validation | ➖ | No SOAP (and no GraphQL) | — |

## V14 Configuration

| # | Requirement (short) | | How | Test |
|---|---|---|---|---|
| 14.2.1 | Components up to date, checked at build time | ✅ | `composer audit` and `npm audit` in `./check.sh` and CI; Dependabot proposes updates weekly (`.github/dependabot.yml`) | `./check.sh` |
| 14.2.2 | No unneeded features, samples or docs in production | ✅ | The `prod` image has no dev dependencies, tests or Vite server; `app:demo` and `app:seed-performance` refuse to run in `prod` | `Analytics/DemoCommandTest::testItRefusesToRunOutsideDevAndTest`, `Analytics/SeedPerformanceCommandTest::testItRefusesToRunOutsideDevAndTest`; prod profile |
| 14.2.3 | Subresource integrity for assets on a CDN | ➖ | No CDN: every asset is served by the app | — |
| 14.3.2 | Debug modes off in production | ✅ | `APP_ENV=prod` in the image; `display_errors = Off` everywhere | Prod profile (`APP_ENV`, the ini values) |
| 14.3.3 | No version details in headers | ✅ | Caddy drops `Server`, on its own error responses too (`handle_errors`); `expose_php = Off`. **Known exception:** when Caddy serves HTTPS itself, its automatic `308` redirect from `http://` to `https://` (empty body) still carries `Server: FrankenPHP Caddy`, since it is answered before the site's directives. It names no version. | Prod profile (`prod-check.sh`: a missing asset); the `308` seen with `SERVER_NAME=localhost` (SWQ4b) |
| 14.4.1 | `Content-Type` with a safe character set on every response | ✅ | `application/json` and `application/problem+json` (UTF-8 by definition, RFC 8259), `text/html; charset=utf-8`, `text/csv; charset=utf-8` | Prod profile; the export test |
| 14.4.2 | API responses carry `Content-Disposition: attachment` | ✅ | `App\Http\NoStoreListener` | `Http/HttpTest::testResponsesAreNeverStored` |
| 14.4.3 | A Content Security Policy | ✅ | `default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'` on the frontend in production (`Caddyfile.prod`); the API's responses have one too | Prod profile; the e2e test runs the app under Vite, which sends none |
| 14.4.4 | `X-Content-Type-Options: nosniff` | ✅ | Caddy, on every response, its own errors included | Prod profile (`prod-check.sh`: a missing asset) |
| 14.4.5 | HSTS, with subdomains | ✅ | `Strict-Transport-Security: max-age=31536000; includeSubDomains` on every HTTPS response from Caddy; behind a TLS proxy, the proxy sends it (README) | Prod profile with `SERVER_NAME` |
| 14.4.6 | A `Referrer-Policy` | ✅ | `same-origin` | Prod profile |
| 14.4.7 | Not embeddable by other sites | ✅ | CSP `frame-ancestors 'none'` | Prod profile |
| 14.5.1 | Only the methods in use, including `OPTIONS` | ✅ | Any other method answers `405 method_not_allowed`; there is no CORS, so no preflight is answered. They are not alerted on. | `AuthorizationMatrixTest` |
| 14.5.2 | `Origin` never used for authentication or access control | ✅ | Access depends on the session and the membership. `Origin` only adds to the CSRF check, never grants anything. | `Users/AuthTest` (CSRF tests) |
| 14.5.3 | CORS allows a strict list, never `null` | ✅ | No CORS headers at all: the SPA and the API share one origin; `Origin: null` is refused | `Users/AuthTest::testCrossSiteRequestsAreRejectedEvenWithTheCsrfHeader` |

## Left for after the MVP

The ⏳ rows above, in the order they would be done:
1. **Account settings:** change the password with the current one (2.1.5, 2.1.6), delete the account (8.3.2), and a
   show-password button on the forms (2.1.12).
2. **Sessions:** end a session 30 days after sign-in, however active (3.3.2).
3. **Passwords:** refuse the 10,000 most common passwords from a local list (2.1.7), and a strength meter (2.1.8).
4. **Privacy notice** that an operator can fill in (8.3.3).

## How this was checked

- The tests listed above run in `./check.sh`, `./e2e.sh` and CI. `./prod-check.sh` (also in CI) builds and starts
  the production profile and checks its answers, the headers of Caddy's own errors and the refusal of empty or
  placeholder secrets.
- "Prod profile": `docker compose -f compose.prod.yaml --env-file .env.prod up -d --build --wait`, then `curl`
  against `http://127.0.0.1:8490`: the headers of `/`, an asset, `/api/*` and Caddy's errors (a missing asset, a
  `POST /`); `/api/unknown` (`404` Problem Details, never `index.html`); a `Host` outside `SYMFONY_TRUSTED_HOSTS`
  (`400` from the API); the session cookie of a login; and `/api/me`, login and health with the database stopped
  (`503 database_unavailable` with `Retry-After`, in 2 to 4 s). With `SERVER_NAME=localhost` and port 443 published:
  `http://` redirects to `https://`, and the HTTPS responses, errors included, carry HSTS.
