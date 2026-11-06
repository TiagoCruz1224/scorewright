import { Form, Link, useLoaderData, useRouteLoaderData } from 'react-router';
import { api, ApiError } from '../api/client.js';
import { ErrorSummary, Field } from '../components/forms.jsx';
import { Icon } from '../components/icons.jsx';
import { Breadcrumb, usePageTitle } from '../components/layout.jsx';
import { crumbs, EvaluationHead, evaluationPath, resultsLocked } from './evaluations.jsx';
import { Difference, ExportLink, Locked, MEASURES, n, Value } from './results.jsx';
import { CheckState } from './scoring.jsx';

/** An answer the API keeps from an evaluator while a blind evaluation is open: the page shows the locked state. */
const hidden = (error) => error instanceof ApiError && error.problem.code === 'results_hidden_until_closed';

/** The two runs from the URL (?runA=&runB=), or the evaluation's A and B. */
const choiceOf = (request, evaluation) => {
  const search = new URL(request.url).searchParams;

  return { runA: search.get('runA') ?? evaluation.runs[0]?.id ?? '', runB: search.get('runB') ?? evaluation.runs[1]?.id ?? '' };
};

/**
 * Compare runs B and A of the URL. A 422 (the same run twice, or one that is not the evaluation's) comes back as the
 * form's errors; a 403 results_hidden_until_closed as the locked page.
 */
export async function compareLoader({ params, request }) {
  const base = `/api/projects/${params.projectId}/evaluations/${params.evaluationId}`;
  const evaluation = await api(base, { request });
  const choice = choiceOf(request, evaluation);
  const loaded = { evaluation, choice, comparison: null, problem: null, locked: false };
  if (evaluation.runs.length < 2) {
    return loaded;
  }
  try {
    return { ...loaded, comparison: await api(`${base}/comparison?${new URLSearchParams(choice)}`, { request }) };
  } catch (error) {
    if (hidden(error)) {
      return { ...loaded, locked: true };
    }
    if (error instanceof ApiError && error.problem.status === 422) {
      return { ...loaded, problem: error.problem };
    }
    throw error;
  }
}

export function Compare() {
  const { evaluation, choice, comparison, problem, locked } = useLoaderData();
  const project = useRouteLoaderData('project');
  usePageTitle('Compare', evaluation.name, project.name);
  const isLocked = locked || resultsLocked(project, evaluation);

  return (
    <>
      <Breadcrumb items={crumbs(project, { label: evaluation.name, to: evaluationPath(project, evaluation) }, { label: 'Compare' })} />
      <EvaluationHead project={project} evaluation={evaluation} current="Compare" actions={!isLocked && <ExportLink project={project} evaluation={evaluation} />} />
      {isLocked ? (
        <Locked project={project} evaluation={evaluation} />
      ) : evaluation.runs.length < 2 ? (
        <div className="empty">
          <h2 className="empty-title">Comparing needs two runs</h2>
          <p>This evaluation has one run. Start an evaluation with two or more runs of the same dataset version to compare them.</p>
        </div>
      ) : (
        <>
          <RunPicker evaluation={evaluation} choice={choice} problem={problem} />
          {comparison && <CompareBody project={project} evaluation={evaluation} comparison={comparison} />}
        </>
      )}
    </>
  );
}

/** The run's letter in the colour of its side: the first run solid, the second striped, as in the bars. */
function Side({ run, index, children }) {
  return (
    <span className={index === 0 ? 'run run-a' : 'run run-b'}>
      <span className="run-letter">{run.letter}</span>
      {children}
    </span>
  );
}

/**
 * The two runs, applied only with Update (WCAG 3.2.2: changing a select never changes the page). It is a GET form, so
 * the choice is in the URL. Each label shows the bar style of its side.
 */
function RunPicker({ evaluation, choice, problem }) {
  const label = (index, text) => (
    <span className={index === 0 ? 'run run-a' : 'run run-b'}>
      <span className="run-key" aria-hidden="true" />
      {text}
    </span>
  );
  const options = evaluation.runs.map((run) => (
    <option key={run.id} value={run.id}>
      {run.letter} {run.label}
    </option>
  ));

  return (
    <>
      <ErrorSummary problem={problem} idPrefix="compare" />
      {/* Keyed by the choice, so going back in history shows the runs of that page. */}
      <Form key={`${choice.runA}:${choice.runB}`} method="get" className="panel-bar is-form" aria-label="Runs to compare">
        <Field name="runA" idPrefix="compare" label={label(0, 'Run')} as="select" controlClassName="select" className="w-60 max-w-full" problem={problem} defaultValue={choice.runA}>
          {options}
        </Field>
        <Field name="runB" idPrefix="compare" label={label(1, 'Compared with')} as="select" controlClassName="select" className="w-60 max-w-full" problem={problem} defaultValue={choice.runB}>
          {options}
        </Field>
        <button className="btn" type="submit">
          Update
        </button>
        <p className="hint pb-[9px]">The page changes only when you press Update.</p>
      </Form>
    </>
  );
}

/** "▲ B is 0.7 points higher than A", "▼ B is 5 percentage points lower than A": the difference in words. */
function Sentence({ measure, difference, runs }) {
  const [a, b] = runs.map((run) => run.letter);
  if (difference === null) {
    return <p className="diff diff-zero block font-sans whitespace-normal">No scores to compare yet</p>;
  }
  const rate = measure === 'passRate';
  const rounded = rate ? Math.round(difference) : Math.round(difference * 10) / 10;
  const size = Math.abs(rounded);
  if (rounded === 0) {
    return (
      <p className="diff diff-zero block font-sans whitespace-normal">
        <span aria-hidden="true">=</span> {b} and {a} are level
      </p>
    );
  }
  const amount = rate ? `${size} percentage point${size === 1 ? '' : 's'}` : `${size.toFixed(1)} points`;

  return (
    <p className={`diff block font-sans whitespace-normal ${rounded > 0 ? 'diff-pos' : 'diff-neg'}`}>
      <span aria-hidden="true">{rounded > 0 ? '▲' : '▼'}</span> {b} is {amount} {rounded > 0 ? 'higher' : 'lower'} than {a}
    </p>
  );
}

const showValue = (measure, value) => (measure === 'score' ? (value === null ? <Value value={null} /> : value.toFixed(1)) : <Value measure={measure} value={value} />);

function CompareBody({ project, evaluation, comparison }) {
  const { runs, overall, criteria, tags } = comparison;
  const focus = criteria.find((criterion) => criterion.kind === 'scale_1_5') ?? criteria[0];
  // The overall score and the first three criteria; the table below has them all.
  const cards = [
    { id: 'overall', name: 'Overall score', measure: 'score', a: overall.a, b: overall.b, difference: overall.difference },
    ...criteria.slice(0, 3).map((criterion) => ({ id: criterion.id, name: criterion.name, measure: criterion.measure, a: criterion.a.value, b: criterion.b.value, difference: criterion.difference })),
  ];

  return (
    <>
      <section aria-labelledby="summary-heading">
        <h2 className="sr-only" id="summary-heading">
          Summary
        </h2>
        <div className="stats">
          {cards.map((card) => (
            <div key={card.id} className="stat">
              <p className="stat-label">
                <bdi>{card.name}</bdi>
              </p>
              <p className="stat-ab">
                {runs.map((run, index) => (
                  <Side key={run.id} run={run} index={index}>
                    {showValue(card.measure, index === 0 ? card.a : card.b)}
                  </Side>
                ))}
              </p>
              <Sentence measure={card.measure} difference={card.difference} runs={runs} />
            </div>
          ))}
        </div>
        <p className="small muted mt-2.5">
          On the {n(comparison.cases)} cases both runs answered, so a case one of them skipped weighs on neither side.
        </p>
      </section>

      <ByCriterion runs={runs} overall={overall} criteria={criteria} />
      {focus && tags.length > 0 && <TagChart runs={runs} criterion={focus} tags={tags} />}
      <Disagreements project={project} evaluation={evaluation} comparison={comparison} />
    </>
  );
}

function RunHeadings({ runs }) {
  return runs.map((run, index) => (
    <th key={run.id} scope="col" className="num">
      <Side run={run} index={index}>
        <bdi>{run.label}</bdi>
      </Side>
    </th>
  ));
}

function ByCriterion({ runs, overall, criteria }) {
  const [a, b] = runs.map((run) => run.letter);
  const errors = (side) =>
    side.checkErrors > 0 && (
      <span className="block font-sans text-xs text-[var(--text-muted)]">{n(side.checkErrors)} not checked</span>
    );

  return (
    <section className="section">
      <div className="section-head">
        <h2 className="section-title" id="by-criterion-heading">
          By criterion
        </h2>
      </div>
      <div className="table-box" role="region" aria-labelledby="by-criterion-heading" tabIndex={0}>
        <table className="tbl" style={{ minWidth: 640 }}>
          <thead>
            <tr>
              <th scope="col">Criterion</th>
              <th scope="col">Measure</th>
              <RunHeadings runs={runs} />
              <th scope="col" className="num">
                {b} − {a}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <th scope="row">Overall score</th>
              <td className="muted">Weighted, 0–100</td>
              <td className="num mono">{showValue('score', overall.a)}</td>
              <td className="num mono">{showValue('score', overall.b)}</td>
              <td className="num">{overall.difference === null ? <Value value={null} /> : <Difference measure="score" value={overall.difference} />}</td>
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
                {[criterion.a, criterion.b].map((side, index) => (
                  <td key={index} className="num mono">
                    <Value measure={criterion.measure} value={side.value} />
                    {errors(side)}
                  </td>
                ))}
                <td className="num">{criterion.difference === null ? <Value value={null} /> : <Difference measure={criterion.measure} value={criterion.difference} />}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="small muted mt-2.5">Checks that could not run (“not checked”) are counted apart, never as a fail.</p>
    </section>
  );
}

/** "+1.1" or "−5 pts", as text for a sentence. */
const signed = (measure, value) => {
  const rate = measure === 'passRate';
  const rounded = rate ? Math.round(value) : Math.round(value * 10) / 10;
  const sign = rounded > 0 ? '+' : rounded < 0 ? '−' : '±';

  return rate ? `${sign}${Math.abs(rounded)} pts` : `${sign}${Math.abs(rounded).toFixed(1)}`;
};

/** The criterion per tag: A solid and B striped with the value written beside them, a summary and the same as a table. */
function TagChart({ runs, criterion, tags }) {
  const [a, b] = runs.map((run) => run.letter);
  const scale = criterion.measure === 'mean';
  const rows = tags.map((tag) => {
    const entry = tag.criteria.find((item) => item.id === criterion.id);

    return { tag: tag.tag, cases: tag.cases, a: entry.a.value, b: entry.b.value, difference: entry.difference };
  });
  // Bars on 75% of the width, so the value written after the longest still fits at 320 px; the scale below matches.
  const width = (value) => `${value === null ? 0 : Math.round((value / (scale ? 5 : 100)) * 75)}%`;
  const measured = rows.filter((row) => row.difference !== null);
  const rounded = (row) => (scale ? Math.round(row.difference * 10) : Math.round(row.difference));
  const higher = measured.filter((row) => rounded(row) > 0).sort((x, y) => y.difference - x.difference);
  const lower = measured.filter((row) => rounded(row) < 0).sort((x, y) => x.difference - y.difference);
  const list = (items) => items.map((row) => `${row.tag} (${signed(criterion.measure, row.difference)})`).join(' and ');
  const summary = [
    `${b} is higher than ${a} on ${higher.length} of ${measured.length} tags${higher.length > 0 ? `, most on ${list(higher.slice(0, 2))}` : ''}.`,
    lower.length > 0 && `It is lower on ${list(lower.slice(0, 2))}${lower.length > 2 ? ` and ${lower.length - 2} more` : ''}.`,
    scale ? 'Average from 1 to 5.' : 'Pass rate.',
  ]
    .filter(Boolean)
    .join(' ');
  const ticks = scale ? [0, 1, 2, 3, 4, 5] : [0, 20, 40, 60, 80, 100];

  return (
    <section className="section">
      <figure className="figure" aria-labelledby="tag-heading" aria-describedby="tag-summary">
        <div className="section-head m-0">
          <h2 className="section-title" id="tag-heading">
            <bdi>{criterion.name}</bdi> by tag
          </h2>
          <div className="legend" aria-hidden="true">
            {runs.map((run, index) => (
              <span key={run.id} className={index === 0 ? 'run run-a' : 'run run-b'}>
                <span className="run-key" />
                {run.letter} {run.label}, {index === 0 ? 'solid' : 'striped'}
              </span>
            ))}
          </div>
        </div>
        <p className="fig-sum" id="tag-summary">
          {summary}
        </p>
        <div className="chart" aria-hidden="true">
          {rows.map((row) => (
            <div key={row.tag} className="contents">
              <p className="chart-label">
                <bdi>{row.tag}</bdi>
                <span>
                  {n(row.cases)} case{row.cases === 1 ? '' : 's'}
                </span>
              </p>
              <div className="cbars">
                {[row.a, row.b].map((value, index) => (
                  <span key={index} className="cbar">
                    <span className={index === 0 ? 'bar bar-a' : 'bar bar-b'} style={{ width: width(value) }} />
                    {index === 0 ? a : b} {value === null ? '—' : scale ? value.toFixed(1) : `${Math.round(value)}%`}
                  </span>
                ))}
              </div>
            </div>
          ))}
          <span />
          <div className="chart-scale" style={{ width: '75%' }}>
            {ticks.map((tick) => (
              <span key={tick}>{tick}</span>
            ))}
          </div>
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
                  <th scope="col">Tag</th>
                  <th scope="col" className="num">
                    Cases
                  </th>
                  <th scope="col" className="num">
                    {a} <bdi>{runs[0].label}</bdi>
                  </th>
                  <th scope="col" className="num">
                    {b} <bdi>{runs[1].label}</bdi>
                  </th>
                  <th scope="col" className="num">
                    {b} − {a}
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.tag}>
                    <th scope="row">
                      <bdi>{row.tag}</bdi>
                    </th>
                    <td className="num mono">{n(row.cases)}</td>
                    <td className="num mono">
                      <Value measure={criterion.measure} value={row.a} />
                    </td>
                    <td className="num mono">
                      <Value measure={criterion.measure} value={row.b} />
                    </td>
                    <td className="num">{row.difference === null ? <Value value={null} /> : <Difference measure={criterion.measure} value={row.difference} />}</td>
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

/** One case's value: a 1–5 score, or Pass or Fail. */
const caseValue = (measure, value) => (measure === 'mean' ? value : value === 1 ? 'Pass' : 'Fail');

const casePath = (project, evaluation, caseId, runs) => `${evaluationPath(project, evaluation)}/cases/${caseId}?${new URLSearchParams({ runA: runs[0].id, runB: runs[1].id })}`;

function Disagreements({ project, evaluation, comparison }) {
  const { runs, disagreements } = comparison;
  const [a, b] = runs.map((run) => run.letter);

  return (
    <section className="section">
      <div className="section-head">
        <h2 className="section-title" id="disagreements-heading">
          Largest disagreements
        </h2>
        <p className="section-hint">
          Where {a} and {b} got the most different scores for a case: a pass against a fail first, then each point apart on 1 to 5. Ties go by case order.
        </p>
      </div>
      {disagreements.length === 0 ? (
        <p className="muted">
          {a} and {b} got the same score on every case and criterion scored for both.
        </p>
      ) : (
        <div className="table-box" role="region" aria-labelledby="disagreements-heading" tabIndex={0}>
          <table className="tbl" style={{ minWidth: 820 }}>
            <thead>
              <tr>
                <th scope="col">Case</th>
                <th scope="col">Criterion</th>
                <th scope="col">Input</th>
                <th scope="col" className="num">
                  {a}
                </th>
                <th scope="col" className="num">
                  {b}
                </th>
                <th scope="col" className="num">
                  {b} − {a}
                </th>
              </tr>
            </thead>
            <tbody>
              {disagreements.map((row) => (
                <tr key={`${row.case.id}:${row.criterion.id}`}>
                  <th scope="row">
                    <Link to={casePath(project, evaluation, row.case.id, runs)}>
                      <code>
                        <bdi>{row.case.key}</bdi>
                      </code>
                    </Link>
                  </th>
                  <td>
                    <bdi>{row.criterion.name}</bdi>
                  </td>
                  <td className="muted">
                    <span className="line-clamp-2 max-w-[46ch]">
                      <bdi>{row.case.input}</bdi>
                    </span>
                  </td>
                  <td className="num mono">{caseValue(row.criterion.measure, row.a)}</td>
                  <td className="num mono">{caseValue(row.criterion.measure, row.b)}</td>
                  <td className="num">
                    <Difference measure={row.criterion.measure} value={row.difference} decimals={0} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

/** One case and every run's answer. With ?runA=&runB=, where it stands among their largest disagreements. */
export async function caseLoader({ params, request }) {
  const base = `/api/projects/${params.projectId}/evaluations/${params.evaluationId}`;
  const search = new URL(request.url).searchParams;
  const pair = search.has('runA') || search.has('runB') ? `?${new URLSearchParams({ runA: search.get('runA') ?? '', runB: search.get('runB') ?? '' })}` : '';
  const [evaluation, view] = await Promise.all([
    api(base, { request }),
    api(`${base}/cases/${params.caseId}${pair}`, { request }).catch((error) => {
      if (hidden(error)) {
        return null;
      }
      throw error;
    }),
  ]);

  return { evaluation, view, pair, choice: { runA: search.get('runA'), runB: search.get('runB') } };
}

export function CaseView() {
  const { evaluation, view, pair, choice } = useLoaderData();
  const project = useRouteLoaderData('project');
  const path = evaluationPath(project, evaluation);
  usePageTitle(view ? `Case ${view.case.key}` : 'Case', evaluation.name, project.name);
  const trail = crumbs(project, { label: evaluation.name, to: path }, { label: 'Compare', to: `${path}/compare${pair}` }, { label: view ? view.case.key : 'Case' });

  if (!view) {
    return (
      <>
        <Breadcrumb items={trail} />
        <EvaluationHead project={project} evaluation={evaluation} current="Compare" />
        <Locked project={project} evaluation={evaluation} />
      </>
    );
  }

  const { case: testCase, criteria, answers, disagreement } = view;
  const letter = (id) => evaluation.runs.find((run) => run.id === id)?.letter;
  const [a, b] = [letter(choice.runA), letter(choice.runB)];
  const step = (caseId, children) =>
    caseId ? (
      <Link className="btn btn-sm" to={`${path}/cases/${caseId}${pair}`}>
        {children}
      </Link>
    ) : (
      <button type="button" className="btn btn-sm" disabled>
        {children}
      </button>
    );

  return (
    <>
      <Breadcrumb items={trail} />
      <div className="page-head">
        <div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h1 className="page-title">
              Case{' '}
              {/* The size inline, as in the design: the base rule for code would win over a utility class. */}
              <code style={{ fontSize: 22 }}>
                <bdi>{testCase.key}</bdi>
              </code>
            </h1>
            {testCase.tags.length > 0 && (
              <ul className="tags" aria-label="Tags">
                {testCase.tags.map((tag) => (
                  <li key={tag} className="tag">
                    <bdi>{tag}</bdi>
                  </li>
                ))}
              </ul>
            )}
          </div>
          {disagreement && (
            <p className="page-meta">
              <span>
                Largest disagreement {disagreement.position} of {disagreement.count} on <bdi>{disagreement.criterion.name}</bdi>
              </span>
              <span className="dot">
                {disagreement.criterion.measure === 'mean' ? (
                  <>
                    <Difference measure="mean" value={disagreement.difference} decimals={0} /> for {b}
                  </>
                ) : (
                  `${b} ${disagreement.b === 1 ? 'passes' : 'fails'}, ${a} ${disagreement.a === 1 ? 'passes' : 'fails'}`
                )}
              </span>
            </p>
          )}
        </div>
        {disagreement && (
          <nav className="actions" aria-label="Disagreements">
            {step(
              disagreement.previousCaseId,
              <>
                <Icon name="chev-left" />
                Previous
              </>,
            )}
            {step(
              disagreement.nextCaseId,
              <>
                Next disagreement
                <Icon name="chev-right" />
              </>,
            )}
          </nav>
        )}
      </div>

      <div className="grid-2 mb-7">
        <section className="case-block" aria-labelledby="case-input">
          <h2 className="block-title" id="case-input">
            Input
          </h2>
          <p className="textblock">{testCase.input}</p>
        </section>
        <section className="case-block" aria-labelledby="case-reference">
          <h2 className="block-title" id="case-reference">
            Reference answer
          </h2>
          {testCase.reference === null ? <p className="muted">This case has no reference answer.</p> : <p className="textblock">{testCase.reference}</p>}
        </section>
      </div>

      {answers.length === 0 ? (
        <p className="muted">No run of this evaluation answered this case.</p>
      ) : (
        <div className="split">
          {answers.map((answer) => (
            <AnswerCard key={answer.letter} answer={answer} criteria={criteria} />
          ))}
        </div>
      )}
    </>
  );
}

const CARDS = { A: 'answer-card', B: 'answer-card is-b' };
const RUNS = { A: 'run run-a', B: 'run run-b' };

/** A manual score: the 1–5 value, or Pass or Fail with its icon; "Not scored" until someone scores it. */
function ManualScore({ criterion, score }) {
  if (score.value === null) {
    return <span className="muted">Not scored</span>;
  }
  if (criterion.kind === 'scale_1_5') {
    return <span className="mono">{score.value}</span>;
  }

  return score.value === 1 ? (
    <span className="state state-pass">
      <Icon name="check" />
      Pass
    </span>
  ) : (
    <span className="state state-fail">
      <Icon name="x" />
      Fail
    </span>
  );
}

/** A run's answer as text (never as HTML), its scores and its comment. A solid, B dashed, the others dotted. */
function AnswerCard({ answer, criteria }) {
  const headingId = `answer-${answer.letter}`;

  return (
    <article className={CARDS[answer.letter] ?? 'answer-card is-other'} aria-labelledby={headingId}>
      <header className="answer-head">
        <span className={RUNS[answer.letter] ?? 'run'}>
          <span className="run-letter">{answer.letter}</span>
          <span className={RUNS[answer.letter] ? 'run-key' : 'run-key run-key-other'} aria-hidden="true" />
        </span>
        <h2 id={headingId} className="card-title">
          {/* The space outside the hidden text, so the name is "Run A: baseline-v1", not "Run A:baseline-v1". */}
          <span className="sr-only">Run {answer.letter}:</span> <bdi className="mono">{answer.label}</bdi>
        </h2>
      </header>
      <div className="answer-body">
        <p className="textblock">{answer.output}</p>
        <table className="tbl" aria-label={`Scores of run ${answer.letter}`}>
          <tbody>
            {criteria.map((criterion, index) => {
              const score = answer.scores[index];

              return (
                <tr key={criterion.id}>
                  <th scope="row">
                    <bdi>{criterion.name}</bdi>
                  </th>
                  <td className="num">{criterion.kind === 'automatic' ? <CheckState score={score.outcome ? score : null} /> : <ManualScore criterion={criterion} score={score} />}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {answer.comment ? (
          <div className="comment">
            <p className="small muted mb-0.5">
              Comment{answer.comment.by && <> · {answer.comment.by}</>}
            </p>
            <p className="whitespace-pre-wrap">{answer.comment.text}</p>
          </div>
        ) : (
          <p className="small muted">No comment.</p>
        )}
      </div>
    </article>
  );
}
