import { useMemo, useState } from 'react';
import { flushSync } from 'react-dom';
import { Form, Link, redirect, useActionData, useLoaderData, useNavigation, useRouteLoaderData, useSubmit } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ConfirmDelete, ErrorSummary, Field, StatusMessage } from '../components/forms.jsx';
import { Icon } from '../components/icons.jsx';
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
const KINDS = { scale_1_5: 'Scale 1–5', pass_fail: 'Pass or fail', automatic: 'Automatic check' };
const CHECKS = {
  exact_match: 'Exact match with the reference',
  contains: 'Contains a text',
  not_contains: 'Does not contain a text',
  regex: 'Matches a regular expression',
  length: 'Length limits',
  valid_json: 'Valid JSON',
};
/** The short name of a check, after "Automatic · ". */
const CHECK_NAMES = { exact_match: 'exact match', contains: 'contains', not_contains: 'does not contain', regex: 'regex', length: 'length', valid_json: 'valid JSON' };
const GUIDANCE_HINTS = {
  scale_1_5: 'Required: what 1 and 5 mean, and how to choose between them.',
  pass_fail: 'Required: when an answer passes.',
  automatic: 'Optional: what the check is for.',
};

/** "Scale 1–5", "Automatic · regex": a criterion's kind, as its badge says it. */
const kindName = (kind, checkType) => (kind === 'automatic' ? `Automatic · ${CHECK_NAMES[checkType] ?? 'check'}` : KINDS[kind]);

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
          Regex:{' '}
          <code>
            <bdi>{params.pattern}</bdi>
          </code>
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
      <div className="page-head">
        <div>
          <h1 className="page-title">Rubrics</h1>
          <p className="page-meta">The criteria that answers are scored on.</p>
        </div>
        {/* With no rubrics yet, the empty state has the one button. */}
        {canWrite(project) && list.total > 0 && (
          <div className="actions">
            <Link className="btn btn-primary" to="new">
              <Icon name="plus" />
              New rubric
            </Link>
          </div>
        )}
      </div>
      <StatusMessage message={deleted ? 'The rubric was deleted.' : undefined} />

      {list.total === 0 && <EmptyRubrics project={project} />}
      {list.total > 0 && (
        <div className="table-box" role="region" aria-label="Rubrics" tabIndex={0}>
          <table className="tbl" style={{ minWidth: 560 }}>
            <thead>
              <tr>
                <th scope="col">Rubric</th>
                <th scope="col" className="num">
                  Criteria
                </th>
                <th scope="col">State</th>
              </tr>
            </thead>
            <tbody>
              {list.items.map((rubric) => (
                <tr key={rubric.id}>
                  <th scope="row">
                    <Link className="inline-flex min-h-6 items-center" to={rubric.id}>
                      <bdi>{rubric.name}</bdi>
                    </Link>
                  </th>
                  <td className="num mono">{n(rubric.criterionCount)}</td>
                  <td>
                    {/* The state in words, with the lock as a picture only. */}
                    {rubric.lockedAt ? (
                      <span className="pill pill-closed">
                        <Icon name="lock" />
                        Locked: used in an evaluation
                      </span>
                    ) : (
                      <span className="small muted">Can be edited</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pager list={list} noun="Rubrics" />
    </>
  );
}

function EmptyRubrics({ project }) {
  if (!canWrite(project)) {
    return <p>{project.role === 'owner' ? 'No rubrics.' : 'The owner creates rubrics for this project.'}</p>;
  }

  return (
    <div className="empty">
      <span className="empty-icon">
        <Icon name="layers" size="lg" />
      </span>
      <h2 className="empty-title">Create your first rubric</h2>
      <p>A rubric lists the criteria that answers are scored on: by people, from 1 to 5 or pass or fail, or by an automatic check.</p>
      <div className="actions">
        <Link className="btn btn-primary" to="new">
          <Icon name="plus" />
          New rubric
        </Link>
      </div>
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
    <>
      <Breadcrumb items={crumbs(project, { label: 'New rubric' })} />
      <div className="page-head">
        <div>
          <h1 className="page-title">New rubric</h1>
          <p className="page-meta">Criteria are scored by people, from 1 to 5 or pass or fail, or by an automatic check.</p>
        </div>
      </div>
      {canWrite(project) ? <RubricEditor cancelTo={`/projects/${project.id}/rubrics`} /> : <p>Only the owners of an active project create rubrics.</p>}
    </>
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
    <>
      <Breadcrumb items={crumbs(project, { label: rubric.name, to: `/projects/${project.id}/rubrics/${rubric.id}` }, { label: 'Edit' })} />
      <div className="page-head">
        <div>
          <h1 className="page-title">
            Edit <bdi>{rubric.name}</bdi>
          </h1>
          <p className="page-meta">No evaluation uses this rubric yet, so it can still change. Once one does, it can only be duplicated.</p>
        </div>
      </div>
      {canWrite(project) ? (
        <RubricEditor key={rubric.id} rubric={rubric} cancelTo={`/projects/${project.id}/rubrics/${rubric.id}`} />
      ) : (
        <p>Only the owners of an active project edit rubrics.</p>
      )}
    </>
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
 * New rubric and Edit (UX §3.3, Graphite proposal 2): an outline of the criteria beside the form, the name, then the
 * criteria in order, and a save bar fixed at the bottom. Move up and Move down reorder them without dragging (WCAG
 * 2.5.7), and the focus stays on the moved criterion. Save sends the whole rubric; an error shows in the summary, next
 * to its field and in the outline, and every field keeps what was typed (WCAG 3.3.7).
 */
function RubricEditor({ rubric, cancelTo }) {
  const [name, setName] = useState(rubric?.name ?? '');
  const [criteria, setCriteria] = useState(() => (rubric?.criteria ?? []).map(editable));
  const [announcement, setAnnouncement] = useState();
  const result = useActionData();
  const submit = useSubmit();
  const submitting = useNavigation().state === 'submitting';
  const problem = useMemo(() => byKey(result?.problem, result?.keys ?? []), [result]);
  const listErrors = (problem?.errors ?? []).filter((error) => error.field === 'criteria');
  const hasError = (key) => (problem?.errors ?? []).some((error) => error.field.startsWith(`criteria.${key}.`) || error.field === `criteria.${key}`);

  const title = (criterion, index) => criterion.name.trim() || `Criterion ${index + 1}`;
  const change = (key, changes) => setCriteria((list) => list.map((criterion) => (criterion.key === key ? { ...criterion, ...changes } : criterion)));

  function add() {
    const criterion = { ...BLANK, key: newKey() };
    flushSync(() => {
      setCriteria([...criteria, criterion]);
      setAnnouncement(`Criterion ${criteria.length + 1} added.`);
    });
    // The new criterion's kind comes first: the focus goes to its chosen option.
    document.querySelector(`input[name="criteria.${criterion.key}.kind"]:checked`)?.focus();
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
    <form onSubmit={save} noValidate>
      <div className="editor-grid">
        <nav className="crit-outline" aria-label="Criteria in this rubric">
          <p className="small muted px-2 pb-1.5 font-medium">Criteria</p>
          {criteria.map((criterion, index) => (
            <a key={criterion.key} href={`#crit-${criterion.key}`} className={hasError(criterion.key) ? 'is-error' : undefined}>
              <span className="crit-n" aria-hidden="true">
                {index + 1}
              </span>
              <bdi>{title(criterion, index)}</bdi>
              {hasError(criterion.key) && (
                <>
                  <Icon name="alert" />
                  <span className="sr-only">(has an error)</span>
                </>
              )}
            </a>
          ))}
        </nav>

        <div className="flex min-w-0 flex-col gap-6" style={{ maxWidth: 780 }}>
          <ErrorSummary problem={problem} />
          <Field
            name="name"
            label="Rubric name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            required
            autoComplete="off"
            hint="Up to 200 characters, unique in the project."
            className="max-w-[480px]"
            problem={problem}
          />

          <div>
            <div className="section-head mb-1">
              <h2 className="section-title">Criteria</h2>
              <p className="section-hint">
                {criteria.length} of at most {MAX_CRITERIA}
              </p>
            </div>
            <p className="hint">In the order evaluators see them. A rubric needs at least one.</p>
            <StatusMessage message={announcement} />
          </div>

          {criteria.length > 0 && (
            <ol className="flex flex-col gap-4">
              {criteria.map((criterion, index) => (
                <li key={criterion.key}>
                  <CriterionFields
                    criterion={criterion}
                    index={index}
                    count={criteria.length}
                    title={title(criterion, index)}
                    problem={problem}
                    hasError={hasError(criterion.key)}
                    onChange={(changes) => change(criterion.key, changes)}
                    onMove={(step) => move(index, step)}
                    onRemove={() => remove(index)}
                  />
                </li>
              ))}
            </ol>
          )}
          <div>
            {listErrors.length > 0 && (
              <p id="criteria-error" className="field-error mb-2">
                <Icon name="alert" />
                <span>
                  <span className="sr-only">Error: </span>
                  {listErrors.map((error) => error.message).join(' ')}
                </span>
              </p>
            )}
            <button
              id="field-criteria"
              type="button"
              className="btn btn-dashed"
              onClick={add}
              disabled={criteria.length >= MAX_CRITERIA}
              aria-describedby={listErrors.length > 0 ? 'criteria-error' : undefined}
            >
              <Icon name="plus" />
              Add criterion
            </button>
          </div>
        </div>
      </div>

      <div className="savebar">
        <p className="small muted">Saving checks every criterion. Nothing changes until it passes.</p>
        <div className="actions">
          <Link className="btn" to={cancelTo}>
            Cancel
          </Link>
          <button type="submit" className="btn btn-primary" disabled={submitting}>
            Save rubric
          </button>
        </div>
      </div>
    </form>
  );
}

/** One criterion's fields in a fieldset (WCAG 1.3.1), with its Move and Remove buttons. */
function CriterionFields({ criterion, index, count, title, problem, hasError, onChange, onMove, onRemove }) {
  const path = (field) => `criteria.${criterion.key}.${field}`;
  const set = (field) => (event) => onChange({ [field]: event.target.type === 'checkbox' ? event.target.checked : event.target.value });
  const named = criterion.name.trim();
  const kindId = `field-${path('kind')}`;
  const weight = (
    <Field
      name={path('weight')}
      label={
        <>
          Weight <span className="opt">(optional)</span>
        </>
      }
      type="number"
      inputMode="decimal"
      step="any"
      min="0"
      max="100"
      style={{ maxWidth: 120 }}
      value={criterion.weight}
      onChange={set('weight')}
      hint="Above 0 and at most 100. Empty counts as 1."
      problem={problem}
    />
  );

  return (
    <fieldset className={hasError ? 'crit has-error' : 'crit'} id={`crit-${criterion.key}`}>
      <legend>
        <span className="crit-n" aria-hidden="true">
          {index + 1}
        </span>
        <span className="crit-title">
          <span className="sr-only">
            Criterion {index + 1}
            {named && ': '}
          </span>
          {named ? <bdi>{named}</bdi> : <span className="muted">No name yet</span>}
        </span>
        <span className="kind">{kindName(criterion.kind, criterion.checkType)}</span>
      </legend>
      {/* The names say which criterion, after the visible words (WCAG 2.5.3): "Move up: Tone". */}
      <div className="crit-tools">
        <button id={`move-up-${criterion.key}`} type="button" className="btn btn-ghost btn-sm" disabled={index === 0} onClick={() => onMove(-1)} aria-label={`Move up: ${title}`}>
          <Icon name="arrow-up" />
          Move up
        </button>
        <button id={`move-down-${criterion.key}`} type="button" className="btn btn-ghost btn-sm" disabled={index === count - 1} onClick={() => onMove(1)} aria-label={`Move down: ${title}`}>
          <Icon name="arrow-down" />
          Move down
        </button>
        <button type="button" className="btn btn-ghost btn-sm text-danger" onClick={onRemove} aria-label={`Remove: ${title}`}>
          <Icon name="trash" />
          Remove
        </button>
      </div>
      <div className="crit-body">
        {/* Native radios in a segmented control; an error on the kind links to the group. */}
        <fieldset className="field span-2" id={kindId} tabIndex={-1}>
          <legend className="label mb-1.5">Kind</legend>
          <div className="seg">
            {Object.entries(KINDS).map(([value, text]) => (
              <label key={value}>
                <input type="radio" name={path('kind')} value={value} checked={criterion.kind === value} onChange={set('kind')} />
                {text}
              </label>
            ))}
          </div>
        </fieldset>
        <Field name={path('name')} label="Name" value={criterion.name} onChange={set('name')} autoComplete="off" hint="Up to 200 characters, on one line." problem={problem} />
        {criterion.kind !== 'automatic' && weight}
        {/* Automatic: Name beside Check, then the check's parameters (Minimum beside Maximum), then Weight. */}
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
            {weight}
          </>
        )}
        <Field
          name={path('guidance')}
          label={
            criterion.kind === 'automatic' ? (
              <>
                Guidance <span className="opt">(optional)</span>
              </>
            ) : (
              'Guidance'
            )
          }
          as="textarea"
          className="span-2"
          value={criterion.guidance}
          onChange={set('guidance')}
          hint={`${GUIDANCE_HINTS[criterion.kind]} Up to 5,000 characters.`}
          problem={problem}
        />
      </div>
    </fieldset>
  );
}

function CheckParams({ criterion, path, set, problem }) {
  const ignoreCaseId = `field-${path('checkParams.ignoreCase')}`;
  const ignoreCase = (
    <label className="check" htmlFor={ignoreCaseId}>
      <input id={ignoreCaseId} type="checkbox" checked={criterion.ignoreCase} onChange={set('ignoreCase')} />
      Ignore case
    </label>
  );

  switch (criterion.checkType) {
    case 'exact_match':
      return (
        <div className="span-2 flex flex-col gap-2">
          <p className="hint">Passes when the answer equals the case’s reference, ignoring spaces at both ends. A case without a reference gets a check error, not a fail.</p>
          {ignoreCase}
        </div>
      );
    case 'contains':
    case 'not_contains':
      return (
        <div className="span-2 flex flex-col gap-2">
          <Field name={path('checkParams.text')} label="Text" value={criterion.text} onChange={set('text')} autoComplete="off" hint="Up to 500 characters." problem={problem} />
          {ignoreCase}
        </div>
      );
    case 'regex':
      return (
        <div className="span-2 flex flex-col gap-2">
          <Field
            name={path('checkParams.pattern')}
            label="Pattern"
            controlClassName="input mono"
            value={criterion.pattern}
            onChange={set('pattern')}
            autoComplete="off"
            spellCheck={false}
            hint="A regular expression (PCRE) without delimiters, such as ^Refund \d+. Up to 500 characters; it reads text as Unicode."
            problem={problem}
          />
          {ignoreCase}
        </div>
      );
    case 'length':
      return (
        <>
          <Field
            name={path('checkParams.min')}
            label="Minimum"
            type="number"
            min="0"
            max="50000"
            step="1"
            controlClassName="input mono"
            style={{ maxWidth: 160 }}
            value={criterion.min}
            onChange={set('min')}
            problem={problem}
          />
          <Field
            name={path('checkParams.max')}
            label="Maximum"
            type="number"
            min="0"
            max="50000"
            step="1"
            controlClassName="input mono"
            style={{ maxWidth: 160 }}
            value={criterion.max}
            onChange={set('max')}
            problem={problem}
          />
          <p className="hint span-2 -mt-2">A minimum, a maximum or both: characters of the answer, from 0 to 50,000.</p>
        </>
      );
    default:
      return <p className="hint span-2">Passes when the whole answer is valid JSON.</p>;
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
  const automatic = rubric.criteria.filter((criterion) => criterion.kind === 'automatic').length;
  usePageTitle(rubric.name, project.name);

  return (
    <div className="mx-auto max-w-[960px]">
      <Breadcrumb items={crumbs(project, { label: rubric.name })} />
      <div className="page-head">
        <div>
          <h1 className="page-title">{name}</h1>
          <p className="page-meta">
            <span>{criteriaCount(rubric.criteria.length)}</span>
            <span className="dot">{rubric.criteria.length - automatic} manual</span>
            <span className="dot">{automatic} automatic</span>
          </p>
        </div>
        {owner && !rubric.lockedAt && (
          <div className="actions">
            <Link className="btn btn-primary" to="edit">
              <Icon name="pen" />
              Edit rubric
            </Link>
          </div>
        )}
      </div>
      <StatusMessage message={messages[status]} />
      {rubric.lockedAt && (
        <div className="notice mb-6">
          <Icon name="lock" />
          <p>{owner ? 'Used in an evaluation: duplicate it to change it.' : 'Locked: used in an evaluation, so it no longer changes.'}</p>
        </div>
      )}
      {rubric.duplicatedFrom && (
        <p className="muted mb-4">
          A copy of{' '}
          <Link to={`/projects/${project.id}/rubrics/${rubric.duplicatedFrom.id}`}>
            <bdi>{rubric.duplicatedFrom.name}</bdi>
          </Link>
          .
        </p>
      )}

      <section>
        <div className="section-head">
          <h2 className="section-title" id="criteria-heading">
            Criteria
          </h2>
          <p className="section-hint">In the order evaluators see them.</p>
        </div>
        <ol className="stack" style={{ gap: 12 }}>
          {rubric.criteria.map((criterion, index) => (
            <li key={criterion.id} className="card">
              <div className="card-head">
                <h3 className="card-title flex items-center gap-2.5">
                  <span className="crit-n" aria-hidden="true">
                    {index + 1}
                  </span>
                  <span className="sr-only">{index + 1}. </span>
                  <bdi>{criterion.name}</bdi>
                </h3>
                <span className="kind">{kindName(criterion.kind, criterion.checkType)}</span>
              </div>
              <dl className="kv card-body">
                {criterion.kind === 'automatic' && (
                  <>
                    <dt>Check</dt>
                    <dd>{checkText(criterion)}</dd>
                  </>
                )}
                {criterion.guidance !== '' && (
                  <>
                    <dt>Guidance</dt>
                    <dd className="prewrap">{criterion.guidance}</dd>
                  </>
                )}
                <dt>Weight</dt>
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
          note="Possible only while no evaluation uses it."
          question={
            <>
              Delete the rubric “<bdi>{rubric.name}</bdi>” with its {criteriaCount(rubric.criteria.length)}? This cannot be undone.
            </>
          }
          problem={result?.form === 'delete' ? result.problem : undefined}
        />
      )}
    </div>
  );
}

/** "<name> (copy)", within the 200 characters of a name, as the API names it by default. */
const copyName = (name) => `${[...name].slice(0, 193).join('')} (copy)`;

function DuplicateRubric({ rubric, problem }) {
  const submitting = useNavigation().state === 'submitting';

  return (
    <Form method="post" noValidate className="card section" aria-labelledby="duplicate-heading">
      <input type="hidden" name="intent" value="duplicate" />
      <div className="card-head">
        <h2 className="card-title" id="duplicate-heading">
          Duplicate the rubric
        </h2>
      </div>
      <div className="card-body stack">
        <ErrorSummary problem={problem} idPrefix="duplicate" />
        <p className="muted">The copy has the same criteria and is not used in any evaluation, so you can edit it.</p>
        <Field idPrefix="duplicate" name="name" label="Name of the copy" defaultValue={copyName(rubric.name)} required autoComplete="off" className="max-w-[480px]" problem={problem} />
      </div>
      <div className="card-foot">
        <button type="submit" className="btn" disabled={submitting}>
          <Icon name="copy" />
          Duplicate
        </button>
      </div>
    </Form>
  );
}
