import { render, screen, within } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it } from 'vitest';
import { ImportRejected } from './imports.jsx';

/** ImportRejected has links, so it renders inside a router. */
const renderRejected = (props) =>
  render(<RouterProvider router={createMemoryRouter([{ path: '/', element: <ImportRejected {...props} /> }])} />);

const csvProblem = {
  status: 422,
  code: 'import_rejected',
  title: 'Import rejected: nothing was saved',
  errorCount: 103,
  affectedLines: 61,
  errorCountsByCode: { required: 2, too_long: 100, duplicate_case_key: 1 },
  truncated: true,
  errors: [
    { line: 14, field: 'input', code: 'required', message: 'The input is empty.', excerpt: '' },
    { line: 57, field: 'input', code: 'too_long', message: 'The input has 20,001 characters; the limit is 20,000.', excerpt: 'Below is the transcript' },
    { line: null, field: null, code: 'too_long', message: 'A value is far too long to read.', excerpt: '' },
  ],
};

describe('ImportRejected', () => {
  it('says what happened, counts the errors by type in words, and takes the focus', async () => {
    renderRejected({ problem: csvProblem, fileName: 'support-cases.csv', unchanged: 'Version v3 is unchanged.' });

    const heading = await screen.findByRole('heading', { name: 'Import rejected: nothing was saved' });
    expect(document.activeElement).toBe(heading);
    const summary = heading.parentElement;
    expect(summary.textContent).toContain('support-cases.csv has 103 errors in 61 lines. Fix them and import the file again. Version v3 is unchanged.');
    expect(within(screen.getByRole('list', { name: 'Errors by type' })).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      '2 × required value missing',
      '100 × text too long',
      '1 × duplicate case id',
    ]);
    within(summary).getByRole('link', { name: 'CSV and JSON format' });
  });

  it('lists the errors by line, with excerpts, and how many more were left out', async () => {
    renderRejected({ problem: csvProblem, fileName: 'support-cases.csv', unchanged: 'No dataset was created.' });

    const table = await screen.findByRole('region', { name: 'Errors by line' });
    expect(within(table).getAllByRole('columnheader').map((cell) => cell.textContent)).toEqual(['Line', 'Field', 'Problem']);
    expect(within(table).getAllByRole('rowheader').map((cell) => cell.textContent)).toEqual(['14', '57', 'File']);
    within(table).getByText('Below is the transcript');
    screen.getByText('Line numbers count the header as line 1, as in a spreadsheet.');
    screen.getByText('and 100 more errors of the same kinds');
    screen.getByText(/No dataset was created\./);
  });

  it('numbers the errors of a JSON file by item', async () => {
    const problem = {
      ...csvProblem,
      errorCount: 1,
      affectedLines: 1,
      errorCountsByCode: { invalid_type: 1 },
      truncated: false,
      errors: [{ item: 3, field: 'input', code: 'invalid_type', message: 'The input must be a string.', excerpt: '3' }],
    };
    renderRejected({ problem, fileName: 'cases.json' });

    const table = await screen.findByRole('region', { name: 'Errors by item' });
    expect(within(table).getAllByRole('columnheader')[0].textContent).toBe('Item');
    expect(within(table).getByRole('rowheader').textContent).toBe('3');
    expect(screen.getByRole('heading', { name: 'Import rejected: nothing was saved' }).parentElement.textContent).toContain('cases.json has 1 error in 1 item.');
    expect(screen.queryByText(/more error/)).toBeNull();
  });
});
