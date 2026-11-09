import { Form, useActionData, useNavigation, useRouteLoaderData } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ErrorSummary, Field, StatusMessage } from '../components/forms.jsx';
import { Icon } from '../components/icons.jsx';
import { Breadcrumb, usePageTitle } from '../components/layout.jsx';

export async function settingsAction({ params, request }) {
  const form = await request.formData();
  const intent = form.get('intent');
  const project = `/api/projects/${params.projectId}`;
  const answer = { intent, at: Date.now() };

  return attempt(async () => {
    if (intent === 'save') {
      await api(project, { method: 'PATCH', body: { name: form.get('name'), description: form.get('description') }, request });

      return { ...answer, ok: 'The project details are saved.' };
    }
    if (intent === 'archive' || intent === 'restore') {
      await api(`${project}/${intent}`, { method: 'POST', request });

      return { ...answer, ok: intent === 'archive' ? 'The project is archived: it is read-only now.' : 'The project is restored.' };
    }
    throw new Response('Unknown form', { status: 400 });
  }, answer);
}

export function Settings() {
  const project = useRouteLoaderData('project');
  const result = useActionData();
  // The action's answer comes before the project is read again: say it once the page shows it (SWQ5-01).
  const idle = useNavigation().state === 'idle';
  const archived = Boolean(project.archivedAt);
  usePageTitle('Settings', project.name);

  const head = (
    <>
      <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: project.name, to: `/projects/${project.id}` }, { label: 'Settings' }]} />
      <div className="page-head">
        <h1 className="page-title">Settings</h1>
      </div>
    </>
  );
  if (project.role !== 'owner') {
    return (
      <div className="mx-auto max-w-[880px]">
        {head}
        <p>Only the owners of this project can change its settings.</p>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-[880px]">
      {head}
      <StatusMessage message={idle ? result?.ok : undefined} />
      <div className="stack" style={{ gap: 24 }}>
        {!archived && (
          <section aria-labelledby="details-heading" className="card">
            <div className="card-head">
              <h2 className="card-title" id="details-heading">
                Project details
              </h2>
            </div>
            {/* Keyed by the saved values, so the fields show them again after a save. */}
            <Form method="post" noValidate key={`${project.name}\n${project.description}`} className="card-body stack">
              <ErrorSummary problem={result?.intent === 'save' ? result.problem : undefined} idPrefix="details" />
              <input type="hidden" name="intent" value="save" />
              <Field idPrefix="details" name="name" label="Name" required autoComplete="off" defaultValue={project.name} className="max-w-[480px]" problem={result?.problem} />
              <Field
                idPrefix="details"
                name="description"
                label="Description"
                as="textarea"
                hint="Optional. Up to 2,000 characters."
                defaultValue={project.description}
                problem={result?.problem}
              />
              <div>
                <button type="submit" className="btn btn-primary">
                  Save
                </button>
              </div>
            </Form>
          </section>
        )}

        <section aria-labelledby="archive-heading" className="card">
          <div className="card-body stack" style={{ gap: 12 }}>
            <ErrorSummary problem={result?.intent === 'save' ? undefined : result?.problem} idPrefix="archive" />
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div style={{ maxWidth: '60ch' }}>
                <h2 className="card-title" id="archive-heading">
                  {archived ? 'Restore the project' : 'Archive the project'}
                </h2>
                <p className="muted">
                  {archived
                    ? 'Restoring makes the project editable again and shows it in the project list.'
                    : 'Archiving makes the project read-only for every member and hides it from the project list. You can restore it at any time.'}
                </p>
              </div>
              <Form method="post">
                <input type="hidden" name="intent" value={archived ? 'restore' : 'archive'} />
                <button type="submit" className={archived ? 'btn btn-primary' : 'btn btn-danger'}>
                  <Icon name="archive" />
                  {archived ? 'Restore project' : 'Archive project'}
                </button>
              </Form>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
