import { Form, Link, redirect, useActionData, useLoaderData, useNavigation, useRouteLoaderData } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ConfirmDelete, Field, StatusMessage } from '../components/forms.jsx';
import { attemptUpload, ImportFileField, ImportProgress, UploadProblem } from '../components/imports.jsx';
import { Icon } from '../components/icons.jsx';
import { Breadcrumb, Pager, usePageTitle } from '../components/layout.jsx';

const dateFormat = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });
const formatDate = (iso) => dateFormat.format(new Date(iso));
const cases = (number) => `${number.toLocaleString('en')} ${number === 1 ? 'case' : 'cases'}`;

/** Owners write; nobody writes to an archived project (UX §1.5). */
const canWrite = (project) => project.role === 'owner' && !project.archivedAt;

const crumbs = (project, ...rest) => [
  { label: 'Projects', to: '/projects' },
  { label: project.name, to: `/projects/${project.id}` },
  { label: 'Datasets', to: `/projects/${project.id}/datasets` },
  ...rest,
];

/** The file of a form, or nothing when no file was chosen (the browser then sends an empty, nameless one). */
function uploadOf(form, fields = {}) {
  const body = new FormData();
  for (const [name, value] of Object.entries(fields)) {
    body.set(name, value ?? '');
  }
  const file = form.get('file');
  if (file instanceof File && file.name !== '') {
    body.set('file', file);
  }

  return { body, file: file instanceof File && file.name !== '' ? file : null, fileName: file instanceof File ? file.name : '' };
}

export async function datasetsLoader({ params, request }) {
  const url = new URL(request.url);
  const page = url.searchParams.get('page') ?? '1';

  return {
    list: await api(`/api/projects/${params.projectId}/datasets?page=${encodeURIComponent(page)}`, { request }),
    deleted: url.searchParams.has('deleted'),
  };
}

export function Datasets() {
  const { list, deleted } = useLoaderData();
  const project = useRouteLoaderData('project');
  usePageTitle('Datasets', project.name);

  return (
    <>
      <Breadcrumb items={crumbs(project).slice(0, 2).concat({ label: 'Datasets' })} />
      <div className="page-head">
        <div>
          <h1 className="page-title">Datasets</h1>
          <p className="page-meta">
            {list.total.toLocaleString('en')} {list.total === 1 ? 'dataset' : 'datasets'}
          </p>
        </div>
        {canWrite(project) && list.total > 0 && (
          <div className="actions">
            <Link className="btn btn-primary" to="new">
              <Icon name="plus" />
              New dataset
            </Link>
          </div>
        )}
      </div>
      <StatusMessage message={deleted ? 'The dataset was deleted.' : undefined} />

      {list.total === 0 &&
        (canWrite(project) ? (
          <div className="empty">
            <span className="empty-icon">
              <Icon name="file" size="lg" />
            </span>
            <h2 className="empty-title">Import your first dataset</h2>
            <p>A dataset is a list of test cases: the inputs to send to the AI system, with optional reference answers and tags.</p>
            <div className="actions">
              <Link className="btn btn-primary" to="new">
                <Icon name="plus" />
                New dataset
              </Link>
            </div>
          </div>
        ) : (
          <p>{project.role === 'owner' ? 'No datasets.' : 'The owner imports datasets for this project.'}</p>
        ))}
      {list.total > 0 && (
        <div className="table-box" role="region" aria-label="Datasets" tabIndex={0}>
          <table className="tbl" style={{ minWidth: 600 }}>
            <thead>
              <tr>
                <th scope="col">Dataset</th>
                <th scope="col">Latest version</th>
                <th scope="col" className="num">
                  Cases
                </th>
                <th scope="col">Imported</th>
              </tr>
            </thead>
            <tbody>
              {list.items.map((dataset) => (
                <tr key={dataset.id}>
                  <th scope="row">
                    <Link className="inline-flex min-h-6 items-center" to={dataset.id}>
                      <bdi>{dataset.name}</bdi>
                    </Link>
                  </th>
                  <td>{dataset.latestVersion && <span className="tag">v{dataset.latestVersion.number}</span>}</td>
                  <td className="num mono">{dataset.latestVersion?.caseCount.toLocaleString('en')}</td>
                  <td className="muted">{dataset.latestVersion && <time dateTime={dataset.latestVersion.createdAt}>{formatDate(dataset.latestVersion.createdAt)}</time>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pager list={list} noun="Datasets" />
    </>
  );
}

export async function newDatasetAction({ params, request }) {
  const form = await request.formData();
  const { body, file, fileName } = uploadOf(form, { name: form.get('name') });

  return attemptUpload(
    file,
    async () => {
      const dataset = await api(`/api/projects/${params.projectId}/datasets`, { method: 'POST', body, request });

      return redirect(`/projects/${params.projectId}/datasets/${dataset.id}?created=${dataset.versions[0].id}`);
    },
    { fileName },
  );
}

export function NewDataset() {
  const project = useRouteLoaderData('project');
  const result = useActionData();
  const submitting = useNavigation().state === 'submitting';
  // A rejected import's errors are about the file, not about these fields.
  const formProblem = result?.problem?.code === 'import_rejected' ? undefined : result?.problem;
  usePageTitle('New dataset', project.name);

  return (
    <div className="mx-auto max-w-[880px]">
      <Breadcrumb items={crumbs(project, { label: 'New dataset' })} />
      <div className="page-head">
        <div>
          <h1 className="page-title">New dataset</h1>
          {canWrite(project) && <p className="page-meta">The file becomes version v1. Nothing is saved unless every case is valid.</p>}
        </div>
      </div>
      {canWrite(project) ? (
        <>
          <UploadProblem problem={result?.problem} fileName={result?.fileName} unchanged="No dataset was created." />
          <Form method="post" encType="multipart/form-data" noValidate className="card section">
            <div className="card-body stack" style={{ gap: 20 }}>
              <Field name="name" label="Name" required autoComplete="off" hint="Up to 200 characters, unique in the project." className="max-w-[480px]" problem={formProblem} />
              <ImportFileField problem={formProblem} />
            </div>
            <div className="card-foot">
              <button type="submit" className="btn btn-primary" disabled={submitting}>
                <Icon name="upload" />
                Check and import
              </button>
              <Link className="btn btn-ghost" to={`/projects/${project.id}/datasets`}>
                Cancel
              </Link>
              <ImportProgress />
            </div>
          </Form>
        </>
      ) : (
        <p>Only the owners of an active project import datasets.</p>
      )}
    </div>
  );
}

/** The dataset, the version to show (?version=, else the latest) and its page of cases (?tag=, ?q=, ?page=). */
export async function datasetLoader({ params, request }) {
  const url = new URL(request.url);
  const dataset = await api(`/api/projects/${params.projectId}/datasets/${params.datasetId}`, { request });
  const version = url.searchParams.has('version') ? dataset.versions.find((candidate) => candidate.id === url.searchParams.get('version')) : dataset.versions[0];
  if (!version) {
    throw new Response('No such version', { status: 404 });
  }

  const filters = { tag: url.searchParams.get('tag') ?? '', q: url.searchParams.get('q') ?? '' };
  const query = new URLSearchParams({ page: url.searchParams.get('page') ?? '1' });
  for (const [name, value] of Object.entries(filters)) {
    if (value !== '') {
      query.set(name, value);
    }
  }

  return {
    dataset,
    version,
    filters,
    cases: await api(`/api/projects/${params.projectId}/dataset-versions/${version.id}/cases?${query}`, { request }),
    created: url.searchParams.get('created') === version.id,
  };
}

export async function datasetAction({ params, request }) {
  const form = await request.formData();
  if (form.get('intent') !== 'delete') {
    throw new Response('Unknown form', { status: 400 });
  }

  return attempt(async () => {
    await api(`/api/projects/${params.projectId}/datasets/${params.datasetId}`, { method: 'DELETE', request });

    return redirect(`/projects/${params.projectId}/datasets?deleted=1`);
  });
}

export function Dataset() {
  const { dataset, version, filters, cases: list, created } = useLoaderData();
  const project = useRouteLoaderData('project');
  const result = useActionData();
  const filtered = filters.tag !== '' || filters.q !== '';
  const latest = dataset.versions[0];
  usePageTitle(dataset.name, project.name);

  return (
    <>
      <Breadcrumb items={crumbs(project, { label: dataset.name })} />
      <div className="page-head">
        <div>
          <h1 className="page-title">
            <bdi>{dataset.name}</bdi>
          </h1>
          <p className="page-meta">
            <span>
              {dataset.versions.length} {dataset.versions.length === 1 ? 'version' : 'versions'}
            </span>
            <span className="dot">Latest v{latest.number}</span>
            <span className="dot">{cases(latest.caseCount)}</span>
          </p>
        </div>
        {canWrite(project) && (
          <div className="actions">
            <Link className="btn btn-primary" to="import">
              <Icon name="upload" />
              Import new version
            </Link>
          </div>
        )}
      </div>
      <StatusMessage
        message={
          created ? (
            <>
              Version v{version.number} created: {cases(version.caseCount)}.{' '}
              <a href="#cases-heading" onClick={focusTarget}>
                See its cases
              </a>
            </>
          ) : undefined
        }
      />

      <section>
        <div className="section-head">
          <h2 className="section-title" id="versions-heading">
            Versions
          </h2>
          <p className="section-hint">Each import is a new version. Versions never change.</p>
        </div>
        <div className="table-box" role="region" aria-labelledby="versions-heading" tabIndex={0}>
          <table className="tbl" style={{ minWidth: 560 }}>
            <thead>
              <tr>
                <th scope="col" style={{ width: 120 }}>
                  Version
                </th>
                <th scope="col" className="num" style={{ width: 110 }}>
                  Cases
                </th>
                <th scope="col">Source file</th>
                <th scope="col">Imported</th>
                <th scope="col">
                  <span className="sr-only">Shown</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {dataset.versions.map((candidate) => (
                <tr key={candidate.id} className={candidate.id === version.id ? 'is-current' : undefined}>
                  <th scope="row">
                    {/* At least 24 px tall (WCAG 2.5.8). */}
                    <Link className="inline-flex min-h-6 min-w-6 items-center" to={`?version=${candidate.id}`} aria-current={candidate.id === version.id ? 'true' : undefined}>
                      v{candidate.number}
                    </Link>
                  </th>
                  <td className="num mono">{candidate.caseCount.toLocaleString('en')}</td>
                  <td className="mono">
                    <bdi>{candidate.sourceFilename}</bdi>
                  </td>
                  <td className="muted">
                    <time dateTime={candidate.createdAt}>{formatDate(candidate.createdAt)}</time>
                  </td>
                  <td className="text-right">{candidate.id === version.id && <span className="tag">Shown below</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="section">
        <div className="section-head">
          <h2 className="section-title" id="cases-heading" tabIndex={-1}>
            Cases of v{version.number}
          </h2>
          <p className="section-hint" role="status">
            {filtered ? `${cases(list.total)} match the filters.` : `${cases(list.total)}.`}
          </p>
        </div>
        {/* A GET form: the filters go into the URL, and only the Update button applies them (WCAG 3.2.2). */}
        <Form method="get" role="search" aria-label="Filter the cases" className="panel-bar" key={`${version.id}\n${filters.tag}\n${filters.q}`}>
          <input type="hidden" name="version" value={version.id} />
          <Field idPrefix="filter" name="tag" label="Tag" defaultValue={filters.tag} hint="Exactly this tag." className="w-[200px]" />
          <Field idPrefix="filter" name="q" label="Text" defaultValue={filters.q} hint="In the input, the reference or the case id." className="flex-[1_1_260px]" icon="search" />
          <div className="actions pb-6">
            <button type="submit" className="btn">
              Update
            </button>
            {filtered && (
              <Link className="link-btn" to={`?version=${version.id}`}>
                Clear the filters
              </Link>
            )}
          </div>
        </Form>
        <CaseTable items={list.items} />
        <Pager list={list} noun="Cases" />
      </section>

      {canWrite(project) && (
        <ConfirmDelete
          key={result?.problem ? JSON.stringify(result.problem) : 'delete'}
          heading="Delete the dataset"
          button="Delete dataset"
          note="Removes it with its versions, their cases and runs. Possible only while no evaluation uses it."
          question={
            <>
              Delete “<bdi>{dataset.name}</bdi>” with{' '}
              {dataset.versions.length === 1 ? 'its version, cases and runs' : `its ${dataset.versions.length} versions and all their cases and runs`}? This cannot
              be undone.
            </>
          }
          problem={result?.problem}
        />
      )}
    </>
  );
}

/** The cases as text only (pre-wrap, never HTML), in a region that scrolls sideways on a narrow screen. */
export function CaseTable({ items }) {
  return (
    <div className="table-box" role="region" tabIndex={0} aria-labelledby="cases-heading">
      <table className="tbl" style={{ minWidth: 640 }}>
        <thead>
          <tr>
            <th scope="col" style={{ width: 110 }}>
              Case
            </th>
            <th scope="col">Input</th>
            <th scope="col">Reference</th>
            <th scope="col" style={{ width: 190 }}>
              Tags
            </th>
          </tr>
        </thead>
        <tbody>
          {items.length === 0 && (
            <tr>
              <td colSpan={4} className="muted">
                No cases.
              </td>
            </tr>
          )}
          {items.map((testCase) => (
            <tr key={testCase.id}>
              <th scope="row">
                <code>{testCase.caseKey}</code>
              </th>
              <td className="prewrap">{testCase.input}</td>
              <td className="prewrap">{testCase.reference ?? <span className="muted">None</span>}</td>
              <td>
                {testCase.tags.length > 0 ? (
                  <ul className="tags">
                    {testCase.tags.map((tag) => (
                      <li key={tag} className="tag">
                        <bdi>{tag}</bdi>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <span className="muted">None</span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export async function importVersionLoader({ params, request }) {
  return api(`/api/projects/${params.projectId}/datasets/${params.datasetId}`, { request });
}

export async function importVersionAction({ params, request }) {
  const { body, file, fileName } = uploadOf(await request.formData());

  return attemptUpload(
    file,
    async () => {
      const version = await api(`/api/projects/${params.projectId}/datasets/${params.datasetId}/versions`, { method: 'POST', body, request });

      return redirect(`/projects/${params.projectId}/datasets/${params.datasetId}?version=${version.id}&created=${version.id}`);
    },
    { fileName },
  );
}

export function ImportVersion() {
  const dataset = useLoaderData();
  const project = useRouteLoaderData('project');
  const result = useActionData();
  const submitting = useNavigation().state === 'submitting';
  const formProblem = result?.problem?.code === 'import_rejected' ? undefined : result?.problem;
  usePageTitle('Import new version', dataset.name, project.name);

  return (
    <div className="mx-auto max-w-[960px]">
      <Breadcrumb items={crumbs(project, { label: dataset.name, to: `/projects/${project.id}/datasets/${dataset.id}` }, { label: 'Import new version' })} />
      <div className="page-head">
        <div>
          <h1 className="page-title">Import new version</h1>
          {canWrite(project) && (
            <p className="page-meta">
              <span>
                The file becomes version v{dataset.versions[0].number + 1} of “<bdi>{dataset.name}</bdi>”. Earlier versions do not change.
              </span>
            </p>
          )}
        </div>
      </div>
      {canWrite(project) ? (
        <>
          <UploadProblem problem={result?.problem} fileName={result?.fileName} unchanged={`Version v${dataset.versions[0].number} is unchanged.`} />
          <Form method="post" encType="multipart/form-data" noValidate className="card section">
            {result?.problem?.code === 'import_rejected' && (
              <div className="card-head">
                <h2 className="card-title">Try another file</h2>
              </div>
            )}
            <div className="card-body">
              <ImportFileField problem={formProblem} />
            </div>
            <div className="card-foot">
              <button type="submit" className="btn btn-primary" disabled={submitting}>
                <Icon name="upload" />
                Check and import
              </button>
              <ImportProgress />
            </div>
          </Form>
        </>
      ) : (
        <p>Only the owners of an active project import datasets.</p>
      )}
    </div>
  );
}

function focusTarget(event) {
  const target = document.getElementById(event.currentTarget.getAttribute('href').slice(1));
  if (target) {
    event.preventDefault();
    target.focus();
  }
}
