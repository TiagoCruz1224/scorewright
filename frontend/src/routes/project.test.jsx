import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
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
    '/api/projects/p1/runs?perPage=1': { ...page([]), perPage: 1 },
    '/api/projects/p1/rubrics?perPage=1': { ...page([]), perPage: 1 },
  });

const projectNav = () => within(screen.getByRole('navigation', { name: 'Project' }));

describe('the start page', () => {
  it('sends / to the projects without a React Router warning (SWQ2-10)', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { router } = await renderApp('/', { '/api/me': ME, '/api/projects?page=1': page([]) });

    expect(router.state.location.pathname).toBe('/projects');
    expect(warn.mock.calls.flat().join(' ')).not.toContain('does not have an element or Component');
    warn.mockRestore();
  });
});

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

  it('marks a step done in words once it is, and keeps the others as links', async () => {
    const one = { ...page([{ id: 'x' }]), perPage: 1 };
    await renderApp('/projects/p1', {
      '/api/me': ME,
      '/api/projects/p1': project({ role: 'owner' }),
      '/api/projects/p1/datasets?perPage=1': one,
      '/api/projects/p1/runs?perPage=1': { ...page([]), perPage: 1 },
      '/api/projects/p1/rubrics?perPage=1': one,
    });

    const steps = within(screen.getByRole('heading', { name: 'Next steps' }).parentElement).getAllByRole('listitem');
    expect(steps.map((step) => step.textContent)).toEqual(['Import a dataset: ✓ done', 'Import runs', 'Create a rubric: ✓ done']);
    expect(steps.map((step) => within(step).queryByRole('link')?.getAttribute('href') ?? null)).toEqual([null, '/projects/p1/runs/new', null]);
  });

  it('starts the owner’s next steps with importing a dataset, then runs, then a rubric', async () => {
    await renderPage('/projects/p1', { role: 'owner' });

    const steps = within(screen.getByRole('heading', { name: 'Next steps' }).parentElement);
    expect(steps.getAllByRole('link').map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
      ['Import a dataset', '/projects/p1/datasets/new'],
      ['Import runs', '/projects/p1/runs/new'],
      ['Create a rubric', '/projects/p1/rubrics/new'],
    ]);
  });
});
