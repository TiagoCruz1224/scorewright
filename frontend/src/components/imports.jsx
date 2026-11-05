import { useEffect, useRef } from 'react';
import { Link, useNavigation } from 'react-router';
import { IMPORT_ERROR_NAMES, LIMITS, MAX_FILE_SIZE } from '../importFormat.js';
import { ErrorSummary, Field, StatusMessage } from './forms.jsx';

const count = (number, word) => `${number.toLocaleString('en')} ${word}${number === 1 ? '' : 's'}`;

/** The file field, with the limits and the format before it (UX §4.3, prevention), linked by aria-describedby. */
export function ImportFileField({ problem }) {
  return (
    <Field
      name="file"
      label="CSV or JSON file"
      type="file"
      accept=".csv,.json"
      problem={problem}
      hint={
        <>
          UTF-8, up to {MAX_FILE_SIZE} and {LIMITS.maxCases.toLocaleString('en')} cases. Columns: <code>input</code>, and optionally <code>id</code>,{' '}
          <code>reference</code>, <code>tags</code> and <code>metadata</code> (a JSON object). See the <Link to="/import-format">CSV and JSON format</Link>.
        </>
      }
    />
  );
}

/** While the file uploads and the API checks it (UX §3.2): a status message, read out by screen readers. */
export function ImportProgress() {
  const navigation = useNavigation();
  const file = navigation.state === 'submitting' ? navigation.formData?.get('file') : null;

  return <StatusMessage message={file ? `Checking ${file.name || 'the file'} and importing it…` : undefined} />;
}

/**
 * The problem of an import: the rejected-import summary and table, a file over the limit, or a form error.
 * `unchangedVersion` is the number of the dataset's latest version, if it has one.
 */
export function UploadProblem({ problem, fileName, unchangedVersion }) {
  if (problem?.code === 'import_rejected') {
    return <ImportRejected problem={problem} fileName={fileName} unchangedVersion={unchangedVersion} />;
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
export function ImportRejected({ problem, fileName, unchangedVersion }) {
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
        <h2 id="import-rejected-heading" ref={heading} tabIndex={-1}>
          Import rejected: nothing was saved
        </h2>
        <p>
          <strong className="font-mono">{fileName}</strong> has{' '}
          <strong>
            {count(problem.errorCount, 'error')} in {count(problem.affectedLines, where)}
          </strong>
          . Fix them and import the file again. {unchangedVersion ? `Version v${unchangedVersion} is unchanged.` : 'No dataset was created.'}
        </p>
        <ul className="mb-3 flex flex-wrap gap-2" aria-label="Errors by type">
          {Object.entries(problem.errorCountsByCode).map(([code, number]) => (
            <li key={code} className="badge text-danger">
              {number.toLocaleString('en')} × {IMPORT_ERROR_NAMES[code] ?? code}
            </li>
          ))}
        </ul>
        <div className="flex flex-wrap gap-2">
          <a
            className="btn btn-primary"
            href="#field-file"
            onClick={(event) => {
              event.preventDefault();
              document.getElementById('field-file')?.focus();
            }}
          >
            Choose another file
          </a>
          <Link className="btn" to="/import-format">
            CSV and JSON format
          </Link>
        </div>
      </section>

      <section className="mb-8">
        <h2 id="import-errors-heading">Errors by {where}</h2>
        <p className="muted">
          {byItem ? 'Items are numbered from 1, in the order of the array.' : 'Line numbers count the header as line 1, as in a spreadsheet.'}
        </p>
        <div className="table-scroll" role="region" tabIndex={0} aria-labelledby="import-errors-heading">
          <table>
            <thead>
              <tr>
                <th scope="col">{byItem ? 'Item' : 'Line'}</th>
                <th scope="col">Field</th>
                <th scope="col">Problem</th>
              </tr>
            </thead>
            <tbody>
              {problem.errors.map((error, index) => (
                <tr key={index}>
                  <th scope="row" className="num">
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
        {problem.truncated && <p className="mt-3">and {count(more, 'more error')} of the same kinds</p>}
      </section>
    </>
  );
}
