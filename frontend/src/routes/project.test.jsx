import { render, screen, within } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { routes } from '../routes.jsx';

const members = {
  items: [
    { userId: 'u1', email: 'ana@example.com', displayName: 'Ana', role: 'owner' },
    { userId: 'u2', email: 'bo@example.com', displayName: 'Bo', role: 'viewer' },
  ],
  page: 1,
  perPage: 25,
  total: 2,
};

/** The app at `path`, signed in as Ana, with the project answering `project` (role and archive state). */
async function renderPage(path, project) {
  const answers = {
    '/api/me': { id: 'u1', email: 'ana@example.com', displayName: 'Ana' },
    '/api/projects/p1': { id: 'p1', name: 'Support bot', description: '', archivedAt: null, ...project },
    '/api/projects/p1/members?page=1': members,
  };
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url) => (url in answers ? Response.json(answers[url]) : new Response(null, { status: 404 }))),
  );
  render(<RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} />);
  await screen.findByRole('heading', { level: 1 });
}

const projectNav = () => within(screen.getByRole('navigation', { name: 'Project' }));

describe('project pages show only what the role can do (UX §1.5)', () => {
  it('gives an owner the Members and Settings sections, with every member action', async () => {
    await renderPage('/projects/p1/members', { role: 'owner' });

    expect(projectNav().getByRole('link', { name: 'Members' }).getAttribute('aria-current')).toBe('page');
    projectNav().getByRole('link', { name: 'Settings' });
    screen.getByRole('heading', { name: 'Invite a member' });
    expect(screen.getAllByRole('button', { name: /^Change role of/ })).toHaveLength(2);
    expect(screen.getAllByRole('button', { name: /^Remove/ })).toHaveLength(2);
    expect(document.title).toBe('Members · Support bot · Scorewright');
  });

  it('hides Members and Settings from a viewer', async () => {
    await renderPage('/projects/p1', { role: 'viewer' });

    projectNav().getByRole('link', { name: 'Overview' });
    expect(projectNav().queryByRole('link', { name: 'Members' })).toBeNull();
    expect(projectNav().queryByRole('link', { name: 'Settings' })).toBeNull();
    screen.getByText('Reads the project and its results.');
  });

  it('shows an archived project read-only, even to its owner', async () => {
    await renderPage('/projects/p1/members', { role: 'owner', archivedAt: '2026-11-05T10:00:00+00:00' });

    screen.getByText('This project is archived.');
    screen.getByText('Bo');
    expect(screen.queryByRole('heading', { name: 'Invite a member' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Change role|Remove/ })).toBeNull();
  });
});
