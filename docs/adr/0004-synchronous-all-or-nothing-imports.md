# 0004. Imports validated synchronously, all or nothing, files not stored

Status: accepted (approved 2026-11-04)

## Context

Dataset and run imports must be rejected as a whole, with line-level errors, if any row is invalid. Uploaded files
must never be executed or served back. Limits are 5 MB and 5,000 rows (configurable). The UX shows the result of the
import on the same screen.

## Decision

- The request parses and validates the whole file **in memory** first:
  - a UTF-8 check;
  - CSV with a header row, or JSON with a depth limit;
  - required fields and the field limits in the architecture doc (§4);
  - the optional case `id`: unique in the file, otherwise generated from the row or item number;
  - duplicate and unknown case keys for runs.
- Only if there are no errors, all rows are inserted in **one transaction**, with batched inserts.
- Errors are listed up to 100. The total, the number of affected lines and the count per error code always cover the
  whole file. They are returned as Problem Details (`422 import_rejected`).
- The raw file is discarded after parsing. Only the SHA-256 and the original file name are kept on the dataset version.

## Consequences

- No partial data, no stored uploads, and no file-serving surface.
- The upload request takes as long as the parse: within the limits this should be a few seconds at most, which slice 4
  measures.
- If it is too slow, the same validator moves into a Messenger job behind an "import pending" state. The API contract
  (errors format) stays the same.
