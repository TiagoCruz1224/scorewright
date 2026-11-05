import { fireEvent, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client.js';
import { attemptUpload } from '../components/imports.jsx';
import { LIMITS } from '../importFormat.js';
import { ME, page, project, renderApp } from '../test-utils.jsx';
import { newDatasetAction } from './datasets.jsx';

/** A file that says it is this big; jsdom does not need the bytes. */
const fileOf = (size, name = 'cases.csv') => {
  const file = new File(['input\nHello'], name, { type: 'text/csv' });
  Object.defineProperty(file, 'size', { value: size });

  return file;
};

describe('uploads (SWQ2-02)', () => {
  it('refuses a file over the limit before uploading it', async () => {
    const call = vi.fn();

    const result = await attemptUpload(fileOf(LIMITS.maxFileBytes + 1), call, { fileName: 'cases.csv' });

    expect(call).not.toHaveBeenCalled();
    expect(result).toEqual({ fileName: 'cases.csv', problem: expect.objectContaining({ status: 413, code: 'payload_too_large' }) });
    expect(await attemptUpload(fileOf(LIMITS.maxFileBytes), async () => 'sent')).toBe('sent');
  });

  it('turns a network error or a gateway error into a problem for the form', async () => {
    const network = await attemptUpload(fileOf(10), () => Promise.reject(new TypeError('Failed to fetch')), { fileName: 'cases.csv' });
    const gateway = await attemptUpload(fileOf(10), () => Promise.reject(new ApiError({ status: 502, code: 'http_error', title: 'Something went wrong.', errors: [] })));
    const api = await attemptUpload(fileOf(10), () => Promise.reject(new ApiError({ status: 422, code: 'validation_failed', title: 'Not valid', errors: [] })));

    expect(network).toEqual({ fileName: 'cases.csv', problem: expect.objectContaining({ code: 'upload_failed' }) });
    expect(network.problem.title).toBe('The file could not be sent. Check that it is at most 5 MiB and that you are online, then try again.');
    expect(gateway.problem.code).toBe('upload_failed');
    expect(api.problem.code).toBe('validation_failed');
  });

  it('never sends a file over the limit from the New dataset form', async () => {
    const fetch = vi.fn();
    vi.stubGlobal('fetch', fetch);
    const form = new FormData();
    form.set('name', 'Support cases');
    form.set('file', fileOf(7 * 1024 * 1024, 'big.csv'));

    const result = await newDatasetAction({ params: { projectId: 'p1' }, request: { formData: async () => form, url: 'http://localhost/projects/p1/datasets/new' } });

    expect(fetch).not.toHaveBeenCalled();
    expect([result.fileName, result.problem.status]).toEqual(['big.csv', 413]);
  });

  it('keeps the form and what was typed when the upload cannot be sent (WCAG 3.3.7)', async () => {
    await renderApp('/projects/p1/datasets/new', {
      '/api/me': ME,
      '/api/projects/p1': project({ role: 'owner' }),
      '/api/projects/p1/datasets?page=1': page([]),
      '/api/projects/p1/datasets': () => Promise.reject(new TypeError('Failed to fetch')),
    });
    const name = screen.getByLabelText('Name');
    fireEvent.change(name, { target: { value: 'Support cases' } });
    // jsdom's File cannot go into Node's Request (undici), which the router builds from the form; a disabled field
    // stays out of it, and the fetch fails either way.
    screen.getByLabelText('CSV or JSON file').disabled = true;

    fireEvent.click(screen.getByRole('button', { name: 'Check and import' }));

    const summary = (await screen.findByRole('heading', { name: 'There is a problem' })).parentElement;
    within(summary).getByText(/The file could not be sent/);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('New dataset');
    expect(name.value).toBe('Support cases');
  });
});
