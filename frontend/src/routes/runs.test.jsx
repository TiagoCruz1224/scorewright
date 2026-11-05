import { fireEvent, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ME, page, problemResponse, project, renderApp } from '../test-utils.jsx';

const version = (id, number, caseCount) => ({ id, number, caseCount, sourceFilename: 'cases.csv', sha256: 'a'.repeat(64), createdAt: '2026-11-05T09:00:00+00:00', dataset: { id: 'd1', name: 'Support cases' } });
const versions = page([version('v2', 2, 200), version('v1', 1, 150)]);
const run = {
  id: 'r1',
  label: 'baseline-v1',
  modelDescription: 'Model X',
  datasetVersion: { id: 'v2', number: 2, caseCount: 200, dataset: { id: 'd1', name: 'Support cases' } },
  answerCount: 198,
  sourceFilename: 'baseline.csv',
  sha256: 'b'.repeat(64),
  createdAt: '2026-11-05T10:00:00+00:00',
};

const answers = (role, extra = {}) => ({
  '/api/me': ME,
  '/api/projects/p1': project({ role }),
  '/api/projects/p1/dataset-versions?perPage=100': versions,
  '/api/projects/p1/runs?page=1': page([run]),
  '/api/projects/p1/runs/r1': run,
  ...extra,
});

describe('runs', () => {
  it('shows each run’s coverage in words', async () => {
    await renderApp('/projects/p1/runs', answers('viewer'));

    const item = screen.getByRole('link', { name: 'baseline-v1' }).closest('li');
    expect(item.textContent).toContain('Support cases v2 · 198 of 200 answered');
  });

  it('shows a viewer the runs read-only', async () => {
    await renderApp('/projects/p1/runs', answers('viewer'));
    expect(screen.queryByRole('link', { name: 'Import run' })).toBeNull();
  });

  it('shows a viewer a run without Delete, and an owner with it', async () => {
    await renderApp('/projects/p1/runs/r1', answers('viewer'));
    screen.getByText('198 of 200 cases answered');
    expect(screen.queryByRole('button', { name: 'Delete run' })).toBeNull();
  });

  it('gives an owner Delete run', async () => {
    await renderApp('/projects/p1/runs/r1', answers('owner'));
    screen.getByRole('button', { name: 'Delete run' });
  });

  it('keeps the version, the label and the description after an error (WCAG 3.3.7)', async () => {
    await renderApp(
      '/projects/p1/runs/new',
      answers('owner', {
        '/api/projects/p1/runs': () =>
          problemResponse(422, {
            code: 'validation_failed',
            title: 'The request is not valid',
            errors: [{ field: 'label', code: 'label_taken', message: 'This dataset version already has a run with this label. Choose another label.' }],
          }),
      }),
    );
    const versionField = screen.getByLabelText('Dataset version');
    const label = screen.getByLabelText('Run label');
    const description = screen.getByLabelText('Model description');
    fireEvent.change(versionField, { target: { value: 'v1' } });
    fireEvent.change(label, { target: { value: 'baseline-v1' } });
    fireEvent.change(description, { target: { value: 'Model X\ntemperature 0' } });
    // jsdom's File cannot go into Node's Request (undici), which the router builds from the form; the file plays no
    // part here, and a disabled field stays out of the form data.
    screen.getByLabelText('CSV or JSON file').disabled = true;

    fireEvent.click(screen.getByRole('button', { name: 'Check and import' }));

    const summary = (await screen.findByRole('heading', { name: 'There is a problem' })).parentElement;
    within(summary).getByText('This dataset version already has a run with this label. Choose another label.');
    expect(label.getAttribute('aria-invalid')).toBe('true');
    expect([versionField.value, label.value, description.value]).toEqual(['v1', 'baseline-v1', 'Model X\ntemperature 0']);
  });
});
