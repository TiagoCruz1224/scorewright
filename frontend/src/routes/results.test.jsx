import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { axeViolations, evaluation, ME, problemResponse, project, renderApp } from '../test-utils.jsx';

const run = (letter, label, fields) => ({ letter, id: `run-${letter}`, label, cases: 4, answers: 4, scored: 4, unscored: 0, ...fields });
const measures = (letter, fields) => ({ letter, n: 4, mean: null, passRate: null, checkErrors: 0, ...fields });
const counts = (...values) => values.map((count, index) => ({ value: values.length === 2 ? 1 - index : index + 1, count }));

/** Three runs: the columns grow, and each run is compared with A. */
const results = {
  runs: [run('A', 'baseline-v1', { scored: 3, unscored: 1, overall: 61.4 }), run('B', 'tuned-v2', { answers: 3, scored: 3, overall: 75 }), run('C', 'small-v1', { overall: null, scored: 0, unscored: 4 })],
  criteria: [
    {
      id: 'k1',
      name: 'Tone',
      kind: 'scale_1_5',
      checkType: null,
      weight: 2,
      guidance: '1: rude or cold. 5: warm and clear, without filler.',
      measure: 'mean',
      runs: [
        measures('A', { mean: 3.4, distribution: counts(1, 0, 1, 1, 1) }),
        measures('B', { n: 3, mean: 4.3, distribution: counts(0, 0, 0, 2, 1) }),
        measures('C', { n: 0, distribution: counts(0, 0, 0, 0, 0) }),
      ],
    },
    {
      id: 'k3',
      name: 'Matches the reference',
      kind: 'automatic',
      checkType: 'exact_match',
      weight: null,
      guidance: '',
      measure: 'passRate',
      runs: [
        measures('A', { n: 3, passRate: 66.7, distribution: counts(2, 1), checkErrors: 1 }),
        measures('B', { n: 3, passRate: 100, distribution: counts(3, 0) }),
        measures('C', { n: 0, distribution: counts(0, 0) }),
      ],
    },
  ],
  tags: [
    {
      tag: 'billing',
      cases: 2,
      criteria: [
        { id: 'k1', runs: [measures('A', { n: 2, mean: 3.5 }), measures('B', { n: 2, mean: 3 }), measures('C', { n: 0 })] },
        { id: 'k3', runs: [measures('A', { passRate: 50 }), measures('B', { passRate: 100 }), measures('C', { n: 0 })] },
      ],
    },
    {
      tag: 'refund',
      cases: 1,
      criteria: [
        { id: 'k1', runs: [measures('A', { n: 1, mean: 2 }), measures('B', { n: 1, mean: 4 }), measures('C', { n: 0 })] },
        { id: 'k3', runs: [measures('A', { passRate: 100 }), measures('B', { passRate: 100 }), measures('C', { n: 0 })] },
      ],
    },
  ],
};

const renderResults = (role = 'owner', answer = results, fields = {}) =>
  renderApp('/projects/p1/evaluations/e1/results', {
    '/api/me': ME,
    '/api/projects/p1': project({ role }),
    '/api/projects/p1/evaluations/e1': evaluation(fields),
    '/api/projects/p1/evaluations/e1/results': answer,
  });

const rows = (region) => within(screen.getByRole('region', { name: region })).getAllByRole('row').map((row) => [...row.querySelectorAll('th, td')].map((cell) => cell.textContent.trim()));

describe('results', () => {
  it('shows each criterion per run, with the overall score, the coverage, and check errors apart', async () => {
    await renderResults();

    expect(rows('By criterion')).toEqual([
      ['Criterion', 'Measure', 'Abaseline-v1', 'Btuned-v2', 'Csmall-v1', 'Bars'],
      ['Overall score', 'Weighted, 0–100', '61.4', '75.0', '—no scores yet', ''],
      ['Tone', 'Average, 1–5', '3.4', '4.3', '—no scores yet', ''],
      ['Matches the reference', 'Pass rate, automatic', '67%1 not checked', '100%', '—no scores yet', ''],
      ['Answers with every score', 'Coverage', '3 of 4', '3 of 3', '0 of 4', ''],
    ]);
    // The owner reads them while it is open; evaluators of a blind one wait for the close.
    screen.getByText(/^6 of 11 answers have every score, so these numbers can still change./);
    expect(screen.getByRole('link', { name: 'close the evaluation' }).getAttribute('href')).toBe('/projects/p1/evaluations/e1');
    expect(within(screen.getByRole('navigation', { name: 'Evaluation' })).getByRole('link', { name: 'Results' }).getAttribute('aria-current')).toBe('page');
    // The CSV is the API's attachment, downloaded by a plain link.
    expect(screen.getByRole('link', { name: 'Export results (CSV)' }).getAttribute('href')).toBe('/api/projects/p1/evaluations/e1/export.csv');
  });

  it('describes the distribution in a sentence, and shows the chart as a table on request', async () => {
    await renderResults();

    const figure = screen.getByRole('figure', { name: 'Tone: how often each score was given' });
    expect(figure.getAttribute('aria-describedby')).toBe('distribution-summary');
    expect(document.getElementById('distribution-summary').textContent).toBe('Scored 4 or 5: A on 2 of 4 answers, B on 3 of 3 answers, C on 0 of 0 answers.');
    // The bars are a picture of the table: hidden from screen readers, which get the summary and the table.
    expect(figure.querySelector('.chart').getAttribute('aria-hidden')).toBe('true');
    const details = within(figure).getByText('Show this chart as a table').closest('details');
    expect(details.open).toBe(false);
    expect([...details.querySelectorAll('tr')].map((row) => [...row.children].map((cell) => cell.textContent))).toEqual([
      ['Score', 'A baseline-v1', 'B tuned-v2', 'C small-v1'],
      ['1', '1', '0', '0'],
      ['2', '0', '0', '0'],
      ['3', '1', '0', '0'],
      ['4', '1', '2', '0'],
      ['5', '1', '1', '0'],
    ]);
  });

  it('writes each run’s difference from A with its sign, never only in colour', async () => {
    await renderResults();

    expect(rows('Tone by tag')).toEqual([
      ['Tag', 'Cases', 'A', 'B', 'C', 'B − A', 'C − A', 'Bars'],
      ['billing', '2', '3.5', '3.0', '—no scores yet', '−0.5', '—no scores yet', ''],
      ['refund', '1', '2.0', '4.0', '—no scores yet', '+2.0', '—no scores yet', ''],
    ]);
    const lower = within(screen.getByRole('region', { name: 'Tone by tag' })).getByText('−0.5');
    expect(lower.className).toBe('diff diff-neg');
  });

  it('shows an evaluator of a blind, open evaluation the locked page instead of an error', async () => {
    await renderResults('evaluator', () => problemResponse(403, { code: 'results_hidden_until_closed', title: 'Hidden until closed' }));

    screen.getByRole('heading', { name: 'Results unlock when the owner closes this evaluation' });
    expect(screen.queryByRole('table')).toBeNull();
    expect(screen.queryByRole('link', { name: 'Export results (CSV)' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Continue scoring' }).getAttribute('href')).toBe('/projects/p1/evaluations/e1/score');
  });

  it('tells everyone that the results of a closed evaluation no longer change', async () => {
    await renderResults('viewer', results, { status: 'closed', closedAt: '2026-11-05T12:00:00+00:00' });

    screen.getByText('The evaluation is closed: these results no longer change.');
    expect(screen.queryByRole('link', { name: 'close the evaluation' })).toBeNull();
  });

  it('has no accessibility violations (axe)', async () => {
    await renderResults();

    expect(await axeViolations()).toEqual([]);
  });
});
