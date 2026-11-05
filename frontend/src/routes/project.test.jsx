import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ME, page, project, renderApp } from '../test-utils.jsx';

const members = page([
  { userId: 'u1', email: 'ana@example.com', displayName: 'Ana', role: 'owner' },
  { userId: 'u2', email: 'bo@example.com', displayName: 'Bo', role: 'viewer' },
]);

/** The app at `path`, signed in as Ana, with the project answering `fields` (role and archive state). */
const renderPage = (path, fields) =>
  renderApp(path, {
    '/api/me': ME,
    '/api/projects/p1': project(fields),
    '/api/projects/p1/members?page=1': members,
    '/api/projects/p1/datasets?perPage=1': { ...page([]), perPage: 1 },
  });

const projectNav = () => within(screen.getByRole('navigation', { name: 'Project' }));

describe('project pages show only what the role can do (UX §1.5)', () => {
  it('gives an owner the Members and Settings sections, with every member action', async () => {
    await renderPage('/projects/p1/members', { role: 'owner' });

    expect(projectNav().getByRole('link', { name: 'Members' }).getAttribute('aria-current')).toBe('page');
    projectNav().getByRole('link', { name: 'Settings' });
    screen.getByRole('heading', { name: 'Invite a member' });
    expect(screen.getAllByRole('button', { name: /^Change role of/ })).toHaveLength(2);
    expect(screen.getAllByRole('button', { name: /^Remove/ })).toHaveLength(2);
    // usePageTitle sets the title in an effect, after the page renders.
    await waitFor(() => expect(document.title).toBe('Members · Support bot · Scorewright'));
  });

  it('hides Members and Settings from a viewer, and the owner’s next steps', async () => {
    await renderPage('/projects/p1', { role: 'viewer' });

    projectNav().getByRole('link', { name: 'Overview' });
    projectNav().getByRole('link', { name: 'Datasets' });
    expect(projectNav().queryByRole('link', { name: 'Members' })).toBeNull();
    expect(projectNav().queryByRole('link', { name: 'Settings' })).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Next steps' })).toBeNull();
    screen.getByText('Reads the project and its results.');
  });

  it('shows an archived project read-only, even to its owner', async () => {
    await renderPage('/projects/p1/members', { role: 'owner', archivedAt: '2026-11-05T10:00:00+00:00' });

    screen.getByText('This project is archived.');
    screen.getByText('Bo');
    expect(screen.queryByRole('heading', { name: 'Invite a member' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Change role|Remove/ })).toBeNull();
  });

  it('starts the owner’s next steps with importing a dataset', async () => {
    await renderPage('/projects/p1', { role: 'owner' });

    const steps = within(screen.getByRole('heading', { name: 'Next steps' }).parentElement);
    expect(steps.getByRole('link', { name: 'Import a dataset' }).getAttribute('href')).toBe('/projects/p1/datasets/new');
  });
});
