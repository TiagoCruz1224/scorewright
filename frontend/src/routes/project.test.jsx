import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { evaluation, ME, page, project, renderApp } from '../test-utils.jsx';

const members = page([
  { userId: 'u1', email: 'ana@example.com', displayName: 'Ana', role: 'owner' },
  { userId: 'u2', email: 'bo@example.com', displayName: 'Bo', role: 'viewer' },
]);

const noEvaluations = { ...page([]), perPage: 5 };

/** The app at `path`, signed in as Ana, with the project answering `fields` (role and archive state). */
const renderPage = (path, fields, evaluations = noEvaluations) =>
  renderApp(path, {
    '/api/projects/p1/evaluations?perPage=5': evaluations,
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

  it('points a new user without projects to the demo data (UX §3.1)', async () => {
    await renderApp('/projects', { '/api/me': ME, '/api/projects?page=1': page([]) });

    screen.getByRole('heading', { name: 'Create your first project' });
    expect(screen.getByText(/To look around first, load the demo data/).textContent).toBe(
      'To look around first, load the demo data with docker compose exec app php bin/console app:demo, then sign in as demo.owner@example.com.',
    );
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

  it('gives an evaluator their evaluations to score, beside their role card (canvas: Overview, evaluator)', async () => {
    const closed = evaluation({ id: 'e2', name: 'Pilot', status: 'closed', closedAt: '2026-11-04T10:00:00+00:00', blind: false });
    await renderPage('/projects/p1', { role: 'evaluator' }, { ...page([evaluation(), closed]), perPage: 5 });

    const yours = within(screen.getByRole('heading', { name: 'Your evaluations' }).closest('section'));
    const rows = yours.getAllByRole('listitem');
    expect(rows[0].textContent).toContain('1 of 4 answers scored');
    expect(within(rows[0]).getByRole('link', { name: 'Continue scoring' }).getAttribute('href')).toBe('/projects/p1/evaluations/e1/score');
    // Closed: nothing to score, and its results come with their screens.
    expect(within(rows[1]).queryByRole('link', { name: /scoring/ })).toBeNull();
    expect(rows[1].textContent).toContain('Closed');
    yours.getByText('Results of a blind evaluation unlock when the owner closes it.');

    const card = screen.getByRole('complementary', { name: 'Your role' });
    within(card).getByText('Scores and comments on the answers in evaluations.');
    within(card).getByText('You can read the datasets, runs and rubrics. The owner imports them and manages the project.');
    expect(within(card).queryByRole('link', { name: 'Manage members' })).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Next steps' })).toBeNull();
    expect(card.parentElement.classList.contains('grid-main')).toBe(true);
  });

  it('shows the owner and the viewer the latest evaluations, with progress and state in words', async () => {
    await renderPage('/projects/p1', { role: 'viewer' }, { ...page([evaluation()]), total: 7, perPage: 5 });

    const latest = within(screen.getByRole('heading', { name: 'Latest evaluations' }).closest('section'));
    expect(latest.getByRole('link', { name: 'All evaluations' }).getAttribute('href')).toBe('/projects/p1/evaluations');
    const row = latest.getAllByRole('listitem')[0];
    expect(within(row).getByRole('link', { name: 'Weekly check' }).getAttribute('href')).toBe('/projects/p1/evaluations/e1');
    expect(row.textContent).toContain('Support cases v2 · Support quality · Blind mode');
    expect(row.textContent).toContain('1 of 4 answers scored');
    within(row).getByText('Open');
    // A viewer does not score.
    expect(within(row).queryByRole('link', { name: /scoring/ })).toBeNull();
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
      '/api/projects/p1/evaluations?perPage=5': noEvaluations,
    });

    const card = screen.getByRole('heading', { name: 'Next steps' }).closest('section');
    const steps = within(card).getAllByRole('listitem');
    // Done in words ("Done"), with the check as a picture only; the first step to do says "Next".
    expect(steps.map((step) => [step.querySelector('.step-mark').textContent, step.textContent])).toEqual([
      ['', 'Import a datasetDone'],
      ['2', '2Import runsNext'],
      ['', 'Create a rubricDone'],
      ['4', '4Start an evaluation'],
    ]);
    within(card).getByText('2 of 4 done');
    expect(steps.map((step) => within(step).queryByRole('link')?.getAttribute('href') ?? null)).toEqual([null, '/projects/p1/runs/new', null, '/projects/p1/evaluations/new']);
  });

  it('starts the owner’s next steps with importing a dataset, then runs, a rubric and an evaluation', async () => {
    await renderPage('/projects/p1', { role: 'owner' });

    const steps = within(screen.getByRole('heading', { name: 'Next steps' }).closest('section'));
    expect(steps.getAllByRole('link').map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
      ['Import a dataset', '/projects/p1/datasets/new'],
      ['Import runs', '/projects/p1/runs/new'],
      ['Create a rubric', '/projects/p1/rubrics/new'],
      ['Start an evaluation', '/projects/p1/evaluations/new'],
    ]);
    // Beside the steps: the owner's role card, with what owners do and the way to the members.
    const card = screen.getByRole('complementary', { name: 'Your role' });
    expect(card.parentElement.classList.contains('grid-main')).toBe(true);
    within(card).getByText('Owners import datasets and runs, create rubrics and evaluations, and close evaluations.');
    within(card).getByRole('link', { name: 'Manage members' });
  });
});

describe('the breadcrumb', () => {
  it('lists the trail in order, with the separators left out of the names (SWD2b)', async () => {
    await renderPage('/projects/p1/members', { role: 'owner' });

    const trail = within(screen.getByRole('navigation', { name: 'Breadcrumb' }));
    expect(trail.getAllByRole('listitem').map((item) => [item.textContent, item.getAttribute('aria-current')])).toEqual([
      ['Projects', null],
      ['Support bot', null],
      ['Members', 'page'],
    ]);
    expect(trail.getAllByRole('link').map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
      ['Projects', '/projects'],
      ['Support bot', '/projects/p1'],
    ]);
    // The "/" between the items is CSS generated content with empty alternative text, so it is never read:
    // "Projects", not "/ Projects". (jsdom does not render generated content, so the rule itself is checked.)
    const css = readFileSync('src/index.css', 'utf8'); // Vitest runs from the frontend folder
    expect(css).toMatch(/\.crumbs li \+ li::before \{ content: "\/" \/ "";/);
    expect(css).not.toMatch(/\.crumbs li \+ li::before \{ content: "\/";/);
  });
});
