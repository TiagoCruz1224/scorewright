import { useEffect, useRef, useState } from 'react';
import { Link, redirect, useLoaderData, useNavigate, useRouteLoaderData } from 'react-router';
import { api, ApiError } from '../api/client.js';
import { Icon } from '../components/icons.jsx';
import { Breadcrumb, usePageTitle } from '../components/layout.jsx';
import { evaluationPath, resultsLocked, ResultsLink, RunName } from './evaluations.jsx';

const n = (number) => number.toLocaleString('en');
const clock = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' });
const SHORTCUTS_KEY = 'sw-shortcuts';
const TYPING = /^(text|search|email|url|tel|number|password)$/;

/** A box the user types in: the shortcuts never fire there (UX §4.1). Radios are not one: 4 on a focused scale scores it. */
const isTextField = (element) =>
  element instanceof HTMLElement && (element.isContentEditable || ['TEXTAREA', 'SELECT'].includes(element.tagName) || (element.tagName === 'INPUT' && TYPING.test(element.type)));

/** The ends of a 1–5 scale from its guidance, "1: rude or cold. … 5: warm and clear, without filler." → both phrases. */
export const scaleEnds = (guidance) => {
  const low = guidance.match(/(?:^|[\s.;])1:\s*([^.,;\n]+)/);
  const high = guidance.match(/(?:^|[\s.;])5:\s*([^.,;\n]+)/);

  return low && high ? [low[1].trim(), high[1].trim()] : null;
};

/** Scoring answers 409 evaluation_closed once the evaluation is closed: the page says so, without fields. */
async function unlessClosed(call) {
  try {
    return await call();
  } catch (error) {
    if (error instanceof ApiError && error.problem.code === 'evaluation_closed') {
      return 'closed';
    }
    throw error;
  }
}

const scoringPath = (params) => `/projects/${params.projectId}/evaluations/${params.evaluationId}/score`;

/**
 * Start or Continue scoring, and J: the next answer with missing scores (after ?after= when given) opens at its own URL,
 * which holds only the random item id (ADR 0005). None left: "All N answers scored".
 */
export async function scoringStartLoader({ params, request }) {
  const base = `/api/projects/${params.projectId}/evaluations/${params.evaluationId}`;
  const after = new URL(request.url).searchParams.get('after');
  const [evaluation, next] = await Promise.all([
    api(base, { request }),
    unlessClosed(() => api(`${base}/items/next${after ? `?after=${encodeURIComponent(after)}` : ''}`, { request })),
  ]);
  if (next && next !== 'closed') {
    return redirect(`${scoringPath(params)}/${next.id}`);
  }

  return { evaluation, item: null, closed: next === 'closed' };
}

export async function scoringLoader({ params, request }) {
  const base = `/api/projects/${params.projectId}/evaluations/${params.evaluationId}`;
  const [evaluation, item] = await Promise.all([api(base, { request }), unlessClosed(() => api(`${base}/items/${params.itemId}`, { request }))]);

  return item === 'closed' ? { evaluation, item: null, closed: true } : { evaluation, item, closed: false };
}

export function Scoring() {
  const { evaluation, item, closed } = useLoaderData();
  const project = useRouteLoaderData('project');
  usePageTitle('Score answers', evaluation.name, project.name);
  const back = evaluationPath(project, evaluation);

  return (
    <>
      <Breadcrumb
        items={[
          { label: 'Projects', to: '/projects' },
          { label: project.name, to: `/projects/${project.id}` },
          { label: 'Evaluations', to: `/projects/${project.id}/evaluations` },
          { label: evaluation.name, to: back },
          { label: 'Score' },
        ]}
      />
      {item ? (
        <ScoringItem key={item.id} project={project} evaluation={evaluation} item={item} />
      ) : (
        <>
          <h1 className="page-title mb-5">Score answers</h1>
          {closed ? (
            <div className="notice">
              <Icon name="lock" />
              <p>
                <b>This evaluation is closed.</b> Scores can no longer change. <Link to={back}>Back to the evaluation</Link>
              </p>
            </div>
          ) : (
            <AllScored project={project} evaluation={evaluation} back={back} />
          )}
        </>
      )}
    </>
  );
}

/** The end of the scoring (UX §3.4.5), with the next step for this role. */
function AllScored({ project, evaluation, back }) {
  const next = resultsLocked(project, evaluation)
    ? 'Results unlock when the owner closes this evaluation.'
    : project.role === 'owner'
      ? 'When everyone has finished, close the evaluation on its page.'
      : 'The owner closes the evaluation when everyone has finished.';

  return (
    <div className="empty" style={{ borderStyle: 'solid' }}>
      <span className="empty-icon">
        <Icon name="check" size="lg" />
      </span>
      <h2 className="empty-title">All {n(evaluation.answers.total)} answers scored</h2>
      <p>{next}</p>
      <div className="actions">
        <ResultsLink project={project} evaluation={evaluation} className="btn btn-primary">
          See the results
        </ResultsLink>
        <Link className="btn" to={back}>
          Back to the evaluation
        </Link>
      </div>
    </div>
  );
}

function readShortcuts() {
  try {
    return localStorage.getItem(SHORTCUTS_KEY) !== 'off';
  } catch {
    return true;
  }
}

/**
 * One answer to score (UX §4.1). Every change saves at once, in order, and says "Saved at 10:42" (role="status"). A
 * failed save stays in the queue: the alert offers Retry, and J (next) waits until the queue is empty.
 */
function ScoringItem({ project, evaluation, item }) {
  const navigate = useNavigate();
  const headingRef = useRef(null);
  const commentRef = useRef(null);
  const base = `/api/projects/${project.id}/evaluations/${evaluation.id}/items/${item.id}`;
  const scorePath = `${evaluationPath(project, evaluation)}/score`;
  const criteria = item.criteria;
  const manual = criteria.filter((criterion) => criterion.kind !== 'automatic');
  const automatic = criteria.filter((criterion) => criterion.kind === 'automatic');

  const [values, setValues] = useState(() => Object.fromEntries(item.scores.filter((score) => score.source === 'manual').map((score) => [score.criterionId, score.value])));
  const [comment, setComment] = useState(item.comment?.text ?? '');
  const savedComment = useRef(item.comment?.text ?? '');
  const [latest, setLatest] = useState(item);
  const [status, setStatus] = useState(null);
  const [shortcuts, setShortcuts] = useState(readShortcuts);
  // The changes not saved yet, by what they change ("score:<criterion id>", "comment"), and the chain that sends them.
  const pending = useRef(new Map());
  const queue = useRef(Promise.resolve());

  // The case's title takes the focus on every new answer, so a screen reader announces it (UX §4.1).
  useEffect(() => {
    headingRef.current?.focus();
  }, []);

  // Sends the pending changes one at a time, in order, until none is left: only then "Saved at…". A change made again
  // while its save is on the way is sent again, with the new value.
  const flush = () => {
    setStatus({ state: 'saving' });
    queue.current = queue.current.then(async () => {
      while (pending.current.size > 0) {
        const [key, send] = pending.current.entries().next().value;
        setStatus({ state: 'saving' });
        try {
          const updated = await send();
          if (pending.current.get(key) === send) {
            pending.current.delete(key);
          }
          setLatest(updated);
        } catch (error) {
          setStatus({ state: 'failed', problem: error instanceof ApiError ? error.problem : null });

          return;
        }
      }
      setStatus({ state: 'saved', at: new Date() });
    });

    return queue.current;
  };

  const change = (key, send) => {
    pending.current.set(key, send);
    flush();
  };

  const score = (criterion, value) => {
    setValues((current) => ({ ...current, [criterion.id]: value }));
    change(`score:${criterion.id}`, () => api(`${base}/scores/${criterion.id}`, { method: 'PUT', body: { value } }));
  };

  const saveComment = () => {
    if (comment === savedComment.current) {
      return;
    }
    savedComment.current = comment;
    const text = comment;
    change('comment', () => api(`${base}/comment`, { method: 'PUT', body: { comment: text } }));
  };

  const goNext = async () => {
    await queue.current;
    if (pending.current.size > 0) {
      return; // the alert says it: J waits until it is saved
    }
    navigate(`${scorePath}?after=${item.id}`);
  };

  const goPrevious = () => {
    if (item.previousItemId) {
      navigate(`${scorePath}/${item.previousItemId}`);
    }
  };

  // The shortcuts (UX §4.1): never in a box the user types in, never with Ctrl, Alt or Cmd, and not when turned off.
  useEffect(() => {
    if (!shortcuts) {
      return undefined;
    }
    const onKey = (event) => {
      if (event.ctrlKey || event.altKey || event.metaKey || event.defaultPrevented) {
        return;
      }
      if (event.key === 'Escape' && event.target === commentRef.current) {
        headingRef.current?.focus();

        return;
      }
      if (isTextField(event.target)) {
        return;
      }
      const key = event.key.toLowerCase();
      const focused = manual.find((criterion) => event.target instanceof Element && event.target.closest(`[data-criterion="${criterion.id}"]`));
      const target = (kind) => (focused?.kind === kind ? focused : manual.find((criterion) => criterion.kind === kind));
      if (/^[1-5]$/.test(key) && target('scale_1_5')) {
        event.preventDefault();
        score(target('scale_1_5'), Number(key));
      } else if ((key === 'p' || key === 'f') && target('pass_fail')) {
        event.preventDefault();
        score(target('pass_fail'), key === 'p' ? 1 : 0);
      } else if (key === 'j') {
        event.preventDefault();
        goNext();
      } else if (key === 'k') {
        event.preventDefault();
        goPrevious();
      } else if (key === 'c') {
        event.preventDefault();
        commentRef.current?.focus();
      }
    };
    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  });

  const toggleShortcuts = () => {
    try {
      localStorage.setItem(SHORTCUTS_KEY, shortcuts ? 'off' : 'on');
    } catch {
      // Storage blocked: the choice lasts until the page reloads.
    }
    setShortcuts(!shortcuts);
  };

  const automaticScore = (criterion) => latest.scores.find((entry) => entry.criterionId === criterion.id && entry.source === 'automatic');
  const { answers, caseAnswer } = latest;

  return (
    <>
      <div className="score-head">
        <div className="stack" style={{ gap: 8 }}>
          <h1 className="page-title">Score answers</h1>
          <div className="score-meta">
            {evaluation.blind && (
              <span className="pill pill-blind">
                <Icon name="eye-off" />
                Blind mode: run hidden
              </span>
            )}
            <span className="pill">
              Answer {caseAnswer.number} of {caseAnswer.count} for this case
            </span>
          </div>
        </div>
        <div className="actions">
          <button type="button" className="btn btn-sm" aria-pressed={shortcuts} onClick={toggleShortcuts}>
            <Icon name="keyboard" />
            {/* The state is aria-pressed; the visible "on"/"off" repeats it, so it is not read twice. */}
            <span>
              Keyboard shortcuts<span aria-hidden="true">: {shortcuts ? 'on' : 'off'}</span>
            </span>
          </button>
        </div>
      </div>

      {status?.state === 'failed' && (
        <div className="alert mb-5 items-center" role="alert">
          <Icon name="alert" />
          <p style={{ flex: 1 }}>
            <b>Not saved.</b> {status.problem ? status.problem.title : 'Check your connection.'} <span className="muted">J waits until it is saved.</span>
          </p>
          <button type="button" className="btn btn-sm" onClick={flush}>
            <Icon name="refresh" />
            Retry
          </button>
        </div>
      )}

      <div className="score-wrap">
        <article className="stack" style={{ gap: 20, minWidth: 0 }} aria-labelledby="case-title">
          <div className="flex flex-wrap items-center gap-2.5">
            <h2 ref={headingRef} className="case-title" id="case-title" tabIndex={-1}>
              Test case <bdi>{item.case.key}</bdi>
            </h2>
            {item.case.tags.length > 0 && (
              <ul className="tags" aria-label="Tags">
                {item.case.tags.map((tag) => (
                  <li key={tag} className="tag">
                    <bdi>{tag}</bdi>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <section className="case-block" aria-labelledby="input-heading">
            <h3 className="block-title" id="input-heading">
              Input
            </h3>
            <p className="textblock">{item.case.input}</p>
          </section>
          <section className="case-block" aria-labelledby="reference-heading">
            <h3 className="block-title" id="reference-heading">
              Reference answer
            </h3>
            {item.case.reference === null ? <p className="muted">This case has no reference answer.</p> : <p className="textblock">{item.case.reference}</p>}
          </section>
          <section className="case-block" aria-labelledby="answer-heading">
            <h3 className="block-title" id="answer-heading">
              Answer
              {/* Blind mode: the item has no run at all (ADR 0005), so nothing here can name it. */}
              {item.run ? (
                <RunName run={item.run} small />
              ) : (
                <span className="pill pill-blind" style={{ height: 20 }}>
                  <Icon name="eye-off" />
                  run hidden
                </span>
              )}
            </h3>
            <p className="textblock is-answer">{item.answer === '' ? <span className="muted">The answer is empty.</span> : item.answer}</p>
          </section>
        </article>

        <form className="panel" aria-label="Scores for this answer" onSubmit={(event) => event.preventDefault()}>
          {manual.map((criterion) => (
            <div key={criterion.id} className="panel-sec">
              <ManualCriterion criterion={criterion} value={values[criterion.id]} onScore={(value) => score(criterion, value)} />
            </div>
          ))}
          {automatic.length > 0 && (
            <div className="panel-sec">
              <h3 className="panel-title">Automatic checks</h3>
              <ul className="auto">
                {automatic.map((criterion) => (
                  <li key={criterion.id}>
                    <bdi>{criterion.name}</bdi>
                    <CheckState score={automaticScore(criterion)} />
                  </li>
                ))}
              </ul>
            </div>
          )}
          <div className="panel-sec">
            <div className="field">
              <label className="label" htmlFor="comment">
                Comment on this answer <span className="opt">(optional)</span>
              </label>
              <textarea
                ref={commentRef}
                id="comment"
                className="textarea"
                style={{ minHeight: 72 }}
                aria-describedby="comment-hint"
                maxLength={5000}
                value={comment}
                onChange={(event) => setComment(event.target.value)}
                onBlur={saveComment}
              />
              <p className="hint" id="comment-hint">
                One comment per answer, apart from the scores. Saved when you leave the box.
              </p>
            </div>
          </div>
          <div className="panel-sec">
            <h3 className="panel-title">Keyboard</h3>
            <dl className="keys">
              <dt>
                <span className="kbd">1</span>–<span className="kbd">5</span>
              </dt>
              <dd>score the criterion in focus (else the first)</dd>
              <dt>
                <span className="kbd">P</span> <span className="kbd">F</span>
              </dt>
              <dd>pass or fail</dd>
              <dt>
                <span className="kbd">J</span> <span className="kbd">K</span>
              </dt>
              <dd>next answer with missing scores, previous answer</dd>
              <dt>
                <span className="kbd">C</span> <span className="kbd">Esc</span>
              </dt>
              <dd>go to the comment, leave it</dd>
            </dl>
            <p className="hint mt-2.5">{shortcuts ? 'Never while you type in a box, and never with Ctrl, Alt or Cmd.' : 'Turned off: use the button at the top to turn them on.'}</p>
          </div>
        </form>
      </div>

      <div className="scorebar">
        <button type="button" className="btn" aria-label="Previous answer" disabled={!item.previousItemId} onClick={goPrevious}>
          <Icon name="chev-left" />
          <span className="hide-sm">Previous</span>
          <span className="kbd" aria-hidden="true">
            K
          </span>
        </button>
        <div className="scorebar-mid">
          <SaveStatus status={status} />
          <span>
            <b className="mono" style={{ color: 'var(--text)' }}>
              {n(answers.scored)}
            </b>{' '}
            of {n(answers.total)}
            <span className="hide-sm"> scored</span>
          </span>
        </div>
        <button type="button" className="btn btn-primary" aria-label="Next answer with missing scores" onClick={goNext}>
          Next<span className="hide-sm"> with missing scores</span>
          <span className="kbd" aria-hidden="true">
            J
          </span>
          <Icon name="chev-right" />
        </button>
      </div>
    </>
  );
}

/** Always rendered (role="status"), so a screen reader hears "Saved at 10:42" when it changes (WCAG 4.1.3). */
function SaveStatus({ status }) {
  return (
    <span role="status" className={status?.state === 'saved' ? 'saved' : status?.state === 'failed' ? 'state state-fail' : 'inline-flex items-center gap-1.5'}>
      {status?.state === 'saving' && (
        <>
          <Icon name="clock" />
          Saving…
        </>
      )}
      {status?.state === 'saved' && (
        <>
          <Icon name="check" />
          Saved at {clock.format(status.at)}
        </>
      )}
      {status?.state === 'failed' && (
        <>
          <Icon name="alert" />
          Not saved
        </>
      )}
    </span>
  );
}

/** A manual criterion: its guidance, then native radios (Tab between criteria, arrows within one). */
function ManualCriterion({ criterion, value, onScore }) {
  const guidanceId = `guidance-${criterion.id}`;
  const scale = criterion.kind === 'scale_1_5';
  const ends = scale ? scaleEnds(criterion.guidance) : null;
  const options = scale
    ? [1, 2, 3, 4, 5].map((number) => [number, String(number)])
    : [
        [1, 'Pass', 'check'],
        [0, 'Fail', 'x'],
      ];

  return (
    <fieldset className="crit-q" data-criterion={criterion.id} aria-describedby={criterion.guidance ? guidanceId : undefined}>
      <legend>
        <bdi>{criterion.name}</bdi> {scale && <span className="muted font-normal">(1–5)</span>}
      </legend>
      {criterion.guidance && (
        <p className="guidance" id={guidanceId}>
          {criterion.guidance}
        </p>
      )}
      <div className="scale">
        {options.map(([option, text, icon]) => (
          <label key={option} style={icon ? { gap: 8, gridAutoFlow: 'column' } : undefined}>
            <input type="radio" name={`score-${criterion.id}`} value={option} checked={value === option} onChange={() => onScore(option)} />
            {icon && <Icon name={icon} />}
            {text}
          </label>
        ))}
      </div>
      {ends && (
        <div className="scale-ends" aria-hidden="true">
          <span>{ends[0]}</span>
          <span>{ends[1]}</span>
        </div>
      )}
    </fieldset>
  );
}

const CHECK_ERRORS = { missing_reference: 'Error: no reference', regex_error: 'Error: the pattern failed' };

/** An automatic check, read only, in words and with an icon: ✓ Pass, ✗ Fail, Pending, or why it could not run. */
export function CheckState({ score }) {
  if (!score) {
    return (
      <span className="state state-pending">
        <Icon name="clock" />
        Pending
      </span>
    );
  }
  if (score.outcome === 'check_error') {
    return (
      <span className="state state-pending">
        <Icon name="alert" />
        {CHECK_ERRORS[score.errorCode] ?? 'Error: the check could not run'}
      </span>
    );
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
