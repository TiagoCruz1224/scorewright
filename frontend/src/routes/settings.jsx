import { Form, useActionData, useRouteLoaderData } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ErrorSummary, Field, StatusMessage } from '../components/forms.jsx';
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
  const archived = Boolean(project.archivedAt);
  usePageTitle('Settings', project.name);

  const breadcrumb = <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: project.name, to: `/projects/${project.id}` }, { label: 'Settings' }]} />;
  if (project.role !== 'owner') {
    return (
      <>
        {breadcrumb}
        <h1>Settings</h1>
        <p>Only the owners of this project can change its settings.</p>
      </>
    );
  }

  return (
    <>
      {breadcrumb}
      <h1>Settings</h1>
      <StatusMessage message={result?.ok} />

      {!archived && (
        <section aria-labelledby="details-heading" className="mb-10 max-w-xl">
          <h2 id="details-heading">Project details</h2>
          <ErrorSummary problem={result?.intent === 'save' ? result.problem : undefined} idPrefix="details" />
          {/* Keyed by the saved values, so the fields show them again after a save. */}
          <Form method="post" noValidate key={`${project.name}\n${project.description}`}>
            <input type="hidden" name="intent" value="save" />
            <Field idPrefix="details" name="name" label="Name" required autoComplete="off" defaultValue={project.name} problem={result?.problem} />
            <Field
              idPrefix="details"
              name="description"
              label="Description"
              as="textarea"
              hint="Optional. Up to 2,000 characters."
              defaultValue={project.description}
              problem={result?.problem}
            />
            <button type="submit" className="btn btn-primary">
              Save
            </button>
          </Form>
        </section>
      )}

      <section aria-labelledby="archive-heading" className="max-w-xl">
        <h2 id="archive-heading">{archived ? 'Restore the project' : 'Archive the project'}</h2>
        <p>
          {archived
            ? 'Restoring makes the project editable again and shows it in the project list.'
            : 'Archiving makes the project read-only for every member and hides it from the project list. You can restore it at any time.'}
        </p>
        <ErrorSummary problem={result?.intent === 'save' ? undefined : result?.problem} idPrefix="archive" />
        <Form method="post">
          <input type="hidden" name="intent" value={archived ? 'restore' : 'archive'} />
          <button type="submit" className={archived ? 'btn btn-primary' : 'btn btn-danger'}>
            {archived ? 'Restore project' : 'Archive project'}
          </button>
        </Form>
      </section>
    </>
  );
}
