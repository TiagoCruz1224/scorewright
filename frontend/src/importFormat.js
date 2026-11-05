/**
 * The dataset and run import formats, in one place for every screen that mentions them: the limits (the API's,
 * docs/api.md "Import format" and "Run files") and readable names for the import error codes.
 */
export const LIMITS = {
  maxFileBytes: 5 * 1024 * 1024,
  maxCases: 5000,
  idLength: 100,
  textLength: 20_000,
  tagsPerCase: 20,
  tagLength: 50,
  metadataBytes: 10_240,
  metadataDepth: 30,
  outputLength: 50_000,
};

export const MAX_FILE_SIZE = `${LIMITS.maxFileBytes / 1024 / 1024} MiB`;

/** "2 × required value missing": each code of a rejected import, as the summary counts it. */
export const IMPORT_ERROR_NAMES = {
  unsupported_file_type: 'not a .csv or .json file',
  empty_file: 'empty file',
  invalid_utf8: 'not UTF-8',
  invalid_csv: 'CSV quotes or columns',
  invalid_json: 'invalid JSON',
  missing_column: 'missing column',
  unknown_column: 'unknown column',
  duplicate_column: 'duplicate column',
  unknown_field: 'unknown field',
  invalid_type: 'wrong type',
  too_many_rows: 'too many cases',
  required: 'required value missing',
  too_long: 'text too long',
  duplicate_case_key: 'duplicate case id',
  unknown_case_key: 'unknown case id',
  too_many_tags: 'too many tags',
  tag_too_long: 'tag too long',
  invalid_metadata: 'metadata not a JSON object',
  metadata_too_large: 'metadata too large',
  control_character: 'control character',
};
