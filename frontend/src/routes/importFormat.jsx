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

/** The dataset file format (docs/api.md "Import format"), for the people who prepare the files. */
export function ImportFormat() {
  usePageTitle('CSV and JSON format');

  return (
    <div className="max-w-3xl">
      <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: 'CSV and JSON format' }]} />
      <h1>CSV and JSON format for datasets</h1>
      <p>
        A dataset file is a list of test cases, as a <strong>.csv</strong> or a <strong>.json</strong> file in UTF-8 (a byte order mark is fine), with at most{' '}
        <strong>{MAX_FILE_SIZE}</strong> and <strong>{n(LIMITS.maxCases)} cases</strong>. The import checks the whole file first: if anything is wrong, nothing is
        saved, and the page lists every problem with its line and how to fix it.
      </p>

      <h2 className="mt-8">Fields</h2>
      <div className="table-scroll" role="region" tabIndex={0} aria-label="Fields">
        <table className="min-w-[36rem]">
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
      <p className="mt-3">No value may contain control characters such as NUL or escape. Tabs and line breaks are fine in the input and the reference.</p>

      <h2 className="mt-8">Case ids</h2>
      <p>
        Give every case an <code>id</code>, or none. Ids must be unique in the file. Without ids, a case’s id is its line number minus one in CSV (the first case
        after the header is <code>1</code>) or its position in a JSON array (from <code>1</code>).
      </p>

      <h2 className="mt-8">CSV</h2>
      <ul className="mb-3 list-disc pl-6">
        <li>The first line names the columns, in any order: <code>input</code>, and any of <code>id</code>, <code>reference</code>, <code>tags</code> and <code>metadata</code>. Other columns are rejected.</li>
        <li>Separate values with commas, or with semicolons (as spreadsheets save CSV in many European languages).</li>
        <li>Put a value in double quotes when it contains the separator, a quote or a line break, and double every quote inside it.</li>
        <li>
          <code>tags</code>: comma-separated in one cell. <code>metadata</code>: a JSON object in one cell, or empty. An empty <code>reference</code> means none.
        </li>
        <li>Line numbers in error messages count the header as line 1, as a spreadsheet does; a quoted value over several lines counts as one line.</li>
      </ul>
      <pre className="example" tabIndex={0} aria-label="CSV example">
        {CSV_EXAMPLE}
      </pre>

      <h2 className="mt-8">JSON</h2>
      <p>
        An array of objects with the same fields. <code>id</code> is a string or a whole number, <code>tags</code> an array of strings and <code>metadata</code> an
        object. Errors are numbered by item, from 1.
      </p>
      <pre className="example" tabIndex={0} aria-label="JSON example">
        {JSON_EXAMPLE}
      </pre>
    </div>
  );
}
