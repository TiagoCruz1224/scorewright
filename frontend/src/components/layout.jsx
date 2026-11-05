import { useEffect, useState } from 'react';
import { Form, isRouteErrorResponse, Link, NavLink, Outlet, useLoaderData, useRouteError, useRouteLoaderData, useSearchParams } from 'react-router';
import { api, ApiError } from '../api/client.js';

const APP_NAME = import.meta.env.VITE_APP_NAME;

/** Who is signed in, or null. Runs on the first load and again after every form submission. */
export async function rootLoader({ request }) {
  try {
    return { user: await api('/api/me', { request, redirectOn401: false }) };
  } catch (error) {
    if (error instanceof ApiError && error.problem.status === 401) {
      return { user: null };
    }
    throw error;
  }
}

/** The page title (WCAG 2.4.2): the screen, then the project, then the app. */
export function usePageTitle(...parts) {
  const title = [...parts, APP_NAME].join(' · ');
  useEffect(() => {
    document.title = title;
  }, [title]);
}

export function RootLayout() {
  const { user } = useLoaderData();
  const project = useRouteLoaderData('project');

  return (
    <>
      <a
        className="skip-link"
        href="#main"
        onClick={(event) => {
          event.preventDefault();
          document.getElementById('main')?.focus();
        }}
      >
        Skip to main content
      </a>
      <header className="topbar">
        <Link className="brand" to="/projects">
          {APP_NAME}
        </Link>
        {project && <ProjectNav project={project} />}
        <div className="topbar-end">
          <ThemeToggle />
          {user ? (
            <AccountMenu user={user} />
          ) : (
            <nav aria-label="Account" className="topbar-nav">
              <ul>
                <li>
                  <NavLink to="/sign-in">Sign in</NavLink>
                </li>
                <li>
                  <NavLink to="/register">Register</NavLink>
                </li>
              </ul>
            </nav>
          )}
        </div>
      </header>
      <main id="main" tabIndex={-1} className="mx-auto max-w-5xl px-4 pt-6 pb-24 outline-none">
        <Outlet />
      </main>
    </>
  );
}

/** The project's sections; a section the user cannot use is not shown (UX §1.5). */
function ProjectNav({ project }) {
  const base = `/projects/${project.id}`;

  return (
    <nav aria-label="Project" className="topbar-nav">
      <ul>
        <li>
          <NavLink end to={base}>
            Overview
          </NavLink>
        </li>
        <li>
          <NavLink to={`${base}/datasets`}>Datasets</NavLink>
        </li>
        <li>
          <NavLink to={`${base}/runs`}>Runs</NavLink>
        </li>
        <li>
          <NavLink to={`${base}/rubrics`}>Rubrics</NavLink>
        </li>
        {project.role === 'owner' && (
          <>
            <li>
              <NavLink to={`${base}/members`}>Members</NavLink>
            </li>
            <li>
              <NavLink to={`${base}/settings`}>Settings</NavLink>
            </li>
          </>
        )}
      </ul>
    </nav>
  );
}

function ThemeToggle() {
  const [dark, setDark] = useState(() => document.documentElement.dataset.theme === 'dark');

  return (
    <button
      type="button"
      className="btn"
      aria-pressed={dark}
      onClick={() => {
        const theme = dark ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;
        try {
          localStorage.setItem('sw-theme', theme);
        } catch {
          // Storage blocked: the choice lasts until the page reloads.
        }
        setDark(!dark);
      }}
    >
      Dark theme
    </button>
  );
}

function AccountMenu({ user }) {
  return (
    <details className="account">
      <summary>
        <span className="sr-only">Account: </span>
        {user.displayName}
      </summary>
      <div className="account-panel">
        <p className="muted text-sm">Signed in as {user.email}</p>
        <Form method="post" action="/sign-out">
          <button type="submit" className="btn">
            Sign out
          </button>
        </Form>
      </div>
    </details>
  );
}

/** `items`: [{label, to}], the last one being the current page. */
export function Breadcrumb({ items }) {
  return (
    <nav aria-label="Breadcrumb" className="breadcrumb">
      <ol>
        {items.map((item, index) =>
          index < items.length - 1 ? (
            <li key={item.to}>
              <Link to={item.to}>{item.label}</Link>
            </li>
          ) : (
            <li key={item.label} aria-current="page">
              {item.label}
            </li>
          ),
        )}
      </ol>
    </nav>
  );
}

/** Previous and next links for a list answer ({page, perPage, total}), keeping the other query parameters. */
export function Pager({ list }) {
  const [searchParams] = useSearchParams();
  const pages = Math.ceil(list.total / list.perPage);
  if (pages <= 1) {
    return null;
  }
  const to = (page) => {
    const params = new URLSearchParams(searchParams);
    params.set('page', String(page));
    return `?${params}`;
  };

  return (
    <nav aria-label="Pages" className="mt-6 flex flex-wrap items-center gap-4">
      {list.page > 1 && (
        <Link className="btn" to={to(list.page - 1)}>
          Previous page
        </Link>
      )}
      <span>
        Page {list.page} of {pages}
      </span>
      {list.page < pages && (
        <Link className="btn" to={to(list.page + 1)}>
          Next page
        </Link>
      )}
    </nav>
  );
}

export function Loading() {
  return <p className="p-6">Loading…</p>;
}

/** What went wrong while loading a page: a missing project, a page for owners only, or anything else. */
export function ErrorPage() {
  const error = useRouteError();
  const status = error instanceof ApiError ? error.problem.status : isRouteErrorResponse(error) ? error.status : 500;
  const [title, text] =
    status === 404
      ? ['Not found', 'This page does not exist, or it belongs to a project you are not a member of.']
      : status === 403
        ? ['You cannot open this page', 'Only the owners of this project can open it.']
        : ['Something went wrong', 'Reload the page in a moment. If it keeps happening, the server log says why.'];
  usePageTitle(title);

  return (
    <div className="p-4">
      <h1>{title}</h1>
      <p>{text}</p>
      <p>
        <Link to="/projects">Go to your projects</Link>
      </p>
    </div>
  );
}
