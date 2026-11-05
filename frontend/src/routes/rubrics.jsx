import { useMemo, useState } from 'react';
import { flushSync } from 'react-dom';
import { Form, Link, redirect, useActionData, useLoaderData, useNavigation, useRouteLoaderData, useSubmit } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ConfirmDelete, ErrorSummary, Field, StatusMessage } from '../components/forms.jsx';
import { Breadcrumb, Pager, usePageTitle } from '../components/layout.jsx';

const n = (number) => number.toLocaleString('en');
const criteriaCount = (count) => `${n(count)} ${count === 1 ? 'criterion' : 'criteria'}`;

/** Owners write; nobody writes to an archived project (UX §1.5). */
const canWrite = (project) => project.role === 'owner' && !project.archivedAt;

const crumbs = (project, ...rest) => [
  { label: 'Projects', to: '/projects' },
  { label: project.name, to: `/projects/${project.id}` },
  { label: 'Rubrics', to: `/projects/${project.id}/rubrics` },
  ...rest,
];

const MAX_CRITERIA = 30;
const KINDS = { scale_1_5: 'Manual: scale 1–5', pass_fail: 'Manual: pass or fail', automatic: 'Automatic check' };
const CHECKS = {
  exact_match: 'Exact match with the reference',
  contains: 'Contains a text',
  not_contains: 'Does not contain a text',
  regex: 'Matches a regular expression',
  length: 'Length limits',
  valid_json: 'Valid JSON',
};
const GUIDANCE_HINTS = {
  scale_1_5: 'Required: what 1 and 5 mean, and how to choose between them.',
  pass_fail: 'Required: when an answer passes.',
  automatic: 'Optional: what the check is for.',
};

/** "Regex: ^Refund", "Length: 1–500 characters": an automatic criterion's check in words, the typed parts isolated. */
export function checkText({ checkType, checkParams: params }) {
  const ignoringCase = params.ignoreCase ? ' (ignoring case)' : '';
  switch (checkType) {
    case 'exact_match':
      return `Exact match with the reference${ignoringCase}`;
    case 'contains':
      return (
        <>
          Contains: “<bdi>{params.text}</bdi>”{ignoringCase}
        </>
      );
    case 'not_contains':
      return (
        <>
          Does not contain: “<bdi>{params.text}</bdi>”{ignoringCase}
        </>
      );
    case 'regex':
      return (
        <>
          Regex: <bdi>{params.pattern}</bdi>
          {ignoringCase}
        </>
      );
    case 'length':
      if (params.min !== null && params.max !== null) {
        return `Length: ${n(params.min)}–${n(params.max)} characters`;
      }
      return params.max !== null ? `Length: at most ${n(params.max)} characters` : `Length: at least ${n(params.min)} characters`;
    default:
      return 'Valid JSON';
  }
}

export async function rubricsLoader({ params, request }) {
  const url = new URL(request.url);
  const page = url.searchParams.get('page') ?? '1';

  return { list: await api(`/api/projects/${params.projectId}/rubrics?page=${page}`, { request }), deleted: url.searchParams.has('deleted') };
}

export function Rubrics() {
  const { list, deleted } = useLoaderData();
  const project = useRouteLoaderData('project');
  usePageTitle('Rubrics', project.name);

  return (
    <>
      <Breadcrumb items={crumbs(project).slice(0, 2).concat({ label: 'Rubrics' })} />
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <h1 className="mb-0">Rubrics</h1>
        {/* With no rubrics yet, the empty state has the one button. */}
        {canWrite(project) && list.total > 0 && (
          <Link className="btn btn-primary" to="new">
            New rubric
          </Link>
        )}
      </div>
      <StatusMessage message={deleted ? 'The rubric was deleted.' : undefined} />

      {list.total === 0 && <EmptyRubrics project={project} />}
      {list.total > 0 && (
        <ul className="grid gap-3">
          {list.items.map((rubric) => (
            <li key={rubric.id} className="card">
              <h2 className="mb-1 text-base">
                <Link className="inline-flex min-h-6 items-center" to={rubric.id}>
                  {rubric.name}
                </Link>
              </h2>
              <p className="muted mb-0 text-sm">
                {criteriaCount(rubric.criterionCount)}
                {rubric.lockedAt && (
                  <>
                    {' · '}
                    <strong className="text-text">Locked: used in an evaluation</strong>
                  </>
                )}
              </p>
            </li>
          ))}
        </ul>
      )}
      <Pager list={list} />
    </>
  );
}

function EmptyRubrics({ project }) {
  if (!canWrite(project)) {
    return <p>{project.role === 'owner' ? 'No rubrics.' : 'The owner creates rubrics for this project.'}</p>;
  }

  return (
    <div className="card max-w-xl">
      <h2>Create your first rubric</h2>
      <p>A rubric lists the criteria that answers are scored on: by people, from 1 to 5 or pass or fail, or by an automatic check.</p>
      <Link className="btn btn-primary" to="new">
        New rubric
      </Link>
    </div>
  );
}

/**
 * The editor submits JSON: the rubric as the API takes it ({name, criteria}), and the key of each criterion in that
 * order, which comes back with an error to place it.
 */
async function saveRubric(request, call) {
  const { rubric, keys } = await request.json();

  return attempt(() => call(rubric), { keys });
}

export async function newRubricAction({ params, request }) {
  return saveRubric(request, async (body) => {
    const rubric = await api(`/api/projects/${params.projectId}/rubrics`, { method: 'POST', body, request });

    return redirect(`/projects/${params.projectId}/rubrics/${rubric.id}?created=1`);
  });
}

export function NewRubric() {
  const project = useRouteLoaderData('project');
  usePageTitle('New rubric', project.name);

  return (
    <div className="max-w-3xl">
      <Breadcrumb items={crumbs(project, { label: 'New rubric' })} />
      <h1>New rubric</h1>
      {canWrite(project) ? <RubricEditor /> : <p>Only the owners of an active project create rubrics.</p>}
    </div>
  );
}

/** A locked rubric opens read-only, on its page, where it can be duplicated. */
export async function editRubricLoader({ params, request }) {
  const rubric = await api(`/api/projects/${params.projectId}/rubrics/${params.rubricId}`, { request });
  if (rubric.lockedAt) {
    throw redirect(`/projects/${params.projectId}/rubrics/${params.rubricId}`);
  }

  return { rubric };
}

export async function editRubricAction({ params, request }) {
  return saveRubric(request, async (body) => {
    await api(`/api/projects/${params.projectId}/rubrics/${params.rubricId}`, { method: 'PUT', body, request });

    return redirect(`/projects/${params.projectId}/rubrics/${params.rubricId}?saved=1`);
  });
}

export function EditRubric() {
  const { rubric } = useLoaderData();
  const project = useRouteLoaderData('project');
  usePageTitle(`Edit ${rubric.name}`, project.name);

  return (
    <div className="max-w-3xl">
      <Breadcrumb items={crumbs(project, { label: rubric.name, to: `/projects/${project.id}/rubrics/${rubric.id}` }, { label: 'Edit' })} />
      <h1>Edit {rubric.name}</h1>
      {canWrite(project) ? <RubricEditor key={rubric.id} rubric={rubric} /> : <p>Only the owners of an active project edit rubrics.</p>}
    </div>
  );
}

let lastKey = 0;
/** A criterion's key in the editor: it stays the same when the criterion moves, unlike its position. */
const newKey = () => `c${++lastKey}`;

const BLANK = { kind: 'scale_1_5', name: '', guidance: '', weight: '', checkType: 'contains', text: '', pattern: '', ignoreCase: false, min: '', max: '' };

/** A criterion from the API as the editor's fields, which keep every kind's values while the kind changes. */
function editable(criterion) {
  const params = criterion.checkParams ?? {};
  const text = (value) => (value === null || value === undefined ? '' : String(value));

  return {
    ...BLANK,
    key: newKey(),
    kind: criterion.kind,
    name: criterion.name,
    guidance: criterion.guidance,
    weight: text(criterion.weight),
    checkType: criterion.checkType ?? BLANK.checkType,
    text: params.text ?? '',
    pattern: params.pattern ?? '',
    ignoreCase: params.ignoreCase ?? false,
    min: text(params.min),
    max: text(params.max),
  };
}

/** The criterion as the API takes it: only its kind's fields, numbers as numbers. */
function payload(criterion) {
  const number = (value) => (value === '' ? null : Number(value));
  const fields = { kind: criterion.kind, name: criterion.name, guidance: criterion.guidance, weight: number(criterion.weight) };
  if (criterion.kind !== 'automatic') {
    return fields;
  }
  const { ignoreCase } = criterion;
  const checkParams = {
    exact_match: { ignoreCase },
    contains: { text: criterion.text, ignoreCase },
    not_contains: { text: criterion.text, ignoreCase },
    regex: { pattern: criterion.pattern, ignoreCase },
    length: { min: number(criterion.min), max: number(criterion.max) },
    valid_json: {},
  }[criterion.checkType];

  return { ...fields, checkType: criterion.checkType, checkParams };
}

/**
 * The API names a criterion's field by its position when saved ("criteria[2].checkParams.pattern"); the editor names it
 * by the criterion's key ("criteria.c7.checkParams.pattern"), so an error stays with its criterion after a move.
 */
function byKey(problem, savedKeys) {
  return (
    problem && {
      ...problem,
      errors: problem.errors.map((error) => {
        const match = /^criteria\[(\d+)\](.*)$/.exec(error.field);
        const key = match && savedKeys[Number(match[1])];

        return key ? { ...error, field: `criteria.${key}${match[2]}` } : error;
      }),
    }
  );
}

/**
 * New rubric and Edit (UX §3.3): the name, then the criteria in order. Move up and Move down reorder them without
 * dragging (WCAG 2.5.7), and the focus stays on the moved criterion. Save sends the whole rubric; an error shows in the
 * summary and next to its field, and every field keeps what was typed (WCAG 3.3.7).
 */
function RubricEditor({ rubric }) {
  const [name, setName] = useState(rubric?.name ?? '');
  const [criteria, setCriteria] = useState(() => (rubric?.criteria ?? []).map(editable));
  const [announcement, setAnnouncement] = useState();
  const result = useActionData();
  const submit = useSubmit();
  const submitting = useNavigation().state === 'submitting';
  const problem = useMemo(() => byKey(result?.problem, result?.keys ?? []), [result]);
  const listErrors = (problem?.errors ?? []).filter((error) => error.field === 'criteria');

  const title = (criterion, index) => criterion.name.trim() || `Criterion ${index + 1}`;
  const change = (key, changes) => setCriteria((list) => list.map((criterion) => (criterion.key === key ? { ...criterion, ...changes } : criterion)));

  function add() {
    const criterion = { ...BLANK, key: newKey() };
    flushSync(() => {
      setCriteria([...criteria, criterion]);
      setAnnouncement(`Criterion ${criteria.length + 1} added.`);
    });
    document.getElementById(`field-criteria.${criterion.key}.kind`)?.focus();
  }

  function move(index, step) {
    const target = index + step;
    const list = [...criteria];
    [list[index], list[target]] = [list[target], list[index]];
    const moved = list[target];
    flushSync(() => {
      setCriteria(list);
      setAnnouncement(`${title(moved, target)} moved to position ${target + 1} of ${list.length}.`);
    });
    // The focus stays on the same button of the moved criterion, or on its other one when it reached an end.
    const atEnd = step < 0 ? target === 0 : target === list.length - 1;
    const direction = (step < 0) !== atEnd ? 'up' : 'down';
    document.getElementById(`move-${direction}-${moved.key}`)?.focus();
  }

  function remove(index) {
    const removed = criteria[index];
    flushSync(() => {
      setCriteria(criteria.filter((criterion) => criterion.key !== removed.key));
      setAnnouncement(`${title(removed, index)} removed.`);
    });
    document.getElementById('field-criteria')?.focus();
  }

  function save(event) {
    event.preventDefault();
    submit({ rubric: { name, criteria: criteria.map(payload) }, keys: criteria.map((criterion) => criterion.key) }, { method: 'post', encType: 'application/json' });
  }

  return (
    <>
      <ErrorSummary problem={problem} />
      <form onSubmit={save} noValidate>
        <Field
          name="name"
          label="Rubric name"
          value={name}
          onChange={(event) => setName(event.target.value)}
          required
          autoComplete="off"
          hint="Up to 200 characters, unique in the project."
          problem={problem}
        />

        <h2 className="mt-8">Criteria</h2>
        <p className="hint mb-4">At least one and at most {MAX_CRITERIA}, in the order evaluators see them.</p>
        <StatusMessage message={announcement} />
        {criteria.length === 0 && <p>No criteria yet.</p>}
        <ol className="mb-4 grid gap-4">
          {criteria.map((criterion, index) => (
            <li key={criterion.key}>
              <CriterionFields
                criterion={criterion}
                index={index}
                count={criteria.length}
                title={title(criterion, index)}
                problem={problem}
                onChange={(changes) => change(criterion.key, changes)}
                onMove={(step) => move(index, step)}
                onRemove={() => remove(index)}
              />
            </li>
          ))}
        </ol>
        {listErrors.length > 0 && (
          <p id="criteria-error" className="field-error mb-2">
            <span className="sr-only">Error: </span>
            {listErrors.map((error) => error.message).join(' ')}
          </p>
        )}
        <p>
          <button
            id="field-criteria"
            type="button"
            className="btn"
            onClick={add}
            disabled={criteria.length >= MAX_CRITERIA}
            aria-describedby={listErrors.length > 0 ? 'criteria-error' : undefined}
          >
            Add criterion
          </button>
        </p>

        <button type="submit" className="btn btn-primary mt-4" disabled={submitting}>
          Save
        </button>
      </form>
    </>
  );
}

/** One criterion's fields in a fieldset (WCAG 1.3.1), with its Move and Remove buttons. */
function CriterionFields({ criterion, index, count, title, problem, onChange, onMove, onRemove }) {
  const path = (field) => `criteria.${criterion.key}.${field}`;
  const set = (field) => (event) => onChange({ [field]: event.target.type === 'checkbox' ? event.target.checked : event.target.value });
  const named = criterion.name.trim();

  return (
    <fieldset className="card">
      <legend className="px-1 font-semibold">
        Criterion {index + 1}
        {named && (
          <>
            : <bdi>{named}</bdi>
          </>
        )}
      </legend>
      <Field name={path('kind')} label="Kind" as="select" value={criterion.kind} onChange={set('kind')} problem={problem}>
        {Object.entries(KINDS).map(([value, text]) => (
          <option key={value} value={value}>
            {text}
          </option>
        ))}
      </Field>
      <Field name={path('name')} label="Name" value={criterion.name} onChange={set('name')} autoComplete="off" hint="Up to 200 characters, on one line." problem={problem} />
      {criterion.kind === 'automatic' && (
        <>
          <Field name={path('checkType')} label="Check" as="select" value={criterion.checkType} onChange={set('checkType')} problem={problem}>
            {Object.entries(CHECKS).map(([value, text]) => (
              <option key={value} value={value}>
                {text}
              </option>
            ))}
          </Field>
          <CheckParams criterion={criterion} path={path} set={set} problem={problem} />
        </>
      )}
      <Field
        name={path('guidance')}
        label={criterion.kind === 'automatic' ? 'Guidance (optional)' : 'Guidance'}
        as="textarea"
        value={criterion.guidance}
        onChange={set('guidance')}
        hint={`${GUIDANCE_HINTS[criterion.kind]} Up to 5,000 characters.`}
        problem={problem}
      />
      <Field
        name={path('weight')}
        label="Weight (optional)"
        type="number"
        inputMode="decimal"
        step="any"
        min="0"
        max="100"
        className="max-w-40"
        value={criterion.weight}
        onChange={set('weight')}
        hint="Above 0 and at most 100. Empty counts as 1."
        problem={problem}
      />
      {/* The names say which criterion, after the visible words (WCAG 2.5.3): "Move up: Tone". */}
      <div className="flex flex-wrap gap-2">
        <button id={`move-up-${criterion.key}`} type="button" className="btn" disabled={index === 0} onClick={() => onMove(-1)} aria-label={`Move up: ${title}`}>
          Move up
        </button>
        <button id={`move-down-${criterion.key}`} type="button" className="btn" disabled={index === count - 1} onClick={() => onMove(1)} aria-label={`Move down: ${title}`}>
          Move down
        </button>
        <button type="button" className="btn btn-danger" onClick={onRemove} aria-label={`Remove: ${title}`}>
          Remove
        </button>
      </div>
    </fieldset>
  );
}

function CheckParams({ criterion, path, set, problem }) {
  const ignoreCaseId = `field-${path('checkParams.ignoreCase')}`;
  const ignoreCase = (
    <div className="field flex-row items-center gap-2">
      <input id={ignoreCaseId} type="checkbox" className="size-6" checked={criterion.ignoreCase} onChange={set('ignoreCase')} />
      <label htmlFor={ignoreCaseId}>Ignore case</label>
    </div>
  );

  switch (criterion.checkType) {
    case 'exact_match':
      return (
        <>
          <p className="hint mb-4">
            Passes when the answer equals the case’s reference, ignoring spaces at both ends. A case without a reference gets a check error, not a fail.
          </p>
          {ignoreCase}
        </>
      );
    case 'contains':
    case 'not_contains':
      return (
        <>
          <Field name={path('checkParams.text')} label="Text" value={criterion.text} onChange={set('text')} autoComplete="off" hint="Up to 500 characters." problem={problem} />
          {ignoreCase}
        </>
      );
    case 'regex':
      return (
        <>
          <Field
            name={path('checkParams.pattern')}
            label="Pattern"
            className="font-mono"
            value={criterion.pattern}
            onChange={set('pattern')}
            autoComplete="off"
            spellCheck={false}
            hint="A regular expression (PCRE) without delimiters, such as ^Refund \d+. Up to 500 characters; it reads text as Unicode."
            problem={problem}
          />
          {ignoreCase}
        </>
      );
    case 'length':
      return (
        <>
          <p className="hint mb-2">A minimum, a maximum or both, in characters, from 0 to 50,000.</p>
          <div className="flex flex-wrap gap-x-4">
            <Field name={path('checkParams.min')} label="Minimum" type="number" min="0" max="50000" step="1" className="max-w-40" value={criterion.min} onChange={set('min')} problem={problem} />
            <Field name={path('checkParams.max')} label="Maximum" type="number" min="0" max="50000" step="1" className="max-w-40" value={criterion.max} onChange={set('max')} problem={problem} />
          </div>
        </>
      );
    default:
      return <p className="hint mb-4">Passes when the whole answer is valid JSON.</p>;
  }
}

export async function rubricLoader({ params, request }) {
  const url = new URL(request.url);

  return {
    rubric: await api(`/api/projects/${params.projectId}/rubrics/${params.rubricId}`, { request }),
    status: ['created', 'saved', 'duplicated'].find((name) => url.searchParams.has(name)),
  };
}

export async function rubricAction({ params, request }) {
  const form = await request.formData();
  const path = `/api/projects/${params.projectId}/rubrics/${params.rubricId}`;
  switch (form.get('intent')) {
    case 'duplicate':
      return attempt(
        async () => {
          const copy = await api(`${path}/duplicate`, { method: 'POST', body: { name: form.get('name') ?? '' }, request });

          return redirect(`/projects/${params.projectId}/rubrics/${copy.id}?duplicated=1`);
        },
        { form: 'duplicate' },
      );
    case 'delete':
      return attempt(
        async () => {
          await api(path, { method: 'DELETE', request });

          return redirect(`/projects/${params.projectId}/rubrics?deleted=1`);
        },
        { form: 'delete' },
      );
    default:
      throw new Response('Unknown form', { status: 400 });
  }
}

/** A rubric read-only, for every member (UX §3.3), with Edit, Duplicate and Delete for the owner. */
export function Rubric() {
  const { rubric, status } = useLoaderData();
  const project = useRouteLoaderData('project');
  const result = useActionData();
  const owner = canWrite(project);
  const name = <bdi>{rubric.name}</bdi>;
  const messages = {
    created: <>Rubric {name} created.</>,
    saved: 'Changes saved.',
    duplicated: <>Copy created: {name}. You can edit it.</>,
  };
  usePageTitle(rubric.name, project.name);

  return (
    <>
      <Breadcrumb items={crumbs(project, { label: rubric.name })} />
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <h1 className="mb-0">{rubric.name}</h1>
        {owner && !rubric.lockedAt && (
          <Link className="btn btn-primary" to="edit">
            Edit rubric
          </Link>
        )}
      </div>
      <StatusMessage message={messages[status]} />
      {rubric.lockedAt && (
        <p className="notice">{owner ? 'Used in an evaluation: duplicate it to change it.' : 'Locked: used in an evaluation, so it no longer changes.'}</p>
      )}
      {rubric.duplicatedFrom && (
        <p className="muted">
          A copy of{' '}
          <Link to={`/projects/${project.id}/rubrics/${rubric.duplicatedFrom.id}`}>
            <bdi>{rubric.duplicatedFrom.name}</bdi>
          </Link>
          .
        </p>
      )}

      <section aria-labelledby="criteria-heading" className="mb-8">
        <h2 id="criteria-heading">Criteria</h2>
        <ol className="grid gap-3">
          {rubric.criteria.map((criterion, index) => (
            <li key={criterion.id} className="card">
              <h3 className="mb-2 text-base">
                {index + 1}. <bdi>{criterion.name}</bdi>
              </h3>
              <dl className="mb-0 grid gap-x-6 gap-y-1 sm:grid-cols-[max-content_1fr]">
                <dt className="font-semibold">Kind</dt>
                <dd>{KINDS[criterion.kind]}</dd>
                {criterion.kind === 'automatic' && (
                  <>
                    <dt className="font-semibold">Check</dt>
                    <dd className="break-words">{checkText(criterion)}</dd>
                  </>
                )}
                {criterion.guidance !== '' && (
                  <>
                    <dt className="font-semibold">Guidance</dt>
                    <dd className="prewrap">{criterion.guidance}</dd>
                  </>
                )}
                <dt className="font-semibold">Weight</dt>
                <dd>{criterion.weight === null ? '1 (default)' : n(criterion.weight)}</dd>
              </dl>
            </li>
          ))}
        </ol>
      </section>

      {owner && <DuplicateRubric key={`duplicate-${rubric.id}`} rubric={rubric} problem={result?.form === 'duplicate' ? result.problem : undefined} />}
      {owner && (
        <ConfirmDelete
          key={`delete-${rubric.id}-${result?.form === 'delete' ? JSON.stringify(result.problem) : ''}`}
          heading="Delete the rubric"
          button="Delete rubric"
          question={
            <>
              Delete the rubric “<bdi>{rubric.name}</bdi>” with its {criteriaCount(rubric.criteria.length)}? This cannot be undone.
            </>
          }
          problem={result?.form === 'delete' ? result.problem : undefined}
        />
      )}
    </>
  );
}

/** "<name> (copy)", within the 200 characters of a name, as the API names it by default. */
const copyName = (name) => `${[...name].slice(0, 193).join('')} (copy)`;

function DuplicateRubric({ rubric, problem }) {
  const submitting = useNavigation().state === 'submitting';

  return (
    <section className="mb-8 max-w-xl">
      <h2>Duplicate the rubric</h2>
      <p>The copy has the same criteria and is not used in any evaluation, so you can edit it.</p>
      <ErrorSummary problem={problem} idPrefix="duplicate" />
      <Form method="post" noValidate>
        <input type="hidden" name="intent" value="duplicate" />
        <Field idPrefix="duplicate" name="name" label="Name of the copy" defaultValue={copyName(rubric.name)} required autoComplete="off" problem={problem} />
        <button type="submit" className="btn" disabled={submitting}>
          Duplicate
        </button>
      </Form>
    </section>
  );
}
