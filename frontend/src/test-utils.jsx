import { render, screen } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { vi } from 'vitest';
import { routes } from './routes.jsx';

/**
 * The whole app at `path`, with the real routes, against a fake API: `answers` maps each URL the app requests to its
 * JSON; any other URL answers 404. Resolves once the page has its heading.
 */
export async function renderApp(path, answers) {
  const fetch = vi.fn(async (url) => (url in answers ? Response.json(answers[url]) : new Response(null, { status: 404 })));
  vi.stubGlobal('fetch', fetch);
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(<RouterProvider router={router} />);
  await screen.findByRole('heading', { level: 1 });

  return { router, fetch };
}

export const ME = { id: 'u1', email: 'ana@example.com', displayName: 'Ana' };

export const project = (fields) => ({ id: 'p1', name: 'Support bot', description: '', archivedAt: null, role: 'owner', ...fields });

export const page = (items) => ({ items, page: 1, perPage: 25, total: items.length });
