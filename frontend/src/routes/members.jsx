import { useState } from 'react';
import { flushSync } from 'react-dom';
import { Form, redirect, useActionData, useLoaderData, useRouteLoaderData } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ErrorSummary, Field, StatusMessage } from '../components/forms.jsx';
import { Breadcrumb, Pager, usePageTitle } from '../components/layout.jsx';
import { RoleOptions, ROLES } from '../roles.jsx';

/** Owners only: the API answers 403 to anyone else, and the error page says so. */
export async function membersLoader({ params, request }) {
  const page = new URL(request.url).searchParams.get('page') ?? '1';

  return api(`/api/projects/${params.projectId}/members?page=${encodeURIComponent(page)}`, { request });
}

/**
 * The page's forms, by intent. Each answer says which form it belongs to (intent, userId) and when (at), so only that
 * form shows it and resets.
 */
export async function membersAction({ params, request }) {
  const form = await request.formData();
  const intent = form.get('intent');
  const userId = form.get('userId');
  const self = form.get('self') === '1';
  const members = `/api/projects/${params.projectId}/members`;
  const answer = { intent, userId, at: Date.now() };

  return attempt(async () => {
    if (intent === 'invite') {
      const member = await api(members, { method: 'POST', body: { email: form.get('email'), role: form.get('role') }, request });

      return { ...answer, ok: `${member.displayName} was added as ${ROLES[member.role].article}.` };
    }
    if (intent === 'change-role') {
      const member = await api(`${members}/${userId}`, { method: 'PATCH', body: { role: form.get('role') }, request });
      // An owner who gives up the role can no longer open this page.
      if (self && member.role !== 'owner') {
        return redirect(`/projects/${params.projectId}`);
      }

      return { ...answer, ok: `${member.displayName} is now ${ROLES[member.role].article}.` };
    }
    if (intent === 'remove') {
      await api(`${members}/${userId}`, { method: 'DELETE', request });

      return self ? redirect('/projects') : { ...answer, ok: `${form.get('displayName')} was removed from the project.` };
    }
    throw new Response('Unknown form', { status: 400 });
  }, answer);
}

export function Members() {
  const list = useLoaderData();
  const project = useRouteLoaderData('project');
  const { user } = useRouteLoaderData('root');
  const result = useActionData();
  const canManage = project.role === 'owner' && !project.archivedAt;
  usePageTitle('Members', project.name);

  return (
    <>
      <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: project.name, to: `/projects/${project.id}` }, { label: 'Members' }]} />
      <h1>Members</h1>
      <StatusMessage message={result?.ok} />
      {canManage && <InviteForm key={result?.intent === 'invite' && result.ok ? result.at : 'invite'} result={result?.intent === 'invite' ? result : null} />}

      <h2>
        {list.total} {list.total === 1 ? 'member' : 'members'}
      </h2>
      <ul className="grid gap-3">
        {list.items.map((member) => (
          <li key={member.userId} className="card">
            <p className="mb-0 font-semibold">
              <bdi>{member.displayName}</bdi>
              {member.userId === user.id && ' (you)'}
            </p>
            <p className="muted mb-3 text-sm">
              <bdi>{member.email}</bdi>
            </p>
            {canManage ? (
              <MemberActions
                key={`${member.role}-${result?.userId === member.userId ? result.at : ''}`}
                member={member}
                isSelf={member.userId === user.id}
                result={result?.userId === member.userId ? result : null}
              />
            ) : (
              <p className="mb-0">
                <span className="badge">{ROLES[member.role].label}</span>
              </p>
            )}
          </li>
        ))}
      </ul>
      <Pager list={list} />
    </>
  );
}

function InviteForm({ result }) {
  return (
    <section aria-labelledby="invite-heading" className="card mb-8">
      <h2 id="invite-heading">Invite a member</h2>
      <p className="hint mb-4">Only people who already have an account can be added: ask them to register first.</p>
      <ErrorSummary problem={result?.problem} idPrefix="invite" />
      <Form method="post" noValidate>
        <input type="hidden" name="intent" value="invite" />
        <Field idPrefix="invite" name="email" label="Email" type="email" autoComplete="off" required problem={result?.problem} />
        <Field idPrefix="invite" name="role" label="Role" as="select" defaultValue="viewer" problem={result?.problem}>
          <RoleOptions />
        </Field>
        <button type="submit" className="btn btn-primary">
          Invite
        </button>
      </Form>
    </section>
  );
}

/** Change the role, or remove the member after a confirmation on the page. Keyed by the last answer, so it resets. */
function MemberActions({ member, isSelf, result }) {
  const [confirming, setConfirming] = useState(false);
  const idPrefix = `member-${member.userId}`;
  const hidden = (
    <>
      <input type="hidden" name="userId" value={member.userId} />
      <input type="hidden" name="displayName" value={member.displayName} />
      {isSelf && <input type="hidden" name="self" value="1" />}
    </>
  );

  return (
    <>
      <ErrorSummary problem={result?.problem} idPrefix={idPrefix} />
      <Form method="post" noValidate className="flex flex-wrap items-end gap-x-3">
        <input type="hidden" name="intent" value="change-role" />
        {hidden}
        <Field
          idPrefix={idPrefix}
          name="role"
          label={
            <>
              Role{' '}<span className="sr-only">of {member.displayName}</span>
            </>
          }
          as="select"
          defaultValue={member.role}
          problem={result?.problem}
        >
          <RoleOptions />
        </Field>
        <button type="submit" className="btn mb-4">
          Change role{' '}<span className="sr-only">of {member.displayName}</span>
        </button>
      </Form>

      {confirming ? (
        <Form method="post" className="flex flex-wrap items-center gap-3">
          <input type="hidden" name="intent" value="remove" />
          {hidden}
          <p className="mb-0">
            Remove <bdi>{member.displayName}</bdi> from the project?{isSelf && ' You will lose access to it.'}
          </p>
          <button type="submit" className="btn btn-danger" autoFocus>
            Remove
          </button>
          <button
            type="button"
            className="btn"
            onClick={() => {
              flushSync(() => setConfirming(false));
              document.getElementById(`${idPrefix}-remove`)?.focus();
            }}
          >
            Cancel
          </button>
        </Form>
      ) : (
        <button id={`${idPrefix}-remove`} type="button" className="btn btn-danger" onClick={() => setConfirming(true)}>
          Remove{' '}<span className="sr-only">{member.displayName}</span>
        </button>
      )}
    </>
  );
}
