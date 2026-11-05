import { act, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { axeViolations, evaluation, ME, page, problemResponse, project, renderApp } from '../test-utils.jsx';
import { POLL_MS } from './evaluations.jsx';

const version = { id: 'v1', number: 2, caseCount: 3, dataset: { id: 'd1', name: 'Support cases' } };
const run = (id, label, createdAt, fields = {}) => ({ id, label, modelDescription: `${label} model`, answerCount: 3, datasetVersion: version, createdAt, ...fields });
const runs = [run('run2', 'tuned-v2', '2026-11-05T11:00:00+00:00'), run('run1', 'baseline-v1', '2026-11-05T10:00:00+00:00')];
const rubric = { id: 'r1', name: 'Support quality', criterionCount: 4, automaticCount: 2, lockedAt: null };

const newEvaluationAnswers = (post) => ({
  '/api/me': ME,
  '/api/projects/p1': project({ role: 'owner' }),
  '/api/projects/p1/dataset-versions?perPage=100': page([version]),
  '/api/projects/p1/runs?perPage=100': page(runs),
  '/api/projects/p1/rubrics?perPage=100': page([rubric]),
  '/api/projects/p1/evaluations': post,
});

afterEach(() => {
  vi.useRealTimers();
});

describe('new evaluation', () => {
  it('gives the runs their letters in import order, sums up, and shows each API error next to its field, runs[N] included', async () => {
    let sent;
    const { fetch } = await renderApp(
      '/projects/p1/evaluations/new',
      newEvaluationAnswers((init) => {
        sent = JSON.parse(init.body);

        return problemResponse(422, {
          code: 'validation_failed',
          title: 'The request is not valid',
          errors: [
            { field: 'name', code: 'name_taken', message: 'The project already has an evaluation with this name. Choose another name.' },
            { field: 'runs[1]', code: 'other_dataset_version', message: 'This run answers another dataset version. Choose runs of version 2 of Support cases.' },
          ],
        });
      }),
    );

    // Oldest first: the baseline gets A.
    const checklist = within(screen.getByRole('group', { name: 'Runs' }));
    const boxes = checklist.getAllByRole('checkbox');
    expect(boxes.map((box) => box.closest('label').querySelector('b').textContent)).toEqual(['baseline-v1', 'tuned-v2']);
    fireEvent.click(boxes[1]);
    fireEvent.click(boxes[0]);
    expect(boxes.map((box) => box.closest('label').querySelector('.run-letter')?.textContent)).toEqual(['Letter A', 'Letter B']);
    screen.getByText('6 answers to score · 2 manual criteria · 2 automatic');
    expect(screen.getByRole('checkbox', { name: /Blind mode/ }).checked).toBe(true);

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Weekly check' } });
    fireEvent.click(screen.getByRole('button', { name: 'Start evaluation' }));

    const summary = (await screen.findByRole('heading', { name: 'There is a problem' })).parentElement;
    expect(sent).toEqual({ name: 'Weekly check', datasetVersion: 'v1', runs: ['run1', 'run2'], rubric: 'r1', blind: true });
    expect(fetch.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(1);
    expect(screen.getByLabelText('Name').getAttribute('aria-invalid')).toBe('true');
    // runs[1] is the second run sent, tuned-v2: its box carries the error, and the summary links to it.
    const tuned = screen.getByRole('checkbox', { name: /tuned-v2/ });
    expect(tuned.id).toBe('field-runs[1]');
    expect(tuned.getAttribute('aria-invalid')).toBe('true');
    within(tuned.closest('label')).getByText('This run answers another dataset version. Choose runs of version 2 of Support cases.');
    expect(within(summary).getByRole('link', { name: /another dataset version/ }).getAttribute('href')).toBe('#field-runs[1]');
    expect(screen.getByRole('checkbox', { name: /baseline-v1/ }).getAttribute('aria-invalid')).toBeNull();
  });

  it('has no accessibility violations (axe)', async () => {
    await renderApp('/projects/p1/evaluations/new', newEvaluationAnswers(() => Response.json({})));
    fireEvent.click(screen.getAllByRole('checkbox')[0]);

    expect(await axeViolations()).toEqual([]);
  });
});

describe('the evaluation page', () => {
  it('asks for the checks every 5 s while they run, and stops once they are done', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    let reads = 0;
    const running = evaluation({ checkJob: { status: 'running', processed: 1000, total: 4, errorCode: null } });
    const done = evaluation({ checkJob: { status: 'succeeded', processed: 4, total: 4, errorCode: null } });
    await renderApp('/projects/p1/evaluations/e1', {
      '/api/me': ME,
      '/api/projects/p1': project({ role: 'viewer' }),
      '/api/projects/p1/evaluations/e1': () => Response.json(++reads === 1 ? running : done),
    });

    const checks = () => document.querySelector('.stat[role="status"]').textContent;
    expect(checks()).toContain('running');
    expect(reads).toBe(1);

    await act(() => vi.advanceTimersByTimeAsync(POLL_MS));
    await waitFor(() => expect(checks()).toContain('done'));
    expect(reads).toBe(2);

    await act(() => vi.advanceTimersByTimeAsync(4 * POLL_MS));
    expect(reads).toBe(2);
  });

  it('explains a 409 check_job_running when the owner closes while the checks run', async () => {
    await renderApp('/projects/p1/evaluations/e1', {
      '/api/me': ME,
      '/api/projects/p1': project({ role: 'owner' }),
      '/api/projects/p1/evaluations/e1': evaluation({ checkJob: { status: 'queued', processed: 0, total: 4, errorCode: null } }),
      '/api/projects/p1/evaluations/e1/close': () => problemResponse(409, { code: 'check_job_running', title: 'The automatic checks of this evaluation are queued or running. Wait for them to finish' }),
    });

    fireEvent.click(screen.getByRole('button', { name: 'Close evaluation' }));
    expect(document.querySelector('form.confirm p').textContent).toBe('Close “Weekly check” with 3 answers still unscored?');
    fireEvent.click(within(document.querySelector('form.confirm')).getByRole('button', { name: 'Close evaluation' }));

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Not closed.');
    expect(alert.textContent).toContain('The automatic checks are still queued or running');
    // Not closed: still open, and the checks cannot be run again while they are active.
    within(document.querySelector('.page-head')).getByText('Open');
    expect(screen.queryByRole('button', { name: 'Run checks again' })).toBeNull();
  });
});
