import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ME, page, project, renderApp } from '../test-utils.jsx';

const version = (number, caseCount) => ({ id: `v${number}`, number, caseCount, sourceFilename: `cases-v${number}.csv`, sha256: 'a'.repeat(64), createdAt: '2026-11-05T09:00:00+00:00' });
const dataset = { id: 'd1', name: 'Support cases', createdAt: '2026-11-05T09:00:00+00:00', versions: [version(2, 2), version(1, 1)] };
const cases = page([
  { id: 'c1', caseKey: 'html', input: '<b>bold?</b> <script>alert(1)</script>', reference: 'Plain <i>text</i>', tags: ['billing'], metadata: {} },
  { id: 'c2', caseKey: 'refund-1', input: 'I want a refund.\nMy order is late.', reference: null, tags: [], metadata: {} },
]);

/** The dataset page as `role`, with the cases of version 2 (filtered ones too). */
const renderDataset = (role, path = '/projects/p1/datasets/d1') =>
  renderApp(path, {
    '/api/me': ME,
    '/api/projects/p1': project({ role }),
    '/api/projects/p1/datasets/d1': dataset,
    '/api/projects/p1/dataset-versions/v2/cases?page=1': cases,
    '/api/projects/p1/dataset-versions/v2/cases?page=1&tag=billing&q=refund': page([cases.items[1]]),
  });

describe('dataset page', () => {
  it('shows the cases as text, never as HTML', async () => {
    await renderDataset('owner');

    const table = screen.getByRole('region', { name: 'Cases of v2' });
    const cell = within(table).getByText('<b>bold?</b> <script>alert(1)</script>');
    expect(cell.tagName).toBe('TD');
    expect(table.querySelector('b, i, script')).toBeNull();
    within(table).getByText('Plain <i>text</i>');
  });

  it('puts the filters in the URL, and applies them only with Update', async () => {
    const { router, fetch } = await renderDataset('owner');

    fireEvent.change(screen.getByLabelText('Tag'), { target: { value: 'billing' } });
    fireEvent.change(screen.getByLabelText('Text'), { target: { value: 'refund' } });
    expect(router.state.location.search).toBe('');

    fireEvent.click(screen.getByRole('button', { name: 'Update' }));
    await waitFor(() => expect(router.state.location.search).toBe('?version=v2&tag=billing&q=refund'));
    await screen.findByText('1 case match the filters.');
    expect(fetch.mock.calls.map(([url]) => url)).toContain('/api/projects/p1/dataset-versions/v2/cases?page=1&tag=billing&q=refund');
  });

  it('gives the owner Import new version and Delete dataset', async () => {
    await renderDataset('owner');

    screen.getByRole('link', { name: 'Import new version' });
    screen.getByRole('button', { name: 'Delete dataset' });
  });

  it('shows a viewer the dataset read-only', async () => {
    await renderDataset('viewer');

    screen.getByRole('region', { name: 'Cases of v2' });
    expect(screen.queryByRole('link', { name: 'Import new version' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete dataset' })).toBeNull();
  });
});

describe('datasets list', () => {
  const renderList = (role) =>
    renderApp('/projects/p1/datasets', {
      '/api/me': ME,
      '/api/projects/p1': project({ role }),
      '/api/projects/p1/datasets?page=1': page([]),
    });

  it('invites the owner to import the first dataset', async () => {
    await renderList('owner');

    screen.getByRole('heading', { name: 'Import your first dataset' });
    screen.getByRole('link', { name: 'New dataset' });
  });

  it('tells the other roles that the owner imports datasets, with no button', async () => {
    await renderList('evaluator');

    screen.getByText('The owner imports datasets for this project.');
    expect(screen.queryByRole('link', { name: 'New dataset' })).toBeNull();
  });
});
