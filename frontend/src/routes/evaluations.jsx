import { useEffect, useState } from 'react';
import { flushSync } from 'react-dom';
import { Form, Link, redirect, useActionData, useLoaderData, useNavigation, useRevalidator, useRouteLoaderData } from 'react-router';
import { api } from '../api/client.js';
import { attempt, ConfirmDelete, ErrorSummary, Field, StatusMessage } from '../components/forms.jsx';
import { Icon } from '../components/icons.jsx';
import { Breadcrumb, Pager, usePageTitle } from '../components/layout.jsx';

const n = (number) => number.toLocaleString('en');
const dayMonth = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' });
const fullDate = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });
const plural = (count, word) => `${n(count)} ${word}${count === 1 ? '' : 's'}`;
const criteria = (count, kind = '') => `${n(count)} ${kind}${count === 1 ? 'criterion' : 'criteria'}`;
const versionName = (version) => `${version.dataset.name} v${version.number}`;
/** How often the evaluation page asks for the automatic checks while they are queued or running (UX §3.4: 5 s at most). */
export const POLL_MS = 5000;

export const isOwner = (project) => project.role === 'owner' && !project.archivedAt;
/** Owners and evaluators score open evaluations; an archived project takes no writes. */
export const canScore = (project, evaluation) => ['owner', 'evaluator'].includes(project.role) && !project.archivedAt && evaluation.status === 'open';
/** An evaluator sees no results of a blind evaluation that is still open (architecture §6). */
export const resultsLocked = (project, evaluation) => project.role === 'evaluator' && evaluation.blind && evaluation.status === 'open';
const hasManualScoring = (evaluation) => evaluation.progress.total > 0;
const activeJob = (evaluation) => ['queued', 'running'].includes(evaluation.checkJob?.status);

export const evaluationPath = (project, evaluation) => `/projects/${project.id}/evaluations/${evaluation.id}`;
export const crumbs = (project, ...rest) => [
  { label: 'Projects', to: '/projects' },
  { label: project.name, to: `/projects/${project.id}` },
  { label: 'Evaluations', to: `/projects/${project.id}/evaluations` },
  ...rest,
];

const RUN_CLASSES = { A: 'run run-a', B: 'run run-b' };

/** A run's letter and label: A is solid blue, B striped orange, the others plain (never the colour alone). */
export function RunName({ run, small }) {
  return (
    <span className={RUN_CLASSES[run.letter] ?? 'run'}>
      <span className="run-letter">{run.letter}</span>
      <bdi className={small ? 'mono small' : 'mono'}>{run.label}</bdi>
    </span>
  );
}

/** Open or "Closed 2 Nov" ("Closed" where a column is narrow), and Blind: words with the icon or dot. */
export function StatePills({ evaluation, long, date = true }) {
  return (
    <>
      {evaluation.status === 'open' ? (
        <span className="pill pill-open">
          <span className="dot" />
          Open
        </span>
      ) : (
        <span className="pill pill-closed">
          <Icon name="lock" />
          Closed{date && ` ${dayMonth.format(new Date(evaluation.closedAt))}`}
        </span>
      )}
      {evaluation.blind && (
        <span className="pill pill-blind">
          <Icon name="eye-off" />
          {long ? 'Blind mode' : 'Blind'}
        </span>
      )}
    </>
  );
}

/** "296 of 424 answers scored" and the bar, which only repeats the words (aria-hidden). */
export function AnswersProgress({ evaluation }) {
  const { scored, total } = evaluation.answers;

  return (
    <div className="stack" style={{ gap: 6 }}>
      <span className="small">
        <b className="mono">{n(scored)}</b> of <span className="mono">{n(total)}</span> answers scored
      </span>
      <div className="meter" aria-hidden="true">
        <span style={{ width: `${total ? Math.round((100 * scored) / total) : 0}%` }} />
      </div>
    </div>
  );
}

/**
 * Start or Continue scoring, for whoever may score this evaluation; when there is nothing to score (no manual criteria,
 * none missing, closed, or a viewer), `fallback`, such as the Results link.
 */
export function ScoreLink({ project, evaluation, className = 'btn btn-sm', fallback = null }) {
  if (!canScore(project, evaluation) || !hasManualScoring(evaluation) || evaluation.answers.scored === evaluation.answers.total) {
    return fallback;
  }
  const started = evaluation.progress.scored > 0;

  return (
    <Link className={className} to={`${evaluationPath(project, evaluation)}/score`}>
      <Icon name="play" />
      {started ? 'Continue scoring' : 'Start scoring'}
    </Link>
  );
}

/** Results, for whoever may read them now: not an evaluator while a blind evaluation is open. */
export function ResultsLink({ project, evaluation, className = 'btn btn-sm', children = 'Results' }) {
  return resultsLocked(project, evaluation) ? null : (
    <Link className={className} to={`${evaluationPath(project, evaluation)}/results`}>
      {children}
    </Link>
  );
}

export async function evaluationsLoader({ params, request }) {
  const url = new URL(request.url);
  const list = await api(`/api/projects/${params.projectId}/evaluations?page=${url.searchParams.get('page') ?? '1'}`, { request });

  return { list, deleted: url.searchParams.has('deleted') };
}

export function Evaluations() {
  const { list, deleted } = useLoaderData();
  const project = useRouteLoaderData('project');
  usePageTitle('Evaluations', project.name);

  return (
    <>
      <Breadcrumb items={crumbs(project).slice(0, 2).concat({ label: 'Evaluations' })} />
      <div className="page-head">
        <div>
          <h1 className="page-title">Evaluations</h1>
          <p className="page-meta">A rubric applied to the runs of one dataset version.</p>
        </div>
        {isOwner(project) && list.total > 0 && (
          <div className="actions">
            <Link className="btn btn-primary" to="new">
              <Icon name="plus" />
              New evaluation
            </Link>
          </div>
        )}
      </div>
      <StatusMessage message={deleted ? 'The evaluation was deleted.' : undefined} />

      {list.total === 0 ? (
        <div className="empty">
          <span className="empty-icon">
            <Icon name="layers" size="lg" />
          </span>
          <h2 className="empty-title">{isOwner(project) ? 'Start your first evaluation' : 'No evaluations yet'}</h2>
          <p>{isOwner(project) ? 'An evaluation applies a rubric to the runs of one dataset version, and people score their answers.' : 'The owner starts evaluations for this project.'}</p>
          {isOwner(project) && (
            <div className="actions">
              <Link className="btn btn-primary" to="new">
                <Icon name="plus" />
                New evaluation
              </Link>
            </div>
          )}
        </div>
      ) : (
        <>
          <div className="table-box" role="region" aria-label="Evaluations" tabIndex={0}>
            <table className="tbl" style={{ minWidth: 860 }}>
              <thead>
                <tr>
                  <th scope="col">Evaluation</th>
                  <th scope="col">Runs</th>
                  <th scope="col" style={{ width: 230 }}>
                    Progress
                  </th>
                  <th scope="col">State</th>
                  <th scope="col">
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {list.items.map((evaluation) => (
                  <tr key={evaluation.id}>
                    <th scope="row" style={{ fontWeight: 400 }}>
                      <Link className="inline-flex min-h-6 items-center" to={evaluation.id}>
                        <bdi>{evaluation.name}</bdi>
                      </Link>
                      <br />
                      <span className="small muted">
                        <bdi>{versionName(evaluation.datasetVersion)}</bdi> · <bdi>{evaluation.rubric.name}</bdi>
                      </span>
                    </th>
                    <td>
                      <div className="stack" style={{ gap: 4, alignItems: 'flex-start' }}>
                        {evaluation.runs.map((run) => (
                          <RunName key={run.letter} run={run} small />
                        ))}
                      </div>
                    </td>
                    <td>
                      <AnswersProgress evaluation={evaluation} />
                    </td>
                    <td>
                      <div className="stack" style={{ gap: 6, alignItems: 'flex-start' }}>
                        <StatePills evaluation={evaluation} />
                      </div>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <ScoreLink project={project} evaluation={evaluation} fallback={<ResultsLink project={project} evaluation={evaluation} />} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pager list={list} noun="Evaluations" />
        </>
      )}
    </>
  );
}

/** Every version, run and rubric of the project, to choose from (MVP limits: far below 100 of each in practice). */
export async function newEvaluationLoader({ params, request }) {
  const base = `/api/projects/${params.projectId}`;
  const [versions, runs, rubrics] = await Promise.all(['dataset-versions', 'runs', 'rubrics'].map((list) => api(`${base}/${list}?perPage=100`, { request })));

  return { versions: versions.items, runs: runs.items, rubrics: rubrics.items };
}

/** The runs go in the order of the list, which gives their letters (A, B…), as the API assigns them. */
export async function newEvaluationAction({ params, request }) {
  const form = await request.formData();
  const runs = form.getAll('runs');

  return attempt(
    async () => {
      const evaluation = await api(`/api/projects/${params.projectId}/evaluations`, {
        method: 'POST',
        body: { name: form.get('name'), datasetVersion: form.get('datasetVersion'), runs, rubric: form.get('rubric'), blind: form.has('blind') },
        request,
      });

      return redirect(`/projects/${params.projectId}/evaluations/${evaluation.id}?created=1`);
    },
    { runs },
  );
}

export function NewEvaluation() {
  const { versions, runs, rubrics } = useLoaderData();
  const project = useRouteLoaderData('project');
  const result = useActionData();
  const submitting = useNavigation().state === 'submitting';
  const [versionId, setVersionId] = useState(versions[0]?.id ?? '');
  const [chosen, setChosen] = useState(() => new Set());
  const [rubricId, setRubricId] = useState(rubrics[0]?.id ?? '');
  usePageTitle('New evaluation', project.name);

  // Oldest first, as they were imported: a baseline usually comes before what it is compared with, and gets A.
  const versionRuns = runs.filter((run) => run.datasetVersion.id === versionId).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
  const picked = versionRuns.filter((run) => chosen.has(run.id));
  const letterOf = (run) => 'ABCDE'[picked.indexOf(run)];
  const rubric = rubrics.find((item) => item.id === rubricId);
  const version = versions.find((item) => item.id === versionId);
  const problem = result?.problem;
  // runs[N] errors name the Nth run submitted: shown next to that run, by its label.
  const runErrors = (problem?.errors ?? []).filter((error) => /^runs(\[\d+\])?$/.test(error.field));
  const submitted = result?.runs ?? [];
  const errorOfRun = (run) => runErrors.filter((error) => error.field === `runs[${submitted.indexOf(run.id)}]`);

  if (!isOwner(project)) {
    return <p>Only the owners of an active project start evaluations.</p>;
  }

  return (
    <div className="mx-auto max-w-[832px]">
      <Breadcrumb items={crumbs(project, { label: 'New evaluation' })} />
      <div className="page-head">
        <div>
          <h1 className="page-title">New evaluation</h1>
          <p className="page-meta">Score the runs of one dataset version against a rubric. The rubric is locked once the evaluation starts.</p>
        </div>
      </div>
      {versions.length === 0 || rubrics.length === 0 ? (
        <p>
          An evaluation needs {versions.length === 0 ? 'a dataset with runs' : 'a rubric'}.{' '}
          {versions.length === 0 ? <Link to={`/projects/${project.id}/datasets/new`}>Import a dataset</Link> : <Link to={`/projects/${project.id}/rubrics/new`}>Create a rubric</Link>} first.
        </p>
      ) : (
        <>
          <ErrorSummary problem={problem} />
          <Form method="post" noValidate className="card">
            <div className="card-body stack" style={{ gap: 22 }}>
              <Field name="name" label="Name" autoComplete="off" required hint="Up to 200 characters, unique in the project." className="max-w-[480px]" problem={problem} />
              <Field
                name="datasetVersion"
                label="Dataset version"
                as="select"
                value={versionId}
                onChange={(event) => {
                  setVersionId(event.target.value);
                  setChosen(new Set());
                }}
                hint="The runs below follow this version."
                className="max-w-[480px]"
                problem={problem}
              >
                {versions.map((item) => (
                  <option key={item.id} value={item.id}>
                    {versionName(item)} · {plural(item.caseCount, 'case')}
                  </option>
                ))}
              </Field>
              <fieldset className="field" id="field-runs" tabIndex={-1} aria-describedby="runs-hint">
                <legend className="label mb-1.5">Runs</legend>
                <p className="hint mb-1.5" id="runs-hint">
                  One to five runs of <bdi>{version ? versionName(version) : 'this version'}</bdi>. Each gets a letter, A, B, C…, in this order.
                </p>
                {runErrors
                  .filter((error) => error.field === 'runs')
                  .map((error) => (
                    <p key={error.code} className="field-error">
                      <Icon name="alert" />
                      <span>
                        <span className="sr-only">Error: </span>
                        {error.message}
                      </span>
                    </p>
                  ))}
                {versionRuns.length === 0 ? (
                  <p>
                    This version has no runs yet. <Link to={`/projects/${project.id}/runs/new?datasetVersion=${versionId}`}>Import a run</Link> first.
                  </p>
                ) : (
                  <div className="checklist">
                    {versionRuns.map((run) => {
                      const errors = errorOfRun(run);
                      const index = picked.indexOf(run);
                      const id = index >= 0 ? `field-runs[${index}]` : `run-${run.id}`;

                      return (
                        <label key={run.id} htmlFor={id}>
                          <input
                            id={id}
                            type="checkbox"
                            name="runs"
                            value={run.id}
                            checked={chosen.has(run.id)}
                            aria-invalid={errors.length > 0 || undefined}
                            aria-describedby={errors.length > 0 ? `${id}-error` : undefined}
                            onChange={(event) => {
                              const next = new Set(chosen);
                              event.target.checked ? next.add(run.id) : next.delete(run.id);
                              setChosen(next);
                            }}
                          />
                          <span style={{ flex: 1 }}>
                            <b className="mono" style={{ fontWeight: 500 }}>
                              <bdi>{run.label}</bdi>
                            </b>
                            <br />
                            <span className="small muted">
                              {run.modelDescription ? (
                                <>
                                  <bdi>{run.modelDescription.split('\n')[0]}</bdi> ·{' '}
                                </>
                              ) : null}
                              {plural(run.answerCount, 'answer')}
                            </span>
                            {errors.length > 0 && (
                              <span id={`${id}-error`} className="field-error mt-1">
                                <Icon name="alert" />
                                <span>
                                  <span className="sr-only">Error: </span>
                                  {errors.map((error) => error.message).join(' ')}
                                </span>
                              </span>
                            )}
                          </span>
                          {index >= 0 && (
                            <span className={RUN_CLASSES[letterOf(run)] ?? 'run'}>
                              <span className="run-letter">
                                <span className="sr-only">Letter </span>
                                {letterOf(run)}
                              </span>
                            </span>
                          )}
                        </label>
                      );
                    })}
                  </div>
                )}
              </fieldset>
              <Field name="rubric" label="Rubric" as="select" value={rubricId} onChange={(event) => setRubricId(event.target.value)} className="max-w-[480px]" problem={problem}>
                {rubrics.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.name} · {criteria(item.criterionCount)}
                  </option>
                ))}
              </Field>
              <label className="switch-row">
                <input type="checkbox" name="blind" defaultChecked aria-describedby="blind-hint" />
                <span>
                  <b style={{ fontWeight: 600 }}>Blind mode</b>
                  <br />
                  <span className="small muted" id="blind-hint">
                    Evaluators do not see which run wrote an answer, and the answers of each case come in a random order. It reduces bias. Evaluators cannot turn it off, and they see the results only after you close the evaluation.
                  </span>
                </span>
              </label>
            </div>
            <div className="card-foot">
              <button type="submit" className="btn btn-primary" disabled={submitting}>
                <Icon name="play" />
                Start evaluation
              </button>
              <Link className="btn btn-ghost" to={`/projects/${project.id}/evaluations`}>
                Cancel
              </Link>
              <span className="small muted" style={{ marginLeft: 'auto' }}>
                {picked.length === 0 || !rubric
                  ? 'Choose the runs to score.'
                  : `${plural(picked.reduce((sum, run) => sum + run.answerCount, 0), 'answer')} to score · ${criteria(rubric.criterionCount - rubric.automaticCount, 'manual ')} · ${n(rubric.automaticCount)} automatic`}
              </span>
            </div>
          </Form>
        </>
      )}
    </div>
  );
}

export async function evaluationLoader({ params, request }) {
  const url = new URL(request.url);
  const evaluation = await api(`/api/projects/${params.projectId}/evaluations/${params.evaluationId}`, { request });

  return { evaluation, created: url.searchParams.has('created') };
}

export async function evaluationAction({ params, request }) {
  const form = await request.formData();
  const intent = form.get('intent');
  const path = `/api/projects/${params.projectId}/evaluations/${params.evaluationId}`;

  return attempt(
    async () => {
      if (intent === 'delete') {
        await api(path, { method: 'DELETE', request });

        return redirect(`/projects/${params.projectId}/evaluations?deleted=1`);
      }
      if (intent === 'close') {
        await api(`${path}/close`, { method: 'POST', request });

        return { done: 'The evaluation is closed.' };
      }
      if (intent === 'checks') {
        await api(`${path}/automatic-checks`, { method: 'POST', request });

        return { done: 'The automatic checks run again.' };
      }
      throw new Response('Unknown form', { status: 400 });
    },
    { intent },
  );
}

/** Asks for the evaluation again every POLL_MS while its check job is queued or running, and stops when it is done. */
function usePollWhileChecking(evaluation) {
  const { revalidate, state } = useRevalidator();
  const active = activeJob(evaluation);
  useEffect(() => {
    if (!active || state !== 'idle') {
      return undefined;
    }
    const timer = setTimeout(revalidate, POLL_MS);

    return () => clearTimeout(timer);
  }, [active, evaluation, revalidate, state]);
}

/** The heading, the pills and the sub-navigation, shared by the evaluation's pages (Overview, Results). Compare joins with slice 13. */
export function EvaluationHead({ project, evaluation, current, actions }) {
  const sections = [
    ['Overview', evaluationPath(project, evaluation)],
    ['Results', `${evaluationPath(project, evaluation)}/results`],
    // Comparing needs two runs.
    ...(evaluation.runs.length > 1 ? [['Compare', `${evaluationPath(project, evaluation)}/compare`]] : []),
  ];

  return (
    <>
      <div className="page-head">
        <div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h1 className="page-title">
              <bdi>{evaluation.name}</bdi>
            </h1>
            <StatePills evaluation={evaluation} long />
          </div>
          <p className="page-meta">
            <Link to={`/projects/${project.id}/datasets/${evaluation.datasetVersion.dataset.id}?version=${evaluation.datasetVersion.id}`}>
              <bdi>{versionName(evaluation.datasetVersion)}</bdi>
            </Link>
            <span className="dot">
              Rubric{' '}
              <Link to={`/projects/${project.id}/rubrics/${evaluation.rubric.id}`}>
                <bdi>{evaluation.rubric.name}</bdi>
              </Link>
            </span>
            <span className="dot">Started {fullDate.format(new Date(evaluation.createdAt))}</span>
          </p>
        </div>
        {actions}
      </div>
      <nav className="subnav" aria-label="Evaluation">
        {sections.map(([label, to]) => (
          <Link key={label} to={to} aria-current={label === current ? 'page' : undefined}>
            {label}
          </Link>
        ))}
      </nav>
    </>
  );
}

const CHECK_STATES = { queued: 'queued', running: 'running', succeeded: 'done', failed: 'failed' };
const KINDS = { scale_1_5: 'Scale 1–5', pass_fail: 'Pass or fail' };
const CHECK_NAMES = { exact_match: 'exact match', contains: 'contains', not_contains: 'does not contain', regex: 'regex', length: 'length', valid_json: 'valid JSON' };

export function Evaluation() {
  const { evaluation, created } = useLoaderData();
  const project = useRouteLoaderData('project');
  const result = useActionData();
  usePageTitle(evaluation.name, project.name);
  usePollWhileChecking(evaluation);

  const { answers, checkJob } = evaluation;
  const missing = answers.total - answers.scored;
  const owner = isOwner(project);
  const open = evaluation.status === 'open';

  return (
    <>
      <Breadcrumb items={crumbs(project, { label: evaluation.name })} />
      <EvaluationHead
        project={project}
        evaluation={evaluation}
        current="Overview"
        actions={
          <div className="actions">
            <ScoreLink project={project} evaluation={evaluation} className="btn btn-primary" />
          </div>
        }
      />
      <StatusMessage message={result?.done ?? (created ? 'The evaluation started.' : undefined)} />

      <div className="stats">
        <div className="stat">
          <p className="stat-label">Manual scoring</p>
          {hasManualScoring(evaluation) ? (
            <>
              <p className="stat-value">
                <span>{n(answers.scored)}</span> <small>of {plural(answers.total, 'answer')}</small>
              </p>
              <div className="meter meter-lg" aria-hidden="true">
                <span style={{ width: `${answers.total ? Math.round((100 * answers.scored) / answers.total) : 0}%` }} />
              </div>
              <p className="small muted">{missing === 0 ? 'Every answer has its scores.' : `${plural(missing, 'answer')} still ${missing === 1 ? 'has' : 'have'} missing scores.`}</p>
            </>
          ) : (
            <p className="small muted">The rubric has no manual criteria: there is nothing to score by hand.</p>
          )}
        </div>
        {/* role="status": the screen reader hears the checks progress as it changes, at most every 5 s (UX §3.4). */}
        <div className="stat" role="status">
          <p className="stat-label">Automatic checks</p>
          {checkJob ? (
            <>
              <p className="stat-value">
                <span>{n(checkJob.processed)}</span>{' '}
                <small>
                  of {n(checkJob.total)} · {CHECK_STATES[checkJob.status]}
                </small>
              </p>
              <div className="meter meter-lg" aria-hidden="true">
                <span style={{ width: `${checkJob.total ? Math.round((100 * checkJob.processed) / checkJob.total) : 0}%` }} />
              </div>
              <p className="small muted">
                {activeJob(evaluation)
                  ? 'Updates every few seconds.'
                  : checkJob.status === 'failed'
                    ? 'The checks stopped on an error. Run them again.'
                    : 'Every answer is checked.'}
              </p>
            </>
          ) : (
            <p className="small muted">The rubric has no automatic criteria.</p>
          )}
        </div>
        <div className="stat">
          <p className="stat-label">Runs</p>
          <div className="stack mt-1" style={{ gap: 8 }}>
            {evaluation.runs.map((run) => (
              <RunName key={run.letter} run={run} />
            ))}
          </div>
        </div>
      </div>

      <section className="section" aria-labelledby="bycrit-heading">
        <div className="section-head">
          <h2 className="section-title" id="bycrit-heading">
            Progress by criterion
          </h2>
        </div>
        <div className="table-box" role="region" aria-labelledby="bycrit-heading" tabIndex={0}>
          <table className="tbl" style={{ minWidth: 640 }}>
            <thead>
              <tr>
                <th scope="col">Criterion</th>
                <th scope="col">Kind</th>
                <th scope="col" className="num">
                  Scored
                </th>
                <th scope="col" style={{ width: 240 }}>
                  <span className="sr-only">Progress bar</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {evaluation.criteria.map((criterion) => (
                <tr key={criterion.id}>
                  <th scope="row">
                    <bdi>{criterion.name}</bdi>
                  </th>
                  <td>
                    <span className="kind">{criterion.kind === 'automatic' ? `Automatic · ${CHECK_NAMES[criterion.checkType]}` : KINDS[criterion.kind]}</span>
                  </td>
                  <td className="num mono">
                    {n(criterion.scored)} / {n(answers.total)}
                  </td>
                  <td>
                    <div className="meter mt-[7px]" aria-hidden="true">
                      <span style={{ width: `${answers.total ? Math.round((100 * criterion.scored) / answers.total) : 0}%` }} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {checkJob && owner && open && (
          <div className="mt-3 flex flex-wrap items-center gap-3">
            <ErrorSummary problem={result?.intent === 'checks' ? result.problem : undefined} idPrefix="checks" />
            <p className="small muted" style={{ flex: '1 1 320px' }}>
              Automatic checks can be run again once they finish, for example after a case’s reference changes in a new version.
            </p>
            {!activeJob(evaluation) && (
              <Form method="post">
                <input type="hidden" name="intent" value="checks" />
                <button type="submit" className="btn btn-sm">
                  <Icon name="refresh" />
                  Run checks again
                </button>
              </Form>
            )}
          </div>
        )}
      </section>

      {owner && open && <CloseEvaluation evaluation={evaluation} problem={result?.intent === 'close' ? result.problem : undefined} />}
      {owner && (
        <ConfirmDelete
          key={result?.intent === 'delete' ? JSON.stringify(result.problem) : 'delete'}
          heading="Delete the evaluation"
          button="Delete evaluation"
          note="Deletes its scores, comments and checks. The dataset, runs and rubric stay."
          question={
            <>
              Delete “<bdi>{evaluation.name}</bdi>” with its scores and comments? This cannot be undone.
            </>
          }
          problem={result?.intent === 'delete' ? result.problem : undefined}
        />
      )}
    </>
  );
}

/** Closing asks first, on the page (UX §3.4.6); a 409 check_job_running says to wait for the checks. */
function CloseEvaluation({ evaluation, problem }) {
  const [confirming, setConfirming] = useState(false);
  const missing = evaluation.answers.total - evaluation.answers.scored;

  return (
    <section className="section card" aria-labelledby="close-heading">
      <div className="card-head">
        <h2 className="card-title" id="close-heading">
          Close the evaluation
        </h2>
      </div>
      <div className="card-body stack">
        {problem && (
          <div className="alert" role="alert">
            <Icon name="alert" />
            <div className="alert-body">
              <p className="alert-title">Not closed.</p>
              <p>
                {problem.code === 'check_job_running'
                  ? 'The automatic checks are still queued or running, and closing would stop their scores half-way. Wait until they are done: this page updates by itself.'
                  : problem.title}
              </p>
            </div>
          </div>
        )}
        <p className="muted" style={{ maxWidth: '70ch' }}>
          Closing stops scoring for everyone, for good. {evaluation.blind && 'In blind mode, evaluators can then see the results.'}
        </p>
        {confirming ? (
          <Form method="post" className="confirm">
            <input type="hidden" name="intent" value="close" />
            <p>
              Close “<bdi>{evaluation.name}</bdi>”{missing > 0 && hasManualScoring(evaluation) ? ` with ${plural(missing, 'answer')} still unscored` : ''}?
            </p>
            <button type="submit" className="btn btn-primary btn-sm" autoFocus>
              <Icon name="lock" />
              Close evaluation
            </button>
            <button
              type="button"
              className="btn btn-sm"
              onClick={() => {
                flushSync(() => setConfirming(false));
                document.getElementById('close-start')?.focus();
              }}
            >
              Cancel
            </button>
          </Form>
        ) : (
          <div>
            <button id="close-start" type="button" className="btn" onClick={() => setConfirming(true)}>
              <Icon name="lock" />
              Close evaluation
            </button>
          </div>
        )}
      </div>
    </section>
  );
}
