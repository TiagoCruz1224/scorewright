import { useState } from 'react';
import { flushSync } from 'react-dom';
import { Form, redirect, useActionData, useLoaderData, useNavigation, useRouteLoaderData } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ErrorSummary, Field, StatusMessage } from '../components/forms.jsx';
import { Icon } from '../components/icons.jsx';
import { Breadcrumb, initials, Pager, usePageTitle } from '../components/layout.jsx';
import { RoleBadge, RoleOptions, ROLES } from '../roles.jsx';

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
  // React Router publishes the action's answer before the list is read again: say it once the list shows it (SWQ5-01).
  const idle = useNavigation().state === 'idle';
  const canManage = project.role === 'owner' && !project.archivedAt;
  const owners = list.items.filter((member) => member.role === 'owner').length;
  const counted = (number, word) => `${number} ${word}${number === 1 ? '' : 's'}`;
  usePageTitle('Members', project.name);

  return (
    <>
      <Breadcrumb items={[{ label: 'Projects', to: '/projects' }, { label: project.name, to: `/projects/${project.id}` }, { label: 'Members' }]} />
      <div className="page-head">
        <div>
          <h1 className="page-title">Members</h1>
          <p className="page-meta">
            {counted(list.total, 'member')} · {counted(owners, 'owner')}
          </p>
        </div>
      </div>
      <StatusMessage message={idle ? result?.ok : undefined} />
      {canManage && <InviteForm key={result?.intent === 'invite' && result.ok ? result.at : 'invite'} result={result?.intent === 'invite' ? result : null} />}

      <section className="section">
        <div className="section-head">
          <h2 className="section-title" id="members-heading">
            {counted(list.total, 'member')}
          </h2>
        </div>
        <div className="table-box" role="region" aria-labelledby="members-heading" tabIndex={0}>
          <table className="tbl" style={{ minWidth: canManage ? 640 : 420 }}>
            <thead>
              <tr>
                <th scope="col">Member</th>
                <th scope="col">Role</th>
                {canManage && (
                  <th scope="col">
                    <span className="sr-only">Actions</span>
                  </th>
                )}
              </tr>
            </thead>
            <tbody>
              {list.items.map((member) => (
                <MemberRow
                  key={`${member.userId}-${member.role}-${result?.userId === member.userId ? result.at : ''}`}
                  member={member}
                  isSelf={member.userId === user.id}
                  canManage={canManage}
                  result={result?.userId === member.userId ? result : null}
                />
              ))}
            </tbody>
          </table>
        </div>
        <Pager list={list} noun="Members" />
        {canManage && <p className="small muted mt-2.5">A project always keeps at least one owner. Removing yourself takes away your access.</p>}
      </section>
    </>
  );
}

function InviteForm({ result }) {
  return (
    <section aria-labelledby="invite-heading" className="card">
      <div className="card-head">
        <h2 className="card-title" id="invite-heading">
          Invite a member
        </h2>
      </div>
      <div className="card-body">
        <ErrorSummary problem={result?.problem} idPrefix="invite" />
        <Form method="post" noValidate className="@container flex flex-wrap items-start gap-3">
          <input type="hidden" name="intent" value="invite" />
          <Field
            idPrefix="invite"
            name="email"
            label="Email"
            type="email"
            autoComplete="off"
            required
            className="flex-[1_1_280px]"
            hint="Only people who already have an account can be added: ask them to register first."
            problem={result?.problem}
          />
          <Field idPrefix="invite" name="role" label="Role" as="select" defaultValue="viewer" className="w-[180px]" problem={result?.problem}>
            <RoleOptions />
          </Field>
          {/* Level with the inputs, below their labels, only while the three fit on one line (280 + 180 + 88 px and the gaps). */}
          <button type="submit" className="btn btn-primary @min-[36rem]:mt-[26px]">
            <Icon name="mail" />
            Invite
          </button>
        </Form>
      </div>
    </section>
  );
}

/**
 * One member: who, the role (to change, for an owner) and Remove, which asks on the page first. Keyed by the last
 * answer, so it resets.
 */
function MemberRow({ member, isSelf, canManage, result }) {
  const [confirming, setConfirming] = useState(false);
  const idPrefix = `member-${member.userId}`;
  const roleErrors = (result?.problem?.errors ?? []).filter((error) => error.field === 'role');
  const hidden = (
    <>
      <input type="hidden" name="userId" value={member.userId} />
      <input type="hidden" name="displayName" value={member.displayName} />
      {isSelf && <input type="hidden" name="self" value="1" />}
    </>
  );

  return (
    <>
      <tr>
        <th scope="row" className="font-normal">
          <div className="member">
            <span className="avatar" aria-hidden="true">
              {initials(member.displayName)}
            </span>
            <span>
              <b className="font-medium">
                <bdi>{member.displayName}</bdi>
              </b>
              {isSelf && <span className="muted"> (you)</span>}
              <br />
              <span className="small muted">
                <bdi>{member.email}</bdi>
              </span>
            </span>
          </div>
        </th>
        <td>
          {canManage ? (
            <Form method="post" noValidate className="flex flex-wrap items-center gap-2">
              <input type="hidden" name="intent" value="change-role" />
              {hidden}
              <label className="sr-only" htmlFor={`${idPrefix}-role`}>
                Role of {member.displayName}
              </label>
              <select
                id={`${idPrefix}-role`}
                name="role"
                defaultValue={member.role}
                style={{ width: 150, height: 32 }}
                aria-invalid={roleErrors.length > 0 || undefined}
              >
                <RoleOptions />
              </select>
              <button type="submit" className="btn btn-sm">
                Change role{' '}<span className="sr-only">of {member.displayName}</span>
              </button>
            </Form>
          ) : (
            <RoleBadge role={member.role} />
          )}
        </td>
        {canManage && (
          <td className="text-right">
            {!confirming && (
              <button id={`${idPrefix}-remove`} type="button" className="btn btn-ghost btn-sm text-danger" onClick={() => setConfirming(true)}>
                <Icon name="trash" />
                Remove{' '}<span className="sr-only">{member.displayName}</span>
              </button>
            )}
          </td>
        )}
      </tr>
      {(confirming || result?.problem) && (
        <tr>
          <td colSpan={canManage ? 3 : 2} className="bg-surface">
            <ErrorSummary problem={result?.problem} idPrefix={idPrefix} />
            {confirming && (
              <Form method="post" className="confirm bg-bg">
                <input type="hidden" name="intent" value="remove" />
                {hidden}
                <p>
                  Remove <bdi className="font-semibold">{member.displayName}</bdi> from the project?{isSelf && ' You will lose access to it.'}
                </p>
                <button type="submit" className="btn btn-danger btn-sm" autoFocus>
                  Remove
                </button>
                <button
                  type="button"
                  className="btn btn-sm"
                  onClick={() => {
                    flushSync(() => setConfirming(false));
                    document.getElementById(`${idPrefix}-remove`)?.focus();
                  }}
                >
                  Cancel
                </button>
              </Form>
            )}
          </td>
        </tr>
      )}
    </>
  );
}
