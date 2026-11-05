import { useEffect, useRef } from 'react';
import { Link, useNavigation } from 'react-router';
import { IMPORT_ERROR_NAMES, LIMITS, MAX_FILE_SIZE } from '../importFormat.js';
import { attempt, ErrorSummary, Field } from './forms.jsx';
import { Icon } from './icons.jsx';

const count = (number, word) => `${number.toLocaleString('en')} ${word}${number === 1 ? '' : 's'}`;

const DATASET_COLUMNS = (
  <>
    Columns: <code>input</code>, and optionally <code>id</code>, <code>reference</code>, <code>tags</code> and <code>metadata</code> (a JSON object).
  </>
);

/**
 * The file field, with the limits and the format before it (UX §4.3, prevention), linked by aria-describedby.
 * `rows` and `columns` describe the file: cases and the dataset columns unless given.
 */
export function ImportFileField({ problem, rows = 'cases', columns = DATASET_COLUMNS }) {
  return (
    <Field
      name="file"
      label="CSV or JSON file"
      type="file"
      accept=".csv,.json"
      controlClassName="input file"
      style={{ maxWidth: 480 }}
      problem={problem}
      hint={
        <>
          UTF-8, up to {MAX_FILE_SIZE} and {LIMITS.maxCases.toLocaleString('en')} {rows}. {columns} See the <Link to="/import-format">CSV and JSON format</Link>.
        </>
      }
    />
  );
}

/** The problem of a file over the import limit, as the API's 413 would say it. */
const TOO_LARGE = { status: 413, code: 'payload_too_large', title: 'Payload Too Large', errors: [] };

/** The upload did not get an answer from the API: the connection failed, or the proxy in front of it gave up. */
const NOT_SENT = {
  status: 0,
  code: 'upload_failed',
  title: `The file could not be sent. Check that it is at most ${MAX_FILE_SIZE} and that you are online, then try again.`,
  errors: [],
};

/**
 * Sends an import (`call`) and turns what can go wrong into a problem for the form (SWQ2-02), so the page keeps the
 * form and what was chosen (WCAG 3.3.7) instead of becoming an error page:
 * - a file over the limit is refused here, before it is uploaded;
 * - a network error, or a gateway error (502–504: a proxy may drop a large upload), says the file could not be sent;
 * - an API error is the API's problem, as attempt() gives it.
 */
export async function attemptUpload(file, call, extra = {}) {
  if (file instanceof File && file.size > LIMITS.maxFileBytes) {
    return { ...extra, problem: TOO_LARGE };
  }
  try {
    const result = await attempt(call, extra);

    return [502, 503, 504].includes(result?.problem?.status) ? { ...extra, problem: NOT_SENT } : result;
  } catch (error) {
    // fetch() rejects with a TypeError when the request cannot be made or the connection drops.
    if (error instanceof TypeError) {
      return { ...extra, problem: NOT_SENT };
    }
    throw error;
  }
}

/** While the file uploads and the API checks it (UX §3.2): a status message, read out by screen readers. */
export function ImportProgress() {
  const navigation = useNavigation();
  const file = navigation.state === 'submitting' ? navigation.formData?.get('file') : null;

  return (
    <p role="status" className={file ? 'small muted ml-auto inline-flex items-center gap-1.5' : 'sr-only'}>
      {file && <Icon name="clock" />}
      {file ? `Checking ${file.name || 'the file'} and importing it…` : ''}
    </p>
  );
}

/**
 * The problem of an import: the rejected-import summary and table, a file over the limit, or a form error.
 * `unchanged` says what stayed as it was ("Version v3 is unchanged.", "No run was created.").
 */
export function UploadProblem({ problem, fileName, unchanged }) {
  if (problem?.code === 'import_rejected') {
    return <ImportRejected problem={problem} fileName={fileName} unchanged={unchanged} />;
  }
  if (problem?.status === 413) {
    return <ErrorSummary problem={{ ...problem, title: `The file is larger than ${MAX_FILE_SIZE}, the limit for an import. Split it into several files.` }} />;
  }

  return <ErrorSummary problem={problem} />;
}

/**
 * Import rejected (UX §4.3): what happened and that nothing was saved, the counts by type, then every listed error
 * with how to fix it. The heading takes the focus, so it is read first after submitting.
 */
export function ImportRejected({ problem, fileName, unchanged }) {
  const heading = useRef(null);
  useEffect(() => {
    heading.current?.focus();
  }, [problem]);

  // The API numbers CSV errors by line and JSON errors by item.
  const byItem = problem.errors.some((error) => 'item' in error);
  const where = byItem ? 'item' : 'line';
  const more = problem.errorCount - problem.errors.length;

  return (
    <>
      <section className="alert" aria-labelledby="import-rejected-heading">
        <Icon name="alert" size="lg" />
        <div className="alert-body">
          <h2 id="import-rejected-heading" className="alert-title" ref={heading} tabIndex={-1}>
            Import rejected: nothing was saved
          </h2>
          <p>
            <code>
              <bdi>{fileName}</bdi>
            </code>{' '}
            has{' '}
            <strong>
              {count(problem.errorCount, 'error')} in {count(problem.affectedLines, where)}
            </strong>
            . Fix them and import the file again. {unchanged}
          </p>
          <ul className="counts list-none flex-row p-0" aria-label="Errors by type">
            {Object.entries(problem.errorCountsByCode).map(([code, number]) => (
              <li key={code} className="count-chip">
                <b>{number.toLocaleString('en')}</b> {IMPORT_ERROR_NAMES[code] ?? code}
              </li>
            ))}
          </ul>
          <div className="actions mt-1">
            <a
              className="btn btn-primary btn-sm"
              href="#field-file"
              onClick={(event) => {
                event.preventDefault();
                document.getElementById('field-file')?.focus();
              }}
            >
              Choose another file
            </a>
            <Link className="btn btn-sm" to="/import-format">
              CSV and JSON format
            </Link>
          </div>
        </div>
      </section>

      <section className="section">
        <div className="section-head">
          <h2 className="section-title" id="import-errors-heading">
            Errors by {where}
          </h2>
          <p className="section-hint">
            {byItem ? 'Items are numbered from 1, in the order of the array.' : 'Line numbers count the header as line 1, as in a spreadsheet.'}
          </p>
        </div>
        <div className="table-box" role="region" tabIndex={0} aria-labelledby="import-errors-heading">
          <table className="tbl" style={{ minWidth: 600 }}>
            <thead>
              <tr>
                <th scope="col" className="num" style={{ width: 72 }}>
                  {byItem ? 'Item' : 'Line'}
                </th>
                <th scope="col" style={{ width: 130 }}>
                  Field
                </th>
                <th scope="col">Problem</th>
              </tr>
            </thead>
            <tbody>
              {problem.errors.map((error, index) => (
                <tr key={index}>
                  <th scope="row" className="num mono">
                    {error[where] ?? 'File'}
                  </th>
                  <td>{error.field ? <code>{error.field}</code> : '—'}</td>
                  <td>
                    {error.message}
                    {error.excerpt && <span className="excerpt">{error.excerpt}</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {problem.truncated && <p className="panel-foot">and {count(more, 'more error')} of the same kinds</p>}
      </section>
    </>
  );
}
