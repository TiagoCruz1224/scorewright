import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { axeViolations, evaluation, ME, problemResponse, project, renderApp } from '../test-utils.jsx';

const base = '/api/projects/p1/evaluations/e1';
const screenPath = '/projects/p1/evaluations/e1/score';

/** Item i2 of the blind evaluation e1: the second answer to case refund-7, with its automatic checks done. */
const item = (fields = {}) => ({
  id: 'i2',
  position: 2,
  previousItemId: 'i1',
  case: { key: 'refund-7', tags: ['billing'], input: 'I was charged twice for order 418220.', reference: 'Refund the second charge.' },
  caseAnswer: { number: 2, count: 2 },
  answer: 'Sorry! I refunded the second charge.',
  criteria: [
    { id: 'k1', kind: 'scale_1_5', name: 'Tone', guidance: '1: rude or cold. 3: polite but generic. 5: warm and clear, without filler.', weight: 2 },
    { id: 'k2', kind: 'pass_fail', name: 'Correct', guidance: 'Pass when the facts are right.', weight: null },
    { id: 'k3', kind: 'automatic', name: 'Mentions the order', guidance: '', weight: null },
    { id: 'k4', kind: 'automatic', name: 'Matches the reference', guidance: '', weight: null },
  ],
  scores: [
    { criterionId: 'k3', value: 1, outcome: 'scored', errorCode: null, source: 'automatic', scoredBy: null, updatedAt: '2026-11-05T10:00:00+00:00' },
    { criterionId: 'k4', value: null, outcome: 'check_error', errorCode: 'missing_reference', source: 'automatic', scoredBy: null, updatedAt: '2026-11-05T10:00:00+00:00' },
  ],
  comment: null,
  progress: { scored: 3, total: 8 },
  answers: { scored: 1, total: 4 },
  ...fields,
});

/** The scoring screen on item i2 as an evaluator; `extra` adds or replaces answers (such as the saves). */
const renderScoring = (extra = {}) =>
  renderApp(`${screenPath}/i2`, {
    '/api/me': ME,
    '/api/projects/p1': project({ role: 'evaluator' }),
    [base]: evaluation(),
    [`${base}/items/i2`]: item(),
    [`${base}/items/i2/scores/k1`]: () => Response.json(item()),
    [`${base}/items/i2/scores/k2`]: () => Response.json(item()),
    [`${base}/items/i2/comment`]: () => Response.json(item()),
    ...extra,
  });

/** The saves sent so far: [criterion or "comment", body]. */
const saves = (fetch) =>
  fetch.mock.calls.filter(([, init]) => init?.method === 'PUT').map(([url, init]) => [url.replace(`${base}/items/i2/`, ''), JSON.parse(init.body)]);

const press = (key, options = {}, target = document.activeElement ?? document.body) => fireEvent.keyDown(target, { key, ...options });
const caseHeading = (key) => screen.getByRole('heading', { level: 2, name: `Test case ${key}` });

describe('scoring', () => {
  it('puts the focus on the case and shows nothing of the run in blind mode', async () => {
    await renderScoring();

    await waitFor(() => expect(document.activeElement).toBe(caseHeading('refund-7')));
    screen.getByText('Blind mode: run hidden');
    screen.getByText('Answer 2 of 2 for this case');
    // The evaluation's runs are in memory (its page lists them), but no label or letter reaches the page.
    expect(document.body.innerHTML).not.toMatch(/baseline-v1|tuned-v2|run1|run2/);
    expect(document.querySelector('.run-letter')).toBeNull();
    expect(screenPath).not.toMatch(/run/);
    // The automatic checks are read-only, in words.
    const checks = within(screen.getByRole('heading', { name: 'Automatic checks' }).parentElement);
    expect(checks.getAllByRole('listitem').map((row) => row.textContent)).toEqual(['Mentions the orderPass', 'Matches the referenceError: no reference']);
  });

  it('scores with 1–5 and P/F, but never while typing in a box, with Ctrl, Alt or Cmd, or with the shortcuts off', async () => {
    const { fetch } = await renderScoring();
    await waitFor(() => expect(document.activeElement).toBe(caseHeading('refund-7')));

    press('4');
    press('p');
    await waitFor(() => expect(saves(fetch)).toEqual([['scores/k1', { value: 4 }], ['scores/k2', { value: 1 }]]));
    expect(screen.getByRole('radio', { name: '4' }).checked).toBe(true);
    expect(screen.getByRole('radio', { name: 'Pass' }).checked).toBe(true);

    // In the comment box the keys are text; with a modifier they belong to the browser.
    const comment = screen.getByLabelText(/Comment on this answer/);
    comment.focus();
    press('5', {}, comment);
    press('f', {}, comment);
    press('2', { ctrlKey: true }, document.body);
    press('3', { altKey: true }, document.body);
    press('1', { metaKey: true }, document.body);

    // Turned off: kept in the browser for the next answers.
    fireEvent.click(screen.getByRole('button', { name: 'Keyboard shortcuts' }));
    expect(screen.getByRole('button', { name: 'Keyboard shortcuts' }).getAttribute('aria-pressed')).toBe('false');
    expect(localStorage.getItem('sw-shortcuts')).toBe('off');
    press('5', {}, document.body);

    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(saves(fetch)).toEqual([['scores/k1', { value: 4 }], ['scores/k2', { value: 1 }]]);
    localStorage.removeItem('sw-shortcuts');
  });

  it('says when each change is saved, and saves the comment when the box is left', async () => {
    const { fetch } = await renderScoring();

    fireEvent.click(screen.getByRole('radio', { name: '5' }));
    const status = within(document.querySelector('.scorebar')).getByRole('status');
    await waitFor(() => expect(status.textContent).toMatch(/^Saved at \d\d:\d\d$/));

    const comment = screen.getByLabelText(/Comment on this answer/);
    fireEvent.change(comment, { target: { value: 'Says nothing about the date.' } });
    fireEvent.blur(comment);
    await waitFor(() => expect(saves(fetch)).toEqual([['scores/k1', { value: 5 }], ['comment', { comment: 'Says nothing about the date.' }]]));
    // C goes to the comment, Esc leaves it for the case.
    press('c', {}, document.body);
    expect(document.activeElement).toBe(comment);
    press('Escape', {}, comment);
    expect(document.activeElement).toBe(caseHeading('refund-7'));
  });

  it('keeps a failed save with Retry, and J does not move on until it is saved', async () => {
    let attempts = 0;
    const { fetch, router } = await renderScoring({
      [`${base}/items/i2/scores/k1`]: () => (++attempts === 1 ? Promise.reject(new TypeError('Failed to fetch')) : Response.json(item({ answers: { scored: 2, total: 4 } }))),
      [`${base}/items/next?after=i2`]: item({ id: 'i3', position: 3, previousItemId: 'i2', case: { key: 'order-2', tags: [], input: 'Where is my order?', reference: null }, caseAnswer: { number: 1, count: 2 } }),
      [`${base}/items/i3`]: item({ id: 'i3', position: 3, previousItemId: 'i2', case: { key: 'order-2', tags: [], input: 'Where is my order?', reference: null }, caseAnswer: { number: 1, count: 2 } }),
    });
    await waitFor(() => expect(document.activeElement).toBe(caseHeading('refund-7')));

    press('3', {}, document.body);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Not saved. Check your connection. J waits until it is saved.');

    press('j', {}, document.body);
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(router.state.location.pathname).toBe(`${screenPath}/i2`);
    expect(fetch.mock.calls.some(([url]) => url.includes('/items/next'))).toBe(false);

    fireEvent.click(within(alert).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
    expect(saves(fetch)).toEqual([['scores/k1', { value: 3 }], ['scores/k1', { value: 3 }]]);
    expect(document.querySelector('.scorebar-mid').textContent).toContain('2 of 4');

    // Saved: J opens the next answer with missing scores, and the focus goes to its case.
    press('j', {}, document.body);
    await waitFor(() => expect(router.state.location.pathname).toBe(`${screenPath}/i3`));
    await waitFor(() => expect(document.activeElement).toBe(caseHeading('order-2')));
    screen.getByText('This case has no reference answer.');
  });

  it('goes back with K to the answer before, in the evaluation’s order', async () => {
    const { router } = await renderScoring({ [`${base}/items/i1`]: item({ id: 'i1', position: 1, previousItemId: null, caseAnswer: { number: 1, count: 2 } }) });
    await waitFor(() => expect(document.activeElement).toBe(caseHeading('refund-7')));

    press('k', {}, document.body);

    await waitFor(() => expect(router.state.location.pathname).toBe(`${screenPath}/i1`));
    await screen.findByText('Answer 1 of 2 for this case');
    const previous = screen.getByRole('button', { name: 'Previous' });
    expect([previous.disabled, previous.getAttribute('aria-keyshortcuts')]).toEqual([true, 'K']);
  });

  it('says a closed evaluation is closed, without any field', async () => {
    await renderApp(`${screenPath}/i2`, {
      '/api/me': ME,
      '/api/projects/p1': project({ role: 'evaluator' }),
      [base]: evaluation({ status: 'closed', closedAt: '2026-11-05T12:00:00+00:00' }),
      [`${base}/items/i2`]: () => problemResponse(409, { code: 'evaluation_closed', title: 'The evaluation is closed' }),
    });

    screen.getByText('This evaluation is closed.');
    expect(document.querySelectorAll('main input, main textarea, main select')).toHaveLength(0);
    expect(screen.queryByRole('button', { name: /Next answer/ })).toBeNull();
  });

  it('ends with "All N answers scored", and an evaluator of a blind, open evaluation reads when the results unlock', async () => {
    await renderApp(screenPath, {
      '/api/me': ME,
      '/api/projects/p1': project({ role: 'evaluator' }),
      [base]: evaluation({ answers: { scored: 4, total: 4 } }),
      [`${base}/items/next`]: () => new Response(null, { status: 204 }),
    });

    screen.getByRole('heading', { name: 'All 4 answers scored' });
    screen.getByText('Results unlock when the owner closes this evaluation.');
  });

  it('has no accessibility violations (axe), open and blind', async () => {
    await renderScoring();
    await waitFor(() => expect(document.activeElement).toBe(caseHeading('refund-7')));

    expect(await axeViolations()).toEqual([]);
  });
});
