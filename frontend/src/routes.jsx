import { redirect } from 'react-router';
import { ErrorPage, Loading, RootLayout, rootLoader } from './components/layout.jsx';
import { Register, registerAction, SignIn, signInAction, signOutAction } from './routes/auth.jsx';
import { Members, membersAction, membersLoader } from './routes/members.jsx';
import { NewProject, newProjectAction, Overview, ProjectLayout, projectLoader, Projects, projectsLoader } from './routes/projects.jsx';
import { Settings, settingsAction } from './routes/settings.jsx';

/**
 * The page map (UX §2). Pages render inside the layout, and so does their error page; data comes from loaders and
 * actions, which call the API with its shared client (src/api/client.js).
 */
export const routes = [
  {
    id: 'root',
    path: '/',
    loader: rootLoader,
    Component: RootLayout,
    ErrorBoundary: ErrorPage,
    HydrateFallback: Loading,
    children: [
      {
        ErrorBoundary: ErrorPage,
        children: [
          { index: true, loader: () => redirect('/projects') },
          { path: 'sign-in', action: signInAction, Component: SignIn },
          { path: 'register', action: registerAction, Component: Register },
          { path: 'sign-out', action: signOutAction },
          { path: 'projects', loader: projectsLoader, Component: Projects },
          { path: 'projects/new', action: newProjectAction, Component: NewProject },
          {
            id: 'project',
            path: 'projects/:projectId',
            loader: projectLoader,
            Component: ProjectLayout,
            children: [
              { index: true, Component: Overview },
              { path: 'members', loader: membersLoader, action: membersAction, Component: Members },
              { path: 'settings', action: settingsAction, Component: Settings },
            ],
          },
        ],
      },
    ],
  },
];
