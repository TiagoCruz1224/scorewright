import { Link, useLoaderData, useRouteLoaderData } from 'react-router';
import { api, ApiError } from '../api/client.js';
import { Icon } from '../components/icons.jsx';
import { Breadcrumb, usePageTitle } from '../components/layout.jsx';
import { crumbs, EvaluationHead, evaluationPath, isOwner, ScoreLink } from './evaluations.jsx';
import { scaleEnds } from './scoring.jsx';

export const n = (number) => number.toLocaleString('en');
const BARS = { A: 'bar bar-a', B: 'bar bar-b' };
const barClass = (letter) => BARS[letter] ?? 'bar bar-other';

/** A criterion's value in words: a mean to one decimal ("3.4"), a rate as a whole percent ("71%"). */
const formatValue = (measure, value) => (measure === 'mean' ? value.toFixed(1) : `${Math.round(value)}%`);
/** From 0 to 1, for a bar: a mean on 1–5, a rate or score on 0–100. */
const share = (measure, value) => (value === null ? 0 : measure === 'mean' ? value / 5 : value / 100);

/**
 * The difference with its sign written, never the colour alone: "+1.1", "−0.3", "±0.0"; for a rate, whole percentage
 * points ("+17 pts"). `decimals` is for the rest: 0 for one case's 1–5 scores ("+4").
 */
export function Difference({ measure, value, decimals = 1 }) {
  const rate = measure === 'passRate';
  const factor = rate ? 1 : 10 ** decimals;
  const rounded = Math.round(value * factor) / factor;
  const text = rate ? `${Math.abs(rounded)} pts` : Math.abs(rounded).toFixed(decimals);
  const [sign, className] = rounded > 0 ? ['+', 'diff diff-pos'] : rounded < 0 ? ['−', 'diff diff-neg'] : ['±', 'diff diff-zero'];

  return (
    <span className={className}>
      {sign}
      {text}
    </span>
  );
}

/** A value, or a dash that says there is none yet. */
export function Value({ measure, value }) {
  return value === null ? (
    <>
      <span aria-hidden="true">—</span>
      <span className="sr-only">no scores yet</span>
    </>
  ) : (
    formatValue(measure, value)
  );
}

/**
 * The results, or the locked page: the API answers 403 results_hidden_until_closed to an evaluator while a blind
 * evaluation is open, and the page says so instead of an error (UX §4.2).
 */
export async function resultsLoader({ params, request }) {
  const base = `/api/projects/${params.projectId}/evaluations/${params.evaluationId}`;
  const [evaluation, results] = await Promise.all([
    api(base, { request }),
    api(`${base}/results`, { request }).catch((error) => {
      if (error instanceof ApiError && error.problem.code === 'results_hidden_until_closed') {
        return null;
      }
      throw error;
    }),
  ]);

  return { evaluation, results };
}

export function Results() {
  const { evaluation, results } = useLoaderData();
  const project = useRouteLoaderData('project');
  usePageTitle('Results', evaluation.name, project.name);

  return (
    <>
      <Breadcrumb items={crumbs(project, { label: evaluation.name, to: evaluationPath(project, evaluation) }, { label: 'Results' })} />
      <EvaluationHead project={project} evaluation={evaluation} current="Results" actions={results && <ExportLink project={project} evaluation={evaluation} />} />
      {results ? <ResultsBody project={project} evaluation={evaluation} results={results} /> : <Locked project={project} evaluation={evaluation} />}
    </>
  );
}

/**
 * The results as CSV: a plain link, so the browser downloads the API's attachment (with the session cookie, as any
 * GET). Only where the results can be read: never on the locked page.
 */
export function ExportLink({ project, evaluation }) {
  return (
    <div className="actions">
      <a className="btn" href={`/api/projects/${project.id}/evaluations/${evaluation.id}/export.csv`} download>
        <Icon name="download" />
        Export results (CSV)
      </a>
    </div>
  );
}

/** For an evaluator while a blind evaluation is open: Results, Compare and Case all show this (UX §4.2). */
export function Locked({ project, evaluation }) {
  return (
    <div className="empty" style={{ borderStyle: 'solid' }}>
      <span className="empty-icon">
        <Icon name="lock" size="lg" />
      </span>
      <h2 className="empty-title">Results unlock when the owner closes this evaluation</h2>
      <p>
        It is blind and still open, so nobody scoring it sees which run did better. Scoring so far: {n(evaluation.answers.scored)} of {n(evaluation.answers.total)} answers.
      </p>
      <div className="actions">
        <ScoreLink project={project} evaluation={evaluation} className="btn btn-primary" />
      </div>
    </div>
  );
}

/** The run's letter and label as a column heading: A solid, B striped, the others plain. */
function RunHeading({ run }) {
  return (
    <span className={run.letter === 'A' ? 'run run-a' : run.letter === 'B' ? 'run run-b' : 'run'} style={{ fontWeight: 500 }}>
      <span className="run-letter">{run.letter}</span>
      <bdi>{run.label}</bdi>
    </span>
  );
}

/** The key to the bars, in words: "A baseline-v1, solid". The bars only repeat the numbers, so this is aria-hidden. */
function Legend({ runs, labels = true }) {
  const pattern = { A: 'solid', B: 'striped' };

  return (
    <div className="legend" aria-hidden="true">
      {runs.map((run) => (
        <span key={run.letter} className={run.letter === 'A' ? 'run run-a' : run.letter === 'B' ? 'run run-b' : 'run'}>
          <span className={`run-key${pattern[run.letter] ? '' : ' run-key-other'}`} />
          {run.letter}
          {labels && <> {run.label}</>}, {pattern[run.letter] ?? 'grey'}
        </span>
      ))}
    </div>
  );
}

function Bars({ runs, values, measure }) {
  return (
    <div className="cbars" aria-hidden="true">
      {runs.map((run, index) => (
        <span key={run.letter} className={barClass(run.letter)} style={{ width: `${Math.round(share(measure, values[index]) * 100)}%` }} />
      ))}
    </div>
  );
}

export const MEASURES = { mean: 'Average, 1–5', passRate: 'Pass rate' };

function ResultsBody({ project, evaluation, results }) {
  const { runs, criteria, tags } = results;
  const focus = criteria.find((criterion) => criterion.kind === 'scale_1_5') ?? criteria[0];
  const scored = runs.reduce((sum, run) => sum + run.scored, 0);
  const answers = runs.reduce((sum, run) => sum + run.answers, 0);

  return (
    <>
      <OwnerNote project={project} evaluation={evaluation} scored={scored} answers={answers} />

      {/* Named by its table region, not twice: one landmark per name. */}
      <section>
        <div className="section-head">
          <h2 className="section-title" id="by-criterion-heading">
            By criterion
          </h2>
          <Legend runs={runs} />
        </div>
        <div className="table-box" role="region" aria-labelledby="by-criterion-heading" tabIndex={0}>
          <table className="tbl" style={{ minWidth: 520 + 120 * runs.length }}>
            <thead>
              <tr>
                <th scope="col">Criterion</th>
                <th scope="col">Measure</th>
                {runs.map((run) => (
                  <th key={run.letter} scope="col" className="num">
                    <RunHeading run={run} />
                  </th>
                ))}
                <th scope="col" style={{ width: 240 }}>
                  <span className="sr-only">Bars</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <th scope="row">Overall score</th>
                <td className="muted">Weighted, 0–100</td>
                {runs.map((run) => (
                  <td key={run.letter} className="num mono">
                    {run.overall === null ? <Value value={null} /> : run.overall.toFixed(1)}
                  </td>
                ))}
                <td>
                  <Bars runs={runs} values={runs.map((run) => run.overall)} measure="score" />
                </td>
              </tr>
              {criteria.map((criterion) => (
                <tr key={criterion.id}>
                  <th scope="row">
                    <bdi>{criterion.name}</bdi>
                  </th>
                  <td className="muted">
                    {MEASURES[criterion.measure]}
                    {criterion.kind === 'automatic' && ', automatic'}
                  </td>
                  {criterion.runs.map((run) => (
                    <td key={run.letter} className="num mono">
                      <Value measure={criterion.measure} value={run[criterion.measure]} />
                      {run.checkErrors > 0 && (
                        <span className="block font-sans text-xs text-[var(--text-muted)]">
                          {n(run.checkErrors)} not checked
                        </span>
                      )}
                    </td>
                  ))}
                  <td>
                    <Bars runs={runs} values={criterion.runs.map((run) => run[criterion.measure])} measure={criterion.measure} />
                  </td>
                </tr>
              ))}
              <tr>
                <th scope="row">Answers with every score</th>
                <td className="muted">Coverage</td>
                {runs.map((run) => (
                  <td key={run.letter} className="num mono">
                    {n(run.scored)} of {n(run.answers)}
                  </td>
                ))}
                <td />
              </tr>
            </tbody>
          </table>
        </div>
        <p className="small muted mt-2.5">
          Checks that could not run (“not checked”, such as a case without a reference) are counted apart, never as a fail. A run that left cases unanswered is scored on its answers only.
        </p>
      </section>

      {focus && <Distribution runs={runs} criterion={focus} />}
      {focus && tags.length > 0 && <ByTag runs={runs} criterion={focus} tags={tags} />}
    </>
  );
}

/** The owner reads the results while it is open; the note says what evaluators see (canvas: Results). */
function OwnerNote({ project, evaluation, scored, answers }) {
  if (evaluation.status === 'closed') {
    return (
      <div className="notice mb-7 border-[var(--border-strong)] bg-[var(--surface)]">
        <Icon name="lock" />
        <p>The evaluation is closed: these results no longer change.</p>
      </div>
    );
  }
  if (!isOwner(project)) {
    return null;
  }

  return (
    <div className="notice mb-7 border-[var(--border-strong)] bg-[var(--surface)]">
      <Icon name="info" />
      <p>
        {scored === answers ? `All ${n(answers)} answers are scored.` : `${n(scored)} of ${n(answers)} answers have every score, so these numbers can still change.`}{' '}
        {evaluation.blind ? (
          <>
            Evaluators see these results once you <Link to={evaluationPath(project, evaluation)}>close the evaluation</Link>.
          </>
        ) : (
          'Evaluators can read them too: the evaluation is not blind.'
        )}
      </p>
    </div>
  );
}

/** How often each value was given, as bars with the number written beside them, a summary and the same as a table. */
function Distribution({ runs, criterion }) {
  const scale = criterion.kind === 'scale_1_5';
  const ends = scale ? scaleEnds(criterion.guidance ?? '') : null;
  const values = criterion.runs[0].distribution.map((entry) => entry.value);
  const max = Math.max(1, ...criterion.runs.flatMap((run) => run.distribution.map((entry) => entry.count)));
  const label = (value) => (scale ? `Score ${value}` : value === 1 ? 'Pass' : 'Fail');
  const words = (value) => (ends && value === 1 ? ends[0] : ends && value === 5 ? ends[1] : '');
  const top = (run) => run.distribution.filter((entry) => (scale ? entry.value >= 4 : entry.value === 1)).reduce((sum, entry) => sum + entry.count, 0);
  const summary = `${scale ? 'Scored 4 or 5' : 'Passed'}: ${criterion.runs.map((run) => `${run.letter} on ${n(top(run))} of ${n(run.n)} answers`).join(', ')}.`;

  return (
    <section className="section">
      <figure className="figure" aria-labelledby="distribution-heading" aria-describedby="distribution-summary">
        <div className="section-head m-0">
          <h2 className="section-title" id="distribution-heading">
            <bdi>{criterion.name}</bdi>: how often each {scale ? 'score' : 'result'} was given
          </h2>
          <Legend runs={runs} labels={false} />
        </div>
        <p className="fig-sum" id="distribution-summary">
          {summary}
        </p>
        <div className="chart" aria-hidden="true">
          {values.map((value) => (
            <div key={value} className="contents">
              <p className="chart-label">
                {label(value)}
                {words(value) && <span>{words(value)}</span>}
              </p>
              <div className="cbars">
                {criterion.runs.map((run) => {
                  const count = run.distribution.find((entry) => entry.value === value)?.count ?? 0;

                  return (
                    <span key={run.letter} className="cbar">
                      <span className={barClass(run.letter)} style={{ width: `${Math.round((count / max) * 70)}%` }} />
                      {run.letter} {n(count)}
                    </span>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
        <details className="as-table">
          <summary>
            Show this chart as a table
            <Icon name="chev-down" />
          </summary>
          <div className="table-box">
            <table className="tbl">
              <thead>
                <tr>
                  <th scope="col">{scale ? 'Score' : 'Result'}</th>
                  {runs.map((run) => (
                    <th key={run.letter} scope="col" className="num">
                      {run.letter} <bdi>{run.label}</bdi>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {values.map((value) => (
                  <tr key={value}>
                    <th scope="row">{scale ? value : label(value)}</th>
                    {criterion.runs.map((run) => (
                      <td key={run.letter} className="num mono">
                        {n(run.distribution.find((entry) => entry.value === value)?.count ?? 0)}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </details>
      </figure>
    </section>
  );
}

/** The criterion per tag, with each run's difference from A in words and sign ("B − A"; with more runs, "C − A"…). */
function ByTag({ runs, criterion, tags }) {
  const others = runs.slice(1);

  return (
    <section className="section">
      <div className="section-head">
        <h2 className="section-title" id="by-tag-heading">
          <bdi>{criterion.name}</bdi> by tag
        </h2>
        <p className="section-hint">{criterion.measure === 'mean' ? 'Average from 1 to 5.' : 'Pass rate.'} A case can have several tags.</p>
      </div>
      <div className="table-box" role="region" aria-labelledby="by-tag-heading" tabIndex={0}>
        <table className="tbl" style={{ minWidth: 440 + 100 * runs.length + 90 * others.length }}>
          <thead>
            <tr>
              <th scope="col">Tag</th>
              <th scope="col" className="num">
                Cases
              </th>
              {runs.map((run) => (
                <th key={run.letter} scope="col" className="num">
                  {run.letter}
                </th>
              ))}
              {others.map((run) => (
                <th key={run.letter} scope="col" className="num">
                  {run.letter} − A
                </th>
              ))}
              <th scope="col" style={{ width: 200 }}>
                <span className="sr-only">Bars</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {tags.map((tag) => {
              const values = tag.criteria.find((entry) => entry.id === criterion.id).runs.map((run) => run[criterion.measure]);

              return (
                <tr key={tag.tag}>
                  <th scope="row">
                    <span className="tag">
                      <bdi>{tag.tag}</bdi>
                    </span>
                  </th>
                  <td className="num mono">{n(tag.cases)}</td>
                  {values.map((value, index) => (
                    <td key={runs[index].letter} className="num mono">
                      <Value measure={criterion.measure} value={value} />
                    </td>
                  ))}
                  {others.map((run, index) => (
                    <td key={run.letter} className="num">
                      {values[0] === null || values[index + 1] === null ? <Value value={null} /> : <Difference measure={criterion.measure} value={values[index + 1] - values[0]} />}
                    </td>
                  ))}
                  <td>
                    <Bars runs={runs} values={values} measure={criterion.measure} />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </section>
  );
}
