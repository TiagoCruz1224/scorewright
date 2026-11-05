import { redirect } from 'react-router';

/**
 * One random value per page load, sent with every unsafe request (ADR 0002): the API requires the header, and a page
 * on another site cannot send it.
 */
const csrfToken = crypto.randomUUID();

/** An error answer from the API, with its Problem Details (architecture §5) in `problem`. */
export class ApiError extends Error {
  constructor(problem) {
    super(problem.title);
    this.problem = problem;
  }
}

/**
 * Calls the API and returns the JSON answer (null for 204).
 *
 * - An error answer throws an ApiError. Its `problem` has `status`, `code`, `title` and `errors` (an empty list when
 *   the API sent none), and `retryAfter` in seconds when the API sent Retry-After (429).
 * - A 401 throws a redirect to the sign-in page, which brings the user back here afterwards. Where a 401 is an
 *   expected answer (signing in, asking who is signed in), pass `redirectOn401: false` to get an ApiError instead.
 *
 * Call it from a loader or an action and pass its `request`: the URL to come back to, and the signal that cancels the
 * call when the user navigates away.
 *
 * @param {string} path
 * @param {{method?: string, body?: unknown, request?: Request, redirectOn401?: boolean}} [options] body: a value to
 *        send as JSON, or a FormData to send as multipart/form-data (an upload)
 */
export async function api(path, { method = 'GET', body, request, redirectOn401 = true } = {}) {
  const headers = { Accept: 'application/json, application/problem+json' };
  if (!['GET', 'HEAD'].includes(method)) {
    headers['csrf-token'] = csrfToken;
  }
  // FormData (an upload) goes as multipart/form-data, with the boundary the browser sets; anything else as JSON.
  const isForm = body instanceof FormData;
  if (body !== undefined && !isForm) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(path, {
    method,
    headers,
    body: body === undefined || isForm ? body : JSON.stringify(body),
    credentials: 'same-origin',
    signal: request?.signal,
  });

  if (response.status === 401 && redirectOn401) {
    const here = new URL(request?.url ?? window.location.href);
    throw redirect(signInPath(here.pathname + here.search));
  }
  if (!response.ok) {
    throw new ApiError(await problemOf(response));
  }

  return response.status === 204 ? null : response.json();
}

export function signInPath(next) {
  return `/sign-in?next=${encodeURIComponent(next)}`;
}

/** Where to go after signing in: a path on this site only, never another origin. */
export function safeNext(next) {
  return typeof next === 'string' && next.startsWith('/') && !next.startsWith('//') && !next.startsWith('/\\') ? next : '/projects';
}

async function problemOf(response) {
  const isJson = /json/.test(response.headers.get('Content-Type') ?? '');
  const problem = isJson ? await response.json().catch(() => ({})) : {};
  const retryAfter = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);

  return {
    ...problem,
    status: response.status,
    code: problem.code ?? 'http_error',
    title: problem.title ?? 'Something went wrong. Try again in a moment.',
    errors: Array.isArray(problem.errors) ? problem.errors : [],
    ...(Number.isFinite(retryAfter) ? { retryAfter } : {}),
  };
}
