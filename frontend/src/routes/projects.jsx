import { Form, Link, Outlet, redirect, useActionData, useLoaderData, useNavigation, useRouteLoaderData } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ErrorSummary, Field } from '../components/forms.jsx';
import { Icon } from '../components/icons.jsx';
import { Breadcrumb, Pager, usePageTitle } from '../components/layout.jsx';
import { RoleBadge, ROLES } from '../roles.jsx';
import { AnswersProgress, evaluationPath, ScoreLink, StatePills } from './evaluations.jsx';

/** "Support assistant" → "SA": the glyph of a project row. */
const glyph = (name) => {
  const words = name.trim().split(/\s+/).filter(Boolean);

  return (words.length > 1 ? words[0][0] + words[1][0] : [...(words[0] ?? '?')].slice(0, 2).join('')).toUpperCase();
};

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
    <div className="mx-auto max-w-[960px]">
      {/* The project list is the start: no trail above it, except from the archived ones back to it. */}
      {archived && <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: 'Archived' }]} />}
      <div className="page-head">
        <div>
          <h1 className="page-title">{title}</h1>
          <p className="page-meta">
            {list.total.toLocaleString('en')} {archived ? 'archived' : 'active'} {list.total === 1 ? 'project' : 'projects'}
          </p>
        </div>
        <div className="actions">
          <Link className="btn btn-ghost" to={archived ? '/projects' : '/projects?archived=true'}>
            <Icon name="archive" />
            {archived ? 'Show active projects' : 'Show archived projects'}
          </Link>
          {!archived && list.total > 0 && (
            <Link className="btn btn-primary" to="/projects/new">
              <Icon name="plus" />
              Create project
            </Link>
          )}
        </div>
      </div>

      {list.total === 0 && archived && <p>No archived projects.</p>}
      {list.total === 0 && !archived && (
        <div className="empty">
          <span className="empty-icon">
            <Icon name="folder" size="lg" />
          </span>
          <h2 className="empty-title">Create your first project</h2>
          <p>A project holds the datasets, runs, rubrics and evaluations for one AI system, and the people who work on it.</p>
          <div className="actions">
            <Link className="btn btn-primary" to="/projects/new">
              <Icon name="plus" />
              Create project
            </Link>
          </div>
        </div>
      )}
      {list.total > 0 && (
        <ul className="rows">
          {list.items.map((project) => (
            <li key={project.id} className="row">
              <span className="glyph" aria-hidden="true">
                {glyph(project.name)}
              </span>
              <div className="min-w-0">
                <h2 className="text-sm font-medium">
                  <Link className="row-link" to={`/projects/${project.id}`}>
                    <bdi>{project.name}</bdi>
                  </Link>
                </h2>
                {project.description && <p className="row-desc">{project.description}</p>}
              </div>
              <span>
                <span className="sr-only">Your role: </span>
                <RoleBadge role={project.role} />
              </span>
              <Icon name="chev-right" />
            </li>
          ))}
        </ul>
      )}
      <Pager list={list} noun="Projects" />
    </div>
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
    <div className="mx-auto max-w-[880px]">
      <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: 'Create project' }]} />
      <div className="page-head">
        <h1 className="page-title">Create project</h1>
      </div>
      <ErrorSummary problem={result?.problem} />
      <Form method="post" noValidate className="card">
        <div className="card-body stack" style={{ gap: 20 }}>
          <Field name="name" label="Name" required autoComplete="off" className="max-w-[480px]" problem={result?.problem} />
          <Field name="description" label="Description" as="textarea" hint="Optional. Up to 2,000 characters." problem={result?.problem} />
        </div>
        <div className="card-foot">
          <button type="submit" className="btn btn-primary" disabled={submitting}>
            Create project
          </button>
          <Link className="btn btn-ghost" to="/projects">
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
        <div className="notice mb-6">
          <Icon name="archive" />
          <p>
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

/** How far the project is along the "Next steps" (UX §3.1), and its five latest evaluations. */
export async function overviewLoader({ params, request }) {
  const base = `/api/projects/${params.projectId}`;
  const [datasets, runs, rubrics, evaluations] = await Promise.all([
    ...['datasets', 'runs', 'rubrics'].map((list) => api(`${base}/${list}?perPage=1`, { request })),
    api(`${base}/evaluations?perPage=5`, { request }),
  ]);

  return { hasDataset: datasets.total > 0, hasRun: runs.total > 0, hasRubric: rubrics.total > 0, evaluations };
}

export function Overview() {
  const project = useRouteLoaderData('project');
  const { hasDataset, hasRun, hasRubric, evaluations } = useLoaderData();
  const steps = [
    { label: 'Import a dataset', done: hasDataset, to: 'datasets/new' },
    { label: 'Import runs', done: hasRun, to: 'runs/new' },
    { label: 'Create a rubric', done: hasRubric, to: 'rubrics/new' },
    { label: 'Start an evaluation', done: evaluations.total > 0, to: 'evaluations/new' },
  ];
  const next = steps.find((step) => !step.done);
  usePageTitle('Overview', project.name);

  return (
    <>
      <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: project.name }]} />
      <div className="page-head">
        <div>
          <h1 className="page-title">
            <bdi>{project.name}</bdi>
          </h1>
          <p className="page-meta prewrap" style={{ maxWidth: '70ch' }}>
            {project.description || 'No description.'}
          </p>
        </div>
      </div>

      {/* The main column: the owner's next steps and the latest evaluations; the viewer's latest evaluations; the evaluator's own. */}
      <div className="grid-main">
        <div className="stack" style={{ gap: 32 }}>
          {project.role === 'owner' && (
            <section className="card" aria-labelledby="next-steps-heading">
              <div className="card-head">
                <h2 className="card-title" id="next-steps-heading">
                  Next steps
                </h2>
                <span className="small muted">
                  {steps.filter((step) => step.done).length} of {steps.length} done
                </span>
              </div>
              <ol className="steps">
                {steps.map((step, index) => (
                  <li key={step.label} className={step.done ? 'step is-done' : 'step'}>
                    <span className="step-mark">{step.done ? <Icon name="check" /> : index + 1}</span>
                    {step.done || project.archivedAt ? <span>{step.label}</span> : <Link to={`/projects/${project.id}/${step.to}`}>{step.label}</Link>}
                    <span className="step-state">{step.done ? 'Done' : step === next && !project.archivedAt ? 'Next' : ''}</span>
                  </li>
                ))}
              </ol>
            </section>
          )}
          {project.role === 'evaluator' ? <YourEvaluations project={project} evaluations={evaluations} /> : <LatestEvaluations project={project} evaluations={evaluations} />}
        </div>
        <RoleCard project={project} />
      </div>
    </>
  );
}

/** The owner's and the viewer's latest evaluations, with their progress and state (UX §2). */
function LatestEvaluations({ project, evaluations }) {
  return (
    <section aria-labelledby="evaluations-heading">
      <div className="section-head">
        <h2 className="section-title" id="evaluations-heading">
          Latest evaluations
        </h2>
        {evaluations.total > 0 && <Link to={`/projects/${project.id}/evaluations`}>All evaluations</Link>}
      </div>
      {evaluations.total === 0 ? (
        <p className="muted">No evaluations yet.</p>
      ) : (
        <ul className="rows">
          {evaluations.items.map((evaluation) => (
            <li key={evaluation.id} className="row sm:grid-cols-[minmax(0,1fr)_220px_96px]">
              <div>
                <h3 className="text-sm font-medium">
                  <Link className="row-link" to={evaluationPath(project, evaluation)}>
                    <bdi>{evaluation.name}</bdi>
                  </Link>
                </h3>
                <p className="row-desc">
                  <bdi>
                    {evaluation.datasetVersion.dataset.name} v{evaluation.datasetVersion.number}
                  </bdi>{' '}
                  · <bdi>{evaluation.rubric.name}</bdi>
                  {evaluation.blind && ' · Blind mode'}
                </p>
              </div>
              <AnswersProgress evaluation={evaluation} />
              <div className="justify-self-start">
                <StatePills evaluation={{ ...evaluation, blind: false }} date={false} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** The evaluator's evaluations, with Continue scoring where there is something to score (canvas: Overview, evaluator). */
function YourEvaluations({ project, evaluations }) {
  return (
    <section aria-labelledby="yours-heading">
      <div className="section-head">
        <h2 className="section-title" id="yours-heading">
          Your evaluations
        </h2>
        {evaluations.total > 0 && <Link to={`/projects/${project.id}/evaluations`}>All evaluations</Link>}
      </div>
      {evaluations.total === 0 ? (
        <p className="muted">No evaluations yet. The owner starts them, and they appear here to score.</p>
      ) : (
        <>
          <ul className="rows">
            {evaluations.items.map((evaluation) => (
              <li key={evaluation.id} className="row grid-cols-[minmax(0,1fr)_auto]">
                <div className="stack" style={{ gap: 8 }}>
                  <div>
                    <h3 className="text-sm font-medium">
                      <Link className="row-link" to={evaluationPath(project, evaluation)}>
                        <bdi>{evaluation.name}</bdi>
                      </Link>
                    </h3>
                    <p className="row-desc">
                      <bdi>
                        {evaluation.datasetVersion.dataset.name} v{evaluation.datasetVersion.number}
                      </bdi>{' '}
                      · <bdi>{evaluation.rubric.name}</bdi>
                      {evaluation.status === 'closed' && ' · Closed'}
                      {evaluation.blind && ' · Blind'}
                    </p>
                  </div>
                  <div className="max-w-[360px]">
                    <AnswersProgress evaluation={evaluation} />
                  </div>
                </div>
                {/* Above the row's stretched link, so both can be clicked. */}
                <div className="relative z-10">
                  <ScoreLink project={project} evaluation={evaluation} className="btn btn-primary" />
                </div>
              </li>
            ))}
          </ul>
          <p className="small muted mt-2.5">Results of a blind evaluation unlock when the owner closes it.</p>
        </>
      )}
    </section>
  );
}

function RoleCard({ project }) {
  const role = ROLES[project.role];

  return (
    <aside className="card" aria-labelledby="role-heading">
      <div className="card-head">
        <h2 className="card-title" id="role-heading">
          Your role
        </h2>
        <RoleBadge role={project.role} />
      </div>
      <div className="card-body stack" style={{ gap: 12 }}>
        <p>{role.description}</p>
        <p className="small muted">{role.detail}</p>
        {project.role === 'owner' && (
          <Link className="inline-flex min-h-6 items-center self-start" to={`/projects/${project.id}/members`}>
            Manage members
          </Link>
        )}
      </div>
    </aside>
  );
}
