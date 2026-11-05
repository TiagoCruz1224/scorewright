import { Form, Link, Outlet, redirect, useActionData, useLoaderData, useNavigation, useRouteLoaderData } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ErrorSummary, Field } from '../components/forms.jsx';
import { Breadcrumb, Pager, usePageTitle } from '../components/layout.jsx';
import { ROLES } from '../roles.jsx';

export async function projectsLoader({ request }) {
  const url = new URL(request.url);
  const archived = url.searchParams.get('archived') === 'true';
  const query = new URLSearchParams({ page: url.searchParams.get('page') ?? '1' });
  if (archived) {
    query.set('archived', 'true');
  }

  return { archived, list: await api(`/api/projects?${query}`, { request }) };
}

export function Projects() {
  const { archived, list } = useLoaderData();
  const title = archived ? 'Archived projects' : 'Projects';
  usePageTitle(title);

  return (
    <>
      <Breadcrumb items={archived ? [{ label: 'Projects', to: '/projects' }, { label: 'Archived' }] : [{ label: 'Projects' }]} />
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <h1 className="mb-0">{title}</h1>
        <div className="flex flex-wrap gap-2">
          <Link className="btn" to={archived ? '/projects' : '/projects?archived=true'}>
            {archived ? 'Show active projects' : 'Show archived projects'}
          </Link>
          {!archived && list.total > 0 && (
            <Link className="btn btn-primary" to="/projects/new">
              Create project
            </Link>
          )}
        </div>
      </div>

      {list.total === 0 && archived && <p>No archived projects.</p>}
      {list.total === 0 && !archived && (
        <div className="card max-w-xl">
          <h2>Create your first project</h2>
          <p>A project holds the datasets, runs, rubrics and evaluations for one AI system, and the people who work on it.</p>
          <Link className="btn btn-primary" to="/projects/new">
            Create project
          </Link>
        </div>
      )}
      {list.total > 0 && (
        <ul className="grid gap-3">
          {list.items.map((project) => (
            <li key={project.id} className="card">
              <h2 className="mb-1 text-base">
                <Link className="inline-flex min-h-6 items-center" to={`/projects/${project.id}`}>
                  {project.name}
                </Link>
              </h2>
              <p className="muted mb-0 text-sm">Your role: {ROLES[project.role].label}</p>
              {project.description && <p className="mt-2 mb-0 line-clamp-2">{project.description}</p>}
            </li>
          ))}
        </ul>
      )}
      <Pager list={list} />
    </>
  );
}

export async function newProjectAction({ request }) {
  const form = await request.formData();

  return attempt(async () => {
    const project = await api('/api/projects', { method: 'POST', body: { name: form.get('name'), description: form.get('description') }, request });

    return redirect(`/projects/${project.id}`);
  });
}

export function NewProject() {
  const result = useActionData();
  const submitting = useNavigation().state === 'submitting';
  usePageTitle('Create project');

  return (
    <div className="max-w-xl">
      <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: 'Create project' }]} />
      <h1>Create project</h1>
      <ErrorSummary problem={result?.problem} />
      <Form method="post" noValidate>
        <Field name="name" label="Name" required autoComplete="off" problem={result?.problem} />
        <Field name="description" label="Description" as="textarea" hint="Optional. Up to 2,000 characters." problem={result?.problem} />
        <div className="flex flex-wrap gap-3">
          <button type="submit" className="btn btn-primary" disabled={submitting}>
            Create project
          </button>
          <Link className="btn" to="/projects">
            Cancel
          </Link>
        </div>
      </Form>
    </div>
  );
}

/** The project and the caller's role in it, for every page of the project (route id "project"). */
export async function projectLoader({ params, request }) {
  return api(`/api/projects/${params.projectId}`, { request });
}

/** Every page of a project; an archived project says so above the page, and its pages offer no writes. */
export function ProjectLayout() {
  const project = useLoaderData();

  return (
    <>
      {project.archivedAt && (
        <div className="notice">
          <p className="mb-0">
            <strong>This project is archived.</strong> It is read-only
            {project.role === 'owner' ? (
              <>
                : restore it in <Link to={`/projects/${project.id}/settings`}>Settings</Link> to change it again.
              </>
            ) : (
              '.'
            )}
          </p>
        </div>
      )}
      <Outlet />
    </>
  );
}

/** How far the project is along the "Next steps" (UX §3.1); for now, whether it has a dataset. */
export async function overviewLoader({ params, request }) {
  const datasets = await api(`/api/projects/${params.projectId}/datasets?perPage=1`, { request });

  return { hasDataset: datasets.total > 0 };
}

export function Overview() {
  const project = useRouteLoaderData('project');
  const { hasDataset } = useLoaderData();
  const role = ROLES[project.role];
  usePageTitle('Overview', project.name);

  return (
    <>
      <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: project.name }]} />
      <h1>{project.name}</h1>
      {project.description ? <p className="prewrap">{project.description}</p> : <p className="muted">No description.</p>}

      {project.role === 'owner' && (
        <section aria-labelledby="next-steps-heading" className="mt-8">
          <h2 id="next-steps-heading">Next steps</h2>
          {/* The later steps (runs, rubric, evaluation) join this list with their screens. */}
          <ol className="list-decimal pl-6">
            <li>
              {hasDataset ? (
                <>
                  Import a dataset: <strong className="text-success">✓ done</strong>
                </>
              ) : project.archivedAt ? (
                'Import a dataset'
              ) : (
                <Link to={`/projects/${project.id}/datasets/new`}>Import a dataset</Link>
              )}
            </li>
          </ol>
        </section>
      )}

      <h2 className="mt-8">Your role</h2>
      <p>
        <span className="badge mr-2">{role.label}</span>
        {role.description}
      </p>
    </>
  );
}
