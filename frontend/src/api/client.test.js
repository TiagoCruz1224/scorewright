import { describe, expect, it, vi } from 'vitest';
import { api, ApiError, safeNext } from './client.js';

const problemJson = (status, problem, headers = {}) =>
  new Response(JSON.stringify(problem), { status, headers: { 'Content-Type': 'application/problem+json', ...headers } });

describe('api', () => {
  it('sends the same random csrf-token on every unsafe request, and none on reads', async () => {
    const fetch = vi.fn(async () => new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetch);

    await api('/api/projects', { method: 'POST', body: { name: 'Support bot' } });
    await api('/api/projects/1/members/2', { method: 'DELETE' });
    await api('/api/projects');

    const [post, remove, get] = fetch.mock.calls.map(([, init]) => init);
    expect(post.headers['csrf-token']).toMatch(/^[-\w]{24,}$/);
    expect(remove.headers['csrf-token']).toBe(post.headers['csrf-token']);
    expect(get.headers['csrf-token']).toBeUndefined();
    expect(post.headers['Content-Type']).toBe('application/json');
    expect(post.body).toBe('{"name":"Support bot"}');
  });

  it('sends a FormData as it is, for the browser to encode as multipart (uploads)', async () => {
    const fetch = vi.fn(async () => Response.json({}));
    vi.stubGlobal('fetch', fetch);
    const body = new FormData();
    body.set('name', 'Support cases');

    await api('/api/projects/1/datasets', { method: 'POST', body });

    const [, init] = fetch.mock.calls[0];
    expect(init.body).toBe(body);
    expect(init.headers['Content-Type']).toBeUndefined();
    expect(init.headers['csrf-token']).toMatch(/^[-\w]{24,}$/);
  });

  it('returns the JSON of a success, and null for 204', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => Response.json({ id: '1' })));
    expect(await api('/api/projects/1')).toEqual({ id: '1' });
  });

  it('throws Problem Details as an ApiError, with the field errors', async () => {
    const errors = [{ field: 'email', code: 'no_account', message: 'No account uses this email.' }];
    vi.stubGlobal('fetch', vi.fn(async () => problemJson(422, { title: 'The request is not valid', status: 422, code: 'validation_failed', errors })));

    const error = await api('/api/projects/1/members', { method: 'POST', body: {} }).catch((thrown) => thrown);

    expect(error).toBeInstanceOf(ApiError);
    expect(error.problem).toMatchObject({ status: 422, code: 'validation_failed', title: 'The request is not valid', errors });
  });

  it('gives a 429 its Retry-After in seconds', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => problemJson(429, { title: 'Too many invites: try again later', code: 'too_many_invites' }, { 'Retry-After': '120' })));

    const error = await api('/api/projects/1/members', { method: 'POST', body: {} }).catch((thrown) => thrown);

    expect(error.problem).toMatchObject({ status: 429, code: 'too_many_invites', errors: [], retryAfter: 120 });
  });

  it('describes an error answer that is not Problem Details', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('<h1>Bad gateway</h1>', { status: 502, headers: { 'Content-Type': 'text/html' } })));

    const error = await api('/api/projects').catch((thrown) => thrown);

    expect(error.problem).toEqual({ status: 502, code: 'http_error', title: 'Something went wrong. Try again in a moment.', errors: [] });
  });

  it('answers a 401 with a redirect to Sign in that comes back to the page', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => problemJson(401, { code: 'unauthenticated' })));

    const thrown = await api('/api/projects/1/members', { request: new Request('http://localhost/projects/1/members?page=2') }).catch((error) => error);

    expect(thrown).toBeInstanceOf(Response);
    expect(thrown.status).toBe(302);
    expect(thrown.headers.get('Location')).toBe('/sign-in?next=%2Fprojects%2F1%2Fmembers%3Fpage%3D2');
  });

  it('lets the caller handle a 401 where it is an expected answer', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => problemJson(401, { title: 'The email or password is not correct', code: 'invalid_credentials' })));

    const error = await api('/api/auth/login', { method: 'POST', body: {}, redirectOn401: false }).catch((thrown) => thrown);

    expect(error).toBeInstanceOf(ApiError);
    expect(error.problem.code).toBe('invalid_credentials');
  });
});

describe('safeNext', () => {
  it('only goes back to a path on this site', () => {
    expect(safeNext('/projects/1/members?page=2')).toBe('/projects/1/members?page=2');
    expect(safeNext('/')).toBe('/');
    for (const unsafe of ['//evil.example', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)', null, '/\t/evil.example', '/\n/evil.example', '/ /evil.example', '/projects\u0000', '/projects\u00a0x']) {
      expect(safeNext(unsafe)).toBe('/projects');
    }
  });
});
