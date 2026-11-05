import { useEffect, useRef, useState } from 'react';
import { Form, isRouteErrorResponse, Link, NavLink, Outlet, useLoaderData, useLocation, useRouteError, useRouteLoaderData, useSearchParams } from 'react-router';
import { api, ApiError } from '../api/client.js';
import { RoleBadge } from '../roles.jsx';
import { BrandMark, Icon } from './icons.jsx';

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

/**
 * After moving to another page, the focus goes to the new page's h1, so keyboard and screen reader users start there
 * instead of at the top of the document (SWQ2, note 4). A page that put the focus somewhere itself (an error summary,
 * a rejected import) keeps it. Not on the first load: the browser starts at the top.
 */
function useFocusOnNavigation() {
  const { pathname } = useLocation();
  // The path the focus was last placed for: the first load (and React's second run of effects in development) is not a
  // move to another page.
  const placed = useRef(pathname);
  useEffect(() => {
    if (placed.current === pathname) {
      return;
    }
    placed.current = pathname;
    const main = document.getElementById('main');
    if (main && main.contains(document.activeElement) && document.activeElement !== main) {
      return;
    }
    const heading = main?.querySelector('h1');
    if (heading) {
      heading.setAttribute('tabindex', '-1');
      heading.focus();
    }
  }, [pathname]);
}

export function RootLayout() {
  const { user } = useLoaderData();
  const project = useRouteLoaderData('project');
  useFocusOnNavigation();

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
        <div className="topbar-row">
          <Link className="brand" to="/projects">
            <BrandMark />
            {APP_NAME}
          </Link>
          {project && (
            <>
              <svg className="scope-sep" viewBox="0 0 16 24" aria-hidden="true">
                <path d="M11 4 5 20" stroke="currentColor" strokeWidth="1.25" fill="none" />
              </svg>
              <span className="scope">
                <Link className="inline-flex min-h-6 items-center" to={`/projects/${project.id}`}>
                  <bdi>{project.name}</bdi>
                </Link>
                <span className="sr-only">Your role: </span>
                <RoleBadge role={project.role} />
              </span>
            </>
          )}
          <div className="topbar-end">
            <ThemeToggle />
            {user ? (
              <AccountMenu user={user} />
            ) : (
              <nav aria-label="Account" className="actions">
                <NavLink className="btn btn-ghost btn-sm" to="/sign-in">
                  Sign in
                </NavLink>
                <NavLink className="btn btn-ghost btn-sm" to="/register">
                  Register
                </NavLink>
              </nav>
            )}
          </div>
        </div>
        {project && <ProjectNav project={project} />}
      </header>
      <main id="main" tabIndex={-1} className="page outline-none">
        <Outlet />
      </main>
    </>
  );
}

/** The project's sections; a section the user cannot use is not shown (UX §1.5). */
function ProjectNav({ project }) {
  const base = `/projects/${project.id}`;
  const sections = [
    ['Overview', base],
    ['Datasets', `${base}/datasets`],
    ['Runs', `${base}/runs`],
    ['Rubrics', `${base}/rubrics`],
    ...(project.role === 'owner' ? [['Members', `${base}/members`], ['Settings', `${base}/settings`]] : []),
  ];

  return (
    <nav className="tabs-wrap" aria-label="Project">
      <ul className="tabs">
        {sections.map(([label, to]) => (
          <li key={label}>
            <NavLink className="tab" end={to === base} to={to}>
              {label}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  );
}

function ThemeToggle() {
  const [dark, setDark] = useState(() => document.documentElement.dataset.theme === 'dark');

  return (
    <button
      type="button"
      className="btn btn-ghost btn-icon"
      aria-label="Dark theme"
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
      <Icon name="moon" />
    </button>
  );
}

/** "Ana Lima" → "AL"; one word → its first two letters (avatars, aria-hidden). */
export const initials = (name) => {
  const words = name.trim().split(/\s+/).filter(Boolean);
  const letters = words.length > 1 ? [words[0][0], words.at(-1)[0]] : [...(words[0] ?? '?')].slice(0, 2);

  return letters.join('').toUpperCase();
};

function AccountMenu({ user }) {
  return (
    <details className="account-wrap">
      <summary className="account">
        <span className="avatar" aria-hidden="true">
          {initials(user.displayName)}
        </span>
        <span className="sr-only">Account: </span>
        <bdi className="account-name">{user.displayName}</bdi>
        <Icon name="chev-down" />
      </summary>
      <div className="account-panel">
        <p className="small muted mb-3">
          Signed in as <bdi>{user.email}</bdi>
        </p>
        <Form method="post" action="/sign-out">
          <button type="submit" className="btn btn-sm">
            Sign out
          </button>
        </Form>
      </div>
    </details>
  );
}

/**
 * `items`: [{label, to}], the last one being the current page. Labels are often names people typed, so each is
 * isolated in <bdi>: a bidi control in one (U+202E) cannot reorder the trail (SWQ2-08).
 */
export function Breadcrumb({ items }) {
  return (
    <nav aria-label="Breadcrumb" className="crumbs">
      <ol>
        {items.map((item, index) =>
          index < items.length - 1 ? (
            <li key={item.to}>
              <Link to={item.to}>
                <bdi>{item.label}</bdi>
              </Link>
            </li>
          ) : (
            <li key={item.label} aria-current="page">
              <bdi>{item.label}</bdi>
            </li>
          ),
        )}
      </ol>
    </nav>
  );
}

/**
 * The foot of a list ({page, perPage, total}): "Cases 1–25 of 212" when `noun` is given, the page, and Previous and
 * Next page, keeping the other query parameters. Nothing when the list fits on one page.
 */
export function Pager({ list, noun }) {
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
  const first = (list.page - 1) * list.perPage + 1;

  return (
    <nav aria-label="Pages" className="panel-foot">
      <span>{noun && `${noun} ${first.toLocaleString('en')}–${Math.min(list.total, first + list.perPage - 1).toLocaleString('en')} of ${list.total.toLocaleString('en')}`}</span>
      <span className="actions">
        <span>
          Page {list.page} of {pages}
        </span>
        {list.page > 1 && (
          <Link className="btn btn-sm" to={to(list.page - 1)}>
            <Icon name="chev-left" />
            Previous page
          </Link>
        )}
        {list.page < pages && (
          <Link className="btn btn-sm" to={to(list.page + 1)}>
            Next page
            <Icon name="chev-right" />
          </Link>
        )}
      </span>
    </nav>
  );
}

export function Loading() {
  return <p className="page muted">Loading…</p>;
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
    <div className="empty">
      <span className="empty-icon">
        <Icon name="alert" size="lg" />
      </span>
      <h1 className="empty-title">{title}</h1>
      <p>{text}</p>
      <div className="actions">
        <Link className="btn" to="/projects">
          Go to your projects
        </Link>
      </div>
    </div>
  );
}
