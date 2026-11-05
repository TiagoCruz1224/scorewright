import { Breadcrumb, usePageTitle } from '../components/layout.jsx';
import { LIMITS, MAX_FILE_SIZE } from '../importFormat.js';

const n = (number) => number.toLocaleString('en');

const CSV_EXAMPLE = `id,input,reference,tags,metadata
greeting,Say hello,Hello!,"smalltalk, short","{""lang"": ""en""}"
refund-1,"I want a refund.
My order is late.",,billing,`;

const JSON_EXAMPLE = `[
  { "id": "greeting", "input": "Say hello", "reference": "Hello!", "tags": ["smalltalk", "short"], "metadata": { "lang": "en" } },
  { "id": "refund-1", "input": "I want a refund.\\nMy order is late.", "tags": ["billing"] }
]`;

const RUN_CSV_EXAMPLE = `id,output
greeting,Hello! How can I help?
refund-1,"Sorry about the delay.
Your refund is on its way."`;

const RUN_JSON_EXAMPLE = `[
  { "id": "greeting", "output": "Hello! How can I help?" },
  { "id": "refund-1", "output": "" }
]`;

/** The dataset and run file formats (docs/api.md "Import format", "Run files"), for the people who prepare them. */
export function ImportFormat() {
  usePageTitle('CSV and JSON format');

  return (
    <div className="mx-auto max-w-[880px]">
      <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: 'CSV and JSON format' }]} />
      <div className="page-head">
        <h1 className="page-title">CSV and JSON format for datasets</h1>
      </div>
      <p className="mb-3">
        A dataset file is a list of test cases, as a <strong>.csv</strong> or a <strong>.json</strong> file in UTF-8 (a byte order mark is fine), with at most{' '}
        <strong>{MAX_FILE_SIZE}</strong> and <strong>{n(LIMITS.maxCases)} cases</strong>. The import checks the whole file first: if anything is wrong, nothing is
        saved, and the page lists every problem with its line and how to fix it.
      </p>

      <h2 className="section-title mt-9 mb-3">Fields</h2>
      <div className="table-box" role="region" tabIndex={0} aria-label="Fields">
        <table className="tbl" style={{ minWidth: 560 }}>
          <thead>
            <tr>
              <th scope="col">Field</th>
              <th scope="col">What it is</th>
              <th scope="col">Limit</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <th scope="row">
                <code>input</code>
              </th>
              <td>Required. The prompt to send to the AI system.</td>
              <td>{n(LIMITS.textLength)} characters; line breaks and tabs are fine</td>
            </tr>
            <tr>
              <th scope="row">
                <code>id</code>
              </th>
              <td>Optional. The case id that runs refer to.</td>
              <td>{n(LIMITS.idLength)} characters on one line, unique in the file</td>
            </tr>
            <tr>
              <th scope="row">
                <code>reference</code>
              </th>
              <td>Optional. The expected or ideal answer.</td>
              <td>{n(LIMITS.textLength)} characters; line breaks and tabs are fine</td>
            </tr>
            <tr>
              <th scope="row">
                <code>tags</code>
              </th>
              <td>Optional. Labels to filter and group cases by; repeated tags count once.</td>
              <td>
                {LIMITS.tagsPerCase} per case, {LIMITS.tagLength} characters each
              </td>
            </tr>
            <tr>
              <th scope="row">
                <code>metadata</code>
              </th>
              <td>Optional. Anything else about the case, as a JSON object.</td>
              <td>
                {n(LIMITS.metadataBytes)} bytes as JSON, {LIMITS.metadataDepth} levels deep
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p className="mt-3 mb-3">No value may contain control characters such as NUL or escape. Tabs and line breaks are fine in the input and the reference.</p>

      <h2 className="section-title mt-9 mb-3">Case ids</h2>
      <p className="mb-3">
        Give every case an <code>id</code>, or none. Ids must be unique in the file. Without ids, a case’s id is its line number minus one in CSV (the first case
        after the header is <code>1</code>) or its position in a JSON array (from <code>1</code>).
      </p>

      <h2 className="section-title mt-9 mb-3">CSV</h2>
      <ul className="mb-3 list-disc pl-6">
        <li>The first line names the columns, in any order: <code>input</code>, and any of <code>id</code>, <code>reference</code>, <code>tags</code> and <code>metadata</code>. Other columns are rejected.</li>
        <li>Separate values with commas, or with semicolons (as spreadsheets save CSV in many European languages).</li>
        <li>Put a value in double quotes when it contains the separator, a quote or a line break, and double every quote inside it.</li>
        <li>
          <code>tags</code>: comma-separated in one cell. <code>metadata</code>: a JSON object in one cell, or empty. An empty <code>reference</code> means none.
        </li>
        <li>Line numbers in error messages count the header as line 1, as a spreadsheet does; a quoted value over several lines counts as one line.</li>
      </ul>
      <pre className="example mb-3" tabIndex={0} aria-label="CSV example">
        {CSV_EXAMPLE}
      </pre>

      <h2 className="section-title mt-9 mb-3">JSON</h2>
      <p className="mb-3">
        An array of objects with the same fields. <code>id</code> is a string or a whole number, <code>tags</code> an array of strings and <code>metadata</code> an
        object. Errors are numbered by item, from 1.
      </p>
      <ul className="mb-3 list-disc pl-6">
        <li>
          A key repeated in one object: <strong>the last one wins</strong>, as in a browser. <code>{'{"input": "a", "input": "b"}'}</code> imports <code>b</code>, in{' '}
          <code>metadata</code> too.
        </li>
        <li>
          Numbers in <code>metadata</code> are read as 64-bit floating point: one with more digits than that holds is rounded, and one too large to hold,
          such as <code>1e999</code>, is an error. Put a number in quotes to keep it exactly as text.
        </li>
      </ul>
      <pre className="example mb-3" tabIndex={0} aria-label="JSON example">
        {JSON_EXAMPLE}
      </pre>

      <h2 className="section-title mt-12 mb-3" id="run-files">
        Run files
      </h2>
      <p className="mb-3">
        A run file holds one model’s answers to the cases of a dataset version: one row per answer, with the same rules as a dataset file (UTF-8, .csv or .json,
        at most {MAX_FILE_SIZE} and {n(LIMITS.maxCases)} answers, checked as a whole).
      </p>
      <ul className="mb-3 list-disc pl-6">
        <li>
          <code>id</code> (required): the id of the case this answers, as in the dataset version. An id the version does not have, or a second answer to the same
          case, is an error.
        </li>
        <li>
          <code>output</code> (required): the answer, up to {n(LIMITS.outputLength)} characters; line breaks and tabs are fine. It may be empty: a model that said
          nothing still gave an answer to score.
        </li>
        <li>A run need not answer every case. Its page shows the coverage, such as “198 of 200 answered”, and unanswered cases are not scored.</li>
      </ul>
      <pre className="example mb-3" tabIndex={0} aria-label="Run CSV example">
        {RUN_CSV_EXAMPLE}
      </pre>
      <pre className="example mb-3" tabIndex={0} aria-label="Run JSON example">
        {RUN_JSON_EXAMPLE}
      </pre>
    </div>
  );
}
