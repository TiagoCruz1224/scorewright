import { useState } from 'react';
import { flushSync } from 'react-dom';
import { Form, Link, redirect, useActionData, useLoaderData, useNavigation, useRouteLoaderData } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ErrorSummary, Field, StatusMessage } from '../components/forms.jsx';
import { ImportFileField, ImportProgress, UploadProblem } from '../components/imports.jsx';
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

  return { body, fileName: file instanceof File ? file.name : '' };
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
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <h1 className="mb-0">Datasets</h1>
        {canWrite(project) && list.total > 0 && (
          <Link className="btn btn-primary" to="new">
            New dataset
          </Link>
        )}
      </div>
      <StatusMessage message={deleted ? 'The dataset was deleted.' : undefined} />

      {list.total === 0 &&
        (canWrite(project) ? (
          <div className="card max-w-xl">
            <h2>Import your first dataset</h2>
            <p>A dataset is a list of test cases: the inputs to send to the AI system, with optional reference answers and tags.</p>
            <Link className="btn btn-primary" to="new">
              New dataset
            </Link>
          </div>
        ) : (
          <p>{project.role === 'owner' ? 'No datasets.' : 'The owner imports datasets for this project.'}</p>
        ))}
      {list.total > 0 && (
        <ul className="grid gap-3">
          {list.items.map((dataset) => (
            <li key={dataset.id} className="card">
              <h2 className="mb-1 text-base">
                <Link className="inline-flex min-h-6 items-center" to={dataset.id}>
                  {dataset.name}
                </Link>
              </h2>
              {dataset.latestVersion && (
                <p className="muted mb-0 text-sm">
                  Latest version v{dataset.latestVersion.number}: {cases(dataset.latestVersion.caseCount)}, imported{' '}
                  <time dateTime={dataset.latestVersion.createdAt}>{formatDate(dataset.latestVersion.createdAt)}</time>
                </p>
              )}
            </li>
          ))}
        </ul>
      )}
      <Pager list={list} />
    </>
  );
}

export async function newDatasetAction({ params, request }) {
  const form = await request.formData();
  const { body, fileName } = uploadOf(form, { name: form.get('name') });

  return attempt(
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
    <div className="max-w-3xl">
      <Breadcrumb items={crumbs(project, { label: 'New dataset' })} />
      <h1>New dataset</h1>
      {canWrite(project) ? (
        <>
          <UploadProblem problem={result?.problem} fileName={result?.fileName} />
          <Form method="post" encType="multipart/form-data" noValidate className="max-w-xl">
            <Field name="name" label="Name" required autoComplete="off" hint="Up to 200 characters, unique in the project." problem={formProblem} />
            <ImportFileField problem={formProblem} />
            <button type="submit" className="btn btn-primary" disabled={submitting}>
              Check and import
            </button>
          </Form>
          <ImportProgress />
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
  usePageTitle(dataset.name, project.name);

  return (
    <>
      <Breadcrumb items={crumbs(project, { label: dataset.name })} />
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <h1 className="mb-0">{dataset.name}</h1>
        {canWrite(project) && (
          <Link className="btn btn-primary" to="import">
            Import new version
          </Link>
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

      <section className="mb-8">
        <h2 id="versions-heading">Versions</h2>
        <ul className="grid gap-1">
          {dataset.versions.map((candidate) => (
            <li key={candidate.id}>
              <Link className="inline-flex min-h-6 items-center" to={`?version=${candidate.id}`} aria-current={candidate.id === version.id ? 'true' : undefined}>
                v{candidate.number}
              </Link>
              : {cases(candidate.caseCount)}, from <span className="font-mono text-sm">{candidate.sourceFilename}</span>, imported{' '}
              <time dateTime={candidate.createdAt}>{formatDate(candidate.createdAt)}</time>
              {candidate.id === version.id && <strong> (shown below)</strong>}
            </li>
          ))}
        </ul>
      </section>

      <section className="mb-8">
        <h2 id="cases-heading" tabIndex={-1}>
          Cases of v{version.number}
        </h2>
        {/* A GET form: the filters go into the URL, and only the Update button applies them (WCAG 3.2.2). */}
        <Form method="get" role="search" aria-label="Filter the cases" className="flex flex-wrap items-end gap-x-3" key={`${version.id}\n${filters.tag}\n${filters.q}`}>
          <input type="hidden" name="version" value={version.id} />
          <Field idPrefix="filter" name="tag" label="Tag" defaultValue={filters.tag} hint="Exactly this tag." />
          <Field idPrefix="filter" name="q" label="Text" defaultValue={filters.q} hint="In the input, the reference or the case id." />
          <div className="mb-4 flex flex-wrap items-center gap-3">
            <button type="submit" className="btn">
              Update
            </button>
            {filtered && <Link to={`?version=${version.id}`}>Clear the filters</Link>}
          </div>
        </Form>
        <p>{filtered ? `${cases(list.total)} match the filters.` : `${cases(list.total)}.`}</p>
        {list.total > 0 && <CaseTable items={list.items} />}
        <Pager list={list} />
      </section>

      {canWrite(project) && <DeleteDataset key={result?.problem ? JSON.stringify(result.problem) : 'delete'} dataset={dataset} problem={result?.problem} />}
    </>
  );
}

/** The cases as text only (pre-wrap, never HTML), in a region that scrolls sideways on a narrow screen. */
export function CaseTable({ items }) {
  return (
    <div className="table-scroll" role="region" tabIndex={0} aria-labelledby="cases-heading">
      <table className="min-w-[40rem]">
        <thead>
          <tr>
            <th scope="col">Case</th>
            <th scope="col">Input</th>
            <th scope="col">Reference</th>
            <th scope="col">Tags</th>
          </tr>
        </thead>
        <tbody>
          {items.map((testCase) => (
            <tr key={testCase.id}>
              <th scope="row">
                <code>{testCase.caseKey}</code>
              </th>
              <td className="prewrap">{testCase.input}</td>
              <td className="prewrap">{testCase.reference ?? <span className="muted">None</span>}</td>
              <td>
                {testCase.tags.length > 0 ? (
                  <ul className="flex flex-wrap gap-1">
                    {testCase.tags.map((tag) => (
                      <li key={tag} className="badge">
                        {tag}
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

/** Delete, after a confirmation on the page (like removing a member). */
function DeleteDataset({ dataset, problem }) {
  const [confirming, setConfirming] = useState(false);
  const versions = dataset.versions.length;

  return (
    <section className="max-w-xl">
      <h2 id="delete-heading">Delete the dataset</h2>
      <ErrorSummary problem={problem} idPrefix="delete" />
      {confirming ? (
        <Form method="post" className="flex flex-wrap items-center gap-3">
          <input type="hidden" name="intent" value="delete" />
          <p className="mb-0">
            Delete “{dataset.name}” with {versions === 1 ? 'its version and cases' : `its ${versions} versions and all their cases`}? This cannot be undone.
          </p>
          <button type="submit" className="btn btn-danger" autoFocus>
            Delete
          </button>
          <button
            type="button"
            className="btn"
            onClick={() => {
              flushSync(() => setConfirming(false));
              document.getElementById('delete-dataset')?.focus();
            }}
          >
            Cancel
          </button>
        </Form>
      ) : (
        <button id="delete-dataset" type="button" className="btn btn-danger" onClick={() => setConfirming(true)}>
          Delete dataset
        </button>
      )}
    </section>
  );
}

export async function importVersionLoader({ params, request }) {
  return api(`/api/projects/${params.projectId}/datasets/${params.datasetId}`, { request });
}

export async function importVersionAction({ params, request }) {
  const { body, fileName } = uploadOf(await request.formData());

  return attempt(
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
    <div className="max-w-3xl">
      <Breadcrumb items={crumbs(project, { label: dataset.name, to: `/projects/${project.id}/datasets/${dataset.id}` }, { label: 'Import new version' })} />
      <h1>Import new version</h1>
      {canWrite(project) ? (
        <>
          <p>
            The file becomes version v{dataset.versions[0].number + 1} of “{dataset.name}”. Earlier versions do not change.
          </p>
          <UploadProblem problem={result?.problem} fileName={result?.fileName} unchangedVersion={dataset.versions[0].number} />
          <Form method="post" encType="multipart/form-data" noValidate className="max-w-xl">
            <ImportFileField problem={formProblem} />
            <button type="submit" className="btn btn-primary" disabled={submitting}>
              Check and import
            </button>
          </Form>
          <ImportProgress />
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
