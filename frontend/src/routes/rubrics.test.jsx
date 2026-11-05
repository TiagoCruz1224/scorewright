import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ME, page, problemResponse, project, renderApp } from '../test-utils.jsx';

const rubric = (fields = {}) => ({
  id: 'r1',
  name: 'Support quality',
  criterionCount: 3,
  lockedAt: null,
  duplicatedFrom: null,
  criteria: [
    { id: 'k1', kind: 'scale_1_5', name: 'Tone', guidance: '1: rude. 5: warm.', weight: 2, checkType: null, checkParams: null },
    { id: 'k2', kind: 'automatic', name: 'Mentions the order', guidance: '', weight: null, checkType: 'contains', checkParams: { text: 'order', ignoreCase: true } },
    { id: 'k3', kind: 'automatic', name: 'Starts with Refund', guidance: '', weight: null, checkType: 'regex', checkParams: { pattern: '^Refund', ignoreCase: false } },
  ],
  ...fields,
});

const answers = (role, rubricAnswer = rubric()) => ({
  '/api/me': ME,
  '/api/projects/p1': project({ role }),
  '/api/projects/p1/rubrics?page=1': page([rubricAnswer]),
  '/api/projects/p1/rubrics/r1': rubricAnswer,
});

// The legends of the criteria's fieldsets (the account menu is a group too).
const criterionNames = () => [...document.querySelectorAll('fieldset > legend')].map((legend) => legend.textContent);

describe('rubrics', () => {
  it('moves a criterion with Move up and Move down, and keeps the focus on it (WCAG 2.5.7)', async () => {
    await renderApp('/projects/p1/rubrics/r1/edit', answers('owner'));
    expect(criterionNames()).toEqual(['Criterion 1: Tone', 'Criterion 2: Mentions the order', 'Criterion 3: Starts with Refund']);

    fireEvent.click(screen.getByRole('button', { name: 'Move down: Tone' }));
    expect(criterionNames()).toEqual(['Criterion 1: Mentions the order', 'Criterion 2: Tone', 'Criterion 3: Starts with Refund']);
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Move down: Tone' }));
    screen.getByText('Tone moved to position 2 of 3.');

    fireEvent.click(screen.getByRole('button', { name: 'Move down: Tone' }));
    // At the end of the list Move down is disabled, so the focus goes to Move up of the same criterion.
    expect(criterionNames()).toEqual(['Criterion 1: Mentions the order', 'Criterion 2: Starts with Refund', 'Criterion 3: Tone']);
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Move up: Tone' }));

    fireEvent.click(screen.getByRole('button', { name: 'Move up: Starts with Refund' }));
    expect(criterionNames()).toEqual(['Criterion 1: Starts with Refund', 'Criterion 2: Mentions the order', 'Criterion 3: Tone']);
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Move down: Starts with Refund' }));
  });

  it('shows each error next to its field, by the API’s path, even after the criterion moves', async () => {
    await renderApp('/projects/p1/rubrics/r1/edit', {
      ...answers('owner'),
      '/api/projects/p1/rubrics/r1': (init) =>
        init?.method === 'PUT'
          ? problemResponse(422, {
              code: 'validation_failed',
              title: 'The request is not valid',
              errors: [{ field: 'criteria[2].checkParams.pattern', code: 'invalid_regex', message: 'This pattern does not compile: missing closing parenthesis at offset 7.' }],
            })
          : Response.json(rubric()),
    });

    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    const summary = (await screen.findByRole('heading', { name: 'There is a problem' })).parentElement;
    const link = within(summary).getByRole('link', { name: 'This pattern does not compile: missing closing parenthesis at offset 7.' });
    const regexCriterion = () => screen.getByRole('group', { name: /Starts with Refund/ });
    const pattern = within(regexCriterion()).getByLabelText('Pattern');
    expect(pattern.getAttribute('aria-invalid')).toBe('true');
    expect(link.getAttribute('href')).toBe(`#${pattern.id}`);
    within(regexCriterion()).getByText('This pattern does not compile: missing closing parenthesis at offset 7.');
    expect(within(screen.getByRole('group', { name: /Tone/ })).queryByText(/does not compile/)).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Move up: Starts with Refund' }));
    expect(within(regexCriterion()).getByLabelText('Pattern').getAttribute('aria-invalid')).toBe('true');
  });

  it('shows a viewer the rubric read-only, with each check in words', async () => {
    await renderApp('/projects/p1/rubrics/r1', answers('viewer'));

    screen.getByText('Contains: “order” (ignoring case)');
    screen.getByText('Regex: ^Refund');
    expect(screen.getAllByText('1 (default)')).toHaveLength(2);
    for (const name of ['Edit rubric', 'Duplicate', 'Delete rubric', 'Save']) {
      expect(screen.queryByRole('button', { name }) ?? screen.queryByRole('link', { name })).toBeNull();
    }
  });

  it('opens a locked rubric read-only, with Duplicate and without Save', async () => {
    const { router } = await renderApp('/projects/p1/rubrics/r1/edit', answers('owner', rubric({ lockedAt: '2026-11-05T12:00:00+00:00' })));

    await waitFor(() => expect(router.state.location.pathname).toBe('/projects/p1/rubrics/r1'));
    screen.getByText('Used in an evaluation: duplicate it to change it.');
    screen.getByRole('button', { name: 'Duplicate' });
    expect(screen.getByLabelText('Name of the copy').value).toBe('Support quality (copy)');
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Edit rubric' })).toBeNull();
  });

  it('lists a locked rubric with the words, not only a sign', async () => {
    await renderApp('/projects/p1/rubrics', answers('viewer', rubric({ lockedAt: '2026-11-05T12:00:00+00:00' })));

    expect(screen.getByRole('link', { name: 'Support quality' }).closest('li').textContent).toBe('Support quality3 criteria · Locked: used in an evaluation');
    expect(screen.queryByRole('link', { name: 'New rubric' })).toBeNull();
  });
});
