import { Form, Link, redirect, useActionData, useLoaderData, useNavigation, useRouteLoaderData } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ConfirmDelete, Field, StatusMessage } from '../components/forms.jsx';
import { attemptUpload, ImportFileField, ImportProgress, UploadProblem } from '../components/imports.jsx';
import { Breadcrumb, Pager, usePageTitle } from '../components/layout.jsx';

const dateFormat = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });
const formatDate = (iso) => dateFormat.format(new Date(iso));
const n = (number) => number.toLocaleString('en');
const answers = (number) => `${n(number)} ${number === 1 ? 'answer' : 'answers'}`;

/** Owners write; nobody writes to an archived project (UX §1.5). */
const canWrite = (project) => project.role === 'owner' && !project.archivedAt;

const crumbs = (project, ...rest) => [
  { label: 'Projects', to: '/projects' },
  { label: project.name, to: `/projects/${project.id}` },
  { label: 'Runs', to: `/projects/${project.id}/runs` },
  ...rest,
];

/** "198 of 200 answered": a run may leave cases unanswered (architecture §12, decision 2). */
export const coverage = (run) => `${n(run.answerCount)} of ${n(run.datasetVersion.caseCount)} answered`;

const versionName = (version) => `${version.dataset.name} v${version.number}`;

const RUN_COLUMNS = (
  <>
    Columns: <code>id</code> (the case id of the version) and <code>output</code> (the answer; it may be empty).
  </>
);

/** Every dataset version of the project, to choose one: the API's first 100, plenty for the MVP. */
async function projectVersions(params, request) {
  return (await api(`/api/projects/${params.projectId}/dataset-versions?perPage=100`, { request })).items;
}

export async function runsLoader({ params, request }) {
  const url = new URL(request.url);
  const versionId = url.searchParams.get('datasetVersion') ?? '';
  const query = new URLSearchParams({ page: url.searchParams.get('page') ?? '1' });
  if (versionId !== '') {
    query.set('datasetVersion', versionId);
  }
  const [list, versions] = await Promise.all([api(`/api/projects/${params.projectId}/runs?${query}`, { request }), projectVersions(params, request)]);

  return { list, versions, versionId, deleted: url.searchParams.has('deleted') };
}

export function Runs() {
  const { list, versions, versionId, deleted } = useLoaderData();
  const project = useRouteLoaderData('project');
  usePageTitle('Runs', project.name);

  return (
    <>
      <Breadcrumb items={crumbs(project).slice(0, 2).concat({ label: 'Runs' })} />
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <h1 className="mb-0">Runs</h1>
        {/* With no runs yet, the empty state has the one button. */}
        {canWrite(project) && versions.length > 0 && (list.total > 0 || versionId !== '') && (
          <Link className="btn btn-primary" to={versionId ? `new?datasetVersion=${versionId}` : 'new'}>
            Import run
          </Link>
        )}
      </div>
      <StatusMessage message={deleted ? 'The run was deleted.' : undefined} />

      {versions.length > 0 && (list.total > 0 || versionId !== '') && (
        // A GET form: the version goes into the URL, and only Update applies it (WCAG 3.2.2).
        <Form method="get" className="flex flex-wrap items-end gap-x-3" key={versionId}>
          <Field idPrefix="filter" name="datasetVersion" label="Dataset version" as="select" defaultValue={versionId}>
            <option value="">All versions</option>
            {versions.map((version) => (
              <option key={version.id} value={version.id}>
                {versionName(version)}
              </option>
            ))}
          </Field>
          <button type="submit" className="btn mb-4">
            Update
          </button>
        </Form>
      )}

      {list.total === 0 && <EmptyRuns project={project} hasVersions={versions.length > 0} filtered={versionId !== ''} />}
      {list.total > 0 && (
        <ul className="grid gap-3">
          {list.items.map((run) => (
            <li key={run.id} className="card">
              <h2 className="mb-1 text-base">
                <Link className="inline-flex min-h-6 items-center" to={run.id}>
                  {run.label}
                </Link>
              </h2>
              <p className="muted mb-0 text-sm">
                <bdi>{versionName(run.datasetVersion)}</bdi> · <strong className="text-text">{coverage(run)}</strong> · imported{' '}
                <time dateTime={run.createdAt}>{formatDate(run.createdAt)}</time>
              </p>
            </li>
          ))}
        </ul>
      )}
      <Pager list={list} />
    </>
  );
}

function EmptyRuns({ project, hasVersions, filtered }) {
  if (filtered) {
    return <p>No runs for this version.</p>;
  }
  if (!canWrite(project)) {
    return <p>{project.role === 'owner' ? 'No runs.' : 'The owner imports runs for this project.'}</p>;
  }
  if (!hasVersions) {
    return (
      <div className="card max-w-xl">
        <h2>Import a dataset first</h2>
        <p>A run holds a model’s answers to the cases of a dataset version, so the project needs a dataset before its first run.</p>
        <Link className="btn btn-primary" to={`/projects/${project.id}/datasets/new`}>
          Import a dataset
        </Link>
      </div>
    );
  }

  return (
    <div className="card max-w-xl">
      <h2>Import your first run</h2>
      <p>A run holds the answers one model gave to the cases of a dataset version. It may leave some cases unanswered.</p>
      <Link className="btn btn-primary" to="new">
        Import run
      </Link>
    </div>
  );
}

export async function importRunLoader({ params, request }) {
  return { versions: await projectVersions(params, request), selected: new URL(request.url).searchParams.get('datasetVersion') ?? '' };
}

export async function importRunAction({ params, request }) {
  const form = await request.formData();
  const body = new FormData();
  for (const name of ['datasetVersion', 'label', 'modelDescription']) {
    body.set(name, form.get(name) ?? '');
  }
  const file = form.get('file');
  if (file instanceof File && file.name !== '') {
    body.set('file', file);
  }

  return attemptUpload(
    file instanceof File && file.name !== '' ? file : null,
    async () => {
      const run = await api(`/api/projects/${params.projectId}/runs`, { method: 'POST', body, request });

      return redirect(`/projects/${params.projectId}/runs/${run.id}?created=1`);
    },
    { fileName: file instanceof File ? file.name : '' },
  );
}

/**
 * Import run (UX §3.2, steps 5–6). After an error the form keeps the version, label and description (WCAG 3.3.7):
 * it is not remounted, so its fields keep what was typed.
 */
export function ImportRun() {
  const { versions, selected } = useLoaderData();
  const project = useRouteLoaderData('project');
  const result = useActionData();
  const submitting = useNavigation().state === 'submitting';
  const formProblem = result?.problem?.code === 'import_rejected' ? undefined : result?.problem;
  usePageTitle('Import run', project.name);

  return (
    <div className="max-w-3xl">
      <Breadcrumb items={crumbs(project, { label: 'Import run' })} />
      <h1>Import run</h1>
      {!canWrite(project) && <p>Only the owners of an active project import runs.</p>}
      {canWrite(project) && versions.length === 0 && (
        <p>
          A run answers the cases of a dataset version, and this project has no dataset yet.{' '}
          <Link to={`/projects/${project.id}/datasets/new`}>Import a dataset</Link> first.
        </p>
      )}
      {canWrite(project) && versions.length > 0 && (
        <>
          <UploadProblem problem={result?.problem} fileName={result?.fileName} unchanged="No run was created." />
          <Form method="post" encType="multipart/form-data" noValidate className="max-w-xl">
            <Field name="datasetVersion" label="Dataset version" as="select" defaultValue={selected || versions[0].id} hint="The version whose cases the run answers." problem={formProblem}>
              {versions.map((version) => (
                <option key={version.id} value={version.id}>
                  {versionName(version)} ({n(version.caseCount)} cases)
                </option>
              ))}
            </Field>
            <Field name="label" label="Run label" required autoComplete="off" hint="Up to 100 characters, unique in the version, for example baseline-v1." problem={formProblem} />
            <Field name="modelDescription" label="Model description" as="textarea" hint="Optional: the model, its version and its parameters." problem={formProblem} />
            <ImportFileField problem={formProblem} rows="answers" columns={RUN_COLUMNS} />
            <button type="submit" className="btn btn-primary" disabled={submitting}>
              Check and import
            </button>
          </Form>
          <ImportProgress />
        </>
      )}
    </div>
  );
}

export async function runLoader({ params, request }) {
  const url = new URL(request.url);

  return { run: await api(`/api/projects/${params.projectId}/runs/${params.runId}`, { request }), created: url.searchParams.has('created') };
}

export async function runAction({ params, request }) {
  const form = await request.formData();
  if (form.get('intent') !== 'delete') {
    throw new Response('Unknown form', { status: 400 });
  }

  return attempt(async () => {
    await api(`/api/projects/${params.projectId}/runs/${params.runId}`, { method: 'DELETE', request });

    return redirect(`/projects/${params.projectId}/runs?deleted=1`);
  });
}

/** A run's metadata and coverage; never its answers (architecture §5): those appear when they are scored. */
export function Run() {
  const { run, created } = useLoaderData();
  const project = useRouteLoaderData('project');
  const result = useActionData();
  const version = run.datasetVersion;
  usePageTitle(run.label, project.name);

  return (
    <>
      <Breadcrumb items={crumbs(project, { label: run.label })} />
      <h1>{run.label}</h1>
      <StatusMessage
        message={
          created ? (
            <>
              Run <bdi>{run.label}</bdi> imported: {answers(run.answerCount)} ({n(run.answerCount)} of {n(version.caseCount)} cases).
            </>
          ) : undefined
        }
      />

      <dl className="mb-8 grid gap-x-6 gap-y-2 sm:grid-cols-[max-content_1fr]">
        <dt className="font-semibold">Dataset version</dt>
        <dd>
          <Link to={`/projects/${project.id}/datasets/${version.dataset.id}?version=${version.id}`}><bdi>{versionName(version)}</bdi>
          </Link>
        </dd>
        <dt className="font-semibold">Coverage</dt>
        <dd>
          {n(run.answerCount)} of {n(version.caseCount)} cases answered
          {run.answerCount < version.caseCount && <span className="muted"> (unanswered cases are not scored)</span>}
        </dd>
        <dt className="font-semibold">Model description</dt>
        <dd className="prewrap">{run.modelDescription || <span className="muted">None</span>}</dd>
        <dt className="font-semibold">Imported</dt>
        <dd>
          <time dateTime={run.createdAt}>{formatDate(run.createdAt)}</time> from <bdi className="font-mono text-sm">{run.sourceFilename}</bdi>
        </dd>
      </dl>
      <p className="muted">The answers appear when they are scored, in an evaluation.</p>

      {canWrite(project) && (
        <ConfirmDelete
          key={result?.problem ? JSON.stringify(result.problem) : 'delete'}
          heading="Delete the run"
          button="Delete run"
          question={
            <>
              Delete the run “<bdi>{run.label}</bdi>” with its {answers(run.answerCount)}? This cannot be undone.
            </>
          }
          problem={result?.problem}
        />
      )}
    </>
  );
}
