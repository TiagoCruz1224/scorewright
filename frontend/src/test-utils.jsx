import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { vi } from 'vitest';
import { routes } from './routes.jsx';

/**
 * The whole app at `path`, with the real routes, against a fake API: `answers` maps each URL the app requests to its
 * JSON, or to a function of the request's init that returns a Response; any other URL answers 404. Resolves once the
 * page has its heading.
 */
export async function renderApp(path, answers) {
  const fetch = vi.fn(async (url, init) => {
    const answer = answers[url];
    if (answer === undefined) {
      return new Response(null, { status: 404 });
    }

    return typeof answer === 'function' ? answer(init) : Response.json(answer);
  });
  vi.stubGlobal('fetch', fetch);
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(<RouterProvider router={router} />);
  await screen.findByRole('heading', { level: 1 });

  return { router, fetch };
}

export const ME = { id: 'u1', email: 'ana@example.com', displayName: 'Ana' };

export const project = (fields) => ({ id: 'p1', name: 'Support bot', description: '', archivedAt: null, role: 'owner', ...fields });

export const page = (items) => ({ items, page: 1, perPage: 25, total: items.length });

/** An open, blind evaluation of two runs, as GET P/evaluations/{id} answers it: 4 answers, 1 with all its scores. */
export const evaluation = (fields = {}) => ({
  id: 'e1',
  name: 'Weekly check',
  status: 'open',
  blind: true,
  datasetVersion: { id: 'v1', number: 2, dataset: { id: 'd1', name: 'Support cases' } },
  rubric: { id: 'r1', name: 'Support quality' },
  progress: { scored: 3, total: 8 },
  answers: { scored: 1, total: 4 },
  createdAt: '2026-11-05T10:00:00+00:00',
  closedAt: null,
  runs: [
    { letter: 'A', id: 'run1', label: 'baseline-v1' },
    { letter: 'B', id: 'run2', label: 'tuned-v2' },
  ],
  checkJob: { status: 'succeeded', processed: 4, total: 4, errorCode: null },
  criteria: [
    { id: 'k1', name: 'Tone', kind: 'scale_1_5', checkType: null, scored: 2 },
    { id: 'k2', name: 'Correct', kind: 'pass_fail', checkType: null, scored: 1 },
    { id: 'k3', name: 'Mentions the order', kind: 'automatic', checkType: 'regex', scored: 4 },
  ],
  ...fields,
});

export const problemResponse = (status, problem) =>
  new Response(JSON.stringify({ status, ...problem }), { status, headers: { 'Content-Type': 'application/problem+json' } });

/** axe-core on the rendered page: each violation as "rule: targets", so a failure says what to fix. */
export async function axeViolations(node = document.body) {
  const { violations } = await axe.run(node, { resultTypes: ['violations'] });

  return violations.map((violation) => `${violation.id}: ${violation.nodes.map((target) => target.target.join(' ')).join(', ')}`);
}
